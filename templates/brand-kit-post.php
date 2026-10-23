<?php
/**
 * Brand Kit على صفحة البوست: chips الهوية المستخدمة (تتغير للبوست ده بس) · «ليه الـ AI عمل كده؟» · 👍/👎 · نسخة تانية · اعتمد وجدول
 * المتغيرات: $bkpContentId (int) · $bkpScheduleUrl (اختياري)
 */
if (empty($bkpContentId) || !function_exists('bk_enabled') || !bk_enabled()) return;
$__bkpV = fn(string $f) => url($f) . '?v=' . (@filemtime(__DIR__ . '/../' . $f) ?: time());
?>
<link rel="stylesheet" href="<?= $__bkpV('assets/css/brand-kit.css') ?>">
<div class="bkp" id="bkp" data-id="<?= (int) $bkpContentId ?>" data-api="<?= e(url('api/brand-kit.php')) ?>"
     data-regen="<?= e(url('ajax/regenerate-content.php')) ?>" data-kit="<?= e(url('brand-kit.php')) ?>"
     data-schedule="<?= e($bkpScheduleUrl ?? '#schedule') ?>" data-csrf="<?= e(csrf_token()) ?>" hidden>
    <h4>🧬 الهوية في البوست ده <small class="text-mute" style="font-weight:400">— غيّر أي حاجة للبوست ده بس</small></h4>
    <div class="bkp-chips" id="bkp-chips"></div>
    <ul class="bkp-why" id="bkp-why" hidden></ul>
    <div class="bkp-brief" id="bkp-brief"></div>
    <div class="bkp-act">
        <button type="button" class="btn sm" id="bkp-regen">⟲ نسخة تانية</button>
        <a class="btn sm primary" id="bkp-approve" href="#schedule">✓ اعتمد وجدول</a>
        <span class="bkp-fb" role="group" aria-label="رأيك في البوست"><button type="button" data-v="1" aria-label="عجبني">👍</button> <button type="button" data-v="-1" aria-label="ماعجبنيش">👎</button></span>
        <span class="note" id="bkp-note"></span>
        <span class="sq-reasons" id="bkp-reasons" hidden></span>
    </div>
</div>
<script src="<?= $__bkpV('assets/js/brand-kit-post.js') ?>" defer></script>
