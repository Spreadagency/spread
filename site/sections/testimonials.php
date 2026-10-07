<?php
/**
 * قسم «آراء العملاء» — من جدول site_testimonials (المنشور بس · المميز الأول).
 * زرار «إضافة رأي عميل» بيظهر للأدمن اللي عنده صلاحية آراء العملاء بس — الزوار مابيشوفوهوش،
 * والقسم الفاضي بيستخبى عن الزوار (وبيظهر للأدمن بحالة فاضية علشان يضيف أول رأي).
 */
$__adm = s_admin_can('testimonials');
if (!$testimonials && !$__adm) return;
$__stars = fn(int $n) => $n > 0 ? '<span class="t-stars" role="img" aria-label="تقييم ' . $n . ' من 5">' . str_repeat('★', $n) . '<i>' . str_repeat('★', 5 - $n) . '</i></span>' : '';
?>
  <section class="h-sec h-testi" id="s-testimonials" data-say="ده كلامهم مش كلامي 😄" data-look="-1">
    <div class="h-wrap">
      <?= $secHead('testimonials', 'آراء العملاء', 'chat') ?>
      <?php if ($__adm): ?>
        <div class="t-admin rv"><a class="ws-btn ws-ghost" href="<?= e(s_url('site-admin/testimonials.php?new=1')) ?>"><?= s_icon('sparkle', 17, 2) ?>إضافة رأي عميل</a>
          <a class="ws-link" href="<?= e(s_url('site-admin/testimonials.php')) ?>">إدارة الآراء</a><small>الزرار ده ظاهر ليك انت بس كأدمن</small></div>
      <?php endif; ?>
      <?php if (!$testimonials): ?>
        <div class="t-empty rv"><b>لسه مفيش آراء منشورة</b><span>أضف أول رأي حقيقي من عميل — القسم ده مخفي عن الزوار لحد ما يبقى فيه آراء.</span></div>
      <?php else: ?>
      <div class="t-grid">
        <?php foreach ($testimonials as $i => $t): $av = s_img($t, 'avatar_path', 'avatar_url');
          $who = trim(implode(' · ', array_filter([(string) ($t['role_title'] ?? ''), (string) ($t['company'] ?? '')]))); ?>
          <figure class="t-card ws-lift rv<?= $i % 3 === 0 ? ' rv-r' : ($i % 3 === 2 ? ' rv-l' : '') ?><?= $t['is_featured'] ? ' feat' : '' ?>" style="transition-delay:<?= ($i % 3) * .1 ?>s">
            <span class="t-q" aria-hidden="true">”</span>
            <?= $__stars((int) $t['rating']) ?>
            <blockquote><?= nl2br(e($t['content'])) ?></blockquote>
            <?php if (!empty($t['video_url'])): ?><a class="t-vid" href="<?= e($t['video_url']) ?>" target="_blank" rel="noopener"><?= s_icon('send', 15) ?>شوف الفيديو</a><?php endif; ?>
            <figcaption>
              <?php if ($av): ?><img src="<?= e($av) ?>" alt="" loading="lazy" width="46" height="46"><?php else: ?><span class="t-av" aria-hidden="true"><?= e(mb_substr((string) $t['name'], 0, 1)) ?></span><?php endif; ?>
              <span><b><?= e($t['name']) ?></b><?php if ($who !== ''): ?><small><?= e($who) ?></small><?php endif; ?></span>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>
