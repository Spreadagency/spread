<?php
/**
 * Spread AI — «أكمل حسابك» (المرحلة 10)
 * حساب جوجل (جديد أو قديم) من غير رقم موبايل مايدخلش المنصة قبل ما يسجّل رقمه:
 *   Google Login ← رقم الموبايل ← حفظ ← إكمال الدخول ← الرئيسية
 * بيشتغل في حالتين: جاي من جوجل (لسه مادخلش) · أو جلسة/«افتكرني» لحساب جوجل ناقصه الرقم.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/google-auth.php';
require_once __DIR__ . '/../includes/rate-limit.php';

$pending = $_SESSION['phone_pending'] ?? null;
if ($pending && time() - (int) ($pending['at'] ?? 0) > 1800) {
    unset($_SESSION['phone_pending']);
    flash_set('warning', 'انتهت مهلة إكمال الحساب — سجّل دخول بجوجل تاني');
    redirect('login.php');
}
$uid = $pending ? (int) $pending['uid'] : (int) ($_SESSION['user_id'] ?? 0);
$user = $uid ? db_one('SELECT * FROM users WHERE id = ?', [$uid]) : null;
if (!$user || $user['status'] !== 'active') {
    unset($_SESSION['phone_pending']);
    redirect('login.php');
}
// الحساب مش محتاج رقم (أو اتسجّل خلاص)
if (!account_needs_phone($user)) {
    if ($pending) {
        unset($_SESSION['phone_pending']);
        google_finish_login($user, $pending);
    }
    redirect('dashboard.php');
}

/** الرقم ده مستخدم في حساب تاني؟ (بصيغه المختلفة: 010… · 2010… · +2010…) */
function ca_phone_taken(string $phone, int $exceptId): bool
{
    $d = preg_replace('/\D+/', '', $phone);
    $local = preg_replace('/^(?:00)?20/', '0', $d);
    $variants = array_values(array_unique([$phone, $d, '+' . $d, $local, '2' . $local, '+2' . $local]));
    $ph = implode(',', array_fill(0, count($variants), '?'));
    return (bool) db_one("SELECT id FROM users WHERE id <> ? AND phone IN ($ph) LIMIT 1", array_merge([$exceptId], $variants));
}

$errors = [];
$name = (string) $user['name'];
$phone = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $digits = preg_replace('/\D+/', '', $phone);
    if (!rate_limit_ok('complete_account', client_ip(), 12, 600)) {
        $errors[] = 'محاولات كتير — استنى شوية';
    } elseif (mb_strlen($name) < 2) {
        $errors[] = 'اكتب اسمك';
    } elseif (strlen($digits) < 8 || strlen($digits) > 15 || !preg_match('/^\+?[\d\s\-]+$/', $phone)) {
        $errors[] = 'اكتب رقم موبايل صحيح (مثلًا 01012345678)';
    } elseif (preg_match('/^01/', $digits) && strlen($digits) !== 11) {
        $errors[] = 'رقم الموبايل المصري لازم يكون 11 رقم';
    } elseif (ca_phone_taken($phone, $uid)) {
        rate_limit_hit('complete_account', client_ip());
        $errors[] = 'الرقم ده مسجّل على حساب تاني — استخدم رقم تاني أو كلّم الدعم';
    } else {
        db_run('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, mb_substr($phone, 0, 30), $uid]);
        // بصمة الهوية (حماية العروض من الحسابات المكررة) بتتحدّث بالرقم
        if (function_exists('identity_hash_for') || is_file(__DIR__ . '/../includes/offers.php')) {
            require_once __DIR__ . '/../includes/offers.php';
            try { db_run('UPDATE users SET identity_hash = ? WHERE id = ?', [identity_hash_for($phone, (string) $user['email']), $uid]); } catch (\Throwable $e) {}
        }
        $user = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
        if ($pending) {
            unset($_SESSION['phone_pending']);
            google_finish_login($user, $pending);   // بيكمّل الدخول وبيحوّل
        }
        flash_set('success', 'اتحفظ رقمك ✓');
        redirect('dashboard.php');
    }
}

$page_title = 'أكمل حسابك';
$__no_tabbar = true;
include __DIR__ . '/../templates/header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="brand">
            <?= function_exists('site_logo_html') ? site_logo_html() : '<div class="brand-mark">S</div>' ?>
            <div>
                <div class="brand-name"><?= e(function_exists('site_name') ? site_name() : APP_NAME) ?></div>
                <div class="brand-sub">خطوة أخيرة</div>
            </div>
        </div>
        <h2>أكمل حسابك 📱</h2>
        <p class="sub">محتاجين رقم موبايلك علشان نقدر نتواصل معاك بخصوص حسابك واشتراكك — مش هنشاركه مع حد.</p>
        <?= render_flash() ?>
        <?php foreach ($errors as $err): ?><div class="alert danger" role="alert"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" autocomplete="on">
            <?= csrf_field() ?>
            <div class="field">
                <label for="ca-name">الاسم</label>
                <input id="ca-name" type="text" name="name" class="input" required maxlength="150" value="<?= e($name) ?>" autocomplete="name">
            </div>
            <div class="field">
                <label for="ca-email">البريد الإلكتروني</label>
                <input id="ca-email" type="email" class="input" dir="ltr" value="<?= e((string) $user['email']) ?>" disabled>
            </div>
            <div class="field">
                <label for="ca-phone">رقم الموبايل <span class="req">*</span></label>
                <input id="ca-phone" type="tel" name="phone" class="input" dir="ltr" required maxlength="20" inputmode="tel" autocomplete="tel"
                       value="<?= e($phone) ?>" placeholder="01012345678" autofocus>
                <div class="field-help">الرقم مطلوب علشان تكمل الدخول للمنصة.</div>
            </div>
            <button type="submit" class="btn full lg">حفظ وإكمال الدخول ←</button>
        </form>
        <div class="auth-divider">مش حسابك؟</div>
        <a href="<?= url('logout.php') ?>" class="btn ghost full">خروج</a>
    </div>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
