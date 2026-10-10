<?php
/** قسم «services» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
if (!$services) return;
?>
  <section class="h-services" id="s-services" data-say="كل أداة ليها شغلها" data-look="1">
    <div class="h-wrap">
      <?= $secHead('services', 'الخدمات') ?>
      <div class="sv-tabs ws-noscroll">
        <div role="tablist" aria-label="الخدمات" class="ws-glass-l">
          <?php foreach ($services as $i => $s): ?><a role="tab" class="ws-tab" href="#svc-<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-svc="<?= $i ?>"><span<?= preg_match('/\p{Arabic}/u', (string) $s['title']) ? '' : ' dir="ltr"' ?>><?= e($s['title']) ?></span></a><?php endforeach; ?>
        </div>
      </div>
      <div>
        <?php foreach ($services as $i => $s): $simg = s_img($s); $ic = $s['icon'] ?: ($svcIcons[$i % 6]); $bl = s_lines($s['bullets'] ?? '');
          $ltr = !preg_match('/\p{Arabic}/u', (string) $s['title']); $link = trim((string) ($s['link_url'] ?? '')); ?>
          <div class="sv-item<?= $i % 2 ? ' alt' : '' ?>" id="svc-<?= $i ?>">
            <div class="sv-txt rv <?= $i % 2 ? 'rv-l' : 'rv-r' ?>">
              <span class="sv-ic"><?= s_icon($ic, 26) ?></span>
              <span class="sv-t"><b<?= $ltr ? ' dir="ltr"' : '' ?>><?= e($s['title']) ?></b><?php if (!empty($s['subtitle'])): ?><span><?= e($s['subtitle']) ?></span><?php endif; ?></span>
              <?php if ($s['body']): ?><p><?= e($s['body']) ?></p><?php endif; ?>
              <?php if ($bl): ?><div class="sv-bl"><?php foreach ($bl as $b): ?><span><i><?= s_icon('check', 14, 2.6) ?></i><?= e($b) ?></span><?php endforeach; ?></div><?php endif; ?>
              <a class="ws-btn ws-ghost" href="<?= e($link !== '' ? $appUrl($link) : $regUrl) ?>"><?= e(($s['link_text'] ?? '') ?: 'جرّب ' . $s['title']) ?><?= s_icon('arrow', 17, 2) ?></a>
            </div>
            <div class="sv-media rv <?= $i % 2 ? 'rv-r' : 'rv-l' ?>">
              <div class="box"><?php if ($simg): ?><img src="<?= e($simg) ?>" alt="<?= e($s['title']) ?>" loading="lazy"><?php else: ?><span class="big"><?= s_icon($ic, 56, 1.4) ?></span><?php endif; ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
