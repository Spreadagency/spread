<?php
/** الصفحات — كل صفحات الموقع (الأساسية + المخصصة): الحالة · القائمة · الترتيب · نسخ · معاينة · حذف — والتعديل في الـ Page Builder */
require_once __DIR__ . '/auth.php';
sa_require_perm('pages');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = sa_is_ajax();
    $ajax ? sa_check_csrf_json() : s_check_csrf();
    s_decode_b64();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $p = $id ? s_one('SELECT * FROM site_pages WHERE id = ?', [$id]) : null;

    // القديمة: ظهور الصفحة (منشورة ↔ مسودة)
    if ($action === 'toggle' && $p) {
        $on = (int) !$p['is_active'];
        s_run("UPDATE site_pages SET is_active = ?, status = ?, published_at = COALESCE(published_at, IF(? = 1, NOW(), NULL)) WHERE id = ?", [$on, $on ? 'published' : 'draft', $on, $id]);
        sa_log($on ? 'publish' : 'unpublish', 'pages', ($on ? 'نشر' : 'إلغاء نشر') . ' صفحة «' . $p['title'] . '»', $id);
        s_redirect('site-admin/pages.php');
    }
    // الظهور في القائمة
    if (($action === 'menu' || $action === 'toggle_ajax') && $p) {
        $on = $action === 'toggle_ajax' ? (int) !empty($_POST['on']) : (int) !$p['show_in_menu'];
        s_run('UPDATE site_pages SET show_in_menu = ? WHERE id = ?', [$on, $id]);
        sa_log('toggle', 'pages', ($on ? 'إظهار' : 'إخفاء') . ' صفحة «' . $p['title'] . '» ' . ($on ? 'في' : 'من') . ' القائمة', $id);
        if ($ajax) sa_json(['ok' => true, 'message' => $on ? 'الصفحة ظاهرة في القائمة ✓' : 'الصفحة اتشالت من القائمة']);
        s_redirect('site-admin/pages.php');
    }
    if ($action === 'reorder_ajax') {
        foreach (array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))) as $i => $rid) s_run('UPDATE site_pages SET sort_order = ? WHERE id = ?', [$i + 1, $rid]);
        sa_log('reorder', 'pages', 'إعادة ترتيب الصفحات');
        sa_json(['ok' => true, 'message' => 'ترتيب الصفحات اتحفظ ✓']);
    }
    if ($action === 'duplicate' && $p) {
        $slug = $p['slug'] . '-copy';
        for ($n = 2; s_one('SELECT id FROM site_pages WHERE slug = ?', [$slug]); $n++) $slug = $p['slug'] . '-copy-' . $n;
        require_once dirname(__DIR__) . '/site/blocks.php';
        $blocks = json_encode(s_page_blocks($p), JSON_UNESCAPED_UNICODE);
        $nid = s_insert("INSERT INTO site_pages (slug, title, subtitle, content_html, is_builtin, show_in_menu, is_active, status, sort_order, seo_title, seo_description, og_title, og_description,
                         og_image, noindex, featured_image, blocks_json, show_cta, created_at) VALUES (?,?,?,NULL,0,0,0,'draft',?,?,?,?,?,?,?,?,?,?,NOW())", [
            $slug, mb_substr($p['title'] . ' (نسخة)', 0, 250), $p['subtitle'], (int) $p['sort_order'] + 1, $p['seo_title'], $p['seo_description'], $p['og_title'],
            $p['og_description'], $p['og_image'], (int) $p['noindex'], $p['featured_image'], $blocks, (int) $p['show_cta']]);
        if ($nid) {
            sa_log('duplicate', 'pages', 'نسخ صفحة «' . $p['title'] . '» كمسودة جديدة', $nid);
            s_flash('success', 'اتعملت نسخة كمسودة ✓');
            s_redirect('site-admin/page-edit.php?id=' . $nid);
        }
        s_flash('danger', 'تعذّر النسخ');
        s_redirect('site-admin/pages.php');
    }
    if ($action === 'delete' && $p) {
        if ($p['is_builtin']) {
            s_flash('danger', 'مينفعش تحذف صفحة أساسية — تقدر تخليها مسودة أو مخفية');
        } else {
            s_run('DELETE FROM site_pages WHERE id = ?', [$id]);
            s_run('DELETE FROM site_redirects WHERE to_slug = ?', [$p['slug']]);
            sa_log('delete', 'pages', 'حذف صفحة «' . $p['title'] . '» (/' . $p['slug'] . ')', $id);
            s_flash('success', 'اتحذفت الصفحة');
        }
        s_redirect('site-admin/pages.php');
    }
    // الفورم القديم (إنشاء/تعديل بسيط) — بيتحوّل للمحرر الجديد
    if ($action === 'save') {
        s_flash('warning', 'تعديل الصفحات بقى من الـ Page Builder');
        s_redirect('site-admin/page-edit.php' . ($id ? '?id=' . $id : ''));
    }
    s_redirect('site-admin/pages.php');
}

// الرابط القديم ?edit= ← المحرر الجديد
if (!empty($_GET['edit'])) s_redirect('site-admin/page-edit.php?id=' . (int) $_GET['edit']);
if (!empty($_GET['new'])) s_redirect('site-admin/page-edit.php');

