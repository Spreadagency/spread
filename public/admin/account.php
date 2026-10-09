<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

$user = admin_require('view');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_post('view');
    $row = q_row('SELECT password_hash FROM admins WHERE id = ?', [$user['id']]);
    $current = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    if (!password_verify($current, (string) $row['password_hash'])) {
        flash('err', 'الباسورد الحالي غلط.');
    } elseif (mb_strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        flash('err', 'الباسورد الجديد لازم يكون 10 حروف على الأقل وفيه حروف وأرقام.');
    } elseif ($new !== $confirm) {
        flash('err', 'التأكيد مش مطابق.');
    } elseif (password_verify($new, (string) $row['password_hash'])) {
        flash('err', 'اختار باسورد مختلف عن الحالي.');
    } else {
        q('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
        session_regenerate_id(true);
        admin_log('password_changed');
        flash('ok', 'اتغيّر الباسورد.');
        redirect('index.php');
    }
    redirect('account.php');
}

admin_page_start('الحساب', '');
?>
<div class="page-head"><div><p>بيانات حسابك وتغيير الباسورد</p></div></div>
<?php if ($user['must_change_password']): ?>
<div class="banner"><span class="bi"><?= ic('shield') ?></span><div class="small"><b style="color:var(--ink)">غيّر الباسورد المؤقت الأول.</b> ده حساب جديد أو الباسورد اتعمله Reset.</div></div>
<?php endif; ?>
<div class="grid g-1-1" style="align-items:start">
  <section class="card"><div class="card-h"><h2>بياناتك</h2></div><div class="card-b">
    <dl class="kv"><dt>الاسم</dt><dd><?= e($user['name']) ?></dd><dt>الإيميل</dt><dd class="ltr"><?= e($user['email']) ?></dd><dt>الصلاحية</dt><dd class="ltr"><?= e(ucfirst($user['role'])) ?></dd><dt>آخر دخول</dt><dd><?= e(local_dt($user['last_login_at'])) ?></dd></dl>
  </div></section>
  <form class="card" method="post"><?= csrf_field() ?>
    <div class="card-h"><h2>تغيير الباسورد</h2></div>
    <div class="card-b stack">
      <div class="field"><label for="cur">الباسورد الحالي</label><input class="input" dir="ltr" type="password" id="cur" name="current" autocomplete="current-password" required></div>
      <div class="field"><label for="new">الباسورد الجديد</label><input class="input" dir="ltr" type="password" id="new" name="new" autocomplete="new-password" minlength="10" required><span class="hint">10 حروف على الأقل، فيه حروف وأرقام.</span></div>
      <div class="field"><label for="conf">تأكيد الباسورد</label><input class="input" dir="ltr" type="password" id="conf" name="confirm" autocomplete="new-password" required></div>
    </div>
    <div class="card-f"><button class="btn btn-primary" type="submit" data-loading><?= ic('check', 'sm') ?>احفظ الباسورد</button></div>
  </form>
</div>
<?php
admin_page_end();
