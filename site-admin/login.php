<?php
require_once __DIR__ . '/auth.php';
if (sa_admin()) s_redirect('site-admin/dashboard.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
    if (sa_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        s_redirect('site-admin/dashboard.php');
    }
    $err = 'بيانات الدخول غير صحيحة';
}
$__v = @filemtime(dirname(__DIR__) . '/site-assets/css/site-admin.css') ?: 1;
?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>دخول إدارة الموقع — Spread AI</title>
<link rel="icon" href="<?= e(s_url('site-assets/img/spread-mark.png')) ?>">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(s_url('site-assets/css/site-admin.css')) ?>?v=<?= $__v ?>">
</head><body class="ad">
<main class="ad-login">
  <form class="ad-login-box sa-in" method="POST">
    <span class="ad-logo"><img src="<?= e(s_url('site-assets/img/spread-mark.png')) ?>" alt="" width="40" height="34"><span class="ad-logo-t" dir="ltr"><b>Spread <i>AI</i></b><small>WEBSITE OS</small></span></span>
    <h1>إدارة الموقع</h1>
    <p>لوحة التحكم في الموقع التعريفي — الصفحات والمحتوى والعروض.</p>
    <?php if ($err): ?><div class="ad-alert bad" role="alert"><?= sa_icon('alert', 18) ?><span><?= e($err) ?></span></div><?php endif; ?>
    <?= s_csrf_field() ?>
    <div class="ad-form one">
      <div class="ad-f"><label class="ad-label" for="em">البريد الإلكتروني</label><input class="ad-in" id="em" type="email" name="email" required autofocus dir="ltr" autocomplete="username"></div>
      <div class="ad-f"><label class="ad-label" for="pw">كلمة المرور</label><input class="ad-in" id="pw" type="password" name="password" required autocomplete="current-password"></div>
      <button type="submit" class="ad-btn ad-pri lg" style="width:100%"><?= sa_icon('lock', 17) ?><span>دخول</span></button>
    </div>
  </form>
</main>
</body></html>
