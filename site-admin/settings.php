<?php
/** إعدادات الموقع: الهوية · الهيدر والقوائم · «جاهز نبدأ» والعروض · الفوتر · التواصل · السوشيال · متقدم
 *  (نفس مفاتيح site_settings القديمة — الألوان بقت في «الهوية البصرية») */
require_once __DIR__ . '/auth.php';
sa_require_perm('navigation');

$tabs = [
    'general' => ['عام', [
        ['site_name', 'اسم الموقع', 'text'],
        ['tagline', 'السطر التعريفي', 'text', 'بيظهر في عنوان الصفحة الرئيسية ووصفها.'],
        ['logo', 'اللوجو', 'logo'],
    ]],
    'header' => ['الهيدر والقوائم', [
        // شرائح الهيرو نفسها (العنوان · الشارة · الروبوت · الأزرار) من «Hero والسلايدر» · الصفحات اللي في القائمة من «الصفحات»
        ['ribbon_text', 'عناصر الشريط المتحرك (سطر لكل عنصر)', 'textarea'],
        ['platform_login_url', 'رابط تسجيل الدخول', 'url'],
        ['platform_register_url', 'رابط حساب جديد', 'url'],
    ]],
    'cta' => ['«جاهز نبدأ» والعروض', [
        ['cta_title', 'عنوان «جاهز نبدأ»', 'text'],
        ['cta_body', 'نص «جاهز نبدأ»', 'textarea'],
        ['cta_btn', 'نص الزرار', 'text'],
        ['agency_offer_title', 'عنوان عرض الوكالات (فاضي = مخفي)', 'text'],
        ['agency_offer_body', 'تفاصيل عرض الوكالات', 'textarea'],
        ['agency_offer_url', 'رابط «اعرف أكتر»', 'url'],
        // الباقات نفسها من لوحة المنصة (الباقات) — نفس اللي في نظام الدفع
        ['pricing_yearly_note', 'جنب زرار «سنوي» في الأسعار (مثلًا: وفّر 20%)', 'text'],
    ]],
    'footer' => ['الفوتر', [
        ['footer_about', 'نبذة الفوتر', 'textarea'],
        ['footer_copyright', 'سطر الحقوق', 'text'],
    ]],
    'contact' => ['التواصل', [
        ['contact_phone', 'رقم التليفون', 'tel'],
        ['contact_whatsapp', 'واتساب (بكود الدولة)', 'tel'],
        ['contact_email', 'الإيميل', 'email'],
        ['contact_address', 'العنوان', 'text'],
    ]],
    'social' => ['السوشيال', [
        ['social_facebook', 'فيسبوك', 'url'],
        ['social_instagram', 'انستجرام', 'url'],
        ['social_tiktok', 'تيك توك', 'url'],
        ['social_linkedin', 'لينكدإن', 'url'],
    ]],
    'advanced' => ['متقدم', [
        ['pretty_urls', 'روابط الصفحات النظيفة (/about بدل site/page.php?p=about)', 'bool', 'محتاجة قاعدة .htaccess اللي جاية مع النسخة — الروابط القديمة بتفضل شغالة في الحالتين.'],
        ['track_views', 'عدّاد الزيارات الداخلي (للتحليلات)', 'bool', 'عدّاد يومي لكل صفحة من غير كوكيز ولا بيانات شخصية.'],
    ]],
];
$defaults = ['pretty_urls' => '1', 'track_views' => '1'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'general';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
    s_decode_b64();
    $tab = isset($tabs[$_POST['tab'] ?? '']) ? $_POST['tab'] : 'general';
    $changed = [];
    foreach ($tabs[$tab][1] as $item) {
        [$key, $label, $type] = $item;
        if ($type === 'logo') {
            if (!empty($_FILES['logo']['name'])) {
                $up = s_upload($_FILES['logo'], 'logo');
                if ($up['ok']) {
                    s_delete_upload(s_setting('logo_path'));
                    s_set('logo_path', $up['path']);
                    $changed[] = $label;
                } else {
                    s_flash('danger', $up['error']);
                }
            }
            if (!empty($_POST['__clear_logo'])) {
                s_delete_upload(s_setting('logo_path'));
                s_set('logo_path', '');
                $changed[] = $label;
            }
            continue;
        }
        if ($type === 'bool') {
            $v = !empty($_POST[$key]) ? '1' : '0';
            if ($v !== s_setting($key, $defaults[$key] ?? '')) $changed[] = $label;
            s_set($key, $v);
            continue;
        }
        if (!isset($_POST[$key])) continue;
        $v = trim((string) $_POST[$key]);
        // الروابط: http(s) أو مسار داخلي أو #قسم بس — مفيش javascript: ولا بروتوكولات غريبة
        if ($type === 'url' && $v !== '' && !preg_match('~^(https?://|/|#|mailto:|tel:)~i', $v)) {
            if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $v)) { s_flash('danger', '«' . $label . '»: الرابط لازم يبدأ بـ https:// أو / — ماتحفظش'); continue; }
            $v = 'https://' . ltrim($v, '/');
        }
        if ($v !== s_setting($key)) $changed[] = $label;
        s_set($key, mb_substr($v, 0, 2000));
    }
    if ($changed) sa_log('settings', 'navigation', 'تعديل إعدادات «' . $tabs[$tab][0] . '»: ' . implode('، ', array_slice($changed, 0, 6)));
    if (empty($_SESSION['site_flash'])) s_flash('success', 'تم حفظ الإعدادات ✓');
    s_redirect('site-admin/settings.php?tab=' . $tab);
}

