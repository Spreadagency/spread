<?php
/** مكتبة الوسائط — كل الصور المرفوعة في الموقع: رفع · بحث · نسخ اللينك · النص البديل · حذف · (JSON للاختيار من أي فورم) */
require_once __DIR__ . '/auth.php';
sa_require();

$canManage = sa_can('media');

/* اختيار من أي فورم: أي حد عنده صلاحية على أي قسم فيه صور يقدر يشوف القائمة */
if (isset($_GET['json'])) {
    $items = [];
    foreach (s_all('SELECT * FROM site_media ORDER BY id DESC LIMIT 300') as $m) {
        $items[] = ['id' => (int) $m['id'], 'url' => SITE_UPLOAD_URL . '/' . $m['path'], 'name' => (string) ($m['original_name'] ?: $m['path']),
                    'dim' => $m['width'] ? $m['width'] . '×' . $m['height'] : ''];
    }
    sa_json(['ok' => true, 'items' => $items]);
}

/* رفع صورة من أي محرر (Page Builder · الفورمز) — بيرجّع اللينك */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_ajax') {
    sa_check_csrf_json();
    if (!sa_can('media') && !sa_can('pages') && !sa_can('content') && !sa_can('homepage')) sa_json(['ok' => false, 'error' => 'مش ضمن صلاحياتك'], 403);
    $up = !empty($_FILES['file']['name']) ? s_upload($_FILES['file'], 'media') : ['ok' => false, 'error' => 'اختار صورة'];
    if (!$up['ok']) sa_json(['ok' => false, 'error' => $up['error']]);
    sa_log('upload', 'media', 'رفع صورة «' . mb_substr((string) $_FILES['file']['name'], 0, 80) . '»');
    sa_json(['ok' => true, 'url' => SITE_UPLOAD_URL . '/' . $up['path']]);
}

sa_require_perm('media');

/* ملفات اترفعت قبل المكتبة: نسجّلها مرة واحدة علشان تظهر */
if (s_setting('media_scanned') !== '1' && is_dir(SITE_UPLOAD_DIR)) {
    foreach (glob(SITE_UPLOAD_DIR . '/*.{jpg,jpeg,png,webp,gif,svg}', GLOB_BRACE) ?: [] as $f) {
        $info = @getimagesize($f);
        s_run('INSERT IGNORE INTO site_media (path, original_name, mime, size, width, height, created_at) VALUES (?,?,?,?,?,?, FROM_UNIXTIME(?))',
            [basename($f), basename($f), $info['mime'] ?? null, (int) @filesize($f), $info[0] ?? null, $info[1] ?? null, (int) @filemtime($f)]);
    }
    s_set('media_scanned', '1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
    s_decode_b64();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'upload') {
        $files = $_FILES['files'] ?? null;
        $ok = 0; $errs = [];
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $n) {
                $one = ['name' => $n, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                $up = s_upload($one, 'media');
                $up['ok'] ? $ok++ : $errs[] = $n . ': ' . $up['error'];
            }
        }
        if ($ok) sa_log('upload', 'media', "رفع {$ok} ملف لمكتبة الوسائط");
        s_flash($errs ? ($ok ? 'warning' : 'danger') : 'success', $ok ? "اترفع {$ok} ملف ✓" . ($errs ? ' — ومشاكل: ' . implode(' · ', array_slice($errs, 0, 3)) : '') : ($errs ? implode(' · ', array_slice($errs, 0, 3)) : 'اختار ملفات الأول'));
        s_redirect('site-admin/media.php');
    }
    if ($action === 'alt') {
        $id = (int) ($_POST['id'] ?? 0);
        s_run('UPDATE site_media SET alt = ? WHERE id = ?', [mb_substr(trim((string) ($_POST['alt'] ?? '')), 0, 255) ?: null, $id]);
        sa_log('update', 'media', 'تعديل النص البديل لملف #' . $id, $id);
        s_flash('success', 'اتحفظ ✓');
        s_redirect('site-admin/media.php');
    }
    if ($action === 'delete') {
        $m = s_one('SELECT * FROM site_media WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if ($m) {
            s_run('DELETE FROM site_media WHERE id = ?', [(int) $m['id']]);
            $f = SITE_UPLOAD_DIR . '/' . basename((string) $m['path']);
            if (is_file($f)) @unlink($f);
            sa_log('delete', 'media', 'حذف ملف «' . ($m['original_name'] ?: $m['path']) . '» من مكتبة الوسائط', (int) $m['id']);
            s_flash('success', 'الملف اتحذف');
        }
        s_redirect('site-admin/media.php');
    }
}

