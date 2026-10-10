<?php
require_once __DIR__ . '/ai-log.php';
require_once __DIR__ . '/ai-gateway.php';
/**
 * Spread AI v2 — Smart AI Bridge (Phase 1)
 *
 * طبقة وصل بين كود v2 الحالي (ai_generate / ai_generate_image)
 * وبين معمارية الـ Multi-Provider الجديدة (services/).
 *
 * التشغيل: من الأدمن → إدارة الـ AI → تفعيل "Smart Router"
 * لو متقفل أو مفيش موفر نشط → الكود القديم يشتغل عادي (صفر مخاطرة).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// حماية من الرفع الناقص: لو أي ملف من services مش موجود → النظام يشتغل بالطريقة القديمة بدون أي fatal
$__smart_files = [
    __DIR__ . '/crypto.php',
    __DIR__ . '/../services/Contracts/AIProviderContract.php',
    __DIR__ . '/../services/Adapters/BaseAdapter.php',
    __DIR__ . '/../services/Adapters/OpenAIAdapter.php',
    __DIR__ . '/../services/Adapters/OpenRouterAdapter.php',
    __DIR__ . '/../services/Adapters/GeminiAdapter.php',
    __DIR__ . '/../services/ProviderFactory.php',
    __DIR__ . '/../services/SmartRouter.php',
    __DIR__ . '/../services/AIService.php',
];
$__smart_ok = true;
foreach ($__smart_files as $__f) {
    if (!is_file($__f)) { $__smart_ok = false; break; }
}
if (!$__smart_ok) {
    if (!defined('SMART_AI_MISSING')) define('SMART_AI_MISSING', true);
    if (!function_exists('smart_ai_enabled')) {
        function smart_ai_enabled(): bool { return false; }
    }
    return; // الكود القديم يكمل عادي
}
foreach ($__smart_files as $__f) {
    require_once $__f;
}

/**
 * هل الـ Smart Router مفعّل وجاهز؟
 */
function smart_ai_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }
    // 8-أ: البوابة الموحدة لو شغالة بتاخد الأولوية على الـ Smart Router القديم
    if (function_exists('ai_gw_enabled') && ai_gw_enabled()) {
        return $enabled = false;
    }
    if (!function_exists('get_setting') || get_setting('use_smart_router', '0') !== '1') {
        return $enabled = false;
    }
    try {
        $row = db_one('SELECT COUNT(*) AS c FROM ai_providers WHERE status = "active"');
        return $enabled = ((int) ($row['c'] ?? 0) > 0);
    } catch (Throwable $e) {
        return $enabled = false; // الجداول لسه متعملتش
    }
}

/**
 * توليد نص عبر الـ Router — نفس return shape بتاع ai_generate() القديمة
 * ['ok', 'response', 'error', 'usage' => ['in','out'], 'model']
 */

/** تسجيل استدعاء نصي من المسار الذكي */
function smart_log_text(string $prompt, array $imageUrls, array $ctx, array $r, float $t0, string $status, ?string $error, string $taskCode): void
{
    if (!function_exists('ai_log_request')) {
        return;
    }
    ai_log_request([
        'kind'           => $ctx['kind'] ?? ($taskCode === 'plan_ideas' ? 'ideas' : 'content'),
        'user_id'        => $ctx['user_id'] ?? null,
        'reference_type' => $ctx['reference_type'] ?? null,
        'reference_id'   => $ctx['reference_id'] ?? null,
        'options'        => array_merge((array) ($ctx['options'] ?? []), ['task_code' => $taskCode]),
        'route'          => 'smart',
        'provider'       => $r['provider'] ?? 'smart-router',
        'model'          => $r['model'] ?? null,
        'endpoint'       => 'Smart Router → ' . ($r['provider'] ?? '?'),
        'prompt'         => $prompt,
        'images'         => $imageUrls,
        'status'         => $status,
        'error'          => $error,
        'response'       => $status === 'ok' ? (string) ($r['content'] ?? '') : null,
        'tokens_in'      => (int) ($r['tokens_in'] ?? 0),
        'tokens_out'     => (int) ($r['tokens_out'] ?? 0),
        'cost_usd'       => $r['cost_usd'] ?? null,
        'cap'            => 'text',
        'duration_ms'    => (int) round((microtime(true) - $t0) * 1000),
    ]);
}

