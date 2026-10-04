<?php
require_once __DIR__ . '/../includes/auth.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$reset = null;
$errors = [];

if ($token) {
    $reset = db_one(
        'SELECT pr.*, u.email FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
         LIMIT 1',
        [$token]
    );
}

if (!$reset) {
    $page_title = 'رابط منتهي';
    include __DIR__ . '/../templates/header.php';
    ?>
    <div class="auth-wrap">
        <div class="auth-card text-center">
            <div class="brand" style="justify-content:center"><div class="brand-mark">S</div></div>
            <div style="font-size:64px;margin-bottom:8px">⌛</div>
            <h2>الرابط منتهي</h2>
            <p class="sub mb-20">رابط إعادة التعيين انتهت صلاحيته أو غير صحيح. اطلب رابط جديد.</p>
            <a href="<?= url('forgot-password.php') ?>" class="btn full lg">طلب رابط جديد</a>
        </div>
    </div>
    <?php
    include __DIR__ . '/../templates/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) $errors[] = 'كلمة المرور قصيرة (8 حروف على الأقل)';
    if ($password !== $confirm) $errors[] = 'الكلمتان غير متطابقتين';

    if (empty($errors)) {
        db_run(
            'UPDATE users SET password = ? WHERE id = ?',
            [password_hash($password, PASSWORD_BCRYPT), $reset['user_id']]
        );
        db_run('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$reset['id']]);
        // ⑥-أ: تاريخ آخر تغيير + خروج كل الأجهزة (لو الحساب اتسرق، الجلسات القديمة تقفل)
        try {
            db_run('UPDATE users SET password_changed_at = NOW() WHERE id = ?', [$reset['user_id']]);
            db_run('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$reset['user_id']]);
        } catch (\Throwable $e) { /* قبل الترحيل */ }

        flash_set('success', 'تم تغيير كلمة المرور بنجاح. سجّل دخولك بكلمة المرور الجديدة.');
        redirect('login.php');
    }
}

$page_title = 'إعادة تعيين كلمة المرور';
include __DIR__ . '/../templates/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <div class="brand">
            <div class="brand-mark">S</div>
            <div>
                <div class="brand-name"><?= e(APP_NAME) ?></div>
            </div>
        </div>

        <h2>كلمة مرور جديدة 🔐</h2>
        <p class="sub">اختر كلمة مرور جديدة لحسابك (<?= e($reset['email']) ?>)</p>

        <?php foreach ($errors as $err): ?>
            <div class="alert danger"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="field">
                <label>كلمة المرور الجديدة</label>
                <input type="password" name="password" class="input" required minlength="6">
            </div>

            <div class="field">
                <label>تأكيد كلمة المرور</label>
                <input type="password" name="password_confirm" class="input" required minlength="6">
            </div>

            <button type="submit" class="btn full lg">حفظ كلمة المرور الجديدة</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
