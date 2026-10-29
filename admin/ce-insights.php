<?php
/**
 * Spread AI — الأدمن: الاستخدام والتعلّم (المرحلة I)
 *   الاستخدام: الاستهلاك يومي وشهري · لكل مرحلة وموديل وعميل · أغلى 10 حملات · نسبة الإعادة لكل مرحلة · الـ Playground لوحده
 *   التعلّم: التقرير الشهري — درجة الناقد قدام الأداء الحقيقي (عام · لكل تخصص · لكل براند) + اللي اتطبّق على ملفات الهوية
 *   النتايج: آخر نتايج دخّلها العملاء
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ce-lab.php';
require_once __DIR__ . '/../includes/ce-learning.php';
require_once __DIR__ . '/../includes/ce-publish.php';

require_admin();
require_admin_can('ai_settings');
$ready = ce_ready() && ce_write_ready();
$lready = ce_learning_ready();
$tab = in_array($_GET['tab'] ?? '', ['usage', 'learning', 'results'], true) ? $_GET['tab'] : 'usage';
$aid = (int) (current_admin()['id'] ?? 0) ?: null;
$period = ce_learning_period_ok((string) ($_GET['period'] ?? '')) ? (string) $_GET['period'] : ce_learning_last_month();

if ($lready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = (string) ($_POST['act'] ?? '');
    $p = ce_learning_period_ok((string) ($_POST['period'] ?? '')) ? (string) $_POST['period'] : $period;
    if ($act === 'run') {
        $r = ce_learning_month($p);
        admin_log('ce_learning_run', 'ce_learning_report', null, $p);
        flash_set(isset($r['error']) ? 'danger' : 'success', isset($r['error']) ? $r['error'] : 'التقرير اتعمل ✓ — ' . (int) ($r['global']['n'] ?? 0) . ' قطعة · ' . $r['skills'] . ' تخصص · ' . $r['users'] . ' براند');
        redirect('admin/ce-insights.php?tab=learning&period=' . $p);
    }
    if ($act === 'settings') {
        set_setting('ce_learning_min_n', (string) max(3, min(200, (int) ($_POST['min_n'] ?? 5))));
        set_setting('ce_learning_apply', !empty($_POST['apply']) ? '1' : '0');
        admin_log('ce_learning_settings', 'settings', null);
        flash_set('success', 'اتحفظ ✓');
        redirect('admin/ce-insights.php?tab=learning&period=' . $p);
    }
    if ($act === 'meta_settings') {
        set_setting('ce_meta_auto_match', !empty($_POST['auto']) ? '1' : '0');
        set_setting('ce_meta_match_min', (string) max(0.5, min(0.99, round((float) ($_POST['match_min'] ?? 0.75), 2))));
        set_setting('ce_meta_sync_days', (string) max(3, min(90, (int) ($_POST['days'] ?? 30))));
        set_setting('ce_meta_sync_hours', (string) max(1, min(72, (int) ($_POST['hours'] ?? 12))));
        admin_log('ce_meta_settings', 'settings', null);
        flash_set('success', 'اتحفظ ✓');
        redirect('admin/ce-insights.php?tab=results');
    }
    if ($act === 'meta_now') {
        @set_time_limit(300);
        $m = ce_meta_auto_match(50);
        $sy = ce_meta_sync_due(100);
        admin_log('ce_meta_sync_now', 'ce_performance', null, json_encode([$m, $sy]));
        flash_set('success', 'ربط تلقائي: ' . $m['linked'] . ' قطعة · اتحدّث: ' . $sy['synced'] . ' بوست');
        redirect('admin/ce-insights.php?tab=results');
    }
    if ($act === 'skill_draft') {
        $r = ce_learning_skill_draft((int) ($_POST['report_id'] ?? 0), $aid);
        if (!$r['ok']) { flash_set('danger', $r['error']); redirect('admin/ce-insights.php?tab=learning&period=' . $p); }
        admin_log('ce_skill_learning_draft', 'ce_skill', $r['skill_id'], 'v' . $r['version']);
        flash_set('success', 'اتعملت نسخة v' . $r['version'] . ' (مسودة) فيها الـ Hooks الناجحة — اختبرها وفعّلها من هنا');
        redirect('admin/ce-skills.php?id=' . (int) $r['skill_id'] . '&v=' . (int) $r['id']);
    }
    redirect('admin/ce-insights.php?tab=' . $tab);
}

$page_title = 'الاستخدام والتعلّم';
$active = 'ce-insights';
include __DIR__ . '/../templates/admin-header.php';
$usd = fn($v) => '$' . number_format((float) $v, (float) $v < 1 ? 4 : 2);
$num = fn($v) => number_format((float) $v);
$pct = fn($v) => $v === null ? '—' : $v . '%';
$stageLabel = fn(string $t) => CE_PROMPT_STAGES[$t] ?? (['ce_image' => 'توليد الصورة', 'ce_write' => 'الكتابة'][$t] ?? $t);
$corr = fn($v) => $v === null ? '—' : number_format((float) $v, 2);
$corrTone = fn($v) => $v === null ? 'chip-line' : ((float) $v >= 0.35 ? 'chip-mint' : ((float) $v >= 0.15 ? 'chip-amber' : 'chip-coral'));
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main ce-adm">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head">
            <h1>الاستخدام والتعلّم 📈</h1>
            <div class="sub">بنصرف قد إيه وفين · وهل درجة الناقد فعلًا بتتنبأ بأداء المحتوى بعد النشر — والمحرك بيتعلّم من أحسن القطع كل شهر</div>
        </div>
<?php if (!$ready): ?>
        <div class="alert warning">شغّل ترحيلات المحرك من <a href="<?= e(url('admin/migrations.php')) ?>">الترحيلات</a> الأول.</div>
<?php else: ?>
        <div class="a2-seg" style="margin-bottom:14px">
            <?php foreach (['usage' => 'الاستخدام والتكلفة', 'learning' => 'التقرير الشهري (التعلّم)', 'results' => 'نتايج العملاء'] as $k => $l): ?>
                <a class="<?= $tab === $k ? 'on' : '' ?>" href="?tab=<?= $k ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>

<?php if ($tab === 'usage'):
    $days = (int) ($_GET['days'] ?? 30);
    $all = !empty($_GET['all']);
    $U = ce_usage($days, !$all);
    $T = $U['total'];
    $calls = max(1, (int) $T['calls']);
    $maxDay = max(0.000001, ...array_map(fn($d) => (float) $d['cost'], $U['daily'] ?: [['cost' => 0]]));
?>
        <form class="ce-ins-filter" method="get"><input type="hidden" name="tab" value="usage">
            <div class="a2-seg"><?php foreach ([7 => '7 أيام', 30 => '30 يوم', 90 => '90 يوم', 365 => 'سنة'] as $d => $l): ?>
                <a class="<?= $U['range']['days'] === $d ? 'on' : '' ?>" href="?tab=usage&days=<?= $d ?><?= $all ? '&all=1' : '' ?>"><?= $l ?></a><?php endforeach; ?></div>
            <a class="btn sm ghost" href="?tab=usage&days=<?= $U['range']['days'] ?><?= $all ? '' : '&all=1' ?>"><?= $all ? 'المحرك الجديد بس' : 'كل نداءات الـ AI في الموقع' ?></a>
        </form>
        <div class="ce-kpis">
            <div class="ce-f"><span>التكلفة</span><b dir="ltr"><?= $usd($T['cost']) ?></b><small><?= $num($T['credits']) ?> كريدت اتخصم</small></div>
            <div class="ce-f"><span>النداءات</span><b><?= $num($T['calls']) ?></b><small>فشل <?= round(100 * (int) $T['fails'] / $calls, 1) ?>% · متوسط <?= $num($T['ms']) ?> ms</small></div>
            <div class="ce-f"><span>التوكنز</span><b><?= $num($T['tin'] + $T['tout']) ?></b><small>داخل <?= $num($T['tin']) ?> · خارج <?= $num($T['tout']) ?> · cached <?= $num($T['tcache']) ?></small></div>
            <div class="ce-f"><span>الـ Playground</span><b dir="ltr"><?= $usd($U['sandbox']['cost']) ?></b><small><?= $num($U['sandbox']['calls']) ?> نداء (برّه الحساب ده)</small></div>
            <?php if ((int) $T['unknown_cost']): ?><div class="ce-f"><span>تكلفة مجهولة ⚠</span><b><?= $num($T['unknown_cost']) ?></b><small>نداء ناجح بتوكنز وتكلفته 0 — الموديل مالوش سعر في السجل</small></div><?php endif; ?>
        </div>

        <section class="card">
            <div class="a2-h"><h3>يوم بيوم</h3><span class="a2-grow"></span><small class="a2-muted"><?= e(substr($U['range']['from'], 0, 10)) ?> ← <?= e(substr($U['range']['to'], 0, 10)) ?></small></div>
            <?php if ($U['daily']): ?>
            <div class="ce-bars" role="img" aria-label="التكلفة اليومية">
                <?php foreach ($U['daily'] as $d): ?><i style="height:<?= max(2, round(100 * (float) $d['cost'] / $maxDay)) ?>%" title="<?= e($d['d'] . ' · ' . $usd($d['cost']) . ' · ' . $d['calls'] . ' نداء') ?>"></i><?php endforeach; ?>
            </div>
            <?php else: ?><p class="a2-muted">مفيش نداءات في الفترة دي.</p><?php endif; ?>
        </section>

        <div class="ce-fgrid" style="grid-template-columns:minmax(0,3fr) minmax(0,2fr)">
            <section class="card">
                <div class="a2-h"><h3>لكل مرحلة</h3></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المرحلة</th><th>نداءات</th><th>توكنز (داخل · خارج)</th><th>التكلفة</th><th>فشل</th><th>ms</th></tr></thead><tbody>
                <?php foreach ($U['by_stage'] as $s): ?>
                    <tr><td><?= e($stageLabel($s['task'])) ?></td><td class="num"><?= $num($s['calls']) ?></td><td class="num"><?= $num($s['tin']) ?> · <?= $num($s['tout']) ?></td>
                        <td class="num" dir="ltr"><?= $usd($s['cost']) ?></td><td class="num"><?= round(100 * (int) $s['fails'] / max(1, (int) $s['calls']), 1) ?>%</td><td class="num"><?= $num($s['ms']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$U['by_stage']): ?><tr><td colspan="6" class="a2-muted">—</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
            <section class="card">
                <div class="a2-h"><h3>نسبة الإعادة لكل مرحلة</h3></div>
                <p class="a2-muted">لو نسبة مرحلة عالية، غالبًا برومبتها أو موديلها محتاج تحسين — جرّبه في <a href="<?= e(url('admin/ce-lab.php')) ?>">الـ Playground</a>.</p>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المرحلة</th><th>إعادة</th><th>من</th><th>النسبة</th></tr></thead><tbody>
                <?php foreach ($U['revise'] as $r): ?>
                    <tr><td><?= e($r['label']) ?></td><td class="num"><?= $num($r['revise']) ?></td><td class="num"><?= $num($r['n']) ?></td>
                        <td><span class="chip <?= $r['rate'] === null ? 'chip-line' : ($r['rate'] >= 50 ? 'chip-coral' : ($r['rate'] >= 25 ? 'chip-amber' : 'chip-mint')) ?>"><?= $pct($r['rate']) ?></span></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </section>
        </div>

        <div class="ce-fgrid" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
            <section class="card">
                <div class="a2-h"><h3>أغلى 10 حملات</h3></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الحملة</th><th>العميل</th><th>نداءات</th><th>التكلفة</th></tr></thead><tbody>
                <?php foreach ($U['top_campaigns'] as $c): ?>
                    <tr><td>#<?= (int) $c['campaign_id'] ?> <?= e(mb_strimwidth((string) $c['title'], 0, 40, '…')) ?></td><td><?= e((string) ($c['name'] ?: $c['email'])) ?></td>
                        <td class="num"><?= $num($c['calls']) ?></td><td class="num" dir="ltr"><?= $usd($c['cost']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$U['top_campaigns']): ?><tr><td colspan="4" class="a2-muted">—</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
            <section class="card">
                <div class="a2-h"><h3>لكل عميل (أعلى 20)</h3></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>العميل</th><th>نداءات</th><th>كريدت</th><th>التكلفة</th></tr></thead><tbody>
                <?php foreach ($U['by_user'] as $u): ?>
                    <tr><td><?= e((string) ($u['name'] ?: ('#' . $u['user_id']))) ?> <small class="a2-muted"><?= e((string) $u['email']) ?></small></td>
                        <td class="num"><?= $num($u['calls']) ?></td><td class="num"><?= $num($u['credits']) ?></td><td class="num" dir="ltr"><?= $usd($u['cost']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$U['by_user']): ?><tr><td colspan="4" class="a2-muted">—</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
        </div>

        <div class="ce-fgrid" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
            <section class="card">
                <div class="a2-h"><h3>لكل موديل</h3></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الموديل</th><th>نداءات</th><th>توكنز</th><th>التكلفة</th></tr></thead><tbody>
                <?php foreach ($U['by_model'] as $m): ?>
                    <tr><td dir="ltr"><?= e($m['model']) ?></td><td class="num"><?= $num($m['calls']) ?></td><td class="num"><?= $num($m['tin'] + $m['tout']) ?></td><td class="num" dir="ltr"><?= $usd($m['cost']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$U['by_model']): ?><tr><td colspan="4" class="a2-muted">—</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
            <section class="card">
                <div class="a2-h"><h3>شهر بشهر</h3></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الشهر</th><th>نداءات</th><th>توكنز</th><th>كريدت</th><th>التكلفة</th></tr></thead><tbody>
                <?php foreach (array_reverse($U['monthly']) as $m): ?>
                    <tr><td><?= e($m['m']) ?></td><td class="num"><?= $num($m['calls']) ?></td><td class="num"><?= $num($m['tokens']) ?></td><td class="num"><?= $num($m['credits']) ?></td><td class="num" dir="ltr"><?= $usd($m['cost']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$U['monthly']): ?><tr><td colspan="5" class="a2-muted">—</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
        </div>

<?php elseif (!$lready): ?>
        <div class="alert warning">شغّل ترحيل «2026_ce_learning» من <a href="<?= e(url('admin/migrations.php')) ?>">الترحيلات</a> الأول.</div>

<?php elseif ($tab === 'learning'):
    $G = db_one('SELECT * FROM ce_learning_reports WHERE period = ? AND scope = "global" AND scope_id = 0', [$period]);
    $rep = $G ? (json_decode((string) $G['report_json'], true) ?: null) : null;
    $skillReps = db_all('SELECT r.*, s.name sname FROM ce_learning_reports r LEFT JOIN ce_skills s ON s.id = r.scope_id WHERE r.period = ? AND r.scope = "skill" ORDER BY r.n DESC', [$period]);
    $userReps = db_all('SELECT r.*, u.name uname, u.email FROM ce_learning_reports r LEFT JOIN users u ON u.id = r.scope_id WHERE r.period = ? AND r.scope = "user" ORDER BY r.n DESC LIMIT 50', [$period]);
    $months = [];
    for ($i = 1; $i <= 12; $i++) $months[] = date('Y-m', strtotime("first day of -$i months"));
    array_unshift($months, date('Y-m'));
    $points = $rep && $rep['enough'] ? ce_learning_rows($period) : [];
    $dimNames = ['format' => 'الشكل', 'angle' => 'الزاوية', 'pillar' => 'العمود', 'trigger_type' => 'الدافع', 'funnel' => 'المرحلة'];
?>
        <div class="ce-fgrid" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr)">
            <section class="card">
                <div class="a2-h"><h3>تقرير شهر</h3><span class="a2-grow"></span>
                    <form method="get" class="ce-row-btns"><input type="hidden" name="tab" value="learning">
                        <select class="form-control" name="period" onchange="this.form.submit()" aria-label="الشهر"><?php foreach ($months as $m): ?><option value="<?= e($m) ?>" <?= $m === $period ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></form>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="run"><input type="hidden" name="period" value="<?= e($period) ?>"><button class="btn sm"><?= $G ? 'حدّث التقرير' : 'اعمل التقرير' ?></button></form></div>
                <?php if (!$rep): ?>
                    <p class="a2-muted">التقرير ده لسه ماتعملش. بيتعمل تلقائي أول كل شهر (عن الشهر اللي فات) لو الـ cron شغال — أو اضغط «اعمل التقرير».</p>
                <?php else: ?>
                    <div class="ce-kpis">
                        <div class="ce-f"><span>العلاقة (Spearman)</span><b><span class="chip <?= $corrTone($rep['enough'] ? $rep['spearman'] : null) ?>"><?= $rep['enough'] ? $corr($rep['spearman']) : '—' ?></span></b><small><?= e($rep['label']) ?></small></div>
                        <div class="ce-f"><span>Pearson</span><b><?= $rep['enough'] ? $corr($rep['pearson']) : '—' ?></b><small>على القيم نفسها</small></div>
                        <div class="ce-f"><span>قطع بنتايج</span><b><?= (int) $rep['n'] ?></b><small>أقل عدد <?= (int) $rep['min_n'] ?></small></div>
                        <div class="ce-f"><span>متوسط التفاعل</span><b><?= e((string) $rep['avg_rate']) ?>%</b><small>متوسط الجودة <?= e((string) ($rep['avg_qa'] ?? '—')) ?></small></div>
                    </div>
                    <?php if ($points): $maxR = max(0.01, ...array_map(fn($r) => (float) $r['eng_rate'], $points)); $minQ = min(array_map(fn($r) => (float) $r['qa_score'], $points)); ?>
                    <figure class="ce-scatter" aria-label="درجة الناقد قدام التفاعل">
                        <svg viewBox="0 0 400 220" role="img"><title>كل نقطة قطعة: يمين = درجة أعلى · فوق = تفاعل أعلى</title>
                            <line x1="30" y1="200" x2="395" y2="200" class="ax"/><line x1="30" y1="5" x2="30" y2="200" class="ax"/>
                            <?php foreach ($points as $pt): $x = 30 + 365 * (((float) $pt['qa_score'] - $minQ) / max(1, 100 - $minQ)); $y = 200 - 190 * ((float) $pt['eng_rate'] / $maxR); ?>
                                <circle cx="<?= round($x, 1) ?>" cy="<?= round($y, 1) ?>" r="4"><title><?= e('الجودة ' . $pt['qa_score'] . ' · التفاعل ' . $pt['eng_rate'] . '%') ?></title></circle>
                            <?php endforeach; ?>
                            <text x="395" y="215" text-anchor="end">درجة الناقد ←</text><text x="34" y="14">التفاعل %</text>
                        </svg>
                    </figure>
                    <?php endif; ?>

                    <h4>أنهي معيار بيتنبأ بالأداء؟</h4>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المعيار</th><th>العلاقة</th><th>الوزن دلوقتي</th><?php if ($rep['weights_hint']): ?><th>وزن مقترح</th><?php endif; ?></tr></thead><tbody>
                    <?php foreach ($rep['criteria'] as $k => $c): ?>
                        <tr><td><?= e($c['label']) ?></td><td><span class="chip <?= $corrTone($c['spearman']) ?>"><?= $corr($c['spearman']) ?></span></td><td class="num"><?= e((string) ($c['weight'] ?? '—')) ?></td>
                            <?php if ($rep['weights_hint']): ?><td class="num"><?= e((string) ($rep['weights_hint'][$k] ?? '—')) ?></td><?php endif; ?></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php if ($rep['weights_hint']): ?><p class="a2-muted">الأوزان المقترحة بتدّي وزن أكبر للمعايير اللي ليها علاقة أقوى بالتفاعل الحقيقي. ده اقتراح بس — لو موافق غيّرها من <a href="<?= e(url('admin/ce-settings.php?tab=write')) ?>">الكتابة والجودة</a>.</p><?php endif; ?>

                    <h4>إيه اللي بيجيب تفاعل أعلى</h4>
                    <?php if ($rep['patterns']): ?><ul><?php foreach ($rep['patterns'] as $pt): ?><li><?= e($pt) ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <div class="ce-fgrid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
                    <?php foreach ($rep['dims'] as $dim => $list): if (!$list) continue; ?>
                        <div><b><?= e($dimNames[$dim] ?? $dim) ?></b>
                            <table class="a2-tbl"><tbody><?php foreach (array_slice($list, 0, 6) as $x): ?>
                                <tr><td><?= e($x['label']) ?> <small class="a2-muted">(<?= (int) $x['n'] ?>)</small></td><td class="num"><?= e((string) $x['avg_rate']) ?>%</td>
                                    <td class="num" style="color:<?= $x['lift'] >= 0 ? '#0B6B78' : '#C2362E' ?>"><?= $x['lift'] >= 0 ? '+' : '' ?><?= (int) $x['lift'] ?>%</td></tr>
                            <?php endforeach; ?></tbody></table></div>
                    <?php endforeach; ?>
                    </div>

                    <h4>أحسن 3 قطع</h4>
                    <?php foreach ($rep['top'] as $t): ?>
                        <div class="ce-ins-post"><b><?= e((string) $t['eng_rate']) ?>%</b> <small class="a2-muted">· الجودة <?= e((string) $t['qa_score']) ?> · <?= e(CE_FORMATS[$t['format']] ?? $t['format']) ?> · حملة #<?= (int) $t['campaign_id'] ?></small>
                            <p><?= e($t['hook'] ?: $t['excerpt']) ?></p></div>
                    <?php endforeach; ?>
                    <?php if ($rep['bottom']): ?><h4>الأضعف</h4>
                        <?php foreach ($rep['bottom'] as $t): ?><div class="ce-ins-post weak"><b><?= e((string) $t['eng_rate']) ?>%</b> <small class="a2-muted">· الجودة <?= e((string) $t['qa_score']) ?></small><p><?= e($t['hook'] ?: $t['excerpt']) ?></p></div><?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <div>
                <section class="card">
                    <div class="a2-h"><h3>الإعدادات</h3></div>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="settings"><input type="hidden" name="period" value="<?= e($period) ?>">
                        <label class="ce-f"><span>أقل عدد قطع بنتايج عشان نحسب العلاقة</span><input class="form-control" type="number" name="min_n" min="3" max="200" value="<?= ce_setting_int('ce_learning_min_n', 5) ?>"></label>
                        <label class="ce-check"><input type="hidden" name="apply" value="0"><input type="checkbox" name="apply" value="1" <?= ce_setting_int('ce_learning_apply', 1) === 1 ? 'checked' : '' ?>>
                            <span>التقرير يحدّث ملف هوية كل براند: أحسن 3 قطع بقت أمثلة للكاتب + الأنماط الناجحة (من غير ما يلمس اللي العميل كتبه)</span></label>
                        <button class="btn sm">احفظ</button>
                    </form>
                </section>
                <section class="card">
                    <div class="a2-h"><h3>لكل تخصص</h3></div>
                    <?php foreach ($skillReps as $s): $sr = json_decode((string) $s['report_json'], true) ?: []; ?>
                        <div class="ce-ins-row"><b><?= e((string) ($s['sname'] ?: ('#' . $s['scope_id']))) ?></b>
                            <span class="chip <?= $corrTone($s['spearman']) ?>"><?= $corr($s['spearman']) ?></span> <small class="a2-muted"><?= (int) $s['n'] ?> قطعة</small>
                            <?php if (array_filter((array) ($sr['top'] ?? []), fn($t) => !empty($t['hook']))): ?>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="skill_draft"><input type="hidden" name="report_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="period" value="<?= e($period) ?>">
                                <button class="btn sm ghost">نسخة جديدة للـ Skill بالـ Hooks الناجحة</button></form>
                            <?php endif; ?></div>
                    <?php endforeach; ?>
                    <?php if (!$skillReps): ?><p class="a2-muted">مفيش حملات بـ Skills ليها نتايج في الشهر ده.</p><?php endif; ?>
                </section>
                <section class="card">
                    <div class="a2-h"><h3>لكل براند</h3></div>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>البراند</th><th>قطع</th><th>العلاقة</th><th>ملف الهوية</th></tr></thead><tbody>
                    <?php foreach ($userReps as $u): $ap = json_decode((string) $u['applied_json'], true) ?: null; ?>
                        <tr><td><?= e((string) ($u['uname'] ?: ('#' . $u['scope_id']))) ?></td><td class="num"><?= (int) $u['n'] ?></td><td><?= $corr($u['spearman']) ?></td>
                            <td><?= $ap ? (!empty($ap['changed']) ? '<span class="chip chip-mint">اتحدّث</span>' : '<span class="chip chip-line">زي ما هو</span>') : '—' ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$userReps): ?><tr><td colspan="4" class="a2-muted">—</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            </div>
        </div>

<?php else: /* النتايج */
    $rows = db_all('SELECT p.*, c.qa_score, c.format, k.title, u.name uname, u.email FROM ce_performance p JOIN ce_contents c ON c.id = p.content_id
                    JOIN ce_campaigns k ON k.id = p.campaign_id LEFT JOIN users u ON u.id = p.user_id ORDER BY COALESCE(p.updated_at, p.created_at) DESC LIMIT 100');
?>
        <?php if (function_exists('ce_publish_ready') && ce_publish_ready()): $ps = ce_publish_stats(); ?>
        <section class="card">
            <div class="a2-h"><h3>النشر من المحرك</h3><span class="a2-grow"></span><small class="a2-muted">الـ worker بينشر اللي معاده جه كل دقيقة</small></div>
            <div class="ce-kpis">
                <div class="ce-f"><span>مستني معاده</span><b><?= (int) ($ps['pending'] ?? 0) ?></b></div>
                <div class="ce-f"><span>اتنشر النهارده</span><b><?= (int) ($ps['today'] ?? 0) ?></b><small>الإجمالي <?= (int) ($ps['published'] ?? 0) ?></small></div>
                <div class="ce-f"><span>فشل</span><b><?= (int) ($ps['failed'] ?? 0) ?></b></div>
            </div>
            <?php if (!empty($ps['recent_failures'])): ?>
            <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>العميل</th><th>المنصة</th><th>المعاد</th><th>السبب</th></tr></thead><tbody>
            <?php foreach ($ps['recent_failures'] as $f): ?>
                <tr><td><?= e((string) ($f['uname'] ?? ('#' . $f['user_id']))) ?></td><td><?= e(CE_PUB_PLATFORMS[$f['platform']] ?? $f['platform']) ?></td><td class="num"><?= e(substr((string) $f['scheduled_at'], 0, 16)) ?></td><td><?= e(mb_strimwidth((string) $f['error'], 0, 120, '…')) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (ce_meta_ready()): $ms = ce_meta_stats(); ?>
        <div class="ce-fgrid" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr)">
            <section class="card">
                <div class="a2-h"><h3>Meta Insights</h3><span class="a2-grow"></span>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="meta_now"><button class="btn sm">اربط واسحب دلوقتي</button></form></div>
                <div class="ce-kpis">
                    <div class="ce-f"><span>قطع مربوطة ببوست</span><b><?= (int) ($ms['linked'] ?? 0) ?></b><small>منهم تلقائي <?= (int) ($ms['auto'] ?? 0) ?></small></div>
                    <div class="ce-f"><span>اتحدّثت آخر 24 ساعة</span><b><?= (int) ($ms['synced_24h'] ?? 0) ?></b><small>الـ worker بيسحب كل 10 دقايق</small></div>
                    <div class="ce-f"><span>تفاعل بس (من غير وصول)</span><b><?= (int) ($ms['partial'] ?? 0) ?></b><small>الصفحة محتاجة صلاحية Insights</small></div>
                    <div class="ce-f"><span>أخطاء / صفحات انتهت</span><b><?= (int) ($ms['bad'] ?? 0) ?></b><small>العميل يربط الصفحة تاني</small></div>
                </div>
                <p class="a2-muted">الصلاحيات المطلوبة من تطبيق Meta: <code>read_insights</code> و<code>instagram_manage_insights</code> (اتضافوا لزرار الربط). الصفحات اللي اتربطت قبل كده لازم تتربط تاني عشان توافق عليهم — ولو التطبيق Live لازم الصلاحيتين يعدّوا App Review.</p>
            </section>
            <section class="card">
                <div class="a2-h"><h3>إعدادات السحب</h3></div>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="meta_settings">
                    <label class="ce-check"><input type="hidden" name="auto" value="0"><input type="checkbox" name="auto" value="1" <?= ce_setting_int('ce_meta_auto_match', 1) === 1 ? 'checked' : '' ?>> <span>ربط تلقائي لو نص البوست شبه القطعة</span></label>
                    <label class="ce-f"><span>أقل تشابه للربط التلقائي (0.5–0.99)</span><input class="form-control" type="number" step="0.01" min="0.5" max="0.99" name="match_min" value="<?= e((string) ce_setting_float('ce_meta_match_min', 0.75)) ?>"></label>
                    <label class="ce-f"><span>نسحب لحد كام يوم بعد النشر</span><input class="form-control" type="number" min="3" max="90" name="days" value="<?= ce_setting_int('ce_meta_sync_days', 30) ?>"></label>
                    <label class="ce-f"><span>نحدّث البوست كل كام ساعة</span><input class="form-control" type="number" min="1" max="72" name="hours" value="<?= ce_setting_int('ce_meta_sync_hours', 12) ?>"></label>
                    <button class="btn sm">احفظ</button>
                </form>
            </section>
        </div>
        <?php endif; ?>

        <section class="card">
            <div class="a2-h"><h3>آخر 100 نتيجة</h3><span class="a2-grow"></span><small class="a2-muted">العميل بيدخّلها من «📈 النتايج بعد النشر» تحت كل قطعة · المعدل = (لايك + 2×كومنت + 3×مشاركة + 3×حفظ + 2×ضغطة + 5×رسالة) ÷ الوصول</small></div>
            <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>العميل</th><th>الحملة</th><th>الشكل</th><th>الجودة</th><th>الوصول</th><th>التفاعل</th><th>رسايل</th><th>المنصة</th><th>النشر</th><th>المصدر</th></tr></thead><tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><?= e((string) ($r['uname'] ?: $r['email'])) ?></td><td>#<?= (int) $r['campaign_id'] ?> <?= e(mb_strimwidth((string) $r['title'], 0, 30, '…')) ?></td>
                    <td><?= e(CE_FORMATS[$r['format']] ?? $r['format']) ?></td><td class="num"><?= e((string) $r['qa_score']) ?></td><td class="num"><?= $num($r['reach']) ?></td>
                    <td class="num"><b><?= e((string) $r['eng_rate']) ?>%</b></td><td class="num"><?= $num($r['messages']) ?></td><td><?= e(CE_PERF_PLATFORMS[$r['platform']] ?? $r['platform']) ?></td>
                    <td class="num"><?= e((string) ($r['published_at'] ?? '—')) ?></td><td><?= e(['manual' => 'يدوي', 'meta' => 'Meta', 'import' => 'استيراد'][$r['source']] ?? $r['source']) ?><?php if (!empty($r['provider_post_id'])): ?>
                        <span class="chip <?= ['ok' => 'chip-mint', 'partial' => 'chip-amber', 'error' => 'chip-coral', 'expired' => 'chip-coral'][$r['sync_status']] ?? 'chip-line' ?>" title="<?= e((string) ($r['sync_error'] ?? '')) ?>"><?= e(['ok' => 'متزامن', 'partial' => 'تفاعل بس', 'error' => 'خطأ', 'expired' => 'الصفحة انتهت', 'pending' => 'مستني'][$r['sync_status']] ?? '—') ?></span>
                        <?php if ($r['permalink']): ?><a href="<?= e((string) $r['permalink']) ?>" target="_blank" rel="noopener">↗</a><?php endif; ?><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="10" class="a2-muted">مفيش نتايج لسه.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
<?php endif; ?>
<?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
