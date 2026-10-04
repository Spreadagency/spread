<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/integrations.php';
require_once __DIR__ . '/../../includes/rate-limit.php';

require_login();
require_csrf();

$user = current_user();
$packageId = (int) ($_POST['package_id'] ?? 0);

if (!rate_limit('buy', 'u' . $user['id'], 10, 3600)) {
    json_response(['ok' => false, 'error' => 'محاولات كتير. جرب تاني بعد شوية.']);
}

$package = db_one('SELECT * FROM credit_packages WHERE id = ? AND is_active = 1', [$packageId]);
if (!$package) {
    json_response(['ok' => false, 'error' => 'الباقة غير متاحة']);
}

// Local order first
$orderId = db_insert_row('payment_orders', [
    'user_id' => $user['id'],
    'package_id' => $package['id'],
    'credits' => $package['credits'],
    'amount_egp' => $package['price_egp'],
    'status' => 'pending',
]);

$payment = paymob_create_payment($user, $package, $orderId);
if (!$payment['ok']) {
    db_run('UPDATE payment_orders SET status = ? WHERE id = ?', ['failed', $orderId]);
    json_response(['ok' => false, 'error' => $payment['error']]);
}

db_run('UPDATE payment_orders SET paymob_order_id = ? WHERE id = ?', [$payment['paymob_order_id'], $orderId]);

json_response(['ok' => true, 'redirect' => $payment['iframe_url']]);
