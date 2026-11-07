<?php
/**
 * Spread AI — الأدمن: ربحية الباقات (المرحلة 8)
 * لكل باقة في الفترة: المشتركين · الإيراد (موزّع على أيام الدورة) + الإضافات · المدفوع فعلًا · تكلفة الـ AI الفعلية
 * · الربح والهامش · لكل مشترك · أعلى المهام تكلفة — + الفيديو · عمليات النظام · مستويات الجودة · العملاء اللي بيخسّروا · CSV
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/profitability.php';

require_admin();
require_admin_can('view_costs');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (admin_can('manage_packages') && ($_POST['action'] ?? '') === 'alert') {
        set_setting('profit_alert_margin', (string) max(0, min(100, (int) ($_POST['profit_alert_margin'] ?? 30))));
        admin_log('profit_alert_margin', 'settings', null, (string) (int) ($_POST['profit_alert_margin'] ?? 30));
        flash_set('success', 'اتحفظ ✓');
    }
    redirect('admin/profitability.php?' . http_build_query(array_intersect_key($_GET, array_flip(['p', 'from', 'to']))));
}

$per = a2_period((string) ($_GET['p'] ?? 'month'), $_GET['from'] ?? null, $_GET['to'] ?? null);
$rep = usage_ready() ? pf_report($per['from'], $per['to']) : null;

if ($rep && ($_GET['export'] ?? '') === 'csv') {
    admin_log('profitability_export', 'report', null, $per['label']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="profitability-' . $per['from_d'] . '_' . $per['to_d'] . '.csv"');
    echo pf_csv($rep, $per['label']);
    exit;
}

$taskLabels = [];
foreach (a2_all('SELECT task, label FROM ai_task_routes') as $r) $taskLabels[$r['task']] = $r['label'];
$tierNames = function_exists('pkg_tiers') ? pkg_tiers() : [];
$lvl = ['loss' => ['chip-coral', 'خسران'], 'low' => ['chip-amber', 'هامش قليل'], 'cost' => ['chip-line', 'تكلفة بس'], 'ok' => ['chip-mint', 'كويس']];
$color = fn($v) => $v >= 0 ? '#0B8F83' : '#C2362E';
$qs = fn(array $o = []) => '?' . http_build_query(array_merge(array_intersect_key($_GET, array_flip(['p', 'from', 'to'])), $o));

$active = 'profitability';
$page_title = 'ربحية الباقات';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>💹 ربحية الباقات</h1>
                <div class="sub">اللي اتدفع في كل باقة قدام تكلفة الـ AI الفعلية — <?= e($per['label']) ?> (<?= e($per['from_d']) ?> ← <?= e($per['to_d']) ?>)</div>
            </div>
            <div class="a2-seg">
                <?php foreach (['month' => 'الشهر ده', 'last_month' => 'الشهر اللي فات', '30d' => '30 يوم', '90d' => '3 شهور'] as $k => $l): ?>
                    <a href="?p=<?= $k ?>" class="<?= $per['key'] === $k ? 'on' : '' ?>"><?= e($l) ?></a>
                <?php endforeach; ?>
                <?php if ($rep): ?><a href="<?= e($qs(['export' => 'csv'])) ?>">⬇ CSV</a><?php endif; ?>
            </div>
        </div>
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
            <input type="hidden" name="p" value="custom">
            <label class="field" style="margin:0"><small>من</small><input type="date" name="from" class="input" value="<?= e($per['from_d']) ?>"></label>
            <label class="field" style="margin:0"><small>لحد</small><input type="date" name="to" class="input" value="<?= e($per['to_d']) ?>"></label>
            <button class="btn ghost sm">عرض</button>
        </form>

        <?php if (!$rep): ?>
            <div class="alert warning">لازم تشغّل ترحيل «8-أ» (تكاليف الـ AI) الأول من <a href="<?= url('admin/migrations.php') ?>">الترحيلات</a>.</div>
        <?php else: $t = $rep['totals']; ?>
            <div class="a2-grid a2-g6" style="margin-bottom:16px">
                <div class="a2-kpi"><small>الإيراد (موزّع + إضافات)</small><b><?= a2egp($t['revenue']) ?></b><em>المدفوع فعلًا في الفترة: <?= a2egp($t['cash']) ?></em></div>
                <div class="a2-kpi"><small>تكلفة الـ AI</small><b><?= a2egp($t['cost'] + $rep['system']['cost']) ?></b><em><?= a2n($t['runs'] + $rep['system']['runs']) ?> عملية · منها النظام <?= a2egp($rep['system']['cost']) ?></em></div>
                <div class="a2-kpi"><small>الربح بعد الـ AI</small><b style="color:<?= $color($t['profit']) ?>"><?= a2egp($t['profit']) ?></b><em><?= $t['margin'] !== null ? 'هامش ' . $t['margin'] . '%' : '—' ?></em></div>
                <div class="a2-kpi"><small>المشتركين</small><b><?= a2n($t['subs']) ?></b><em>جداد في الفترة: <?= a2n($t['new']) ?></em></div>
                <div class="a2-kpi"><small>الكريدت المستهلك</small><b><?= a2n($t['credits']) ?></b><em>تصميمات: <?= a2n($t['designs']) ?></em></div>
                <div class="a2-kpi"><small>تكلفة كل 100 ج إيراد</small><b><?= $t['revenue'] > 0 ? a2n(($t['cost'] + $rep['system']['cost']) / $t['revenue'] * 100, 1) . ' ج' : '—' ?></b><em>حد التنبيه: هامش أقل من <?= a2n($rep['alert']) ?>%</em></div>
            </div>

            <div class="card" style="margin-bottom:16px">
                <div class="a2-h"><h3>حسب الباقة</h3><span class="a2-en"><?= e($per['label']) ?></span></div>
                <?php if (!$rep['rows']): ?><div class="a2-empty">مفيش اشتراكات ولا عمليات في الفترة دي</div><?php else: ?>
                <div class="a2-tw"><table class="a2-tbl">
                    <thead><tr><th>الباقة</th><th>مشتركين</th><th>الإيراد</th><th>تكلفة الـ AI</th><th>الربح</th><th>الهامش</th><th>لكل مشترك<br><small>إيراد / تكلفة</small></th><th>استهلاك</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rep['rows'] as $r): [$cls, $lbl] = $lvl[$r['level']]; ?>
                        <tr<?= $r['id'] <= 0 ? ' style="background:#F7FAFD"' : '' ?>>
                            <td><b><?= e($r['name']) ?></b><?php if ($r['tier'] !== '' && $r['id'] > 0): ?> <span class="a2-muted">· <?= e($tierNames[$r['tier']] ?? $r['tier']) ?></span><?php endif; ?>
                                <?php if ($r['unknown_amount']): ?><div class="a2-muted" style="font-size:11px">⚠ <?= (int) $r['unknown_amount'] ?> تفعيل يدوي من غير مبلغ مسجّل (إيراده صفر)</div><?php endif; ?></td>
                            <td class="num"><?= $r['id'] === PF_VIDEO ? (int) $r['new'] . ' طلب' : a2n($r['subs']) . ($r['new'] ? ' <span class="a2-muted">(+' . (int) $r['new'] . ' جديد)</span>' : '') ?></td>
                            <td class="num"><?= a2n($r['revenue'], 2) ?><?php if ($r['addons'] > 0): ?><div class="a2-muted" style="font-size:11px">منها إضافات <?= a2n($r['addons'], 2) ?></div><?php endif; ?>
                                <?php if ($r['id'] !== PF_VIDEO && abs($r['cash'] - $r['accrued']) > 0.5): ?><div class="a2-muted" style="font-size:11px">اتدفع في الفترة <?= a2n($r['cash'], 2) ?></div><?php endif; ?></td>
                            <td class="num"><?= $r['id'] === PF_VIDEO ? '<span class="a2-muted">يدوي</span>' : a2n($r['cost'], 2) ?></td>
                            <td class="num" style="color:<?= $color($r['profit']) ?>;font-weight:700"><?= a2n($r['profit'], 2) ?></td>
                            <td class="num"><?= $r['margin'] !== null ? $r['margin'] . '%' : '—' ?></td>
                            <td class="num"><?= $r['subs'] ? a2n($r['rev_per_sub'], 1) . ' / ' . a2n($r['cost_per_sub'], 1) : '—' ?></td>
                            <td class="num" style="font-size:12px"><?= a2n($r['runs']) ?> عملية<br><?= a2n($r['credits']) ?> كريدت · <?= a2n($r['designs']) ?> تصميم</td>
                            <td><span class="chip <?= $cls ?>"><?= e($lbl) ?></span>
                                <?php if ($r['tasks']): ?><details><summary style="cursor:pointer;font-size:11.5px">أعلى تكلفة</summary>
                                    <?php foreach ($r['tasks'] as $tk => $c): ?><div style="font-size:11.5px;white-space:nowrap"><?= e($taskLabels[$tk] ?? $tk) ?>: <?= a2n($c, 2) ?></div><?php endforeach; ?></details><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rep['system']['runs']): ?>
                        <tr style="background:#F7FAFD"><td><b>عمليات النظام</b><div class="a2-muted" style="font-size:11px">من غير عميل (اختبارات الأدمن · الصيانة)</div></td><td></td><td class="num">—</td>
                            <td class="num"><?= a2n($rep['system']['cost'], 2) ?></td><td class="num" style="color:#C2362E;font-weight:700"><?= a2n(-$rep['system']['cost'], 2) ?></td><td></td><td></td><td class="num"><?= a2n($rep['system']['runs']) ?> عملية</td><td></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
                <p class="a2-muted" style="margin:10px 0 0;line-height:1.9">
                    <b>الإيراد</b> = اللي اتدفع في كل اشتراك موزّع بالتساوي على أيام الدورة (الفترة بتاخد نصيب أيامها بس — السنوي والـ 3 شهور مابيتحسبوش كلهم في شهر الدفع) + الإضافات يوم اعتمادها.
                    <b>التكلفة</b> = التكلفة الفعلية عند المزودين لكل عمليات العميل (بالمحاولات الفاشلة) بسعر الدولار وقت العملية، على الباقة اللي كانت شغالة له يومها.
                    الكريدت الهدية والتجربة إيراده صفر فبيظهر كتكلفة في «بدون باقة مدفوعة».
                </p>
            </div>

            <div class="a2-grid a2-g2" style="margin-bottom:16px">
                <div class="card">
                    <div class="a2-h"><h3>🔻 عملاء تكلفتهم أكتر من اللي دفعوه</h3></div>
                    <?php if (!$rep['users']): ?><div class="a2-empty">مفيش — كل العملاء مكسبين في الفترة دي ✓</div><?php else: ?>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>العميل</th><th>الباقة</th><th>إيراد</th><th>تكلفة</th><th>الفرق</th></tr></thead><tbody>
                    <?php foreach ($rep['users'] as $x): ?>
                        <tr><td><a href="<?= url('admin/user-view.php?id=' . (int) $x['user_id']) ?>"><?= e($x['name']) ?></a><br><span class="a2-muted"><?= e($x['email']) ?></span></td>
                            <td style="font-size:12px"><?= e($x['pkg_name']) ?></td><td class="num"><?= a2n($x['revenue'], 2) ?></td><td class="num"><?= a2n($x['cost'], 2) ?></td>
                            <td class="num" style="color:#C2362E;font-weight:700"><?= a2n($x['profit'], 2) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <div class="a2-h"><h3>⭐ حسب مستوى الجودة</h3></div>
                    <?php if (!$rep['tiers']): ?><div class="a2-empty">—</div><?php else: ?>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المستوى</th><th>مشتركين</th><th>إيراد</th><th>تكلفة</th><th>تكلفة/مشترك</th><th>الهامش</th></tr></thead><tbody>
                    <?php foreach ($rep['tiers'] as $k => $x): $m = $x['revenue'] > 0 ? round(($x['revenue'] - $x['cost']) / $x['revenue'] * 100, 1) : null; ?>
                        <tr><td><b><?= e($tierNames[$k] ?? $k) ?></b></td><td class="num"><?= a2n($x['subs']) ?></td><td class="num"><?= a2n($x['revenue'], 2) ?></td><td class="num"><?= a2n($x['cost'], 2) ?></td>
                            <td class="num"><?= $x['subs'] ? a2n($x['cost'] / $x['subs'], 2) : '—' ?></td><td class="num" style="color:<?= $color((float) ($m ?? 0)) ?>"><?= $m !== null ? $m . '%' : '—' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p class="a2-muted" style="margin:8px 0 0">لو مستوى هامشه قليل: زوّد «معامل سعر الكتابة» بتاعه من <a href="<?= url('admin/quality-tiers.php') ?>">مستويات الجودة</a> أو سعر الباقة.</p>
                    <?php endif; ?>
                    <?php if (admin_can('manage_packages')): ?>
                    <form method="POST" style="display:flex;gap:8px;align-items:flex-end;margin-top:14px">
                        <?= csrf_field() ?><input type="hidden" name="action" value="alert">
                        <label class="field" style="margin:0;flex:1"><small>نبّهني لو هامش الباقة أقل من (%)</small><input type="number" name="profit_alert_margin" class="input" min="0" max="100" value="<?= (int) $rep['alert'] ?>"></label>
                        <button class="btn ghost sm">حفظ</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <p class="a2-muted">تفاصيل كل مهمة وموديل في <a href="<?= url('admin/ai-costs.php') ?>">تكاليف الـ AI</a> · الأسعار في <a href="<?= url('admin/packages.php') ?>">الباقات</a> و<a href="<?= url('admin/pricing.php') ?>">تسعير العمليات</a>.</p>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
