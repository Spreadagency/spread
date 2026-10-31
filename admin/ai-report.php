<?php
/**
 * Spread AI — مركز الجودة: التقرير اليومي (المرحلة 5)
 * آخر تقرير من Claude (daily_review) + الأرشيف + رسوم الجودة لآخر 30 يوم (تقييم Claude · إعادة التوليد · تقييمات العملاء)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ai-center.php';

require_admin();
require_admin_can('ai_settings');

$ready = aic_has('ai_reports');
$type = in_array($_GET['type'] ?? '', ['daily', 'weekly', 'security'], true) ? $_GET['type'] : 'daily';
$id = (int) ($_GET['id'] ?? 0);

$reports = $ready ? a2_all('SELECT id, type, report_date, LEFT(summary, 180) teaser, created_at, updated_at FROM ' . mcp_t('ai_reports') . ' WHERE type = ? ORDER BY report_date DESC, id DESC LIMIT 60', [$type]) : [];
$report = null;
if ($ready) {
    $report = $id ? db_one('SELECT * FROM ' . mcp_t('ai_reports') . ' WHERE id = ?', [$id])
                  : ($reports ? db_one('SELECT * FROM ' . mcp_t('ai_reports') . ' WHERE id = ?', [(int) $reports[0]['id']]) : null);
}
$payload = $report && $report['payload'] ? json_decode((string) $report['payload'], true) : null;

/* ── رسوم آخر 30 يوم (حسب يوم إنشاء المنشور) ── */
$toD = date('Y-m-d');
$fromD = date('Y-m-d', strtotime('-29 days'));
$labels = [];
for ($d = strtotime($fromD); $d <= strtotime($toD); $d += 86400) $labels[] = date('m/d', $d);
$evalReady = aic_has('ai_evaluations');
$overall = $evalReady ? a2_daily('SELECT DATE(c.created_at) d, AVG(e.overall) v FROM ' . mcp_t('ai_evaluations') . ' e JOIN contents c ON c.id = e.content_id
                                   WHERE c.created_at >= ? AND c.created_at < ? GROUP BY d', $fromD, $toD) : [];
$posts = a2_daily('SELECT DATE(created_at) d, COUNT(*) v FROM contents WHERE created_at >= ? AND created_at < ? GROUP BY d', $fromD, $toD);
$regen = a2_daily('SELECT DATE(created_at) d, COUNT(*) v FROM content_versions WHERE version_type = "ai" AND version_number > 1 AND created_at >= ? AND created_at < ? GROUP BY d', $fromD, $toD);
$up = a2_daily('SELECT DATE(created_at) d, COUNT(*) v FROM contents WHERE feedback = 1 AND created_at >= ? AND created_at < ? GROUP BY d', $fromD, $toD);
$down = a2_daily('SELECT DATE(created_at) d, COUNT(*) v FROM contents WHERE feedback = -1 AND created_at >= ? AND created_at < ? GROUP BY d', $fromD, $toD);
$regenRate = [];
foreach ($posts as $d => $n) $regenRate[$d] = $n > 0 ? round(($regen[$d] ?? 0) / $n * 100, 1) : 0;

/* ── مقارنة آخر 7 أيام باللي قبلها ── */
$sumLast = fn(array $s, int $from, int $len) => array_sum(array_slice(array_values($s), $from, $len));
$avgNonZero = function (array $s, int $from, int $len): ?float {
    $v = array_filter(array_slice(array_values($s), $from, $len), fn($x) => $x > 0);
    return $v ? round(array_sum($v) / count($v), 2) : null;
};
$k = [
    'overall' => [$avgNonZero($overall, 23, 7), $avgNonZero($overall, 16, 7)],
    'regen' => [($p = $sumLast($posts, 23, 7)) ? round($sumLast($regen, 23, 7) / $p * 100, 1) : null, ($p2 = $sumLast($posts, 16, 7)) ? round($sumLast($regen, 16, 7) / $p2 * 100, 1) : null],
    'like' => [($r = $sumLast($up, 23, 7) + $sumLast($down, 23, 7)) ? round($sumLast($up, 23, 7) / $r * 100) : null, ($r2 = $sumLast($up, 16, 7) + $sumLast($down, 16, 7)) ? round($sumLast($up, 16, 7) / $r2 * 100) : null],
];
$delta = function (?float $now, ?float $prev, bool $lowerIsBetter = false): string {
    if ($now === null || $prev === null || $prev == 0.0) return '<em>مفيش مقارنة</em>';
    $d = round(($now - $prev) / $prev * 100);
    $good = $lowerIsBetter ? $d <= 0 : $d >= 0;
    return '<em style="color:' . ($d === 0.0 || $d === 0 ? '#8391A6' : ($good ? '#2a9d76' : '#d9534f')) . '">' . ($d > 0 ? '+' : '') . $d . '% عن الأسبوع اللي قبله' . (abs($d) > 20 ? ' ⚠️' : '') . '</em>';
};
$evalCount7 = $evalReady ? (int) a2_scalar('SELECT COUNT(*) FROM ' . mcp_t('ai_evaluations') . ' WHERE created_at >= ?', [date('Y-m-d', strtotime('-6 days')) . ' 00:00:00']) : 0;

$typeLbl = ['daily' => 'اليومي', 'weekly' => 'الأسبوعي', 'security' => 'الأمان'];
$active = 'ai-report';
$page_title = 'التقرير اليومي';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>مركز الجودة: التقرير اليومي</h1>
                <div class="sub">التقرير اللي Claude بيكتبه كل يوم + اتجاه الجودة لآخر 30 يوم</div>
            </div>
            <?= aic_tabs('ai-report') ?>
        </div>

        <div class="a2-grid a2-g4" style="margin-bottom:14px">
            <div class="a2-kpi"><small>متوسط تقييم Claude (7 أيام)</small><b><?= $k['overall'][0] !== null ? number_format($k['overall'][0], 1) . ' / 10' : '—' ?></b><?= $delta($k['overall'][0], $k['overall'][1]) ?></div>
            <div class="a2-kpi"><small>نسبة إعادة التوليد (7 أيام)</small><b><?= $k['regen'][0] !== null ? $k['regen'][0] . '%' : '—' ?></b><?= $delta($k['regen'][0], $k['regen'][1], true) ?></div>
            <div class="a2-kpi"><small>رضا العملاء 👍 (7 أيام)</small><b><?= $k['like'][0] !== null ? $k['like'][0] . '%' : '—' ?></b><?= $delta($k['like'][0] !== null ? (float) $k['like'][0] : null, $k['like'][1] !== null ? (float) $k['like'][1] : null) ?></div>
            <div class="a2-kpi"><small>بلاغات جديدة · تقييمات الأسبوع</small><b><?= aic_new_issues() ?> · <?= $evalCount7 ?></b><em><a href="<?= e(url('admin/ai-issues.php?status=new')) ?>">افتح البلاغات</a></em></div>
        </div>

        <div class="a2-grid a2-g3" style="margin-bottom:14px">
            <div class="card"><div class="a2-h"><h3>متوسط تقييم Claude</h3><span class="a2-muted">من 10 · حسب يوم المنشور</span></div>
                <?= $evalReady ? a2_line_chart([['label' => 'الإجمالي', 'color' => '#4f46e5', 'data' => array_values($overall)]], $labels, 'aic-ov') : '<div class="a2-empty">لسه مفيش تقييمات</div>' ?></div>
            <div class="card"><div class="a2-h"><h3>نسبة إعادة التوليد</h3><span class="a2-muted">% من المنشورات</span></div>
                <?= a2_line_chart([['label' => 'إعادة التوليد', 'color' => '#e0a21a', 'data' => array_values($regenRate)]], $labels, 'aic-rg') ?></div>
            <div class="card"><div class="a2-h"><h3>تقييمات العملاء</h3><span class="a2-muted">عدد</span></div>
                <?= a2_line_chart([['label' => '👍', 'color' => '#2a9d76', 'data' => array_values($up)], ['label' => '👎', 'color' => '#d9534f', 'data' => array_values($down)]], $labels, 'aic-rt') ?></div>
        </div>

        <?php if (!$ready): ?>
            <?= aic_setup_notice() ?>
        <?php else: ?>
        <div class="split split-2" style="align-items:flex-start">
            <div class="card">
                <div class="a2-h" style="flex-wrap:wrap;gap:8px">
                    <h3><?= $report ? 'التقرير ' . e($typeLbl[$report['type']] ?? $report['type']) . ' · ' . e($report['report_date']) : 'التقرير' ?></h3>
                    <div class="a2-seg">
                        <?php foreach ($typeLbl as $tk => $tl): ?><a class="<?= $type === $tk ? 'on' : '' ?>" href="?type=<?= $tk ?>"><?= e($tl) ?></a><?php endforeach; ?>
                    </div>
                </div>
                <?php if (!$report): ?>
                    <div class="a2-empty" style="text-align:start;line-height:1.9">
                        لسه مفيش تقرير <?= e($typeLbl[$type]) ?>. التقرير بيتعمل من Claude:
                        <ol style="padding-inline-start:20px;margin:6px 0">
                            <li>اربط موصّل Spread AI في Claude (<code>docs/DEPLOY.md</code> الخطوة 9).</li>
                            <li>في شات جديد: فعّل الموصّل، واختار برومبت <b>daily_review</b> أو اكتب «اعمل المراجعة اليومية».</li>
                            <li>Claude بيحفظ التقرير، وبيظهر هنا تلقائيًا.</li>
                        </ol>
                    </div>
                <?php else: ?>
                    <div class="a2-muted" style="font-size:12px;margin-bottom:8px">اتحفظ <?= e($report['created_at']) ?><?= $report['updated_at'] ? ' · اتحدّث ' . e($report['updated_at']) : '' ?></div>
                    <div style="font-size:14px"><?= aic_md((string) $report['summary']) ?></div>
                    <?php if (is_array($payload) && $payload): ?>
                        <details style="margin-top:12px"><summary style="cursor:pointer;font-weight:600">الأرقام اللي Claude حفظها مع التقرير</summary>
                            <pre dir="ltr" style="white-space:pre-wrap;background:#F6F8FB;border-radius:10px;padding:10px;font-size:12px;max-height:360px;overflow:auto"><?= e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="a2-h"><h3>الأرشيف</h3><span class="chip"><?= count($reports) ?></span></div>
                <?php if (!$reports): ?><div class="a2-empty">فاضي</div><?php endif; ?>
                <?php foreach ($reports as $r): ?>
                    <a href="?type=<?= e($type) ?>&id=<?= (int) $r['id'] ?>" style="display:block;padding:9px 10px;border-radius:12px;margin-bottom:4px;text-decoration:none;color:inherit;<?= $report && (int) $report['id'] === (int) $r['id'] ? 'background:#EEF2FF' : '' ?>">
                        <b><?= e($r['report_date']) ?></b>
                        <div class="a2-muted" style="font-size:12px;line-height:1.6"><?= e(mb_substr(preg_replace('/[#*|`]/u', '', (string) $r['teaser']), 0, 110)) ?>…</div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
