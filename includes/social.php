<?php
/**
 * Spread AI v2 — وحدة النشر الاجتماعي
 * FeatureGate + TokenVault + GraphClient + Publisher
 * فيسبوك + انستجرام (Graph API) — التوكنات مشفرة AES-256-GCM
 */
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/helpers.php';

/* ═══════════════════ Feature Gate ═══════════════════ */

/**
 * هل الوحدة متاحة للمستخدم؟
 * off = مقفولة للكل · on = مفتوحة للكل (إلا المستثنى) · allowlist = بالسماح فقط
 */
function feature_allows(int $userId, string $key = 'social_publishing'): bool
{
    static $cache = [];
    $ck = $userId . ':' . $key;
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    try {
        $flag = db_one('SELECT default_mode FROM feature_flags WHERE feature_key = ?', [$key]);
        $mode = $flag['default_mode'] ?? 'off';
        if ($mode === 'off') {
            return $cache[$ck] = false;
        }
        $row = db_one('SELECT * FROM user_feature_access WHERE user_id = ? AND feature_key = ?', [$userId, $key]);
        if ($mode === 'on') {
            // مفتوحة للكل إلا من عُطّل صراحة
            return $cache[$ck] = !($row && !$row['is_enabled']);
        }
        // allowlist
        if (!$row || !$row['is_enabled']) {
            return $cache[$ck] = false;
        }
        if ($row['expires_at'] && $row['expires_at'] < date('Y-m-d H:i:s')) {
            return $cache[$ck] = false;
        }
        return $cache[$ck] = true;
    } catch (\Throwable $e) {
        return $cache[$ck] = false;
    }
}

/** أقصى عدد صفحات مسموح للمستخدم يربطها */
function feature_max_pages(int $userId, string $key = 'social_publishing'): int
{
    try {
        $row = db_one('SELECT max_pages FROM user_feature_access WHERE user_id = ? AND feature_key = ?', [$userId, $key]);
        return max(1, (int) ($row['max_pages'] ?? 1));
    } catch (\Throwable $e) {
        return 1;
    }
}

/** حارس الصفحات: يمنع الوصول المباشر بالرابط */
function feature_require(int $userId, string $key = 'social_publishing'): void
{
    if (!feature_allows($userId, $key)) {
        // طلبات AJAX: رد JSON
        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
               || str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/ajax/');
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['ok' => false, 'error' => 'الميزة دي مش متفعّلة لحسابك'], JSON_UNESCAPED_UNICODE));
        }
        // الصفحات: رجوع للرئيسية برسالة واضحة بدل صفحة بيضا فيها سطر نص
        if (function_exists('flash_set') && function_exists('redirect')) {
            flash_set('info', 'النشر على السوشيال مش متفعّل لحسابك لسه — كلّمنا ونفعّلهولك.');
            redirect('dashboard.php');
        }
        http_response_code(403);
        exit('هذه الميزة غير مفعّلة لحسابك — تواصل مع إدارة المنصة.');
    }
}

/* ═══════════════════ Token Vault ═══════════════════ */

function social_token_encrypt(string $token): string
{
    return Crypto::encrypt($token);
}

function social_token_decrypt(string $enc): ?string
{
    try {
        return Crypto::decrypt($enc);
    } catch (\Throwable $e) {
        return null;
    }
}

/* ═══════════════════ إعدادات ميتا ═══════════════════ */

function meta_app_id(): string
{
    return trim((string) get_setting('meta_app_id', ''));
}

function meta_app_secret(): string
{
    $enc = (string) get_setting('meta_app_secret_enc', '');
    if ($enc === '') {
        return '';
    }
    return social_token_decrypt($enc) ?? '';
}

function meta_configured(): bool
{
    return meta_app_id() !== '' && meta_app_secret() !== '';
}

function graph_base(): string
{
    return rtrim((string) get_setting('graph_base_url', 'https://graph.facebook.com/v21.0'), '/');
}

function fb_oauth_base(): string
{
    return rtrim((string) get_setting('fb_oauth_base_url', 'https://www.facebook.com/v21.0'), '/');
}

function social_callback_url(): string
{
    return url('social/callback.php');
}

