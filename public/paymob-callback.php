<?php
/**
 * Paymob transaction callback (GET redirect after payment).
 * Verifies HMAC, marks the order paid, and adds credits — idempotent.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/integrations.php';
require_once __DIR__ . '/../includes/usage.php'; // admin_alert

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
    // الدفع + الكريدت + الباقة في transaction واحدة: لو الشحن فشل → كله بيرجع والطلب يفضل pending (يتعاد بأمان)
    $pdo = db();
    try {
        $pdo->beginTransaction();
        // 8-ب: تحديث مشروط — لو الكول باك وصل مرتين في نفس اللحظة، واحد بس يشحن ويبدأ الدورة
        $__st = $pdo->prepare('UPDATE payment_orders SET status = ?, paymob_txn_id = ?, paid_at = ? WHERE id = ? AND status <> ?');
        $__st->execute(['paid', $txnId, date('Y-m-d H:i:s'), $order['id'], 'paid']);
        if ($__st->rowCount() !== 1) {
            $pdo->commit();
            flash_set('success', 'تم شحن رصيدك بنجاح ✓');
            header('Location: ' . url('credits.php'));
            exit;
        }
        // صلاحية الكريدت = مدة الباقة (نفس نهاية الدورة)
        $__pkg = !empty($order['package_id']) ? db_one('SELECT validity_days FROM credit_packages WHERE id = ?', [(int) $order['package_id']]) : null;
        $__ok = credits_add((int) $order['user_id'], (int) $order['credits'],
            'شحن رصيد: ' . $order['credits'] . ' كريدت (طلب #' . $order['id'] . ')',
            null, 'purchase', (int) $order['id'], !empty($__pkg['validity_days']) ? (int) $__pkg['validity_days'] : null,
            ['idem_key' => 'po:' . (int) $order['id']]);
        if (!$__ok) throw new RuntimeException('credits_add failed');
        // 8-ب: دورة باقة جديدة (الحصص الشهرية بتبدأ من دلوقتي)
        if (function_exists('plan_start') && function_exists('plans_ready') && plans_ready()) {
            if (!plan_start((int) $order['user_id'], (int) $order['package_id'], (int) $order['credits'], null, 'paid', null, 'Paymob #' . $order['id'])) {
                throw new RuntimeException('plan_start failed');
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        integrations_log('paymob', 'order ' . $order['id'] . ' PAID at Paymob but crediting FAILED (rolled back, still pending): ' . $e->getMessage());
        if (function_exists('admin_alert')) {
            admin_alert('critical', 'payment', 'دفع Paymob اتم والرصيد ماتضافش — طلب #' . $order['id'],
                'العميل #' . $order['user_id'] . ' دفع ' . $order['credits'] . ' كريدت والشحن فشل واترجع (الطلب لسه pending). أعد فتح لينك الرجوع أو ضيف الرصيد يدوي. الخطأ: ' . $e->getMessage(),
                'admin/payments.php', 'paymob_fail:' . $order['id']);
        }
        flash_set('danger', 'الدفع وصلنا بس حصلت مشكلة في شحن الرصيد — فريقنا اتبلّغ وهيضيفه في أقرب وقت (طلب #' . $order['id'] . ').');
        header('Location: ' . url('credits.php'));
        exit;
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
