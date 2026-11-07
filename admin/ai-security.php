<?php
/**
 * Spread AI — مركز الجودة: الأمان وسلامة البيانات (MCP المرحلة 8)
 * فحص الإعدادات (نقطة نقطة بالحل) · ترابط البيانات · ملاحظات الأمان (Claude · ZAP · المكتبات · الإعدادات) · السجلات · اختبارات E2E
 * كل حاجة هنا قراءة — الإصلاح بإيدك (الحل مكتوب جنب كل مشكلة)، والأدمن يقدر يعلّم ملاحظة «اتصلّحت/مقبولة/غلط».
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ai-center.php';
require_once __DIR__ . '/../includes/security-center.php';

require_admin();
require_admin_can('ai_settings');
$ready = sec_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'run') {
        $r = sec_run();
        admin_log('security_run', 'security', null, json_encode($r));
        flash_set($r['critical'] ? 'warning' : 'success', 'الفحص خلص: ' . $r['config_failed'] . ' من ' . $r['config_total'] . ' إعداد محتاج تعديل · ' . $r['integrity_open'] . ' مشكلة بيانات');
    } elseif ($a === 'finding' && $ready) {
        $st = in_array($_POST['status'] ?? '', ['open', 'in_progress', 'fixed', 'accepted_risk', 'false_positive'], true) ? $_POST['status'] : 'open';
        db_run('UPDATE ' . mcp_t('security_findings') . ' SET status = ?, fixed_at = IF(? = "fixed", NOW(), fixed_at) WHERE id = ?', [$st, $st, (int) ($_POST['id'] ?? 0)]);
        admin_log('security_finding', 'security', (int) ($_POST['id'] ?? 0), $st);
        flash_set('success', 'اتحفظ ✓');
    } elseif ($a === 'integrity' && $ready) {
        $st = ($_POST['status'] ?? '') === 'ignored' ? 'ignored' : 'open';
        db_run('UPDATE ' . mcp_t('integrity_issues') . ' SET status = ? WHERE id = ?', [$st, (int) ($_POST['id'] ?? 0)]);
        admin_log('integrity_status', 'security', (int) ($_POST['id'] ?? 0), $st);
        flash_set('success', 'اتحفظ ✓');
    } elseif ($a === 'auto') {
        set_setting('security_auto', !empty($_POST['security_auto']) ? '1' : '0');
        flash_set('success', 'اتحفظ ✓');
    }
    redirect('admin/ai-security.php' . (isset($_POST['tab']) ? '?tab=' . urlencode((string) $_POST['tab']) : ''));
}

$tab = in_array($_GET['tab'] ?? '', ['overview', 'findings', 'integrity', 'logs', 'e2e'], true) ? $_GET['tab'] : 'overview';
$cfg = sec_config_checks();
$passed = count(array_filter($cfg, fn($c) => $c['ok']));
$sevChip = ['critical' => 'chip-coral', 'high' => 'chip-coral', 'medium' => 'chip-amber', 'low' => 'chip-line', 'info' => 'chip-line'];
$sevLbl = ['critical' => 'حرجة', 'high' => 'عالية', 'medium' => 'متوسطة', 'low' => 'بسيطة', 'info' => 'معلومة'];
$srcLbl = ['config' => 'الإعدادات', 'code_review' => 'مراجعة Claude', 'zap' => 'فحص ZAP', 'dependency' => 'المكتبات', 'logs' => 'السجلات'];
$stLbl = ['open' => 'مفتوحة', 'in_progress' => 'شغالين عليها', 'fixed' => 'اتصلّحت', 'accepted_risk' => 'مقبولة', 'false_positive' => 'مش مشكلة'];
$findings = $ready ? db_all('SELECT * FROM ' . mcp_t('security_findings') . ' ORDER BY status IN ("open","in_progress") DESC, FIELD(severity, "critical","high","medium","low","info"), last_seen DESC LIMIT 200') : [];
$issues = $ready ? db_all('SELECT * FROM ' . mcp_t('integrity_issues') . ' ORDER BY status = "open" DESC, count DESC, check_key') : [];
$openF = count(array_filter($findings, fn($f) => in_array($f['status'], ['open', 'in_progress'], true) && $f['source'] !== 'config'));
$openI = count(array_filter($issues, fn($i) => $i['status'] === 'open'));
$e2e = sec_e2e_latest();
$e2eFail = count(array_filter($e2e, fn($x) => $x['status'] === 'failed'));

$active = 'ai-security';
$page_title = 'الأمان وسلامة البيانات';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div><h1>الأمان وسلامة البيانات</h1><div class="sub">فحص يومي تلقائي (آخر فحص: <?= e((string) get_setting('security_last_run', '') ?: 'لسه') ?>) — Claude بيقرا النتايج في المراجعة اليومية</div></div>
            <?= aic_tabs('ai-security') ?>
        </div>
        <?php if (!$ready): ?><?= aic_setup_notice() ?><?php endif; ?>

        <div class="a2-grid a2-g4" style="margin-bottom:14px">
            <div class="a2-kpi"><small>الإعدادات</small><b style="color:<?= $passed === count($cfg) ? '#0B8F83' : '#C2362E' ?>"><?= $passed ?> / <?= count($cfg) ?></b><em>نقطة سليمة</em></div>
            <div class="a2-kpi"><small>ملاحظات أمان مفتوحة</small><b><?= $openF ?></b><em>Claude · ZAP · المكتبات</em></div>
            <div class="a2-kpi"><small>مشاكل في البيانات</small><b style="color:<?= $openI ? '#C2362E' : '#0B8F83' ?>"><?= $openI ?></b><em>من <?= count($issues) ?> فحص</em></div>
            <div class="a2-kpi"><small>اختبارات الموقع (E2E)</small><b style="color:<?= $e2eFail ? '#C2362E' : '#0B8F83' ?>"><?= $e2e ? (count($e2e) - $e2eFail) . ' / ' . count($e2e) : '—' ?></b><em><?= $e2e ? 'آخر تشغيل ' . e(substr((string) max(array_column($e2e, 'run_at')), 0, 16)) : 'لسه ماتشغّلتش' ?></em></div>
        </div>

        <nav class="a2-tabs" aria-label="أقسام الأمان">
            <?php foreach (['overview' => 'فحص الإعدادات', 'integrity' => 'سلامة البيانات', 'findings' => 'ملاحظات الأمان', 'logs' => 'السجلات', 'e2e' => 'اختبارات الموقع'] as $k => $l): ?>
                <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($l) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($tab === 'overview'): ?>
            <div class="card">
                <div class="a2-h"><h3>فحص الإعدادات</h3>
                    <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="run"><button class="btn sm">▶ افحص دلوقتي</button></form></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th></th><th>النقطة</th><th>الوضع</th><th>الخطورة</th><th>الحل</th></tr></thead><tbody>
                <?php usort($cfg, fn($x, $y) => $x['ok'] <=> $y['ok'] ?: array_search($x['severity'], ['critical', 'high', 'medium', 'low']) <=> array_search($y['severity'], ['critical', 'high', 'medium', 'low']));
                foreach ($cfg as $c): ?>
                    <tr><td style="font-size:18px"><?= $c['ok'] ? '✅' : '⚠️' ?></td><td><b><?= e($c['title']) ?></b></td>
                        <td style="font-size:12.5px"><?= e($c['detail']) ?></td>
                        <td><?= $c['ok'] ? '' : '<span class="chip ' . $sevChip[$c['severity']] . '">' . e($sevLbl[$c['severity']]) . '</span>' ?></td>
                        <td style="font-size:12.5px;line-height:1.7"><?= $c['ok'] ? '<span class="a2-muted">—</span>' : e($c['fix']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <form method="POST" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="auto">
                    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="security_auto" value="1" <?= get_setting('security_auto', '1') === '1' ? 'checked' : '' ?> onchange="this.form.submit()"> فحص تلقائي كل يوم (قبل التقرير اليومي بساعة) + تنبيه لو فيه حاجة حرجة</label></form>
                <p class="a2-muted" style="margin:8px 0 0">قايمة الفحص الكاملة ودليل «تعمل إيه لما التقرير يقول كذا» في <code>docs/SECURITY.md</code>.</p>
            </div>

        <?php elseif ($tab === 'integrity'): ?>
            <div class="card">
                <div class="a2-h"><h3>سلامة البيانات</h3><form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="run"><input type="hidden" name="tab" value="integrity"><button class="btn sm">▶ افحص دلوقتي</button></form></div>
                <?php if (!$issues): ?><div class="a2-empty">لسه ماتعملش فحص — دوس «افحص دلوقتي»</div><?php endif; ?>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الفحص</th><th>العدد</th><th>الحل</th><th>الحالة</th><th></th></tr></thead><tbody>
                <?php foreach ($issues as $i): $ids = json_decode((string) $i['entity_ids'], true) ?: []; ?>
                    <tr<?= $i['status'] !== 'open' ? ' style="opacity:.6"' : '' ?>>
                        <td><b><?= e((string) $i['description']) ?></b><div class="a2-muted" dir="ltr" style="font-size:11px"><?= e($i['check_key']) ?> · <?= e($i['entity_type']) ?></div>
                            <?php if ($ids): ?><div class="a2-muted" style="font-size:11.5px">أرقام: <?= e(implode('، ', array_slice($ids, 0, 15))) ?><?= count($ids) > 15 ? '…' : '' ?></div><?php endif; ?></td>
                        <td class="num"><b><?= (int) $i['count'] ?></b></td>
                        <td style="font-size:12.5px;line-height:1.7"><?= e((string) $i['suggested_fix']) ?></td>
                        <td><span class="chip <?= $i['status'] === 'open' ? 'chip-coral' : ($i['status'] === 'fixed' ? 'chip-mint' : 'chip-line') ?>"><?= ['open' => 'مفتوحة', 'fixed' => 'سليمة', 'ignored' => 'متجاهلة'][$i['status']] ?></span></td>
                        <td><?php if ($i['status'] !== 'fixed'): ?><form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="integrity"><input type="hidden" name="tab" value="integrity"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                            <button class="btn ghost sm" name="status" value="<?= $i['status'] === 'ignored' ? 'open' : 'ignored' ?>"><?= $i['status'] === 'ignored' ? 'رجّعها' : 'تجاهل' ?></button></form><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>

        <?php elseif ($tab === 'findings'): ?>
            <div class="card">
                <div class="a2-h"><h3>ملاحظات الأمان</h3><span class="a2-muted">من Claude (مراجعة الكود والسجلات) · فحص ZAP · فحص المكتبات · الإعدادات</span></div>
                <?php if (!$findings): ?><div class="a2-empty">مفيش ملاحظات</div><?php endif; ?>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الملاحظة</th><th>المصدر</th><th>الخطورة</th><th>آخر مرة</th><th>الحالة</th></tr></thead><tbody>
                <?php foreach ($findings as $f): ?>
                    <tr<?= in_array($f['status'], ['fixed', 'accepted_risk', 'false_positive'], true) ? ' style="opacity:.6"' : '' ?>>
                        <td><b><?= e((string) $f['title']) ?></b><div class="a2-muted" dir="ltr" style="font-size:11px"><?= e(mb_substr((string) $f['location'], 0, 120)) ?></div>
                            <?php if ($f['description'] || $f['recommendation']): ?><details><summary style="cursor:pointer;font-size:12px">التفاصيل والحل</summary>
                                <div style="font-size:12.5px;line-height:1.8"><?= nl2br(e((string) $f['description'])) ?><?= $f['recommendation'] ? '<br><b>الحل:</b> ' . nl2br(e((string) $f['recommendation'])) : '' ?></div></details><?php endif; ?></td>
                        <td><?= e($srcLbl[$f['source']] ?? $f['source']) ?></td>
                        <td><span class="chip <?= $sevChip[$f['severity']] ?? 'chip-line' ?>"><?= e($sevLbl[$f['severity']] ?? $f['severity']) ?></span></td>
                        <td class="a2-muted" style="font-size:12px"><?= e(substr((string) $f['last_seen'], 0, 16)) ?></td>
                        <td><form method="POST" style="display:flex;gap:4px"><?= csrf_field() ?><input type="hidden" name="action" value="finding"><input type="hidden" name="tab" value="findings"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                            <select name="status" class="input" style="padding:4px 6px;font-size:12px" onchange="this.form.submit()"><?php foreach ($stLbl as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></form></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <p class="a2-muted" style="margin:8px 0 0">نتايج ZAP وفحص المكتبات بتدخل هنا بـ <code>php tests/security/import-findings.php</code> (الخطوات في <code>docs/STAGING.md</code>).</p>
            </div>

        <?php elseif ($tab === 'logs'): $lg = sec_logs(24); ?>
            <div class="a2-grid a2-g3">
                <div class="card"><div class="a2-h"><h3>محاولات دخول (24 ساعة)</h3></div>
                    <?php if (!$lg['failed_logins']): ?><div class="a2-empty">مفيش</div><?php endif; ?>
                    <?php foreach ($lg['failed_logins'] as $r): ?><div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0"><span dir="ltr"><?= e(mb_substr((string) $r['rate_key'], 0, 40)) ?></span><span class="a2-muted"><?= e($r['action']) ?> · <b><?= (int) $r['n'] ?></b></span></div><?php endforeach; ?></div>
                <div class="card"><div class="a2-h"><h3>نشاط حدود الطلبات</h3></div>
                    <?php if (!$lg['rate_activity']): ?><div class="a2-empty">مفيش</div><?php endif; ?>
                    <?php foreach ($lg['rate_activity'] as $r): ?><div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0"><span dir="ltr"><?= e($r['action']) ?></span><span class="a2-muted"><?= (int) $r['n'] ?> طلب · <?= (int) $r['k'] ?> مصدر</span></div><?php endforeach; ?></div>
                <div class="card"><div class="a2-h"><h3>طلبات MCP اترفضت</h3></div>
                    <?php if (!$lg['mcp_denied']): ?><div class="a2-empty">مفيش</div><?php endif; ?>
                    <?php foreach ($lg['mcp_denied'] as $r): ?><div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0"><span dir="ltr"><?= e($r['result_status']) ?></span><b><?= (int) $r['n'] ?></b></div><?php endforeach; ?>
                    <p class="a2-muted" style="font-size:12px">التفاصيل في <a href="<?= url('admin/ai-mcp-log.php') ?>">سجل الـ MCP</a></p></div>
            </div>

        <?php else: ?>
            <div class="card">
                <div class="a2-h"><h3>اختبارات الموقع الكاملة (Playwright)</h3><span class="a2-muted">آخر نتيجة لكل صفحة/سيناريو</span></div>
                <?php if (!$e2e): ?><div class="a2-empty" style="text-align:start;line-height:1.9">لسه ماتشغّلتش. من الـ VPS أو جهازك:<br><code dir="ltr">E2E_BASE=https://staging… node tests/e2e/site-e2e.mjs</code> ← بعدها <code dir="ltr">php tests/e2e/record.php e2e-results.json</code> على السيرفر (الخطوات في docs/STAGING.md).</div><?php endif; ?>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>السيناريو</th><th>النتيجة</th><th>الوقت</th><th>الخطأ</th><th>آخر تشغيل</th></tr></thead><tbody>
                <?php foreach ($e2e as $x): ?>
                    <tr><td dir="ltr" style="text-align:start"><?= e($x['flow_key']) ?></td><td><span class="chip <?= $x['status'] === 'passed' ? 'chip-mint' : 'chip-coral' ?>"><?= $x['status'] === 'passed' ? 'نجح' : 'فشل' ?></span></td>
                        <td class="num"><?= $x['duration_ms'] !== null ? number_format((int) $x['duration_ms']) . ' ms' : '—' ?></td><td style="font-size:12px"><?= e(mb_substr((string) $x['error'], 0, 200)) ?></td>
                        <td class="a2-muted" style="font-size:12px"><?= e(substr((string) $x['run_at'], 0, 16)) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
