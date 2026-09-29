<?php
/**
 * Spread AI v2 — إعدادات Design Studio (المرحلة ⑤-ب)
 * تبويبات: الطرق (الأساسية + طرق جديدة بتفاصيلها) · المقاسات
 * كل فورم لعنصر واحد (data-safe-post + decode_b64_fields) — أسماء الحقول بسيطة عشان فك الـ base64 يشتغل
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/studio-config.php';
require_once __DIR__ . '/../includes/uploader.php';
require_once __DIR__ . '/../includes/trends.php';

require_admin();
require_admin_can('site_settings');

$tab = in_array($_GET['tab'] ?? '', ['methods', 'sizes', 'trends'], true) ? $_GET['tab'] : 'methods';
$presets = studio_method_presets();
$back = fn(string $t, string $extra = '') => redirect('admin/studio-settings.php?tab=' . $t . $extra);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $a = $_POST['action'] ?? '';

    /* ═══ الطرق ═══ */
    if ($a === 'method_save') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = $id ? db_one('SELECT * FROM studio_methods WHERE id = ?', [$id]) : null;
        $isBuilt = $row && $row['kind'] === 'built_in';
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 80);
        if ($title === '') { flash_set('danger', 'اكتب اسم الطريقة'); $back('methods', $id ? '&edit=' . $id : '&new=1'); }

        $old = $row ? (json_decode((string) $row['config'], true) ?: []) : [];
        $ratios = design_ratios();
        $cfg = $old;
        $cfg['ratio'] = array_key_exists($_POST['ratio'] ?? '', $ratios) ? $_POST['ratio'] : (string) array_key_first($ratios);
        $cfg['brief'] = !empty($_POST['brief']);
        $purposes = array_values(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 40), preg_split('/\r?\n/', (string) ($_POST['purposes'] ?? '')))));
        if (!$isBuilt || ($row['mkey'] ?? '') === 'from_image') $cfg['purposes'] = array_slice($purposes, 0, 10);

        if (!$isBuilt) {
            // المحرك نفسه — للطرق الجديدة بس
            $cfg['text'] = ['label' => mb_substr(trim((string) ($_POST['text_label'] ?? '')), 0, 60),
                            'placeholder' => mb_substr(trim((string) ($_POST['text_placeholder'] ?? '')), 0, 160),
                            'required' => !empty($_POST['text_required'])];
            $cfg['uploads'] = [];
            for ($i = 0; $i < 3; $i++) {
                $lbl = mb_substr(trim((string) ($_POST['up' . $i . '_label'] ?? '')), 0, 60);
                if ($lbl === '') continue;
                $cfg['uploads'][] = ['label' => $lbl, 'role' => mb_substr(trim((string) ($_POST['up' . $i . '_role'] ?? '')), 0, 400),
                                     'required' => !empty($_POST['up' . $i . '_required'])];
            }
            $cfg['prompt'] = mb_substr(trim((string) ($_POST['prompt'] ?? '')), 0, 3000);
            if ($cfg['prompt'] === '') { flash_set('danger', 'اكتب قالب البرومبت — ده قلب الطريقة'); $back('methods', $id ? '&edit=' . $id : '&new=1'); }
            if (!$cfg['uploads'] && $cfg['text']['label'] === '') {
                flash_set('danger', 'الطريقة محتاجة صورة أو خانة نصية على الأقل'); $back('methods', $id ? '&edit=' . $id : '&new=1');
            }
        }
        $vals = [
            $title,
            mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 300) ?: null,
            mb_substr(trim((string) ($_POST['badge'] ?? '')), 0, 30) ?: null,
            array_key_exists($_POST['icon'] ?? '', $presets['icons']) ? $_POST['icon'] : 'sparkles',
            array_key_exists($_POST['color'] ?? '', $presets['colors']) ? $_POST['color'] : 'blue',
            max(0, min(999, (int) ($_POST['sort_order'] ?? 100))),
            !empty($_POST['is_active']) ? 1 : 0,
            json_encode($cfg, JSON_UNESCAPED_UNICODE),
        ];
        if ($row) {
            db_run('UPDATE studio_methods SET title=?, description=?, badge=?, icon=?, color=?, sort_order=?, is_active=?, config=? WHERE id=?',
                array_merge($vals, [$row['id']]));
        } else {
            $key = 'custom_' . substr(bin2hex(random_bytes(4)), 0, 8);
            $id = db_insert('INSERT INTO studio_methods (title, description, badge, icon, color, sort_order, is_active, config, mkey, kind)
                             VALUES (?,?,?,?,?,?,?,?,?, "custom")', array_merge($vals, [$key]));
        }
        flash_set('success', 'اتحفظت الطريقة ✓');
        $back('methods');
    }
    if ($a === 'method_toggle') {
        db_run('UPDATE studio_methods SET is_active = 1 - is_active WHERE id = ?', [(int) $_POST['id']]);
        $back('methods');
    }
    if ($a === 'method_delete') {
        $n = db()->prepare('DELETE FROM studio_methods WHERE id = ? AND kind = "custom"');
        $n->execute([(int) $_POST['id']]);
        flash_set($n->rowCount() ? 'success' : 'danger', $n->rowCount() ? 'اتمسحت الطريقة' : 'الطرق الأساسية مابتتمسحش — تقدر تقفلها');
        $back('methods');
    }


    /* ═══ الترندات ═══ */
    $splitList = function (string $v, bool $arrow = false): array {
        $parts = preg_split($arrow ? '/\s*(?:→|->|>|←|\r?\n)\s*/u' : '/\s*(?:,|،|\r?\n)\s*/u', trim($v));
        return array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 60), $parts), fn($x) => $x !== '')), 0, 12);
    };
    if ($a === 'trend_save') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = $id ? db_one('SELECT * FROM trends WHERE id = ?', [$id]) : null;
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        if ($name === '') { flash_set('danger', 'اكتب اسم الترند'); $back('trends', $id ? '&edit=' . $id : '&new=1'); }
        $plats = []; foreach ((array) ($_POST['plat'] ?? []) as $i) { if (isset(trend_platforms()[(int) $i])) $plats[] = trend_platforms()[(int) $i]; }
        $inds = [];  foreach ((array) ($_POST['ind'] ?? []) as $i)  { if (isset(trend_industries()[(int) $i])) $inds[] = trend_industries()[(int) $i]; }
        $structure = $splitList((string) ($_POST['structure'] ?? ''), true);
        if (count($structure) < 2) { flash_set('danger', 'هيكل الترند محتاج خطوتين على الأقل (افصل بـ →)'); $back('trends', $id ? '&edit=' . $id : '&new=1'); }
        $ref = $row['ref_image_path'] ?? null;
        if (!empty($_FILES['ref']['name'])) {
            $up = upload_image($_FILES['ref'], 'trends');
            if (!$up['ok']) { flash_set('danger', 'صورة الترند: ' . $up['error']); $back('trends', $id ? '&edit=' . $id : '&new=1'); }
            $ref = $up['path'];
        }
        if (!empty($_POST['ref_remove'])) $ref = null;
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['start'] ?? '') ? $_POST['start'] : null;
        $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['end'] ?? '') ? $_POST['end'] : null;
        if ($start && $end && $end < $start) { flash_set('danger', 'تاريخ الانتهاء قبل البداية'); $back('trends', $id ? '&edit=' . $id : '&new=1'); }
        $j = fn($a) => json_encode($a, JSON_UNESCAPED_UNICODE);
        $vals = [$name, in_array($_POST['type'] ?? '', trend_types(), true) ? $_POST['type'] : 'Visual', $j($plats), $j($inds),
                 mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 1000), mb_substr(trim((string) ($_POST['adapt'] ?? '')), 0, 1000),
                 $j($structure), $j($splitList((string) ($_POST['visual'] ?? ''))), $j($splitList((string) ($_POST['tone'] ?? ''))),
                 $j($splitList((string) ($_POST['allowed'] ?? ''))), $j($splitList((string) ($_POST['locked'] ?? ''))),
                 mb_substr(trim((string) ($_POST['prompt_template'] ?? '')), 0, 3000) ?: null, $ref,
                 ($_POST['status'] ?? '') === 'hidden' ? 'hidden' : 'active', $start, $end, max(0, min(999, (int) ($_POST['sort_order'] ?? 100)))];
        if ($row) {
            db_run('UPDATE trends SET name=?, type=?, platforms=?, industries=?, description=?, adapt=?, structure=?, visual=?, tone=?, allowed=?, locked=?,
                    prompt_template=?, ref_image_path=?, status=?, start_date=?, end_date=?, sort_order=? WHERE id=?', array_merge($vals, [$row['id']]));
        } else {
            // رقم الترند (T143…) — لو أدمن تاني خده في نفس اللحظة، بنجرّب الرقم اللي بعده (unique key)
            for ($try = 0; $try < 3; $try++) {
                $next = (int) (db_one('SELECT COALESCE(MAX(CAST(SUBSTRING(code, 2) AS UNSIGNED)), 100) n FROM trends WHERE code REGEXP "^T[0-9]+$"')['n'] ?? 100) + 1 + $try;
                try {
                    db_insert('INSERT INTO trends (name, type, platforms, industries, description, adapt, structure, visual, tone, allowed, locked,
                               prompt_template, ref_image_path, status, start_date, end_date, sort_order, code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                               array_merge($vals, ['T' . $next]));
                    break;
                } catch (\PDOException $e) {
                    if ($try === 2 || strpos($e->getMessage(), 'Duplicate') === false) throw $e;
                }
            }
        }
        flash_set('success', 'اتحفظ الترند ✓');
        $back('trends');
    }
    if ($a === 'trend_toggle') {
        db_run('UPDATE trends SET status = IF(status = "active", "hidden", "active") WHERE id = ?', [(int) $_POST['id']]);
        $back('trends', '&f=' . urlencode((string) ($_POST['f'] ?? '')));
    }
    if ($a === 'trend_delete') {
        db_run('DELETE FROM trends WHERE id = ?', [(int) $_POST['id']]);
        flash_set('success', 'اتمسح الترند');
        $back('trends');
    }
    if ($a === 'trend_restore') {
        $n = trends_seed(true);
        flash_set('success', 'رجعت الترندات الافتراضية (' . $n . ') — ترنداتك التانية زي ما هي');
        $back('trends');
    }

    /* ═══ المقاسات ═══ */
    $all = array_values(array_map(fn($r) => ['w' => $r['w'], 'h' => $r['h'], 'label' => $r['label'], 'hint' => $r['hint'],
                                                'keywords' => $r['keywords'], 'active' => $r['active']], studio_ratios_all()));
    $keys = array_keys(studio_ratios_all());
    if ($a === 'size_save') {
        $w = (int) ($_POST['w'] ?? 0); $h = (int) ($_POST['h'] ?? 0);
        if ($w < 256 || $h < 256 || $w > 4096 || $h > 4096 || max($w, $h) / min($w, $h) > 4) {
            flash_set('danger', 'الأبعاد لازم تكون بين 256 و4096، والنسبة مش أكتر من 1:4');
            $back('sizes');
        }
        $item = ['w' => $w, 'h' => $h, 'label' => mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 40) ?: studio_ratio_key($w, $h),
                 'hint' => mb_substr(trim((string) ($_POST['hint'] ?? '')), 0, 60),
                 'keywords' => mb_substr(trim((string) ($_POST['keywords'] ?? '')), 0, 200), 'active' => !empty($_POST['active']) ? 1 : 0];
        $idx = ($_POST['idx'] ?? '') === '' ? -1 : (int) $_POST['idx'];
        $newKey = studio_ratio_key($w, $h);
        foreach ($keys as $i => $k) {
            if ($k === $newKey && $i !== $idx) { flash_set('danger', 'المقاس ' . $newKey . ' موجود قبل كده'); $back('sizes'); }
        }
        if ($idx >= 0 && isset($all[$idx])) $all[$idx] = $item; else $all[] = $item;
        if (!array_filter($all, fn($r) => $r['active'])) { flash_set('danger', 'لازم يفضل مقاس واحد شغال على الأقل'); $back('sizes'); }
        studio_ratios_save($all);
        flash_set('success', 'اتحفظ المقاس ' . $newKey . ' ✓');
        $back('sizes');
    }
    if ($a === 'size_delete') {
        $idx = (int) ($_POST['idx'] ?? -1);
        if (isset($all[$idx])) {
            array_splice($all, $idx, 1);
            if (!array_filter($all, fn($r) => $r['active'])) { flash_set('danger', 'لازم يفضل مقاس واحد شغال على الأقل'); $back('sizes'); }
            studio_ratios_save($all);
            flash_set('success', 'اتمسح المقاس');
        }
        $back('sizes');
    }
    if ($a === 'size_reset') {
        set_setting('design_ratios_json', '');
        flash_set('success', 'رجعت المقاسات الافتراضية');
        $back('sizes');
    }
}

