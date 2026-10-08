<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

if (AdminAuth::user()) {
    redirect('index.php');
}
$error = '';
$email = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
// Only allow local admin paths as the post-login target.
if (!preg_match('#^/(?![/\\])[A-Za-z0-9/_\-.]*admin/[A-Za-z0-9_\-.]*\.php(\?[^\s]*)?$#', $next)) {
    $next = 'index.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'انتهت صلاحية الصفحة، جرّب تاني.';
    } else {
        $r = AdminAuth::attempt($email, (string) ($_POST['password'] ?? ''));
        if ($r['ok']) {
            redirect($next);
        }
        $error = $r['error'];
    }
}

admin_page_start('تسجيل الدخول', '');
?>
<form class="card login-card" method="post" novalidate>
  <img src="<?= e(asset((string) Settings::get('logo'))) ?>" alt="" class="login-logo">
  <div class="stack-8"><h2>تسجيل الدخول</h2><p class="muted small">لوحة تحكم حملة <?= e(Settings::get('hero_title')) ?></p></div>
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <?php if ($error): ?><div class="err-state" role="alert"><?= ic('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
  <div class="field"><label for="email">الإيميل</label><input class="input" dir="ltr" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></div>
  <div class="field"><label for="password">الباسورد</label><div class="input-group"><input class="input" dir="ltr" type="password" id="password" name="password" autocomplete="current-password" required><span class="addon"><button type="button" data-reveal="password" aria-label="إظهار"><?= ic('eye', 'sm') ?></button></span></div></div>
  <button class="btn btn-primary btn-lg" type="submit" data-loading><?= ic('key', 'sm') ?>دخول</button>
  <p class="made-login small muted">صنعت بواسطة <a href="https://spreadagency.net" target="_blank" rel="noopener">Spread</a></p>
</form>
<?php
admin_page_end();