/* ═══════════════════ Graph Client ═══════════════════ */

/**
 * استدعاء موحّد لـ Graph API مع تسجيل في publish_logs (التوكنات بتتخفى)
 */
function graph_request(string $method, string $path, array $params = [], array $logCtx = []): array
{
    $t0 = microtime(true);
    $url = graph_base() . '/' . ltrim($path, '/');

    $ch = curl_init();
    if (strtoupper($method) === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $url . ($params ? '?' . http_build_query($params) : ''));
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 12,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    $json = $body !== false ? (json_decode((string) $body, true) ?: []) : [];

    // تسجيل — بدون توكنات أبدًا
    $safeParams = $params;
    foreach (['access_token', 'client_secret', 'fb_exchange_token', 'code'] as $k) {
        if (isset($safeParams[$k])) {
            $safeParams[$k] = '***';
        }
    }
    try {
        db_run(
            'INSERT INTO publish_logs (post_id, connection_id, action, http_status, request_json, response_json, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $logCtx['post_id'] ?? null,
                $logCtx['connection_id'] ?? null,
                $logCtx['action'] ?? ($method . ' ' . $path),
                $status,
                json_encode(['url' => $url, 'params' => $safeParams], JSON_UNESCAPED_UNICODE),
                mb_substr((string) $body, 0, 4000),
                $ms,
            ]
        );
    } catch (\Throwable $e) {
        // best effort
    }

    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'اتصال فشل: ' . $curlErr, 'code' => 0, 'data' => []];
    }
    if (isset($json['error'])) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $json['error']['message'] ?? 'Graph error',
            'code' => (int) ($json['error']['code'] ?? 0),
            'subcode' => (int) ($json['error']['error_subcode'] ?? 0),
            'data' => $json,
        ];
    }
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'code' => 0, 'data' => $json];
}

/* ═══════════════════ الاتصالات ═══════════════════ */

function user_connections(int $userId, ?string $status = 'active'): array
{
    $sql = 'SELECT * FROM social_connections WHERE user_id = ?';
    $p = [$userId];
    if ($status) {
        $sql .= ' AND status = ?';
        $p[] = $status;
    }
    try {
        return db_all($sql . ' ORDER BY id', $p);
    } catch (\Throwable $e) {
        // الجدول ناقص أو مشكلة DB — منرجعش 500، نسجّل ونكمل فاضي
        if (function_exists('social_log_error')) {
            social_log_error('user_connections: ' . $e->getMessage());
        }
        return [];
    }
}

function connection_for_user(int $connectionId, int $userId): ?array
{
    try {
        $c = db_one('SELECT * FROM social_connections WHERE id = ? AND user_id = ?', [$connectionId, $userId]);
        return $c ?: null;
    } catch (\Throwable $e) {
        if (function_exists('social_log_error')) {
            social_log_error('connection_for_user: ' . $e->getMessage());
        }
        return null;
    }
}

function connection_mark(int $id, string $status, ?string $error = null): void
{
    db_run(
        'UPDATE social_connections SET status = ?, last_error = ?, last_verified_at = NOW() WHERE id = ?',
        [$status, $error !== null ? mb_substr($error, 0, 1000) : null, $id]
    );
}

/* ═══════════════════ الناشر ═══════════════════ */

/**
 * نشر محتوى على اتصال (صفحة فيسبوك أو حساب انستجرام مربوط بيها)
 * $target: 'facebook' | 'instagram'
 * يرجع ['ok', 'post_id'|'error', 'code', 'permanent' => bool]
 */
