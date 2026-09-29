<?php
/** إدارة الصفحات الداخلية + إنشاء صفحات HTML مخصصة */
require_once __DIR__ . '/auth.php';
sa_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
        s_decode_b64();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '', (string) ($_POST['slug'] ?? '')));
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 250);
        if ($slug === '' || $title === '') {
            s_flash('danger', 'العنوان والمعرّف (slug) مطلوبين');
            s_redirect('site-admin/pages.php');
        }
        $data = [
            $title,
            mb_substr(trim((string) ($_POST['subtitle'] ?? '')), 0, 500) ?: null,
            (string) ($_POST['content_html'] ?? ''),
            !empty($_POST['show_in_menu']) ? 1 : 0,
            !empty($_POST['is_active']) ? 1 : 0,
            (int) ($_POST['sort_order'] ?? 0),
        ];
        if ($id) {
            $existing = s_one('SELECT * FROM site_pages WHERE id = ?', [$id]);
            // الصفحات المدمجة: المعرّف ثابت
            if ($existing && !$existing['is_builtin']) {
                $dup = s_one('SELECT id FROM site_pages WHERE slug = ? AND id != ?', [$slug, $id]);
                if ($dup) { s_flash('danger', 'المعرّف ده مستخدم في صفحة تانية'); s_redirect('site-admin/pages.php'); }
                $data[] = $slug;
                $data[] = $id;
                s_run('UPDATE site_pages SET title=?, subtitle=?, content_html=?, show_in_menu=?, is_active=?, sort_order=?, slug=? WHERE id=?', $data);
            } else {
                $data[] = $id;
                s_run('UPDATE site_pages SET title=?, subtitle=?, content_html=?, show_in_menu=?, is_active=?, sort_order=? WHERE id=?', $data);
            }
            s_flash('success', 'تم حفظ الصفحة ✓');
        } else {
            if (s_one('SELECT id FROM site_pages WHERE slug = ?', [$slug])) {
                s_flash('danger', 'المعرّف ده موجود بالفعل');
                s_redirect('site-admin/pages.php');
            }
            array_unshift($data, $slug);
            s_insert('INSERT INTO site_pages (slug, title, subtitle, content_html, show_in_menu, is_active, sort_order, is_builtin) VALUES (?,?,?,?,?,?,?,0)', $data);
            s_flash('success', 'تم إنشاء الصفحة ✓');
        }
        s_redirect('site-admin/pages.php');
    }

    if ($action === 'toggle') {
        s_run('UPDATE site_pages SET is_active = 1 - is_active WHERE id = ?', [$id]);
        s_redirect('site-admin/pages.php');
    }
    if ($action === 'menu') {
        s_run('UPDATE site_pages SET show_in_menu = 1 - show_in_menu WHERE id = ?', [$id]);
        s_redirect('site-admin/pages.php');
    }
    if ($action === 'delete') {
        $p = s_one('SELECT * FROM site_pages WHERE id = ?', [$id]);
        if ($p && !$p['is_builtin']) {
            s_run('DELETE FROM site_pages WHERE id = ?', [$id]);
            s_flash('success', 'تم حذف الصفحة');
        } else {
            s_flash('danger', 'مينفعش تحذف صفحة أساسية — تقدر تخفيها بس');
        }
        s_redirect('site-admin/pages.php');
    }
}

$editing = !empty($_GET['edit']) ? s_one('SELECT * FROM site_pages WHERE id = ?', [(int) $_GET['edit']]) : null;
$rows = s_all('SELECT * FROM site_pages ORDER BY sort_order, id');
$__t = 'الصفحات الداخلية';
include __DIR__ . '/layout.php';
?>
<div class="card" style="background:rgba(15,60,201,.06);border-color:rgba(15,60,201,.2)">
  <b>الصفحات الأساسية</b> (احنا مين · الخدمات · التصميمات · الشرح · الأسعار) محتواها بيتولّد تلقائيًا من أقسام اللوحة —
  وتقدر تضيف فوقه <b>HTML مخصص</b> يظهر في أولها.<br>
  <b>الصفحات المخصصة</b> اللي تنشئها هنا بتكون HTML كامل من عندك.
</div>

