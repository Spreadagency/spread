<?php
/**
 * تركيب موقع Spread AI — يشتغل مرة واحدة وبعدها امسحه
 */
require_once dirname(__DIR__) . '/site/functions.php';

$hasAdmin = (int) (s_one('SELECT COUNT(*) c FROM site_admins')['c'] ?? 0) > 0;
$done = false;
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasAdmin) {
    s_decode_b64();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
        $err = 'املأ البيانات — الباسورد 8 حروف على الأقل';
    } else {
        s_insert('INSERT INTO site_admins (name, email, password) VALUES (?, ?, ?)',
            [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        $done = true;
    }
}

$checks = [
    'اتصال قاعدة بيانات الموقع' => (bool) s_one('SELECT 1 AS ok'),
    'جداول الموقع موجودة' => (bool) s_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'site_settings'"),
    'مجلد الصور قابل للكتابة' => is_dir(SITE_UPLOAD_DIR) ? is_writable(SITE_UPLOAD_DIR) : @mkdir(SITE_UPLOAD_DIR, 0755, true),
    'نسخة PHP 8+' => version_compare(PHP_VERSION, '8.0.0', '>='),
];
?><!DOCTYPE html><html lang="ar" dir="rtl"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>تركيب موقع Spread AI</title>
<link href="https://fonts.googleapis.com/css2?family=Almarai:wght@700;800&family=IBM+Plex+Sans+Arabic:wght@400;600&display=swap" rel="stylesheet">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F5F2EC;font-family:"IBM Plex Sans Arabic",sans-serif;padding:20px;line-height:1.8}
.box{background:#fff;border-radius:20px;padding:34px;width:100%;max-width:520px;box-shadow:0 20px 50px -30px rgba(0,0,0,.3)}
h1{font-family:Almarai;font-size:22px;margin:0 0 18px}
table{width:100%;border-collapse:collapse;margin-bottom:20px;font-size:14px}
td{padding:8px 4px;border-bottom:1px solid #eee}
label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
input{width:100%;padding:11px 13px;border:1px solid #ddd;border-radius:10px;font-family:inherit;font-size:14px;margin-bottom:14px;box-sizing:border-box}
button{width:100%;padding:12px;border:0;border-radius:10px;background:#0F3CC9;color:#fff;font-family:inherit;font-weight:600;font-size:15px;cursor:pointer}
.ok{color:#0f7a5f;font-weight:700}.no{color:#c0392b;font-weight:700}
.msg{padding:12px 15px;border-radius:10px;margin-bottom:16px;font-size:14px}
.err{background:#fdecea;color:#c0392b}.suc{background:#e8f7f1;color:#0f7a5f}
</style><script src="../site-assets/js/admin.js" defer></script></head><body>
<div class="box">
<h1>🚀 تركيب موقع Spread AI</h1>
<table>
<?php foreach ($checks as $label => $ok): ?>
  <tr><td><?= e($label) ?></td><td style="text-align:end"><span class="<?= $ok ? 'ok' : 'no' ?>"><?= $ok ? '✓ تمام' : '✕ فيه مشكلة' ?></span></td></tr>
<?php endforeach; ?>
</table>

<?php if ($done): ?>
  <div class="msg suc">✅ تم إنشاء حساب الإدارة بنجاح!<br><b>امسح ملف install.php دلوقتي</b> عشان الأمان.</div>
  <a href="login.php"><button type="button">ادخل للوحة التحكم ←</button></a>
<?php elseif ($hasAdmin): ?>
  <div class="msg suc">الموقع متركّب بالفعل. <b>امسح ملف install.php.</b></div>
  <a href="login.php"><button type="button">دخول لوحة التحكم</button></a>
<?php elseif (in_array(false, $checks, true)): ?>
  <div class="msg err">صلّح النقط الحمرا فوق الأول. راجع بيانات قاعدة البيانات في <code>site/config.php</code> ونفّذ <code>sql/site-schema.sql</code>.</div>
<?php else: ?>
  <?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
  <form method="POST" data-safe-post>
    <label>اسمك</label><input type="text" name="name" required>
    <label>الإيميل</label><input type="email" name="email" required dir="ltr">
    <label>كلمة المرور (8 حروف على الأقل)</label><input type="password" name="password" required minlength="8">
    <button type="submit">إنشاء حساب الإدارة</button>
  </form>
<?php endif; ?>
</div></body></html>