function social_publish(array $content, array $conn, string $target = 'facebook'): array
{
    $token = social_token_decrypt($conn['access_token_enc']);
    if (!$token) {
        return ['ok' => false, 'error' => 'تعذّر فك تشفير التوكن', 'code' => 0, 'permanent' => true];
    }

    // نص البوست
    $message = trim((string) ($content['generated_text'] ?? ''));
    if (!empty($content['hashtags'])) {
        $message .= "\n\n" . trim((string) $content['hashtags']);
    }
    $message = mb_substr($message, 0, 60000);

    // الصور حسب الشكل (⑦-ج): كاروسيل = شريحة لكل صورة · منشور/ستوري = صورة واحدة — لازم تكون على URL عام
    require_once __DIR__ . '/content-formats.php';
    $images = content_publish_images($content);
    if (content_format_key($content['format'] ?? 'post') === 'video') {
        return ['ok' => false, 'error' => 'الفيديو بيتنشر بعد ما فريق Spread AI ينفّذه', 'code' => 0, 'permanent' => true];
    }

    $logCtx = ['post_id' => (int) $content['id'], 'connection_id' => (int) $conn['id']];

    if ($target === 'instagram') {
        if (empty($conn['ig_user_id'])) {
            return ['ok' => false, 'error' => 'الصفحة دي مش مربوط بيها حساب انستجرام بيزنس', 'code' => 0, 'permanent' => true];
        }
        return social_ig_send($content, $conn, $token, $message, $images, $logCtx) + ['code' => 0, 'permanent' => false];
    }

    // ═══ فيسبوك ═══
    return social_fb_send($content, $conn, $token, $message, $images, null, $logCtx) + ['code' => 0, 'permanent' => false];
}

/* ═══════════ الإرسال حسب الشكل (⑦-ج) ═══════════ */

/**
 * فيسبوك: منشور بصورة · كاروسيل (صور متعددة attached_media) · ستوري (photo_stories) · نص بس
 * $scheduleTs = null → فوري | timestamp → جدولة على فيسبوك (الستوري مابتتجدولش)
 */
function social_fb_send(array $content, array $conn, string $token, string $message, array $images, ?int $scheduleTs, array $logCtx): array
{
    require_once __DIR__ . '/content-formats.php';
    $format = content_format_key($content['format'] ?? 'post');
    $page = $conn['provider_page_id'];
    $sched = $scheduleTs !== null ? ['published' => 'false', 'scheduled_publish_time' => $scheduleTs] : [];

    if ($format === 'story' && $images) {
        if ($scheduleTs !== null) {
            return ['ok' => false, 'error' => 'ستوري فيسبوك مابتتجدولش — بتتنشر من الطابور في معادها', 'permanent' => true];
        }
        $up = graph_request('POST', $page . '/photos', ['url' => $images[0], 'published' => 'false', 'access_token' => $token], $logCtx + ['action' => 'fb_story_upload']);
        if (!$up['ok'] || empty($up['data']['id'])) return $up['ok'] ? ['ok' => false, 'error' => 'فيسبوك مارجعش الصورة', 'permanent' => false] : social_classify_error($up);
        $r = graph_request('POST', $page . '/photo_stories', ['photo_id' => $up['data']['id'], 'access_token' => $token], $logCtx + ['action' => 'fb_story']);
        if (!$r['ok']) return social_classify_error($r);
        return ['ok' => true, 'post_id' => (string) ($r['data']['post_id'] ?? $r['data']['id'] ?? '')];
    }

    if ($format === 'carousel' && count($images) >= 2) {
        // كل صورة بتترفع من غير نشر ← منشور واحد بيجمعهم بالترتيب
        $ids = [];
        foreach ($images as $k => $img) {
            $p = ['url' => $img, 'published' => 'false', 'access_token' => $token];
            if ($scheduleTs !== null) $p['temporary'] = 'true';
            $up = graph_request('POST', $page . '/photos', $p, $logCtx + ['action' => 'fb_carousel_upload_' . ($k + 1)]);
            if (!$up['ok'] || empty($up['data']['id'])) return $up['ok'] ? ['ok' => false, 'error' => 'فيسبوك مارجعش صورة الشريحة ' . ($k + 1), 'permanent' => false] : social_classify_error($up);
            $ids[] = (string) $up['data']['id'];
        }
        $params = ['message' => $message, 'access_token' => $token] + $sched;
        foreach ($ids as $k => $id) $params['attached_media[' . $k . ']'] = json_encode(['media_fbid' => $id]);
        $r = graph_request('POST', $page . '/feed', $params, $logCtx + ['action' => $scheduleTs !== null ? 'fb_carousel_scheduled' : 'fb_carousel']);
        if (!$r['ok']) return social_classify_error($r);
        return ['ok' => true, 'post_id' => (string) ($r['data']['id'] ?? $r['data']['post_id'] ?? '')];
    }

    if ($images) {
        $r = graph_request('POST', $page . '/photos', ['url' => $images[0], 'caption' => $message, 'access_token' => $token] + $sched,
            $logCtx + ['action' => $scheduleTs !== null ? 'fb_photo_scheduled' : 'fb_photo']);
    } else {
        $r = graph_request('POST', $page . '/feed', ['message' => $message, 'access_token' => $token] + $sched,
            $logCtx + ['action' => $scheduleTs !== null ? 'fb_feed_scheduled' : 'fb_feed']);
    }
    if (!$r['ok']) return social_classify_error($r);
    return ['ok' => true, 'post_id' => (string) ($r['data']['post_id'] ?? $r['data']['id'] ?? '')];
}

