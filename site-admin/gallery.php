<?php
/**
 * معرض التصميمات — رفع / لينك / استيراد من تصميمات المنصة
 */
require_once __DIR__ . '/auth.php';
sa_require();

/* ─── الاتصال بقاعدة المنصة (قراءة فقط) لاستيراد التصميمات ─── */
function platform_pdo(): ?PDO
{
    static $p = null;
    static $tried = false;
    if ($tried) return $p;
    $tried = true;
    $cfg = dirname(__DIR__) . '/includes/config.php';
    if (!is_file($cfg)) return null;
    $src = file_get_contents($cfg);
    $get = function (string $c) use ($src) {
        return preg_match("/define\(\s*'" . $c . "'\s*,\s*'([^']*)'/", $src, $m) ? $m[1] : null;
    };
    $h = $get('DB_HOST'); $n = $get('DB_NAME'); $u = $get('DB_USER'); $w = $get('DB_PASS');
    if (!$n || !$u) return null;
    try {
        $p = new PDO("mysql:host=" . ($h ?: 'localhost') . ";dbname={$n};charset=utf8mb4", $u, (string) $w, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (\Throwable $e) {
        $p = null;
    }
    return $p;
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
    s_check_csrf();
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
        s_flash($added ? 'success' : 'danger', $added ? "تم استيراد {$added} تصميم من المنصة ✓" : 'مفيش تصميمات جديدة اتضافت');
        s_redirect('site-admin/gallery.php');
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id ? s_one('SELECT * FROM site_gallery WHERE id = ?', [$id]) : null;

        $url = trim((string) ($_POST['image_url'] ?? ''));
        if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');

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

        $data = [
            mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200) ?: null,
            $path,
            $url !== '' ? mb_substr($url, 0, 700) : null,
            mb_substr(trim((string) ($_POST['category'] ?? '')), 0, 80) ?: null,
            (int) ($_POST['sort_order'] ?? 0),
            !empty($_POST['is_active']) ? 1 : 0,
        ];

        if ($id) {
            $data[] = $id;
            s_run('UPDATE site_gallery SET title=?, image_path=?, image_url=?, category=?, sort_order=?, is_active=? WHERE id=?', $data);
            s_flash('success', 'تم الحفظ ✓');
        } else {
            array_splice($data, 3, 0, [$url !== '' ? 'link' : 'upload']);
            s_insert('INSERT INTO site_gallery (title, image_path, image_url, source, category, sort_order, is_active) VALUES (?,?,?,?,?,?,?)', $data);
            s_flash('success', 'تمت الإضافة ✓');
        }
        s_redirect('site-admin/gallery.php');
    }

    if ($action === 'toggle') {
        s_run('UPDATE site_gallery SET is_active = 1 - is_active WHERE id = ?', [(int) $_POST['id']]);
        s_redirect('site-admin/gallery.php');
    }

    if ($action === 'delete') {
        $r = s_one('SELECT * FROM site_gallery WHERE id = ?', [(int) $_POST['id']]);
        if ($r) {
            if (($r['source'] ?? '') === 'upload' && $r['image_path']) s_delete_upload($r['image_path']);
            s_run('DELETE FROM site_gallery WHERE id = ?', [(int) $_POST['id']]);
            s_flash('success', 'تم الحذف');
        }
        s_redirect('site-admin/gallery.php');
    }

    if ($action === 'reorder') {
        foreach (($_POST['order'] ?? []) as $rid => $ord) {
            s_run('UPDATE site_gallery SET sort_order = ? WHERE id = ?', [(int) $ord, (int) $rid]);
        }
        s_flash('success', 'تم حفظ الترتيب ✓');
        s_redirect('site-admin/gallery.php');
    }
}

$editing = !empty($_GET['edit']) ? s_one('SELECT * FROM site_gallery WHERE id = ?', [(int) $_GET['edit']]) : null;
$rows = s_all('SELECT * FROM site_gallery ORDER BY sort_order, id');
$showImport = isset($_GET['import']);
$pDesigns = $showImport ? platform_designs() : [];
$existingRefs = array_column(s_all('SELECT platform_ref FROM site_gallery WHERE platform_ref IS NOT NULL'), 'platform_ref');

$__t = 'التصميمات (المعرض)';
include __DIR__ . '/layout.php';
?>

<div class="card" style="background:rgba(15,60,201,.06);border-color:rgba(15,60,201,.2)">
  تقدر تضيف تصميمات بثلاث طرق: <b>ترفعها</b> · <b>لينك مباشر</b> · أو <b>تستوردها من التصميمات اللي اتعملت في المنصة</b>.
  <div style="margin-top:10px">
    <a href="?import=1" class="btn s">🔗 استورد من تصميمات المنصة</a>
    <a href="<?= e(s_page_url('designs')) ?>" target="_blank" class="btn g s">👁 شوف صفحة التصميمات</a>
  </div>
</div>

