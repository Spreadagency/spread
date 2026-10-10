<?php
/**
 * Spread AI v2 — الأدمن: الإعلانات والعروض
 * رسايل وعروض بتظهر للعملاء في لوحتهم على شكل صور
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploader.php';
require_once __DIR__ . '/../includes/announcements.php';   // سلايدر الرئيسية: زرار · جمهور · باقة · مواعيد

require_admin();
require_admin_can('site_settings');
$admin = current_admin();
ann_ensure_schema();

/** 2026-10-01T09:00 أو 2026-10-01 ← DATETIME (النهاية من غير ساعة = آخر اليوم) */
function ann_dt(string $v, bool $end): ?string
{
    $v = trim($v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v)) return str_replace('T', ' ', $v) . ':00';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v . ($end ? ' 23:59:59' : ' 00:00:00');
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    // السلايد الأساسي (لما مفيش إعلانات — ولما فيه بيظهر معاهم)
    if ($action === 'default_slide') {
        set_setting('support_slide_title', mb_substr(trim($_POST['support_slide_title'] ?? ''), 0, 120));
        set_setting('support_slide_body', mb_substr(trim($_POST['support_slide_body'] ?? ''), 0, 300));
        set_setting('support_whatsapp', preg_replace('/[^0-9]/', '', (string) ($_POST['support_whatsapp'] ?? '')));
        set_setting('support_whatsapp_msg', mb_substr(trim($_POST['support_whatsapp_msg'] ?? ''), 0, 200));
        admin_log('update_default_slide', 'announcement', null, 'support');
        flash_set('success', 'اتحفظ السلايد الأساسي ✓');
        redirect('admin/announcements.php');
    }

    if ($action === 'add' || $action === 'update') {
        $editId = $action === 'update' ? (int) ($_POST['ann_id'] ?? 0) : 0;
        $old = $editId ? db_one('SELECT * FROM announcements WHERE id = ?', [$editId]) : null;
        if ($action === 'update' && !$old) {
            flash_set('danger', 'الإعلان مش موجود');
            redirect('admin/announcements.php');
        }
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 180);
        if ($title === '') {
            flash_set('danger', 'اكتب عنوان الإعلان');
            redirect('admin/announcements.php' . ($editId ? '?edit=' . $editId : ''));
        }
        $imagePath = $old['image_path'] ?? null;
        if (!empty($_FILES['image']['name'])) {
            $r = upload_image($_FILES['image'], 'announcements');
            if ($r['ok']) {
                if ($imagePath) delete_upload($imagePath);
                $imagePath = $r['path'];
            } else {
                flash_set('danger', 'فشل رفع الصورة: ' . ($r['error'] ?? ''));
                redirect('admin/announcements.php' . ($editId ? '?edit=' . $editId : ''));
            }
        }
        $starts = ann_dt((string) ($_POST['starts_at'] ?? ''), false);
        $ends   = ann_dt((string) ($_POST['ends_at'] ?? ''), true);
        if ($starts && $ends && $ends < $starts) {
            flash_set('danger', 'تاريخ النهاية قبل البداية');
            redirect('admin/announcements.php' . ($editId ? '?edit=' . $editId : ''));
        }
        $link   = trim($_POST['link_url'] ?? '');
        $audience = ($_POST['audience'] ?? 'all') === 'package' ? 'package' : 'all';
        $pkgId = $audience === 'package' ? (int) ($_POST['package_id'] ?? 0) : 0;
        if ($audience === 'package' && !db_one('SELECT id FROM credit_packages WHERE id = ?', [$pkgId])) {
            flash_set('danger', 'اختار الباقة');
            redirect('admin/announcements.php' . ($editId ? '?edit=' . $editId : ''));
        }
        $vals = [$title, mb_substr(trim($_POST['body'] ?? ''), 0, 1000) ?: null, $imagePath,
                 $link !== '' ? mb_substr($link, 0, 500) : null, mb_substr(trim($_POST['btn_label'] ?? ''), 0, 60) ?: null,
                 $audience, $pkgId ?: null, $starts, $ends, (int) ($_POST['sort_order'] ?? 0)];
        if ($editId) {
            db_run('UPDATE announcements SET title = ?, body = ?, image_path = ?, link_url = ?, btn_label = ?, audience = ?, package_id = ?,
                    starts_at = ?, ends_at = ?, sort_order = ? WHERE id = ?', array_merge($vals, [$editId]));
            admin_log('update_announcement', 'announcement', $editId, $title);
            flash_set('success', 'اتحدّث الإعلان ✓');
        } else {
            db_insert('INSERT INTO announcements (title, body, image_path, link_url, btn_label, audience, package_id, starts_at, ends_at, sort_order)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals);
            admin_log('add_announcement', 'announcement', null, $title);
            flash_set('success', 'تم نشر الإعلان ✓ — ظاهر في سلايدر الرئيسية');
        }
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
$packages = db_all('SELECT id, name FROM credit_packages ORDER BY order_num, id');
$pkgNames = array_column($packages, 'name', 'id');
$edit = !empty($_GET['edit']) ? db_one('SELECT * FROM announcements WHERE id = ?', [(int) $_GET['edit']]) : null;
$dtv = fn($v) => $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : '';
$def = ann_default_slide();

$active = 'announcements';
$page_title = 'الإعلانات والعروض';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>الإعلانات والعروض 📣</h1>
            <div class="sub">سلايدر الرئيسية: صورة + معلومة سريعة + زرار — لكل العملاء أو لباقة معيّنة، بتاريخ بداية ونهاية. لو مفيش إعلانات، الأساسي «تواصل مع خدمة العملاء» (وبيفضل موجود مع الإعلانات).</div>
        </div>

        <?= render_flash() ?>

        <div class="card" style="margin-bottom:20px" id="form">
            <div class="card-head"><h3><?= $edit ? 'تعديل الإعلان' : 'إعلان جديد' ?></h3><?php if ($edit): ?><a href="<?= url('admin/announcements.php') ?>" class="btn ghost sm">إلغاء التعديل</a><?php endif; ?></div>
            <form method="POST" data-safe-post enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
                <?php if ($edit): ?><input type="hidden" name="ann_id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
                <div class="field-row">
                    <div class="field">
                        <label>العنوان <span class="req">*</span></label>
                        <input type="text" name="title" class="input" required maxlength="180" value="<?= e($edit['title'] ?? '') ?>" placeholder="مثال: عرض العيد — كريدت مضاعف 🎉">
                    </div>
                    <div class="field">
                        <label>صورة السلايد (مفضّل مربعة أو 4:3)<?= !empty($edit['image_path']) ? ' — سيبها فاضية تفضل القديمة' : '' ?></label>
                        <input type="file" name="image" class="input" accept="image/jpeg,image/png,image/webp">
                    </div>
                </div>
                <div class="field">
                    <label>المعلومة السريعة (اختياري)</label>
                    <textarea name="body" class="textarea" rows="2" maxlength="1000" placeholder="تفاصيل العرض في سطر أو اتنين..."><?= e($edit['body'] ?? '') ?></textarea>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>لينك الزرار (اختياري — من غيره مفيش زرار)</label>
                        <input type="text" name="link_url" class="input" dir="ltr" value="<?= e($edit['link_url'] ?? '') ?>" placeholder="https://... أو credits.php">
                    </div>
                    <div class="field">
                        <label>نص الزرار</label>
                        <input type="text" name="btn_label" class="input" maxlength="60" value="<?= e($edit['btn_label'] ?? '') ?>" placeholder="اعرف أكتر">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>يظهر لمين؟</label>
                        <select name="audience" class="input" onchange="document.getElementById('ann-pkg').style.display = this.value === 'package' ? '' : 'none'">
                            <option value="all" <?= ($edit['audience'] ?? 'all') === 'all' ? 'selected' : '' ?>>كل العملاء</option>
                            <option value="package" <?= ($edit['audience'] ?? '') === 'package' ? 'selected' : '' ?>>باقة معيّنة</option>
                        </select>
                    </div>
                    <div class="field" id="ann-pkg" style="<?= ($edit['audience'] ?? '') === 'package' ? '' : 'display:none' ?>">
                        <label>الباقة</label>
                        <select name="package_id" class="input">
                            <?php foreach ($packages as $pk): ?>
                                <option value="<?= (int) $pk['id'] ?>" <?= (int) ($edit['package_id'] ?? 0) === (int) $pk['id'] ? 'selected' : '' ?>><?= e($pk['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>يبدأ من</label>
                        <input type="datetime-local" name="starts_at" class="input" value="<?= e($dtv($edit['starts_at'] ?? null)) ?>">
                    </div>
                    <div class="field">
                        <label>ينتهي في</label>
                        <input type="datetime-local" name="ends_at" class="input" value="<?= e($dtv($edit['ends_at'] ?? null)) ?>">
                    </div>
                    <div class="field">
                        <label>الترتيب</label>
                        <input type="number" name="sort_order" class="input" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
                    </div>
                </div>
                <button type="submit" class="btn"><?= $edit ? '💾 حفظ التعديل' : '📣 نشر الإعلان' ?></button>
            </form>
        </div>

        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3>🎧 السلايد الأساسي — تواصل مع خدمة العملاء</h3></div>
            <p class="sub" style="font-size:12.5px;margin-top:0">بيظهر لوحده لو مفيش إعلانات شغالة للعميل، ومع الإعلانات لو فيه.</p>
            <form method="POST" data-safe-post>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="default_slide">
                <div class="field-row">
                    <div class="field"><label>العنوان</label><input type="text" name="support_slide_title" class="input" maxlength="120" value="<?= e($def['title']) ?>"></div>
                    <div class="field"><label>واتساب خدمة العملاء (بكود الدولة)</label><input type="tel" name="support_whatsapp" class="input" dir="ltr" value="<?= e((string) get_setting('support_whatsapp', '')) ?>" placeholder="201xxxxxxxxx — فاضي = رقم الواتساب العائم"></div>
                </div>
                <div class="field-row">
                    <div class="field"><label>النص</label><input type="text" name="support_slide_body" class="input" maxlength="300" value="<?= e($def['body']) ?>"></div>
                    <div class="field"><label>رسالة الواتساب الجاهزة</label><input type="text" name="support_whatsapp_msg" class="input" maxlength="200" value="<?= e((string) get_setting('support_whatsapp_msg', 'أهلًا، محتاج مساعدة في Spread AI')) ?>"></div>
                </div>
                <button type="submit" class="btn ghost">حفظ السلايد الأساسي</button>
            </form>
        </div>

        <div class="auto-grid">
            <?php foreach ($items as $a):
                $expired = $a['ends_at'] && strtotime((string) $a['ends_at']) < time();
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
                        <?= $a['link_url'] ? ' · 🔗 ' . e($a['btn_label'] ?: 'اعرف أكتر') : '' ?>
                        · <?= ($a['audience'] ?? 'all') === 'package' ? '🎯 ' . e($pkgNames[(int) $a['package_id']] ?? 'باقة محذوفة') : '👥 كل العملاء' ?>
                    </div>
                    <div style="display:flex;gap:6px">
                        <a href="<?= url('admin/announcements.php?edit=' . (int) $a['id']) ?>#form" class="btn ghost sm">✎ تعديل</a>
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
