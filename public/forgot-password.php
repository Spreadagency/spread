<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/rate-limit.php';

if (is_logged_in()) redirect('dashboard.php');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');

    if (!valid_email($email)) {
        $message = 'البريد الإلكتروني غير صحيح';
        $messageType = 'danger';
    } elseif (!rate_limit('forgot', client_ip(), 5, 3600)) {
        $message = 'محاولات كتير. جرب تاني بعد ساعة.';
        $messageType = 'danger';
    } else {
        $user = db_one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($user) {
            $token = random_token(64);
            db_run(
                'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)',
                [$user['id'], $token, date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL)]
            );
            send_reset_email($user['name'], $email, $token);
        }
        // Always show same message for security
        $message = 'لو الإيميل موجود في النظام، هتلاقي رابط إعادة التعيين خلال دقايق';
        $messageType = 'success';
    }
}

$page_title = 'نسيت كلمة المرور';
include __DIR__ . '/../templates/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <div class="brand">
            <div class="brand-mark">S</div>
            <div>
                <div class="brand-name"><?= e(APP_NAME) ?></div>
                <div class="brand-sub"><?= e(APP_TAGLINE) ?></div>
            </div>
        </div>

        <h2>نسيت كلمة المرور؟ 🔑</h2>
        <p class="sub">دخّل بريدك الإلكتروني وهنبعت لك رابط إعادة التعيين</p>

        <?php if ($message): ?>
            <div class="alert <?= e($messageType) ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="field">
                <label>البريد الإلكتروني</label>
                <input type="email" name="email" class="input" required placeholder="you@example.com">
            </div>

            <button type="submit" class="btn full lg">
                إرسال رابط الاستعادة
            </button>
        </form>

        <div class="auth-divider">أو</div>

        <a href="<?= url('login.php') ?>" class="btn ghost full">العودة لتسجيل الدخول</a>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
