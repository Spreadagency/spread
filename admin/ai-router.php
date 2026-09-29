<?php
/**
 * الموديلات والـ Router (المرحلة 8-أ) — تشغيل البوابة · سلسلة البدائل لكل مهمة · الميزانيات · Circuit Breaker
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/admin-ui.php';

require_admin();
require_admin_can('ai_settings');
$ready = usage_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    require_csrf();
    decode_b64_fields();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'gateway') {
        $on = !empty($_POST['on']);
        if ($on && !ai_gw_providers()) {
            flash_set('danger', 'مينفعش تشغّل البوابة ومفيش ولا مزود مفعّل بمفتاح — اضبط «مزودين الـ AI» الأول');
            redirect('admin/ai-router.php');
        }
        set_setting('ai_gateway_enabled', $on ? '1' : '0');
        admin_log('ai_gateway_' . ($on ? 'on' : 'off'), 'settings', null, null);
        flash_set('success', $on ? 'البوابة الموحدة اشتغلت ✓ — كل طلبات الـ AI بقت بتعدّي على سلسلة البدائل' : 'البوابة اتقفلت — المنصة رجعت للمسار القديم (مفتاح واحد)');
        redirect('admin/ai-router.php');
    }

    if ($action === 'save_task') {
        $task = (string) ($_POST['task'] ?? '');
        $row = db_one('SELECT * FROM ai_task_routes WHERE task = ?', [$task]);
        if ($row) {
            $chain = [];
            for ($i = 1; $i <= 3; $i++) {
                $p = (string) ($_POST['p' . $i] ?? '');
                $m = trim((string) ($_POST['m' . $i] ?? ''));
                if ($p === '') continue;
                if (!db_one('SELECT id FROM ai_providers WHERE provider_name = ?', [$p])) continue;
                if ($m !== '' && !preg_match('#^[A-Za-z0-9._:/@+-]{1,120}$#', $m)) {
                    flash_set('danger', 'اسم الموديل «' . $m . '» فيه حروف مش مسموحة');
                    redirect('admin/ai-router.php#t-' . $task);
                }
                $chain[] = ['p' => $p, 'm' => $m];
            }
            $cb = trim((string) ($_POST['cost_budget_usd'] ?? ''));
            db_run(
                'UPDATE ai_task_routes SET chain_json = ?, max_attempts = ?, time_budget_s = ?, cost_budget_usd = ?, json_required = ?, allow_fallback = ?,
                    updated_by = ?, updated_at = NOW() WHERE task = ?',
                [
                    $chain ? json_encode($chain, JSON_UNESCAPED_SLASHES) : null,
                    max(1, min(6, (int) ($_POST['max_attempts'] ?? 3))),
                    max(15, min(600, (int) ($_POST['time_budget_s'] ?? 120))),
                    $cb === '' ? null : max(0, min(50, (float) $cb)),
                    !empty($_POST['json_required']) ? 1 : 0,
                    !empty($_POST['allow_fallback']) ? 1 : 0,
                    (int) (current_admin()['id'] ?? 0) ?: null, $task,
                ]
            );
            admin_log('ai_route_save', 'ai_task_routes', null, json_encode(['task' => $task, 'chain' => $chain], JSON_UNESCAPED_UNICODE));
            ai_gw_cache_bust();
            flash_set('success', 'اتحفظ مسار «' . ($row['label'] ?: $task) . '» ✓');
        }
        redirect('admin/ai-router.php#t-' . $task);
    }

    if ($action === 'test_task') {
        $task = (string) ($_POST['task'] ?? '');
        $cap = usage_task_capability($task);
        $row = db_one('SELECT * FROM ai_task_routes WHERE task = ?', [$task]);
        if ($row && $cap !== 'image') {
            $t0 = microtime(true);
            $req = $cap === 'web'
                ? ['prompt' => 'What is the capital of Egypt? Answer in one short sentence with a source.', 'max_results' => 2, 'search_context' => 'low', 'max_tokens' => 120]
                : ['messages' => [['role' => 'user', 'content' => (int) $row['json_required'] === 1 ? 'Return ONLY this JSON: {"ok":true}' : 'Reply with the single word: OK']], 'max_tokens' => 30, 'temperature' => 0];
            $r = ai_run($task, $req, ['feature' => 'admin_test', 'customer_data' => false]);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            admin_log('ai_route_test', 'ai_task_routes', null, $task . ' ' . ($r['ok'] ? 'ok' : 'fail'));
            flash_set($r['ok'] ? 'success' : 'danger', $r['ok']
                ? "«{$row['label']}» اشتغل ✓ — {$r['provider']} · {$r['model']} · {$r['attempts']} محاولة · {$ms}ms · " . a2usd($r['cost_usd'], 5) . ($r['failover'] ? ' (اتحوّل للبديل)' : '')
                : "«{$row['label']}» فشل — " . ($r['error_class'] ?? '') . ': ' . mb_substr((string) ($r['error_detail'] ?? $r['error']), 0, 200));
        }
        redirect('admin/ai-router.php#t-' . $task);
    }

    if ($action === 'breaker_settings') {
        set_setting('ai_breaker_threshold', (string) max(1, min(20, (int) ($_POST['threshold'] ?? 3))));
        set_setting('ai_breaker_window_min', (string) max(1, min(60, (int) ($_POST['window'] ?? 5))));
        set_setting('ai_breaker_cooldown_min', (string) max(1, min(120, (int) ($_POST['cooldown'] ?? 5))));
        set_setting('ai_failover_alert_per_hour', (string) max(1, min(500, (int) ($_POST['failover_alert'] ?? 5))));
        admin_log('ai_breaker_settings', 'settings', null, null);
        flash_set('success', 'اتحفظت إعدادات الحماية ✓');
        redirect('admin/ai-router.php#breaker');
    }

    if ($action === 'reset') {
        db_run('DELETE FROM ai_provider_state WHERE provider = ? AND capability = ?', [(string) ($_POST['provider'] ?? ''), (string) ($_POST['cap'] ?? '')]);
        admin_log('ai_breaker_reset', 'ai_provider_state', null, ($_POST['provider'] ?? '') . '/' . ($_POST['cap'] ?? ''));
        flash_set('success', 'رجع للسلسلة ✓');
        redirect('admin/ai-router.php#breaker');
    }
    redirect('admin/ai-router.php');
}

$gwOn = $ready && (string) get_setting('ai_gateway_enabled', '0') === '1';
$providersAll = $ready ? ai_gw_providers(true) : [];
$routes = [];
try { $routes = $ready ? db_all('SELECT * FROM ai_task_routes ORDER BY FIELD(task, "content","ideas","eval","brand","design","research_search","research_analyze","general")') : []; } catch (\Throwable $e) {}
$states = [];
try { $states = $ready ? db_all('SELECT * FROM ai_provider_state ORDER BY provider, capability') : []; } catch (\Throwable $e) {}
$contentChain = $ready ? ai_gw_chain('content', ['customer_data' => true]) : [];
$use7 = [];
try {
    foreach ($ready ? db_all('SELECT task, COUNT(*) n, SUM(status = "ok") ok, SUM(failovers > 0) fo FROM ai_runs WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY task') : [] as $u) $use7[$u['task']] = $u;
} catch (\Throwable $e) {}

$capLbl = ['text' => 'نص', 'image' => 'صور', 'web' => 'بحث ويب'];
$active = 'ai-router';
$page_title = 'الموديلات والـ Router';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>

        <div class="page-head with-actions">
            <div>
                <h1>الموديلات والـ Router</h1>
                <div class="sub">Models &amp; Router · لكل مهمة: أساسي ← بديل ← بديل، والتحويل تلقائي لو المزود وقع</div>
            </div>
            <a class="btn soft" href="<?= url('admin/ai-providers.php') ?>"><?= admin_icon('plug', 16) ?> المزودين</a>
        </div>

        <?php if (!$ready): ?>
            <div class="alert warning">لازم تشغّل ترحيل «8-أ» الأول من <a href="<?= url('admin/migrations.php') ?>">الترحيلات</a>.</div>
        <?php else: ?>

        <div class="card" style="margin-bottom:16px">
            <div class="a2-h"><h3>مسار التنفيذ</h3><span class="a2-en">Execution path</span></div>
            <div class="a2-flow">
                <div class="a2-node"><b>Request</b><small>طلب العميل</small></div><span class="a2-arr">‹</span>
                <div class="a2-node"><b>Smart Router</b><small>بيختار المسار حسب نوع المهمة</small></div><span class="a2-arr">‹</span>
                <div class="a2-node" style="flex:2"><b>Provider</b><small><?= $contentChain ? e(implode(' ← ', array_map(fn($c) => $c['p']['label'], $contentChain))) : 'مفيش مزود مفعّل' ?></small></div><span class="a2-arr">‹</span>
                <div class="a2-node"><b>Model</b><small>حسب نوع الطلب</small></div><span class="a2-arr">‹</span>
                <div class="a2-node"><b>Response</b><small>بيتسجل بتكلفته في AI Logs</small></div>
            </div>
        </div>

        <form method="post" class="card" style="margin-bottom:16px">
            <?= csrf_field() ?><input type="hidden" name="action" value="gateway">
            <div class="a2-row" style="justify-content:space-between">
                <div style="flex:1;min-width:240px">
                    <h3 style="margin:0 0 4px;font-size:16px">البوابة الموحدة (Smart Router) <span class="chip <?= $gwOn ? 'chip-mint' : 'chip-line' ?>"><?= $gwOn ? '● شغالة' : 'متقفلة' ?></span></h3>
                    <div class="a2-muted">لما تشتغل: كل المزايا (المحتوى · الأفكار · التقييم · Brand Brain · البحث · التصميمات) بتمشي بالسلاسل اللي تحت، مع تحويل تلقائي وتسجيل تكلفة كل محاولة. لما تتقفل: المسار القديم بمفتاح واحد (والتكلفة لسه بتتسجل).</div>
                </div>
                <label class="a2-sw" title="تشغيل البوابة"><input type="checkbox" name="on" value="1" <?= $gwOn ? 'checked' : '' ?> onchange="if(confirm(this.checked?'تشغّل البوابة الموحدة؟ اتأكد إنك اختبرت المزودين.':'تقفل البوابة وترجع للمسار القديم؟'))this.form.submit();else this.checked=!this.checked" aria-label="تشغيل البوابة"><span></span></label>
            </div>
        </form>

        <div class="a2-grid" style="gap:14px">
        <?php foreach ($routes as $r):
            $task = $r['task'];
            $cap = $r['capability'];
            $chain = json_decode((string) $r['chain_json'], true) ?: [];
            $eff = ai_gw_chain($task, ['customer_data' => true]);
            $eligible = array_values(array_filter($providersAll, fn($p) => ai_gw_supports($p, $cap)));
            $u = $use7[$task] ?? null;
        ?>
            <form method="post" class="card" id="t-<?= e($task) ?>" data-safe-post>
                <?= csrf_field() ?><input type="hidden" name="task" value="<?= e($task) ?>">
                <div class="a2-h">
                    <h3><?= e($r['label'] ?: $task) ?></h3>
                    <span class="chip chip-line"><?= e($capLbl[$cap] ?? $cap) ?></span>
                    <span class="a2-en"><?= e($task) ?></span>
                    <span class="a2-grow"></span>
                    <?php if ($u): ?><span class="a2-muted">آخر 7 أيام: <?= (int) $u['n'] ?> عملية · نجاح <?= $u['n'] ? round($u['ok'] / $u['n'] * 100) : 0 ?>% · تحويل للبديل <?= (int) $u['fo'] ?></span><?php endif; ?>
                </div>
                <div class="a2-chain">
                    <?php foreach (['Primary Provider', 'Secondary Provider', 'Fallback Provider'] as $i => $lbl):
                        $sel = $chain[$i]['p'] ?? '';
                        $mod = $chain[$i]['m'] ?? '';
                        $dl = 'dl-' . $task . '-' . $i;
                    ?>
                    <div class="a2-step">
                        <span class="a2-lbl"><?= $lbl ?></span>
                        <select class="input" name="p<?= $i + 1 ?>" data-dl="<?= $dl ?>" onchange="a2Models(this)">
                            <option value=""><?= $i === 0 && !$chain ? 'تلقائي (حسب الأولوية)' : '—' ?></option>
                            <?php foreach ($eligible as $p): ?>
                                <option value="<?= e($p['name']) ?>" <?= $sel === $p['name'] ? 'selected' : '' ?> data-models="<?= e(json_encode(array_values(array_unique(array_merge($p['models'][$cap] ?? [], $cap === 'web' ? ($p['models']['text'] ?? []) : []))))) ?>"><?= e($p['label']) ?><?= $p['status'] !== 'active' ? ' (متوقف)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input class="input" type="text" name="m<?= $i + 1 ?>" value="<?= e($mod) ?>" list="<?= $dl ?>" placeholder="الموديل (فاضي = الافتراضي)" dir="ltr" data-no-encode="1">
                        <datalist id="<?= $dl ?>"></datalist>
                    </div>
                    <?php if ($i < 2): ?><span class="a2-arr" style="color:#2EC4B0;font-weight:700;align-self:center">‹</span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="a2-grid a2-g4" style="margin-top:12px;gap:10px">
                    <div><label class="a2-lbl">أقصى محاولات</label><input class="input" type="number" name="max_attempts" min="1" max="6" value="<?= (int) $r['max_attempts'] ?>" style="width:100%"></div>
                    <div><label class="a2-lbl">ميزانية الوقت (ث)</label><input class="input" type="number" name="time_budget_s" min="15" max="600" value="<?= (int) $r['time_budget_s'] ?>" style="width:100%"></div>
                    <div><label class="a2-lbl">ميزانية التكلفة ($ / طلب)</label><input class="input" type="number" name="cost_budget_usd" min="0" max="50" step="0.001" value="<?= $r['cost_budget_usd'] !== null ? e(rtrim(rtrim((string) $r['cost_budget_usd'], '0'), '.')) : '' ?>" placeholder="بدون حد" style="width:100%"></div>
                    <div class="a2-grid" style="gap:6px;align-content:end">
                        <label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="allow_fallback" value="1" <?= (int) $r['allow_fallback'] === 1 ? 'checked' : '' ?>> يحوّل للبدائل</label>
                        <?php if ($cap === 'text'): ?><label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="json_required" value="1" <?= (int) $r['json_required'] === 1 ? 'checked' : '' ?>> لازم JSON صالح</label><?php endif; ?>
                    </div>
                </div>
                <div class="a2-row" style="margin-top:12px">
                    <span class="a2-muted" style="flex:1;min-width:200px">الفعلي دلوقتي: <?= $eff ? e(implode(' ← ', array_map(fn($c) => $c['p']['name'] . ' · ' . $c['m'], $eff))) : '<b style="color:#C2362E">مفيش مزود صالح للمهمة دي</b>' ?></span>
                    <button class="btn sm" type="submit" name="action" value="save_task">حفظ</button>
                    <?php if ($cap !== 'image'): ?><button class="btn sm soft" type="submit" name="action" value="test_task" <?= $eff ? '' : 'disabled' ?>>اختبار السلسلة ▷</button>
                    <?php else: ?><span class="a2-muted">اختبار الصور بيتكلف — جرّبه من تصميم حقيقي</span><?php endif; ?>
                </div>
            </form>
        <?php endforeach; ?>
        </div>

        <div class="a2-grid a2-g2" style="margin-top:16px" id="breaker">
            <form method="post" class="card">
                <?= csrf_field() ?><input type="hidden" name="action" value="breaker_settings">
                <div class="a2-h"><h3>الحماية (Circuit Breaker)</h3></div>
                <p class="a2-muted" style="margin-top:0">لو المزود فشل عدد مرات معين (أعطال مؤقتة: شبكة/5xx/429/رد فاضي) خلال مدة قصيرة، بيتوقف مؤقتًا وتروح الطلبات للبديل على طول، وبعد المهلة بيتجرب طلب اختبار واحد. مشاكل المفتاح/الرصيد بتوقفه 30 دقيقة وتبعت تنبيه.</p>
                <div class="a2-grid a2-g2" style="gap:10px">
                    <div><label class="a2-lbl">عدد الأعطال</label><input class="input" type="number" name="threshold" min="1" max="20" value="<?= (int) get_setting('ai_breaker_threshold', 3) ?>" style="width:100%"></div>
                    <div><label class="a2-lbl">خلال (دقايق)</label><input class="input" type="number" name="window" min="1" max="60" value="<?= (int) get_setting('ai_breaker_window_min', 5) ?>" style="width:100%"></div>
                    <div><label class="a2-lbl">مدة التوقف (دقايق)</label><input class="input" type="number" name="cooldown" min="1" max="120" value="<?= (int) get_setting('ai_breaker_cooldown_min', 5) ?>" style="width:100%"></div>
                    <div><label class="a2-lbl">تنبيه لو التحويل أكتر من (في الساعة)</label><input class="input" type="number" name="failover_alert" min="1" max="500" value="<?= (int) get_setting('ai_failover_alert_per_hour', 5) ?>" style="width:100%"></div>
                </div>
                <div style="margin-top:12px"><button class="btn sm" type="submit">حفظ</button></div>
            </form>
            <div class="card">
                <div class="a2-h"><h3>حالة المزودين الآن</h3></div>
                <?php if (!$states): ?><div class="a2-empty">كل المزودين شغالين — مفيش أعطال مسجلة</div><?php else: ?>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المزود</th><th>القدرة</th><th>الحالة</th><th>آخر خطأ</th><th></th></tr></thead><tbody>
                <?php foreach ($states as $s):
                    $isDown = in_array($s['state'], ['open', 'half'], true) && (empty($s['opened_until']) || strtotime($s['opened_until']) > time()); ?>
                    <tr>
                        <td><b><?= e($s['provider']) ?></b></td><td><?= e($capLbl[$s['capability']] ?? $s['capability']) ?></td>
                        <td><span class="a2-st <?= $isDown ? 'bad' : ((int) $s['fail_count'] ? 'warn' : '') ?>"><?= $isDown ? 'متوقف لحد ' . date('H:i', strtotime($s['opened_until'])) : ((int) $s['fail_count'] ? (int) $s['fail_count'] . ' أعطال' : 'شغال') ?></span></td>
                        <td class="a2-muted" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e((string) $s['last_error']) ?>"><?= e((string) $s['reason']) ?> <?= e(mb_substr((string) $s['last_error'], 0, 60)) ?></td>
                        <td><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="provider" value="<?= e($s['provider']) ?>"><input type="hidden" name="cap" value="<?= e($s['capability']) ?>"><button class="btn sm ghost" type="submit">تصفير</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>
<script>
function a2Models(sel) {
    var dl = document.getElementById(sel.dataset.dl); if (!dl) return;
    var o = sel.options[sel.selectedIndex], list = [];
    try { list = JSON.parse(o.getAttribute('data-models') || '[]'); } catch (e) {}
    dl.innerHTML = list.map(function (m) { var x = document.createElement('option'); x.value = m; return x.outerHTML; }).join('');
}
document.querySelectorAll('select[data-dl]').forEach(a2Models);
</script>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
