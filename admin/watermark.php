<?php
/**
 * Spread AI v2 — الأدمن: العلامة المائية (Phase 5)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploader.php';
require_once __DIR__ . '/../includes/watermark.php';

require_admin();
require_admin_can('site_settings');
$admin = current_admin();

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    // ── رفع لوجو العلامة ──
    if ($action === 'upload_logo' && !empty($_FILES['logo']['name'])) {
        $r = upload_image($_FILES['logo'], 'branding');
        if ($r['ok']) {
            // امسح القديم
            $old = (string) get_setting('watermark_logo', '');
            if ($old !== '' && $old !== $r['path']) {
                delete_upload($old);
            }
            set_setting('watermark_logo', $r['path']);
            admin_log('watermark_logo', 'settings', null, $r['path']);
            flash_set('success', 'تم رفع لوجو العلامة المائية ✓ (الأفضل PNG بخلفية شفافة)');
        } else {
            flash_set('danger', $r['error'] ?? 'فشل الرفع');
        }
        redirect('admin/watermark.php');
    }

    // ── حفظ الإعدادات ──
    if ($action === 'save_settings') {
        set_setting('watermark_enabled', !empty($_POST['enabled']) ? '1' : '0');
        set_setting('watermark_size', (string) max(3, min(40, (int) ($_POST['size'] ?? 12))));
        set_setting('watermark_opacity', (string) max(5, min(100, (int) ($_POST['opacity'] ?? 40))));
        $pos = in_array($_POST['position'] ?? '', ['bottom-right', 'bottom-left', 'top-right', 'top-left'], true) ? $_POST['position'] : 'bottom-right';
        set_setting('watermark_position', $pos);
        set_setting('watermark_padding', (string) max(0, min(15, (int) ($_POST['padding'] ?? 3))));
        set_setting('watermark_keep_original', !empty($_POST['keep_original']) ? '1' : '0');
        admin_log('watermark_settings', 'settings', null, json_encode($_POST));
        flash_set('success', 'تم حفظ الإعدادات ✓');
        redirect('admin/watermark.php');
    }

    // ── اختبار على صورة ──
    if ($action === 'test' && !empty($_FILES['test_image']['name'])) {
        $r = upload_image($_FILES['test_image'], 'tmp');
        if ($r['ok']) {
            $wm = watermark_apply($r['path'], ['keep_original' => '0']);
            if ($wm['ok']) {
                $testResult = $r['path'];
            } else {
                delete_upload($r['path']);
                flash_set('danger', 'فشل الاختبار: ' . ($wm['error'] ?? ''));
            }
        } else {
            flash_set('danger', $r['error'] ?? 'فشل رفع صورة الاختبار');
        }
    }
}

$enabled  = get_setting('watermark_enabled', '0') === '1';
$logo     = (string) get_setting('watermark_logo', '');
$size     = (int) get_setting('watermark_size', 12);
$opacity  = (int) get_setting('watermark_opacity', 40);
$position = (string) get_setting('watermark_position', 'bottom-right');
$padding  = (int) get_setting('watermark_padding', 3);
$keepOrig = get_setting('watermark_keep_original', '1') === '1';
$gdOk     = function_exists('imagecreatetruecolor');

$posLabels = [
    'bottom-right' => 'أسفل اليمين (الافتراضي)',
    'bottom-left'  => 'أسفل الشمال',
    'top-right'    => 'أعلى اليمين',
    'top-left'     => 'أعلى الشمال',
];
// CSS للمعاينة
$posCss = match ($position) {
    'bottom-left' => 'bottom:PADpx;left:PADpx;',
    'top-right'   => 'top:PADpx;right:PADpx;',
    'top-left'    => 'top:PADpx;left:PADpx;',
    default       => 'bottom:PADpx;right:PADpx;',
};
$previewPad = (int) round(300 * $padding / 100);
$posCss = str_replace('PAD', (string) $previewPad, $posCss);

$active = 'watermark';
$page_title = 'العلامة المائية';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>العلامة المائية 💧</h1>
            <div class="sub">لوجو المنصة يتحط تلقائيًا صغير وشفاف على كل تصميم بيتولّد</div>
        </div>

        <?= render_flash() ?>

        <?php if (!$gdOk): ?>
            <div class="alert danger">⚠ مكتبة GD غير مفعّلة على السيرفر — فعّلها من إعدادات PHP (php-gd) الأول.</div>
        <?php endif; ?>

        <div class="split split-2">

            <!-- الإعدادات -->
            <div>
                <div class="card" style="margin-bottom:20px">
                    <div class="card-head"><h3>1) لوجو العلامة</h3></div>
                    <?php if ($logo): ?>
                        <div style="background:repeating-conic-gradient(#eee 0 25%,#fff 0 50%) 0 0/16px 16px;border-radius:8px;padding:14px;display:inline-block;margin-bottom:10px">
                            <img src="<?= url('storage/' . $logo) ?>" style="max-height:70px" alt="watermark logo">
                        </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_logo">
                        <div class="field" style="flex:1">
                            <label>ملف اللوجو (PNG شفاف مفضّل)</label>
                            <input type="file" name="logo" class="input" accept="image/png,image/webp,image/jpeg" required>
                        </div>
                        <button type="submit" class="btn btn-primary">⬆ رفع</button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-head"><h3>2) الإعدادات</h3></div>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_settings">

                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;cursor:pointer">
                            <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                            <b>تفعيل العلامة المائية على التصميمات الجديدة</b>
                        </label>

                        <div class="field-row">
                            <div class="field">
                                <label>الحجم: <b id="v-size"><?= $size ?></b>% من عرض الصورة</label>
                                <input type="range" name="size" min="3" max="40" value="<?= $size ?>" oninput="prev()" id="in-size" style="width:100%">
                            </div>
                            <div class="field">
                                <label>الشفافية: <b id="v-op"><?= $opacity ?></b>%</label>
                                <input type="range" name="opacity" min="5" max="100" value="<?= $opacity ?>" oninput="prev()" id="in-op" style="width:100%">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field">
                                <label>المكان</label>
                                <select name="position" class="input" id="in-pos" onchange="prev()">
                                    <?php foreach ($posLabels as $k => $v): ?>
                                        <option value="<?= $k ?>" <?= $position === $k ? 'selected' : '' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>المسافة من الحافة: <b id="v-pad"><?= $padding ?></b>%</label>
                                <input type="range" name="padding" min="0" max="15" value="<?= $padding ?>" oninput="prev()" id="in-pad" style="width:100%">
                            </div>
                        </div>

                        <label style="display:flex;align-items:center;gap:8px;margin:10px 0;cursor:pointer">
                            <input type="checkbox" name="keep_original" value="1" <?= $keepOrig ? 'checked' : '' ?>>
                            <span class="sub">الاحتفاظ بنسخة أصلية بدون علامة (في designs/originals/)</span>
                        </label>

                        <button type="submit" class="btn btn-primary" style="width:100%">💾 حفظ الإعدادات</button>
                    </form>
                </div>
            </div>

            <!-- المعاينة والاختبار -->
            <div>
                <div class="card" style="margin-bottom:20px">
                    <div class="card-head"><h3>معاينة فورية</h3></div>
                    <div id="preview-box" style="position:relative;width:300px;height:300px;border-radius:10px;overflow:hidden;background:linear-gradient(135deg,#4a3db8,#8e44ad 55%,#e67e22);margin:0 auto">
                        <?php if ($logo): ?>
                            <img id="preview-logo" src="<?= url('storage/' . $logo) ?>"
                                 style="position:absolute;<?= $posCss ?>width:<?= (int) round(300 * $size / 100) ?>px;opacity:<?= $opacity / 100 ?>" alt="">
                        <?php else: ?>
                            <div class="sub" style="color:#fff;padding:20px">ارفع اللوجو الأول 👈</div>
                        <?php endif; ?>
                    </div>
                    <p class="sub" style="text-align:center;margin-top:8px;font-size:12px">المعاينة تقريبية — النتيجة النهائية من الاختبار تحت</p>
                </div>

                <div class="card">
                    <div class="card-head"><h3>اختبار حقيقي</h3></div>
                    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="test">
                        <div class="field" style="flex:1">
                            <label>ارفع أي صورة وشوف النتيجة الفعلية</label>
                            <input type="file" name="test_image" class="input" accept="image/*" required>
                        </div>
                        <button type="submit" class="btn">🔬 اختبار</button>
                    </form>
                    <?php if ($testResult): ?>
                        <img src="<?= url('storage/' . $testResult) ?>?t=<?= time() ?>" style="width:100%;border-radius:10px;margin-top:12px" alt="test result">
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
function prev() {
    const size = document.getElementById('in-size').value;
    const op = document.getElementById('in-op').value;
    const pad = document.getElementById('in-pad').value;
    const pos = document.getElementById('in-pos').value;
    document.getElementById('v-size').textContent = size;
    document.getElementById('v-op').textContent = op;
    document.getElementById('v-pad').textContent = pad;
    const l = document.getElementById('preview-logo');
    if (!l) return;
    const padPx = Math.round(300 * pad / 100);
    l.style.width = Math.round(300 * size / 100) + 'px';
    l.style.opacity = op / 100;
    l.style.top = l.style.bottom = l.style.left = l.style.right = 'auto';
    if (pos.includes('top')) l.style.top = padPx + 'px'; else l.style.bottom = padPx + 'px';
    if (pos.includes('left')) l.style.left = padPx + 'px'; else l.style.right = padPx + 'px';
}
</script>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
