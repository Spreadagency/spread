<?php
/**
 * Spread AI v2 — التحليلات
 * عدد المنشورات + نسبة الاستخدام (Usage) — من بيانات المنصة الحقيقية بس
 * (تحليلات التفاعل هتيجي لما نربط بيانات ميتا)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ui-v2.php';
if (is_file(__DIR__ . '/../includes/lifecycle.php')) {
    require_once __DIR__ . '/../includes/lifecycle.php';
}

require_login();
$user = current_user();
if (!ui_v2_enabled()) {
    redirect('dashboard.php');
}
$uid = (int) $user['id'];

$posts = db_one('SELECT COUNT(*) total,
                        SUM(created_at >= DATE_FORMAT(NOW(), "%Y-%m-01")) this_month,
                        SUM(created_at >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH, "%Y-%m-01")
                            AND created_at < DATE_FORMAT(NOW(), "%Y-%m-01")) last_month,
                        SUM(publish_status = "published") published,
                        SUM(publish_status IN ("pending","processing") AND scheduled_at IS NOT NULL) scheduled
                 FROM contents WHERE user_id = ?', [$uid]) ?: [];
$designs = (int) (db_one('SELECT COUNT(*) n FROM content_designs WHERE user_id = ?', [$uid])['n'] ?? 0);
$campaigns = ['active' => 0, 'completed' => 0];
try {
    foreach (db_all('SELECT status, COUNT(*) n FROM campaigns WHERE user_id = ? GROUP BY status', [$uid]) as $r) {
        $campaigns[$r['status']] = (int) $r['n'];
    }
} catch (\Throwable $e) {}

// آخر 6 شهور
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $k = date('Y-m', strtotime("first day of -{$i} month"));
    $months[$k] = 0;
}
foreach (db_all('SELECT DATE_FORMAT(created_at, "%Y-%m") m, COUNT(*) n FROM contents
                 WHERE user_id = ? AND created_at >= DATE_FORMAT(NOW() - INTERVAL 5 MONTH, "%Y-%m-01") GROUP BY m', [$uid]) as $r) {
    if (isset($months[$r['m']])) $months[$r['m']] = (int) $r['n'];
}
$maxM = max(1, max($months));
$mNames = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

$usage = ui_credits_usage($uid);
$thisM = (int) ($posts['this_month'] ?? 0);
$lastM = (int) ($posts['last_month'] ?? 0);
$delta = $lastM > 0 ? (int) round(($thisM - $lastM) / $lastM * 100) : null;

$active = 'analytics';
$page_title = 'التحليلات';
include __DIR__ . '/../templates/header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <section class="an-grid">
            <!-- الاستخدام -->
            <div class="card an-usage">
                <div class="an-ring" style="--p:<?= $usage['pct'] ?>">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle cx="60" cy="60" r="52" class="an-ring-bg"/>
                        <circle cx="60" cy="60" r="52" class="an-ring-fg" stroke-dasharray="326.7"
                                stroke-dashoffset="<?= round(326.7 - 326.7 * $usage['pct'] / 100, 1) ?>"/>
                    </svg>
                    <div class="an-ring-mid"><b><?= $usage['pct'] ?>%</b><span>Usage</span></div>
                </div>
                <div class="an-usage-txt">
                    <h2>استخدام الكريدت</h2>
                    <p>استخدمت <b><?= $usage['used'] ?></b> من <b><?= $usage['total'] ?></b> كريدت من آخر شحن</p>
                    <p class="sub">
                        باقي <b><?= $usage['balance'] ?></b> كريدت
                        <?php if ($usage['days_left'] !== null): ?> · بتنتهي بعد <b><?= $usage['days_left'] ?></b> يوم<?php endif; ?>
                    </p>
                    <a href="<?= url('credits.php') ?>" class="btn sm">اشحن رصيد</a>
                </div>
            </div>

            <!-- عدد المنشورات -->
            <div class="card an-kpi">
                <span class="an-kpi-ic"><?= ui_icon('send', 20) ?></span>
                <b><?= $thisM ?></b>
                <span>منشور هذا الشهر</span>
                <?php if ($delta !== null): ?>
                    <em class="<?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '+' : '' ?><?= $delta ?>% عن الشهر اللي فات</em>
                <?php endif; ?>
            </div>
            <div class="card an-kpi">
                <span class="an-kpi-ic"><?= ui_icon('folder', 20) ?></span>
                <b><?= (int) ($posts['total'] ?? 0) ?></b><span>إجمالي المنشورات</span>
            </div>
            <div class="card an-kpi">
                <span class="an-kpi-ic"><?= ui_icon('image', 20) ?></span>
                <b><?= $designs ?></b><span>تصميمات</span>
            </div>
            <div class="card an-kpi">
                <span class="an-kpi-ic"><?= ui_icon('megaphone', 20) ?></span>
                <b><?= $campaigns['active'] ?></b><span>حملات نشطة</span>
            </div>
        </section>

        <section class="card an-chart">
            <div class="card-head"><h3>المنشورات آخر 6 شهور</h3></div>
            <div class="an-bars">
                <?php foreach ($months as $k => $n): ?>
                    <div class="an-bar">
                        <b><?= $n ?></b>
                        <span style="height:<?= max(4, round($n / $maxM * 100)) ?>%"></span>
                        <small><?= $mNames[(int) substr($k, 5)] ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="an-split">
                <div><b><?= (int) ($posts['published'] ?? 0) ?></b><span>اتنشر</span></div>
                <div><b><?= (int) ($posts['scheduled'] ?? 0) ?></b><span>مجدول</span></div>
                <div><b><?= $campaigns['completed'] ?></b><span>حملات مكتملة</span></div>
            </div>
        </section>

        <p class="sub an-note"><?= ui_icon('alert', 16) ?> تحليلات التفاعل والوصول هتظهر هنا لما نربط بيانات صفحاتك على ميتا.</p>
    </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
