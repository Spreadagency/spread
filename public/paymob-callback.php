<?php
/**
 * Paymob transaction callback (GET redirect after payment).
 * Verifies HMAC, marks the order paid, and adds credits — idempotent.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/integrations.php';

$success = ($_GET['success'] ?? '') === 'true';
$paymobOrderId = $_GET['order'] ?? '';
$txnId = $_GET['id'] ?? '';

$order = $paymobOrderId
    ? db_one('SELECT * FROM payment_orders WHERE paymob_order_id = ?', [$paymobOrderId])
    : null;

$msg = '';
$type = 'danger';

if (!$order) {
    $msg = 'الطلب غير موجود.';
} elseif (!paymob_verify_hmac($_GET)) {
    integrations_log('paymob', 'HMAC verification FAILED for order ' . $paymobOrderId);
    $msg = 'تعذر التحقق من عملية الدفع. لو خُصم منك مبلغ تواصل معنا.';
} elseif (!$success) {
    db_run('UPDATE payment_orders SET status = ?, paymob_txn_id = ? WHERE id = ? AND status = ?',
        ['failed', $txnId, $order['id'], 'pending']);
    $msg = 'لم تكتمل عملية الدفع. جرب تاني.';
} elseif ($order['status'] === 'paid') {
    // Idempotent: already processed
    $msg = 'تم شحن رصيدك بنجاح ✓';
    $type = 'success';
} else {
    // 8-ب: تحديث مشروط — لو الكول باك وصل مرتين في نفس اللحظة، واحد بس يشحن ويبدأ الدورة
    $__st = db()->prepare('UPDATE payment_orders SET status = ?, paymob_txn_id = ?, paid_at = ? WHERE id = ? AND status <> ?');
    $__st->execute(['paid', $txnId, date('Y-m-d H:i:s'), $order['id'], 'paid']);
    if ($__st->rowCount() !== 1) {
        flash_set('success', 'تم شحن رصيدك بنجاح ✓');
        header('Location: ' . url('credits.php'));
        exit;
    }
    // صلاحية الكريدت = مدة الباقة (نفس نهاية الدورة)
    $__pkg = !empty($order['package_id']) ? db_one('SELECT validity_days FROM credit_packages WHERE id = ?', [(int) $order['package_id']]) : null;
    credits_add((int) $order['user_id'], (int) $order['credits'],
        'شحن رصيد: ' . $order['credits'] . ' كريدت (طلب #' . $order['id'] . ')',
        null, 'purchase', (int) $order['id'], !empty($__pkg['validity_days']) ? (int) $__pkg['validity_days'] : null);
    // 8-ب: دورة باقة جديدة (الحصص الشهرية بتبدأ من دلوقتي)
    if (function_exists('plan_start')) {
        plan_start((int) $order['user_id'], (int) $order['package_id'], (int) $order['credits'], null, 'paid', null, 'Paymob #' . $order['id']);
    }
    integrations_log('paymob', 'order ' . $order['id'] . ' PAID — +' . $order['credits'] . ' credits for user ' . $order['user_id']);
    $msg = (function_exists('credits_show_numbers') && !credits_show_numbers())
        ? 'تم تفعيل باقتك بنجاح ✓ — الحصص الجديدة شغالة من دلوقتي'
        : 'تم شحن رصيدك بنجاح ✓ (+' . $order['credits'] . ' كريدت)';
    $type = 'success';
}

flash_set($type, $msg);
header('Location: ' . url('credits.php'));
exit;