/** إنستجرام: صورة · كاروسيل (2–10) · ستوري — container ← media_publish */
function social_ig_send(array $content, array $conn, string $token, string $message, array $images, array $logCtx): array
{
    require_once __DIR__ . '/content-formats.php';
    $format = content_format_key($content['format'] ?? 'post');
    $ig = $conn['ig_user_id'];
    if (!$images) {
        return ['ok' => false, 'error' => 'انستجرام يتطلب تصميم/صورة مرفقة بالبوست', 'permanent' => true];
    }
    if ($format === 'carousel') {
        if (count($images) < 2) return ['ok' => false, 'error' => 'كاروسيل إنستجرام محتاج شريحتين متصممين على الأقل', 'permanent' => true];
        $children = [];
        foreach (array_slice($images, 0, 10) as $k => $img) {
            $r = graph_request('POST', $ig . '/media', ['image_url' => $img, 'is_carousel_item' => 'true', 'access_token' => $token],
                $logCtx + ['action' => 'ig_carousel_item_' . ($k + 1)]);
            if (!$r['ok'] || empty($r['data']['id'])) return $r['ok'] ? ['ok' => false, 'error' => 'إنستجرام مارجعش الشريحة ' . ($k + 1), 'permanent' => false] : social_classify_error($r);
            $children[] = (string) $r['data']['id'];
        }
        $r1 = graph_request('POST', $ig . '/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $message, 'access_token' => $token],
            $logCtx + ['action' => 'ig_carousel_container']);
    } elseif ($format === 'story') {
        $r1 = graph_request('POST', $ig . '/media', ['image_url' => $images[0], 'media_type' => 'STORIES', 'access_token' => $token],
            $logCtx + ['action' => 'ig_story_container']);
    } else {
        $r1 = graph_request('POST', $ig . '/media', ['image_url' => $images[0], 'caption' => $message, 'access_token' => $token],
            $logCtx + ['action' => 'ig_media_container']);
    }
    if (!$r1['ok']) return social_classify_error($r1);
    $containerId = $r1['data']['id'] ?? '';
    if (!$containerId) return ['ok' => false, 'error' => 'انستجرام: لم يرجع container id', 'permanent' => false];
    $r2 = graph_request('POST', $ig . '/media_publish', ['creation_id' => $containerId, 'access_token' => $token], $logCtx + ['action' => 'ig_media_publish']);
    if (!$r2['ok']) return social_classify_error($r2);
    return ['ok' => true, 'post_id' => (string) ($r2['data']['id'] ?? '')];
}

/**
 * تصنيف أخطاء Graph → هل نعيد المحاولة؟
 */
function social_classify_error(array $r): array
{
    $code = (int) ($r['code'] ?? 0);
    $msg = (string) ($r['error'] ?? 'خطأ غير معروف');

    // 190 = توكن باطل → الاتصال منتهي، بلا إعادة
    if ($code === 190) {
        return ['ok' => false, 'error' => $msg, 'code' => 190, 'permanent' => true, 'token_dead' => true];
    }
    // صلاحيات ناقصة / باراميتر غلط → نهائي
    if (in_array($code, [200, 10, 100, 803], true)) {
        return ['ok' => false, 'error' => $msg, 'code' => $code, 'permanent' => true];
    }
    // rate limit → أعد بعد 15 دقيقة
    if (in_array($code, [4, 17, 32, 613], true)) {
        return ['ok' => false, 'error' => $msg, 'code' => $code, 'permanent' => false, 'retry_minutes' => 15];
    }
    // مؤقت
    return ['ok' => false, 'error' => $msg, 'code' => $code, 'permanent' => false];
}

