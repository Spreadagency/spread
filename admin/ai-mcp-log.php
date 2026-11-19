<?php
/**
 * Spread AI — مركز الجودة: سجل الـ MCP (المرحلة 5)
 * كل طلب وصل للـ Internal API من خادم الـ MCP (أو الاختبار): العميل · الأداة · الصلاحية · النتيجة · الوقت · المدخلات (من غير بيانات شخصية)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ai-center.php';

require_admin();
require_admin_can('ai_settings');

$ready = aic_has('mcp_audit_log');
$L = mcp_t('mcp_audit_log');
$per = a2_period((string) ($_GET['p'] ?? '7d'), $_GET['from'] ?? null, $_GET['to'] ?? null);
$f = [
    'client' => preg_match('/^[A-Za-z0-9:_\-.]{1,40}$/', (string) ($_GET['client'] ?? '')) ? (string) $_GET['client'] : '',
    'tool' => preg_match('/^[a-z_]{2,80}$/', (string) ($_GET['tool'] ?? '')) ? (string) $_GET['tool'] : '',
    'scope' => in_array($_GET['scope'] ?? '', ['read', 'ai_write', 'draft'], true) ? (string) $_GET['scope'] : '',
    'res' => in_array($_GET['res'] ?? '', ['ok', 'err'], true) ? (string) $_GET['res'] : '',
];
$where = ['created_at >= ?', 'created_at < ?'];
$params = [$per['from'], $per['to']];
if ($f['client']) { $where[] = 'client = ?'; $params[] = $f['client']; }
if ($f['tool']) { $where[] = 'tool = ?'; $params[] = $f['tool']; }
if ($f['scope']) { $where[] = 'scope = ?'; $params[] = $f['scope']; }
if ($f['res'] === 'ok') $where[] = 'result_status = "ok"';
if ($f['res'] === 'err') $where[] = 'result_status <> "ok"';
$W = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$total = $ready ? (int) a2_scalar("SELECT COUNT(*) FROM $L WHERE $W", $params) : 0;
$agg = $ready ? (db_one("SELECT SUM(result_status <> 'ok') err, COUNT(DISTINCT client) clients, AVG(duration_ms) ms FROM $L WHERE $W", $params) ?: []) : [];
$rows = $ready ? a2_all("SELECT * FROM $L WHERE $W ORDER BY id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $params) : [];
$byTool = $ready ? a2_all("SELECT tool, COUNT(*) n, SUM(result_status <> 'ok') err, AVG(duration_ms) ms, MAX(created_at) last FROM $L WHERE $W GROUP BY tool ORDER BY n DESC LIMIT 30", $params) : [];
$clients = $ready ? array_column(a2_all("SELECT DISTINCT client FROM $L WHERE created_at >= ? ORDER BY client LIMIT 50", [date('Y-m-d', strtotime('-90 days'))]), 'client') : [];
$errTypes = $ready ? a2_all("SELECT result_status s, COUNT(*) n FROM $L WHERE $W AND result_status <> 'ok' GROUP BY result_status ORDER BY n DESC LIMIT 8", $params) : [];
$errLbl = ['auth' => 'توكن غلط', 'scope_mismatch' => 'صلاحية مش مطابقة', 'not_found' => 'مش موجود', 'invalid' => 'مدخلات غلط', 'already_rejected' => 'اقتراح مرفوض قبل كده',
           'db_denied' => 'MySQL رفض', 'rate_limit' => 'طلبات كتير', 'tool_disabled' => 'الأداة مقفولة', 'not_implemented' => 'لسه ماتعملتش', 'server_error' => 'خطأ في السيرفر', 'unknown_route' => 'مسار مش موجود'];
$scopeLbl = ['read' => 'قراءة', 'ai_write' => 'تقييم', 'draft' => 'مسودة'];
$qs = function (array $over = []) use ($f, $per) {
    $q = array_filter(array_merge(['p' => $per['key'], 'client' => $f['client'], 'tool' => $f['tool'], 'scope' => $f['scope'], 'res' => $f['res']], $over), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};

$active = 'ai-mcp-log';
$page_title = 'سجل الـ MCP';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>مركز الجودة: سجل الـ MCP</h1>
                <div class="sub">كل طلب من Claude (عن طريق خادم الـ MCP) اتسجّل هنا — الإيميلات والأرقام متخفية والأسرار متشالة</div>
            </div>
            <?= aic_tabs('ai-mcp-log') ?>
        </div>

        <?php if (!$ready): ?>
            <?= aic_setup_notice() ?>
        <?php else: ?>
        <form class="card" method="get" style="margin-bottom:14px">
            <div class="a2-row" style="gap:8px;flex-wrap:wrap">
                <select class="input" name="p" style="width:auto"><?php foreach (['today' => 'النهارده', '7d' => '7 أيام', '30d' => '30 يوم', 'month' => 'الشهر ده', '90d' => '3 شهور'] as $pk => $pl): ?><option value="<?= $pk ?>" <?= $per['key'] === $pk ? 'selected' : '' ?>><?= $pl ?></option><?php endforeach; ?></select>
                <select class="input" name="client" style="width:auto"><option value="">كل العملاء</option><?php foreach ($clients as $c): ?><option value="<?= e((string) $c) ?>" <?= $f['client'] === $c ? 'selected' : '' ?>><?= e((string) $c) ?></option><?php endforeach; ?></select>
                <select class="input" name="scope" style="width:auto"><option value="">كل الصلاحيات</option><?php foreach ($scopeLbl as $sk => $sl): ?><option value="<?= $sk ?>" <?= $f['scope'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></select>
                <select class="input" name="res" style="width:auto"><option value="">كل النتايج</option><option value="ok" <?= $f['res'] === 'ok' ? 'selected' : '' ?>>نجح</option><option value="err" <?= $f['res'] === 'err' ? 'selected' : '' ?>>فشل</option></select>
                <?php if ($f['tool']): ?><input type="hidden" name="tool" value="<?= e($f['tool']) ?>"><span class="chip chip-primary" dir="ltr"><?= e($f['tool']) ?></span><?php endif; ?>
                <button class="btn sm" type="submit">عرض</button>
                <?php if (array_filter($f)): ?><a class="btn sm ghost" href="?p=<?= e($per['key']) ?>">مسح</a><?php endif; ?>
            </div>
        </form>

        <div class="a2-grid a2-g4" style="margin-bottom:14px">
            <div class="a2-kpi"><small>طلبات · <?= e($per['label']) ?></small><b><?= a2n($total) ?></b></div>
            <div class="a2-kpi"><small>فشلت</small><b><?= a2n($agg['err'] ?? 0) ?></b><em><?= $total ? round(((int) ($agg['err'] ?? 0)) / $total * 100, 1) : 0 ?>%</em></div>
            <div class="a2-kpi"><small>عملاء مختلفين</small><b><?= a2n($agg['clients'] ?? 0) ?></b></div>
            <div class="a2-kpi"><small>متوسط الوقت</small><b><?= isset($agg['ms']) && $agg['ms'] !== null ? a2n($agg['ms']) . ' ms' : '—' ?></b></div>
        </div>

        <div class="split split-2" style="align-items:flex-start;margin-bottom:14px">
            <div class="card">
                <div class="a2-h"><h3>حسب الأداة</h3></div>
                <?php if (!$byTool): ?><div class="a2-empty">مفيش طلبات في الفترة دي</div><?php else: ?>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الأداة</th><th>طلبات</th><th>فشل</th><th>ms</th><th>آخر طلب</th></tr></thead><tbody>
                    <?php foreach ($byTool as $t): ?>
                        <tr><td><a dir="ltr" href="<?= e($qs(['tool' => $t['tool']])) ?>"><?= e($t['tool']) ?></a></td><td class="num"><?= a2n($t['n']) ?></td>
                            <td class="num"><?= (int) $t['err'] ? '<a href="' . e($qs(['tool' => $t['tool'], 'res' => 'err'])) . '" style="color:#d9534f">' . (int) $t['err'] . '</a>' : '0' ?></td>
                            <td class="num"><?= a2n($t['ms']) ?></td><td class="a2-muted"><?= e(date('m/d H:i', strtotime($t['last']))) ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="a2-h"><h3>أسباب الفشل</h3></div>
                <?php if (!$errTypes): ?><div class="a2-empty">مفيش أخطاء ✓</div><?php endif; ?>
                <?php foreach ($errTypes as $et): ?>
                    <div class="a2-kv"><span><?= e($errLbl[$et['s']] ?? $et['s']) ?> <span class="a2-muted" dir="ltr">(<?= e($et['s']) ?>)</span></span><b><?= a2n($et['n']) ?></b></div>
                <?php endforeach; ?>
                <p class="a2-muted" style="font-size:12.5px;line-height:1.8;margin-top:10px">«MySQL رفض» معناه إن حد حاول عملية ممنوعة على الصلاحية دي وقاعدة البيانات نفسها رفضتها — ده المتوقع من اختبار الصلاحيات، ولو ظهر من Claude يبقى اتمنع صح.</p>
            </div>
        </div>

        <div class="card" style="padding:0">
            <?php if (!$rows): ?><div class="a2-empty">مفيش طلبات بالفلاتر دي</div><?php else: ?>
            <div class="a2-tw" style="border:0"><table class="a2-tbl"><thead><tr><th>#</th><th>الوقت</th><th>العميل</th><th>الأداة</th><th>الصلاحية</th><th>النتيجة</th><th>ms</th><th>IP</th><th>المدخلات</th></tr></thead><tbody>
            <?php foreach ($rows as $r): $prm = json_decode((string) $r['params'], true); ?>
                <tr>
                    <td class="num">#<?= (int) $r['id'] ?></td>
                    <td class="a2-muted" title="<?= e($r['created_at']) ?>"><?= e(date('m/d H:i:s', strtotime($r['created_at']))) ?></td>
                    <td><a dir="ltr" href="<?= e($qs(['client' => (string) $r['client']])) ?>"><?= e((string) $r['client']) ?></a></td>
                    <td><a dir="ltr" href="<?= e($qs(['tool' => $r['tool']])) ?>"><?= e($r['tool']) ?></a></td>
                    <td><?= e($scopeLbl[$r['scope']] ?? $r['scope']) ?></td>
                    <td><span class="chip <?= $r['result_status'] === 'ok' ? 'chip-mint' : ($r['result_status'] === 'db_denied' ? 'chip-coral' : 'chip-amber') ?>" title="<?= e($r['result_status']) ?>"><?= e($r['result_status'] === 'ok' ? 'نجح' : ($errLbl[$r['result_status']] ?? $r['result_status'])) ?></span></td>
                    <td class="num"><?= $r['duration_ms'] !== null ? (int) $r['duration_ms'] : '—' ?></td>
                    <td class="a2-muted" dir="ltr"><?= e((string) $r['ip']) ?></td>
                    <td><?php if (is_array($prm) && $prm): ?><details><summary style="cursor:pointer;font-size:12.5px"><?= count($prm) ?> حقل</summary>
                        <pre dir="ltr" style="white-space:pre-wrap;background:#F6F8FB;border-radius:8px;padding:6px;font-size:11.5px;max-width:420px;max-height:240px;overflow:auto"><?= e(json_encode($prm, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php else: ?><span class="a2-muted">—</span><?php endif; ?></td>
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
