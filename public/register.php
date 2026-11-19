<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../includes/integrations.php';
require_once __DIR__ . '/../includes/credits.php'; // get_setting (Phase 6)

trial_capture_token();
referral_capture();

if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$old = ['name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    $old = ['name' => $name, 'email' => $email, 'phone' => trim($_POST['phone'] ?? ''),
            'business_name' => mb_substr(trim((string) ($_POST['business_name'] ?? '')), 0, 120)];

    if ($password !== $confirm) {
        $errors[] = 'كلمتا المرور مش متطابقتين';
    }

    if (empty($errors) && !rate_limit('register', client_ip(), 5, 3600)) {
        $errors[] = 'محاولات تسجيل كتير من نفس الجهاز. جرب تاني بعد ساعة.';
    }

    if (empty($errors)) {
        $result = register_user($name, $email, $password);
        if ($result['ok']) {
            // حفظ رقم الموبايل
            // اسم النشاط (خانة في الشكل الجديد) ← ملف البراند مباشرة
            if ($old['business_name'] !== '') {
                // register_user() بيحط اسم الشخص مكان اسم النشاط تلقائيًا — نستبدله لو لسه بالقيمة التلقائية
                db_run('UPDATE brand_profiles SET business_name = ? WHERE user_id = ?
                        AND (business_name IS NULL OR business_name = "" OR business_name = ?)',
                    [$old['business_name'], $result['user_id'], $name]);
            }
            $phone = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
            if ($phone !== '') {
                db_run('UPDATE users SET phone = ? WHERE id = ?', [mb_substr($phone, 0, 30), $result['user_id']]);
            }

            // ربط الإحالة وصرف هدية الترحيب — بيفشل بصمت ومبيمنعش التسجيل أبدًا
            referral_attach_on_register((int) $result['user_id'], $_POST['ref_code'] ?? null);

            // Phase 6: موافقة يدوية لو مفعّلة
            if (get_setting('manual_approval', '0') === '1') {
                db_run('UPDATE users SET approval_status = "pending" WHERE id = ?', [$result['user_id']]);
            }

            // Send verification email
            $token = create_verification_token($result['user_id']);
            send_verification_email($name, $email, $token);

            // Notify Spread CRM (fire-and-forget, never blocks signup)
            crm_notify_signup($name, $email);

            // البداية السريعة (المرحلة 1): دخول تلقائي ← لينكات + 5 أسئلة + أول بوست — التفعيل بيتطلب قبل النشر والشراء
            if (onb_enabled()) {
                login_user((int) $result['user_id'], false);
                $__trialContent = trial_claim((int) $result['user_id']);
                redirect(ij_landing((int) $result['user_id'], $__trialContent ? 'content-view.php?id=' . $__trialContent : 'start.php'));
            }

            flash_set('success', get_setting('manual_approval', '0') === '1'
                ? 'تم إنشاء حسابك! فعّل بريدك، وبعدها الإدارة هتراجع حسابك وتوافق عليه قريبًا.'
                : 'تم إنشاء حسابك بنجاح! فتّش بريدك (والإسبام) لتفعيل الحساب.');
            // لو المستخدم جاي من التجربة المجانية، نفضل ماسكين التوكن لحد ما يسجّل دخول
            redirect('login.php' . (!empty($_SESSION['trial_token']) ? '?trial=' . urlencode($_SESSION['trial_token']) : ''));
        } else {
            $errors[] = $result['error'];
        }
    }
}

$page_title = 'إنشاء حساب';

// الشكل الجديد: شاشة الدخول من التصميم (نفس المنطق والحقول — العرض بس اللي اتغيّر)
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) {
    $authMode = 'register';
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

        <h2><?= e(__('إنشاء حساب جديد ✨')) ?></h2>
        <p class="sub"><?= e(__('ابدأ تنشئ محتوى احترافي بالذكاء الاصطناعي خلال دقايق')) ?></p>

        <?php foreach ($errors as $err): ?>
            <div class="alert danger"><?= e(__($err)) ?></div>
        <?php endforeach; ?>

        <?php $googleIntent = 'register'; include __DIR__ . '/../templates/google-button.php'; ?>
                    <form method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="field">
                <label><?= e(__('الاسم الكامل')) ?> <span class="req">*</span></label>
                <input type="text" name="name" class="input" required minlength="2"
                       value="<?= e($old['name']) ?>" placeholder="<?= e(__('مثال: مصطفى أحمد')) ?>">
            </div>

            <div class="field">
                <label><?= e(__('البريد الإلكتروني')) ?> <span class="req">*</span></label>
                <input type="email" name="email" class="input" required
                       value="<?= e($old['email']) ?>" placeholder="you@example.com">
            </div>

            <div class="field">
                <label><?= e(__('رقم الموبايل')) ?> <span class="req">*</span></label>
                <input type="tel" name="phone" class="input" dir="ltr" required pattern="[0-9+]{8,20}"
                       value="<?= e($old['phone'] ?? '') ?>" placeholder="01xxxxxxxxx">
            </div>

            <div class="field">
                        <label><?= e(__('كود دعوة أو خصم')) ?> <span class="text-mute" style="font-size:12px"><?= e(__('(اختياري)')) ?></span></label>
                        <input type="text" name="ref_code" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e(referral_active_code()) ?>" placeholder="SPREAD123"
                               style="text-transform:uppercase">
                        <?php if (referral_active_code() !== ''): ?>
                            <div class="field-help" style="color:var(--success)"><?= e(__('✓ كود الدعوة اتسجل — هتاخد رصيد هدية بعد التفعيل')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                <label><?= e(__('كلمة المرور')) ?> <span class="req">*</span></label>
                <input type="password" name="password" class="input" required minlength="8"
                       placeholder="<?= e(__('6 حروف على الأقل')) ?>">
                <div class="field-help"><?= e(__('على الأقل 6 حروف. خلّيها قوية شوية 💪')) ?></div>
            </div>

            <div class="field">
                <label><?= e(__('تأكيد كلمة المرور')) ?> <span class="req">*</span></label>
                <input type="password" name="password_confirm" class="input" required minlength="8"
                       placeholder="<?= e(__('أعد كلمة المرور')) ?>">
            </div>

            <button type="submit" class="btn full lg">
                <?= e(__('✨ إنشاء حسابي')) ?>
            </button>
        </form>

        <div class="auth-divider"><?= e(__('عندك حساب بالفعل؟')) ?></div>

        <a href="<?= url('login.php') ?>" class="btn ghost full"><?= e(__('تسجيل الدخول')) ?></a>

        <div class="footer-row">
            <?= e(__('بإنشاء حساب، أنت توافق على شروط الاستخدام وسياسة الخصوصية')) ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