$methods = studio_methods(false);
$editing = null;
if ($tab === 'methods' && isset($_GET['edit'])) {
    foreach ($methods as $m) if ($m['id'] === (int) $_GET['edit']) $editing = $m;
}
$isNew = $tab === 'methods' && isset($_GET['new']);
$ratiosAll = studio_ratios_all();

$active = 'studio-settings';
$page_title = 'إعدادات Design Studio';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>🎨 إعدادات Design Studio</h1>
            <div class="sub">طرق التصميم اللي بتظهر للعميل وتفاصيلها · المقاسات</div>
        </div>
        <?= render_flash() ?>

        <div class="seg" style="margin-bottom:16px">
            <a href="?tab=methods" class="<?= $tab === 'methods' ? 'on' : '' ?>" style="padding:8px 18px;text-decoration:none">الطرق (<?= count($methods) ?>)</a>
            <a href="?tab=sizes" class="<?= $tab === 'sizes' ? 'on' : '' ?>" style="padding:8px 18px;text-decoration:none">المقاسات (<?= count($ratiosAll) ?>)</a>
            <a href="?tab=trends" class="<?= $tab === 'trends' ? 'on' : '' ?>" style="padding:8px 18px;text-decoration:none">🔥 الترندات</a>
        </div>

        <?php if ($tab === 'methods' && ($editing || $isNew)):
            $m = $editing ?: ['id' => 0, 'kind' => 'custom', 'key' => '', 'title' => '', 'description' => '', 'badge' => '', 'icon' => 'sparkles',
                              'color' => 'blue', 'sort' => 100, 'active' => 1, 'ratio' => '1:1', 'brief' => true, 'purposes' => [],
                              'text' => ['label' => 'اكتب فكرتك', 'placeholder' => '', 'required' => false], 'uploads' => [], 'prompt' => ''];
            $built = $m['kind'] === 'built_in';
        ?>
        <!-- ═══ تعديل / طريقة جديدة ═══ -->
        <div class="card">
            <div class="card-head"><h3><?= $isNew ? '＋ طريقة جديدة' : 'تعديل: ' . e($m['title']) ?>
                <?php if ($built): ?><span class="badge">أساسية — المحرك ثابت</span><?php endif; ?></h3></div>
            <form method="POST" data-safe-post>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="method_save">
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">

                <h4 style="margin:4px 0 10px">① اللي العميل بيشوفه</h4>
                <div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="field"><label>الاسم</label><input class="input" name="title" required maxlength="80" value="<?= e($m['title']) ?>"></div>
                    <div class="field"><label>شارة (اختياري)</label><input class="input" name="badge" maxlength="30" value="<?= e($m['badge']) ?>" placeholder="مميز · جديد"></div>
                </div>
                <div class="field"><label>الوصف</label><input class="input" name="description" maxlength="300" value="<?= e($m['description']) ?>"></div>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
                    <div class="field"><label>الأيقونة</label><select class="select" name="icon">
                        <?php foreach ($presets['icons'] as $k => $l): ?><option value="<?= $k ?>" <?= $m['icon'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>اللون</label><select class="select" name="color">
                        <?php foreach ($presets['colors'] as $k => $l): ?><option value="<?= $k ?>" <?= $m['color'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>الترتيب</label><input class="input" type="number" name="sort_order" value="<?= (int) $m['sort'] ?>" min="0" max="999"></div>
                    <div class="field"><label>المقاس الافتراضي</label><select class="select" name="ratio">
                        <?php foreach (design_ratios() as $k => $r): ?><option value="<?= e($k) ?>" <?= $m['ratio'] === $k ? 'selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach; ?></select></div>
                </div>
                <label style="display:flex;gap:8px;align-items:center;margin:6px 0"><input type="checkbox" name="is_active" value="1" <?= $m['active'] ? 'checked' : '' ?>> ظاهرة للعملاء</label>
                <label style="display:flex;gap:8px;align-items:center;margin:6px 0"><input type="checkbox" name="brief" value="1" <?= $m['brief'] ? 'checked' : '' ?>> فيها خطوة Creative Brief بالـ AI قبل التوليد</label>

                <?php if (!$built || $m['key'] === 'from_image'): ?>
                <div class="field"><label>الاختيارات («تحب نعمل إيه؟») — كل سطر اختيار</label>
                    <textarea class="textarea" name="purposes" rows="4" placeholder="إعلان منتج&#10;عرض أو خصم"><?= e(implode("\n", $m['purposes'])) ?></textarea></div>
                <?php endif; ?>

                <?php if (!$built): ?>
                <h4 style="margin:18px 0 10px">② المطلوب من العميل</h4>
                <div style="display:grid;grid-template-columns:2fr 3fr auto;gap:12px;align-items:end">
                    <div class="field"><label>عنوان الخانة النصية (فاضي = مفيش خانة)</label><input class="input" name="text_label" maxlength="60" value="<?= e($m['text']['label']) ?>"></div>
                    <div class="field"><label>مثال جوه الخانة</label><input class="input" name="text_placeholder" maxlength="160" value="<?= e($m['text']['placeholder']) ?>"></div>
                    <label style="display:flex;gap:6px;align-items:center;margin-bottom:12px"><input type="checkbox" name="text_required" value="1" <?= $m['text']['required'] ? 'checked' : '' ?>> إجباري</label>
                </div>
                <p class="field-help">الصور المطلوبة (لحد 3) — و«دورها» بيتقال للـ AI بالإنجليزي: يعمل بيها إيه بالظبط.</p>
                <?php for ($i = 0; $i < 3; $i++): $u = $m['uploads'][$i] ?? ['label' => '', 'role' => '', 'required' => false]; ?>
                <div style="display:grid;grid-template-columns:1.2fr 3fr auto;gap:12px;align-items:end">
                    <div class="field"><label>صورة <?= $i + 1 ?> — الاسم للعميل</label><input class="input" name="up<?= $i ?>_label" maxlength="60" value="<?= e($u['label']) ?>" placeholder="<?= $i ? '' : 'صورة المنتج' ?>"></div>
                    <div class="field"><label>دورها للـ AI</label><input class="input" name="up<?= $i ?>_role" maxlength="400" dir="ltr" value="<?= e($u['role']) ?>"
                        placeholder="<?= $i ? '' : 'the PRODUCT photo — make it the hero, keep it exactly as is' ?>"></div>
                    <label style="display:flex;gap:6px;align-items:center;margin-bottom:12px"><input type="checkbox" name="up<?= $i ?>_required" value="1" <?= $u['required'] ? 'checked' : '' ?>> إجباري</label>
                </div>
                <?php endfor; ?>

                <h4 style="margin:18px 0 10px">③ قالب البرومبت (قلب الطريقة)</h4>
                <div class="field">
                    <textarea class="textarea" name="prompt" rows="6" dir="auto" required placeholder="Create a Ramadan greeting design for {brand}. {text}. Style: {purpose}."><?= e($m['prompt']) ?></textarea>
                    <p class="field-help">
                        بيتبدّل تلقائيًا: <code>{text}</code> كلام العميل (أو الـ Brief لو مفعّل) · <code>{purpose}</code> الاختيار · <code>{brand}</code> اسم البراند.
                        <b>الهوية (الألوان · اللوجو · قواعد التصميم) والمقاس بيتضافوا لوحدهم</b> — ماتكتبهمش.
                    </p>
                </div>
                <?php endif; ?>

                <div style="display:flex;gap:8px;margin-top:12px">
                    <button class="btn">حفظ</button>
                    <a href="?tab=methods" class="btn ghost">إلغاء</a>
                </div>
            </form>
        </div>

        <?php elseif ($tab === 'methods'): ?>
        <!-- ═══ قايمة الطرق ═══ -->
        <div class="card">
            <div class="card-head" style="display:flex;justify-content:space-between;align-items:center">
                <h3>الطرق بالترتيب اللي العميل بيشوفه</h3>
                <a href="?tab=methods&new=1" class="btn sm">＋ طريقة جديدة</a>
            </div>
            <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>الطريقة</th><th>النوع</th><th>المطلوب</th><th>الحالة</th><th></th></tr></thead><tbody>
            <?php foreach ($methods as $m): ?>
                <tr style="<?= $m['active'] ? '' : 'opacity:.55' ?>">
                    <td><?= (int) $m['sort'] ?></td>
                    <td><b><?= e($m['title']) ?></b> <?= $m['badge'] ? '<span class="badge">' . e($m['badge']) . '</span>' : '' ?>
                        <div class="sub" style="font-size:12px"><?= e($m['description']) ?></div></td>
                    <td><?= $m['kind'] === 'built_in' ? 'أساسية' : 'جديدة' ?></td>
                    <td class="sub" style="font-size:12px"><?php
                        if ($m['kind'] === 'custom') {
                            $req = [];
                            foreach ($m['uploads'] as $u) $req[] = '📷 ' . $u['label'];
                            if ($m['text']['label'] !== '') $req[] = '✎ ' . $m['text']['label'];
                            echo e(implode(' · ', $req)) . ($m['brief'] ? ' · Brief' : '');
                        } else { echo $m['key'] === 'trend' ? 'من لوحة الترندات' : '—'; }
                    ?></td>
                    <td>
                        <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="method_toggle"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <button class="btn ghost sm"><?= $m['active'] ? '✓ ظاهرة' : '⏸ مقفولة' ?></button></form>
                    </td>
                    <td style="white-space:nowrap">
                        <a href="?tab=methods&edit=<?= $m['id'] ?>" class="btn ghost sm">تعديل</a>
                        <?php if ($m['kind'] === 'custom'): ?>
                        <form method="POST" style="display:inline" data-confirm="تمسح الطريقة «<?= e($m['title']) ?>»؟"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="method_delete"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <button class="btn ghost sm" style="color:#c0392b">مسح</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <p class="field-help" style="margin-top:10px">مثال لطريقة جديدة: «تهنئة مناسبة» — خانة «اسم المناسبة» + اختيارات (رمضان · العيد · رأس السنة) + قالب: <code>Create an elegant {purpose} greeting design for {brand}. {text}</code></p>
        </div>


        <?php elseif ($tab === 'trends'):
            $allTrends = trends_all();
            $cnt = ['active' => 0, 'hidden' => 0, 'expired' => 0, 'scheduled' => 0];
            foreach ($allTrends as $t) $cnt[$t['effective']]++;
            $f = in_array($_GET['f'] ?? '', ['active', 'hidden', 'expired', 'scheduled'], true) ? $_GET['f'] : '';
            $tEdit = null;
            if (isset($_GET['edit'])) foreach ($allTrends as $t) if ($t['id'] === (int) $_GET['edit']) $tEdit = $t;
            $tNew = isset($_GET['new']);
            $stLbl = ['active' => ['Active', '#10A8A0'], 'hidden' => ['Hidden', '#8391A6'], 'expired' => ['Expired', '#E0526A'], 'scheduled' => ['قريبًا', '#D98A1F']];
        ?>
        <?php if ($tEdit || $tNew):
            $t = $tEdit ?: ['id' => 0, 'code' => '', 'name' => '', 'type' => 'Visual', 'platforms' => ['Instagram', 'Facebook'], 'industries' => [],
                            'description' => '', 'adapt' => '', 'structure' => [], 'visual' => [], 'tone' => [], 'allowed' => [], 'locked' => [],
                            'prompt_template' => '', 'ref' => null, 'status' => 'active', 'start' => date('Y-m-d'), 'end' => date('Y-m-d', strtotime('+30 days')), 'sort' => 100];
        ?>
        <div style="display:grid;grid-template-columns:minmax(0,1.6fr) minmax(260px,1fr);gap:16px;align-items:start">
        <div class="card">
            <div class="card-head"><h3><?= $tNew ? '＋ ترند جديد' : 'تعديل #' . e($t['code']) ?></h3></div>
            <form method="POST" enctype="multipart/form-data" data-safe-post>
                <?= csrf_field() ?><input type="hidden" name="action" value="trend_save"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <div class="field"><label>Trend Name</label><input class="input" name="name" required maxlength="120" value="<?= e($t['name']) ?>"></div>
                <div class="field"><label>Upload Reference — صورة الترند الأصلي</label>
                    <?php if ($t['ref']): ?><div style="display:flex;gap:10px;align-items:center;margin-bottom:6px">
                        <img src="<?= e($t['ref']) ?>" alt="" style="height:70px;border-radius:10px">
                        <label><input type="checkbox" name="ref_remove" value="1"> شيل الصورة</label></div><?php endif; ?>
                    <input type="file" name="ref" accept="image/*" class="input">
                    <p class="field-help">الـ AI بياخد منها <b>الهيكل والتكوين بس</b> — مش المحتوى ولا الأشخاص ولا البراند.</p></div>
                <div class="field"><label>Trend Type</label><div style="display:flex;gap:10px;flex-wrap:wrap">
                    <?php foreach (trend_types() as $ty): ?><label><input type="radio" name="type" value="<?= $ty ?>" <?= $t['type'] === $ty ? 'checked' : '' ?>> <?= $ty ?></label><?php endforeach; ?>
                </div><p class="field-help">Reel ← بيطلع غلاف + سكريبت مشاهد (الاستوديو بيعمل صور، مش فيديو)</p></div>
                <div class="field"><label>Platforms</label><div style="display:flex;gap:10px;flex-wrap:wrap">
                    <?php foreach (trend_platforms() as $i => $pl): ?><label><input type="checkbox" name="plat[]" value="<?= $i ?>" <?= in_array($pl, $t['platforms'], true) ? 'checked' : '' ?>> <?= $pl ?></label><?php endforeach; ?></div></div>
                <div class="field"><label>Suitable Industries</label><div style="display:flex;gap:10px;flex-wrap:wrap">
                    <?php foreach (trend_industries() as $i => $in): ?><label><input type="checkbox" name="ind[]" value="<?= $i ?>" <?= in_array($in, $t['industries'], true) ? 'checked' : '' ?>> <?= e($in) ?></label><?php endforeach; ?></div></div>
                <div class="field"><label>Trend Description</label><textarea class="textarea" name="description" rows="2" maxlength="1000"><?= e($t['description']) ?></textarea></div>
                <div class="field"><label>How to Adapt — إزاي الـ AI يطبّقه على أي براند</label><textarea class="textarea" name="adapt" rows="2" maxlength="1000"><?= e($t['adapt']) ?></textarea></div>
                <h4 style="margin:16px 0 8px">Trend Blueprint</h4>
                <div class="field"><label>Trend Structure (افصل بـ →)</label><input class="input" name="structure" required value="<?= e(implode(' → ', $t['structure'])) ?>" placeholder="Hook → مفاجأة → كشف المنتج → CTA"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="field"><label>Visual Structure (افصل بـ ،)</label><input class="input" name="visual" value="<?= e(implode('، ', $t['visual'])) ?>"></div>
                    <div class="field"><label>Tone</label><input class="input" name="tone" value="<?= e(implode('، ', $t['tone'])) ?>"></div>
                    <div class="field"><label>✓ مسموح تغييره</label><input class="input" name="allowed" value="<?= e(implode('، ', $t['allowed'])) ?>"></div>
                    <div class="field"><label>🔒 لا يتم تغييره</label><input class="input" name="locked" value="<?= e(implode('، ', $t['locked'])) ?>"></div>
                </div>
                <div class="field"><label>Prompt Template (اختياري)</label>
                    <textarea class="textarea" name="prompt_template" rows="3" dir="auto" maxlength="3000" placeholder="فاضي = الـ AI بيبني البرومبت من الـ Blueprint لوحده"><?= e($t['prompt_template']) ?></textarea>
                    <p class="field-help">بيتبدّل: <code>{idea}</code> الفكرة اللي العميل اختارها · <code>{brand}</code> اسم البراند · <code>{structure}</code> الهيكل</p></div>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
                    <div class="field"><label>Start Date</label><input class="input" type="date" name="start" value="<?= e((string) $t['start']) ?>"></div>
                    <div class="field"><label>End Date</label><input class="input" type="date" name="end" value="<?= e((string) $t['end']) ?>"></div>
                    <div class="field"><label>Status</label><select class="select" name="status">
                        <option value="active" <?= $t['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="hidden" <?= $t['status'] === 'hidden' ? 'selected' : '' ?>>Hidden</option></select></div>
                    <div class="field"><label>الترتيب</label><input class="input" type="number" name="sort_order" value="<?= (int) $t['sort'] ?>" min="0" max="999"></div>
                </div>
                <div style="display:flex;gap:8px;margin-top:10px"><button class="btn">حفظ الترند</button><a href="?tab=trends" class="btn ghost">إلغاء</a></div>
            </form>
        </div>
        <div class="card" style="position:sticky;top:16px">
            <div class="card-head"><h3>معاينة الـ Blueprint</h3></div>
            <?php if ($t['ref']): ?><img src="<?= e($t['ref']) ?>" alt="" style="width:100%;border-radius:14px;margin-bottom:10px"><?php endif; ?>
            <b style="font-size:16px"><?= e($t['name'] ?: 'اسم الترند') ?></b> <span class="badge"><?= e($t['type']) ?></span>
            <p class="sub" style="font-size:13px"><?= e($t['description']) ?></p>
            <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin:8px 0">
                <?php foreach ($t['structure'] as $i => $st): ?><?= $i ? '<span class="sub">←</span>' : '' ?><span class="badge" style="background:var(--primary-soft);color:var(--primary-deep)"><?= e($st) ?></span><?php endforeach; ?></div>
            <p style="font-size:12.5px;margin:4px 0"><b>مناسب لـ:</b> <?= e(implode(' · ', $t['industries']) ?: '—') ?></p>
            <p style="font-size:12.5px;margin:4px 0;color:#0b6b63"><b>مسموح:</b> <?= e(implode(' · ', $t['allowed']) ?: '—') ?></p>
            <p style="font-size:12.5px;margin:4px 0;color:#b0304a"><b>ثابت:</b> <?= e(implode(' · ', $t['locked']) ?: '—') ?></p>
            <div class="alert info" style="margin-top:10px;font-size:12.5px"><b>إزاي بيوصل للمستخدم؟</b> الـ AI بيقرا الـ Blueprint + Brand Brain للمستخدم، ويطلع 3 أفكار مخصصة للبراند بنفس هيكل الترند — من غير ما ينسخ الأصل. الترندات الـ Active بس بتظهر في Design Studio.</div>
        </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <h3>إدارة الترندات</h3>
                <div style="display:flex;gap:8px">
                    <form method="POST" data-confirm="ترجّع الترندات الافتراضية لحالتها الأصلية؟ ترنداتك التانية مش هتتلمس."><?= csrf_field() ?>
                        <input type="hidden" name="action" value="trend_restore"><button class="btn ghost sm">استرجاع الافتراضي</button></form>
                    <a href="?tab=trends&new=1" class="btn sm">＋ إضافة Trend</a>
                    <a href="<?= url('design-studio.php') ?>" class="btn ghost sm" target="_blank">فتح Design Studio ←</a>
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
                <a href="?tab=trends" class="badge" style="<?= $f === '' ? 'background:var(--ink);color:#fff' : '' ?>">الكل (<?= count($allTrends) ?>)</a>
                <?php foreach ($stLbl as $k => [$l, $c]): if (!$cnt[$k] && $k === 'scheduled') continue; ?>
                    <a href="?tab=trends&f=<?= $k ?>" class="badge" style="<?= $f === $k ? "background:{$c};color:#fff" : "color:{$c}" ?>"><?= $l ?> (<?= $cnt[$k] ?>)</a>
                <?php endforeach; ?>
            </div>
            <div class="table-wrap"><table class="table"><thead><tr><th></th><th>الترند</th><th>النوع</th><th>الحالة</th><th>مناسب لـ</th><th>الفترة</th><th></th></tr></thead><tbody>
            <?php foreach ($allTrends as $t): if ($f && $t['effective'] !== $f) continue; [$sl, $sc] = $stLbl[$t['effective']]; ?>
                <tr>
                    <td><?php if ($t['ref']): ?><img src="<?= e($t['ref']) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px"><?php else: ?><span class="sub">—</span><?php endif; ?></td>
                    <td><span class="sub" dir="ltr">#<?= e($t['code']) ?></span> <b><?= e($t['name']) ?></b><div class="sub" style="font-size:12px"><?= e($t['description']) ?></div></td>
                    <td><?= e($t['type']) ?></td>
                    <td><span class="badge" style="color:<?= $sc ?>"><?= $sl ?></span></td>
                    <td class="sub" style="font-size:12px"><?= e(implode(' · ', $t['industries'])) ?></td>
                    <td class="sub" style="font-size:12px;white-space:nowrap" dir="ltr"><?= e((string) $t['start']) ?> → <?= e((string) $t['end']) ?></td>
                    <td style="white-space:nowrap">
                        <a href="?tab=trends&edit=<?= $t['id'] ?>" class="btn ghost sm">تعديل</a>
                        <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="trend_toggle"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>">
                            <button class="btn ghost sm"><?= $t['status'] === 'active' ? 'إخفاء' : 'إظهار' ?></button></form>
                        <form method="POST" style="display:inline" data-confirm="تمسح «<?= e($t['name']) ?>»؟"><?= csrf_field() ?><input type="hidden" name="action" value="trend_delete"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button class="btn ghost sm" style="color:#c0392b">مسح</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <p class="field-help" style="margin-top:10px">Expired = عدّى تاريخ الانتهاء (مابيظهرش للعملاء لوحده) · قريبًا = لسه تاريخ البداية ماجاش</p>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ═══ المقاسات ═══ -->
        <div class="card">
            <div class="card-head"><h3>المقاسات اللي بتظهر للعميل</h3></div>
            <div class="alert info" style="margin-bottom:12px">
                <b>المقاس المكتوب هو الأساسي:</b> لو العميل كتب في طلبه «1080×1350» أو «مقاس 4:5» أو كلمة من «الكلمات» (زي ستوري)، ده اللي بيتنفّذ — حتى لو اختار مقاس تاني، وحتى لو مش موجود هنا.
            </div>
            <!-- صفوف div مش جدول: <form> جوه <tr> بيطلع بره الجدول والخانات ماتتبعتش -->
            <div class="sz-head"><span></span><span>الاسم</span><span>العرض × الطول</span><span>الوصف</span><span>كلمات بتدل عليه</span><span>شغال</span><span></span></div>
            <?php $i = 0; foreach ($ratiosAll as $key => $r): $pw = 34; $ph = (int) round($pw * $r['h'] / $r['w']); if ($ph > 48) { $ph = 48; $pw = (int) round(48 * $r['w'] / $r['h']); } ?>
            <div class="sz-row">
                <form method="POST" data-safe-post class="sz-grid"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="size_save"><input type="hidden" name="idx" value="<?= $i ?>">
                    <span class="sz-prev"><i style="width:<?= $pw ?>px;height:<?= $ph ?>px"></i><small dir="ltr"><?= e($key) ?></small></span>
                    <input class="input" name="label" value="<?= e($r['label']) ?>" maxlength="40" aria-label="الاسم">
                    <span class="sz-wh" dir="ltr"><input class="input" type="number" name="w" value="<?= $r['w'] ?>" min="256" max="4096" aria-label="العرض"> ×
                        <input class="input" type="number" name="h" value="<?= $r['h'] ?>" min="256" max="4096" aria-label="الطول"></span>
                    <input class="input" name="hint" value="<?= e($r['hint']) ?>" maxlength="60" aria-label="الوصف">
                    <input class="input" name="keywords" value="<?= e($r['keywords']) ?>" maxlength="200" placeholder="ستوري, story" aria-label="كلمات">
                    <label class="sz-on"><input type="checkbox" name="active" value="1" <?= $r['active'] ? 'checked' : '' ?>></label>
                    <button class="btn ghost sm">حفظ</button>
                </form>
                <form method="POST" data-confirm="تمسح المقاس <?= e($key) ?>؟" class="sz-del"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="size_delete"><input type="hidden" name="idx" value="<?= $i ?>">
                    <button class="btn ghost sm" style="color:#c0392b" aria-label="مسح <?= e($key) ?>">✕</button></form>
            </div>
            <?php $i++; endforeach; ?>
            <div class="sz-row sz-new">
                <form method="POST" data-safe-post class="sz-grid"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="size_save"><input type="hidden" name="idx" value="">
                    <span class="sz-prev">＋</span>
                    <input class="input" name="label" maxlength="40" placeholder="أفقي 16:9">
                    <span class="sz-wh" dir="ltr"><input class="input" type="number" name="w" min="256" max="4096" placeholder="1920" required> ×
                        <input class="input" type="number" name="h" min="256" max="4096" placeholder="1080" required></span>
                    <input class="input" name="hint" maxlength="60" placeholder="يوتيوب/غلاف">
                    <input class="input" name="keywords" maxlength="200" placeholder="يوتيوب, youtube">
                    <label class="sz-on"><input type="checkbox" name="active" value="1" checked></label>
                    <button class="btn sm">إضافة</button>
                </form>
            </div>
            <style>
                .sz-head, .sz-grid { display: grid; grid-template-columns: 56px 1.2fr 200px 1fr 1.3fr 44px 64px; gap: 8px; align-items: center; }
                .sz-head { font-size: 12px; color: var(--mute); font-weight: 700; padding: 0 6px 6px; margin-inline-end: 44px; }
                .sz-row { display: flex; gap: 6px; align-items: center; padding: 6px; border-radius: 12px; border-bottom: 1px solid var(--line-2); }
                .sz-row .sz-grid { flex: 1; } .sz-new { background: var(--surface-2); margin-top: 6px; }
                .sz-new .sz-grid { margin-inline-end: 44px; }
                .sz-prev { display: flex; flex-direction: column; align-items: center; gap: 2px; }
                .sz-prev i { display: block; border-radius: 5px; background: linear-gradient(135deg, #2ee3cc, #0c87ef); }
                .sz-prev small { font-size: 10.5px; color: var(--mute); }
                .sz-wh { display: flex; align-items: center; gap: 4px; } .sz-wh .input { width: 84px; }
                .sz-on { display: flex; justify-content: center; } .sz-del { flex: none; width: 38px; }
                @media (max-width: 1100px) { .sz-head { display: none; } .sz-grid { grid-template-columns: 1fr 1fr; } }
            </style>
            <p class="field-help" style="margin-top:10px">مقاس OpenAI بيتحسب لوحده من الاتجاه (مربع · طولي · عرضي). المفتاح (4:5 · 9:16…) بيتحسب من الأبعاد.</p>
            <form method="POST" data-confirm="ترجّع المقاسات الافتراضية؟ التعديلات هتتمسح." style="margin-top:8px"><?= csrf_field() ?>
                <input type="hidden" name="action" value="size_reset"><button class="btn ghost sm">رجّع الافتراضي</button></form>
        </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
