<?php
/**
 * Spread AI v2 — Webhook ميتا
 * GET: التحقق (hub.challenge) · POST: deauthorize → تعليم الاتصالات revoked
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/social.php';

// ─── التحقق الأولي من ميتا ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify = (string) get_setting('meta_webhook_verify_token', '');
    if ($verify !== ''
        && ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '') === 'subscribe'
        && hash_equals($verify, (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''))) {
        echo $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
        exit;
    }
    http_response_code(403);
    exit('Forbidden');
}

// ─── أحداث POST ───
$raw = file_get_contents('php://input') ?: '';

// تحقق التوقيع لو السيكرت موجود
$secret = meta_app_secret();
if ($secret !== '') {
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    if ($sig === '' || !hash_equals($expected, $sig)) {
        http_response_code(403);
        exit('Bad signature');
    }
}

$payload = json_decode($raw, true) ?: [];

// deauthorize: المستخدم شال التطبيق → علّم اتصالاته
foreach (($payload['entry'] ?? []) as $entry) {
    $pageId = (string) ($entry['id'] ?? '');
    if ($pageId !== '') {
        db_run(
            'UPDATE social_connections SET status = "revoked", last_error = "المستخدم ألغى صلاحية التطبيق من فيسبوك" WHERE provider_page_id = ?',
            [$pageId]
        );
        db_run(
            'UPDATE contents c JOIN social_connections sc ON sc.id = c.connection_id
             SET c.publish_status = "cancelled", c.publish_error = "أُلغي: الصفحة فقدت الصلاحية"
             WHERE sc.provider_page_id = ? AND c.publish_status IN ("pending","processing")',
            [$pageId]
        );
    }
}

// سجل الحدث
try {
    db_run('INSERT INTO publish_logs (action, http_status, response_json) VALUES (?, 200, ?)',
        ['webhook_event', mb_substr($raw, 0, 4000)]);
} catch (\Throwable $e) {
}

echo 'OK';
