<?php
require_once __DIR__ . '/usage.php';
/**
 * Spread AI v2 — تسجيل استدعاءات الذكاء الاصطناعي
 *
 * بيسجّل اللي اتبعت للـ API بالظبط عشان تقدر تشخّص أي مشكلة في البرومبت.
 * ⚠️ التسجيل مبيفشلش العملية أبدًا — أي خطأ هنا بيتبلع بصمت.
 */

if (!function_exists('db_insert')) {
    require_once __DIR__ . '/db.php';
}

function ai_log_enabled(): bool
{
    return !function_exists('get_setting') || get_setting('ai_logging_enabled', '1') === '1';
}

/**
 * تصغير صورة مرجعية لمعاينة في لوحة الأدمن (مش تخزين الصورة كاملة)
 */
function ai_log_thumb(string $dataUri, int $max = 96): ?string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('get_setting')) {
        return null;
    }
    if (get_setting('ai_log_store_thumbs', '1') !== '1') {
        return null;
    }
    if (!preg_match('~^data:image/[a-z+]+;base64,(.+)$~is', trim($dataUri), $m)) {
        return null;
    }
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) > 8 * 1024 * 1024) {
        return null;
    }
    try {
        $src = @imagecreatefromstring($bin);
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min($max / max(1, $w), $max / max(1, $h), 1);
        $nw = max(1, (int) ($w * $scale));
        $nh = max(1, (int) ($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($dst, null, 8);
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return 'data:image/png;base64,' . base64_encode($out);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * وصف الصور المرجعية بدون تخزينها كاملة
 * @param array $images ['uri'=>..., 'role'=>...] أو مجرد data URIs
 */
function ai_log_images_meta(array $images): array
{
    $meta = [];
    foreach ($images as $img) {
        $uri  = is_array($img) ? ($img['uri'] ?? '') : (string) $img;
        $role = is_array($img) ? ($img['role'] ?? $img['label'] ?? '') : '';
        if (!is_string($uri) || $uri === '') {
            continue;
        }
        $mime = 'غير معروف';
        $bytes = 0;
        if (preg_match('~^data:(image/[a-z+]+);base64,(.+)$~is', trim($uri), $m)) {
            $mime = $m[1];
            $bytes = (int) (strlen($m[2]) * 3 / 4);
        } elseif (preg_match('~^https?://~i', $uri)) {
            $mime = 'رابط خارجي';
        }
        $meta[] = [
            'role'  => (string) $role,
            'mime'  => $mime,
            'bytes' => $bytes,
            'thumb' => ai_log_thumb($uri),
        ];
    }
    return $meta;
}

/**
 * تسجيل استدعاء.
 * @return int|null id السجل
 */
function ai_log_request(array $d): ?int
{
    // 8-أ: كل استدعاء (حتى من المسار القديم) بيتسجل كعملية + محاولة بتكلفتها — مستقل عن إعداد سجل البرومبتات
    if (($d['route'] ?? 'legacy') !== 'gateway' && function_exists('usage_record_legacy')) {
        usage_record_legacy($d);
    }
    if (!ai_log_enabled()) {
        return null;
    }
    try {
        $prompt = (string) ($d['prompt'] ?? '');
        $imagesMeta = $d['images_meta'] ?? (isset($d['images']) ? ai_log_images_meta((array) $d['images']) : []);

        return db_insert(
            'INSERT INTO ai_request_logs
                (kind, user_id, reference_type, reference_id, route, provider, model, endpoint,
                 prompt, prompt_chars, images_sent, images_meta, options_json,
                 status, http_code, error, response_excerpt, tokens_in, tokens_out, credits_used, duration_ms)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                mb_substr((string) ($d['kind'] ?? 'unknown'), 0, 30),
                $d['user_id'] ?? null,
                isset($d['reference_type']) ? mb_substr((string) $d['reference_type'], 0, 40) : null,
                $d['reference_id'] ?? null,
                in_array($d['route'] ?? 'legacy', ['smart', 'gateway'], true) ? $d['route'] : 'legacy',
                isset($d['provider']) ? mb_substr((string) $d['provider'], 0, 60) : null,
                isset($d['model']) ? mb_substr((string) $d['model'], 0, 120) : null,
                isset($d['endpoint']) ? mb_substr((string) $d['endpoint'], 0, 255) : null,
                mb_substr($prompt, 0, 2000000),
                mb_strlen($prompt),
                count($imagesMeta),
                $imagesMeta ? json_encode($imagesMeta, JSON_UNESCAPED_UNICODE) : null,
                !empty($d['options']) ? json_encode($d['options'], JSON_UNESCAPED_UNICODE) : null,
                ($d['status'] ?? 'ok') === 'failed' ? 'failed' : 'ok',
                $d['http_code'] ?? null,
                isset($d['error']) ? mb_substr((string) $d['error'], 0, 500) : null,
                isset($d['response']) ? mb_substr((string) $d['response'], 0, 4000) : null,
                (int) ($d['tokens_in'] ?? 0),
                (int) ($d['tokens_out'] ?? 0),
                (int) ($d['credits_used'] ?? 0),
                (int) ($d['duration_ms'] ?? 0),
            ]
        );
    } catch (\Throwable $e) {
        error_log('[ai-log] ' . $e->getMessage());
        return null;
    }
}

/** تنظيف السجلات القديمة */
function ai_log_cleanup(): int
{
    try {
        $days = max(1, (int) (function_exists('get_setting') ? get_setting('ai_log_keep_days', 30) : 30));
        db_run('DELETE FROM ai_request_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
        return 1;
    } catch (\Throwable $e) {
        return 0;
    }
}
