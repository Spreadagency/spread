<?php
/**
 * Spread AI v2 — الأدمن: مظهر الموقع
 * اللوجو + الاسم + الألوان + رسالة الهيدر + الفوتر + زرار الواتساب العائم
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/uploader.php';

require_admin();
require_admin_can('site_settings');
$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    if ($action === 'ui_v2') {
        $m = in_array($_POST['ui_v2_mode'] ?? '', ['off', 'optin', 'all'], true) ? $_POST['ui_v2_mode'] : 'optin';
        set_setting('ui_v2_mode', $m);
        set_setting('ui_v2_thinking', !empty($_POST['ui_v2_thinking']) ? '1' : '0');
        admin_log('ui_v2_mode', 'settings', null, $m);
        flash_set('success', 'تم حفظ إعداد الواجهة ✓');
        redirect('admin/appearance.php');
    }

    if ($action === 'save') {
        set_setting('site_name', mb_substr(trim($_POST['site_name'] ?? ''), 0, 100) ?: APP_NAME);
        set_setting('site_tagline', mb_substr(trim($_POST['site_tagline'] ?? ''), 0, 150));

        // ألوان (hex فقط أو فاضي = الافتراضي)
        foreach (['theme_primary', 'theme_primary_2', 'theme_primary_ink'] as $k) {
            $v = trim($_POST[$k] ?? '');
            set_setting($k, preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : '');
        }

        set_setting('header_message', mb_substr(trim($_POST['header_message'] ?? ''), 0, 200));
        set_setting('footer_text', mb_substr(trim($_POST['footer_text'] ?? ''), 0, 300));
        set_setting('whatsapp_float', preg_replace('/[^0-9]/', '', $_POST['whatsapp_float'] ?? ''));
        set_setting('whatsapp_float_msg', mb_substr(trim($_POST['whatsapp_float_msg'] ?? ''), 0, 200));

        // اللوجو
        if (!empty($_FILES['site_logo']['name'])) {
            $r = upload_image($_FILES['site_logo'], 'branding');
            if ($r['ok']) {
                $old = (string) get_setting('site_logo', '');
                if ($old !== '') {
                    delete_upload($old);
                }
                set_setting('site_logo', $r['path']);
            } else {
                flash_set('warning', 'الإعدادات اتحفظت لكن اللوجو فشل: ' . ($r['error'] ?? ''));
            }
        }

        admin_log('site_appearance', 'settings', null, 'updated');
        flash_set('success', 'تم حفظ مظهر الموقع ✓ — التغييرات ظاهرة فورًا لكل المستخدمين');
        redirect('admin/appearance.php');
    }

    if ($action === 'remove_logo') {
        $old = (string) get_setting('site_logo', '');
        if ($old !== '') {
            delete_upload($old);
            set_setting('site_logo', '');
        }
        flash_set('success', 'رجعنا للوجو الافتراضي');
        redirect('admin/appearance.php');
    }

    if ($action === 'reset_colors') {
        set_setting('theme_primary', '');
        set_setting('theme_primary_2', '');
        set_setting('theme_primary_ink', '');
        flash_set('success', 'رجعنا للألوان الافتراضية');
        redirect('admin/appearance.php');
    }
}

$siteName    = site_setting('site_name', APP_NAME);
$siteTagline = site_setting('site_tagline', defined('APP_TAGLINE') ? APP_TAGLINE : '');
$siteLogo    = site_setting('site_logo', '');
$tp  = site_setting('theme_primary', '');
$tp2 = site_setting('theme_primary_2', '');
$tpi = site_setting('theme_primary_ink', '');
$headerMsg = site_setting('header_message', '');
$footerTxt = site_setting('footer_text', '');
$waFloat   = site_setting('whatsapp_float', '');
$waMsg     = site_setting('whatsapp_float_msg', '');

$active = 'appearance';
$page_title = 'مظهر الموقع';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>مظهر الموقع 🎛</h1>
            <div class="sub">اللوجو والألوان والرسائل — بتظهر فورًا لكل العملاء</div>
        </div>

        <?= render_flash() ?>

        <!-- الواجهة الجديدة: فورم لوحدها (كانت متداخلة جوه فورم المظهر فكانت بتكسر زرار «حفظ المظهر») -->
        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3>✨ الواجهة الجديدة (v2)</h3></div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ui_v2">
                <?php $__m = get_setting('ui_v2_mode', 'optin'); ?>
                <?php foreach ([
                    'off'   => ['🔒 مقفولة', 'الشكل القديم للكل'],
                    'optin' => ['🧪 تجريبية', 'القديم افتراضيًا — والعميل يقدر يجرّب الجديد من ملفه الشخصي'],
                    'all'   => ['🚀 للكل', 'الجديد افتراضيًا — والعميل يقدر يرجع للقديم'],
                ] as $k => [$lbl, $hint]): ?>
                    <label style="display:flex;gap:10px;align-items:flex-start;padding:11px 13px;border:1px solid var(--line);border-radius:12px;margin-bottom:8px;cursor:pointer">
                        <input type="radio" name="ui_v2_mode" value="<?= $k ?>" <?= $__m === $k ? 'checked' : '' ?> style="width:auto;margin-top:3px">
                        <span><b><?= $lbl ?></b><br><span class="sub" style="font-size:12px"><?= e($hint) ?></span></span>
                    </label>
                <?php endforeach; ?>
                <label style="display:flex;gap:9px;align-items:center;margin:12px 0 14px;cursor:pointer">
                    <input type="checkbox" name="ui_v2_thinking" value="1" <?= get_setting('ui_v2_thinking', '1') === '1' ? 'checked' : '' ?> style="width:auto">
                    <span>شاشة «Spread AI يفكر...» مع كل عملية AI</span>
                </label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn">💾 حفظ</button>
                    <a href="<?= url('dashboard.php?ui=v2') ?>" target="_blank" class="btn ghost">👁 معاينة (بحساب عميل)</a>
                </div>
                <p class="field-help" style="margin-top:10px">
                    المعاينة بتشتغل بإضافة <code>?ui=v2</code> لأي رابط في المنصة — ولإلغائها <code>?ui=v1</code>.
                </p>
            </form>
        </div>
        <form method="POST" data-safe-post enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="split split-2">

                <div>

                    <div class="card" style="margin-bottom:20px">
                        <div class="card-head"><h3>الهوية الأساسية</h3></div>
                        <div class="field">
                            <label>اسم الموقع</label>
                            <input type="text" name="site_name" class="input" value="<?= e($siteName) ?>">
                        </div>
                        <div class="field">
                            <label>السطر التعريفي (تحت الاسم)</label>
                            <input type="text" name="site_tagline" class="input" value="<?= e($siteTagline) ?>" placeholder="منصة المحتوى بالذكاء الاصطناعي">
                        </div>
                        <div class="field">
                            <label>اللوجو (مربع — PNG شفاف مفضّل)</label>
                            <?php if ($siteLogo): ?>
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                                    <img src="<?= url('storage/' . $siteLogo) ?>" style="width:52px;height:52px;object-fit:contain;background:var(--surface-2);border-radius:12px;padding:6px" alt="">
                                    <span class="sub">اللوجو الحالي</span>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="site_logo" class="input" accept="image/png,image/webp,image/jpeg,image/svg+xml">
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head"><h3>ألوان الموقع</h3></div>
                        <p class="sub" style="margin-bottom:12px">سيب أي لون فاضي = القيمة الافتراضية (البنفسجي)</p>
                        <div class="field-row">
                            <div class="field">
                                <label>اللون الأساسي</label>
                                <div style="display:flex;gap:6px">
                                    <input type="color" value="<?= e($tp ?: '#7c6df2') ?>" oninput="this.nextElementSibling.value=this.value" style="width:44px;height:40px;border:none;border-radius:8px;cursor:pointer">
                                    <input type="text" name="theme_primary" class="input" dir="ltr" value="<?= e($tp) ?>" placeholder="#7c6df2">
                                </div>
                            </div>
                            <div class="field">
                                <label>اللون الثانوي</label>
                                <div style="display:flex;gap:6px">
                                    <input type="color" value="<?= e($tp2 ?: '#a89bff') ?>" oninput="this.nextElementSibling.value=this.value" style="width:44px;height:40px;border:none;border-radius:8px;cursor:pointer">
                                    <input type="text" name="theme_primary_2" class="input" dir="ltr" value="<?= e($tp2) ?>" placeholder="#a89bff">
                                </div>
                            </div>
                            <div class="field">
                                <label>لون النص الداكن</label>
                                <div style="display:flex;gap:6px">
                                    <input type="color" value="<?= e($tpi ?: '#4a3db8') ?>" oninput="this.nextElementSibling.value=this.value" style="width:44px;height:40px;border:none;border-radius:8px;cursor:pointer">
                                    <input type="text" name="theme_primary_ink" class="input" dir="ltr" value="<?= e($tpi) ?>" placeholder="#4a3db8">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="card" style="margin-bottom:20px">
                        <div class="card-head"><h3>الهيدر والفوتر</h3></div>
                        <div class="field">
                            <label>رسالة أعلى الموقع (شريط ملوّن — سيبها فاضية لإخفائها)</label>
                            <input type="text" name="header_message" class="input" value="<?= e($headerMsg) ?>" placeholder="مثال: 🎉 خصم 20% على كل الباقات لنهاية الشهر">
                        </div>
                        <div class="field">
                            <label>سطر الفوتر</label>
                            <input type="text" name="footer_text" class="input" value="<?= e($footerTxt) ?>" placeholder="مثال: جميع الحقوق محفوظة © Spread Agency 2026">
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head"><h3>زرار واتساب عائم 💬</h3></div>
                        <div class="field">
                            <label>رقم الواتساب بكود الدولة (فاضي = مفيش زرار)</label>
                            <input type="text" name="whatsapp_float" class="input" dir="ltr" value="<?= e($waFloat) ?>" placeholder="201xxxxxxxxx">
                        </div>
                        <div class="field">
                            <label>الرسالة الجاهزة لما العميل يدوس</label>
                            <input type="text" name="whatsapp_float_msg" class="input" value="<?= e($waMsg) ?>">
                        </div>
                        <p class="sub" style="font-size:12px">بيظهر زرار أخضر عائم أسفل شمال كل صفحات العملاء</p>
                    </div>

                    <button type="submit" class="btn full" style="margin-top:16px">💾 حفظ المظهر</button>
                </div>
            </div>
        </form>

        <div style="display:flex;gap:8px;margin-top:12px">
            <?php if ($siteLogo): ?>
            <form method="POST" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="remove_logo"><button class="btn sm">↩ اللوجو الافتراضي</button></form>
            <?php endif; ?>
            <?php if ($tp || $tp2 || $tpi): ?>
            <form method="POST" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="reset_colors"><button class="btn sm">↩ الألوان الافتراضية</button></form>
            <?php endif; ?>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
