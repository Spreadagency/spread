<?php
/**
 * الأدمن — مكتبة الـ Presets (Brand Kit)
 * استايلات تصميم · باليتات · نبرات · أنماط صور · أنماط شخصيات — كل واحد بصورة Preview والعميل بيختار منها
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploader.php';
require_once __DIR__ . '/../includes/brand-kit.php';

require_admin();
require_admin_can('ai_settings');
bk_ensure_schema();

$kinds = ['design_style' => 'استايل تصميم', 'palette' => 'باليتة ألوان', 'tone' => 'نبرة كلام', 'visual' => 'نمط صور', 'character_style' => 'نمط شخصية / ماسكوت'];
$pct = fn($v) => max(0, min(100, (int) $v));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    if (!empty($_POST['save'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $kind = array_key_exists($_POST['kind'] ?? '', $kinds) ? $_POST['kind'] : 'design_style';
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
        if ($name === '') { flash_set('danger', 'اكتب الاسم'); redirect('admin/style-presets.php'); }
        $data = match ($kind) {
            'palette' => ['colors' => array_values(array_filter(array_map(fn($c) => preg_match('/^#[0-9a-f]{6}$/i', trim($c)) ? strtoupper(trim($c)) : '', (array) ($_POST['colors'] ?? []))))],
            'tone' => ['voice' => ['formal_friendly' => $pct($_POST['formal_friendly'] ?? 60), 'serious_fun' => $pct($_POST['serious_fun'] ?? 40), 'scientific_simple' => $pct($_POST['scientific_simple'] ?? 70)]],
            'visual' => ['visuals' => array_filter(['kind' => in_array($_POST['v_kind'] ?? '', ['photo', '3d', 'flat', 'mixed'], true) ? $_POST['v_kind'] : '',
                         'light' => in_array($_POST['v_light'] ?? '', ['bright', 'warm', 'dramatic'], true) ? $_POST['v_light'] : '',
                         'people' => in_array($_POST['v_people'] ?? '', ['egyptian', 'arab', 'characters_only', 'none'], true) ? $_POST['v_people'] : ''])],
            default => ['desc' => mb_substr(trim((string) ($_POST['desc'] ?? '')), 0, 300), 'en' => mb_substr(trim((string) ($_POST['en'] ?? '')), 0, 500)],
        };
        $old = $id ? db_one('SELECT * FROM style_presets WHERE id = ?', [$id]) : null;
        $preview = $old['preview_path'] ?? null;
        if (!empty($_FILES['preview']['name'])) {
            $up = upload_image($_FILES['preview'], 'presets');
            if ($up['ok']) { if ($preview) delete_upload($preview); $preview = $up['path']; }
            else flash_set('warning', 'الصورة: ' . $up['error']);
        }
        $f = [$kind, $name, $preview, bk_json($data), (int) ($_POST['sector_template_id'] ?? 0) ?: null, !empty($_POST['is_active']) ? 1 : 0, (int) ($_POST['sort_order'] ?? 0)];
        if ($id) {
            db_run('UPDATE style_presets SET kind=?, name=?, preview_path=?, data=?, sector_template_id=?, is_active=?, sort_order=? WHERE id=?', array_merge($f, [$id]));
        } else {
            $id = db_insert('INSERT INTO style_presets (kind, name, preview_path, data, sector_template_id, is_active, sort_order) VALUES (?,?,?,?,?,?,?)', $f);
        }
        admin_log('save_preset', 'style_presets', $id, $name);
        flash_set('success', 'اتحفظ ✓');
        redirect('admin/style-presets.php?kind=' . $kind);
    }
    if (!empty($_POST['delete'])) {
        $r = db_one('SELECT preview_path FROM style_presets WHERE id = ?', [(int) $_POST['delete']]);
        if (!empty($r['preview_path'])) delete_upload($r['preview_path']);
        db_run('DELETE FROM style_presets WHERE id = ?', [(int) $_POST['delete']]);
        admin_log('delete_preset', 'style_presets', (int) $_POST['delete'], '');
        flash_set('success', 'اتحذف');
        redirect('admin/style-presets.php');
    }
}

$fKind = array_key_exists($_GET['kind'] ?? '', $kinds) ? $_GET['kind'] : '';
$rows = db_all('SELECT * FROM style_presets' . ($fKind ? ' WHERE kind = ?' : '') . ' ORDER BY kind, sort_order, id', $fKind ? [$fKind] : []);
$sectors = bk_sectors(false);
$ed = !empty($_GET['edit']) ? db_one('SELECT * FROM style_presets WHERE id = ?', [(int) $_GET['edit']]) : null;
$ed = $ed ?: ['id' => 0, 'kind' => $fKind ?: 'design_style', 'name' => '', 'preview_path' => null, 'data' => '{}', 'sector_template_id' => null, 'is_active' => 1, 'sort_order' => 0];
$ed['d'] = bk_arr($ed['data']);

$active = 'style-presets';
$page_title = 'مكتبة الـ Presets';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <?php if (is_file(__DIR__ . '/../templates/admin-topbar.php')) include __DIR__ . '/../templates/admin-topbar.php'; ?>
        <div class="page-head"><h1>مكتبة الـ Presets 🎛</h1><div class="sub">اختيارات جاهزة بتظهر للعميل كـ chips في مركز الهوية — وكل اختيار بيترجم لتعليمات للـ AI.</div></div>
        <?= render_flash() ?>
        <div class="seg" style="margin-bottom:14px;display:flex;gap:6px;flex-wrap:wrap">
            <a class="btn sm <?= $fKind === '' ? '' : 'ghost' ?>" href="?">الكل</a>
            <?php foreach ($kinds as $k => $l): ?><a class="btn sm <?= $fKind === $k ? '' : 'ghost' ?>" href="?kind=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?>
        </div>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:20px;align-items:start" id="sp-grid">
            <div class="card">
                <div class="table-wrap"><table class="table">
                    <thead><tr><th></th><th>الاسم</th><th>النوع</th><th>التفاصيل</th><th>القطاع</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): $d = bk_arr($r['data']); ?>
                        <tr style="<?= $r['is_active'] ? '' : 'opacity:.55' ?>">
                            <td><?php if ($r['preview_path']): ?><img src="<?= e(upload_url($r['preview_path'])) ?>" alt="" style="width:48px;height:36px;object-fit:cover;border-radius:6px"><?php endif; ?></td>
                            <td><b><?= e($r['name']) ?></b></td>
                            <td><span class="chip"><?= e($kinds[$r['kind']]) ?></span></td>
                            <td style="font-size:12.5px">
                                <?php if ($r['kind'] === 'palette'): foreach ((array) ($d['colors'] ?? []) as $c): ?><span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:<?= e($c) ?>;margin-inline-end:2px;vertical-align:middle"></span><?php endforeach;
                                elseif ($r['kind'] === 'tone'): $v = $d['voice'] ?? []; echo 'ودود ' . (int) ($v['formal_friendly'] ?? 0) . ' · مرح ' . (int) ($v['serious_fun'] ?? 0) . ' · بسيط ' . (int) ($v['scientific_simple'] ?? 0);
                                elseif ($r['kind'] === 'visual'): echo e(implode(' · ', (array) ($d['visuals'] ?? [])));
                                else: echo e((string) ($d['desc'] ?? '')); endif; ?>
                            </td>
                            <td style="font-size:12px"><?php foreach ($sectors as $s) if ((int) $s['id'] === (int) $r['sector_template_id']) echo e($s['name']); ?><?= $r['sector_template_id'] ? '' : 'الكل' ?></td>
                            <td style="white-space:nowrap"><a class="btn ghost sm" href="?edit=<?= (int) $r['id'] ?>&kind=<?= e($r['kind']) ?>#form">تعديل</a>
                                <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')"><?= csrf_field() ?><button class="btn ghost sm danger" name="delete" value="<?= (int) $r['id'] ?>">حذف</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
            <div class="card" id="form">
                <div class="card-head"><h3><?= $ed['id'] ? 'تعديل Preset' : 'Preset جديد' ?></h3></div>
                <form method="POST" enctype="multipart/form-data" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $ed['id'] ?>">
                    <div class="field"><label>النوع</label><select name="kind" class="select" id="sp-kind"><?php foreach ($kinds as $k => $l): ?><option value="<?= $k ?>" <?= $ed['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>الاسم (اللي العميل بيشوفه)</label><input name="name" class="input" value="<?= e($ed['name']) ?>"></div>
                    <div data-k="design_style character_style">
                        <div class="field"><label>وصف للعميل</label><input name="desc" class="input" value="<?= e((string) ($ed['d']['desc'] ?? '')) ?>"></div>
                        <div class="field"><label>تعليمات للـ AI (إنجليزي)</label><textarea name="en" class="textarea" rows="3" dir="ltr"><?= e((string) ($ed['d']['en'] ?? '')) ?></textarea></div>
                    </div>
                    <div data-k="palette" class="field"><label>الألوان</label><div style="display:flex;gap:8px">
                        <?php for ($i = 0; $i < 4; $i++): ?><input type="color" name="colors[]" value="<?= e((string) ($ed['d']['colors'][$i] ?? '#FFFFFF')) ?>" style="width:56px;height:40px;border:0"><?php endfor; ?></div></div>
                    <div data-k="tone">
                        <?php foreach (['formal_friendly' => 'رسمي ← ودود', 'serious_fun' => 'جاد ← مرح', 'scientific_simple' => 'علمي ← بسيط'] as $k => $l): ?>
                            <div class="field"><label><?= e($l) ?></label><input type="range" name="<?= $k ?>" min="0" max="100" value="<?= (int) ($ed['d']['voice'][$k] ?? 50) ?>" style="width:100%"></div>
                        <?php endforeach; ?>
                    </div>
                    <div data-k="visual">
                        <?php $vv = $ed['d']['visuals'] ?? []; foreach (['v_kind' => ['kind', ['photo' => 'صور حقيقية', '3d' => '3D', 'flat' => 'فلات', 'mixed' => 'مزيج']], 'v_light' => ['light', ['bright' => 'مشرقة', 'warm' => 'دافية', 'dramatic' => 'درامية']], 'v_people' => ['people', ['egyptian' => 'مصريين', 'arab' => 'عرب', 'characters_only' => 'الشخصيات بس', 'none' => 'من غير ناس']]] as $n => [$key, $opts]): ?>
                            <div class="field"><label><?= ['v_kind' => 'نوع الصور', 'v_light' => 'الإضاءة', 'v_people' => 'الناس'][$n] ?></label><select name="<?= $n ?>" class="select"><option value="">—</option><?php foreach ($opts as $ok => $ol): ?><option value="<?= $ok ?>" <?= ($vv[$key] ?? '') === $ok ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?></select></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="field"><label>صورة Preview</label>
                        <?php if ($ed['preview_path']): ?><img src="<?= e(upload_url($ed['preview_path'])) ?>" alt="" style="width:100%;max-height:140px;object-fit:cover;border-radius:10px;margin-bottom:6px"><?php endif; ?>
                        <input type="file" name="preview" accept="image/png,image/jpeg,image/webp" class="input"></div>
                    <div class="field"><label>القطاع</label><select name="sector_template_id" class="select"><option value="0">كل القطاعات</option><?php foreach ($sectors as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $ed['sector_template_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="field" style="display:flex;gap:14px;align-items:center">
                        <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="is_active" value="1" <?= $ed['is_active'] ? 'checked' : '' ?>> مفعّل</label>
                        <input type="number" name="sort_order" class="input" style="width:90px" value="<?= (int) $ed['sort_order'] ?>" title="الترتيب">
                    </div>
                    <button class="btn full" name="save" value="1">حفظ</button>
                </form>
            </div>
        </div>
    </main>
</div>
<style>@media (max-width:1100px){#sp-grid{grid-template-columns:1fr !important}}</style>
<script>
(function () {
  var sel = document.getElementById('sp-kind');
  function sync() { document.querySelectorAll('#form [data-k]').forEach(function (el) { el.style.display = el.dataset.k.split(' ').indexOf(sel.value) > -1 ? '' : 'none'; }); }
  sel.addEventListener('change', sync); sync();
})();
</script>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
