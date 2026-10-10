<?php
require_once __DIR__ . '/../includes/auth.php';

$token = trim($_GET['token'] ?? '');
$ok = false;
$message = '';

if (empty($token)) {
    $message = 'رابط التفعيل غير صالح';
} else {
    $ok = verify_email_token($token);
    if ($ok) {
        $message = 'تم تفعيل حسابك بنجاح 🎉 تقدر تسجل دخولك دلوقتي.';
    } else {
        $message = 'الرابط ده انتهت صلاحيته أو غير صحيح';
    }
}

$page_title = 'تأكيد البريد';
include __DIR__ . '/../templates/header.php';
?>

<div class="auth-wrap">
    <div class="auth-card text-center">
        <div class="brand" style="justify-content:center">
            <div class="brand-mark">S</div>
        </div>

        <?php if ($ok): ?>
            <div style="font-size:64px;margin-bottom:8px">✓</div>
            <h2 style="color:var(--success)">تم التفعيل!</h2>
            <p class="sub" style="margin-bottom:24px"><?= e($message) ?></p>
            <a href="<?= url('login.php') ?>" class="btn full lg">انتقل إلى تسجيل الدخول</a>
        <?php else: ?>
            <div style="font-size:64px;margin-bottom:8px">⚠</div>
            <h2 style="color:var(--danger)">حصلت مشكلة</h2>
            <p class="sub" style="margin-bottom:24px"><?= e($message) ?></p>
            <a href="<?= url('login.php') ?>" class="btn ghost full">العودة لتسجيل الدخول</a>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
