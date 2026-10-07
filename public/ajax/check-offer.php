<?php
/** تحقق لحظي من كود العرض */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/offers.php';

header('Content-Type: application/json; charset=utf-8');
decode_b64_fields();

$ip = offer_client_ip();
if (!rate_limit('check_offer', 'ip' . $ip, 10, 60)) {
    echo json_encode(['ok' => false, 'message' => 'محاولات كتير — استنى دقيقة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = strtoupper(trim((string) ($_POST['code'] ?? $_GET['code'] ?? '')));
if ($code === '') {
    echo json_encode(['ok' => false, 'message' => 'اكتب الكود'], JSON_UNESCAPED_UNICODE);
    exit;
}

$u = is_logged_in() ? current_user() : ['id' => 0, 'identity_hash' => null, 'phone' => null, 'email' => null];
$idh = $u['identity_hash'] ?? identity_hash_for($u['phone'] ?? null, $u['email'] ?? null);

$res = offer_eligibility($code, $u, [
    'role' => 'referee',
    'identity_hash' => $idh,
    'package_id' => (int) ($_POST['package_id'] ?? 0) ?: null,
]);

if (!$res['ok']) {
    echo json_encode(['ok' => false, 'message' => $res['message'] ?: 'الكود غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

$o = $res['offer'];
$credits = (int) $o['referee_credits'];
$validity = $o['credit_validity_days'] !== null ? (int) $o['credit_validity_days'] : (int) get_setting('offer_default_validity_days', 30);

echo json_encode([
    'ok' => true,
    'title' => $o['title'],
    'credits' => $credits,
    'validity_days' => $validity,
    'message' => $credits > 0
        ? "✓ الكود شغّال — هتاخد {$credits} كريدت صلاحية {$validity} يوم"
        : '✓ الكود شغّال',
], JSON_UNESCAPED_UNICODE);
