<?php
/**
 * Spread AI — محرك المحتوى v3 · المرحلة C: الموديلات
 *   سجل الموديلات (بيتجاب من الـ API بتاع المزود — مفيش كتابة أسماء إلا في «متقدم»)
 *   خريطة المراحل: موديل + بديل + حرارة لكل مرحلة (قوايم بس)
 *   الإعدادات الجاهزة: اقتصادي · متوازن · أفضل جودة
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ai-models.php';

require_admin();
require_admin_can('ai_settings');

$ready = aim_ready();
$tab = in_array($_GET['tab'] ?? '', ['registry', 'stages', 'presets'], true) ? $_GET['tab'] : 'registry';
$adminId = (int) (current_admin()['id'] ?? 0);
$back = fn(string $t, string $extra = '') => redirect('admin/ai-models.php?tab=' . $t . $extra);

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'fetch') {
            $prov = (string) ($_POST['provider'] ?? '');
            [$ok, $models, $err, $src] = aim_fetch($prov);
            if (!$ok) { flash_set('danger', 'تعذّر جلب الموديلات من ' . $prov . ': ' . $err); $back('registry'); }
            $_SESSION['aim_fetched'] = ['provider' => $prov, 'at' => time(), 'source' => $src, 'models' => $models];
            flash_set('success', 'اتجاب ' . count($models) . ' موديل من ' . $prov . ($src === 'list' ? ' (المزود ده مالوش API للموديلات — دي قايمته الرسمية)' : ''));
            $back('registry', '#fetched');
        }
        if ($action === 'add_fetched') {
            $f = $_SESSION['aim_fetched'] ?? null;
            $pick = array_flip(array_map('strval', (array) ($_POST['pick'] ?? [])));
            if (!$f || !$pick) { flash_set('danger', 'اختار موديل واحد على الأقل'); $back('registry', '#fetched'); }
            $n = 0;
            foreach ($f['models'] as $m) {
                if (!isset($pick[$m['model']])) continue;
                aim_save($m + ['provider_name' => $f['provider'], 'enabled' => 1], $adminId);
                $n++;
            }
            admin_log('ai_models_add', 'ai_model_registry', null, json_encode(['provider' => $f['provider'], 'n' => $n]));
            flash_set('success', "اتضاف $n موديل للسجل ✓ — راجع الأسعار والمستوى لكل واحد");
            $back('registry');
        }
        if ($action === 'save_model') {
            $m = aim_get((int) ($_POST['id'] ?? 0));
            if (!$m) throw new \InvalidArgumentException('الموديل مش موجود');
            $in = $_POST + ['provider_name' => $m['provider_name'], 'model' => $m['model'], 'source' => $m['source']];
            if (empty($_POST['enabled']) && ($used = aim_used_in($m))) {
                flash_set('warning', 'الموديل ده مستخدم في: ' . implode('، ', $used) . ' — اتقفل، والبوابة هتتخطاه لو المزود رفضه. غيّره من «خريطة المراحل».');
            }
            aim_save($in, $adminId);
            admin_log('ai_model_save', 'ai_model_registry', (int) $m['id'], $m['model']);
            flash_set('success', 'اتحفظ ✓ ' . $m['model']);
            $back('registry', '#m' . (int) $m['id']);
        }
        if ($action === 'delete_model') {
            $m = aim_get((int) ($_POST['id'] ?? 0));
            if ($m) {
                if ($used = aim_used_in($m)) throw new \InvalidArgumentException('الموديل مستخدم في: ' . implode('، ', $used) . ' — غيّره من «خريطة المراحل» الأول');
                db_run('DELETE FROM ai_model_registry WHERE id = ?', [(int) $m['id']]);
                admin_log('ai_model_delete', 'ai_model_registry', (int) $m['id'], $m['model']);
                flash_set('success', 'اتشال من السجل: ' . $m['model']);
            }
            $back('registry');
        }
        if ($action === 'add_manual') {
            aim_save(['provider_name' => $_POST['provider_name'] ?? '', 'model' => $_POST['model'] ?? '', 'type' => $_POST['type'] ?? 'text', 'source' => 'manual',
                      'tier' => $_POST['tier'] ?? 'balanced', 'enabled' => 1], $adminId);
            flash_set('success', 'اتضاف ✓ — كمّل الأسعار والخصائص');
            $back('registry');
        }
        if ($action === 'save_stages') {
            $n = 0;
            foreach ((array) ($_POST['st'] ?? []) as $task => $v) {
                if (!is_array($v) || !preg_match('/^[a-z_]{2,40}$/', (string) $task)) continue;
                $temp = !empty($v['auto']) || !isset($v['temp']) || $v['temp'] === '' ? null : (float) $v['temp'];
                aim_stage_save((string) $task, (int) ($v['primary'] ?? 0) ?: null, (int) ($v['fallback'] ?? 0) ?: null, $temp, $adminId);
                $n++;
            }
            admin_log('ai_stage_map_save', 'ai_task_routes', null, (string) $n);
            flash_set('success', "خريطة المراحل اتحفظت ✓ ($n مرحلة)");
            $back('stages');
        }
        if (in_array($action, ['preset_apply', 'preset_save', 'preset_reset'], true)) {
            $preset = (string) ($_POST['preset'] ?? '');
            if (!isset(AIM_TIERS[$preset])) throw new \InvalidArgumentException('إعداد مش معروف');
            if ($action === 'preset_apply') {
                $n = aim_preset_apply($preset, $adminId);
                flash_set('success', 'اتطبّق «' . AIM_TIERS[$preset] . "» على $n مرحلة ✓ — تقدر تعدّل أي مرحلة من «خريطة المراحل»");
                admin_log('ai_preset_apply', 'ai_model_presets', null, $preset);
                $back('stages');
            }
            aim_preset_save_current($preset, $adminId, $action === 'preset_reset');
            admin_log('ai_preset_' . ($action === 'preset_reset' ? 'reset' : 'save'), 'ai_model_presets', null, $preset);
            flash_set('success', $action === 'preset_reset' ? 'الإعداد رجع يتبني تلقائي من مستوى الموديلات' : 'الخريطة الحالية اتحفظت في «' . AIM_TIERS[$preset] . '»');
            $back('presets');
        }
    } catch (\InvalidArgumentException $e) {
        flash_set('danger', $e->getMessage());
        $back($tab);
    } catch (\Throwable $e) {
        error_log('[ai-models] ' . $e->getMessage());
        flash_set('danger', 'حصلت مشكلة: ' . $e->getMessage());
        $back($tab);
    }
}

$providers = $ready ? ai_gw_providers(true) : [];
$models = $ready ? aim_list() : [];
$byId = array_column($models, null, 'id');
$fetched = $_SESSION['aim_fetched'] ?? null;
if ($fetched && time() - (int) $fetched['at'] > 3600) $fetched = null;
$stages = $tab === 'stages' || $tab === 'presets' ? aim_stages() : [];
$capLbl = ['text' => 'نص', 'image' => 'صور', 'web' => 'بحث'];
$tierChip = ['economy' => 'chip-mint', 'balanced' => 'chip-primary', 'quality' => 'chip-amber'];
$price = fn($v) => $v === null || $v === '' ? '—' : '$' . rtrim(rtrim(number_format((float) $v, 4), '0'), '.');
$modelOpt = function (array $st, ?array $selected, bool $allowNone) use ($models): string {
    $cap = (string) $st['capability'];
    $out = $allowNone ? '<option value="">— بدون —</option>' : '<option value="">— اختار —</option>';
    $selId = $selected ? (int) $selected['id'] : 0;
    $groups = [];
    foreach ($models as $m) {
        if (!aim_fits($m, $cap, (bool) $st['needs_vision'])) continue;
        $groups[$m['provider_name']][] = $m;
    }
    foreach ($groups as $prov => $ms) {
        $out .= '<optgroup label="' . e($prov) . '">';
        foreach ($ms as $m) {
            $out .= '<option value="' . (int) $m['id'] . '"' . ((int) $m['id'] === $selId ? ' selected' : '') . '>' . e($m['label'] ?: $m['model'])
                . ' · ' . e(AIM_TIERS[$m['tier']]) . ($m['price_in_1m'] !== null ? ' · $' . rtrim(rtrim(number_format((float) $m['price_in_1m'], 3), '0'), '.') . '/$' . rtrim(rtrim(number_format((float) $m['price_out_1m'], 3), '0'), '.') : '') . '</option>';
        }
        $out .= '</optgroup>';
    }
    return $out;
};

$active = 'ai-models';
$page_title = 'الموديلات';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>الموديلات</h1>
                <div class="sub">سجل الموديلات من المزودين نفسهم · موديل وبديل وحرارة لكل مرحلة · إعدادات جاهزة بضغطة</div>
            </div>
            <div class="a2-seg">
                <a class="<?= $tab === 'registry' ? 'on' : '' ?>" href="?tab=registry">سجل الموديلات</a>
                <a class="<?= $tab === 'stages' ? 'on' : '' ?>" href="?tab=stages">خريطة المراحل</a>
                <a class="<?= $tab === 'presets' ? 'on' : '' ?>" href="?tab=presets">الإعدادات الجاهزة</a>
            </div>
        </div>

        <?php if (!$ready): ?>
            <div class="alert warning">لازم تشغّل ترحيل «2026_ce_models» الأول من <a href="<?= e(url('admin/migrations.php')) ?>">الترحيلات</a> (أو «فحص التحديث»).</div>
        <?php elseif ($tab === 'registry'): ?>

        <div class="card" style="margin-bottom:14px">
            <div class="a2-h"><h3>جلب الموديلات من المزود</h3><span class="a2-muted">بيكلّم الـ API بتاع المزود ويجيب الموديلات المتاحة بمفتاحك</span></div>
            <form method="post" class="a2-row" style="gap:8px;flex-wrap:wrap">
                <?= csrf_field() ?><input type="hidden" name="action" value="fetch">
                <select class="input" name="provider" style="width:auto" required>
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= e($p['name']) ?>" <?= $p['has_key'] ? '' : 'disabled' ?>><?= e($p['label']) ?><?= $p['has_key'] ? ($p['status'] !== 'active' ? ' (متوقف)' : '') : ' — مالوش مفتاح' ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" type="submit">جلب الموديلات</button>
                <a class="btn ghost" href="<?= e(url('admin/ai-providers.php')) ?>">المزودين والمفاتيح</a>
            </form>
        </div>

        <?php if ($fetched): ?>
        <form method="post" class="card" id="fetched" style="margin-bottom:14px">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_fetched">
            <div class="a2-h">
                <h3>متاح عند <?= e($fetched['provider']) ?> (<?= count($fetched['models']) ?>)</h3>
                <input class="input" type="search" placeholder="فلترة بالاسم…" oninput="var q=this.value.toLowerCase();document.querySelectorAll('#fetched tbody tr').forEach(function(r){r.style.display=r.dataset.m.indexOf(q)>-1?'':'none'})" style="width:220px">
                <span class="a2-grow"></span>
                <button class="btn sm" type="submit">إضافة المختار للسجل</button>
            </div>
            <div class="a2-tw" style="max-height:420px;overflow:auto"><table class="a2-tbl"><thead><tr><th></th><th>الموديل</th><th>النوع</th><th>المستوى (مقترح)</th><th>دخل / خرج / كاش لكل 1M</th><th>السياق</th><th>JSON</th><th>كاش</th></tr></thead><tbody>
                <?php foreach ($fetched['models'] as $m): ?>
                    <tr data-m="<?= e(strtolower($m['model'])) ?>">
                        <td><input type="checkbox" name="pick[]" value="<?= e($m['model']) ?>" <?= $m['in_registry'] ? 'disabled' : '' ?>></td>
                        <td dir="ltr" style="text-align:start"><b><?= e($m['model']) ?></b><?= $m['in_registry'] ? ' <span class="chip chip-mint">في السجل</span>' : '' ?></td>
                        <td><?= e(AIM_TYPES[$m['type']] ?? $m['type']) ?></td>
                        <td><span class="chip <?= $tierChip[$m['tier']] ?>"><?= e(AIM_TIERS[$m['tier']]) ?></span></td>
                        <td class="num" dir="ltr"><?= $price($m['price_in_1m']) ?> / <?= $price($m['price_out_1m']) ?> / <?= $price($m['price_cached_1m']) ?><?= $m['price_image'] !== null ? ' · ' . $price($m['price_image']) . '/img' : '' ?></td>
                        <td class="num"><?= $m['max_context'] ? a2n($m['max_context']) : '—' ?></td>
                        <td><?= $m['supports_json'] ? '✓' : '—' ?></td><td><?= $m['supports_cache'] ? '✓' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <p class="a2-muted" style="font-size:12.5px;margin:8px 0 0">الأسعار بتيجي من المزود لو بيرجّعها (OpenRouter)، أو من جدول الأسعار الحالي. لو فاضية، اكتبها بعد الإضافة من سعر المزود الرسمي — هي اللي بيتحسب بيها تكلفة كل طلب.</p>
        </form>
        <?php endif; ?>

        <div class="card" style="padding:0">
            <div class="a2-h" style="padding:14px 16px 0"><h3>السجل</h3><span class="chip"><?= count($models) ?></span><span class="a2-grow"></span>
                <span class="a2-muted" style="font-size:12.5px">الموديلات المفعّلة بس بتظهر في «خريطة المراحل»</span></div>
            <?php if (!$models): ?><div class="a2-empty">السجل فاضي — اجلب الموديلات من مزود فوق.</div><?php else: ?>
            <div class="a2-tw" style="border:0"><table class="a2-tbl"><thead><tr><th>المزود</th><th>الموديل</th><th>النوع</th><th>المستوى</th><th>دخل / خرج / كاش (1M)</th><th>السياق</th><th>JSON · كاش · صور</th><th>الحالة</th><th></th></tr></thead><tbody>
            <?php foreach ($models as $m): ?>
                <tr id="m<?= (int) $m['id'] ?>" style="<?= (int) $m['enabled'] ? '' : 'opacity:.55' ?>">
                    <td><?= e($m['provider_name']) ?></td>
                    <td dir="ltr" style="text-align:start"><b><?= e($m['model']) ?></b><?= $m['label'] ? '<div class="a2-muted" style="font-size:12px">' . e($m['label']) . '</div>' : '' ?><?= $m['source'] === 'manual' ? ' <span class="chip chip-line">يدوي</span>' : '' ?></td>
                    <td><?= e(AIM_TYPES[$m['type']] ?? $m['type']) ?></td>
                    <td><span class="chip <?= $tierChip[$m['tier']] ?>"><?= e(AIM_TIERS[$m['tier']]) ?></span></td>
                    <td class="num" dir="ltr"><?= $price($m['price_in_1m']) ?> / <?= $price($m['price_out_1m']) ?> / <?= $price($m['price_cached_1m']) ?><?= $m['price_image'] !== null ? '<br>' . $price($m['price_image']) . '/img' : '' ?></td>
                    <td class="num"><?= $m['max_context'] ? a2n($m['max_context']) : '—' ?></td>
                    <td><?= (int) $m['supports_json'] ? '✓' : '—' ?> · <?= (int) $m['supports_cache'] ? '✓' : '—' ?> · <?= (int) $m['supports_vision'] ? '✓' : '—' ?></td>
                    <td><?= (int) $m['enabled'] ? '<span class="chip chip-mint">مفعّل</span>' : '<span class="chip chip-line">مقفول</span>' ?></td>
                    <td>
                        <details><summary class="btn sm ghost" style="list-style:none">تعديل</summary>
                            <form method="post" style="min-width:300px;margin-top:8px;display:grid;gap:6px">
                                <?= csrf_field() ?><input type="hidden" name="action" value="save_model"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <input class="input" name="label" value="<?= e((string) $m['label']) ?>" placeholder="اسم للعرض (اختياري)">
                                <div class="a2-row" style="gap:6px">
                                    <select class="input" name="type"><?php foreach (AIM_TYPES as $tk => $tl): ?><option value="<?= $tk ?>" <?= $m['type'] === $tk ? 'selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select>
                                    <select class="input" name="tier"><?php foreach (AIM_TIERS as $tk => $tl): ?><option value="<?= $tk ?>" <?= $m['tier'] === $tk ? 'selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="a2-row" style="gap:6px" dir="ltr">
                                    <input class="input" type="number" step="0.000001" min="0" name="price_in_1m" value="<?= e((string) $m['price_in_1m']) ?>" placeholder="in $/1M" title="سعر الدخل لكل مليون توكن">
                                    <input class="input" type="number" step="0.000001" min="0" name="price_out_1m" value="<?= e((string) $m['price_out_1m']) ?>" placeholder="out $/1M" title="سعر الخرج لكل مليون توكن">
                                    <input class="input" type="number" step="0.000001" min="0" name="price_cached_1m" value="<?= e((string) $m['price_cached_1m']) ?>" placeholder="cached $/1M" title="سعر الدخل من الكاش">
                                </div>
                                <div class="a2-row" style="gap:6px" dir="ltr">
                                    <input class="input" type="number" step="0.000001" min="0" name="price_image" value="<?= e((string) $m['price_image']) ?>" placeholder="$/image">
                                    <input class="input" type="number" min="0" name="max_context" value="<?= e((string) $m['max_context']) ?>" placeholder="max context">
                                </div>
                                <div class="a2-row" style="gap:10px;flex-wrap:wrap;font-size:13px">
                                    <label><input type="checkbox" name="supports_json" value="1" <?= (int) $m['supports_json'] ? 'checked' : '' ?>> JSON</label>
                                    <label><input type="checkbox" name="supports_cache" value="1" <?= (int) $m['supports_cache'] ? 'checked' : '' ?>> كاش</label>
                                    <label><input type="checkbox" name="supports_vision" value="1" <?= (int) $m['supports_vision'] ? 'checked' : '' ?>> بيقرا صور</label>
                                    <label><input type="checkbox" name="enabled" value="1" <?= (int) $m['enabled'] ? 'checked' : '' ?>> مفعّل</label>
                                </div>
                                <div class="a2-row" style="gap:6px"><button class="btn sm" type="submit">حفظ</button>
                                    <button class="btn sm ghost" name="action" value="delete_model" formnovalidate data-confirm="يتشال من السجل؟">شيل</button></div>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>

        <details class="card" style="margin-top:14px">
            <summary style="cursor:pointer;font-weight:600">متقدم: إضافة موديل يدوي (لو المزود مابيرجّعهوش)</summary>
            <form method="post" data-safe-post class="a2-row" style="gap:8px;flex-wrap:wrap;margin-top:10px">
                <?= csrf_field() ?><input type="hidden" name="action" value="add_manual">
                <select class="input" name="provider_name" style="width:auto"><?php foreach ($providers as $p): ?><option value="<?= e($p['name']) ?>"><?= e($p['label']) ?></option><?php endforeach; ?></select>
                <input class="input" name="model" required placeholder="اسم الموديل بالظبط" dir="ltr" style="width:240px">
                <select class="input" name="type" style="width:auto"><?php foreach (AIM_TYPES as $tk => $tl): ?><option value="<?= $tk ?>"><?= e($tl) ?></option><?php endforeach; ?></select>
                <select class="input" name="tier" style="width:auto"><?php foreach (AIM_TIERS as $tk => $tl): ?><option value="<?= $tk ?>" <?= $tk === 'balanced' ? 'selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select>
                <button class="btn sm ghost" type="submit">إضافة</button>
            </form>
        </details>

        <?php elseif ($tab === 'stages'): ?>

        <form method="post" class="card" style="padding:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_stages">
            <div class="a2-h" style="padding:14px 16px 0"><h3>خريطة المراحل</h3><span class="a2-grow"></span><button class="btn sm" type="submit">حفظ الكل</button></div>
            <p class="a2-muted" style="font-size:12.5px;margin:6px 16px 10px;line-height:1.8">لكل مرحلة: موديل أساسي، وبديل بيتحوّل له تلقائي لو الأساسي وقع (انتهاء الوقت · 5xx · ضغط 429) والتحويل بيتسجل. القوايم فيها الموديلات المفعّلة اللي نوعها مناسب للمرحلة بس.
                «تلقائي» في الحرارة = حسب الكود اللي بينادي (السلوك الحالي).</p>
            <?php if (!$models): ?><div class="alert warning" style="margin:0 16px 14px">السجل فاضي — <a href="?tab=registry">اجلب الموديلات</a> الأول.</div><?php endif; ?>
            <div class="a2-tw" style="border:0"><table class="a2-tbl"><thead><tr><th>المرحلة</th><th>القدرة</th><th>الموديل الأساسي</th><th>البديل</th><th>الحرارة</th></tr></thead><tbody>
            <?php $grp = null; foreach ($stages as $st): $task = $st['task']; $g = $st['stage_group'] === 'engine' ? 'engine' : 'live';
                if ($g !== $grp): $grp = $g; ?>
                <tr><td colspan="5" style="background:#F6F8FB;font-weight:700"><?= $g === 'engine' ? 'محرك المحتوى v3 <span class="a2-muted" style="font-weight:400;font-size:12px">— بيتستخدم من المرحلة D وبعدها</span>' : 'المزايا الحالية <span class="a2-muted" style="font-weight:400;font-size:12px">— شغالة دلوقتي عند العملاء</span>' ?></td></tr>
            <?php endif; ?>
                <tr>
                    <td><b><?= e((string) ($st['label'] ?: $task)) ?></b><div class="a2-muted" dir="ltr" style="font-size:11.5px;text-align:start"><?= e($task) ?></div></td>
                    <td><?= e($st['needs_vision'] ? 'نص + صور' : ($capLbl[$st['capability']] ?? $st['capability'])) ?></td>
                    <td><select class="input" name="st[<?= e($task) ?>][primary]" style="min-width:220px"><?= $modelOpt($st, $st['primary_reg'], false) ?></select>
                        <?php if ($st['primary'] && !$st['primary_reg']): ?><div class="a2-muted" style="font-size:11.5px">الحالي من غير السجل: <span dir="ltr"><?= e($st['primary']['p'] . ' / ' . $st['primary']['m']) ?></span></div><?php endif; ?>
                        <?php if (!$st['primary']): ?><div class="a2-muted" style="font-size:11.5px">فاضي = حسب أولوية المزودين (السلوك القديم)</div><?php endif; ?></td>
                    <td><select class="input" name="st[<?= e($task) ?>][fallback]" style="min-width:200px"><?= $modelOpt($st, $st['fallback_reg'], true) ?></select>
                        <?php if ($st['extra_chain']): ?><div class="a2-muted" style="font-size:11.5px">+ <?= count($st['extra_chain']) ?> بديل إضافي من «الموديلات والـ Router»</div><?php endif; ?></td>
                    <td style="min-width:190px">
                        <?php if ($st['capability'] === 'text'): $t = $st['temperature'] !== null ? (float) $st['temperature'] : (AIM_DEFAULT_TEMP[$task] ?? 0.7); ?>
                            <div class="a2-row" style="gap:6px">
                                <input type="range" min="0" max="1.5" step="0.05" name="st[<?= e($task) ?>][temp]" value="<?= $t ?>" style="flex:1" oninput="this.nextElementSibling.textContent=(+this.value).toFixed(2)" <?= $st['temperature'] === null ? 'disabled' : '' ?>>
                                <b style="width:34px;direction:ltr"><?= number_format($t, 2) ?></b>
                            </div>
                            <label style="font-size:12px"><input type="checkbox" name="st[<?= e($task) ?>][auto]" value="1" <?= $st['temperature'] === null ? 'checked' : '' ?> onchange="this.closest('td').querySelector('input[type=range]').disabled=this.checked"> تلقائي</label>
                        <?php else: ?><span class="a2-muted">—</span><input type="hidden" name="st[<?= e($task) ?>][auto]" value="1"><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <div style="padding:12px 16px"><button class="btn" type="submit">حفظ الكل</button></div>
        </form>

        <?php else: /* presets */ ?>

        <div class="a2-grid a2-g3">
            <?php foreach (AIM_TIERS as $pk => $pl): $map = aim_preset_build($pk); $prow = db_one('SELECT * FROM ai_model_presets WHERE preset = ?', [$pk]) ?: []; $saved = !empty($prow['map_json']); ?>
            <div class="card">
                <div class="a2-h"><h3><?= e($pl) ?></h3><span class="chip <?= $tierChip[$pk] ?>"><?= $saved ? 'محفوظ' : 'تلقائي' ?></span></div>
                <p class="a2-muted" style="font-size:12.5px;line-height:1.8;margin:0 0 8px">
                    <?= $pk === 'economy' ? 'أرخص موديلات كويسة لكل مرحلة.' : ($pk === 'balanced' ? 'جودة وسعر متوازنين — الاختيار الافتراضي.' : 'أقوى الموديلات المتاحة، أغلى.') ?>
                    الناقد بيتختار من مزود غير الكاتب لو متاح.
                    <?= $prow['applied_at'] ?? null ? '<br>آخر تطبيق: ' . e($prow['applied_at']) : '' ?></p>
                <div style="max-height:320px;overflow:auto">
                <?php foreach ($stages as $st): $e = $map[$st['task']] ?? null; $pm = $e && $e['model_id'] ? ($byId[$e['model_id']] ?? null) : null; $fm = $e && !empty($e['fallback_id']) ? ($byId[$e['fallback_id']] ?? null) : null; ?>
                    <div class="a2-kv" style="font-size:12.5px;padding:5px 0">
                        <span><?= e((string) ($st['label'] ?: $st['task'])) ?></span>
                        <b style="font-weight:500;direction:ltr;text-align:end"><?= $pm ? e($pm['model']) : '<span class="a2-muted">—</span>' ?><?= $fm ? '<span class="a2-muted"> ↳ ' . e($fm['model']) . '</span>' : '' ?><?= $e && $e['temperature'] !== null ? '<span class="a2-muted"> · ' . number_format((float) $e['temperature'], 2) . '</span>' : '' ?></b>
                    </div>
                <?php endforeach; ?>
                </div>
                <form method="post" class="a2-row" style="gap:6px;margin-top:10px;flex-wrap:wrap">
                    <?= csrf_field() ?><input type="hidden" name="preset" value="<?= $pk ?>">
                    <button class="btn sm" name="action" value="preset_apply" data-confirm="ده هيغيّر موديلات كل المراحل (ومنها المزايا الشغالة عند العملاء) — متأكد؟">تطبيق</button>
                    <button class="btn sm ghost" name="action" value="preset_save">احفظ الخريطة الحالية هنا</button>
                    <?php if ($saved): ?><button class="btn sm ghost" name="action" value="preset_reset">رجّعه تلقائي</button><?php endif; ?>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="a2-muted" style="font-size:12.5px;margin-top:10px;line-height:1.8">«تلقائي» = بيتبني من مستوى كل موديل في السجل (اقتصادي · متوازن · جودة) من المزودين اللي ليهم مفتاح شغال. بعد التطبيق تقدر تعدّل أي مرحلة لوحدها من «خريطة المراحل».</p>

        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
