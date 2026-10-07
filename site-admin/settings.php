<?php
/** إعدادات الموقع: الهيدر والفوتر والألوان والروابط */
require_once __DIR__ . '/auth.php';
sa_require();

$groups = [
    'الهوية' => [
        ['site_name', 'اسم الموقع', 'text'],
        ['tagline', 'السطر التعريفي', 'text'],
        ['logo', 'اللوجو', 'logo'],
    ],
    'الهيرو والشريط المتحرك' => [
        // شرائح الهيرو نفسها (العنوان · الشارة · الروبوت · الأزرار) من «السلايدر»
        ['ribbon_text', 'عناصر الشريط المتحرك (سطر لكل عنصر)', 'textarea'],
    ],
    'أزرار المنصة' => [
        ['platform_login_url', 'رابط تسجيل الدخول', 'text'],
        ['platform_register_url', 'رابط حساب جديد', 'text'],
    ],
    'قسم «جاهز نبدأ»' => [
        ['cta_title', 'العنوان', 'text'],
        ['cta_body', 'النص', 'textarea'],
        ['cta_btn', 'نص الزرار', 'text'],
    ],
    'العروض (كارت الوكالات)' => [
        ['agency_offer_title', 'عنوان عرض الوكالات (فاضي = مخفي)', 'text'],
        ['agency_offer_body', 'تفاصيل العرض', 'textarea'],
        ['agency_offer_url', 'رابط «اعرف أكتر»', 'text'],
    ],
    'الأسعار' => [
        // الباقات نفسها من لوحة المنصة (الباقات) — نفس اللي في نظام الدفع
        ['pricing_yearly_note', 'جنب زرار «سنوي» (مثلًا: وفّر 20%)', 'text'],
    ],
    'الفوتر' => [
        ['footer_about', 'نبذة الفوتر', 'textarea'],
        ['footer_copyright', 'سطر الحقوق', 'text'],
    ],
    'التواصل' => [
        ['contact_phone', 'رقم التليفون', 'text'],
        ['contact_whatsapp', 'واتساب (بكود الدولة)', 'text'],
        ['contact_email', 'الإيميل', 'text'],
        ['contact_address', 'العنوان', 'text'],
    ],
    'السوشيال' => [
        ['social_facebook', 'فيسبوك', 'text'],
        ['social_instagram', 'انستجرام', 'text'],
        ['social_tiktok', 'تيك توك', 'text'],
        ['social_linkedin', 'لينكدإن', 'text'],
    ],
    'الألوان' => [
        ['color_blue', 'الأزرق الأساسي', 'color'],
        ['color_turquoise', 'التركواز', 'color'],
        ['color_sky', 'اللبني', 'color'],
        ['color_ink', 'الأسود', 'color'],
        ['color_beige', 'البيج (الخلفية)', 'color'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
        s_decode_b64();
    foreach ($groups as $items) {
        foreach ($items as [$key, $label, $type]) {
            if ($type === 'logo') {
                if (!empty($_FILES['logo']['name'])) {
                    $up = s_upload($_FILES['logo'], 'logo');
                    if ($up['ok']) {
                        s_delete_upload(s_setting('logo_path'));
                        s_set('logo_path', $up['path']);
                    } else {
                        s_flash('danger', $up['error']);
                    }
                }
                if (!empty($_POST['__clear_logo'])) {
                    s_delete_upload(s_setting('logo_path'));
                    s_set('logo_path', '');
                }
                continue;
            }
            if (!isset($_POST[$key])) continue;
            $v = trim((string) $_POST[$key]);
            if ($type === 'color' && $v !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $v)) continue;
            s_set($key, mb_substr($v, 0, 2000));
        }
    }
    s_flash('success', 'تم حفظ الإعدادات ✓');
    s_redirect('site-admin/settings.php');
}

$__t = 'الهيدر والفوتر والإعدادات';
include __DIR__ . '/layout.php';
?>
<form method="POST" enctype="multipart/form-data" data-safe-post>
  <?= s_csrf_field() ?>
  <?php foreach ($groups as $gLabel => $items): ?>
    <div class="card">
      <h3><?= e($gLabel) ?></h3>
      <div class="row">
        <?php foreach ($items as [$key, $label, $type]):
          $val = $key === 'logo' ? '' : s_setting($key); ?>
          <div class="f" style="<?= $type === 'textarea' ? 'grid-column:1/-1' : '' ?>">
            <label><?= e($label) ?></label>
            <?php if ($type === 'textarea'): ?>
              <textarea name="<?= e($key) ?>" rows="3"><?= e($val) ?></textarea>
            <?php elseif ($type === 'color'): ?>
              <div style="display:flex;gap:7px">
                <input type="color" value="<?= e($val ?: '#0F3CC9') ?>" oninput="this.nextElementSibling.value=this.value" style="width:46px;padding:2px;height:40px">
                <input type="text" name="<?= e($key) ?>" value="<?= e($val) ?>" dir="ltr" placeholder="#0F3CC9">
              </div>
            <?php elseif ($type === 'logo'):
              $lp = s_setting('logo_path'); ?>
              <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <?php if ($lp): ?><img src="<?= e(SITE_UPLOAD_URL . '/' . $lp) ?>" class="thumb" alt=""><?php endif; ?>
                <input type="file" name="logo" accept="image/*" style="font-size:12.5px;flex:1;min-width:180px">
                <?php if ($lp): ?>
                  <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12px">
                    <input type="checkbox" name="__clear_logo" value="1" style="width:auto"> امسح
                  </label>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <input type="text" name="<?= e($key) ?>" value="<?= e($val) ?>"
                     <?= str_contains($key, 'url') || str_contains($key, 'social') || str_contains($key, 'email') || str_contains($key, 'phone') || str_contains($key, 'whatsapp') ? 'dir="ltr"' : '' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <button class="btn">💾 حفظ كل الإعدادات</button>
</form>
<?php include __DIR__ . '/layout-end.php'; ?>
