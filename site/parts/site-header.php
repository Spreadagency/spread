<?php
/**
 * هيدر الموقع الموحّد (الرئيسية + كل الصفحات الداخلية) — نفس الـ Design System
 * المتوقع: $nav = [[label, href, highlight?], …] · $loginUrl · $ctaHref · $logo · $siteName · $homeHref
 */
?>
<header class="h-head">
  <div class="h-bar ws-glass-l">
    <a href="<?= e($homeHref) ?>" class="h-logo" aria-label="<?= e($siteName) ?> — الرئيسية">
      <img src="<?= e($logo) ?>" alt="" width="38" height="32">
      <span class="h-logo-t" dir="ltr"><span>Spread <span class="ai-word">AI</span></span></span>
    </a>
    <nav class="h-nav" aria-label="القائمة الرئيسية">
      <?php foreach ($nav as $n): ?><a class="ws-nav<?= !empty($n[2]) ? ' hl' : '' ?>" href="<?= e($n[1]) ?>"<?= !empty($n[3]) ? ' aria-current="page"' : '' ?>><?= e($n[0]) ?></a><?php endforeach; ?>
    </nav>
    <div class="h-acts">
      <a href="<?= e($loginUrl) ?>" class="ws-nav">تسجيل الدخول</a>
      <a href="<?= e($ctaHref) ?>" class="ws-btn ws-pri">ابدأ مجانًا</a>
      <button type="button" class="h-menu-btn" id="h-menu-btn" aria-label="القائمة" aria-expanded="false" aria-controls="h-mnav"><?= s_icon('menu', 20, 2) ?></button>
    </div>
  </div>
  <nav class="h-mnav ws-glass-l" id="h-mnav" aria-label="القائمة الرئيسية">
    <?php foreach (($mnav ?? $nav) as $n): ?><a class="ws-nav" href="<?= e($n[1]) ?>"><?= e($n[0]) ?></a><?php endforeach; ?>
  </nav>
</header>
