<?php
/**
 * Spread AI — الأدمن: مساعدات شاشة الإرسال (JSON)
 *   GET  action=users&q=…   بحث عن عميل (اسم · إيميل · موبايل)
 *   POST action=count       عدد الجمهور للفلتر (إجمالي · منهم واتساب)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/Notifier.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!is_admin_logged_in() || !admin_can('view_users')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
wa_ensure_schema();
$action = (string) ($_GET['action'] ?? '');

if ($action === 'users') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'users' => []]); exit; }
    $digits = preg_replace('/\D/', '', $q);
    $rows = db_all('SELECT id, name, email, phone, whatsapp_phone, whatsapp_verified, whatsapp_opt_in FROM users
                    WHERE name LIKE ? OR email LIKE ?' . ($digits !== '' && strlen($digits) >= 3 ? ' OR phone LIKE ? OR whatsapp_phone LIKE ?' : '') . '
                    ORDER BY id DESC LIMIT 12',
        array_merge(["%$q%", "%$q%"], $digits !== '' && strlen($digits) >= 3 ? ["%$digits%", "%$digits%"] : []));
    echo json_encode(['ok' => true, 'users' => array_map(fn($u) => [
        'id' => (int) $u['id'], 'name' => (string) $u['name'], 'email' => (string) $u['email'],
        'wa' => (int) $u['whatsapp_opt_in'] === 1 && wa_phone_for($u) !== '',
    ], $rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'count') {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_check()) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $type = (string) ($_POST['aud_type'] ?? 'all');
    if ($type === 'all') {
        $aud = ['type' => 'all'];
    } else {
        $aud = wa_audience_from_form($_POST);
    }
    [$n, $wa] = wa_audience_count($aud);
    echo json_encode(['ok' => true, 'total' => $n, 'wa' => $wa]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid']);
