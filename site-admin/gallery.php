<?php
/**
 * معرض التصميمات — رفع / لينك / استيراد من تصميمات المنصة
 */
require_once __DIR__ . '/auth.php';
sa_require_perm('designs');

/* ─── الاتصال بقاعدة المنصة (قراءة فقط) لاستيراد التصميمات ─── */
/** اتصال قاعدة المنصة — الدالة المشتركة في site/functions.php */
function platform_pdo(): ?PDO
{
    return s_platform_pdo();
}

function platform_designs(int $limit = 60): array
{
    $pdo = platform_pdo();
    if (!$pdo) return [];
    try {
        $st = $pdo->query("
            SELECT * FROM (
                SELECT id, image_path, model, created_at, 'content' AS src FROM content_designs
                UNION ALL
                SELECT id, image_path, model, created_at, 'studio' AS src FROM studio_designs
            ) d ORDER BY created_at DESC LIMIT {$limit}");
        return $st->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = sa_is_ajax();
    $ajax ? sa_check_csrf_json() : s_check_csrf();
    s_decode_b64();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'import') {
        $picked = (array) ($_POST['pick'] ?? []);
        $added = 0;
        foreach ($picked as $ref) {
            [$src, $id, $path] = array_pad(explode('|', (string) $ref, 3), 3, '');
            $path = trim($path);
            if ($path === '') continue;
            $exists = s_one('SELECT id FROM site_gallery WHERE platform_ref = ?', [$src . ':' . $id]);
            if ($exists) continue;
            s_insert('INSERT INTO site_gallery (title, image_path, source, platform_ref, category, sort_order) VALUES (?, ?, "platform", ?, ?, 0)', [
                mb_substr(trim((string) ($_POST['import_title'] ?? '')), 0, 200) ?: null,
                $path,
                $src . ':' . $id,
                mb_substr(trim((string) ($_POST['import_category'] ?? '')), 0, 80) ?: null,
            ]);
            $added++;
        }
        if ($added) sa_log('create', 'designs', "استيراد {$added} تصميم من المنصة للمعرض");
        s_flash($added ? 'success' : 'danger', $added ? "تم استيراد {$added} تصميم من المنصة ✓" : 'مفيش تصميمات جديدة اتضافت');
        s_redirect('site-admin/gallery.php');
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id ? s_one('SELECT * FROM site_gallery WHERE id = ?', [$id]) : null;

        $url = trim((string) ($_POST['image_url'] ?? ''));
        if ($url !== '' && !preg_match('~^(https?://|/)~i', $url)) $url = 'https://' . ltrim($url, '/');

        $path = $existing['image_path'] ?? null;
        if (!empty($_FILES['image']['name'])) {
            $up = s_upload($_FILES['image'], 'gallery');
            if ($up['ok']) {
                if ($path && ($existing['source'] ?? '') === 'upload') s_delete_upload($path);
                $path = $up['path'];
            } else {
                s_flash('danger', $up['error']);
            }
        }

        $sort = (int) ($_POST['sort_order'] ?? 0);
        if (!$id && $sort === 0) $sort = (int) (s_one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM site_gallery')['n'] ?? 1);
        $data = [
            mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200) ?: null,
            $path,
            $url !== '' ? mb_substr($url, 0, 700) : null,
            mb_substr(trim((string) ($_POST['category'] ?? '')), 0, 80) ?: null,
            $sort,
            !empty($_POST['is_active']) ? 1 : 0,
        ];

        if ($id) {
            $data[] = $id;
            s_run('UPDATE site_gallery SET title=?, image_path=?, image_url=?, category=?, sort_order=?, is_active=? WHERE id=?', $data);
            sa_log('update', 'designs', 'تعديل تصميم في المعرض ' . ($data[0] ? '«' . $data[0] . '»' : '#' . $id), $id);
            if (empty($_SESSION['site_flash'])) s_flash('success', 'تم الحفظ ✓');
        } else {
            array_splice($data, 3, 0, [$url !== '' ? 'link' : 'upload']);
            $nid = s_insert('INSERT INTO site_gallery (title, image_path, image_url, source, category, sort_order, is_active) VALUES (?,?,?,?,?,?,?)', $data);
            sa_log('create', 'designs', 'إضافة تصميم للمعرض ' . ($data[0] ? '«' . $data[0] . '»' : ''), $nid ?: null);
            if (empty($_SESSION['site_flash'])) s_flash('success', 'تمت الإضافة ✓');
        }
        s_redirect('site-admin/gallery.php');
    }

    if ($action === 'toggle' || $action === 'toggle_ajax') {
        $id = (int) $_POST['id'];
        if ($action === 'toggle_ajax') s_run('UPDATE site_gallery SET is_active = ? WHERE id = ?', [!empty($_POST['on']) ? 1 : 0, $id]);
        else s_run('UPDATE site_gallery SET is_active = 1 - is_active WHERE id = ?', [$id]);
        $on = (int) (s_one('SELECT is_active FROM site_gallery WHERE id = ?', [$id])['is_active'] ?? 0);
        sa_log('toggle', 'designs', ($on ? 'إظهار' : 'إخفاء') . ' تصميم #' . $id . ' في المعرض', $id);
        if ($ajax) sa_json(['ok' => true, 'on' => $on, 'message' => $on ? 'ظاهر في الموقع ✓' : 'اتخفى من الموقع']);
        s_redirect('site-admin/gallery.php');
    }

    if ($action === 'delete') {
        $r = s_one('SELECT * FROM site_gallery WHERE id = ?', [(int) $_POST['id']]);
        if ($r) {
            if (($r['source'] ?? '') === 'upload' && $r['image_path']) s_delete_upload($r['image_path']);
            s_run('DELETE FROM site_gallery WHERE id = ?', [(int) $_POST['id']]);
            sa_log('delete', 'designs', 'حذف تصميم من المعرض ' . ($r['title'] ? '«' . $r['title'] . '»' : '#' . $r['id']), (int) $r['id']);
            s_flash('success', 'تم الحذف');
        }
        s_redirect('site-admin/gallery.php');
    }

    if ($action === 'reorder') {
        foreach (($_POST['order'] ?? []) as $rid => $ord) {
            s_run('UPDATE site_gallery SET sort_order = ? WHERE id = ?', [(int) $ord, (int) $rid]);
        }
        sa_log('reorder', 'designs', 'إعادة ترتيب معرض التصميمات');
        s_flash('success', 'تم حفظ الترتيب ✓');
        s_redirect('site-admin/gallery.php');
    }

    if ($action === 'reorder_ajax') {
        foreach (array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))) as $i => $rid) {
            s_run('UPDATE site_gallery SET sort_order = ? WHERE id = ?', [$i + 1, $rid]);
        }
        sa_log('reorder', 'designs', 'إعادة ترتيب معرض التصميمات');
        sa_json(['ok' => true, 'message' => 'تم حفظ الترتيب ✓']);
    }
}