$rows = s_all('SELECT * FROM site_pages ORDER BY sort_order, id');
$counts = ['published' => 0, 'draft' => 0, 'hidden' => 0];
foreach ($rows as $r) $counts[$r['status'] ?? 'published'] = ($counts[$r['status'] ?? 'published'] ?? 0) + 1;

$__t = 'الصفحات';
include __DIR__ . '/layout.php';
echo sa_page_head('file', 'الصفحات', 'Pages', 'كل صفحات الموقع. اعمل صفحة جديدة من غير كود بالأقسام (Hero · نص · صور · مميزات · أسعار · آراء · أسئلة · CTA · فيديو · HTML) — وكل صفحة ليها SEO ومعاينة ومسودة/نشر.');
$filters = '<select class="ad-filter" data-filter-select="status" aria-label="الحالة"><option value="">كل الحالات (' . count($rows) . ')</option>'
    . '<option value="published">منشورة (' . $counts['published'] . ')</option><option value="draft">مسودة (' . $counts['draft'] . ')</option><option value="hidden">مخفية (' . $counts['hidden'] . ')</option></select>';
echo sa_search_bar('ابحث في الصفحات...', count($rows), 'صفحة', sa_btn('إضافة صفحة', 'pri', 'page-edit.php', 'plus'), $filters);
?>
<div class="ad-table-w"><table class="ad-table">
  <thead><tr><th style="width:36px"><span class="sr-only">ترتيب</span></th><th>الصفحة</th><th>الرابط</th><th>الحالة</th><th>آخر تحديث</th><th>في القائمة</th><th style="text-align:left">إجراءات</th></tr></thead>
  <tbody data-sortable>
  <?php $n = count($rows); foreach ($rows as $i => $p): $st = $p['status'] ?? 'published'; $url = s_page_url($p['slug']); ?>
    <tr data-row data-id="<?= (int) $p['id'] ?>" data-status="<?= e($st) ?>" class="<?= $st === 'published' ? '' : 'off' ?>">
      <td class="ad-grab-td"><span class="ad-ib ad-grab" data-grab title="اسحب لإعادة الترتيب"><?= sa_icon('grip', 17) ?></span></td>
      <td class="t-first" data-l="الصفحة"><a class="t-main" href="page-edit.php?id=<?= (int) $p['id'] ?>" style="color:inherit"><?= e($p['title']) ?></a>
        <span class="t-sub"><?= $p['is_builtin'] ? 'صفحة أساسية' : 'صفحة مخصصة' ?><?= !empty($p['blocks_json']) ? ' · Page Builder' : '' ?></span></td>
      <td data-l="الرابط" dir="ltr" style="text-align:right"><a href="<?= e($url) ?>" target="_blank" rel="noopener" class="t-num">/<?= e($p['slug']) ?></a></td>
      <td class="t-chip" data-l="الحالة"><?= sa_status_chip($st) ?></td>
      <td data-l="آخر تحديث" class="t-num"><?= e(sa_ago($p['updated_at'])) ?></td>
      <td data-l="في القائمة"><?= sa_switch('m' . (int) $p['id'], (bool) $p['show_in_menu'], '', ['data-toggle-id' => (string) (int) $p['id'], 'aria-label' => 'إظهار «' . $p['title'] . '» في القائمة']) ?></td>
      <td class="ad-acts"><div class="ad-acts-in">
        <button type="button" class="ad-ib" data-move="up" aria-label="لفوق" title="لفوق"<?= $i === 0 ? ' aria-disabled="true"' : '' ?>><?= sa_icon('up', 17) ?></button>
        <button type="button" class="ad-ib" data-move="down" aria-label="لتحت" title="لتحت"<?= $i === $n - 1 ? ' aria-disabled="true"' : '' ?>><?= sa_icon('down', 17) ?></button>
        <a class="ad-ib" href="page-edit.php?id=<?= (int) $p['id'] ?>" aria-label="تعديل" title="تعديل"><?= sa_icon('edit', 17) ?></a>
        <a class="ad-ib" href="<?= e(s_url('site/page.php?p=' . urlencode($p['slug']) . '&preview=1')) ?>" target="_blank" rel="noopener" aria-label="معاينة" title="معاينة"><?= sa_icon('eye', 17) ?></a>
        <?= crud_like_form((int) $p['id'], 'duplicate', 'copy', 'نسخ') ?>
        <?php if (!$p['is_builtin']): ?><?= crud_like_form((int) $p['id'], 'delete', 'trash', 'حذف', 'هتحذف صفحة «' . $p['title'] . '» نهائيًا — الرابط /' . $p['slug'] . ' هيبطل يشتغل. متأكد؟', 'del') ?><?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="ad-foot-hint"><?= sa_icon('grip', 14) ?>اسحب الصفحة من المقبض لإعادة الترتيب (نفس ترتيبها في القائمة)، أو استخدم الأسهم.</p>
<?php
function crud_like_form(int $id, string $action, string $icon, string $label, string $confirm = '', string $cls = ''): string
{
    return '<form method="POST"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . s_csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="ad-ib ' . e($cls) . '" title="' . e($label) . '" aria-label="' . e($label) . '">' . sa_icon($icon, 17) . '</button></form>';
}
include __DIR__ . '/layout-end.php';
