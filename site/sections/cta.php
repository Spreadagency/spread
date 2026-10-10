<?php
/** قسم «cta» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
?>
  <section class="h-cta" id="s-cta" data-say="يلا بينا!" data-look="0">
    <div class="cta-box rv rv-s">
      <div class="cta-txt">
        <h2 class="ws-h2"><?= e(s_setting('cta_title', $D['cta'][0])) ?></h2>
        <p><?= e(s_setting('cta_body', $D['cta'][1])) ?></p>
        <div class="row">
          <a class="ws-btn ws-pri" href="<?= e($regUrl) ?>"><?= e(s_setting('cta_btn', $D['cta'][2])) ?><?= s_icon('arrow', 19, 2.2) ?></a>
          <?php if ($trialOn): ?><a class="ws-btn ws-ghost-d" href="#s-create"><?= e($D['cta'][3]) ?></a><?php endif; ?>
        </div>
      </div>
      <div class="cta-bot"><div class="ws-glow"></div><img src="<?= e($img('bot-wave.webp')) ?>" alt="روبوت Spread AI بيسلّم" loading="lazy" width="230" height="357"></div>
    </div>
  </section>
