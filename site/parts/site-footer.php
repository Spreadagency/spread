<?php
/** فوتر الموقع الموحّد — المتوقع: $logo · $loginUrl · $homeHref (# للرئيسية أو رابط index.php) */
$menuPages = s_menu_pages();
$__h = rtrim($homeHref, '#');
?>
<footer class="h-foot">
  <div class="in">
    <div class="cols">
      <div class="about">
        <a href="<?= e($homeHref) ?>" class="h-logo"><img src="<?= e($logo) ?>" alt="" width="38" height="32" loading="lazy"><span class="h-logo-t" dir="ltr"><span>Spread <span class="ai-word">AI</span></span><small>Create · Plan · Publish</small></span></a>
        <p><?= e(s_setting('footer_about', 'فريق تسويق كامل بالذكاء الاصطناعي — من الفكرة للمحتوى للتصميم للنشر.')) ?></p>
        <?php $soc = ['social_facebook' => 'f', 'social_instagram' => 'IG', 'social_tiktok' => 'TT', 'social_linkedin' => 'in'];
        $socOn = array_filter($soc, fn($k) => s_setting($k) !== '', ARRAY_FILTER_USE_KEY);
        if ($socOn): ?><div class="soc"><?php foreach ($socOn as $k => $l): ?><a href="<?= e(s_setting($k)) ?>" target="_blank" rel="noopener" aria-label="<?= e($k) ?>"><?= e($l) ?></a><?php endforeach; ?></div><?php endif; ?>
      </div>
      <div class="col"><b>المنصة</b>
        <a href="<?= e(s_url('brand-brain.php')) ?>">Brand Brain</a><a href="<?= e(s_url('design-studio.php')) ?>">Design Studio</a>
        <a href="<?= e(s_url('research.php')) ?>">Deep Research</a><a href="<?= e(s_url('campaigns.php')) ?>">الحملات</a>
        <a href="<?= e($loginUrl) ?>">تسجيل الدخول</a>
      </div>
      <div class="col"><b>الموقع</b>
        <?php foreach ($menuPages as $pg): ?><a href="<?= e(s_page_url($pg['slug'])) ?>"><?= e($pg['title']) ?></a><?php endforeach; ?>
        <?php if (!$menuPages): ?><a href="<?= e($__h) ?>#s-services">الخدمات</a><a href="<?= e($__h) ?>#s-designs">التصميمات</a><?php endif; ?>
        <a href="<?= e($__h) ?>#s-pricing">الأسعار</a>
      </div>
      <?php $ce = s_setting('contact_email'); $cw = s_setting('contact_whatsapp'); $cp = s_setting('contact_phone'); $ca = s_setting('contact_address');
      if ($ce || $cw || $cp || $ca): ?>
      <div class="col"><b>تواصل</b>
        <?php if ($ce): ?><a href="mailto:<?= e($ce) ?>" dir="ltr" style="text-align:right"><?= e($ce) ?></a><?php endif; ?>
        <?php if ($cw): ?><a href="https://wa.me/<?= e(preg_replace('/\D/', '', $cw)) ?>" target="_blank" rel="noopener">واتساب: <span dir="ltr"><?= e($cw) ?></span></a><?php endif; ?>
        <?php if ($cp): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $cp)) ?>" dir="ltr" style="text-align:right"><?= e($cp) ?></a><?php endif; ?>
        <?php if ($ca): ?><span style="font-size:14px"><?= e($ca) ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <hr>
    <div class="bot"><span><?= e(s_setting('footer_copyright', '© ' . date('Y') . ' Spread AI — كل الحقوق محفوظة')) ?></span>
      <span><?php foreach (['terms' => 'الشروط', 'privacy' => 'الخصوصية'] as $slug => $l): if (s_one("SELECT id FROM site_pages WHERE slug = ? AND is_active = 1 AND COALESCE(status, 'published') <> 'draft'", [$slug])): ?><a href="<?= e(s_page_url($slug)) ?>"><?= e($l) ?></a><?php endif; endforeach; ?></span></div>
  </div>
</footer>
