<?php
/**
 * /api/v1/handoff.php?code=… — فتح الموقع من التطبيق بنفس الحساب (متصفح عادي — جلسة بكوكيز)
 *   الكود بيطلع من app.php?action=web_handoff · صالح دقيقتين · مرة واحدة بس · لصفحات محددة
 *   (ربط صفحة فيسبوك مثلًا: موافقة فيسبوك لازم تتم في المتصفح)
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/rate-limit.php';
require_once __DIR__ . '/../../../includes/mobile.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

$code = (string) ($_GET['code'] ?? '');
if (!rate_limit('m_handoff_open', client_ip(), 20, 600) || !preg_match('/^spm_[A-Za-z0-9_\-]{40,80}$/', $code)) {
    redirect('login.php');
}

mobile_ensure_schema();
$row = mobile_token_row($code, 'handoff');
// مرة واحدة بس: اللي يلحق يعلّمه «مستخدم» هو اللي يدخل
if (!$row || !db_one('SELECT 1 ok FROM mobile_tokens WHERE id = ? AND revoked_at IS NULL', [$row['id']])) {
    flash_set('warning', 'الرابط انتهى — افتحه تاني من التطبيق');
    redirect('login.php');
}
db_run('UPDATE mobile_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL', [$row['id']]);
if (db()->query('SELECT ROW_COUNT()')->fetchColumn() < 1) {
    redirect('login.php');
}

$user = db_one('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
if (!$user || mobile_account_block($user)) {
    redirect('login.php');
}

if ((int) ($_SESSION['user_id'] ?? 0) !== (int) $user['id']) {
    login_user((int) $user['id'], false);
}
$target = auth_safe_next((string) $row['target']) ?? 'dashboard.php';
redirect($target);