/**
 * backoff للمحاولات: 1د → 5د → 15د
 */
function social_backoff_minutes(int $attempts): int
{
    return [1 => 1, 2 => 5][$attempts] ?? 15;
}

/* ═══════════════════ حماية وتشخيص وحدة النشر ═══════════════════ */

/** تسجيل خطأ في ملف لوج مخصص */
function social_log_error(string $msg): void
{
    try {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . '/social-errors.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    } catch (\Throwable $e) {
        // متجاهَل — التسجيل مش سبب لإسقاط الطلب
    }
}

/** صفحة خطأ مستقلة تمامًا (متعتمدش على القوالب عشان متقعش هي كمان) */
function social_render_error(string $title, string $technical = '', bool $showRetry = true): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $tech = $technical !== '' ? htmlspecialchars($technical, ENT_QUOTES, 'UTF-8') : '';
    echo <<<HTML
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>مشكلة في الربط</title>
<style>
body{font-family:system-ui,'Segoe UI',Tahoma,sans-serif;background:#f5f5fa;margin:0;padding:24px;color:#222;line-height:1.9}
.box{max-width:640px;margin:40px auto;background:#fff;border-radius:18px;padding:28px;box-shadow:0 6px 30px rgba(0,0,0,.08)}
h1{font-size:20px;margin:0 0 10px}
.tech{background:#fdf3f2;border:1px solid #f5c6c2;border-radius:12px;padding:12px 14px;margin:16px 0;
font-family:ui-monospace,monospace;font-size:12.5px;direction:ltr;text-align:left;overflow-wrap:anywhere;color:#a3312a}
a.btn{display:inline-block;background:#4a3db8;color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px;margin:6px 6px 0 0;font-weight:600}
a.ghost{background:#eee;color:#333}
.hint{font-size:13.5px;color:#555}
</style></head><body><div class="box">
<h1>⚠️ {$t}</h1>
<p class="hint">حصلت مشكلة أثناء ربط صفحة فيسبوك. التفاصيل التقنية تحت — ابعتها لمطوّر المنصة لو محتاج.</p>
HTML;
    if ($tech !== '') {
        echo '<div class="tech">' . $tech . '</div>';
    }
    echo '<p class="hint">📄 الخطأ اتسجل كمان في <code>storage/logs/social-errors.log</code></p>';
    if ($showRetry) {
        echo '<a class="btn" href="' . $base . '/social/connect.php">↻ جرّب الربط تاني</a>';
    }
    echo '<a class="btn ghost" href="' . $base . '/social-diagnose.php">🩺 فحص شامل للربط</a>';
    echo '<a class="btn ghost" href="' . $base . '/social-accounts.php">رجوع</a>';
    echo '</div></body></html>';
}

/**
 * يمسك الأخطاء القاتلة ويحوّلها لصفحة مفهومة بدل 500 فاضية
 */
function social_guard(string $context): void
{
    register_shutdown_function(static function () use ($context) {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        $detail = $e['message'] . ' @ ' . basename((string) $e['file']) . ':' . $e['line'];
        social_log_error('[' . $context . '] FATAL: ' . $detail);
        if (!headers_sent()) {
            http_response_code(500);
        }
        social_render_error('خطأ تقني أثناء الربط', $detail);
    });
}

/** الجداول والأعمدة المطلوبة للوحدة */
function social_schema_status(): array
{
    $need = [
        'social_connections' => ['id', 'user_id', 'platform', 'provider_page_id', 'page_name', 'page_avatar_url', 'ig_user_id', 'ig_username', 'access_token_enc', 'status'],
        'feature_flags' => ['feature_key', 'default_mode'],
        'user_feature_access' => ['user_id', 'feature_key', 'is_enabled', 'max_pages'],
        'publish_logs' => ['action', 'http_status'],
    ];
    $missingTables = [];
    $missingCols = [];
    foreach ($need as $table => $cols) {
        try {
            $t = db_one('SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
            if ((int) ($t['c'] ?? 0) === 0) {
                $missingTables[] = $table;
                continue;
            }
            foreach ($cols as $col) {
                $c = db_one('SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $col]);
                if ((int) ($c['c'] ?? 0) === 0) {
                    $missingCols[] = $table . '.' . $col;
                }
            }
        } catch (\Throwable $e) {
            $missingTables[] = $table . ' (تعذر الفحص)';
        }
    }
    // أعمدة النشر على contents
    foreach (['connection_id', 'publish_status', 'attempts', 'next_attempt_at', 'lock_token'] as $col) {
        try {
            $c = db_one('SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = "contents" AND column_name = ?', [$col]);
            if ((int) ($c['c'] ?? 0) === 0) {
                $missingCols[] = 'contents.' . $col;
            }
        } catch (\Throwable $e) {
        }
    }
    return ['tables' => $missingTables, 'columns' => $missingCols, 'ok' => !$missingTables && !$missingCols];
}

/**
 * إنشاء الناقص تلقائيًا (آمن للتكرار) — عشان الترقية لو ما اتنفذتش الوحدة تصلّح نفسها
 */
function social_ensure_schema(): array
{
    $done = [];

    // توسيع الأعمدة الضيقة الموروثة — بيتنفذ دايمًا حتى لو الجداول كاملة
    // (روابط صور صفحات فيسبوك بتتجاوز 512 حرف وبتفشّل الحفظ بخطأ 1406)
    foreach ([
        ['social_connections', 'page_avatar_url', 1000, "ALTER TABLE `social_connections` MODIFY COLUMN `page_avatar_url` VARCHAR(1000) NULL"],
        ['social_connections', 'ig_username',      190, "ALTER TABLE `social_connections` MODIFY COLUMN `ig_username` VARCHAR(190) NULL"],
    ] as [$table, $col, $want, $sql]) {
        try {
            $cur = db_one(
                'SELECT CHARACTER_MAXIMUM_LENGTH len FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $col]
            );
            if ($cur && (int) ($cur['len'] ?? 0) > 0 && (int) $cur['len'] < $want) {
                db_run($sql);
                $done[] = 'وُسّع عمود ' . $table . '.' . $col . ' إلى ' . $want;
            }
        } catch (\Throwable $e) {
            social_log_error('widen ' . $table . '.' . $col . ': ' . $e->getMessage());
        }
    }

    $st = social_schema_status();
    if ($st['ok']) {
        return $done;
    }

    $ddl = [];
    if (in_array('social_connections', $st['tables'], true)) {
        $ddl['social_connections'] = "CREATE TABLE IF NOT EXISTS `social_connections` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `platform` ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook',
            `provider_page_id` VARCHAR(64) NOT NULL,
            `page_name` VARCHAR(255) NOT NULL,
            `page_avatar_url` VARCHAR(1000) NULL,
            `ig_user_id` VARCHAR(64) NULL,
            `ig_username` VARCHAR(128) NULL,
            `access_token_enc` TEXT NOT NULL,
            `token_type` ENUM('page','user') NOT NULL DEFAULT 'page',
            `scopes` TEXT NULL,
            `expires_at` DATETIME NULL,
            `status` ENUM('active','expired','revoked','error') NOT NULL DEFAULT 'active',
            `last_error` TEXT NULL,
            `last_verified_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_page` (`user_id`,`platform`,`provider_page_id`),
            INDEX `idx_user_status` (`user_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    if (in_array('feature_flags', $st['tables'], true)) {
        $ddl['feature_flags'] = "CREATE TABLE IF NOT EXISTS `feature_flags` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `feature_key` VARCHAR(64) NOT NULL UNIQUE,
            `label_ar` VARCHAR(128) NOT NULL,
            `default_mode` ENUM('off','on','allowlist') NOT NULL DEFAULT 'off',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    if (in_array('user_feature_access', $st['tables'], true)) {
        $ddl['user_feature_access'] = "CREATE TABLE IF NOT EXISTS `user_feature_access` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `feature_key` VARCHAR(64) NOT NULL,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `max_pages` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `granted_by` INT NULL,
            `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NULL,
            UNIQUE KEY `uniq_user_feature` (`user_id`,`feature_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    if (in_array('publish_logs', $st['tables'], true)) {
        $ddl['publish_logs'] = "CREATE TABLE IF NOT EXISTS `publish_logs` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `post_id` INT NULL,
            `connection_id` BIGINT UNSIGNED NULL,
            `action` VARCHAR(64) NOT NULL,
            `http_status` SMALLINT NULL,
            `request_json` TEXT NULL,
            `response_json` TEXT NULL,
            `duration_ms` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_post` (`post_id`), INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }
    foreach ($ddl as $name => $sql) {
        try {
            db_run($sql);
            $done[] = 'أُنشئ جدول ' . $name;
        } catch (\Throwable $e) {
            social_log_error('ensure_schema فشل إنشاء ' . $name . ': ' . $e->getMessage());
        }
    }

    // الأعمدة الناقصة
    $colDdl = [
        'social_connections.ig_user_id' => "ALTER TABLE `social_connections` ADD COLUMN `ig_user_id` VARCHAR(64) NULL",
        'social_connections.ig_username' => "ALTER TABLE `social_connections` ADD COLUMN `ig_username` VARCHAR(128) NULL",
        'social_connections.page_avatar_url' => "ALTER TABLE `social_connections` ADD COLUMN `page_avatar_url` VARCHAR(1000) NULL",
        'contents.connection_id' => "ALTER TABLE `contents` ADD COLUMN `connection_id` BIGINT UNSIGNED NULL",
        'contents.publish_status' => "ALTER TABLE `contents` ADD COLUMN `publish_status` ENUM('draft','pending','processing','published','failed','cancelled') NOT NULL DEFAULT 'draft'",
        'contents.attempts' => "ALTER TABLE `contents` ADD COLUMN `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0",
        'contents.next_attempt_at' => "ALTER TABLE `contents` ADD COLUMN `next_attempt_at` DATETIME NULL",
        'contents.lock_token' => "ALTER TABLE `contents` ADD COLUMN `lock_token` CHAR(36) NULL",
    ];
    $st2 = social_schema_status();
    foreach ($st2['columns'] as $col) {
        if (isset($colDdl[$col])) {
            try {
                db_run($colDdl[$col]);
                $done[] = 'أُضيف عمود ' . $col;
            } catch (\Throwable $e) {
                social_log_error('ensure_schema فشل عمود ' . $col . ': ' . $e->getMessage());
            }
        }
    }

    // بذرة الميزة
    try {
        db_run("INSERT IGNORE INTO feature_flags (feature_key, label_ar, default_mode) VALUES ('social_publishing', 'النشر التلقائي على السوشيال', 'allowlist')");
    } catch (\Throwable $e) {
    }

    return $done;
}


/**
 * الطول الأقصى الفعلي لعمود (من information_schema) — عشان نقص القيم قبل الحفظ
 * بدل ما الاستعلام يقع بخطأ 1406
 */
function social_column_limit(string $table, string $column, int $fallback = 255): int
{
    static $cache = [];
    $k = $table . '.' . $column;
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    try {
        $r = db_one(
            'SELECT CHARACTER_MAXIMUM_LENGTH len FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        $len = (int) ($r['len'] ?? 0);
        return $cache[$k] = $len > 0 ? $len : $fallback;
    } catch (\Throwable $e) {
        return $cache[$k] = $fallback;
    }
}

/** قص نص ليناسب عمودًا بعينه (بهامش أمان) */
function social_fit(string $value, string $table, string $column, int $fallback = 255): string
{
    $limit = social_column_limit($table, $column, $fallback);
    return mb_substr($value, 0, max(1, $limit - 2));
}

/* ═══════════════ النشر الفوري والجدولة على فيسبوك ═══════════════ */

/** ساعة النشر الافتراضية (بتوقيت مصر) */
function default_publish_hour(): int
{
    $h = (int) get_setting('default_publish_hour', 21);
    return ($h >= 0 && $h <= 23) ? $h : 21;
}

/** تاريخ + الساعة الافتراضية → 'Y-m-d H:i:s' */
function default_publish_datetime(string $date): string
{
    return $date . ' ' . str_pad((string) default_publish_hour(), 2, '0', STR_PAD_LEFT) . ':00:00';
}

/**
 * تجهيز نص البوست + صورته
 */
function content_publish_payload(array $content): array
{
    $message = trim((string) ($content['generated_text'] ?? ''));
    if (!empty($content['cta'])) {
        $message .= "\n\n" . trim((string) $content['cta']);
    }
    if (!empty($content['hashtags'])) {
        $message .= "\n\n" . trim((string) $content['hashtags']);
    }

    require_once __DIR__ . '/content-formats.php';
    $images = content_publish_images($content);
    $imageUrl = $images[0] ?? null;

    return ['message' => mb_substr($message, 0, 60000), 'image_url' => $imageUrl, 'images' => $images];
}

/**
 * النشر على فيسبوك — فورًا أو بجدولة على فيسبوك نفسه
 * $scheduleTs = null → نشر فوري | timestamp → فيسبوك يمسك البوست وينشره في معاده
 * فيسبوك بيقبل الجدولة بين 10 دقايق و6 شهور من دلوقتي
 */
function fb_publish(array $content, array $conn, ?int $scheduleTs = null): array
{
    $token = social_token_decrypt($conn['access_token_enc']);
    if (!$token) {
        return ['ok' => false, 'error' => 'تعذّر فك تشفير التوكن', 'permanent' => true];
    }

    $payload = content_publish_payload($content);
    $logCtx = ['post_id' => (int) $content['id'], 'connection_id' => (int) $conn['id']];

    $params = ['access_token' => $token];
    $scheduled = false;

    if ($scheduleTs !== null) {
        $min = time() + 600;          // 10 دقائق
        $max = time() + 15552000;     // 6 شهور
        if ($scheduleTs < $min) {
            $scheduleTs = null;       // قريب أوي → انشر فورًا
        } elseif ($scheduleTs > $max) {
            return ['ok' => false, 'error' => 'فيسبوك بيقبل الجدولة لحد 6 شهور مقدمًا فقط', 'permanent' => true];
        }
    }
    if ($scheduleTs !== null) {
        $params['published'] = 'false';
        $params['scheduled_publish_time'] = $scheduleTs;
        $scheduled = true;
    }

    $r = social_fb_send($content, $conn, $token, $payload['message'], $payload['images'], $scheduled ? $scheduleTs : null, $logCtx);
    if (!$r['ok']) {
        return $r;
    }

    return [
        'ok' => true,
        'post_id' => (string) $r['post_id'],
        'scheduled' => $scheduled,
        'scheduled_ts' => $scheduleTs,
    ];
}

/**
 * النشر على انستجرام (فوري فقط — انستجرام مبيدعمش الجدولة عبر الـ API)
 */
function ig_publish(array $content, array $conn): array
{
    if (empty($conn['ig_user_id'])) {
        return ['ok' => false, 'error' => 'الصفحة مش مربوط بيها حساب انستجرام بيزنس', 'permanent' => true];
    }
    $token = social_token_decrypt($conn['access_token_enc']);
    if (!$token) {
        return ['ok' => false, 'error' => 'تعذّر فك تشفير التوكن', 'permanent' => true];
    }
    $payload = content_publish_payload($content);
    $logCtx = ['post_id' => (int) $content['id'], 'connection_id' => (int) $conn['id']];
    return social_ig_send($content, $conn, $token, $payload['message'], $payload['images'], $logCtx);
}

/** رابط البوست المنشور على فيسبوك */
function fb_post_url(string $providerPostId): string
{
    $id = trim($providerPostId);
    if ($id === '') {
        return '';
    }
    return 'https://www.facebook.com/' . str_replace('_', '/posts/', $id);
}

/** فصل صفحة: إلغاء البوستات المحجوزة عليها + حذف التوكن نهائيًا (من «حساباتي المربوطة» ومركز الإعدادات) */
function social_disconnect(array $conn): void
{
    db_run('UPDATE contents SET publish_status = "cancelled", connection_id = NULL WHERE connection_id = ? AND publish_status IN ("pending","processing")', [$conn['id']]);
    db_run('DELETE FROM social_connections WHERE id = ?', [$conn['id']]);
}
