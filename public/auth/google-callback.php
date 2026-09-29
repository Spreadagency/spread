<?php
/** رجوع جوجل بعد الموافقة */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/google-auth.php';

function google_fail(string $msg): void
{
    google_clear_session();
    flash_set('danger', $msg);
    redirect('login.php');
}

if (is_logged_in()) {
    redirect('dashboard.php');
}
if (!google_enabled()) {
    google_fail('تسجيل الدخول بجوجل مش مفعّل');
}

// المستخدم رفض الموافقة
if (!empty($_GET['error'])) {
    google_clear_session();
    flash_set('warning', $_GET['error'] === 'access_denied'
        ? 'لغيت تسجيل الدخول بجوجل'
        : 'جوجل رجّع خطأ: ' . mb_substr((string) $_GET['error'], 0, 60));
    redirect('login.php');
}

// التحقق من الـ state (حماية CSRF)
$state = (string) ($_GET['state'] ?? '');
if ($state === '' || empty($_SESSION['google_state']) || !hash_equals($_SESSION['google_state'], $state)) {
    google_log('state mismatch');
    google_fail('فشل التحقق الأمني — ابدأ من جديد');
}
// الجلسة لازم تكون حديثة (10 دقايق)
if (time() - (int) ($_SESSION['google_time'] ?? 0) > 600) {
    google_fail('انتهت مهلة العملية — حاول تاني');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    google_fail('جوجل مرجّعش كود التفويض');
}

$tok = google_exchange_code($code);
if (!$tok['ok']) {
    google_fail($tok['error']);
}

$g = google_verify_id_token($tok['id_token']);
if (!$g['ok']) {
    google_fail($g['error']);
}

$intent = $_SESSION['google_intent'] ?? 'login';
google_clear_session();

$res = google_find_or_create_user($g);
if (!$res['ok']) {
    flash_set('danger', $res['error'] ?? 'تعذّر تسجيل الدخول');
    redirect('login.php');
}

$userId = (int) $res['user_id'];
$user = db_one('SELECT * FROM users WHERE id = ?', [$userId]);

if (!$user || $user['status'] !== 'active') {
    google_fail('الحساب ده موقوف — كلّم الدعم');
}

// حساب جديد: نربط الإحالة ونصرف هدية الترحيب
if (!empty($res['is_new']) && function_exists('referral_attach_on_register')) {
    referral_attach_on_register($userId);
}

if (($user['approval_status'] ?? 'approved') === 'pending') {
    flash_set('warning', 'حسابك اتعمل وفي انتظار موافقة الإدارة — هنبعتلك إيميل أول ما يتفعّل.');
    redirect('login.php');
}
if (($user['approval_status'] ?? '') === 'rejected') {
    google_fail('طلب حسابك اترفض — كلّم الدعم');
}

// 10: مفيش رقم موبايل ← «أكمل حسابك» قبل أي دخول (الموبايل إجباري لحسابات جوجل)
if (function_exists('account_needs_phone') && account_needs_phone($user)) {
    session_regenerate_id(true);
    $_SESSION['phone_pending'] = ['uid' => $userId, 'is_new' => !empty($res['is_new']), 'linked' => !empty($res['linked']), 'at' => time()];
    redirect('complete-account.php');
}

google_finish_login($user, $res);