$__t = 'الإعدادات';
include __DIR__ . '/layout.php';
echo sa_page_head('settings', 'الإعدادات', 'Settings', 'الهوية والهيدر والفوتر وبيانات التواصل — كل حاجة بتظهر في كل صفحات الموقع.');
echo '<nav class="ad-pills" aria-label="أقسام الإعدادات">';
foreach ($tabs as $k => [$l]) echo '<a class="ad-pill' . ($k === $tab ? ' on' : '') . '" href="?tab=' . $k . '"' . ($k === $tab ? ' aria-current="page"' : '') . '>' . e($l) . '</a>';
echo '</nav>';
?>
<form method="POST" enctype="multipart/form-data" data-safe-post class="ad-card sa-in" style="margin-top:14px">
  <?= s_csrf_field() ?>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="ad-form">
    <?php foreach ($tabs[$tab][1] as $item):
      [$key, $label, $type] = $item; $hint = $item[3] ?? '';
      if ($type === 'logo'):
        $lp = s_setting('logo_path'); ?>
        <div class="ad-f">
          <span class="ad-label">اللوجو</span>
          <div class="ad-drop<?= $lp ? ' has' : '' ?>" data-drop>
            <span class="ad-drop-pv"><?= $lp ? '<img src="' . e(SITE_UPLOAD_URL . '/' . $lp) . '" alt="">' : sa_icon('image', 22) ?></span>
            <span class="ad-drop-t"><b data-drop-name><?= $lp ? 'اللوجو الحالي' : 'لسه مفيش ملف' ?></b><small>PNG أو SVG أو WebP — لو فاضي بيظهر لوجو Spread AI</small></span>
            <input type="file" name="logo" accept="image/*" aria-label="اللوجو">
          </div>
          <?php if ($lp): ?><label class="ad-mini"><input type="checkbox" name="__clear_logo" value="1"> امسح اللوجو</label><?php endif; ?>
        </div>
      <?php elseif ($type === 'bool'): ?>
        <div class="ad-f wide"><div class="ad-swrow"><span><span class="ad-label"><?= e($label) ?></span><?php if ($hint): ?><span class="ad-hint" style="display:block"><?= e($hint) ?></span><?php endif; ?></span>
          <?= sa_switch($key, s_setting($key, $defaults[$key] ?? '0') === '1', 'مفعّل') ?></div></div>
      <?php else:
        echo sa_field(['name' => $key, 'label' => $label, 'type' => $type === 'tel' ? 'text' : $type, 'dir' => in_array($type, ['url', 'email', 'tel'], true) ? 'ltr' : '',
                       'rows' => 3, 'hint' => $hint, 'wide' => $type === 'textarea'], s_setting($key));
      endif; ?>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:10px;margin-top:20px"><?= sa_btn('حفظ', 'pri lg', null, 'check', ['type' => 'submit']) ?></div>
</form>
<?php include __DIR__ . '/layout-end.php'; ?>