<?php if ($showImport): ?>
  <div class="card">
    <h3>استيراد من المنصة</h3>
    <?php if (!$pDesigns): ?>
      <p style="color:var(--dim);font-size:14px">
        مفيش تصميمات متاحة — تأكد إن المنصة متركّبة في نفس المجلد وإن ملف <code>includes/config.php</code> فيه بيانات قاعدة بياناتها.
      </p>
    <?php else: ?>
      <form method="POST" data-safe-post>
        <?= s_csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <div class="row" style="margin-bottom:12px">
          <div class="f"><label>تصنيف للمستورد (اختياري)</label><input type="text" name="import_category" placeholder="سوشيال ميديا"></div>
          <div class="f"><label>عنوان موحّد (اختياري)</label><input type="text" name="import_title" placeholder="تصميم من المنصة"></div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;max-height:460px;overflow-y:auto;padding:4px">
          <?php foreach ($pDesigns as $d):
            $ref = $d['src'] . ':' . $d['id'];
            $already = in_array($ref, $existingRefs, true);
            $imgUrl = PLATFORM_STORAGE_URL . '/' . ltrim((string) $d['image_path'], '/');
          ?>
            <label style="position:relative;cursor:<?= $already ? 'default' : 'pointer' ?>;opacity:<?= $already ? '.42' : '1' ?>">
              <input type="checkbox" name="pick[]" value="<?= e($d['src'] . '|' . $d['id'] . '|' . $d['image_path']) ?>"
                     <?= $already ? 'disabled' : '' ?> style="position:absolute;top:6px;inset-inline-start:6px;z-index:2;width:18px;height:18px">
              <img src="<?= e($imgUrl) ?>" alt="" loading="lazy"
                   style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:10px;background:#eee">
              <?php if ($already): ?><span class="chip" style="position:absolute;bottom:6px;inset-inline-start:6px;font-size:10px">مضاف</span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="btn" style="margin-top:14px">⬇ استورد المختار</button>
        <a href="gallery.php" class="btn g">إلغاء</a>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h3><?= $editing ? '✎ تعديل تصميم' : '＋ إضافة تصميم' ?></h3>
  <form method="POST" enctype="multipart/form-data" data-safe-post>
    <?= s_csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <div class="row">
      <div class="f"><label>العنوان</label><input type="text" name="title" value="<?= e($editing['title'] ?? '') ?>"></div>
      <div class="f"><label>التصنيف</label><input type="text" name="category" value="<?= e($editing['category'] ?? '') ?>" placeholder="سوشيال / لوجو / بانر"></div>
      <div class="f"><label>الترتيب</label><input type="number" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>"></div>
      <div class="f">
        <label>الظهور</label>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400">
          <input type="checkbox" name="is_active" value="1" <?= ($editing ? $editing['is_active'] : 1) ? 'checked' : '' ?> style="width:auto"> ظاهر
        </label>
      </div>
      <div class="f" style="grid-column:1/-1">
        <label>الصورة</label>
        <?php $prev = $editing ? s_img($editing) : ''; ?>
        <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
          <?php if ($prev): ?><img src="<?= e($prev) ?>" class="thumb" style="width:70px;height:70px" alt=""><?php endif; ?>
          <div style="flex:1;min-width:220px">
            <input type="file" name="image" accept="image/*" style="font-size:12.5px;margin-bottom:7px">
            <input type="text" name="image_url" dir="ltr" value="<?= e($editing['image_url'] ?? '') ?>" placeholder="أو لينك صورة مباشر">
          </div>
        </div>
      </div>
    </div>
    <button class="btn"><?= $editing ? '💾 حفظ' : '＋ إضافة' ?></button>
    <?php if ($editing): ?><a href="gallery.php" class="btn g">إلغاء</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>التصميمات (<?= count($rows) ?>)</h3>
  <?php if (!$rows): ?>
    <p style="color:var(--dim);font-size:14px">مفيش تصميمات لسه.</p>
  <?php else: ?>
    <form method="POST" data-safe-post>
      <?= s_csrf_field() ?>
      <input type="hidden" name="action" value="reorder">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px">
        <?php foreach ($rows as $r): $im = s_img($r); ?>
          <div style="border:1px solid var(--line);border-radius:12px;padding:8px;<?= $r['is_active'] ? '' : 'opacity:.5' ?>">
            <?php if ($im): ?>
              <img src="<?= e($im) ?>" alt="" loading="lazy" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;background:#eee">
            <?php endif; ?>
            <div style="font-size:12px;margin-top:6px;min-height:18px"><?= e(mb_substr((string) $r['title'], 0, 26)) ?></div>
            <div style="font-size:10.5px;color:var(--dim)">
              <?= ['upload' => '⬆ مرفوع', 'platform' => '🔗 من المنصة', 'link' => '🌐 لينك'][$r['source']] ?? '' ?>
              <?= $r['category'] ? ' · ' . e($r['category']) : '' ?>
            </div>
            <div style="display:flex;gap:4px;align-items:center;margin-top:7px">
              <input type="number" name="order[<?= (int) $r['id'] ?>]" value="<?= (int) $r['sort_order'] ?>" style="width:52px;padding:4px;font-size:12px">
              <a href="?edit=<?= (int) $r['id'] ?>" class="btn g s">✎</a>
              <button type="submit" form="tg-<?= (int) $r['id'] ?>" class="btn g s"><?= $r['is_active'] ? '🚫' : '👁' ?></button>
              <button type="submit" form="dl-<?= (int) $r['id'] ?>" class="btn d s">🗑</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="btn g" style="margin-top:14px">↕ حفظ الترتيب</button>
    </form>
    <?php foreach ($rows as $r): ?>
      <form id="tg-<?= (int) $r['id'] ?>" method="POST" style="display:none"><?= s_csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"></form>
      <form id="dl-<?= (int) $r['id'] ?>" method="POST" style="display:none" onsubmit="return confirm('حذف التصميم؟')"><?= s_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"></form>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/layout-end.php'; ?>