$editing = !empty($_GET['edit']) ? s_one('SELECT * FROM site_gallery WHERE id = ?', [(int) $_GET['edit']]) : null;
$rows = s_all('SELECT * FROM site_gallery ORDER BY sort_order, id');
$showImport = isset($_GET['import']);
$pDesigns = $showImport ? platform_designs() : [];
$existingRefs = array_column(s_all('SELECT platform_ref FROM site_gallery WHERE platform_ref IS NOT NULL'), 'platform_ref');
$cats = array_values(array_unique(array_filter(array_map(fn($r) => trim((string) $r['category']), $rows))));
$srcLabels = ['upload' => 'مرفوع', 'platform' => 'من المنصة', 'link' => 'لينك'];

$__t = 'التصميمات';
include __DIR__ . '/layout.php';

echo sa_page_head('image', 'التصميمات', 'Designs Gallery', 'تقدر تضيف تصميمات بثلاث طرق: ترفعها · لينك مباشر · أو تستوردها من التصميمات اللي اتعملت في المنصة.',
    sa_btn('استورد من المنصة', 'soft', '?import=1', 'refresh') . sa_btn('صفحة التصميمات', 'sec', s_page_url('designs'), 'external', ['target' => '_blank', 'rel' => 'noopener']));

$filters = '<select class="ad-filter" data-filter-select="st" aria-label="الحالة"><option value="">كل الحالات</option><option value="on">ظاهر</option><option value="off">مخفي</option></select>';
if ($cats) {
    $filters .= '<select class="ad-filter" data-filter-select="cat" aria-label="التصنيف"><option value="">كل التصنيفات</option>';
    foreach ($cats as $c) $filters .= '<option value="' . e($c) . '">' . e($c) . '</option>';
    $filters .= '</select>';
}
echo sa_search_bar('ابحث في التصميمات...', count($rows), 'تصميم', sa_btn('إضافة تصميم', 'pri', '?new=1', 'plus', ['data-open-drawer' => 'crud']), $filters);
?>

