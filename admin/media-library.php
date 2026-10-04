<?php
/**
 * Spread AI v2 — الأدمن: مكتبة التصميمات / الستوديو (Phase 4)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploader.php';

require_admin();
require_admin_can('studio_media');
$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // رفع متعدد
    if ($action === 'upload' && !empty($_FILES['files']['name'][0])) {
        $uploaded = 0;
        $count = count($_FILES['files']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $file = [
                'name'     => $_FILES['files']['name'][$i],
                'type'     => $_FILES['files']['type'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'error'    => $_FILES['files']['error'][$i],
                'size'     => $_FILES['files']['size'][$i],
            ];
            $r = upload_image($file, 'library');
            if (!$r['ok']) continue;
            $title = trim($_POST['title'] ?? '') ?: pathinfo($file['name'], PATHINFO_FILENAME);
            db_insert(
                'INSERT INTO media_library (title, category, style_tags, image_path, description, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    mb_substr($title, 0, 180),
                    mb_substr(trim($_POST['category'] ?? 'general') ?: 'general', 0, 80),
                    mb_substr(trim($_POST['style_tags'] ?? ''), 0, 255),
                    $r['path'],
                    mb_substr(trim($_POST['description'] ?? ''), 0, 1000),
                    $admin['id'],
                ]
            );
            $uploaded++;
        }
        admin_log('studio_upload', 'media_library', null, $uploaded . ' items');
        flash_set($uploaded ? 'success' : 'danger', $uploaded ? "تم رفع {$uploaded} تصميم للستوديو ✓" : 'مفيش ملفات اترفعت — تأكد من النوع (JPG/PNG/WEBP) والحجم');
        redirect('admin/media-library.php');
    }

    // إضافة بلينكات خارجية (سطر لكل لينك)
    if (($_POST['action'] ?? '') === 'add_links') {
        $raw = trim($_POST['links'] ?? '');
        $added = 0;
        foreach (preg_split('/[\r\n]+/', $raw) as $line) {
            $u = trim($line);
            if ($u === '') continue;
            if (!preg_match('#^https?://#i', $u)) {
                $u = 'https://' . ltrim($u, '/');
            }
            if (!filter_var($u, FILTER_VALIDATE_URL) || mb_strlen($u) > 1000) continue;
            db_insert(
                'INSERT INTO media_library (title, category, style_tags, image_url, description, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    mb_substr(trim($_POST['title'] ?? '') ?: 'مرجع من لينك', 0, 180),
                    mb_substr(trim($_POST['category'] ?? 'general') ?: 'general', 0, 80),
                    mb_substr(trim($_POST['style_tags'] ?? ''), 0, 255),
                    $u,
                    mb_substr(trim($_POST['description'] ?? ''), 0, 1000),
                    $admin['id'],
                ]
            );
            $added++;
        }
        admin_log('studio_links', 'media_library', null, $added . ' links');
        flash_set($added ? 'success' : 'danger', $added ? "تم إضافة {$added} لينك للمعرض ✓" : 'مفيش لينكات صالحة');
        redirect('admin/media-library.php');
    }

    // تعديل بيانات عنصر
    if ($action === 'update_item') {
        $id = (int) ($_POST['media_id'] ?? 0);
        db_run(
            'UPDATE media_library SET title = ?, category = ?, style_tags = ?, description = ? WHERE id = ?',
            [
                mb_substr(trim($_POST['title'] ?? ''), 0, 180),
                mb_substr(trim($_POST['category'] ?? 'general') ?: 'general', 0, 80),
                mb_substr(trim($_POST['style_tags'] ?? ''), 0, 255),
                mb_substr(trim($_POST['description'] ?? ''), 0, 1000),
                $id,
            ]
        );
        flash_set('success', 'تم التحديث ✓');
        redirect('admin/media-library.php');
    }

    if ($action === 'toggle') {
        db_run('UPDATE media_library SET is_active = 1 - is_active WHERE id = ?', [(int) $_POST['media_id']]);
        redirect('admin/media-library.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['media_id'] ?? 0);
        $row = db_one('SELECT image_path, image_url FROM media_library WHERE id = ?', [$id]);
        if ($row) {
            if (!empty($row['image_path'])) {
                delete_upload($row['image_path']);
            }
            db_run('DELETE FROM media_library WHERE id = ?', [$id]);
            admin_log('studio_delete', 'media_library', $id);
            flash_set('success', 'تم الحذف');
        }
        redirect('admin/media-library.php');
    }
}

$filter = trim($_GET['cat'] ?? '');
$where = $filter !== '' ? ' WHERE category = ?' : '';
$params = $filter !== '' ? [$filter] : [];
$items = db_all('SELECT m.*, (SELECT COUNT(*) FROM user_media_selections s WHERE s.media_id = m.id) AS picks FROM media_library m' . $where . ' ORDER BY m.id DESC', $params);
$cats = db_all('SELECT category, COUNT(*) AS c FROM media_library GROUP BY category ORDER BY c DESC');

$active = 'studio';
$page_title = 'الستوديو — مكتبة التصميمات';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>الستوديو 🎨</h1>
            <div class="sub">ارفع أشكال تصميمات — العملاء يختاروا المفضل عندهم والـ AI يولّد على ذوقهم</div>
        </div>

        <?= render_flash() ?>

        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3>رفع تصميمات (متعدد)</h3></div>
            <form method="POST" enctype="multipart/form-data" style="display:grid;grid-template-columns:1fr 1fr 1fr 1.4fr auto;gap:10px;align-items:end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload">
                <div class="field"><label>العنوان (اختياري)</label><input type="text" name="title" class="input" placeholder="لو فاضي: اسم الملف"></div>
                <div class="field"><label>التصنيف</label><input type="text" name="category" class="input" list="cats" placeholder="general"></div>
                <datalist id="cats"><?php foreach ($cats as $c): ?><option value="<?= e($c['category']) ?>"><?php endforeach; ?></datalist>
                <div class="field"><label>Style Tags (مهم للـ AI)</label><input type="text" name="style_tags" class="input" placeholder="minimal, luxury, bold"></div>
                <div class="field"><label>الملفات</label><input type="file" name="files[]" class="input" accept="image/jpeg,image/png,image/webp" multiple required></div>
                <button type="submit" class="btn btn-primary">⬆ رفع</button>
            </form>

            <div style="border-top:1px dashed var(--line);margin-top:16px;padding-top:16px">
                <h3 style="font-size:15px;margin-bottom:4px">🔗 أو أضف بلينكات (من غير رفع)</h3>
                <p class="sub" style="margin-bottom:10px;font-size:12px">الصور بتتعرض من مصدرها مباشرة — لينك في كل سطر. لازم يكون لينك صورة مباشر (ينتهي بـ .jpg/.png/.webp غالبًا) وHTTPS.</p>
                <form method="POST" data-safe-post style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_links">
                    <div class="field"><label>العنوان</label><input type="text" name="title" class="input" placeholder="مرجع من لينك"></div>
                    <div class="field"><label>التصنيف</label><input type="text" name="category" class="input" list="cats" placeholder="general"></div>
                    <div class="field"><label>Style Tags</label><input type="text" name="style_tags" class="input" placeholder="minimal, luxury"></div>
                    <button type="submit" class="btn">＋ إضافة</button>
                    <div class="field" style="grid-column:1/-1;margin:0">
                        <label>اللينكات (سطر لكل صورة)</label>
                        <textarea name="links" class="textarea" rows="3" dir="ltr" required data-no-encode="1"
                                  placeholder="https://example.com/design1.jpg&#10;https://example.com/design2.png"></textarea>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($cats): ?>
        <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
            <a href="<?= url('admin/media-library.php') ?>" class="chip <?= $filter === '' ? 'chip-primary' : '' ?>">الكل</a>
            <?php foreach ($cats as $c): ?>
                <a href="?cat=<?= urlencode($c['category']) ?>" class="chip <?= $filter === $c['category'] ? 'chip-primary' : '' ?>"><?= e($c['category']) ?> (<?= $c['c'] ?>)</a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">
            <?php foreach ($items as $m): ?>
                <div class="card" style="padding:10px;<?= $m['is_active'] ? '' : 'opacity:.5' ?>">
                    <img src="<?= e(media_display_url($m)) ?>" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px" loading="lazy" alt=""
                         onerror="this.style.opacity=.25;this.title='اللينك مش شغال'">
                    <?php if (!empty($m['image_url'])): ?><span class="chip" style="font-size:10px;padding:1px 6px">🔗 لينك</span><?php endif; ?>
                    <form method="POST" style="margin-top:8px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_item">
                        <input type="hidden" name="media_id" value="<?= $m['id'] ?>">
                        <input type="text" name="title" class="input" style="margin-bottom:5px;font-size:13px" value="<?= e($m['title']) ?>">
                        <div style="display:flex;gap:5px;margin-bottom:5px">
                            <input type="text" name="category" class="input" style="font-size:12px" value="<?= e($m['category']) ?>" placeholder="تصنيف">
                            <input type="text" name="style_tags" class="input" style="font-size:12px" value="<?= e($m['style_tags'] ?? '') ?>" placeholder="tags">
                        </div>
                        <div class="sub" style="font-size:11px;margin-bottom:6px">❤ اختاره <?= (int) $m['picks'] ?> عميل</div>
                        <button type="submit" class="btn btn-sm">حفظ</button>
                    </form>
                    <div style="display:flex;gap:5px;margin-top:5px">
                        <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="media_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn btn-sm"><?= $m['is_active'] ? 'إخفاء' : 'إظهار' ?></button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('حذف التصميم نهائيًا؟')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="media_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="color:#c0392b">🗑</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$items): ?><p class="sub">مفيش تصميمات — ارفع أول مجموعة 👆</p><?php endif; ?>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
