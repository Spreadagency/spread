<?php
/** قسم «trial» — نفس قسم الرئيسية (بيتعرض في الرئيسية والصفحات الداخلية وبلوكات الـ Page Builder) */
if (!$trialOn) return;
?>
  <section class="h-create" id="s-create" data-say="جرّب بنفسك — من غير حساب" data-look="1">
    <div class="h-wrap">
      <?= $secHead('trial', 'جرّبها دلوقتي — من غير حساب') ?>
      <div class="ws-card d-card rv rv-s" id="demo">
        <div class="d-grid">
          <div class="d-main">
            <div class="d-steps" role="list" aria-label="خطوات التجربة">
              <?php foreach (['بياناتك', '4 أفكار', 'المنشور', 'التصميم', 'احفظ'] as $i => $t): ?>
                <div role="listitem" data-s="<?= $i ?>" class="<?= $i === 0 ? 'cur' : '' ?>"><span class="n"><em><?= $i + 1 ?></em><?= s_icon('check', 15, 2.6) ?></span><span class="t"><?= e($t) ?></span><?php if ($i < 4): ?><span class="bar"></span><?php endif; ?></div>
              <?php endforeach; ?>
            </div>

            <!-- ① البيانات -->
            <form class="d-pane on" data-p="form" novalidate>
              <div class="d-h"><h3>عرّفنا على مشروعك</h3><span>3 معلومات بس — والباقي على Spread AI.</span></div>
              <div class="d-two">
                <div class="d-f"><label for="d-biz">اسم النشاط</label><input id="d-biz" class="ws-input" maxlength="200" placeholder="مثلاً: مطعم البيت" autocomplete="organization">
                  <span class="d-err" id="d-biz-err" role="alert" hidden>اكتب اسم النشاط علشان نبني عليه الأفكار</span></div>
                <div class="d-f"><label for="d-aud">مين جمهورك؟ <small>(اختياري)</small></label><input id="d-aud" class="ws-input" maxlength="300" placeholder="مثلاً: عائلات في المنصورة"></div>
              </div>
              <div class="d-f"><span class="d-lbl" id="d-fl">المجال</span>
                <div class="d-chips" role="group" aria-labelledby="d-fl">
                  <?php foreach (['مطعم أو كافيه', 'عيادة أو مركز طبي', 'متجر ملابس', 'أكاديمية أو كورسات', 'خدمات', 'مجال تاني'] as $i => $f): ?>
                    <button type="button" class="ws-pill<?= $i === 0 ? ' f-on' : '' ?>" data-field="<?= e($f) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= e($f) ?></button>
                  <?php endforeach; ?>
                </div>
                <input id="d-other" class="ws-input" maxlength="150" placeholder="اكتب مجالك (مثلاً: محل حلويات)" hidden>
              </div>
              <div class="d-f"><span class="d-lbl" id="d-gl">هدف المنشور</span>
                <div class="d-chips" role="group" aria-labelledby="d-gl">
                  <?php foreach (['زيادة المبيعات', 'تعريف بالبراند', 'عرض خاص', 'تفاعل أكتر'] as $i => $g): ?>
                    <button type="button" class="ws-pill<?= $i === 0 ? ' g-on' : '' ?>" data-goal="<?= e($g) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= e($g) ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
              <p class="d-msg" data-msg hidden role="alert"></p>
              <button type="submit" class="ws-btn ws-pri" data-act="ideas" style="align-self:flex-start">
                <span class="idle">ولّد 4 أفكار</span><?= s_icon('sparkle', 18, 2) ?>
              </button>
            </form>

            <!-- ② الأفكار -->
            <div class="d-pane" data-p="ideas">
              <div class="d-h"><h3>4 أفكار لـ <span data-biz>مشروعك</span></h3><span>اختار الفكرة اللي تعجبك.</span></div>
              <div class="d-ideas" id="d-ideas"></div>
              <p class="d-msg" data-msg hidden role="alert"></p>
              <div class="d-row">
                <button type="button" class="ws-btn ws-pri" data-act="post" hidden>اكتب المنشور<?= s_icon('arrow', 18, 2.2) ?></button>
                <span class="d-pick-hint" data-pick-hint>اختار فكرة الأول ←</span>
                <button type="button" class="ws-btn ws-ghost" data-act="regen"><?= s_icon('shuffle', 17) ?>أفكار تانية</button>
                <button type="button" class="ws-link" data-go="form">تعديل البيانات</button>
              </div>
            </div>

            <!-- ③ المنشور -->
            <div class="d-pane" data-p="post">
              <div class="d-h"><h3>منشورك جاهز ✍️</h3><span>مكتوب بصوت <span data-biz>مشروعك</span> — والأقواس [ ] مكان تفاصيلك.</span></div>
              <div class="d-post"><span class="sa-chip" id="d-post-chip"></span><p id="d-post-text"></p><span class="tags" id="d-post-tags"></span></div>
              <div class="d-row">
                <button type="button" class="ws-btn ws-pri" data-go="design">صمّمه<?= s_icon('arrow', 18, 2.2) ?></button>
                <button type="button" class="ws-btn ws-ghost" data-act="copy">نسخ المنشور</button>
                <button type="button" class="ws-link" data-go="ideas">غيّر الفكرة</button>
              </div>
            </div>

            <!-- ④ التصميم — بيتحقق من الاشتراك الأول (مفيش أي طلب تصميم قبل التحقق) -->
            <?php $__pw = s_paywall(); ?>
            <div class="d-pane" data-p="design">
              <div class="d-gate-load" data-gate-view="loading" role="status">
                <svg class="sa-spin" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="#0C87EF" stroke-width="2.6" stroke-linecap="round" stroke-dasharray="14 8"></circle></svg>
                <span>بنتأكد من اشتراكك قبل التصميم...</span>
              </div>
              <div class="d-pay" data-gate-view="paywall" hidden>
                <span class="d-pay-ic"><?= s_icon('image', 30, 1.6) ?><i><?= s_icon('sparkle', 14, 2) ?></i></span>
                <span class="sa-chip d-pay-chip">جاهز تحوّل منشورك لتصميم؟</span>
                <h3><?= e($__pw['paywall_title']) ?></h3>
                <p><?= e($__pw['paywall_body']) ?></p>
                <?php if ($__pw['benefits']): ?>
                <ul class="d-pay-list">
                  <?php foreach ($__pw['benefits'] as $b): ?><li><i><?= s_icon('check', 14, 2.6) ?></i><?= e($b) ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <div class="d-row">
                  <a class="ws-btn ws-pri" data-pay-cta href="<?= e($__pw['paywall_cta_url'] ?: '#s-pricing') ?>"><?= e($__pw['paywall_cta']) ?><?= s_icon('arrow', 18, 2.2) ?></a>
                  <button type="button" class="ws-btn ws-ghost" data-go="post">العودة للمنشور</button>
                </div>
                <button type="button" class="ws-link d-pay-save" data-go="save">أو سجّل حساب مجاني واحفظ المنشور من غير تصميم ←</button>
              </div>
              <div class="d-pay ok" data-gate-view="subscribed" hidden>
                <span class="d-pay-ic"><?= s_icon('check', 30, 2.4) ?></span>
                <h3>انت مشترك — يلا نصمّم ✨</h3>
                <p>هننقل المنشور لحسابك ونكمّل التصميم والجدولة والنشر من جوه المنصة.</p>
                <div class="d-row">
                  <a class="ws-btn ws-pri" data-continue href="#">كمّل للتصميم<?= s_icon('arrow', 18, 2.2) ?></a>
                  <button type="button" class="ws-btn ws-ghost" data-go="post">رجوع للمنشور</button>
                </div>
              </div>
              <p class="d-msg" data-msg hidden role="alert"></p>
            </div>

            <!-- ⑤ احفظ -->
            <div class="d-pane" data-p="save">
              <span class="d-done"><?= s_icon('check', 30, 2.6) ?></span>
              <h3>منشورك وهوية <span data-biz>مشروعك</span> جاهزين 🎉</h3>
              <p>سجّل علشان تحفظهم في حسابك — الهوية والمنشور بيتحفظوا من غير ما يتخصم أي Credits.</p>
              <div class="d-row">
                <a class="ws-btn ws-pri" id="d-reg" href="<?= e($regUrl) ?>">إنشاء حساب واحفظ<?= s_icon('arrow', 18, 2.2) ?></a>
                <button type="button" class="ws-btn ws-ghost" data-act="restart">جرّب منشور تاني</button>
              </div>
              <a href="<?= e($loginUrl) ?>" id="d-login" style="font-size:13.5px;font-weight:600">عندك حساب؟ سجّل دخول واحفظ ←</a>
            </div>
          </div>

          <!-- المعاينة الحيّة -->
          <div class="d-prev" aria-label="معاينة المنشور">
            <span class="d-live"><i class="sa-pulse"></i>معاينة حيّة</span>
            <div class="d-phone">
              <div class="d-ph-head"><span class="d-av" id="d-av">S</span><span><b data-biz>مشروعك</b><small>الآن</small></span></div>
              <div class="d-art" id="d-art">
                <div class="ph"><?= s_icon('image', 28, 1.5) ?>التصميم هيظهر هنا</div>
                <div class="sk"><span class="sa-shimmer"></span><span class="sa-shimmer"></span><span class="sa-shimmer"></span></div>
                <div class="ct"><i id="d-ac"></i><b id="d-art-t"></b><small id="d-art-s"><span data-biz>مشروعك</span> · [صورة المنتج]</small></div>
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
