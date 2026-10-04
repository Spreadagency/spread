<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/rate-limit.php';

if (is_admin_logged_in()) {
    redirect('admin/dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!rate_limit_ok('admin_login', client_ip(), 5, 900)) {
        $result = ['ok' => false, 'error' => 'محاولات كتير. استنى 15 دقيقة وجرب تاني.'];
    } else {
        $result = authenticate_admin($email, $password);
        if (!$result['ok']) {
            rate_limit_hit('admin_login', client_ip());
        }
    }
    if ($result['ok']) {
        login_admin((int) $result['admin']['id']);
        admin_log('login', null, null, 'admin login');
        redirect('admin/dashboard.php');
    } else {
        $errors[] = $result['error'];
    }
}

$page_title = 'دخول الإدارة';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <div class="brand-mark" style="background:#4a3db8">⚡</div>
        <h1>لوحة الإدارة</h1>
        <p>دخول مخصص للمسؤولين فقط</p>

        <?php foreach ($errors as $err): ?>
            <div class="alert danger"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST">
            <?= csrf_field() ?>

            <div class="field">
                <label>البريد الإلكتروني</label>
                <input type="email" name="email" class="input" required autofocus>
            </div>

            <div class="field">
                <label>كلمة المرور</label>
                <input type="password" name="password" class="input" required>
            </div>

            <button type="submit" class="btn lg full" style="background:#4a3db8">دخول الإدارة</button>
        </form>

        <p class="text-mute" style="text-align:center;margin-top:20px;font-size:12px">
            <a href="<?= url('login.php') ?>" class="text-mute">← دخول المستخدمين</a>
        </p>
    </div>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