<div class="card">
  <h3><?= $editing ? '✎ تعديل: ' . e($editing['title']) : '＋ صفحة جديدة' ?></h3>
  <form method="POST" data-safe-post>
    <?= s_csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <div class="row">
      <div class="f"><label>عنوان الصفحة *</label><input type="text" name="title" required value="<?= e($editing['title'] ?? '') ?>"></div>
      <div class="f">
        <label>المعرّف في الرابط (slug) *</label>
        <input type="text" name="slug" dir="ltr" required value="<?= e($editing['slug'] ?? '') ?>"
               <?= !empty($editing['is_builtin']) ? 'readonly style="background:#f2f0ec"' : '' ?> placeholder="faq">
        <div class="hint">الرابط: <?= e(SITE_BASE) ?>/site/page.php?p=<b>slug</b></div>
      </div>
      <div class="f"><label>الترتيب</label><input type="number" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>"></div>
      <div class="f" style="grid-column:1/-1"><label>وصف تحت العنوان</label>
        <input type="text" name="subtitle" value="<?= e($editing['subtitle'] ?? '') ?>"></div>
      <div class="f" style="grid-column:1/-1">
        <label>محتوى HTML<?= !empty($editing['is_builtin']) ? ' (بيظهر فوق محتوى الصفحة التلقائي)' : '' ?></label>
        <textarea name="content_html" rows="14" dir="ltr" style="font-family:ui-monospace,monospace;font-size:12.5px"><?= e($editing['content_html'] ?? '') ?></textarea>
        <div class="hint">تقدر تستخدم: &lt;h2&gt; &lt;p&gt; &lt;ul&gt;&lt;li&gt; &lt;img src&gt; &lt;a href&gt; — وكمان كلاسات الموقع زي class="card" و class="btn-pill btn-blue"</div>
      </div>
      <div class="f">
        <label>الظهور</label>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="is_active" value="1" <?= ($editing ? $editing['is_active'] : 1) ? 'checked' : '' ?> style="width:auto"> الصفحة شغالة</label>
      </div>
      <div class="f">
        <label>القائمة</label>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="show_in_menu" value="1" <?= ($editing ? $editing['show_in_menu'] : 1) ? 'checked' : '' ?> style="width:auto"> تظهر في القائمة</label>
      </div>
    </div>
    <button class="btn"><?= $editing ? '💾 حفظ' : '＋ إنشاء الصفحة' ?></button>
    <?php if ($editing): ?><a href="pages.php" class="btn g">إلغاء</a>
      <a href="<?= e(s_page_url($editing['slug'])) ?>" target="_blank" class="btn g">👁 معاينة</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>كل الصفحات (<?= count($rows) ?>)</h3>
  <div class="tw">
  <table>
    <thead><tr><th>الصفحة</th><th>الرابط</th><th>النوع</th><th>القائمة</th><th>الحالة</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr style="<?= $p['is_active'] ? '' : 'opacity:.5' ?>">
        <td><b><?= e($p['title']) ?></b><?php if ($p['subtitle']): ?><div class="hint"><?= e(mb_substr($p['subtitle'], 0, 44)) ?></div><?php endif; ?></td>
        <td dir="ltr" style="font-size:12px">?p=<?= e($p['slug']) ?></td>
        <td><span class="chip"><?= $p['is_builtin'] ? 'أساسية' : 'مخصصة' ?></span></td>
        <td><span class="chip <?= $p['show_in_menu'] ? 'on' : '' ?>"><?= $p['show_in_menu'] ? 'ظاهرة' : 'مخفية' ?></span></td>
        <td><span class="chip <?= $p['is_active'] ? 'on' : '' ?>"><?= $p['is_active'] ? 'شغالة' : 'موقوفة' ?></span></td>
        <td style="white-space:nowrap">
          <a href="?edit=<?= (int) $p['id'] ?>" class="btn g s">✎</a>
          <a href="<?= e(s_page_url($p['slug'])) ?>" target="_blank" class="btn g s">👁</a>
          <button type="submit" form="mn-<?= (int) $p['id'] ?>" class="btn g s" title="إظهار/إخفاء من القائمة">☰</button>
          <button type="submit" form="tg-<?= (int) $p['id'] ?>" class="btn g s"><?= $p['is_active'] ? '🚫' : '✔' ?></button>
          <?php if (!$p['is_builtin']): ?><button type="submit" form="dl-<?= (int) $p['id'] ?>" class="btn d s">🗑</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php foreach ($rows as $p): ?>
    <form id="tg-<?= (int) $p['id'] ?>" method="POST" style="display:none"><?= s_csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"></form>
    <form id="mn-<?= (int) $p['id'] ?>" method="POST" style="display:none"><?= s_csrf_field() ?><input type="hidden" name="action" value="menu"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"></form>
    <form id="dl-<?= (int) $p['id'] ?>" method="POST" style="display:none" onsubmit="return confirm('حذف الصفحة نهائيًا؟')"><?= s_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"></form>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/layout-end.php'; ?>
