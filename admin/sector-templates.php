<?php
/**
 * الأدمن — قوالب القطاعات (Brand Kit)
 * أسئلة بريف إضافية · اتجاهات المحتوى الافتراضية · مواضيع افتراضية · ممنوعات القطاع (بتتطبق دايمًا) · مسودة/منشور
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/brand-kit.php';

require_admin();
require_admin_can('ai_settings');
bk_ensure_schema();

$lines = fn(string $s) => array_values(array_filter(array_map('trim', preg_split('/\R/u', $s))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    if (!empty($_POST['save'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
        if ($name === '') { flash_set('danger', 'اكتب اسم القطاع'); redirect('admin/sector-templates.php'); }
        $pillars = [];
        foreach ($lines((string) ($_POST['pillars'] ?? '')) as $l) {
            [$n, $pc] = array_pad(array_map('trim', explode('|', $l, 2)), 2, '0');
            if ($n !== '') $pillars[] = ['name' => mb_substr($n, 0, 80), 'percent' => max(0, min(100, (int) $pc))];
        }
        $f = [$name, mb_substr(trim((string) ($_POST['keywords'] ?? '')), 0, 500) ?: null,
              bk_json(array_slice($lines((string) ($_POST['brief_questions'] ?? '')), 0, 10)), bk_json($pillars),
              bk_json(array_slice($lines((string) ($_POST['default_topics'] ?? '')), 0, 60)), bk_json(array_slice($lines((string) ($_POST['forbidden'] ?? '')), 0, 60)),
              mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000) ?: null, !empty($_POST['is_published']) ? 1 : 0, (int) ($_POST['sort_order'] ?? 0)];
        if ($id) {
            db_run('UPDATE sector_templates SET name=?, keywords=?, brief_questions=?, default_pillars=?, default_topics=?, forbidden=?, notes=?, is_published=?, sort_order=? WHERE id=?', array_merge($f, [$id]));
            admin_log('update_sector', 'sector_templates', $id, $name);
        } else {
            $id = db_insert('INSERT INTO sector_templates (name, keywords, brief_questions, default_pillars, default_topics, forbidden, notes, is_published, sort_order) VALUES (?,?,?,?,?,?,?,?,?)', $f);
            admin_log('create_sector', 'sector_templates', $id, $name);
        }
        BrandContext::flush();
        flash_set('success', 'اتحفظ ✓');
        redirect('admin/sector-templates.php?edit=' . $id);
    }
    if (!empty($_POST['delete'])) {
        $id = (int) $_POST['delete'];
        db_run('UPDATE brand_kits SET sector_template_id = NULL WHERE sector_template_id = ?', [$id]);
        db_run('DELETE FROM sector_templates WHERE id = ?', [$id]);
        admin_log('delete_sector', 'sector_templates', $id, '');
        flash_set('success', 'اتحذف — العملاء اللي كانوا عليه رجعوا من غير قالب');
        redirect('admin/sector-templates.php');
    }
}

$rows = db_all('SELECT s.*, (SELECT COUNT(*) FROM brand_kits k WHERE k.sector_template_id = s.id) kits,
                       (SELECT COUNT(*) FROM brand_learning l WHERE l.sector_template_id = s.id) lib
                FROM sector_templates s ORDER BY s.sort_order, s.id');
$ed = !empty($_GET['edit']) ? bk_sector((int) $_GET['edit']) : null;
$ed = $ed ?: ['id' => 0, 'name' => '', 'keywords' => '', 'brief_questions' => [], 'default_pillars' => [], 'default_topics' => [], 'forbidden' => [], 'notes' => '', 'is_published' => 0, 'sort_order' => 0];

$active = 'sector-templates';
$page_title = 'قوالب القطاعات';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <?php if (is_file(__DIR__ . '/../templates/admin-topbar.php')) include __DIR__ . '/../templates/admin-topbar.php'; ?>
        <div class="page-head"><h1>قوالب القطاعات 🏷</h1>
            <div class="sub">نقطة البداية لكل عميل حسب مجاله: اتجاهات ومواضيع افتراضية وممنوعات بتتطبق دايمًا. القالب بيتختار تلقائيًا من مجال العميل (الكلمات المفتاحية) والعميل يقدر يغيّره.</div></div>
        <?= render_flash() ?>
        <div style="display:grid;grid-template-columns:minmax(0,1fr) 440px;gap:20px;align-items:start" id="st-grid">
            <div class="card">
                <div class="card-head"><h3>القطاعات (<?= count($rows) ?>)</h3><a class="btn ghost sm" href="?#form">+ قطاع</a></div>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>القطاع</th><th>الاتجاهات</th><th>ممنوعات</th><th>عملاء</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): $pl = bk_arr($r['default_pillars']); ?>
                        <tr>
                            <td><b><?= e($r['name']) ?></b><div class="text-mute" style="font-size:12px"><?= e(mb_substr((string) $r['keywords'], 0, 80)) ?></div></td>
                            <td style="font-size:12.5px"><?= e(implode('، ', array_map(fn($p) => $p['name'] . ' ' . $p['percent'] . '%', $pl))) ?></td>
                            <td><?= count(bk_arr($r['forbidden'])) ?></td>
                            <td><?= (int) $r['kits'] ?></td>
                            <td><?= $r['is_published'] ? '<span class="chip chip-mint">منشور</span>' : '<span class="chip">مسودة</span>' ?></td>
                            <td style="white-space:nowrap"><a class="btn ghost sm" href="?edit=<?= (int) $r['id'] ?>#form">تعديل</a>
                                <a class="btn ghost sm" href="<?= url('admin/brand-learning.php?sector=' . (int) $r['id']) ?>">المكتبة (<?= (int) $r['lib'] ?>)</a>
                                <form method="POST" style="display:inline" onsubmit="return confirm('حذف القطاع؟')"><?= csrf_field() ?><button class="btn ghost sm danger" name="delete" value="<?= (int) $r['id'] ?>">حذف</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
            <div class="card" id="form">
                <div class="card-head"><h3><?= $ed['id'] ? 'تعديل: ' . e($ed['name']) : 'قطاع جديد' ?></h3></div>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $ed['id'] ?>">
                    <div class="field"><label>الاسم</label><input name="name" class="input" value="<?= e($ed['name']) ?>"></div>
                    <div class="field"><label>كلمات مفتاحية للتعرّف التلقائي</label><input name="keywords" class="input" value="<?= e((string) $ed['keywords']) ?>" placeholder="عيادة,دكتور,أسنان"><div class="field-help">بفاصلة — بتتقارن بمجال العميل واسم نشاطه.</div></div>
                    <div class="field"><label>اتجاهات المحتوى الافتراضية</label>
                        <textarea name="pillars" class="textarea" rows="5" placeholder="توعية وطمأنة|35"><?= e(implode("\n", array_map(fn($p) => $p['name'] . '|' . $p['percent'], $ed['default_pillars']))) ?></textarea>
                        <div class="field-help">سطر لكل اتجاه: الاسم|النسبة</div></div>
                    <div class="field"><label>مواضيع افتراضية (سطر لكل موضوع)</label><textarea name="default_topics" class="textarea" rows="5"><?= e(implode("\n", $ed['default_topics'])) ?></textarea></div>
                    <div class="field"><label>⛔ ممنوعات القطاع (بتتطبق على كل كابشن دايمًا)</label><textarea name="forbidden" class="textarea" rows="4" placeholder="مضمون 100%"><?= e(implode("\n", $ed['forbidden'])) ?></textarea></div>
                    <div class="field"><label>أسئلة بريف إضافية للقطاع (سطر لكل سؤال)</label><textarea name="brief_questions" class="textarea" rows="3"><?= e(implode("\n", $ed['brief_questions'])) ?></textarea></div>
                    <div class="field"><label>ملاحظات داخلية</label><textarea name="notes" class="textarea" rows="2"><?= e((string) $ed['notes']) ?></textarea></div>
                    <div class="field" style="display:flex;gap:14px;align-items:center">
                        <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="is_published" value="1" <?= $ed['is_published'] ? 'checked' : '' ?>> منشور (يظهر للعملاء)</label>
                        <input type="number" name="sort_order" class="input" style="width:90px" value="<?= (int) $ed['sort_order'] ?>" title="الترتيب">
                    </div>
                    <button class="btn full" name="save" value="1">حفظ</button>
                    <?php if ($ed['id']): ?><a class="btn ghost full mt-10" href="?">قطاع جديد</a><?php endif; ?>
                </form>
            </div>
        </div>
    </main>
</div>
<style>@media (max-width:1100px){#st-grid{grid-template-columns:1fr !important}}</style>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
