<?php
/**
 * لوحة التشغيل (المرحلة 8 · تصميم PlatformAdmin) — أرقام حقيقية
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
if (is_file(__DIR__ . '/../includes/ai.php')) require_once __DIR__ . '/../includes/ai.php';
if (is_file(__DIR__ . '/../includes/smart-ai.php')) require_once __DIR__ . '/../includes/smart-ai.php';

require_admin();
$admin = current_admin();
$canMoney = admin_can('view_costs'); // التكاليف والإيراد والمبيعات للمدير والأدمن الكامل بس

$per = a2_period((string) ($_GET['p'] ?? 'month'), $_GET['from'] ?? null, $_GET['to'] ?? null);
$prev = a2_prev_period($per);
$F = $per['from']; $T = $per['to'];

// ─── KPIs (الفترة + المقارنة بالفترة اللي قبلها + سلسلة يومية) ───
$sparkFrom = $per['days'] >= 7 ? $per['from_d'] : date('Y-m-d', strtotime($per['to_d'] . ' -6 days'));
$kpiDefs = [
    ['المستخدمين النشطين', 'active',
        'SELECT COUNT(DISTINCT user_id) FROM ai_runs WHERE user_id IS NOT NULL AND created_at >= ? AND created_at < ?',
        'SELECT DATE(created_at) d, COUNT(DISTINCT user_id) v FROM ai_runs WHERE user_id IS NOT NULL AND created_at >= ? AND created_at < ? GROUP BY d', '#0C87EF'],
    ['Credits مستهلكة', 'credits',
        'SELECT COALESCE(SUM(amount),0) FROM credit_transactions WHERE action_type = "consume" AND created_at >= ? AND created_at < ?',
        'SELECT DATE(created_at) d, SUM(amount) v FROM credit_transactions WHERE action_type = "consume" AND created_at >= ? AND created_at < ? GROUP BY d', '#F2A93B'],
    ['محتوى مُولَّد', 'content',
        'SELECT COUNT(*) FROM contents WHERE created_at >= ? AND created_at < ?',
        'SELECT DATE(created_at) d, COUNT(*) v FROM contents WHERE created_at >= ? AND created_at < ? GROUP BY d', '#2EC4B0'],
    ['تصميمات مُنتجة', 'designs',
        'SELECT (SELECT COUNT(*) FROM content_designs WHERE created_at >= ? AND created_at < ?)',
        'SELECT DATE(created_at) d, COUNT(*) v FROM content_designs WHERE created_at >= ? AND created_at < ? GROUP BY d', '#E0524A'],
    ['طلبات AI', 'ai',
        'SELECT COUNT(*) FROM ai_runs WHERE created_at >= ? AND created_at < ?',
        'SELECT DATE(created_at) d, COUNT(*) v FROM ai_runs WHERE created_at >= ? AND created_at < ? GROUP BY d', '#9C8CFF'],
    ['تسجيلات جديدة', 'signups',
        'SELECT COUNT(*) FROM users WHERE created_at >= ? AND created_at < ?',
        'SELECT DATE(created_at) d, COUNT(*) v FROM users WHERE created_at >= ? AND created_at < ? GROUP BY d', '#0C87EF'],
];
$kpis = [];
$series = [];
foreach ($kpiDefs as [$lbl, $key, $sql, $sqlD, $col]) {
    $now = (float) a2_scalar($sql, [$F, $T]);
    if ($key === 'designs') {
        $now += (float) a2_scalar('SELECT COUNT(*) FROM studio_designs WHERE created_at >= ? AND created_at < ?', [$F, $T]);
    }
    $pv = (float) a2_scalar($sql, [$prev['from'], $prev['to']]);
    $daily = a2_daily($sqlD, $sparkFrom, $per['to_d']);
    $kpis[] = ['t' => $lbl, 'v' => $now, 'd' => a2_delta($now, $pv), 'spark' => array_values($daily), 'c' => $col];
    $series[$key] = $daily;
}

// ─── الرسم: آخر 30 يوم ───
$c30f = date('Y-m-d', strtotime('-29 days'));
$c30t = date('Y-m-d');
$chartSeries = [
    'active' => ['label' => 'المستخدمين النشطين', 'color' => '#0C87EF', 'data' => array_values(a2_daily($kpiDefs[0][3], $c30f, $c30t))],
    'ai' => ['label' => 'طلبات AI', 'color' => '#9C8CFF', 'data' => array_values(a2_daily($kpiDefs[4][3], $c30f, $c30t))],
    'content' => ['label' => 'المحتوى', 'color' => '#2EC4B0', 'data' => array_values(a2_daily($kpiDefs[2][3], $c30f, $c30t))],
    'designs' => ['label' => 'التصميمات', 'color' => '#E0524A', 'data' => array_values(a2_daily($kpiDefs[3][3], $c30f, $c30t))],
    'credits' => ['label' => 'Credits', 'color' => '#F2A93B', 'data' => array_values(a2_daily($kpiDefs[1][3], $c30f, $c30t))],
];
$chartLabels = [];
for ($d = strtotime($c30f); $d <= strtotime($c30t); $d += 86400) $chartLabels[] = date('j/n', $d);

// ─── اقتصاديات الـ AI ───
$us = usage_summary($F, $T);
$rev = usage_credit_revenue($F, $T);
$fxNow = usage_fx_rate();
$afterAi = $rev['revenue'] - $us['cost_egp'];

// ─── التجاري ───
$paidOrders = (int) a2_scalar('SELECT COUNT(*) FROM payment_orders WHERE status = "paid" AND paid_at >= ? AND paid_at < ?', [$F, $T]);
$paidAmount = (float) a2_scalar('SELECT COALESCE(SUM(amount_egp),0) FROM payment_orders WHERE status = "paid" AND paid_at >= ? AND paid_at < ?', [$F, $T]);
$manualPaid = (float) a2_scalar('SELECT COALESCE(SUM(l.credits * l.unit_egp),0) FROM credit_lots l JOIN credit_transactions t ON t.id = l.tx_id WHERE l.source = "paid" AND t.reference_type = "manual" AND l.created_at >= ? AND l.created_at < ?', [$F, $T]);
$payingAccounts = (int) a2_scalar('SELECT COUNT(DISTINCT user_id) FROM credit_lots WHERE source = "paid" AND estimated = 0');
$activeWallets = (int) a2_scalar('SELECT COUNT(*) FROM credit_wallets WHERE balance > 0 AND (expires_at IS NULL OR expires_at > NOW())');
$newUsers = (int) $kpis[5]['v'];

// ─── صحة النظام ───
$gwOn = function_exists('ai_gw_enabled') && ai_gw_enabled();
$smartOn = !$gwOn && function_exists('smart_ai_enabled') && smart_ai_enabled();
$legacyKey = function_exists('ai_has_key') && ai_has_key();
$provRows = function_exists('ai_gw_providers') ? ai_gw_providers() : [];
$openBreakers = (int) a2_scalar('SELECT COUNT(*) FROM ai_provider_state WHERE state IN ("open","half") AND (opened_until IS NULL OR opened_until > NOW())');
$aiOk24 = (int) a2_scalar('SELECT COUNT(*) FROM ai_runs WHERE status = "ok" AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)');
$aiAll24 = (int) a2_scalar('SELECT COUNT(*) FROM ai_runs WHERE status IN ("ok","failed") AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)');
$cronLast = (string) get_setting('cron_publish_last_run', '');
$health = [];
if ($gwOn) {
    $health[] = ['AI Gateway', $openBreakers ? 'شغال ببديل' : 'Active', $openBreakers ? 'warn' : 'ok', 'admin/ai-router.php'];
} elseif ($smartOn) {
    $health[] = ['Smart Router', 'Active', 'ok', 'admin/ai-router.php'];
} else {
    $health[] = ['AI Provider', $legacyKey ? 'Connected (بدون بديل)' : 'مش مضبوط', $legacyKey ? 'warn' : 'bad', 'admin/ai-providers.php'];
}
$health[] = ['نجاح طلبات AI (24 ساعة)', $aiAll24 ? round($aiOk24 / $aiAll24 * 100) . '%' : '—', !$aiAll24 ? 'off' : ($aiOk24 / $aiAll24 >= .95 ? 'ok' : ($aiOk24 / $aiAll24 >= .8 ? 'warn' : 'bad')), 'admin/ai-runs.php'];
$metaOk = false;
try { if (!function_exists('meta_configured')) require_once __DIR__ . '/../includes/social.php'; $metaOk = function_exists('meta_configured') && meta_configured(); } catch (\Throwable $e) {}
$health[] = ['Facebook / Instagram', $metaOk ? 'Connected' : 'مش مضبوط', $metaOk ? 'ok' : 'warn', 'admin/settings.php?tab=social'];
$gOk = false;
try { if (!function_exists('google_enabled')) require_once __DIR__ . '/../includes/google-auth.php'; $gOk = function_exists('google_enabled') && google_enabled(); } catch (\Throwable $e) {}
$health[] = ['Google Auth', $gOk ? 'Active' : 'متقفل', $gOk ? 'ok' : 'off', 'admin/google-auth.php'];
$cronAge = $cronLast ? (time() - strtotime($cronLast)) : null;
$health[] = ['Scheduler (النشر المجدول)', $cronAge === null ? 'مالوش تشغيل مسجّل' : ($cronAge < 1200 ? 'Running' : 'متأخر ' . time_ago($cronLast)), $cronAge === null ? 'warn' : ($cronAge < 1200 ? 'ok' : 'bad'), 'admin/settings.php?tab=access#f-cron_secret'];
$health[] = ['Database', 'Healthy', 'ok', 'admin/migrations.php'];

// ─── تنبيهات محتاجة تدخل ───
$todo = [];
if (!$gwOn && !$smartOn && !$legacyKey) $todo[] = ['🔴 مفيش مفتاح AI مضبوط — المنصة مش هتولّد', 'admin/ai-providers.php'];
foreach (admin_alerts_open(6) as $al) $todo[] = [(['critical' => '🚨 ', 'warn' => '⚠️ ', 'info' => 'ℹ️ '][$al['level']] ?? '') . $al['title'], $al['link'] ?: 'admin/alerts.php'];
$pa = (int) a2_scalar('SELECT COUNT(*) FROM users WHERE approval_status = "pending"');
if ($pa) $todo[] = ["{$pa} حساب مستني الموافقة", 'admin/approvals.php'];
$vr = (int) a2_scalar('SELECT COUNT(*) FROM contents WHERE format = "video" AND video_status = "sent"');
if ($vr) $todo[] = ["{$vr} طلب فيديو جديد مستني التنفيذ", 'admin/video-requests.php'];
$expTok = (int) a2_scalar('SELECT COUNT(*) FROM social_connections WHERE status IN ("expired","error") OR (expires_at IS NOT NULL AND expires_at < DATE_ADD(NOW(), INTERVAL 3 DAY))');
if ($expTok) $todo[] = ["{$expTok} ربط سوشيال منتهي أو قرّب ينتهي", 'admin/social-connections.php'];
$expCr = a2_all('SELECT w.user_id, w.balance, w.expires_at, u.name FROM credit_wallets w JOIN users u ON u.id = w.user_id WHERE w.balance > 0 AND w.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 2 DAY) ORDER BY w.expires_at LIMIT 3');
foreach ($expCr as $x) $todo[] = ['Credits بتنتهي قريب: ' . $x['name'] . ' (' . (int) $x['balance'] . ')', 'admin/user-view.php?id=' . (int) $x['user_id']];
$failed24 = (int) a2_scalar('SELECT COUNT(*) FROM ai_runs WHERE status = "failed" AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)');
if ($failed24 >= 3) $todo[] = ["{$failed24} عملية AI فشلت في آخر 24 ساعة (الكريدت بيرجع تلقائيًا)", 'admin/ai-runs.php?status=failed'];

// ─── آخر إجراءات الأدمن + آخر المحتوى ───
$recentActs = a2_all('SELECT l.action, l.entity_type, l.created_at, a.name FROM admin_logs l LEFT JOIN admin_users a ON a.id = l.admin_user_id ORDER BY l.id DESC LIMIT 6');
$recentContents = a2_all('SELECT c.id, c.content_type, c.status, c.created_at, u.name AS user_name FROM contents c JOIN users u ON c.user_id = u.id ORDER BY c.id DESC LIMIT 6');

$h = (int) date('G');
$greet = $h < 12 ? 'صباح الخير' : ($h < 18 ? 'مساء الخير' : 'مساء الخير');
$active = 'dashboard';
$page_title = 'لوحة التشغيل';
include __DIR__ . '/../templates/admin-header.php';
$periods = ['today' => 'النهارده', '7d' => '7 أيام', 'month' => 'الشهر ده', 'last_month' => 'الشهر اللي فات'];
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>

        <div class="page-head with-actions">
            <div>
                <h1><?= e($greet) ?> <?= e(explode(' ', (string) ($admin['name'] ?? ''))[0]) ?> 👋</h1>
                <div class="sub">تشغيل المنصة · <?= e($per['label']) ?> · آخر تحديث <?= date('g:i') ?> <?= date('a') === 'am' ? 'ص' : 'م' ?></div>
            </div>
            <div class="a2-seg" role="tablist" aria-label="الفترة">
                <?php foreach ($periods as $k => $l): ?>
                    <a href="?p=<?= $k ?>" class="<?= $per['key'] === $k ? 'on' : '' ?>" role="tab" aria-selected="<?= $per['key'] === $k ? 'true' : 'false' ?>"><?= e($l) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($todo): ?>
        <div class="a2-alerts" style="margin-bottom:18px">
            <h3>⚠ تنبيهات محتاجة تدخل (<?= count($todo) ?>)</h3>
            <div class="a2-grid a2-g2">
                <?php foreach (array_slice($todo, 0, 8) as [$t, $u]): ?>
                    <a href="<?= e(url($u)) ?>"><span><?= e($t) ?></span>←</a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="a2-grid a2-g6" style="margin-bottom:18px">
            <?php foreach (array_reverse($kpis) as $k): ?>
            <div class="a2-kpi">
                <small><?= e($k['t']) ?></small>
                <b><?= a2n($k['v']) ?></b>
                <?php if ($k['d'] !== null): ?><em class="<?= $k['d'] > 0 ? 'up' : ($k['d'] < 0 ? 'down' : '') ?>"><?= $k['d'] > 0 ? '▲' : ($k['d'] < 0 ? '▼' : '•') ?> <?= abs($k['d']) ?>% عن الفترة اللي قبلها</em><?php else: ?><em>جديد في الفترة دي</em><?php endif; ?>
                <?= a2_spark($k['spark'], $k['c']) ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="a2-grid a2-side" style="margin-bottom:18px">
            <div class="card">
                <div class="a2-h"><h3>الاستهلاك</h3><span class="a2-en">Usage Overview · آخر 30 يوم</span></div>
                <?= a2_line_chart($chartSeries, $chartLabels, 'a2usage') ?>
            </div>
            <div class="a2-grid">
                <div class="card">
                    <div class="a2-h"><h3>صحة النظام</h3></div>
                    <?php foreach ($health as [$t, $v, $lvl, $u]): ?>
                        <div class="a2-kv"><span><a href="<?= e(url($u)) ?>" style="color:inherit"><?= e($t) ?></a></span><b class="a2-st <?= $lvl === 'ok' ? '' : e($lvl) ?>"><?= e($v) ?></b></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="a2-grid <?= $canMoney ? 'a2-g3' : '' ?>" style="margin-bottom:18px">
            <?php if ($canMoney): ?>
            <div class="card">
                <div class="a2-h"><h3>اقتصاديات الـ AI</h3><span class="a2-en"><?= e($per['label']) ?></span></div>
                <div class="a2-kv"><span>تكلفة المزودين</span><b class="a2-num"><?= a2egp($us['cost_egp']) ?></b></div>
                <div class="a2-kv"><span>منها محاولات فشلت (على المنصة)</span><b class="a2-num"><?= a2egp($us['cost_failed_usd'] * $fxNow) ?></b></div>
                <div class="a2-kv"><span>إيراد الكريدت المستهلك</span><b class="a2-num"><?= a2egp($rev['revenue']) ?></b></div>
                <div class="a2-kv"><span>الإيراد بعد تكلفة الـ AI</span><b class="a2-num" style="color:<?= $afterAi >= 0 ? '#0B8F83' : '#C2362E' ?>"><?= a2egp($afterAi) ?></b></div>
                <?php if ($us['estimated']): ?><div class="a2-muted" style="margin-top:8px">⚠ <?= (int) $us['estimated'] ?> عملية تكلفتها تقديرية (موديل مالوش سعر) — <a href="<?= url('admin/ai-costs.php?tab=prices') ?>">ضيف السعر</a></div><?php endif; ?>
                <div style="margin-top:12px"><a class="btn sm soft" href="<?= url('admin/ai-costs.php') ?>">تفاصيل التكاليف ←</a></div>
            </div>
            <div class="card">
                <div class="a2-h"><h3>الصورة التجارية</h3><span class="a2-en"><?= e($per['label']) ?></span></div>
                <div class="a2-kv"><span>مستخدمين جداد</span><b><?= a2n($newUsers) ?></b></div>
                <div class="a2-kv"><span>عمليات دفع (Paymob)</span><b><?= a2n($paidOrders) ?></b></div>
                <div class="a2-kv"><span>مبيعات الفترة</span><b class="a2-num"><?= a2egp($paidAmount + $manualPaid, 0) ?></b></div>
                <div class="a2-kv"><span>حسابات دفعت قبل كده</span><b><?= a2n($payingAccounts) ?></b></div>
                <div class="a2-kv"><span>محافظ فيها رصيد ساري</span><b><?= a2n($activeWallets) ?></b></div>
            </div>
            <?php endif; ?>
            <div class="card">
                <div class="a2-h"><h3>آخر إجراءات الأدمن</h3><span class="a2-grow"></span><a href="<?= url('admin/logs.php') ?>" class="a2-muted">الكل</a></div>
                <?php if (!$recentActs): ?><div class="a2-empty">مفيش إجراءات لسه</div><?php endif; ?>
                <?php foreach ($recentActs as $ra): ?>
                    <div class="a2-kv"><span><?= e($ra['action']) ?><?= $ra['entity_type'] ? ' · ' . e($ra['entity_type']) : '' ?></span><small class="a2-muted"><?= e(($ra['name'] ?? '—') . ' · ' . time_ago($ra['created_at'])) ?></small></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="a2-h"><h3>أحدث المحتوى</h3><span class="a2-grow"></span><a href="<?= url('admin/content-history.php') ?>" class="a2-muted">عرض الكل</a></div>
            <?php if (!$recentContents): ?><div class="a2-empty">لا توجد منشورات</div><?php else: ?>
            <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>#</th><th>العميل</th><th>النوع</th><th>الحالة</th><th>من</th></tr></thead><tbody>
            <?php foreach ($recentContents as $c): ?>
                <tr><td><a href="<?= url('admin/content-view.php?id=' . (int) $c['id']) ?>">#<?= (int) $c['id'] ?></a></td><td><?= e($c['user_name']) ?></td><td><?= e(content_type_label($c['content_type'] ?? '')) ?></td>
                    <td><span class="chip <?= e(status_chip((string) $c['status'])) ?>"><?= e(status_label((string) $c['status'])) ?></span></td><td class="a2-muted"><?= e(time_ago($c['created_at'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
