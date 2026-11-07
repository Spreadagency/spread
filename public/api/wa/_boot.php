<?php
/**
 * Spread AI — بوابة n8n للواتساب (مش للمتصفح)
 * كل الطلبات لازم تبعت X-Spread-Key = wa_shared_secret (من لوحة الأدمن → الواتساب)، غير كده 401
 */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/credits.php';
require_once __DIR__ . '/../../../includes/Notifier.php';

function wa_json(array $data, int $code = 200): void
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (\Throwable $e) {
    error_log('[wa-api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    wa_json(['ok' => false, 'error' => 'server_error'], 500);
});

wa_ensure_schema();
if (!wa_check_key()) wa_json(['ok' => false, 'error' => 'unauthorized'], 401);
