<?php
/**
 * Spread AI v2 — الأدمن: تعديل هوية أي عميل (Phase 6)
 * لوجو + ألوان + كل تفاصيل البراند
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploader.php';

require_admin();
$admin = current_admin();

$userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$user = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
if (!$user) {
    flash_set('danger', 'المستخدم غير موجود');
    redirect('admin/users.php');
}

$brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ?', [$userId]);

// عمود personal_image_path موجود من features-upgrade — نتأكد عشان التوافق
$hasPersonal = (bool) db_one(
    "SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'brand_profiles' AND column_name = 'personal_image_path'"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    // إنشاء بروفايل لو مش موجود
    if (!$brand) {
        $bid = db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, "")', [$userId]);
        $brand = db_one('SELECT * FROM brand_profiles WHERE id = ?', [$bid]);
    }

    if ($action === 'save_brand') {
        db_run(
            'UPDATE brand_profiles SET business_name = ?, industry = ?, description = ?, audience = ?,
             tone = ?, colors = ?, dialect = ?, keywords_use = ?, keywords_avoid = ?, notes = ? WHERE id = ?',
            [
                mb_substr(trim($_POST['business_name'] ?? ''), 0, 255),
                mb_substr(trim($_POST['industry'] ?? ''), 0, 150),
                mb_substr(trim($_POST['description'] ?? ''), 0, 5000),
                mb_substr(trim($_POST['audience'] ?? ''), 0, 5000),
                mb_substr(trim($_POST['tone'] ?? ''), 0, 100),
                mb_substr(trim($_POST['colors'] ?? ''), 0, 100),
                mb_substr(trim($_POST['dialect'] ?? ''), 0, 50),
                mb_substr(trim($_POST['keywords_use'] ?? ''), 0, 2000),
                mb_substr(trim($_POST['keywords_avoid'] ?? ''), 0, 2000),
                mb_substr(trim($_POST['notes'] ?? ''), 0, 5000),
                $brand['id'],
            ]
        );

        // لوجو البراند
        if (!empty($_FILES['logo']['name'])) {
            $r = upload_image($_FILES['logo'], 'brand-images');
            if ($r['ok']) {
                if (!empty($brand['logo_path'])) {
                    delete_upload($brand['logo_path']);
                }
                db_run('UPDATE brand_profiles SET logo_path = ? WHERE id = ?', [$r['path'], $brand['id']]);
            } else {
                flash_set('warning', 'اتحفظت البيانات لكن اللوجو فشل: ' . ($r['error'] ?? ''));
            }
        }

        // صورة شخصية (لو العمود موجود)
        if ($hasPersonal && !empty($_FILES['personal_image']['name'])) {
            $r = upload_image($_FILES['personal_image'], 'brand-images');
            if ($r['ok']) {
                if (!empty($brand['personal_image_path'])) {
                    delete_upload($brand['personal_image_path']);
                }
                db_run('UPDATE brand_profiles SET personal_image_path = ? WHERE id = ?', [$r['path'], $brand['id']]);
            }
        }

        admin_log('admin_edit_brand', 'brand_profile', (int) $brand['id'], 'user #' . $userId);
        flash_set('success', 'تم حفظ هوية العميل ✓');
        redirect('admin/user-brand.php?user_id=' . $userId);
    }

    if ($action === 'remove_logo' && !empty($brand['logo_path'])) {
        delete_upload($brand['logo_path']);
        db_run('UPDATE brand_profiles SET logo_path = NULL WHERE id = ?', [$brand['id']]);
        admin_log('admin_remove_brand_logo', 'brand_profile', (int) $brand['id']);
        flash_set('success', 'تم حذف اللوجو');
        redirect('admin/user-brand.php?user_id=' . $userId);
    }
}

$b = $brand ?: [];

$active = 'users';
$page_title = 'هوية العميل: ' . e($user['name']);
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <h1>هوية العميل ◈</h1>
                <div class="sub"><?= e($user['name']) ?> — <?= e($user['email']) ?></div>
            </div>
            <a href="<?= url('admin/user-view.php?id=' . $userId) ?>" class="btn">→ ملف العميل</a>
        </div>

        <?= render_flash() ?>

        <form method="POST" data-safe-post enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_brand">
            <input type="hidden" name="user_id" value="<?= $userId ?>">

            <div class="split split-l">

                <div class="card">
                    <div class="card-head"><h3>بيانات النشاط</h3></div>

                    <div class="field-row">
                        <div class="field">
                            <label>اسم النشاط</label>
                            <input type="text" name="business_name" class="input" value="<?= e($b['business_name'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>المجال</label>
                            <input type="text" name="industry" class="input" value="<?= e($b['industry'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label>وصف البراند</label>
                        <textarea name="description" class="textarea" rows="3"><?= e($b['description'] ?? '') ?></textarea>
                    </div>
                    <div class="field">
                        <label>الجمهور المستهدف</label>
                        <textarea name="audience" class="textarea" rows="2"><?= e($b['audience'] ?? '') ?></textarea>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>نبرة الكتابة</label>
                            <input type="text" name="tone" class="input" value="<?= e($b['tone'] ?? '') ?>" placeholder="ودّي، احترافي، مرح...">
                        </div>
                        <div class="field">
                            <label>اللهجة</label>
                            <select name="dialect" class="input">
                                <option value="">— افتراضي —</option>
                                <option value="egyptian" <?= ($b['dialect'] ?? '') === 'egyptian' ? 'selected' : '' ?>>مصرية</option>
                                <option value="gulf" <?= ($b['dialect'] ?? '') === 'gulf' ? 'selected' : '' ?>>خليجية</option>
                                <option value="msa" <?= ($b['dialect'] ?? '') === 'msa' ? 'selected' : '' ?>>فصحى مبسطة</option>
                            </select>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>كلمات يفضل استخدامها</label>
                            <textarea name="keywords_use" class="textarea" rows="2"><?= e($b['keywords_use'] ?? '') ?></textarea>
                        </div>
                        <div class="field">
                            <label>كلمات ممنوعة</label>
                            <textarea name="keywords_avoid" class="textarea" rows="2"><?= e($b['keywords_avoid'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="field">
                        <label>ملاحظات إضافية</label>
                        <textarea name="notes" class="textarea" rows="2"><?= e($b['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <div>
                    <div class="card" style="margin-bottom:20px">
                        <div class="card-head"><h3>اللوجو</h3></div>
                        <?php if (!empty($b['logo_path'])): ?>
                            <img src="<?= e(upload_url($b['logo_path'])) ?>" style="max-height:80px;border-radius:10px;background:#f6f5fb;padding:8px;margin-bottom:8px" alt="logo">
                        <?php endif; ?>
                        <div class="field">
                            <label><?= !empty($b['logo_path']) ? 'استبدال اللوجو' : 'رفع لوجو' ?></label>
                            <input type="file" name="logo" class="input" accept="image/*">
                        </div>
                    </div>

                    <?php if ($hasPersonal): ?>
                    <div class="card" style="margin-bottom:20px">
                        <div class="card-head"><h3>صورة شخصية / منتج</h3></div>
                        <?php if (!empty($b['personal_image_path'])): ?>
                            <img src="<?= e(upload_url($b['personal_image_path'])) ?>" style="max-height:80px;border-radius:10px;margin-bottom:8px" alt="">
                        <?php endif; ?>
                        <div class="field">
                            <input type="file" name="personal_image" class="input" accept="image/*">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card">
                        <div class="card-head"><h3>ألوان البراند</h3></div>
                        <div class="field">
                            <input type="text" name="colors" class="input" value="<?= e($b['colors'] ?? '') ?>" placeholder="مثال: #1a2b5f, #d4af37">
                        </div>
                        <p class="sub" style="font-size:12px">بتدخل في برومبت التصميمات</p>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px">💾 حفظ هوية العميل</button>
                </div>
            </div>
        </form>

        <?php if (!empty($b['logo_path'])): ?>
        <form method="POST" data-safe-post style="margin-top:12px" onsubmit="return confirm('حذف لوجو العميل؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_logo">
            <input type="hidden" name="user_id" value="<?= $userId ?>">
            <button type="submit" class="btn btn-sm" style="color:#c0392b">🗑 حذف اللوجو الحالي</button>
        </form>
        <?php endif; ?>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
