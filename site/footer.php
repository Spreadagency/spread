<?php
/** فوتر الموقع المشترك */
$__fpages = s_menu_pages();
$__soc = [
    'social_facebook'  => ['f', 'فيسبوك'],
    'social_instagram' => ['◎', 'انستجرام'],
    'social_tiktok'    => ['♪', 'تيك توك'],
    'social_linkedin'  => ['in', 'لينكدإن'],
];
?>
<footer class="site-footer">
  <div class="wrap">
    <div class="foot-grid">
      <div>
        <h4><?= e(s_setting('site_name', 'Spread AI')) ?></h4>
        <p style="font-size:14px;margin:0 0 16px;max-width:38ch"><?= e(s_setting('footer_about')) ?></p>
        <div class="soc">
          <?php foreach ($__soc as $key => [$icon, $label]): $v = s_setting($key); if (!$v) continue; ?>
            <a href="<?= e($v) ?>" target="_blank" rel="noopener" title="<?= e($label) ?>"><?= $icon ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <h4>الموقع</h4>
        <div class="foot-links">
          <a href="<?= e(s_url('index.php')) ?>">الرئيسية</a>
          <?php foreach ($__fpages as $pg): ?>
            <a href="<?= e(s_page_url($pg['slug'])) ?>"><?= e($pg['title']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <h4>المنصة</h4>
        <div class="foot-links">
          <a href="<?= e(s_setting('platform_login_url', PLATFORM_LOGIN)) ?>">تسجيل الدخول</a>
          <a href="<?= e(s_setting('platform_register_url', PLATFORM_REGISTER)) ?>">حساب جديد</a>
          <a href="<?= e(s_url('index.php')) ?>#pricing">الأسعار</a>
          <a href="<?= e(s_url('index.php')) ?>#services">الخدمات</a>
        </div>
      </div>

      <div>
        <h4>تواصل معنا</h4>
        <div class="foot-links">
          <?php if ($v = s_setting('contact_phone')): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $v)) ?>" dir="ltr" style="text-align:start">📞 <?= e($v) ?></a><?php endif; ?>
          <?php if ($v = s_setting('contact_whatsapp')): ?><a href="https://wa.me/<?= e(preg_replace('/[^0-9]/', '', $v)) ?>" target="_blank" rel="noopener">💬 واتساب</a><?php endif; ?>
          <?php if ($v = s_setting('contact_email')): ?><a href="mailto:<?= e($v) ?>" dir="ltr" style="text-align:start">✉ <?= e($v) ?></a><?php endif; ?>
          <?php if ($v = s_setting('contact_address')): ?><span style="font-size:14px">📍 <?= e($v) ?></span><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="foot-bottom">
      <span><?= e(s_setting('footer_copyright')) ?></span>
      <span><?= e(s_setting('tagline')) ?></span>
    </div>
  </div>
</footer>

<div id="lb" onclick="if(event.target.id==='lb'||event.target.id==='lbClose')this.style.display='none'">
  <img id="lbImg" src="" alt="">
  <button id="lbClose" class="btn-pill btn-light">✕ إغلاق</button>
</div>

<script src="<?= e(s_url('site-assets/js/site.js')) ?>?v=<?= @filemtime(dirname(__DIR__) . '/site-assets/js/site.js') ?: time() ?>"></script>
</body>
</html>
