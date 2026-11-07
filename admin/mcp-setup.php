<?php
/**
 * Spread AI — إعداد الـ MCP (المرحلة 3): حالة الـ Internal API · قاعدة الـ AI · إثبات حدود الصلاحيات · السجل
 * الأسرار (التوكن · باسوردات MySQL) في includes/config.php بس — الصفحة دي بتعرض الحالة ومابتعرضش أي سر.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mcp-config.php';

require_admin();
require_admin_can('ai_settings');

$conf = fn(string $k) => defined($k) ? (string) constant($k) : '';
$probes = null;
$migrateMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'migrate') {
        try {
            $r = mcp_ai_db_migrate();
            admin_log('mcp_ai_db_migrate', 'settings', null, mcp_ai_db());
            flash_set('success', 'جداول قاعدة الـ AI جاهزة ✓ (' . $r['statements'] . ' جملة)');
        } catch (\Throwable $e) {
            flash_set('danger', 'تعذّر إنشاء الجداول: ' . $e->getMessage() . ' — اتأكد إن مستخدم المنصة (' . DB_USER . ') ليه صلاحية على قاعدة ' . mcp_ai_db());
        }
        redirect('admin/mcp-setup.php');
    }
}
if (isset($_GET['probe'])) {
    $probes = iapi_secure_mode() ? mcp_permission_probes() : [];
    admin_log('mcp_permission_probe', 'settings', null, $probes ? count(array_filter($probes, fn($p) => $p['denied'])) . '/' . count($probes) . ' denied' : 'not configured');
}

$tables = mcp_ai_tables_status();
$tablesOk = !in_array(false, $tables, true);
$token = $conf('INTERNAL_API_TOKEN');
$ips = array_filter(array_map('trim', explode(',', $conf('INTERNAL_API_IPS'))));
$separate = $conf('MCP_AI_DB_NAME') !== '' && $conf('MCP_AI_DB_NAME') !== DB_NAME;
$conns = [];
foreach (['read', 'ai_write', 'draft'] as $s) {
    try { iapi_pdo($s)->query('SELECT 1'); $conns[$s] = [true, '']; } catch (\Throwable $e) { $conns[$s] = [false, mb_substr($e->getMessage(), 0, 140)]; }
}
$log = [];
if ($tables['mcp_audit_log'] ?? false) {
    try { $log = db_all('SELECT client, tool, scope, result_status, duration_ms, ip, created_at FROM ' . mcp_t('mcp_audit_log') . ' ORDER BY id DESC LIMIT 25'); } catch (\Throwable $e) {}
}
$checks = [
    ['التوكن (INTERNAL_API_TOKEN)', strlen($token) >= 32, strlen($token) >= 32 ? 'متظبط (' . strlen($token) . ' حرف)' : 'ناقص — 32 حرف على الأقل (الأفضل 64)'],
    ['IP خادم الـ MCP (INTERNAL_API_IPS)', (bool) $ips, $ips ? implode('، ', $ips) : 'فاضي ← مسموح لـ 127.0.0.1 بس'],
    ['قاعدة الـ AI منفصلة (MCP_AI_DB_NAME)', $separate, $separate ? mcp_ai_db() : 'نفس قاعدة المنصة — للتطوير بس'],
    ['مستخدمين MySQL المحدودين (MCP_DB_*)', iapi_secure_mode(), iapi_secure_mode() ? 'متظبطين' : 'مش متظبطين ← الـ API شغال بمستخدم المنصة (مش آمن للإنتاج)'],
    ['جداول قاعدة الـ AI (12)', $tablesOk, count(array_filter($tables)) . ' / ' . count($tables)],
];
foreach ($conns as $s => [$ok, $err]) $checks[] = ['اتصال صلاحية ' . $s, $ok, $ok ? 'شغال' : $err];
$allOk = !in_array(false, array_column($checks, 1), true);

$active = 'mcp-setup';
$page_title = 'إعداد الـ MCP';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <div style="flex:1"></div>
            <span class="badge-admin">ADMIN</span>
        </div>
        <?= render_flash() ?>
        <div class="page-head">
            <h1>إعداد الـ MCP 🛡</h1>
            <div class="sub">الـ Internal API اللي خادم الـ MCP بيكلمه — قاعدة الـ AI المنفصلة وحدود الصلاحيات</div>
        </div>

        <div class="card" style="margin-bottom:16px">
            <div class="card-head"><h3>الحالة <?= $allOk ? '<span class="chip chip-mint">جاهز ✓</span>' : '<span class="chip chip-amber">محتاج إعداد</span>' ?></h3></div>
            <div class="table-wrap"><table class="table">
                <tbody>
                <?php foreach ($checks as [$label, $ok, $info]): ?>
                    <tr><td style="width:36px"><?= $ok ? '✅' : '⚠️' ?></td><td><b><?= e($label) ?></b></td><td class="text-mute" style="font-size:12.5px"><?= e($info) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <form method="POST" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="migrate">
                    <button class="btn" <?= $tablesOk ? 'disabled' : '' ?>>إنشاء جداول قاعدة الـ AI</button></form>
                <a class="btn ghost" href="?probe=1">🔒 اختبار حدود الصلاحيات</a>
            </div>
            <p class="sub" style="margin:12px 0 0;font-size:12.5px">العنوان اللي خادم الـ MCP بيكلمه: <code dir="ltr"><?= e(rtrim(APP_URL, '/')) ?>/internal-api/v1/index.php</code> — الإعداد خطوة بخطوة في <code>docs/INTERNAL_API.md</code></p>
        </div>

        <?php if ($probes !== null): ?>
        <div class="card" style="margin-bottom:16px">
            <div class="card-head"><h3>🔒 إثبات حدود الصلاحيات</h3>
                <?php if ($probes): $den = count(array_filter($probes, fn($p) => $p['denied'])); ?>
                    <span class="chip <?= $den === count($probes) ? 'chip-mint' : 'chip-amber' ?>"><?= $den ?> / <?= count($probes) ?> مرفوض</span>
                <?php endif; ?></div>
            <?php if (!$probes): ?>
                <p class="sub">مستخدمين MySQL المحدودين مش متظبطين — الاختبار محتاجهم.</p>
            <?php else: ?>
                <p class="sub" style="margin-bottom:10px">كل محاولة هنا لازم MySQL نفسه يرفضها. الجمل كلها مكتوبة بحيث ماتغيّرش أي حاجة حتى لو اتنفذت.</p>
                <div class="table-wrap"><table class="table"><tbody>
                    <?php foreach ($probes as $p): ?>
                        <tr><td style="width:36px"><?= $p['denied'] ? '✅' : '❌' ?></td><td><?= e($p['label']) ?></td>
                            <td class="text-mute" style="font-size:12px"><?= $p['denied'] ? 'مرفوض من MySQL' : 'اتنفذ! راجع صلاحيات المستخدم ده فورًا' ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head"><h3>آخر طلبات الـ MCP</h3><span class="chip"><?= count($log) ?></span></div>
            <?php if (!$log): ?>
                <p class="sub">لسه مفيش طلبات.</p>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>الوقت</th><th>العميل</th><th>الأداة</th><th>الصلاحية</th><th>النتيجة</th><th>ms</th></tr></thead>
                    <tbody>
                    <?php foreach ($log as $r): ?>
                        <tr><td style="font-size:12px;white-space:nowrap"><?= e($r['created_at']) ?></td><td><?= e($r['client']) ?></td><td><code dir="ltr"><?= e($r['tool']) ?></code></td>
                            <td><?= e($r['scope']) ?></td><td><span class="chip <?= $r['result_status'] === 'ok' ? 'chip-mint' : 'chip-amber' ?>"><?= e($r['result_status']) ?></span></td><td><?= (int) $r['duration_ms'] ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
