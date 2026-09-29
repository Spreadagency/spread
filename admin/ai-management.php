<?php
/**
 * Spread AI v2 — إدارة الـ AI (Phase 1)
 * الموفرين، الموديلات، الـ Smart Routing، والتفعيل
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php'; // get_setting / set_setting
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/smart-ai.php';

require_admin();
require_admin_can('ai_settings');
$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // ── تفعيل / إيقاف الـ Smart Router ──
    if ($action === 'toggle_router') {
        $new = get_setting('use_smart_router', '0') === '1' ? '0' : '1';
        set_setting('use_smart_router', $new);
        admin_log('toggle_smart_router', 'settings', null, $new);
        flash_set('success', $new === '1' ? 'تم تفعيل الـ Smart Router ✓' : 'تم إيقاف الـ Smart Router — النظام رجع للوضع القديم');
        redirect('admin/ai-management.php');
    }

    // ── حفظ API Key لموفر ──
    if ($action === 'update_key') {
        $id = (int) ($_POST['provider_id'] ?? 0);
        $key = trim($_POST['api_key'] ?? '');
        if ($id && $key) {
            try {
                db_run('UPDATE ai_providers SET api_key_encrypted = ? WHERE id = ?', [Crypto::encrypt($key), $id]);
                admin_log('update_api_key', 'ai_provider', $id);
                flash_set('success', 'تم حفظ المفتاح مشفّرًا ✓');
            } catch (Throwable $e) {
                flash_set('danger', 'فشل التشفير: ' . $e->getMessage());
            }
        }
        redirect('admin/ai-management.php');
    }

    // ── تفعيل / إيقاف موفر ──
    if ($action === 'toggle_provider') {
        $id = (int) ($_POST['provider_id'] ?? 0);
        db_run('UPDATE ai_providers SET status = IF(status = "active", "disabled", "active") WHERE id = ?', [$id]);
        admin_log('toggle_provider', 'ai_provider', $id);
        flash_set('success', 'تم تغيير حالة الموفر');
        redirect('admin/ai-management.php');
    }

    // ── اختبار اتصال ──
    if ($action === 'health_check') {
        $id = (int) ($_POST['provider_id'] ?? 0);
        $p = db_one('SELECT provider_name FROM ai_providers WHERE id = ?', [$id]);
        if ($p) {
            try {
                $ok = \Spread\ProviderFactory::make($p['provider_name'])->ping();
                db_run('UPDATE ai_providers SET health_status = ?, last_health_check = NOW() WHERE id = ?', [$ok ? 'healthy' : 'down', $id]);
                flash_set($ok ? 'success' : 'danger', $ok ? "✓ {$p['provider_name']} شغّال" : "✕ {$p['provider_name']} مش راد");
            } catch (Throwable $e) {
                db_run('UPDATE ai_providers SET health_status = "down", last_health_check = NOW() WHERE id = ?', [$id]);
                flash_set('danger', 'فشل الاختبار: ' . $e->getMessage());
            }
        }
        redirect('admin/ai-management.php');
    }

    // ── إضافة موديل ──
    if ($action === 'add_model') {
        $pid = (int) ($_POST['provider_id'] ?? 0);
        $name = trim($_POST['model_name'] ?? '');
        if ($pid && $name) {
            db_insert(
                'INSERT INTO ai_models (provider_id, model_name, display_name, model_type, max_tokens, priority_order)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $pid,
                    $name,
                    trim($_POST['display_name'] ?? '') ?: $name,
                    in_array($_POST['model_type'] ?? '', ['text', 'image', 'vision'], true) ? $_POST['model_type'] : 'text',
                    (int) ($_POST['max_tokens'] ?? 0) ?: null,
                    (int) ($_POST['priority_order'] ?? 100),
                ]
            );
            admin_log('add_ai_model', 'ai_model', null, $name);
            flash_set('success', 'تم إضافة الموديل ✓');
        }
        redirect('admin/ai-management.php');
    }

    // ── تفعيل / إيقاف / حذف موديل ──
    if ($action === 'toggle_model') {
        db_run('UPDATE ai_models SET status = IF(status = "active", "disabled", "active") WHERE id = ?', [(int) $_POST['model_id']]);
        redirect('admin/ai-management.php');
    }
    if ($action === 'delete_model') {
        $mid = (int) ($_POST['model_id'] ?? 0);
        $used = db_one('SELECT id FROM smart_routing_rules WHERE preferred_model_id = ? LIMIT 1', [$mid]);
        if ($used) {
            flash_set('danger', 'الموديل مستخدم في قاعدة توجيه — عدّل القاعدة الأول');
        } else {
            db_run('DELETE FROM ai_models WHERE id = ?', [$mid]);
            flash_set('success', 'تم حذف الموديل');
        }
        redirect('admin/ai-management.php');
    }

    // ── حفظ قاعدة توجيه ──
    if ($action === 'save_rule') {
        $task = trim($_POST['task_code'] ?? '');
        $modelId = (int) ($_POST['preferred_model_id'] ?? 0);
        $chainRaw = trim($_POST['fallback_chain'] ?? '');
        $chain = null;
        if ($chainRaw !== '') {
            $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $chainRaw))));
            $chain = $ids ? json_encode($ids) : null;
        }
        if ($task && $modelId) {
            $exists = db_one('SELECT id FROM smart_routing_rules WHERE task_code = ?', [$task]);
            if ($exists) {
                db_run('UPDATE smart_routing_rules SET preferred_model_id = ?, fallback_chain = ?, is_active = 1 WHERE id = ?',
                    [$modelId, $chain, $exists['id']]);
            } else {
                db_insert('INSERT INTO smart_routing_rules (task_code, preferred_model_id, fallback_chain) VALUES (?, ?, ?)',
                    [$task, $modelId, $chain]);
            }
            admin_log('save_routing_rule', 'routing_rule', null, $task);
            flash_set('success', 'تم حفظ قاعدة التوجيه ✓');
        }
        redirect('admin/ai-management.php');
    }
}

$routerOn = get_setting('use_smart_router', '0') === '1';
$providers = db_all('SELECT * FROM ai_providers ORDER BY priority ASC');
$models = db_all(
    'SELECT m.*, p.provider_name FROM ai_models m
     JOIN ai_providers p ON p.id = m.provider_id
     ORDER BY p.priority, m.priority_order'
);
$rules = db_all(
    'SELECT r.*, m.model_name, p.provider_name
     FROM smart_routing_rules r
     JOIN ai_models m ON m.id = r.preferred_model_id
     JOIN ai_providers p ON p.id = m.provider_id
     ORDER BY r.task_code'
);
$recentJobs = db_all('SELECT * FROM ai_job_logs ORDER BY id DESC LIMIT 15');
$recentFailovers = db_all('SELECT * FROM ai_failover_logs ORDER BY id DESC LIMIT 8');

$healthChips = ['healthy' => '🟢 سليم', 'degraded' => '🟡 متذبذب', 'down' => '🔴 واقع', 'unknown' => '⚪ غير معروف'];
$taskLabels = [
    'content_generation' => 'توليد المنشورات',
    'image_generation'   => 'توليد التصميمات',
    'source_summary'     => 'تلخيص المستندات',
    'plan_ideas'         => 'أفكار خطة المحتوى (مرحلة 3)',
    'design_prompt'      => 'برومبت التصميم (مرحلة 3)',
];

$active = 'ai';
$page_title = 'إدارة الـ AI';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <h1>إدارة الـ AI ⚙</h1>
                <div class="sub">الموفرين، الموديلات، والـ Smart Routing مع Failover تلقائي</div>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_router">
                <button type="submit" class="btn <?= $routerOn ? '' : 'btn-primary' ?>">
                    <?= $routerOn ? '⏸ إيقاف الـ Smart Router' : '▶ تفعيل الـ Smart Router' ?>
                </button>
            </form>
        </div>

        <?= render_flash() ?>

        <div class="alert <?= $routerOn ? 'success' : 'warning' ?>">
            <?php if ($routerOn): ?>
                ✓ الـ Smart Router <b>مفعّل</b> — التوليد بيمشي على قواعد التوجيه والـ failover chain تحت.
            <?php else: ?>
                ⚠ الـ Smart Router <b>متوقف</b> — النظام شغال بالطريقة القديمة (config.php). فعّله بعد ما تضيف مفتاح API وتختبر الاتصال.
            <?php endif; ?>
        </div>

        <!-- Providers -->
        <div class="card" style="margin-top:20px">
            <div class="card-head"><h3>الموفرين (Providers)</h3></div>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>الموفر</th><th>الحالة</th><th>الصحة</th><th>API Key</th><th>إجراءات</th></tr></thead>
                <tbody>
                <?php foreach ($providers as $p): ?>
                    <tr>
                        <td><b><?= e($p['provider_name']) ?></b><div class="sub" style="font-size:11px"><?= e($p['api_base_url']) ?></div></td>
                        <td><span class="chip <?= $p['status'] === 'active' ? 'chip-primary' : '' ?>"><?= $p['status'] === 'active' ? 'نشط' : 'متوقف' ?></span></td>
                        <td><?= $healthChips[$p['health_status']] ?? '⚪' ?>
                            <?php if ($p['last_health_check']): ?><div class="sub" style="font-size:11px"><?= time_ago($p['last_health_check']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:flex;gap:6px">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_key">
                                <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                                <input type="password" name="api_key" class="input" style="max-width:220px"
                                       placeholder="<?= $p['api_key_encrypted'] ? '•••••• (محفوظ)' : 'أدخل المفتاح' ?>" autocomplete="new-password">
                                <button type="submit" class="btn btn-sm">حفظ</button>
                            </form>
                        </td>
                        <td style="white-space:nowrap">
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_provider">
                                <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm"><?= $p['status'] === 'active' ? 'إيقاف' : 'تفعيل' ?></button>
                            </form>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="health_check">
                                <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm">🔌 اختبار</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
                </div>
        </div>

        <!-- Models -->
        <div class="card" style="margin-top:20px">
            <div class="card-head"><h3>الموديلات (Models)</h3></div>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>ID</th><th>الموديل</th><th>الموفر</th><th>النوع</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($models as $m): ?>
                    <tr>
                        <td><span class="chip"><?= $m['id'] ?></span></td>
                        <td><b><?= e($m['display_name'] ?: $m['model_name']) ?></b><div class="sub" style="font-size:11px"><?= e($m['model_name']) ?></div></td>
                        <td><?= e($m['provider_name']) ?></td>
                        <td><?= ['text' => '📝 نص', 'image' => '🎨 صور', 'vision' => '👁 رؤية'][$m['model_type']] ?? $m['model_type'] ?></td>
                        <td><span class="chip <?= $m['status'] === 'active' ? 'chip-primary' : '' ?>"><?= $m['status'] === 'active' ? 'نشط' : 'متوقف' ?></span></td>
                        <td style="white-space:nowrap">
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_model">
                                <input type="hidden" name="model_id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-sm"><?= $m['status'] === 'active' ? 'إيقاف' : 'تفعيل' ?></button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('حذف الموديل؟')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_model">
                                <input type="hidden" name="model_id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="color:#c0392b">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
                </div>

            <div style="margin-top:14px;border-top:1px solid #eee;padding-top:14px">
                <form method="POST" style="display:grid;grid-template-columns:1fr 1.4fr 1fr 0.8fr 0.6fr auto;gap:8px;align-items:end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_model">
                    <div class="field"><label>الموفر</label>
                        <select name="provider_id" class="input">
                            <?php foreach ($providers as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['provider_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label>اسم الموديل (API)</label><input type="text" name="model_name" class="input" placeholder="anthropic/claude-sonnet-4.5" required></div>
                    <div class="field"><label>اسم العرض</label><input type="text" name="display_name" class="input" placeholder="Claude Sonnet"></div>
                    <div class="field"><label>النوع</label>
                        <select name="model_type" class="input">
                            <option value="text">نص</option><option value="image">صور</option><option value="vision">رؤية</option>
                        </select>
                    </div>
                    <div class="field"><label>Max Tokens</label><input type="number" name="max_tokens" class="input" placeholder="4000"></div>
                    <button type="submit" class="btn btn-primary">+ إضافة</button>
                </form>
            </div>
        </div>

        <!-- Routing Rules -->
        <div class="card" style="margin-top:20px">
            <div class="card-head"><h3>قواعد التوجيه (Smart Routing)</h3></div>
            <p class="sub" style="margin-bottom:10px">كل مهمة → موديل مفضل + سلسلة بدائل (IDs الموديلات مفصولة بفواصل) لو فشل الأساسي.</p>

            <?php foreach ($taskLabels as $code => $label):
                $rule = null;
                foreach ($rules as $r) { if ($r['task_code'] === $code) { $rule = $r; break; } }
            ?>
            <form method="POST" style="display:grid;grid-template-columns:1.2fr 1.4fr 1fr auto;gap:8px;align-items:end;margin-bottom:10px;padding-bottom:10px;border-bottom:1px dashed #eee">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="task_code" value="<?= e($code) ?>">
                <div class="field"><label><b><?= e($label) ?></b> <span class="sub">(<?= e($code) ?>)</span></label></div>
                <div class="field"><label>الموديل المفضل</label>
                    <select name="preferred_model_id" class="input">
                        <?php foreach ($models as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $rule && (int) $rule['preferred_model_id'] === (int) $m['id'] ? 'selected' : '' ?>>
                                #<?= $m['id'] ?> — <?= e($m['display_name'] ?: $m['model_name']) ?> (<?= e($m['provider_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>سلسلة البدائل (Fallback IDs)</label>
                    <input type="text" name="fallback_chain" class="input" placeholder="مثال: 2,3"
                           value="<?= $rule ? e(implode(',', json_decode($rule['fallback_chain'] ?? '[]', true) ?: [])) : '' ?>">
                </div>
                <button type="submit" class="btn btn-sm">حفظ</button>
            </form>
            <?php endforeach; ?>
        </div>

        <!-- Logs -->
        <div class="split split-flex" style="--c1:1.5fr;--c2:1fr;gap:20px;margin-top:20px;align-items:flex-start">
            <div class="card">
                <div class="card-head"><h3>آخر عمليات الـ AI</h3></div>
                <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>النوع</th><th>الموديل</th><th>Tokens</th><th>المدة</th><th>الحالة</th><th>متى</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentJobs as $j): ?>
                        <tr>
                            <td><?= e($j['job_type']) ?></td>
                            <td class="sub" style="font-size:12px"><?= e($j['model'] ?? '—') ?></td>
                            <td><?= (int) $j['tokens_in'] ?> / <?= (int) $j['tokens_out'] ?></td>
                            <td><?= $j['duration_ms'] ? round($j['duration_ms'] / 1000, 1) . 's' : '—' ?></td>
                            <td><?= $j['status'] === 'success' ? '✓' : '<span style="color:#c0392b">✕</span>' ?></td>
                            <td class="sub" style="font-size:12px"><?= time_ago($j['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentJobs): ?><tr><td colspan="6" class="sub">لسه مفيش عمليات عبر الـ Router</td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card">
                <div class="card-head"><h3>الـ Failovers</h3></div>
                <?php if (!$recentFailovers): ?>
                    <p class="sub">مفيش failovers — كل حاجة تمام 🎉</p>
                <?php else: ?>
                    <?php foreach ($recentFailovers as $f): ?>
                        <div style="padding:8px 0;border-bottom:1px dashed #eee;font-size:13px">
                            <b><?= e($f['failed_model']) ?></b> فشل →
                            <?= $f['fallback_model'] ? 'تحويل لـ <b>' . e($f['fallback_model']) . '</b>' : '<span style="color:#c0392b">فشل نهائي</span>' ?>
                            <div class="sub" style="font-size:11px"><?= e(mb_substr((string) $f['failure_reason'], 0, 90)) ?> — <?= time_ago($f['created_at']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
