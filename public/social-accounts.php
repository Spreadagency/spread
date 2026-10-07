<?php
/**
 * Spread AI v2 — حساباتي المربوطة
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/social.php';

require_login();
$user = current_user();
feature_require((int) $user['id']);

// فصل صفحة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect') {
    require_csrf();
    $conn = connection_for_user((int) $_POST['conn_id'], (int) $user['id']);
    if ($conn) {
        social_disconnect($conn);
        flash_set('success', 'تم فصل «' . $conn['page_name'] . '» وحذف التوكن نهائيًا — البوستات المجدولة عليها اتلغت.');
    }
    redirect('social-accounts.php');
}

$connections = user_connections((int) $user['id'], null);
$maxPages = feature_max_pages((int) $user['id']);
$statusLabels = [
    'active' => ['✓ نشط', '#2a7d5f', 'var(--mint-soft,#eefaf4)'],
    'expired' => ['⚠ منتهي — أعد الربط', '#c0392b', '#fdecea'],
    'revoked' => ['✕ ملغي', '#c0392b', '#fdecea'],
    'error' => ['⚠ خطأ', '#a06c1e', '#fdf6e3'],
];

$active = 'social-accounts';
$page_title = 'حساباتي المربوطة';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
            <div>
                <h1>حساباتي المربوطة 🔗</h1>
                <div class="sub">اربط صفحات فيسبوك (ومعاها انستجرام بيزنس) والمنصة تنشر بوستاتك المجدولة تلقائيًا — <?= count($connections) ?>/<?= $maxPages ?> صفحة</div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php if (count($connections) < $maxPages): ?>
                    <a href="<?= url('social/connect.php') ?>" class="btn">📘 اربط صفحتك بضغطة واحدة</a>
                <?php endif; ?>
                <a href="<?= url('social-diagnose.php') ?>" class="btn ghost">🩺 فحص الربط</a>
            </div>
        </div>

        <?= render_flash() ?>

        <?php if (!meta_configured()): ?>
            <div class="card" style="border-color:#f0c36d;background:#fdf6e3;max-width:640px">
                ⚠️ <b>الربط لسه مش جاهز</b> — إدارة المنصة لم تضبط بيانات تطبيق ميتا بعد. تواصل معهم.
            </div>
        <?php endif; ?>

        <?php if (!$connections): ?>
            <div class="card" style="max-width:640px;text-align:center;padding:40px 20px">
                <div style="font-size:44px;margin-bottom:10px">📘</div>
                <h3>مفيش صفحات مربوطة لسه</h3>
                <p class="sub" style="margin:10px 0 18px">اضغط الزرار، سجّل دخولك في فيسبوك، اختار صفحتك — وخلاص!<br>لو صفحتك مربوط بيها حساب انستجرام بيزنس، هيتربط تلقائيًا معاها 📸</p>
                <a href="<?= url('social/connect.php') ?>" class="btn">📘 اربط صفحتك دلوقتي</a>
            </div>
        <?php else: ?>
            <div class="auto-grid" style="max-width:900px">
                <?php foreach ($connections as $c):
                    [$sLabel, $sColor, $sBg] = $statusLabels[$c['status']] ?? ['?', '#666', '#eee'];
                ?>
                    <div class="card" style="padding:16px">
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
                            <?php if ($c['page_avatar_url']): ?>
                                <img src="<?= e($c['page_avatar_url']) ?>" style="width:52px;height:52px;border-radius:14px;object-fit:cover" alt="">
                            <?php else: ?>
                                <div style="width:52px;height:52px;border-radius:14px;background:var(--primary-soft);display:grid;place-items:center;font-size:22px">📘</div>
                            <?php endif; ?>
                            <div style="flex:1;min-width:0">
                                <b><?= e($c['page_name']) ?></b>
                                <div class="sub" style="font-size:11px" dir="ltr"><?= e($c['provider_page_id']) ?></div>
                                <?php if ($c['ig_user_id']): ?>
                                    <div class="sub" style="font-size:12px">📸 انستجرام: @<?= e($c['ig_username'] ?: $c['ig_user_id']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <span class="chip" style="color:<?= $sColor ?>;background:<?= $sBg ?>;font-weight:700"><?= $sLabel ?></span>
                        <?php if ($c['last_error'] && $c['status'] !== 'active'): ?>
                            <p class="sub" style="font-size:11px;margin-top:6px"><?= e(mb_substr($c['last_error'], 0, 120)) ?></p>
                        <?php endif; ?>

                        <div style="display:flex;gap:8px;margin-top:12px">
                            <?php if ($c['status'] !== 'active'): ?>
                                <a href="<?= url('social/connect.php') ?>" class="btn sm">↻ أعد الربط</a>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('فصل «<?= e($c['page_name']) ?>»؟ التوكن هيتحذف نهائيًا وأي بوستات مجدولة عليها هتتلغي.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="disconnect">
                                <input type="hidden" name="conn_id" value="<?= $c['id'] ?>">
                                <button class="btn ghost sm" style="color:#c0392b">✕ فصل</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card" style="max-width:900px;margin-top:16px;background:var(--primary-soft)">
                💡 <b>إزاي تنشر تلقائيًا؟</b> افتح أي بوست → «جدولة النشر» → اختار صفحتك من قايمة «انشر على» وحدد الوقت — والباقي علينا. لو البوست عليه تصميم هيتنشر بالصورة.
            </div>
        <?php endif; ?>

    </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
