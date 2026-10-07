<?php
/** التجربة التفاعلية «اصنع منشورك الآن» — نصوص القسم · شاشة الاشتراك عند مرحلة التصميم · حالة التجربة من المنصة */
require_once __DIR__ . '/metrics.php';
sa_require_perm('create_post');

$sec = s_one("SELECT * FROM site_sections WHERE section_key = 'trial'");
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
    s_decode_b64();
    if ($sec) {
        s_run('UPDATE site_sections SET title = ?, subtitle = ?, is_visible = ? WHERE id = ?', [
            mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255) ?: null, mb_substr(trim((string) ($_POST['subtitle'] ?? '')), 0, 1000) ?: null,
            !empty($_POST['is_visible']) ? 1 : 0, (int) $sec['id']]);
    }
    foreach (array_keys(s_paywall_defaults()) as $k) {
        if (!isset($_POST[$k])) continue;
        $v = trim((string) $_POST[$k]);
        if ($k === 'paywall_cta_url' && $v !== '' && !preg_match('~^(https?://|/|#)~i', $v)) $v = 'https://' . ltrim($v, '/');
        s_set($k, mb_substr($v, 0, 2000) === s_paywall_defaults()[$k] ? '' : mb_substr($v, 0, 2000));
    }
    sa_log('update', 'create_post', 'تعديل قسم «اصنع منشورك» وشاشة الاشتراك');
    s_flash('success', 'اتحفظ ✓');
    s_redirect('site-admin/create-post.php');
}

$pw = s_paywall();
$M = sm_metrics(30);
$pOk = sm_platform_ok();
$pset = fn(string $k, string $d) => $pOk ? s_platform_setting($k, $d) : null;

$__t = 'التجربة التفاعلية';
include __DIR__ . '/layout.php';
echo sa_page_head('rocket', 'التجربة التفاعلية', 'Create Post Flow', 'بيانات المشروع ← 4 أفكار ← اختيار فكرة ← المنشور ← التصميم ← الاشتراك. التصميم والنشر للمشتركين بس — اللي مش مشترك بيشوف شاشة الاشتراك من غير ما يتولّد تصميم ولا يتخصم أي Credits.',
    sa_btn('جرّب على الموقع', 'sec', s_url('index.php') . '#s-create', 'external', ['target' => '_blank', 'rel' => 'noopener']));
?>
<div class="ad-stats">
  <?= sm_stat_card('trials', $M['trials'], sa_can('leads') ? 'leads.php' : '') ?>
  <?= sm_stat_card('posts', $M['posts']) ?>
  <?= sm_stat_card('claims', $M['claims']) ?>
  <div class="ad-stat"><small>حالة التجربة (من المنصة)</small>
    <?php if (!$pOk): ?><span class="v"><b style="font-size:18px">اربط المنصة</b></span>
    <?php else: $on = $pset('trial_enabled', '1') === '1'; ?>
      <span class="v"><b style="font-size:20px"><?= $on ? 'شغالة' : 'موقوفة' ?></b><?= sa_chip($on ? 'Live' : 'Off', $on ? 'ok' : 'warn') ?></span>
      <span class="ad-hint"><?= (int) $pset('trial_ideas_count', '4') ?> أفكار · <?= (int) $pset('trial_per_ip_hour', '5') ?> محاولات/ساعة · التصميم <?= $pset('design_requires_subscription', '1') === '1' ? 'للمشتركين بس' : 'متاح للكل' ?></span>
    <?php endif; ?>
  </div>
</div>

<form method="POST" data-safe-post class="ad-split wide-side">
  <?= s_csrf_field() ?>
  <div style="display:flex;flex-direction:column;gap:18px">
    <section class="ad-card sa-in">
      <div class="ad-card-h"><h3>قسم «اصنع منشورك» في الرئيسية</h3><?= sa_switch('is_visible', (bool) ($sec['is_visible'] ?? 1), 'ظاهر') ?></div>
      <div class="ad-form one">
        <?= sa_field(['name' => 'title', 'label' => 'العنوان', 'max' => 255], $sec['title'] ?? '') ?>
        <?= sa_field(['name' => 'subtitle', 'label' => 'الوصف', 'type' => 'textarea', 'rows' => 2], $sec['subtitle'] ?? '') ?>
      </div>
    </section>
    <section class="ad-card sa-in">
      <div class="ad-card-h"><h3>شاشة الاشتراك عند مرحلة التصميم</h3><?= sa_chip('Paywall', 'violet') ?></div>
      <div class="ad-form one">
        <?= sa_field(['name' => 'paywall_title', 'label' => 'العنوان', 'max' => 200], $pw['paywall_title']) ?>
        <?= sa_field(['name' => 'paywall_body', 'label' => 'الوصف', 'type' => 'textarea', 'rows' => 2], $pw['paywall_body']) ?>
        <?= sa_field(['name' => 'paywall_benefits', 'label' => 'المزايا (سطر لكل ميزة)', 'type' => 'textarea', 'rows' => 5], $pw['paywall_benefits']) ?>
        <div class="ad-form">
          <?= sa_field(['name' => 'paywall_cta', 'label' => 'نص الزرار', 'max' => 80], $pw['paywall_cta']) ?>
          <?= sa_field(['name' => 'paywall_cta_url', 'label' => 'رابط الزرار', 'type' => 'url', 'hint' => 'فاضي = قسم الأسعار في الرئيسية.'], $pw['paywall_cta_url']) ?>
        </div>
      </div>
    </section>
    <div><?= sa_btn('حفظ', 'pri lg', null, 'check', ['type' => 'submit']) ?></div>
  </div>
  <aside class="ad-card sa-in" style="position:sticky;top:calc(var(--top) + 20px);text-align:center">
    <span class="ad-label" style="display:block;text-align:right;margin-bottom:12px">معاينة الشاشة</span>
    <span class="ad-empty-ic" style="margin:0 auto 10px"><?= sa_icon('lock', 26, 1.7) ?></span>
    <b style="font-family:'Readex Pro';font-size:17px;display:block"><?= e($pw['paywall_title']) ?></b>
    <p class="ad-hint" style="font-size:12.5px;margin:6px 0 12px"><?= e($pw['paywall_body']) ?></p>
    <div style="display:flex;flex-direction:column;gap:6px;text-align:right;margin-bottom:14px">
      <?php foreach ($pw['benefits'] as $b): ?><span style="display:flex;gap:8px;align-items:center;font-size:13px"><span style="color:#0B7A66"><?= sa_icon('check', 15, 2.6) ?></span><?= e($b) ?></span><?php endforeach; ?>
    </div>
    <span class="ad-btn ad-pri" style="width:100%"><?= e($pw['paywall_cta']) ?></span>
    <span class="ad-btn ad-ghost" style="width:100%;margin-top:6px">العودة للمنشور</span>
    <p class="ad-hint" style="margin:14px 0 0;text-align:right">التحقق من الاشتراك بيحصل قبل أي طلب تصميم — على الموقع وجوه المنصة كمان. تشغيل/إيقاف الشرط من لوحة المنصة ← الإعدادات.</p>
  </aside>
</form>
<?php include __DIR__ . '/layout-end.php'; ?>
