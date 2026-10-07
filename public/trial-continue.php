<?php
/**
 * Spread AI — الكمال من «اصنع منشورك» للتصميم (المشتركين)
 * الموقع بيودّي هنا بعد ما يتأكد إن العميل مشترك: المنشور بيتنقل لحسابه (نفس trial_claim — صفر كريدت)
 * وبيفتح مرحلة التصميم جوه المنصة (Generate Design ← Preview ← Save ← Schedule ← Publish زي ما هي).
 * مش داخل ← صفحة الدخول بنفس التوكن (المسار القديم) · مش مشترك ← المنشور بيتحفظ وشاشة الاشتراك بتظهر مكان التصميم.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/credits.php';

trial_capture_token();
if (!is_logged_in()) {
    redirect('login.php' . (!empty($_SESSION['trial_token']) ? '?trial=' . urlencode($_SESSION['trial_token']) : ''));
}
require_login();

$cid = trial_claim((int) current_user()['id']);
if ($cid) {
    flash_set('success', 'المنشور اللي عملته في التجربة اتحفظ في حسابك ✓ — كمّل التصميم من هنا');
    redirect('content-view.php?id=' . $cid . '#design');
}
flash_set('info', 'التجربة دي اتحفظت قبل كده — تلاقي منشورك في «المحتوى».');
redirect('content-history.php');
