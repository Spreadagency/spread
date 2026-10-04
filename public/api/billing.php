<?php
/**
 * API الباقات والدفع (المرحلة 10)
 *   POST action=quote   {package_id, code, method?}     حساب كود الخصم من السيرفر (السعر النهائي · الهدية)
 *   POST action=submit  (multipart: package_id, method, promo_code, transaction_ref, transferred_at, note, phone, proof)
 *   POST action=cancel  {id}                              إلغاء طلب لسه بيتراجع
 *   POST action=respond (multipart: id, note, proof?)     رد على «طلب معلومات إضافية»
 *   POST action=notif_read {id?}                          قراءة إشعار (أو الكل)
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/billing.php';

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action();

if (!billing_ready()) api_fail('الدفع مش متاح دلوقتي — كلّم الدعم', 'unavailable', 503);

switch ($action) {

    case 'quote':
        if (!rate_limit('promo_quote', 'u' . $uid, 20, 600)) api_fail('محاولات كتير — استنى شوية', 'rate_limit', 429);
        $pkg = billing_package(api_int('package_id'));
        if (!$pkg) api_fail('الباقة دي مش متاحة', 'not_found', 404);
        $code = api_str('code', 40);
        if ($code === '') api_fail('اكتب الكود', 'empty', 422);
        $q = promo_quote($code, $user, $pkg, api_str('method', 40));
        if (!$q['ok']) api_fail($q['message'] ?: 'الكود غير صحيح أو منتهي', 'invalid', 422, ['reason' => $q['reason'] ?? '']);
        api_ok(['quote' => [
            'code' => $q['code'], 'original' => $q['original'], 'discount' => $q['discount'], 'final' => $q['final'],
            'bonus_credits' => $q['bonus_credits'] + (int) ($pkg['bonus_credits'] ?? 0), 'promo_bonus' => $q['bonus_credits'],
            'extra_days' => $q['extra_days'], 'lines' => $q['lines'], 'label' => promo_reward_label($q['offer']), 'methods' => $q['methods'],
        ]]);

    case 'submit':
        if (!rate_limit('payment_submit', 'u' . $uid, 8, 3600)) api_fail('طلبات كتير — استنى شوية', 'rate_limit', 429);
        $in = [
            'package_id' => api_int('package_id'), 'method' => api_str('method', 40), 'promo_code' => api_str('promo_code', 40),
            'transaction_ref' => api_str('transaction_ref', 120), 'transferred_at' => api_str('transferred_at', 30),
            'note' => api_str('note', 1000), 'phone' => api_str('phone', 30),
        ];
        $r = billing_create_request($user, $in, $_FILES['proof'] ?? null);
        if (!$r['ok']) api_fail($r['error'], 'invalid', 422, isset($r['existing']) ? ['existing' => $r['existing']] : []);
        api_ok(['id' => $r['id'], 'redirect' => url('payments.php?id=' . $r['id'] . '&sent=1')]);

    case 'cancel':
        $r = billing_user_cancel(api_int('id'), $uid);
        if (!$r['ok']) api_fail($r['error'], 'state', 409);
        api_ok(['cancelled' => true]);

    case 'respond':
        if (!rate_limit('payment_submit', 'u' . $uid, 8, 3600)) api_fail('محاولات كتير — استنى شوية', 'rate_limit', 429);
        $r = billing_user_respond(api_int('id'), $uid, api_str('note', 1000), $_FILES['proof'] ?? null);
        if (!$r['ok']) api_fail($r['error'], 'invalid', 422);
        api_ok(['sent' => true]);

    case 'notif_read':
        $id = api_int('id');
        db_run('UPDATE user_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL' . ($id ? ' AND id = ?' : ''), $id ? [$uid, $id] : [$uid]);
        api_ok(['read' => true]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
