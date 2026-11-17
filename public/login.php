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

    if (!rate_limit_ok('login', client_ip(), 6, 600) || !rate_limit_ok('login_acct', login_acct_key($email), LOGIN_ACCT_MAX, LOGIN_ACCT_WINDOW)) {
        $errors[] = 'محاولات كتير. استنى 15 دقيقة وجرب تاني.';
        $result = ['ok' => false, 'error' => null, 'blocked' => true];
    } else {
        $result = authenticate_user($email, $password);
        if (!$result['ok']) {
            rate_limit_hit('login', client_ip());
            rate_limit_hit('login_acct', login_acct_key($email));   // المرحلة 6: حد لكل حساب كمان
        }
    }
    if ($result['ok']) {
        $user = $result['user'];
        if (!$user['email_verified_at'] && onb_enabled() && onb_in_window($user)) {
            // البداية السريعة: يدخل يكمّل التجربة — التفعيل مطلوب قبل النشر والشراء
            login_user((int) $user['id'], !empty($_POST['remember']));
            $__trialContent = trial_claim((int) $user['id']);
            redirect(ij_landing((int) $user['id'], $__trialContent ? 'content-view.php?id=' . $__trialContent : (onb_done((int) $user['id']) ? 'dashboard.php' : 'start.php')));
        } elseif (!$user['email_verified_at']) {
            $errors[] = __('الحساب لسه مش مفعل. تشيك على بريدك، أو اطلب رابط تفعيل جديد من هنا: {url}', ['url' => url('resend-verification.php')]);
        } elseif (($user['approval_status'] ?? 'approved') === 'pending') {
            $errors[] = 'حسابك تحت المراجعة — الإدارة هتوافق عليه قريبًا وهيوصلك إيميل تأكيد.';
        } elseif (($user['approval_status'] ?? 'approved') === 'rejected') {
            $errors[] = 'عذرًا، لم تتم الموافقة على حسابك. تواصل مع الإدارة.';
        } else {
            // «افتكرني» — بيتحفظ في الجلسة لحد ما الدخول يكمل (حتى لو فيه تحقق بخطوتين)
            $_SESSION['remember_me'] = !empty($_POST['remember']) ? 1 : 0;
            // التحقق بخطوتين (لو مفعّل) ← كود على الإيميل وصفحة الكود — وإلا دخول عادي
            account_login_or_challenge($user, auth_peek_next());
            // نقل بيانات التجربة المجانية للحساب (لو جاي منها)
            $__trialContent = trial_claim((int) ($_SESSION['user_id'] ?? 0));
            if ($__trialContent) {
                flash_set('success', 'أهلًا بيك! المنشور اللي عملته في التجربة اتحفظ في حسابك ✓');
                redirect(ij_landing((int) ($_SESSION['user_id'] ?? 0), 'content-view.php?id=' . $__trialContent));
            }
            // رحلة الهوية أول ما يدخل (لو لسه ما اعتمدش هويته) — وإلا الصفحة اللي كان رايحها
            $__next = auth_take_next();
            redirect(!empty($_SESSION['user_id']) ? ij_landing((int) $_SESSION['user_id'], $__next) : $__next);
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
$i18n_ready = true;   // الترجمة: الصفحة دي مترجمة بالكامل
include __DIR__ . '/../templates/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card">
        <?php if (i18n_enabled()): ?><div style="display:flex;justify-content:flex-end;margin-bottom:-6px"><?= i18n_switcher(url('lang.php')) ?></div><?php endif; ?>
        <div class="brand">
            <?= function_exists('site_logo_html') ? site_logo_html() : '<img src="' . e(url('assets/img/spread-mark-128.png')) . '" width="40" height="40" alt="Spread AI">' ?>
            <div>
                <div class="brand-name"><?= e(function_exists('site_name') ? site_name() : APP_NAME) ?></div>
                <div class="brand-sub"><?= e(__(function_exists('site_tagline') ? site_tagline() : APP_TAGLINE)) ?></div>
            </div>
        </div>

        <h2><?= e(__('أهلًا بعودتك 👋')) ?></h2>
        <p class="sub"><?= e(__('سجّل دخولك علشان تكمل الإبداع')) ?></p>

        <?= render_flash() ?>

        <?php foreach ($errors as $err): ?>
            <div class="alert danger"><?= e(__($err)) ?></div>
        <?php endforeach; ?>

        <?php $googleIntent = 'login'; include __DIR__ . '/../templates/google-button.php'; ?>
                    <form method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="field">
                <label><?= e(__('البريد الإلكتروني')) ?></label>
                <input type="email" name="email" class="input" required
                       value="<?= e($email) ?>" placeholder="you@example.com">
            </div>

            <div class="field">
                <label><?= e(__('كلمة المرور')) ?></label>
                <input type="password" name="password" class="input" required
                       placeholder="••••••••">
                <div class="field-help" style="text-align:end;">
                    <a href="<?= url('forgot-password.php') ?>"><?= e(__('نسيت كلمة المرور؟')) ?></a>
                </div>
                <div class="field" style="margin-top:-6px">
                    <label style="display:flex;gap:8px;align-items:center;font-weight:400;cursor:pointer">
                        <input type="checkbox" name="remember" value="1" <?= remember_default() ? 'checked' : '' ?> style="width:auto"> <?= e(__('افتكرني على الجهاز ده')) ?>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn full lg">
                <?= e(__('تسجيل الدخول →')) ?>
            </button>
        </form>

        <div class="auth-divider"><?= e(__('جديد على المنصة؟')) ?></div>

        <a href="<?= url('register.php') ?>" class="btn ghost full"><?= e(__('إنشاء حساب جديد')) ?></a>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
