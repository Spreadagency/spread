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
    } elseif (!rate_limit('resend', client_ip(), 5, 3600)) {
        $message = 'محاولات كتير. جرب تاني بعد ساعة.';
        $messageType = 'danger';
    } else {
        $user = db_one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);

        if ($user && !$user['email_verified_at'] && $user['status'] === 'active') {
            // Throttle: max one resend every 2 minutes per user
            $recent = db_one(
                'SELECT id FROM email_verifications
                 WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                 ORDER BY created_at DESC LIMIT 1',
                [$user['id']]
            );

            if (!$recent) {
                $token = create_verification_token((int) $user['id']);
                send_verification_email($user['name'], $email, $token);
            }
        }

        // Always show same message (no user enumeration)
        $message = 'لو الإيميل موجود ومش مفعل، هيوصلك رابط تفعيل جديد خلال دقايق. لو مكانش وصل، استنى دقيقتين وجرب تاني.';
        $messageType = 'success';
    }
}

$page_title = 'إعادة إرسال رابط التفعيل';
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

        <h2>إعادة إرسال رابط التفعيل ✉️</h2>
        <p class="sub">دخّل بريدك الإلكتروني وهنبعت لك رابط تفعيل جديد</p>

        <?= render_flash() ?>

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
                إرسال رابط التفعيل
            </button>
        </form>

        <div class="auth-divider">أو</div>

        <a href="<?= url('login.php') ?>" class="btn ghost full">العودة لتسجيل الدخول</a>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
