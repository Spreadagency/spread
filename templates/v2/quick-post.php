<?php
/**
 * المنشور السريع (الجديد): خدمة ← شكل ← عميل مثالي ← بوست + CTA + فكرة التصميم
 * المتغيرات من create-content.php: $user · $fromCampaign
 */
$__qpVer = fn(string $f) => url($f) . '?v=' . (@filemtime(__DIR__ . '/../../' . $f) ?: time());
?>
<link rel="stylesheet" href="<?= $__qpVer('assets/css/quick-post.css') ?>">
<div class="app">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../topbar.php'; ?>
        <?= render_flash() ?>
        <div class="qp" id="qp" data-base="<?= e(rtrim(url(''), '/')) ?>">
            <div class="qp-head">
                <h1>منشور سريع ⚡</h1>
                <p>3 اختيارات وزرار — والـ AI يكتب البوست والـ CTA وفكرة التصميم بهوية براندك.</p>
                <a class="qp-classic" href="<?= url('create-content.php?classic=1') ?>">الشكل القديم</a>
            </div>

            <section class="qp-card" id="qp-form">
                <div class="qp-step"><b>1</b><span>الخدمة</span></div>
                <div class="qp-chips" id="qp-services"><button type="button" class="on" data-v="0">من غير خدمة</button></div>
                <p class="qp-mute" id="qp-no-svc" hidden>مفيش خدمات في هويتك لسه — <a href="<?= url('brand-kit.php#bk-services') ?>">ضيف خدماتك</a>.</p>

                <div class="qp-step"><b>2</b><span>الشكل</span></div>
                <div class="qp-chips" id="qp-formats"></div>

                <div class="qp-step"><b>3</b><span>لمين؟ <small>(العميل المثالي)</small></span></div>
                <div class="qp-chips" id="qp-personas"><button type="button" class="on" data-v="0">سيبها للـ AI</button></div>
                <p class="qp-mute" id="qp-no-per" hidden>الـ AI يقدر يعمل عملاءك المثاليين في ثانية — <a href="<?= url('brand-kit.php#bk-personas') ?>">من هوية البراند</a>.</p>

                <label class="qp-lbl" for="qp-note">عايز تقول إيه؟ <small>(اختياري)</small></label>
                <input id="qp-note" class="qp-in" maxlength="300" placeholder="مثلًا: عرض 20% على التبييض لحد آخر الشهر">

                <button type="button" class="qp-go" id="qp-go">اكتب المنشور ✨ <small class="cr" id="qp-cost"></small></button>
                <p class="qp-err" id="qp-err" role="alert" hidden></p>
            </section>

            <section class="qp-card qp-out" id="qp-out" hidden>
                <div class="qp-out-head"><b id="qp-out-title">المنشور جاهز ✓</b><span class="qp-mute" id="qp-out-fmt"></span></div>
                <div class="qp-field"><span>الـ Hook</span><p id="qp-hook"></p></div>
                <div class="qp-field"><span>المنشور</span><p id="qp-body"></p></div>
                <div class="qp-field" id="qp-slides-w" hidden><span>الشرائح</span><ol id="qp-slides"></ol></div>
                <div class="qp-field" id="qp-script-w" hidden><span>سكريبت الفيديو</span><p id="qp-script"></p></div>
                <div class="qp-field cta"><span>الـ CTA</span><p id="qp-cta"></p></div>
                <div class="qp-field"><span>الهاشتاجات</span><p id="qp-tags" dir="auto"></p></div>
                <div class="qp-field design" id="qp-design-w"><span>🎨 فكرة التصميم</span><p id="qp-design"></p></div>
                <div class="qp-acts">
                    <a class="qp-go sm" id="qp-design-btn" href="#">صمّم دلوقتي 🎨</a>
                    <a class="qp-btn" id="qp-open" href="#">افتح المنشور</a>
                    <button type="button" class="qp-btn" id="qp-again">نسخة تانية ↻</button>
                    <button type="button" class="qp-btn" id="qp-copy">نسخ</button>
                </div>
            </section>
        </div>
    </main>
</div>
<script src="<?= $__qpVer('assets/js/quick-post.js') ?>" defer></script>
