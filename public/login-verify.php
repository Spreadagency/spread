<?php
/**
 * Spread AI v2 — التحقق بخطوتين عند الدخول (⑥-أ)
 * بعد ما الباسورد (أو جوجل) يتأكد والعميل مفعّل التحقق بخطوتين: كود 6 أرقام على الإيميل
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate-limit.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$pending = $_SESSION['2fa_pending'] ?? null;
// مهلة الخطوة دي 15 دقيقة من وقت الباسورد
if (!$pending || time() - (int) ($pending['at'] ?? 0) > 900 || isset($_GET['cancel'])) {
    unset($_SESSION['2fa_pending'], $_SESSION['2fa']);
    if (!isset($_GET['cancel']) && $pending) flash_set('warning', 'الخطوة دي انتهت — سجّل دخولك تاني');
    redirect('login.php');
}

$user = db_one('SELECT * FROM users WHERE id = ?', [(int) $pending['uid']]);
if (!$user || $user['status'] !== 'active') {
    unset($_SESSION['2fa_pending'], $_SESSION['2fa']);
    redirect('login.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'resend') {
        $r = account_2fa_send($user, 'login');
        $r['ok'] ? flash_set('success', 'بعتنا كود جديد ✓') : flash_set('danger', $r['error']);
        redirect('login-verify.php');
    }
    if (!rate_limit_ok('2fa_verify', client_ip(), 10, 600)) {
        $errors[] = 'محاولات كتير — استنى 10 دقايق';
    } else {
        $r = account_2fa_verify((int) $user['id'], 'login', (string) ($_POST['code'] ?? ''));
        if (!$r['ok']) {
            rate_limit_hit('2fa_verify', client_ip());
            $errors[] = $r['error'];
        } else {
            $next = $pending['next'] ?? 'dashboard.php';
            login_user((int) $user['id']);
            if (function_exists('trial_claim')) {
                $tc = trial_claim((int) $user['id']);
                if ($tc) {
                    flash_set('success', 'أهلًا بيك! المنشور اللي عملته في التجربة اتحفظ في حسابك ✓');
                    redirect('content-view.php?id=' . $tc);
                }
            }
            redirect(preg_match('/^[a-z0-9\-]+\.php(\?[^\s]*)?$/i', $next) ? $next : 'dashboard.php');
        }
    }
}

// a***d@gmail.com
[$__u, $__d] = array_pad(explode('@', (string) $user['email'], 2), 2, '');
$maskedEmail = mb_strlen($__u) <= 3
    ? mb_substr($__u, 0, 1) . '•••@' . $__d
    : mb_substr($__u, 0, 1) . str_repeat('•', min(6, mb_strlen($__u) - 2)) . mb_substr($__u, -1) . '@' . $__d;

$page_title = 'التحقق بخطوتين';
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) {
    $authMode = 'verify';
    include __DIR__ . '/../templates/v2/auth.php';
    exit;
}
include __DIR__ . '/../templates/header.php';
?>
<div class="auth-wrap">
    <div class="auth-card">
        <h2>اكتب الكود اللي وصلك 🔐</h2>
        <p class="sub">بعتنا كود من 6 أرقام على <b dir="ltr"><?= e($maskedEmail) ?></b> — صالح 10 دقايق.</p>
        <?= render_flash() ?>
        <?php foreach ($errors as $err): ?>
            <div class="alert danger"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify">
            <div class="field">
                <label>كود التحقق</label>
                <input name="code" class="input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus
                       autocomplete="one-time-code" dir="ltr" style="letter-spacing:8px;text-align:center;font-size:20px">
            </div>
            <button class="btn full">تأكيد ودخول</button>
        </form>
        <form method="POST" style="margin-top:14px;text-align:center;font-size:13px">
            <?= csrf_field() ?><input type="hidden" name="action" value="resend">
            <button class="btn ghost sm">ابعت كود جديد</button>
            <a href="<?= url('login-verify.php?cancel=1') ?>" style="margin-inline-start:8px">ادخل بحساب تاني</a>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
