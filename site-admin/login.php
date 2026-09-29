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
?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>دخول إدارة الموقع</title>
<link href="https://fonts.googleapis.com/css2?family=Almarai:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;600&display=swap" rel="stylesheet">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0E0E14;color:#F5F2EC;font-family:"IBM Plex Sans Arabic",sans-serif;padding:20px}
.box{background:#16161f;border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:34px;width:100%;max-width:380px}
h1{font-family:Almarai;font-size:21px;margin:0 0 6px}
p{color:rgba(245,242,236,.6);font-size:13.5px;margin:0 0 22px}
label{display:block;font-size:13px;margin-bottom:5px;font-weight:600}
input{width:100%;padding:11px 13px;border:1px solid rgba(255,255,255,.14);border-radius:10px;background:#0E0E14;color:#F5F2EC;font-family:inherit;font-size:14px;margin-bottom:14px;box-sizing:border-box}
button{width:100%;padding:12px;border:0;border-radius:10px;background:#0F3CC9;color:#fff;font-family:inherit;font-weight:600;font-size:14.5px;cursor:pointer}
button:hover{background:#14B8A6}
.err{background:rgba(192,57,43,.18);color:#ff9b90;padding:10px 13px;border-radius:10px;font-size:13px;margin-bottom:14px}
</style></head><body>
<form class="box" method="POST">
  <h1>إدارة الموقع</h1>
  <p>لوحة التحكم في الموقع التعريفي</p>
  <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
  <?= s_csrf_field() ?>
  <label>البريد الإلكتروني</label>
  <input type="email" name="email" required autofocus dir="ltr">
  <label>كلمة المرور</label>
  <input type="password" name="password" required>
  <button type="submit">دخول</button>
</form>
</body></html>
