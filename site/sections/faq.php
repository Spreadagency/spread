<?php
/** قسم «الأسئلة الشائعة» — من جدول site_faqs (أكورديون بـ details/summary — بيشتغل من غير JS) */
if (!$faqs) return;
?>
  <section class="h-sec h-faq" id="s-faq" data-say="عندك سؤال؟" data-look="1">
    <div class="h-wrap">
      <?= $secHead('faq', 'الأسئلة الشائعة', 'chat') ?>
      <div class="faq-list">
        <?php foreach ($faqs as $i => $q): ?>
          <details class="faq-i rv"<?= $i === 0 ? ' open' : '' ?>>
            <summary><span><?= e($q['question']) ?></span><i aria-hidden="true"><?= s_icon('arrow', 18, 2) ?></i></summary>
            <div class="faq-a"><?= nl2br(e($q['answer'])) ?></div>
          </details>
        <?php endforeach; ?>
      </div>
      <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['answer']]], $faqs)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
    </div>
  </section>
