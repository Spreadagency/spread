<?php
/**
 * منتقي مقاس التصميم (partial مشترك)
 * الصفحة المستضيفة بتقرأ: document.querySelector('.ratio-opt.on')?.dataset.ratio
 * $ratioScope = معرّف فريد لو الصفحة فيها أكتر من منتقي
 */
$rpScope = $ratioScope ?? 'main';
$rpCurrent = $ratioCurrent ?? '1:1';
?>
<div class="field">
    <label style="font-size:13px">📐 مقاس التصميم</label>
    <div class="ratio-strip" data-scope="<?= e($rpScope) ?>">
        <?php foreach (design_ratios() as $key => $r): ?>
            <button type="button" class="ratio-opt <?= $key === $rpCurrent ? 'on' : '' ?>" data-ratio="<?= e($key) ?>"
                    onclick="pickRatio(this)" title="<?= e($r['hint']) ?>">
                <span class="ratio-box" style="aspect-ratio:<?= str_replace(':', '/', $key) ?>"></span>
                <span class="ratio-lbl"><?= e($key) ?></span>
            </button>
        <?php endforeach; ?>
    </div>
</div>
<script>
if (typeof window.pickRatio !== 'function') {
    window.pickRatio = function (el) {
        el.closest('.ratio-strip').querySelectorAll('.ratio-opt').forEach(b => b.classList.remove('on'));
        el.classList.add('on');
    };
    window.selectedRatio = function (scope) {
        const strip = scope
            ? document.querySelector('.ratio-strip[data-scope="' + scope + '"]')
            : document.querySelector('.ratio-strip');
        return strip?.querySelector('.ratio-opt.on')?.dataset.ratio || '1:1';
    };
}
</script>