<?php if ($showImport): ?>
  <div class="ad-card" style="margin-bottom:18px">
    <div class="ad-card-h"><h3>استيراد من المنصة</h3><a class="ad-ib" href="gallery.php" aria-label="إغلاق"><?= sa_icon('x', 18) ?></a></div>
    <?php if (!$pDesigns): ?>
      <?= sa_empty('link', 'مفيش تصميمات متاحة', 'تأكد إن المنصة متركّبة في نفس المجلد وإن ملف includes/config.php فيه بيانات قاعدة بياناتها.') ?>
    <?php else: ?>
      <form method="POST" data-safe-post>
        <?= s_csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <div class="ad-form" style="margin-bottom:14px">
          <?= sa_field(['name' => 'import_category', 'label' => 'تصنيف للمستورد (اختياري)', 'ph' => 'بوستات / ستوريز / لوجوهات']) ?>
          <?= sa_field(['name' => 'import_title', 'label' => 'عنوان موحّد (اختياري)', 'ph' => 'تصميم من المنصة']) ?>
        </div>
        <div class="ad-media-grid sm ad-scroll" style="max-height:460px;overflow-y:auto;padding:4px">
          <?php foreach ($pDesigns as $d):
            $ref = $d['src'] . ':' . $d['id'];
            $already = in_array($ref, $existingRefs, true);
            $imgUrl = PLATFORM_STORAGE_URL . '/' . ltrim((string) $d['image_path'], '/'); ?>
            <label class="ad-mi" style="cursor:<?= $already ? 'default' : 'pointer' ?>;<?= $already ? 'opacity:.45' : '' ?>">
              <span class="im" style="background-image:url('<?= e($imgUrl) ?>')"></span>
              <span class="mt"><span class="ad-cb"><input type="checkbox" name="pick[]" value="<?= e($d['src'] . '|' . $d['id'] . '|' . $d['image_path']) ?>" <?= $already ? 'disabled' : '' ?> aria-label="اختيار"><span><?= sa_icon('check', 15, 2.6) ?></span></span>
                <small><?= $already ? 'مضاف بالفعل' : e(date('Y/m/d', strtotime($d['created_at']))) ?></small></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:10px;margin-top:14px"><?= sa_btn('استورد المختار', 'pri', null, 'down', ['type' => 'submit']) ?><a href="gallery.php" class="ad-btn ad-sec">إلغاء</a></div>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$rows): ?>
  <?= sa_empty('image', 'لسه مفيش تصميمات', 'ارفع أول تصميم أو استورد من شغل المنصة الحقيقي.', sa_btn('إضافة تصميم', 'pri', '?new=1', 'plus', ['data-open-drawer' => 'crud']) . sa_btn('استورد من المنصة', 'soft', '?import=1', 'refresh')) ?>
<?php else: ?>
  <div class="ad-media-grid" data-sortable>
    <?php foreach ($rows as $i => $r): $im = s_img($r); ?>
      <div class="ad-mi sa-in<?= $r['is_active'] ? '' : ' off' ?>" data-row data-id="<?= (int) $r['id'] ?>" data-st="<?= $r['is_active'] ? 'on' : 'off' ?>" data-cat="<?= e(trim((string) $r['category'])) ?>" style="<?= $r['is_active'] ? '' : 'opacity:.55' ?>">
        <span class="im" style="background-image:url('<?= e($im) ?>')"></span>
        <span class="mt"><b><?= e($r['title'] ?: 'بدون عنوان') ?></b><small><?= e($srcLabels[$r['source']] ?? '') ?><?= $r['category'] ? ' · ' . e($r['category']) : '' ?></small></span>
        <div class="ma">
          <span class="ad-ib ad-grab" data-grab title="اسحب لإعادة الترتيب" aria-hidden="true"><?= sa_icon('grip', 16) ?></span>
          <?= sa_switch('t' . (int) $r['id'], (bool) $r['is_active'], '', ['data-toggle-id' => (string) (int) $r['id'], 'aria-label' => 'إظهار']) ?>
          <span class="ad-sp"></span>
          <a class="ad-ib" href="?edit=<?= (int) $r['id'] ?>" aria-label="تعديل" title="تعديل"><?= sa_icon('edit', 16) ?></a>
          <form method="POST" data-confirm="هتحذف التصميم ده من المعرض — متأكد؟"><?= s_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="ad-ib del" aria-label="حذف" title="حذف"><?= sa_icon('trash', 16) ?></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="ad-foot-hint"><?= sa_icon('grip', 14) ?>اسحب التصميم لإعادة الترتيب — بيتحفظ تلقائيًا.</p>
<?php endif; ?>

<?= sa_drawer_open('crud', $editing ? 'تعديل تصميم' : 'إضافة تصميم', (bool) $editing || !empty($_GET['new']), 'enctype="multipart/form-data"') ?>
  <?= s_csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
  <div class="ad-form one">
    <?= sa_field(['name' => 'title', 'label' => 'العنوان', 'max' => 200], $editing['title'] ?? '') ?>
    <?= sa_field(['name' => 'category', 'label' => 'التصنيف', 'ph' => 'بوستات / ستوريز / بورتريه / لوجوهات / قبل / بعد', 'hint' => 'التصنيف بيحدد تبويب التصميم في الموقع.'], $editing['category'] ?? '') ?>
    <?= sa_field(['name' => 'image', 'label' => 'الصورة', 'type' => 'image'], '', $editing ?? []) ?>
    <?= sa_field(['name' => 'sort_order', 'label' => 'الترتيب', 'type' => 'number', 'hint' => 'سيبه 0 للإضافة في الآخر.'], (int) ($editing['sort_order'] ?? 0)) ?>
    <?= sa_field(['name' => 'is_active', 'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهر في الموقع'], $editing ? (int) $editing['is_active'] : 1) ?>
  </div>
<?= sa_drawer_close($editing ? 'حفظ التعديلات' : 'إضافة') ?>

<?php include __DIR__ . '/layout-end.php'; ?>
