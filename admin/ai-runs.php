<?php
/**
 * سجل طلبات الـ AI (المرحلة 8-أ) — كل عملية ومحاولاتها: المزود · الموديل · التوكنز · التكلفة · التحويل للبديل · الخطأ
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';

require_admin();
require_admin_can('view_costs');
$ready = usage_ready();

$per = a2_period((string) ($_GET['p'] ?? '7d'), $_GET['from'] ?? null, $_GET['to'] ?? null);
$f = [
    'status' => in_array($_GET['status'] ?? '', ['ok', 'failed', 'refused'], true) ? $_GET['status'] : '',
    'task' => preg_match('/^[a-z_]{2,40}$/', (string) ($_GET['task'] ?? '')) ? (string) $_GET['task'] : '',
    'provider' => preg_match('/^[a-z0-9_-]{2,40}$/', (string) ($_GET['provider'] ?? '')) ? (string) $_GET['provider'] : '',
    'user' => (int) ($_GET['user'] ?? 0),
    'fo' => !empty($_GET['fo']),
    'ref_type' => preg_match('/^[a-z_]{2,40}$/', (string) ($_GET['ref_type'] ?? '')) ? (string) $_GET['ref_type'] : '',
    'ref_id' => (int) ($_GET['ref_id'] ?? 0),
];
$where = ['r.created_at >= ?', 'r.created_at < ?'];
$params = [$per['from'], $per['to']];
if ($f['ref_type'] && $f['ref_id']) { $where = ['r.ref_type = ?', 'r.ref_id = ?']; $params = [$f['ref_type'], $f['ref_id']]; }
if ($f['status']) { $where[] = 'r.status = ?'; $params[] = $f['status']; }
if ($f['task']) { $where[] = 'r.task = ?'; $params[] = $f['task']; }
if ($f['provider']) { $where[] = 'EXISTS (SELECT 1 FROM ai_attempts a WHERE a.run_id = r.id AND a.provider = ?)'; $params[] = $f['provider']; }
if ($f['user']) { $where[] = 'r.user_id = ?'; $params[] = $f['user']; }
if ($f['fo']) { $where[] = 'r.failovers > 0'; }
$W = implode(' AND ', $where);
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = $ready ? (int) a2_scalar("SELECT COUNT(*) FROM ai_runs r WHERE $W", $params) : 0;
$rows = $ready ? a2_all("SELECT r.*, u.name user_name FROM ai_runs r LEFT JOIN users u ON u.id = r.user_id WHERE $W ORDER BY r.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $params) : [];
$atts = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int) $r['id'], $rows));
    foreach (a2_all("SELECT * FROM ai_attempts WHERE run_id IN ($ids) ORDER BY run_id, seq") as $a) $atts[$a['run_id']][] = $a;
}
$agg = $ready ? db_one("SELECT COALESCE(SUM(r.cost_egp),0) ce, COALESCE(SUM(r.credits_charged),0) cr, SUM(r.status = 'ok') ok, SUM(r.failovers > 0) fo FROM ai_runs r WHERE $W", $params) : [];
$tasks = a2_all('SELECT task, label FROM ai_task_routes ORDER BY task');
$taskLbl = array_column($tasks, 'label', 'task');
$provs = a2_all('SELECT provider_name FROM ai_providers ORDER BY priority');
$clsLbl = ['network' => 'شبكة', 'timeout' => 'انتهت المهلة', 'rate_limit' => 'ضغط (429)', 'auth' => 'مفتاح', 'quota' => 'رصيد', 'server' => 'عطل المزود', 'bad_json' => 'JSON غلط',
           'policy' => 'سياسة المحتوى', 'bad_request' => 'صيغة الطلب', 'unsupported' => 'باراميتر مش مدعوم', 'not_found' => 'موديل مش موجود', 'empty' => 'رد فاضي', 'no_provider' => 'مفيش مزود'];
$stLbl = ['ok' => ['chip-mint', 'نجح'], 'failed' => ['chip-coral', 'فشل'], 'refused' => ['chip-amber', 'مرفوض'], 'running' => ['chip-line', 'شغال']];
$qs = function (array $over = []) use ($f, $per) {
    $q = array_filter(array_merge(['p' => $per['key'], 'status' => $f['status'], 'task' => $f['task'], 'provider' => $f['provider'], 'user' => $f['user'] ?: '', 'fo' => $f['fo'] ? 1 : '',
        'ref_type' => $f['ref_type'], 'ref_id' => $f['ref_id'] ?: ''], $over), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};

$active = 'ai-runs';
$page_title = 'سجل طلبات الـ AI';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>سجل طلبات الـ AI</h1>
                <div class="sub">AI Request Logs · كل عملية ومحاولاتها عند المزودين بتكلفتها</div>
            </div>
            <div class="a2-seg">
                <a class="on" href="<?= url('admin/ai-runs.php') ?>">العمليات والتكلفة</a>
                <a href="<?= url('admin/ai-logs.php') ?>">البرومبتات الكاملة</a>
            </div>
        </div>

        <?php if (!$ready): ?>
            <div class="alert warning">لازم تشغّل ترحيل «8-أ» الأول من <a href="<?= url('admin/migrations.php') ?>">الترحيلات</a>.</div>
        <?php else: ?>
        <form class="card" method="get" style="margin-bottom:14px">
            <div class="a2-row" style="gap:8px">
                <?php if ($f['ref_type']): ?><input type="hidden" name="ref_type" value="<?= e($f['ref_type']) ?>"><input type="hidden" name="ref_id" value="<?= (int) $f['ref_id'] ?>"><span class="chip chip-primary"><?= e($f['ref_type']) ?> #<?= (int) $f['ref_id'] ?></span><?php endif; ?>
                <select class="input" name="p" style="width:auto"><?php foreach (['today' => 'النهارده', '7d' => '7 أيام', '30d' => '30 يوم', 'month' => 'الشهر ده', '90d' => '3 شهور'] as $k => $l): ?><option value="<?= $k ?>" <?= $per['key'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
                <select class="input" name="status" style="width:auto"><option value="">كل الحالات</option><?php foreach (['ok' => 'نجح', 'failed' => 'فشل', 'refused' => 'مرفوض'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
                <select class="input" name="task" style="width:auto"><option value="">كل المهام</option><?php foreach ($tasks as $t): ?><option value="<?= e($t['task']) ?>" <?= $f['task'] === $t['task'] ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?></select>
                <select class="input" name="provider" style="width:auto"><option value="">كل المزودين</option><?php foreach ($provs as $p): ?><option value="<?= e($p['provider_name']) ?>" <?= $f['provider'] === $p['provider_name'] ? 'selected' : '' ?>><?= e($p['provider_name']) ?></option><?php endforeach; ?></select>
                <input class="input" type="number" name="user" min="1" value="<?= $f['user'] ?: '' ?>" placeholder="رقم العميل" style="width:120px">
                <label style="display:inline-flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="fo" value="1" <?= $f['fo'] ? 'checked' : '' ?>> اتحوّل للبديل</label>
                <button class="btn sm" type="submit">عرض</button>
                <?php if (array_filter($f)): ?><a class="btn sm ghost" href="<?= url('admin/ai-runs.php') ?>">مسح</a><?php endif; ?>
            </div>
        </form>

        <div class="a2-grid a2-g4" style="margin-bottom:14px">
            <div class="a2-kpi"><small>عمليات</small><b><?= a2n($total) ?></b></div>
            <div class="a2-kpi"><small>نجاح</small><b><?= $total ? round(($agg['ok'] ?? 0) / $total * 100) : 0 ?>%</b><em>بديل <?= a2n($agg['fo'] ?? 0) ?></em></div>
            <div class="a2-kpi"><small>تكلفة المنصة</small><b><?= a2egp($agg['ce'] ?? 0) ?></b></div>
            <div class="a2-kpi"><small>كريدت مخصوم</small><b><?= a2n($agg['cr'] ?? 0) ?></b></div>
        </div>

        <div class="card" style="padding:0">
            <?php if (!$rows): ?><div class="a2-empty">مفيش عمليات بالفلاتر دي</div><?php else: ?>
            <div class="a2-tw" style="border:0"><table class="a2-tbl"><thead><tr><th>#</th><th>الوقت</th><th>العميل</th><th>المهمة</th><th>المزود · الموديل</th><th>محاولات</th><th>توكنز</th><th>التكلفة</th><th>كريدت</th><th>الحالة</th></tr></thead><tbody>
            <?php foreach ($rows as $r): $st = $stLbl[$r['status']] ?? ['chip-line', $r['status']]; $as = $atts[$r['id']] ?? []; ?>
                <tr>
                    <td class="num">#<?= (int) $r['id'] ?></td>
                    <td class="a2-muted" title="<?= e($r['created_at']) ?>"><?= e(date('m/d H:i', strtotime($r['created_at']))) ?></td>
                    <td><?= $r['user_id'] ? '<a href="' . e(url('admin/user-view.php?id=' . (int) $r['user_id'])) . '">' . e($r['user_name'] ?? ('#' . $r['user_id'])) . '</a>' : '<span class="a2-muted">أدمن/نظام</span>' ?></td>
                    <td><b><?= e($taskLbl[$r['task']] ?? $r['task']) ?></b><br><span class="a2-muted"><?= e((string) $r['feature']) ?> · <?= e($r['route']) ?><?= $r['ref_id'] ? ' · <a href="' . e($qs(['ref_type' => $r['ref_type'], 'ref_id' => $r['ref_id']])) . '">' . e($r['ref_type']) . ' #' . (int) $r['ref_id'] . '</a>' : '' ?></span></td>
                    <td><b><?= e((string) $r['provider']) ?></b><br><span class="a2-muted" dir="ltr"><?= e((string) $r['model']) ?></span></td>
                    <td>
                        <details><summary style="cursor:pointer"><?= (int) $r['attempts'] ?><?= (int) $r['failovers'] ? ' <span class="chip chip-amber">بديل</span>' : '' ?></summary>
                            <div style="min-width:320px;margin-top:6px">
                            <?php foreach ($as as $a): ?>
                                <div class="a2-kv" style="padding:5px 0;font-size:12.5px">
                                    <span><?= (int) $a['seq'] ?>. <b><?= e((string) $a['provider']) ?></b> <span dir="ltr"><?= e((string) $a['model']) ?></span>
                                    <?= $a['status'] === 'ok' ? '✓' : '✗ ' . e($clsLbl[$a['error_class']] ?? (string) $a['error_class']) . ($a['http_code'] ? ' ' . (int) $a['http_code'] : '') ?>
                                    <?= $a['error'] ? '<br><span class="a2-muted">' . e(mb_substr((string) $a['error'], 0, 140)) . '</span>' : '' ?></span>
                                    <small class="a2-num"><?= a2n($a['duration_ms'] / 1000, 1) ?>s · <?= a2usd($a['cost_usd'], 5) ?></small>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </details>
                    </td>
                    <td class="num"><?= a2n($r['tokens_in']) ?> / <?= a2n($r['tokens_out']) ?><?= $r['images_count'] ? '<br>' . (int) $r['images_count'] . ' 🖼' : '' ?></td>
                    <td class="num"><?= a2egp($r['cost_egp'], 3) ?><br><span class="a2-muted"><?= a2usd($r['cost_usd'], 5) ?> · <?= e(['provider' => 'فعلية', 'calculated' => 'محسوبة', 'estimated' => 'تقديرية', 'mixed' => 'مختلطة', 'none' => '—'][$r['cost_source']] ?? $r['cost_source']) ?></span></td>
                    <td class="num"><?= (int) $r['credits_charged'] ?: '—' ?></td>
                    <td><span class="chip <?= $st[0] ?>"><?= e($st[1]) ?></span><?= $r['error_class'] && $r['status'] !== 'ok' ? '<br><span class="a2-muted">' . e($clsLbl[$r['error_class']] ?? $r['error_class']) . '</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php if ($total > 50): $pages = (int) ceil($total / 50); ?>
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
