<?php
/**
 * Spread AI — الأدمن: مستويات الجودة (المرحلة 5)
 * كل مستوى: الاسم والوصف · معامل سعر الكتابة · جولات التحسين وحد الجودة · إعادات الصورة · الأولوية
 * + موديل لكل مهمة (الكتابة · الناقد · التصميم …) — فاضي = زي «الموديلات والـ Router» (السلسلة الأساسية بتفضل بديل)
 * كل باقة بتختار مستواها من «الباقات».
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ai-models.php';

require_admin();
require_admin_can('ai_settings');

if (!qt_ready()) {
    flash_set('warning', 'شغّل ترحيل 2026_quality_tiers الأول (فحص التحديث)');
    redirect('admin/migrations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $act = (string) ($_POST['action'] ?? '');
    $num = fn(string $k, int $lo, int $hi) => ($_POST[$k] ?? '') === '' ? null : max($lo, min($hi, (int) $_POST[$k]));

    if ($act === 'save_tier') {
        $key = (string) ($_POST['tkey'] ?? '');
        $isNew = !qt_get($key);
        if ($isNew && !preg_match('/^[a-z][a-z0-9_]{1,19}$/', $key)) {
            flash_set('danger', 'كود المستوى: حروف إنجليزي صغيرة وأرقام (مثلًا vip)');
            redirect('admin/quality-tiers.php');
        }
        $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 60) ?: $key;
        $vals = [$label, mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255) ?: null, max(0.1, min(20, (float) ($_POST['credit_mult'] ?? 1))),
                 $num('qa_rounds', 0, 4), $num('qa_pass', 50, 100), $num('vision_regen', 0, 4), max(0, min(100, (int) ($_POST['priority'] ?? 0))),
                 (int) ($_POST['sort_order'] ?? 0), $key === QT_DEFAULT || !empty($_POST['is_active']) ? 1 : 0];
        if ($isNew) db_run('INSERT INTO quality_tiers (label, description, credit_mult, qa_rounds, qa_pass, vision_regen, priority, sort_order, is_active, tkey) VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$key]));
        else db_run('UPDATE quality_tiers SET label = ?, description = ?, credit_mult = ?, qa_rounds = ?, qa_pass = ?, vision_regen = ?, priority = ?, sort_order = ?, is_active = ? WHERE tkey = ?', array_merge($vals, [$key]));
        admin_log('quality_tier_save', 'quality_tier', null, $key);
        flash_set('success', 'اتحفظ المستوى ✓');
        redirect('admin/quality-tiers.php?tier=' . urlencode($key));
    }

    if ($act === 'save_routes') {
        $tier = (string) ($_POST['tier'] ?? '');
        if (!qt_get($tier) || $tier === QT_DEFAULT) redirect('admin/quality-tiers.php');
        foreach (db_all('SELECT task FROM ai_task_routes') as $t) {
            $task = (string) $t['task'];
            $mid = (int) ($_POST['m'][$task] ?? 0);
            $temp = trim((string) ($_POST['t'][$task] ?? ''));
            qt_route_save($tier, $task, $mid > 0 && aim_get($mid) ? $mid : null, $temp === '' ? null : max(0, min(1.5, (float) $temp)));
        }
        admin_log('quality_tier_routes', 'quality_tier', null, $tier);
        flash_set('success', 'اتحفظت موديلات المستوى ✓');
        redirect('admin/quality-tiers.php?tier=' . urlencode($tier));
    }
}

$tiers = qt_all(false);
$cur = isset($tiers[$_GET['tier'] ?? '']) ? (string) $_GET['tier'] : (string) (array_keys($tiers)[1] ?? QT_DEFAULT);
$tasks = db_all('SELECT task, label, capability, chain_json FROM ai_task_routes ORDER BY FIELD(capability, "text", "image", "web"), task');
$models = aim_list(['enabled' => true]);
$routes = [];
foreach (db_all('SELECT * FROM ai_tier_routes WHERE tier = ?', [$cur]) as $r) $routes[$r['task']] = $r;
$usedBy = [];
foreach (db_all('SELECT quality_tier, COUNT(*) n FROM credit_packages GROUP BY quality_tier') as $r) $usedBy[$r['quality_tier']] = (int) $r['n'];
$ceDef = ['rounds' => (int) get_setting('ce_qa_max_rounds', 2), 'pass' => (int) get_setting('ce_qa_pass_total', 85), 'regen' => (int) get_setting('ce_vision_max_regen', 2)];

$active = 'quality-tiers';
$page_title = 'مستويات الجودة';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>مستويات الجودة ⭐</h1>
            <div class="sub">الموديلات وسرعتها وتفاصيلها لكل مستوى · معامل سعر الكتابة · جولات التحسين — وكل باقة بتختار مستواها من «الباقات»</div>
        </div>
        <?= render_flash() ?>

        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3>المستويات</h3></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>المستوى</th><th>معامل السعر</th><th>جولات التحسين</th><th>حد الجودة</th><th>إعادات الصورة</th><th>الأولوية</th><th>باقات</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tiers as $k => $t): ?>
                    <tr<?= (int) $t['is_active'] ? '' : ' style="opacity:.55"' ?>><td><b><?= e($t['label']) ?></b> <small class="text-mute" dir="ltr"><?= e($k) ?></small><div class="sub" style="font-size:11.5px"><?= e((string) $t['description']) ?></div></td>
                        <td class="num">× <?= e(rtrim(rtrim(number_format((float) $t['credit_mult'], 2), '0'), '.')) ?></td>
                        <td class="num"><?= $t['qa_rounds'] !== null ? (int) $t['qa_rounds'] : '<span class="text-mute">' . $ceDef['rounds'] . '</span>' ?></td>
                        <td class="num"><?= $t['qa_pass'] !== null ? (int) $t['qa_pass'] : '<span class="text-mute">' . $ceDef['pass'] . '</span>' ?></td>
                        <td class="num"><?= $t['vision_regen'] !== null ? (int) $t['vision_regen'] : '<span class="text-mute">' . $ceDef['regen'] . '</span>' ?></td>
                        <td class="num"><?= (int) $t['priority'] ?></td>
                        <td class="num"><?= (int) ($usedBy[$k] ?? 0) ?></td>
                        <td><a class="btn ghost sm" href="?tier=<?= e(urlencode($k)) ?>#edit">✎</a></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="field-help">الرمادي = إعداد المحرك العام (من «إعدادات المحرك»). «عادي» هو المستوى الأساسي وموديلاته هي اللي في «الموديلات والـ Router».</div>
        </div>

        <?php $t = ($_GET['tier'] ?? '') === '__new' ? null : ($tiers[$cur] ?? null); ?>
        <div class="split split-2" id="edit">
            <div class="card">
                <div class="card-head"><h3><?= $t ? 'تعديل: ' . e($t['label']) : 'مستوى جديد' ?></h3></div>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="action" value="save_tier">
                    <div class="field-row">
                        <div class="field"><label>الكود</label><input type="text" name="tkey" class="input" dir="ltr" value="<?= e($t['tkey'] ?? '') ?>" <?= $t ? 'readonly' : 'required' ?> pattern="[a-z][a-z0-9_]{1,19}"></div>
                        <div class="field"><label>الاسم</label><input type="text" name="label" class="input" maxlength="60" value="<?= e($t['label'] ?? '') ?>"></div>
                    </div>
                    <div class="field"><label>الوصف (العميل بيشوفه)</label><input type="text" name="description" class="input" maxlength="255" value="<?= e((string) ($t['description'] ?? '')) ?>" placeholder="أسرع · أدق · تفاصيل أكتر"></div>
                    <div class="field-row">
                        <div class="field"><label>معامل سعر الكتابة</label><input type="number" name="credit_mult" class="input" min="0.1" max="20" step="0.05" value="<?= e((string) ($t['credit_mult'] ?? '1')) ?>"><div class="field-help">سعر كل خدمة كتابة × المعامل (لفوق)</div></div>
                        <div class="field"><label>الأولوية في الطابور</label><input type="number" name="priority" class="input" min="0" max="100" value="<?= (int) ($t['priority'] ?? 0) ?>"><div class="field-help">الأعلى بيتنفذ الأول (= أسرع)</div></div>
                    </div>
                    <div class="field-row">
                        <div class="field"><label>جولات التحسين</label><input type="number" name="qa_rounds" class="input" min="0" max="4" value="<?= e((string) ($t['qa_rounds'] ?? '')) ?>" placeholder="<?= $ceDef['rounds'] ?>"></div>
                        <div class="field"><label>حد الجودة</label><input type="number" name="qa_pass" class="input" min="50" max="100" value="<?= e((string) ($t['qa_pass'] ?? '')) ?>" placeholder="<?= $ceDef['pass'] ?>"></div>
                        <div class="field"><label>إعادات الصورة</label><input type="number" name="vision_regen" class="input" min="0" max="4" value="<?= e((string) ($t['vision_regen'] ?? '')) ?>" placeholder="<?= $ceDef['regen'] ?>"></div>
                    </div>
                    <div class="field-row">
                        <div class="field"><label>الترتيب</label><input type="number" name="sort_order" class="input" value="<?= (int) ($t['sort_order'] ?? 0) ?>"></div>
                        <div class="field"><label style="display:flex;align-items:center;gap:8px;margin-top:26px"><input type="checkbox" name="is_active" value="1" <?= !$t || (int) $t['is_active'] ? 'checked' : '' ?> <?= ($t['tkey'] ?? '') === QT_DEFAULT ? 'disabled' : '' ?>> مفعّل</label></div>
                    </div>
                    <button class="btn sm">💾 حفظ</button> <a class="btn ghost sm" href="?tier=__new#edit">＋ مستوى جديد</a>
                </form>
            </div>

            <div class="card">
                <div class="card-head"><h3>🤖 الموديلات في «<?= e($t['label'] ?? '') ?>»</h3></div>
                <?php if (!$t || $cur === QT_DEFAULT): ?>
                    <p class="sub">موديلات «عادي» هي الأساسية — بتتعدّل من <a href="<?= url('admin/ai-router.php') ?>">الموديلات والـ Router</a>.</p>
                <?php else: ?>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="action" value="save_routes"><input type="hidden" name="tier" value="<?= e($cur) ?>">
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>المهمة</th><th>الموديل</th><th style="width:90px">الحرارة</th></tr></thead>
                        <tbody>
                        <?php foreach ($tasks as $tk):
                            $cap = (string) $tk['capability'];
                            $sel = (int) ($routes[$tk['task']]['model_id'] ?? 0);
                            $base = json_decode((string) $tk['chain_json'], true)[0] ?? null; ?>
                            <tr><td><b><?= e((string) $tk['label']) ?></b><div class="sub" style="font-size:11px" dir="ltr"><?= e((string) $tk['task']) ?><?= $base ? ' · ' . e($base['p'] . '/' . ($base['m'] ?: 'default')) : '' ?></div></td>
                                <td><select name="m[<?= e((string) $tk['task']) ?>]" class="input" style="min-width:180px">
                                    <option value="0">— زي الأساسي —</option>
                                    <?php foreach ($models as $m): if (!aim_fits($m, $cap)) continue; ?>
                                        <option value="<?= (int) $m['id'] ?>" <?= $sel === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['provider_name'] . ' · ' . ($m['label'] ?: $m['model'])) ?><?= $m['tier'] ? ' (' . e((string) $m['tier']) . ')' : '' ?></option>
                                    <?php endforeach; ?>
                                </select></td>
                                <td><?php if ($cap === 'text'): ?><input type="number" name="t[<?= e((string) $tk['task']) ?>]" class="input" min="0" max="1.5" step="0.05" value="<?= e((string) ($routes[$tk['task']]['temperature'] ?? '')) ?>" placeholder="—"><?php endif; ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <div class="field-help">موديل المستوى بيتجرب الأول، ولو فشل بيكمل على سلسلة «عادي». الحرارة فاضية = زي الأساسي.</div>
                    <button class="btn sm">💾 حفظ الموديلات</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
