<?php
/**
 * Spread AI — /api/v1 (تطبيق الموبايل) — تهيئة مشتركة
 *   لازم أول سطر في كل ملف v1:  require_once __DIR__ . '/_init.php';
 *   • الجلسة من توكن الجهاز (مفيش كوكيز) — includes/mobile-boot.php
 *   • ردود JSON دايمًا: {ok:true,…} أو {ok:false,error,code} بكود HTTP حقيقي
 */
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === '_init.php') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../includes/mobile-boot.php';   // قبل config.php (بداية الجلسة)
require_once __DIR__ . '/../../../includes/mobile.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// تطوير نسخة الويب من التطبيق (expo start --web) — مصادر مسموحة من الإعدادات بس (افتراضي: ولا واحد)
(function () {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') return;
    $allowed = array_filter(array_map('trim', explode(',', (string) get_setting('mobile_cors_origins', ''))));
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, X-Spread-Token, Content-Type, X-Payload, Idempotency-Key, X-App-Platform, X-App-Version, X-Device-Name');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 600');
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
})();

set_exception_handler(function (\Throwable $e) {
    error_log('[api/v1] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_fail('حصلت مشكلة مؤقتة — جرّب تاني بعد لحظة', 'server_error', 500);
});

mobile_ensure_schema();

/** حد طلبات عام لكل مستخدم/IP (نفس إعداد الـ API) */
function v1_rate(string $bucket, int $max, int $win, ?int $uid = null): void
{
    $key = $uid ? 'u' . $uid : 'ip' . client_ip();
    if (!rate_limit('m_' . $bucket, $key, $max, $win)) {
        api_fail('طلبات كتير بسرعة — استنى شوية وجرّب تاني', 'rate_limit', 429);
    }
}

function v1_require_post(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        api_fail('الطلب ده لازم يكون POST', 'method', 405);
    }
}