function smart_ai_generate(string $prompt, array $imageUrls = [], string $taskCode = 'content_generation', array $context = []): array
{
    $__t0 = microtime(true);
    $__ctx = function_exists('ai_log_context') ? ai_log_context() : [];
    $messages = ai_build_messages($prompt, $imageUrls); // نفس الدالة الحالية في ai.php

    $r = \Spread\AIService::generate($taskCode, $messages, [
        'user_id'        => $context['user_id']        ?? null,
        'reference_type' => $context['reference_type'] ?? null,
        'reference_id'   => $context['reference_id']   ?? null,
        'temperature'    => $context['temperature']    ?? 0.8,
        'max_tokens'     => $context['max_tokens']     ?? null,
        'json_mode'      => $context['json_mode']      ?? false,
        'job_type'       => $context['job_type']       ?? 'content_gen',
    ]);

    if ($r['ok']) {
        smart_log_text($prompt, $imageUrls, $__ctx, $r, $__t0, 'ok', null, $taskCode);
        return [
            'ok'       => true,
            'response' => $r['content'],
            'error'    => null,
            'usage'    => ['in' => (int) $r['tokens_in'], 'out' => (int) $r['tokens_out']],
            'model'    => $r['model'],
        ];
    }
    smart_log_text($prompt, $imageUrls, $__ctx, $r, $__t0, 'failed', $r['error'] ?? null, $taskCode);

    ai_log_error('[smart-router] ' . ($r['error'] ?? 'failed'));
    return [
        'ok' => false, 'response' => null,
        'error' => 'محرك الذكاء الاصطناعي مشغول حاليًا. حاول تاني بعد دقيقة.',
        'usage' => ['in' => 0, 'out' => 0], 'model' => $r['model'] ?? 'router',
    ];
}

/**
 * توليد صورة عبر الـ Router — نفس return shape بتاع ai_generate_image() القديمة
 * ['ok', 'path' (relative to storage/), 'error', 'model']
 */

/** تسجيل استدعاء صورة من المسار الذكي */
function smart_log_image(string $prompt, array $refs, array $ctx, array $r, float $t0, string $status, ?string $error = null, ?string $path = null): void
{
    if (!function_exists('ai_log_request')) {
        return;
    }
    $provider = $r['provider'] ?? 'smart-router';
    ai_log_request([
        'kind'           => $ctx['kind'] ?? 'design',
        'user_id'        => $ctx['user_id'] ?? null,
        'reference_type' => $ctx['reference_type'] ?? null,
        'reference_id'   => $ctx['reference_id'] ?? null,
        'options'        => $ctx['options'] ?? null,
        'route'          => 'smart',
        'provider'       => $provider,
        'model'          => $r['model'] ?? null,
        'endpoint'       => $provider === 'openai'
            ? ('https://api.openai.com/v1/images/' . (!empty($refs) ? 'edits' : 'generations'))
            : ('Smart Router → ' . $provider),
        'prompt'         => $prompt,
        'images'         => $ctx['images'] ?? $refs,
        'status'         => $status,
        'error'          => $error,
        'response'       => $path ? ('تم حفظ الصورة: ' . $path) : null,
        'cap'            => 'image',
        'cost_usd'       => $r['cost_usd'] ?? null,
        'duration_ms'    => (int) round((microtime(true) - $t0) * 1000),
    ]);
}

function smart_ai_image(string $prompt, array $context = []): array
{
    @set_time_limit(120);
    $__t0 = microtime(true);
    $__ctx = function_exists('ai_log_context') ? ai_log_context() : [];
    $__refs = $context['reference_images'] ?? [];

    $r = \Spread\AIService::generateImage($prompt, [
        'user_id'          => $context['user_id']          ?? null,
        'reference_type'   => $context['reference_type']   ?? null,
        'reference_id'     => $context['reference_id']     ?? null,
        'size'             => $context['size']             ?? '1024x1024',
        'task_code'        => $context['task_code']        ?? 'image_generation',
        'reference_images' => $context['reference_images'] ?? [],
    ]);

    if (!$r['ok'] || empty($r['images'][0])) {
        ai_log_error('[smart-router image] ' . ($r['error'] ?? 'no image'));
        smart_log_image($prompt, $__refs, $__ctx, $r, $__t0, 'failed', $r['error'] ?? 'الموديل لم يرجع صورة');
        return ['ok' => false, 'path' => null, 'error' => 'تعذر توليد التصميم حاليًا. حاول تاني بعد دقيقة.', 'model' => $r['model'] ?? 'router'];
    }

    $dir = UPLOADS_PATH . '/designs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $filename = bin2hex(random_bytes(12)) . '.png';
    $dest = $dir . '/' . $filename;

    $img = $r['images'][0];
    $binary = null;

    if (preg_match('#^data:image/\w+;base64,(.+)$#s', $img, $m)) {
        $binary = base64_decode($m[1]);
    } elseif (preg_match('#^https?://#', $img)) {
        $ch = curl_init($img);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true]);
        $binary = curl_exec($ch);
        curl_close($ch);
    } else {
        $binary = base64_decode($img, true) ?: null; // raw b64
    }

    if (!$binary || !file_put_contents($dest, $binary)) {
        return ['ok' => false, 'path' => null, 'error' => 'تعذر حفظ التصميم.', 'model' => $r['model'] ?? 'router'];
    }

    smart_log_image($prompt, $__refs, $__ctx, $r, $__t0, 'ok', null, 'uploads/designs/' . $filename);
    return ['ok' => true, 'path' => 'uploads/designs/' . $filename, 'error' => null, 'model' => $r['model'] ?? 'router'];
}
