<?php
/**
 * Spread AI — مركز الجودة: تقييمات المحتوى (المرحلة 5)
 * كل تقييمات Claude بفلاتر (الفترة · الدرجة · إصدار البرومبت) + أضعف المعايير + تفاصيل تقييم واحد
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ai-center.php';

require_admin();
require_admin_can('ai_settings');

$ready = aic_has('ai_evaluations');
$E = mcp_t('ai_evaluations');
$per = a2_period((string) ($_GET['p'] ?? '30d'), $_GET['from'] ?? null, $_GET['to'] ?? null);
$num = fn($k) => isset($_GET[$k]) && is_numeric($_GET[$k]) ? max(0, min(10, (float) $_GET[$k])) : null;
$f = [
    'min' => $num('min'),
    'max' => $num('max'),
    'version' => (int) ($_GET['version'] ?? 0),
    'brand' => (int) ($_GET['brand'] ?? 0),
    'low' => !empty($_GET['low']),
];
$where = ['e.created_at >= ?', 'e.created_at < ?'];
$params = [$per['from'], $per['to']];
if ($f['min'] !== null) { $where[] = 'e.overall >= ?'; $params[] = $f['min']; }
if ($f['max'] !== null) { $where[] = 'e.overall <= ?'; $params[] = $f['max']; }
if ($f['version']) { $where[] = 'e.prompt_version_id = ?'; $params[] = $f['version']; }
if ($f['brand']) { $where[] = 'c.brand_profile_id = ?'; $params[] = $f['brand']; }
if ($f['low']) { $where[] = 'e.overall < ?'; $params[] = aic_low(); }
$W = implode(' AND ', $where);
$from = "FROM $E e LEFT JOIN contents c ON c.id = e.content_id LEFT JOIN brand_profiles b ON b.id = c.brand_profile_id";

/* ── تقييم واحد بالتفصيل ── */
$one = null;
if ($ready && ($oid = (int) ($_GET['id'] ?? 0))) {
    $one = db_one("SELECT e.*, c.generated_text, c.format, c.platform, c.feedback, c.created_at post_at, c.user_id, b.business_name, p.prompt_type, p.version pv
                   FROM $E e LEFT JOIN contents c ON c.id = e.content_id LEFT JOIN brand_profiles b ON b.id = c.brand_profile_id LEFT JOIN prompts p ON p.id = e.prompt_version_id WHERE e.id = ?", [$oid]);
    if ($one) {
        $one['cs'] = json_decode((string) $one['content_scores'], true) ?: [];
        $one['ds'] = json_decode((string) $one['design_scores'], true) ?: [];
        $one['designs'] = a2_all('SELECT id, image_path FROM content_designs WHERE content_id = ? ORDER BY id LIMIT 6', [(int) $one['content_id']]);
        $one['others'] = a2_all("SELECT id, overall, created_at FROM $E WHERE content_id = ? AND id <> ? ORDER BY id DESC LIMIT 5", [(int) $one['content_id'], $oid]);
    }
}

/* ── القايمة والأرقام ── */
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = $ready ? (int) a2_scalar("SELECT COUNT(*) $from WHERE $W", $params) : 0;
$agg = $ready ? (db_one("SELECT AVG(e.overall) o, AVG(e.content_total) ct, AVG(e.design_total) dt, SUM(e.overall < ?) lowN $from WHERE $W", array_merge([aic_low()], $params)) ?: []) : [];
$rows = $ready ? a2_all("SELECT e.id, e.content_id, e.prompt_version_id, e.content_total, e.design_total, e.overall, e.created_at, LEFT(e.weaknesses, 140) weak,
                                c.format, c.feedback, LEFT(c.generated_text, 110) excerpt, c.brand_profile_id, b.business_name
                         $from WHERE $W ORDER BY e.id DESC LIMIT 40 OFFSET " . (($page - 1) * 40), $params) : [];

// متوسط كل معيار (آخر 1500 تقييم بالفلاتر) — بيبيّن أضعف نقطة
$crit = array_fill_keys(array_keys(AIC_CONTENT + AIC_DESIGN), [0.0, 0]);
if ($ready) {
    foreach (a2_all("SELECT e.content_scores, e.design_scores $from WHERE $W ORDER BY e.id DESC LIMIT 1500", $params) as $r) {
        foreach ([(string) $r['content_scores'], (string) $r['design_scores']] as $js) {
            foreach ((json_decode($js, true) ?: []) as $k => $v) if (isset($crit[$k]) && is_numeric($v)) { $crit[$k][0] += (float) $v; $crit[$k][1]++; }
        }
    }
}
$critAvg = array_map(fn($x) => $x[1] ? $x[0] / $x[1] : null, $crit);
$weakest = array_filter($critAvg, fn($v) => $v !== null);
asort($weakest);
$weakest = array_slice($weakest, 0, 3, true);

// حسب إصدار البرومبت
$byVer = $ready ? a2_all("SELECT e.prompt_version_id v, p.prompt_type t, p.version pv, p.status, p.is_active, COUNT(*) n, AVG(e.overall) o
                          $from LEFT JOIN prompts p ON p.id = e.prompt_version_id WHERE $W GROUP BY e.prompt_version_id, p.prompt_type, p.version, p.status, p.is_active ORDER BY n DESC LIMIT 12", $params) : [];

$qs = function (array $over = []) use ($f, $per) {
    $q = array_filter(array_merge(['p' => $per['key'], 'from' => $per['key'] === 'custom' ? $per['from_d'] : '', 'to' => $per['key'] === 'custom' ? $per['to_d'] : '',
        'min' => $f['min'], 'max' => $f['max'], 'version' => $f['version'] ?: '', 'brand' => $f['brand'] ?: '', 'low' => $f['low'] ? 1 : ''], $over), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};
$allLbl = AIC_CONTENT + AIC_DESIGN;

$active = 'ai-evaluations';
$page_title = 'تقييمات المحتوى';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>مركز الجودة: تقييمات المحتوى</h1>
                <div class="sub">تقييم Claude لكل منشور: 8 معايير للمحتوى و6 للتصميم (من 10)</div>
            </div>
            <?= aic_tabs('ai-evaluations') ?>
        </div>

        <?php if (!$ready): ?>
            <?= aic_setup_notice() ?>
        <?php else: ?>

        <?php if ($one): ?>
        <div class="card" style="margin-bottom:16px">
            <div class="a2-h">
                <h3>تقييم #<?= (int) $one['id'] ?> · منشور <a href="<?= e(url('admin/content-view.php?id=' . (int) $one['content_id'])) ?>">#<?= (int) $one['content_id'] ?></a></h3>
                <span class="a2-grow"></span><?= aic_score($one['overall'] !== null ? (float) $one['overall'] : null, true) ?>
                <a class="btn sm ghost" href="<?= e($qs()) ?>">رجوع للقايمة</a>
            </div>
            <div class="a2-muted" style="font-size:12.5px;margin-bottom:10px">
                <?= e($one['business_name'] ?? '—') ?> · <?= e((string) $one['format']) ?> · <?= e((string) $one['platform']) ?>
                · البرومبت: <?= $one['prompt_type'] ? e($one['prompt_type']) . ' v' . (int) $one['pv'] . ' (#' . (int) $one['prompt_version_id'] . ')' : '—' ?>
                · تقييم العميل: <?= $one['feedback'] === null ? '—' : ((int) $one['feedback'] === 1 ? '👍' : '👎') ?>
                · المقيّم: <?= e($one['evaluator']) ?> · <?= e($one['created_at']) ?>
            </div>
            <div class="split split-2" style="align-items:flex-start">
                <div>
                    <h4 style="margin:0 0 6px">المحتوى <?= aic_score($one['content_total'] !== null ? (float) $one['content_total'] : null) ?></h4>
                    <?php foreach (AIC_CONTENT as $ck => $cl) echo aic_bar($cl, isset($one['cs'][$ck]) ? (float) $one['cs'][$ck] : null); ?>
                    <h4 style="margin:14px 0 6px">التصميم <?= aic_score($one['design_total'] !== null ? (float) $one['design_total'] : null) ?></h4>
                    <?php if (!$one['ds']): ?><div class="a2-muted" style="font-size:13px">التصميم ماتقيّمش</div><?php endif; ?>
                    <?php if ($one['ds']) foreach (AIC_DESIGN as $dk => $dl) echo aic_bar($dl, isset($one['ds'][$dk]) ? (float) $one['ds'][$dk] : null); ?>
                </div>
                <div>
                    <div style="background:#EFFAF5;border-radius:12px;padding:10px 12px;margin-bottom:8px"><b>✅ نقط القوة</b><div style="white-space:pre-wrap;line-height:1.8;font-size:13.5px"><?= e((string) $one['strengths']) ?></div></div>
                    <div style="background:#FDF1F0;border-radius:12px;padding:10px 12px;margin-bottom:8px"><b>⚠️ نقط الضعف</b><div style="white-space:pre-wrap;line-height:1.8;font-size:13.5px"><?= e((string) $one['weaknesses']) ?></div></div>
                    <div style="background:#EEF2FF;border-radius:12px;padding:10px 12px;margin-bottom:8px"><b>🛠 التعديل المقترح</b><div style="white-space:pre-wrap;line-height:1.8;font-size:13.5px"><?= e((string) $one['fix_suggestion']) ?></div></div>
                    <?php if ($one['designs']): ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0">
                            <?php foreach ($one['designs'] as $d): ?><a href="<?= e(url('storage/' . $d['image_path'])) ?>" target="_blank"><img src="<?= e(url('storage/' . $d['image_path'])) ?>" alt="" loading="lazy" style="width:92px;height:92px;object-fit:cover;border-radius:10px;border:1px solid #E6EDF5<?= (int) $one['design_id'] === (int) $d['id'] ? ';outline:3px solid #4f46e5' : '' ?>"></a><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <details><summary style="cursor:pointer;font-weight:600">نص المنشور</summary><div style="white-space:pre-wrap;line-height:1.9;font-size:13.5px;margin-top:6px"><?= e((string) $one['generated_text']) ?></div></details>
                    <?php if ($one['others']): ?>
                        <div class="a2-muted" style="font-size:12.5px;margin-top:8px">تقييمات تانية لنفس المنشور:
                            <?php foreach ($one['others'] as $o): ?><a href="<?= e($qs(['id' => (int) $o['id']])) ?>">#<?= (int) $o['id'] ?> (<?= number_format((float) $o['overall'], 1) ?>)</a> <?php endforeach; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <form class="card" method="get" style="margin-bottom:14px">
            <div class="a2-row" style="gap:8px;flex-wrap:wrap">
                <select class="input" name="p" style="width:auto"><?php foreach (['today' => 'النهارده', '7d' => '7 أيام', '30d' => '30 يوم', 'month' => 'الشهر ده', '90d' => '3 شهور'] as $pk => $pl): ?><option value="<?= $pk ?>" <?= $per['key'] === $pk ? 'selected' : '' ?>><?= $pl ?></option><?php endforeach; ?></select>
                <input class="input" type="number" step="0.5" min="0" max="10" name="min" value="<?= $f['min'] ?? '' ?>" placeholder="من درجة" style="width:110px">
                <input class="input" type="number" step="0.5" min="0" max="10" name="max" value="<?= $f['max'] ?? '' ?>" placeholder="لحد درجة" style="width:110px">
                <input class="input" type="number" min="1" name="version" value="<?= $f['version'] ?: '' ?>" placeholder="رقم إصدار البرومبت" style="width:160px">
                <input class="input" type="number" min="1" name="brand" value="<?= $f['brand'] ?: '' ?>" placeholder="رقم البراند" style="width:120px">
                <label style="display:inline-flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="low" value="1" <?= $f['low'] ? 'checked' : '' ?>> الضعيف بس (أقل من <?= aic_low() ?>)</label>
                <button class="btn sm" type="submit">عرض</button>
                <?php if (array_filter($f)): ?><a class="btn sm ghost" href="?p=<?= e($per['key']) ?>">مسح</a><?php endif; ?>
            </div>
        </form>

        <div class="a2-grid a2-g4" style="margin-bottom:14px">
            <div class="a2-kpi"><small>تقييمات · <?= e($per['label']) ?></small><b><?= a2n($total) ?></b></div>
            <div class="a2-kpi"><small>متوسط الإجمالي</small><b><?= isset($agg['o']) && $agg['o'] !== null ? number_format((float) $agg['o'], 1) : '—' ?></b><em>المحتوى <?= isset($agg['ct']) && $agg['ct'] !== null ? number_format((float) $agg['ct'], 1) : '—' ?> · التصميم <?= isset($agg['dt']) && $agg['dt'] !== null ? number_format((float) $agg['dt'], 1) : '—' ?></em></div>
            <div class="a2-kpi"><small>ضعيف (أقل من <?= aic_low() ?>)</small><b><?= $total ? round(((int) ($agg['lowN'] ?? 0)) / $total * 100) : 0 ?>%</b><em><a href="<?= e($qs(['low' => 1])) ?>"><?= a2n($agg['lowN'] ?? 0) ?> تقييم</a></em></div>
            <div class="a2-kpi"><small>أضعف المعايير</small><b style="font-size:15px;direction:rtl;text-align:start"><?= $weakest ? implode(' · ', array_map(fn($k, $v) => e($allLbl[$k]) . ' ' . number_format($v, 1), array_keys($weakest), $weakest)) : '—' ?></b></div>
        </div>

        <div class="split split-2" style="align-items:flex-start;margin-bottom:14px">
            <div class="card">
                <div class="a2-h"><h3>متوسط كل معيار</h3><span class="a2-muted">بالفلاتر الحالية</span></div>
                <div class="a2-muted" style="font-size:12px;margin-bottom:4px">المحتوى</div>
                <?php foreach (AIC_CONTENT as $ck => $cl) echo aic_bar($cl, $critAvg[$ck]); ?>
                <div class="a2-muted" style="font-size:12px;margin:10px 0 4px">التصميم</div>
                <?php foreach (AIC_DESIGN as $dk => $dl) echo aic_bar($dl, $critAvg[$dk]); ?>
            </div>
            <div class="card">
                <div class="a2-h"><h3>حسب إصدار البرومبت</h3></div>
                <?php if (!$byVer): ?><div class="a2-empty">مفيش بيانات</div><?php else: ?>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الإصدار</th><th>الحالة</th><th>تقييمات</th><th>المتوسط</th></tr></thead><tbody>
                    <?php foreach ($byVer as $v): ?>
                        <tr><td><?= $v['v'] ? '<a href="' . e($qs(['version' => (int) $v['v']])) . '">' . ($v['t'] !== null ? e((string) $v['t']) . ' v' . (int) $v['pv'] : 'إصدار اتمسح') . '</a> <span class="a2-muted">#' . (int) $v['v'] . '</span>' : '<span class="a2-muted">قبل نظام الإصدارات</span>' ?></td>
                            <td><?= $v['v'] && $v['t'] !== null ? ((int) $v['is_active'] === 1 ? '<span class="chip chip-mint">مفعّل</span>' : ($v['status'] === 'testing' ? '<span class="chip chip-amber">تجربة</span>' : '<span class="chip chip-line">' . e((string) ($v['status'] ?: 'قديم')) . '</span>')) : '' ?></td>
                            <td class="num"><?= a2n($v['n']) ?></td><td><?= aic_score((float) $v['o']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="padding:0">
            <?php if (!$rows): ?>
                <div class="a2-empty">مفيش تقييمات بالفلاتر دي. Claude بيقيّم المنشورات من برومبت <b>daily_review</b> أو <b>evaluate_post</b>.</div>
            <?php else: ?>
            <div class="a2-tw" style="border:0"><table class="a2-tbl"><thead><tr><th>#</th><th>الوقت</th><th>المنشور</th><th>البراند</th><th>الإصدار</th><th>المحتوى</th><th>التصميم</th><th>الإجمالي</th><th>العميل</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="num">#<?= (int) $r['id'] ?></td>
                    <td class="a2-muted" title="<?= e($r['created_at']) ?>"><?= e(date('m/d H:i', strtotime($r['created_at']))) ?></td>
                    <td style="max-width:320px"><a href="<?= e(url('admin/content-view.php?id=' . (int) $r['content_id'])) ?>">#<?= (int) $r['content_id'] ?></a> <span class="a2-muted"><?= e((string) $r['format']) ?></span>
                        <div style="font-size:12.5px;line-height:1.6"><?= e(mb_substr((string) $r['excerpt'], 0, 110)) ?></div>
                        <?php if ($r['weak']): ?><div class="a2-muted" style="font-size:12px">⚠️ <?= e((string) $r['weak']) ?></div><?php endif; ?></td>
                    <td><?= $r['brand_profile_id'] ? '<a href="' . e($qs(['brand' => (int) $r['brand_profile_id']])) . '">' . e($r['business_name'] ?? ('#' . $r['brand_profile_id'])) . '</a>' : '—' ?></td>
                    <td><?= $r['prompt_version_id'] ? '<a href="' . e($qs(['version' => (int) $r['prompt_version_id']])) . '">#' . (int) $r['prompt_version_id'] . '</a>' : '—' ?></td>
                    <td><?= aic_score($r['content_total'] !== null ? (float) $r['content_total'] : null) ?></td>
                    <td><?= aic_score($r['design_total'] !== null ? (float) $r['design_total'] : null) ?></td>
                    <td><?= aic_score($r['overall'] !== null ? (float) $r['overall'] : null) ?></td>
                    <td><?= $r['feedback'] === null ? '—' : ((int) $r['feedback'] === 1 ? '👍' : '👎') ?></td>
                    <td><a class="btn sm ghost" href="<?= e($qs(['id' => (int) $r['id'], 'page' => $page > 1 ? $page : ''])) ?>">التفاصيل</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php if ($total > 40): $pages = (int) ceil($total / 40); ?>
            <div class="a2-row" style="margin-top:12px;justify-content:center">
                <?php if ($page > 1): ?><a class="btn sm ghost" href="<?= e($qs(['page' => $page - 1])) ?>">السابق</a><?php endif; ?>
                <span class="a2-muted">صفحة <?= $page ?> من <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn sm ghost" href="<?= e($qs(['page' => $page + 1])) ?>">التالي</a><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
