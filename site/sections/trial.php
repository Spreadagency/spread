<?php
/** قسم «trial» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
if (!$trialOn) return;
?>
<?= i18n_js(['مشكلة في الاتصال — جرّب تاني', 'مشروعك', 'إنشاء حساب مجاني ←', 'لحظة...', 'بنقرا صفحتك...', 'حصل خطأ — جرّب تاني', 'فكرة', 'بنكتب منشورك...', 'بوست', '✓ اتنسخ', 'Spread AI بيفكر...']) ?>
  <section class="h-create" id="s-create" data-say="<?= e(__('جرّب بنفسك — من غير حساب')) ?>" data-look="1">
    <div class="h-wrap">
      <?= $secHead('trial', 'جرّبها دلوقتي — من غير حساب') ?>
      <div class="ws-card d-card rv rv-s" id="demo">
        <div class="d-grid">
          <div class="d-main">
            <div class="d-steps" role="list" aria-label="<?= e(__('خطوات التجربة')) ?>">
              <?php foreach (['بياناتك', '4 أفكار', 'المنشور', 'التصميم', 'احفظ'] as $i => $t): ?>
                <div role="listitem" data-s="<?= $i ?>" class="<?= $i === 0 ? 'cur' : '' ?>"><span class="n"><em><?= $i + 1 ?></em><?= s_icon('check', 15, 2.6) ?></span><span class="t"><?= e(__($t)) ?></span><?php if ($i < 4): ?><span class="bar"></span><?php endif; ?></div>
              <?php endforeach; ?>
            </div>

            <!-- ① البيانات: لينك الموقع أو صفحة الفيسبوك ← Spread AI بيقرا ويتعرّف على النشاط -->
            <form class="d-pane on" data-p="form" novalidate>
              <div data-fv="link">
                <div class="d-h"><h3><?= e(__('عرّفنا على مشروعك')) ?></h3><span><?= e(__('حط لينك موقعك أو صفحة الفيسبوك — وSpread AI هيتعرّف على نشاطك لوحده.')) ?></span></div>
                <div class="d-f d-url-f"><label for="d-url"><?= e(__('لينك الموقع أو صفحة الفيسبوك')) ?></label>
                  <div class="d-url"><span class="d-url-ic" aria-hidden="true"><?= s_icon('search', 18, 2) ?></span>
                    <input id="d-url" class="ws-input" type="url" inputmode="url" dir="ltr" maxlength="500" autocomplete="url" placeholder="<?= e(__('facebook.com/yourpage أو yoursite.com')) ?>"></div>
                  <span class="d-err" id="d-url-err" role="alert" hidden><?= e(__('حط اللينك علشان نتعرّف على نشاطك')) ?></span>
                  <span class="d-hint"><?= e(__('مثلاً:')) ?> <button type="button" class="ws-link" data-ex="https://www.facebook.com/"><?= e(__('صفحة فيسبوك')) ?></button> · <button type="button" class="ws-link" data-ex="https://"><?= e(__('موقع إلكتروني')) ?></button></span>
                </div>
                <div class="d-manual" data-manual hidden>
                  <p class="d-note" data-manual-note></p>
                  <div class="d-two">
                    <div class="d-f"><label for="d-biz"><?= e(__('اسم النشاط')) ?></label><input id="d-biz" class="ws-input" maxlength="200" placeholder="<?= e(__('مثلاً: مطعم البيت')) ?>" autocomplete="organization">
                      <span class="d-err" id="d-biz-err" role="alert" hidden><?= e(__('اكتب اسم النشاط علشان نكمّل')) ?></span></div>
                    <div class="d-f"><label for="d-ind"><?= e(__('المجال')) ?> <small><?= e(__('(اختياري)')) ?></small></label><input id="d-ind" class="ws-input" maxlength="150" placeholder="<?= e(__('مثلاً: مطعم مشويات')) ?>"></div>
                  </div>
                </div>
                <p class="d-msg" data-msg hidden role="alert"></p>
                <button type="submit" class="ws-btn ws-pri" data-act="analyze" style="align-self:flex-start">
                  <span class="idle"><?= e(__('اتعرّف على مشروعي')) ?></span><?= s_icon('sparkle', 18, 2) ?>
                </button>
              </div>

              <!-- اللي Spread AI عرفه من اللينك -->
              <div data-fv="profile" hidden>
                <div class="d-h"><h3><?= e(__('عرفنا مشروعك 👌')) ?></h3><span><?= e(__('راجع المعلومات — وعدّل الاسم أو المجال لو محتاج.')) ?></span></div>
                <div class="d-prof">
                  <div class="d-prof-top">
                    <span class="d-prof-av" id="d-prof-av" aria-hidden="true"></span>
                    <div class="d-prof-id">
                      <input id="d-p-name" class="ws-input" maxlength="200" aria-label="<?= e(__('اسم النشاط')) ?>">
                      <input id="d-p-ind" class="ws-input" maxlength="150" placeholder="<?= e(__('المجال')) ?>" aria-label="<?= e(__('المجال')) ?>">
                    </div>
                  </div>
                  <p class="d-prof-desc" id="d-p-desc" hidden></p>
                  <ul class="d-prof-svc" id="d-p-svc" hidden></ul>
                  <a class="d-prof-src" id="d-p-src" target="_blank" rel="noopener nofollow" hidden></a>
                </div>
                <p class="d-note"><?= e(__('المعلومات دي هتتحفظ في حسابك أول ما تسجّل.')) ?></p>
                <p class="d-msg" data-msg hidden role="alert"></p>
                <div class="d-row">
                  <button type="button" class="ws-btn ws-pri" data-act="ideas"><?= e(__('ولّد 4 أفكار')) ?><?= s_icon('sparkle', 18, 2) ?></button>
                  <button type="button" class="ws-link" data-act="relink"><?= e(__('لينك تاني')) ?></button>
                </div>
              </div>
            </form>

            <!-- ② الأفكار -->
            <div class="d-pane" data-p="ideas">
              <div class="d-h"><h3><?= e(__('4 أفكار لـ')) ?> <span data-biz><?= e(__('مشروعك')) ?></span></h3><span><?= e(__('اختار الفكرة اللي تعجبك.')) ?></span></div>
              <div class="d-ideas" id="d-ideas"></div>
              <p class="d-msg" data-msg hidden role="alert"></p>
              <div class="d-row">
                <button type="button" class="ws-btn ws-pri" data-act="post" hidden><?= e(__('اكتب المنشور')) ?><?= s_icon('arrow', 18, 2.2) ?></button>
                <span class="d-pick-hint" data-pick-hint><?= e(__('اختار فكرة الأول ←')) ?></span>
                <button type="button" class="ws-btn ws-ghost" data-act="regen"><?= s_icon('shuffle', 17) ?><?= e(__('أفكار تانية')) ?></button>
                <button type="button" class="ws-link" data-go="form"><?= e(__('تعديل البيانات')) ?></button>
              </div>
            </div>

            <!-- ③ المنشور -->
            <div class="d-pane" data-p="post">
              <div class="d-h"><h3><?= e(__('منشورك جاهز ✍️')) ?></h3><span><?= e(__('مكتوب بصوت')) ?> <span data-biz><?= e(__('مشروعك')) ?></span> <?= e(__('— والأقواس [ ] مكان تفاصيلك.')) ?></span></div>
              <div class="d-post"><span class="sa-chip" id="d-post-chip"></span><p id="d-post-text"></p><span class="tags" id="d-post-tags"></span></div>
              <div class="d-row">
                <button type="button" class="ws-btn ws-pri" data-go="design"><?= e(__('صمّمه')) ?><?= s_icon('arrow', 18, 2.2) ?></button>
                <button type="button" class="ws-btn ws-ghost" data-act="copy"><?= e(__('نسخ المنشور')) ?></button>
                <button type="button" class="ws-link" data-go="ideas"><?= e(__('غيّر الفكرة')) ?></button>
              </div>
            </div>

            <!-- ④ التصميم — بيتحقق من الاشتراك الأول (مفيش أي طلب تصميم قبل التحقق) -->
            <?php $__pw = s_paywall(); ?>
            <div class="d-pane" data-p="design">
              <div class="d-gate-load" data-gate-view="loading" role="status">
                <svg class="sa-spin" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="#0C87EF" stroke-width="2.6" stroke-linecap="round" stroke-dasharray="14 8"></circle></svg>
                <span><?= e(__('بنتأكد من اشتراكك قبل التصميم...')) ?></span>
              </div>
              <div class="d-pay" data-gate-view="paywall" hidden>
                <span class="d-pay-ic"><?= s_icon('image', 30, 1.6) ?><i><?= s_icon('sparkle', 14, 2) ?></i></span>
                <span class="sa-chip d-pay-chip"><?= e(__('جاهز تحوّل منشورك لتصميم؟')) ?></span>
                <h3><?= e(__((string) ($__pw['paywall_title']))) ?></h3>
                <p><?= e(__((string) ($__pw['paywall_body']))) ?></p>
                <?php if ($__pw['benefits']): ?>
                <ul class="d-pay-list">
                  <?php foreach ($__pw['benefits'] as $b): ?><li><i><?= s_icon('check', 14, 2.6) ?></i><?= e(__($b)) ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <div class="d-row">
                  <a class="ws-btn ws-pri" data-pay-cta href="<?= e($__pw['paywall_cta_url'] ?: '#s-pricing') ?>"><?= e(__((string) ($__pw['paywall_cta']))) ?><?= s_icon('arrow', 18, 2.2) ?></a>
                  <button type="button" class="ws-btn ws-ghost" data-go="post"><?= e(__('العودة للمنشور')) ?></button>
                </div>
                <button type="button" class="ws-link d-pay-save" data-go="save"><?= e(__('أو سجّل حساب مجاني واحفظ المنشور من غير تصميم ←')) ?></button>
              </div>
              <div class="d-pay ok" data-gate-view="subscribed" hidden>
                <span class="d-pay-ic"><?= s_icon('check', 30, 2.4) ?></span>
                <h3><?= e(__('انت مشترك — يلا نصمّم ✨')) ?></h3>
                <p><?= e(__('هننقل المنشور لحسابك ونكمّل التصميم والجدولة والنشر من جوه المنصة.')) ?></p>
                <div class="d-row">
                  <a class="ws-btn ws-pri" data-continue href="#"><?= e(__('كمّل للتصميم')) ?><?= s_icon('arrow', 18, 2.2) ?></a>
                  <button type="button" class="ws-btn ws-ghost" data-go="post"><?= e(__('رجوع للمنشور')) ?></button>
                </div>
              </div>
              <p class="d-msg" data-msg hidden role="alert"></p>
            </div>

            <!-- ⑤ احفظ -->
            <div class="d-pane" data-p="save">
              <span class="d-done"><?= s_icon('check', 30, 2.6) ?></span>
              <h3><?= e(__('منشورك وهوية')) ?> <span data-biz><?= e(__('مشروعك')) ?></span> <?= e(__('جاهزين 🎉')) ?></h3>
              <p><?= e(__('سجّل علشان تحفظهم في حسابك — الهوية والمنشور بيتحفظوا من غير ما يتخصم أي Credits.')) ?></p>
              <div class="d-row">
                <a class="ws-btn ws-pri" id="d-reg" href="<?= e($regUrl) ?>"><?= e(__('إنشاء حساب واحفظ')) ?><?= s_icon('arrow', 18, 2.2) ?></a>
                <button type="button" class="ws-btn ws-ghost" data-act="restart"><?= e(__('جرّب منشور تاني')) ?></button>
              </div>
              <a href="<?= e($loginUrl) ?>" id="d-login" style="font-size:13.5px;font-weight:600"><?= e(__('عندك حساب؟ سجّل دخول واحفظ ←')) ?></a>
            </div>
          </div>

          <!-- المعاينة الحيّة -->
          <div class="d-prev" aria-label="<?= e(__('معاينة المنشور')) ?>">
            <span class="d-live"><i class="sa-pulse"></i><?= e(__('معاينة حيّة')) ?></span>
            <div class="d-phone">
              <div class="d-ph-head"><span class="d-av" id="d-av">S</span><span><b data-biz><?= e(__('مشروعك')) ?></b><small><?= e(__('الآن')) ?></small></span></div>
              <div class="d-art" id="d-art">
                <div class="ph"><?= s_icon('image', 28, 1.5) ?><?= e(__('التصميم هيظهر هنا')) ?></div>
                <div class="sk"><span class="sa-shimmer"></span><span class="sa-shimmer"></span><span class="sa-shimmer"></span></div>
                <div class="ct"><i id="d-ac"></i><b id="d-art-t"></b><small id="d-art-s"><span data-biz><?= e(__('مشروعك')) ?></span> <?= e(__('· [صورة المنتج]')) ?></small></div>
              </div>
              <div class="d-ph-foot">
                <span class="ic"><?= s_icon('heart', 20) . s_icon('chat', 20) . s_icon('send', 20) ?></span>
                <p class="sa-clamp2" id="d-cap"></p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
