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
                <h1><?= e(__('منشور سريع ⚡')) ?></h1>
                <p><?= e(__('3 اختيارات وزرار — والـ AI يكتب البوست والـ CTA وفكرة التصميم بهوية براندك.')) ?></p>
                <a class="qp-classic" href="<?= url('create-content.php?classic=1') ?>"><?= e(__('الشكل القديم')) ?></a>
            </div>

            <section class="qp-card" id="qp-form">
                <div class="qp-step"><b>1</b><span><?= e(__('الخدمة')) ?></span></div>
                <div class="qp-chips" id="qp-services"><button type="button" class="on" data-v="0"><?= e(__('من غير خدمة')) ?></button></div>
                <p class="qp-mute" id="qp-no-svc" hidden><?= e(__('مفيش خدمات في هويتك لسه —')) ?> <a href="<?= url('brand-kit.php#bk-services') ?>"><?= e(__('ضيف خدماتك')) ?></a>.</p>

                <div class="qp-step"><b>2</b><span><?= e(__('الشكل')) ?></span></div>
                <div class="qp-chips" id="qp-formats"></div>

                <div class="qp-step"><b>3</b><span><?= e(__('لمين؟')) ?> <small><?= e(__('(العميل المثالي)')) ?></small></span></div>
                <div class="qp-chips" id="qp-personas"><button type="button" class="on" data-v="0"><?= e(__('سيبها للـ AI')) ?></button></div>
                <p class="qp-mute" id="qp-no-per" hidden><?= e(__('الـ AI يقدر يعمل عملاءك المثاليين في ثانية —')) ?> <a href="<?= url('brand-kit.php#bk-personas') ?>"><?= e(__('من هوية البراند')) ?></a>.</p>

                <label class="qp-lbl" for="qp-note"><?= e(__('عايز تقول إيه؟')) ?> <small><?= e(__('(اختياري)')) ?></small></label>
                <input id="qp-note" class="qp-in" maxlength="300" placeholder="<?= e(__('مثلًا: عرض 20% على التبييض لحد آخر الشهر')) ?>">

                <button type="button" class="qp-go" id="qp-go"><?= e(__('اكتب المنشور ✨')) ?> <small class="cr" id="qp-cost"></small></button>
                <p class="qp-err" id="qp-err" role="alert" hidden></p>
            </section>

            <section class="qp-card qp-out" id="qp-out" hidden>
                <div class="qp-out-head"><b id="qp-out-title"><?= e(__('المنشور جاهز ✓')) ?></b><span class="qp-mute" id="qp-out-fmt"></span></div>
                <div class="qp-field"><span><?= e(__('الـ Hook')) ?></span><p id="qp-hook" class="bidi-auto"></p></div>
                <div class="qp-field"><span><?= e(__('المنشور')) ?></span><p id="qp-body" class="bidi-auto"></p></div>
                <div class="qp-field" id="qp-slides-w" hidden><span><?= e(__('الشرائح')) ?></span><ol id="qp-slides" class="bidi-auto"></ol></div>
                <div class="qp-field" id="qp-script-w" hidden><span><?= e(__('سكريبت الفيديو')) ?></span><p id="qp-script" class="bidi-auto"></p></div>
                <div class="qp-field cta"><span><?= e(__('الـ CTA')) ?></span><p id="qp-cta" class="bidi-auto"></p></div>
                <div class="qp-field"><span><?= e(__('الهاشتاجات')) ?></span><p id="qp-tags" dir="auto"></p></div>
                <div class="qp-field design" id="qp-design-w"><span><?= e(__('🎨 فكرة التصميم')) ?></span><p id="qp-design"></p></div>
                <div class="qp-acts">
                    <a class="qp-go sm" id="qp-design-btn" href="#"><?= e(__('صمّم دلوقتي 🎨')) ?></a>
                    <a class="qp-btn" id="qp-open" href="#"><?= e(__('افتح المنشور')) ?></a>
                    <button type="button" class="qp-btn" id="qp-again"><?= e(__('نسخة تانية ↻')) ?></button>
                    <button type="button" class="qp-btn" id="qp-copy"><?= e(__('نسخ')) ?></button>
                </div>
            </section>
        </div>
    </main>
</div>
<script src="<?= $__qpVer('assets/js/quick-post.js') ?>" defer></script>
<?= i18n_js(['تعذّر التحميل', '{n} صور', '{n} كريدت', 'بيكتب… ثواني', 'تعذّر الكتابة', 'اتنسخ ✓', 'نسخ', 'انسخ يدويًا']) ?>