$per = 48;
$page = max(1, (int) ($_GET['page'] ?? 1));
$q = trim((string) ($_GET['q'] ?? ''));
$where = $q !== '' ? 'WHERE original_name LIKE ? OR alt LIKE ? OR path LIKE ?' : '';
$params = $q !== '' ? ["%{$q}%", "%{$q}%", "%{$q}%"] : [];
$total = (int) (s_one("SELECT COUNT(*) c FROM site_media {$where}", $params)['c'] ?? 0);
$pages = max(1, (int) ceil($total / $per));
$rows = s_all("SELECT * FROM site_media {$where} ORDER BY id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);
$size = (int) (s_one('SELECT COALESCE(SUM(size),0) s FROM site_media')['s'] ?? 0);
$editing = !empty($_GET['edit']) ? s_one('SELECT * FROM site_media WHERE id = ?', [(int) $_GET['edit']]) : null;

$__t = 'مكتبة الوسائط';
include __DIR__ . '/layout.php';
echo sa_page_head('folder', 'مكتبة الوسائط', 'Media Library', 'كل الصور اللي اترفعت في الموقع في مكان واحد — تقدر تختار منها في أي فورم من زرار «المكتبة».');
?>
<form method="POST" enctype="multipart/form-data" class="ad-card sa-in" style="margin-top:18px">
  <?= s_csrf_field() ?><input type="hidden" name="action" value="upload">
  <div class="ad-drop" data-drop style="padding:22px">
    <span class="ad-drop-pv"><?= sa_icon('upload', 22) ?></span>
    <span class="ad-drop-t"><b data-drop-name>اسحب الصور هنا أو اضغط للاختيار</b><small>JPG · PNG · WebP · GIF · SVG — لحد 6 ميجا للصورة · تقدر تختار كذا صورة مرة واحدة</small></span>
    <input type="file" name="files[]" accept="image/*" multiple aria-label="رفع صور" onchange="this.closest('form').querySelector('[data-up]').hidden=!this.files.length;this.closest('.ad-drop').querySelector('[data-drop-name]').textContent=this.files.length+' ملف جاهز للرفع'">
  </div>
  <div data-up hidden style="margin-top:12px"><?= sa_btn('ارفع', 'pri', null, 'upload', ['type' => 'submit']) ?></div>
</form>

<div class="ad-bar">
  <form class="ad-search" method="GET" role="search"><span><?= sa_icon('search', 17) ?></span><input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث باسم الملف أو النص البديل..." aria-label="ابحث في الوسائط"></form>
  <span class="ad-count"><b><?= $total ?></b> ملف · <?= number_format($size / 1048576, 1) ?> ميجا</span>
</div>

<?php if (!$rows): ?>
  <?= sa_empty('folder', $q !== '' ? 'مفيش نتائج' : 'المكتبة فاضية', $q !== '' ? 'جرّب كلمة تانية.' : 'ارفع صور من فوق — أو أي صورة بترفعها في أي قسم بتتسجّل هنا تلقائيًا.') ?>
<?php else: ?>
  <div class="ad-media-grid">
    <?php foreach ($rows as $m): $url = SITE_UPLOAD_URL . '/' . $m['path']; $abs = rtrim(SITE_URL, '/') . $url; ?>
      <div class="ad-mi sa-in">
        <span class="im" style="background-image:url('<?= e($url) ?>')" role="img" aria-label="<?= e($m['alt'] ?: $m['original_name']) ?>"></span>
        <span class="mt"><b title="<?= e($m['original_name']) ?>"><?= e($m['original_name'] ?: $m['path']) ?></b>
          <small><?= $m['width'] ? (int) $m['width'] . '×' . (int) $m['height'] . ' · ' : '' ?><?= number_format(((int) $m['size']) / 1024) ?> KB<?= $m['alt'] ? ' · Alt ✓' : '' ?></small></span>
        <div class="ma">
          <button type="button" class="ad-ib" data-copy="<?= e($abs) ?>" aria-label="نسخ اللينك" title="نسخ اللينك"><?= sa_icon('link', 16) ?></button>
          <a class="ad-ib" href="?edit=<?= (int) $m['id'] ?>" aria-label="النص البديل" title="النص البديل (Alt)"><?= sa_icon('edit', 16) ?></a>
          <a class="ad-ib" href="<?= e($url) ?>" target="_blank" rel="noopener" aria-label="فتح" title="فتح"><?= sa_icon('external', 16) ?></a>
          <span class="ad-sp"></span>
          <form method="POST" data-confirm="هتحذف الملف ده نهائيًا — لو مستخدم في صفحة أو قسم هيختفي من هناك. متأكد؟"><?= s_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="ad-ib del" aria-label="حذف" title="حذف"><?= sa_icon('trash', 16) ?></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?= sa_pager($page, $pages, $q !== '' ? ['q' => $q] : []) ?>
<?php endif; ?>

<?php if ($editing): ?>
<?= sa_drawer_open('alt', 'النص البديل (Alt)', true) ?>
  <?= s_csrf_field() ?><input type="hidden" name="action" value="alt"><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
  <img src="<?= e(SITE_UPLOAD_URL . '/' . $editing['path']) ?>" alt="" style="width:100%;max-height:260px;object-fit:contain;border-radius:16px;background:#EEF3F8">
  <?= sa_field(['name' => 'alt', 'label' => 'وصف الصورة (للقارئات الصوتية وجوجل)', 'max' => 255], $editing['alt']) ?>
  <div class="ad-f"><span class="ad-label">اللينك</span><div class="ad-drop-x"><input class="ad-in sm" readonly dir="ltr" value="<?= e(rtrim(SITE_URL, '/') . SITE_UPLOAD_URL . '/' . $editing['path']) ?>"><button type="button" class="ad-btn ad-soft sm" data-copy="<?= e(rtrim(SITE_URL, '/') . SITE_UPLOAD_URL . '/' . $editing['path']) ?>"><?= sa_icon('copy', 15) ?>نسخ</button></div></div>
<?= sa_drawer_close() ?>
<?php endif; ?>
<?php include __DIR__ . '/layout-end.php'; ?>
