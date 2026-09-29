<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate-limit.php';

trial_capture_token();

if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!rate_limit_ok('login', client_ip(), 6, 600)) {
        $errors[] = 'محاولات كتير. استنى 10 دقايق وجرب تاني.';
        $result = ['ok' => false, 'error' => null, 'blocked' => true];
    } else {
        $result = authenticate_user($email, $password);
        if (!$result['ok']) {
            rate_limit_hit('login', client_ip());
        }
    }
    if ($result['ok']) {
        $user = $result['user'];
        if (!$user['email_verified_at']) {
            $errors[] = 'الحساب لسه مش مفعل. تشيك على بريدك، أو اطلب رابط تفعيل جديد من هنا: ' . url('resend-verification.php');
        } elseif (($user['approval_status'] ?? 'approved') === 'pending') {
            $errors[] = 'حسابك تحت المراجعة — الإدارة هتوافق عليه قريبًا وهيوصلك إيميل تأكيد.';
        } elseif (($user['approval_status'] ?? 'approved') === 'rejected') {
            $errors[] = 'عذرًا، لم تتم الموافقة على حسابك. تواصل مع الإدارة.';
        } else {
            // التحقق بخطوتين (لو مفعّل) ← كود على الإيميل وصفحة الكود — وإلا دخول عادي
            account_login_or_challenge($user);
            // نقل بيانات التجربة المجانية للحساب (لو جاي منها)
            $__trialContent = trial_claim((int) ($_SESSION['user_id'] ?? 0));
            if ($__trialContent) {
                flash_set('success', 'أهلًا بيك! المنشور اللي عملته في التجربة اتحفظ في حسابك ✓');
                redirect('content-view.php?id=' . $__trialContent);
            }
            redirect('dashboard.php');
        }
    } elseif (!empty($result['error'])) {
        $errors[] = $result['error'];
    }
}

$page_title = 'تسجيل الدخول';

// الشكل الجديد: شاشة الدخول من التصميم (نفس المنطق والحقول — العرض بس اللي اتغيّر)
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) {
    $authMode = 'login';
    include __DIR__ . '/../templates/v2/auth.php';
    exit;
}
include __DIR__ . '/../templates/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <div class="brand">
            <?= function_exists('site_logo_html') ? site_logo_html() : '<div class="brand-mark">S</div>' ?>
            <div>
                <div class="brand-name"><?= e(function_exists('site_name') ? site_name() : APP_NAME) ?></div>
                <div class="brand-sub"><?= e(function_exists('site_tagline') ? site_tagline() : APP_TAGLINE) ?></div>
            </div>
        </div>

        <h2>أهلًا بعودتك 👋</h2>
        <p class="sub">سجّل دخولك علشان تكمل الإبداع</p>

        <?= render_flash() ?>

        <?php foreach ($errors as $err): ?>
            <div class="alert danger"><?= e($err) ?></div>
        <?php endforeach; ?>

        <?php $googleIntent = 'login'; include __DIR__ . '/../templates/google-button.php'; ?>
                    <form method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="field">
                <label>البريد الإلكتروني</label>
                <input type="email" name="email" class="input" required
                       value="<?= e($email) ?>" placeholder="you@example.com">
            </div>

            <div class="field">
                <label>كلمة المرور</label>
                <input type="password" name="password" class="input" required
                       placeholder="••••••••">
                <div class="field-help" style="text-align:end;">
                    <a href="<?= url('forgot-password.php') ?>">نسيت كلمة المرور؟</a>
                </div>
            </div>

            <button type="submit" class="btn full lg">
                تسجيل الدخول →
            </button>
        </form>

        <div class="auth-divider">جديد على المنصة؟</div>

        <a href="<?= url('register.php') ?>" class="btn ghost full">إنشاء حساب جديد</a>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
