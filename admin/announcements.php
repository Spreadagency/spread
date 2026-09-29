<?php
/**
 * Spread AI v2 — الأدمن: الإعلانات والعروض
 * رسايل وعروض بتظهر للعملاء في لوحتهم على شكل صور
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploader.php';

require_admin();
require_admin_can('site_settings');
$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 180);
        if ($title === '') {
            flash_set('danger', 'اكتب عنوان الإعلان');
            redirect('admin/announcements.php');
        }
        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $r = upload_image($_FILES['image'], 'announcements');
            if ($r['ok']) {
                $imagePath = $r['path'];
            } else {
                flash_set('danger', 'فشل رفع الصورة: ' . ($r['error'] ?? ''));
                redirect('admin/announcements.php');
            }
        }
        $starts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['starts_at'] ?? '') ? $_POST['starts_at'] : null;
        $ends   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ends_at'] ?? '') ? $_POST['ends_at'] : null;
        $link   = trim($_POST['link_url'] ?? '');
        db_insert(
            'INSERT INTO announcements (title, body, image_path, link_url, starts_at, ends_at, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$title, mb_substr(trim($_POST['body'] ?? ''), 0, 1000) ?: null, $imagePath,
             $link !== '' ? mb_substr($link, 0, 500) : null, $starts, $ends, (int) ($_POST['sort_order'] ?? 0)]
        );
        admin_log('add_announcement', 'announcement', null, $title);
        flash_set('success', 'تم نشر الإعلان ✓ — ظاهر للعملاء دلوقتي');
        redirect('admin/announcements.php');
    }

    if ($action === 'toggle') {
        db_run('UPDATE announcements SET is_active = 1 - is_active WHERE id = ?', [(int) $_POST['ann_id']]);
        redirect('admin/announcements.php');
    }

    if ($action === 'delete') {
        $a = db_one('SELECT image_path FROM announcements WHERE id = ?', [(int) $_POST['ann_id']]);
        if ($a) {
            if ($a['image_path']) {
                delete_upload($a['image_path']);
            }
            db_run('DELETE FROM announcements WHERE id = ?', [(int) $_POST['ann_id']]);
            admin_log('delete_announcement', 'announcement', (int) $_POST['ann_id']);
            flash_set('success', 'تم الحذف');
        }
        redirect('admin/announcements.php');
    }
}

$items = db_all('SELECT * FROM announcements ORDER BY sort_order, id DESC');

$active = 'announcements';
$page_title = 'الإعلانات والعروض';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>الإعلانات والعروض 📣</h1>
            <div class="sub">رسايل وعروض بتظهر للعملاء في لوحتهم الرئيسية على شكل صور — بتاريخ بداية ونهاية اختياري</div>
        </div>

        <?= render_flash() ?>

        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3>إعلان جديد</h3></div>
            <form method="POST" data-safe-post enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="field-row">
                    <div class="field">
                        <label>العنوان <span class="req">*</span></label>
                        <input type="text" name="title" class="input" required placeholder="مثال: عرض العيد — كريدت مضاعف 🎉">
                    </div>
                    <div class="field">
                        <label>صورة الإعلان (مفضّل 1200×400 أو مربعة)</label>
                        <input type="file" name="image" class="input" accept="image/jpeg,image/png,image/webp">
                    </div>
                </div>
                <div class="field">
                    <label>نص إضافي (اختياري)</label>
                    <textarea name="body" class="textarea" rows="2" placeholder="تفاصيل العرض..."></textarea>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>لينك عند الضغط (اختياري)</label>
                        <input type="text" name="link_url" class="input" dir="ltr" placeholder="https://... أو credits.php">
                    </div>
                    <div class="field">
                        <label>يبدأ من</label>
                        <input type="date" name="starts_at" class="input">
                    </div>
                    <div class="field">
                        <label>ينتهي في</label>
                        <input type="date" name="ends_at" class="input">
                    </div>
                    <div class="field">
                        <label>الترتيب</label>
                        <input type="number" name="sort_order" class="input" value="0">
                    </div>
                </div>
                <button type="submit" class="btn">📣 نشر الإعلان</button>
            </form>
        </div>

        <div class="auto-grid">
            <?php foreach ($items as $a):
                $expired = $a['ends_at'] && $a['ends_at'] < date('Y-m-d');
            ?>
                <div class="card" style="padding:10px;<?= $a['is_active'] && !$expired ? '' : 'opacity:.55' ?>">
                    <?php if ($a['image_path']): ?>
                        <img src="<?= url('storage/' . $a['image_path']) ?>" style="width:100%;border-radius:8px;max-height:160px;object-fit:cover" alt="">
                    <?php endif; ?>
                    <b style="display:block;margin-top:8px"><?= e($a['title']) ?></b>
                    <?php if ($a['body']): ?><p class="sub" style="font-size:13px"><?= e(mb_substr($a['body'], 0, 100)) ?></p><?php endif; ?>
                    <div class="sub" style="font-size:11px;margin:6px 0">
                        <?= $a['starts_at'] ? 'من ' . e($a['starts_at']) : '' ?>
                        <?= $a['ends_at'] ? ' إلى ' . e($a['ends_at']) : '' ?>
                        <?= $expired ? ' · <span style="color:#c0392b">منتهي</span>' : '' ?>
                        <?= $a['link_url'] ? ' · 🔗' : '' ?>
                    </div>
                    <div style="display:flex;gap:6px">
                        <form method="POST" data-safe-post style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="ann_id" value="<?= $a['id'] ?>"><button class="btn sm"><?= $a['is_active'] ? 'إيقاف' : 'تشغيل' ?></button></form>
                        <form method="POST" data-safe-post style="display:inline" onsubmit="return confirm('حذف الإعلان؟')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="ann_id" value="<?= $a['id'] ?>"><button class="btn sm" style="color:#c0392b">🗑</button></form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$items): ?><p class="sub">مفيش إعلانات — أضف أول عرض 👆</p><?php endif; ?>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
