<?php
/**
 * Spread AI — الأدمن: أسئلة الهوية في أول دخول (صفحة «ابدأ» عند العميل)
 *   زوّد سؤال · شيل سؤال · عدّل · رتّب (↑ ↓) · وقّف سؤال من غير ما تمسحه · نسخة إنجليزي لكل سؤال
 *   + تشغيل/إيقاف صفحة الأسئلة كلها لأي حساب جديد بعد أول دخول.
 * الأسئلة بتتحفظ في الإعداد onb_questions (includes/onb-questions.php) — مفيش جدول جديد.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/onboarding.php';

require_admin();
require_admin_can('site_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $act = (string) ($_POST['act'] ?? 'save');
    if ($act === 'defaults') {
        set_setting('onb_questions', json_encode(onb_q_defaults(), JSON_UNESCAPED_UNICODE));
        admin_log('onb_questions_defaults');
        flash_set('success', 'رجعت الأسئلة الأصلية الخمسة');
    } else {
        set_setting('onb_questions_on', !empty($_POST['on']) ? '1' : '0');
        $in = (array) ($_POST['q'] ?? []);
        $topic = (string) ($_POST['topic'] ?? '');
        $list = [];
        foreach ((array) ($_POST['order'] ?? []) as $tok) {
            $tok = (string) $tok;
            if (!isset($in[$tok]) || !is_array($in[$tok])) continue;
            $q = $in[$tok];
            $q['active'] = !empty($q['active']);
            $q['block'] = !empty($q['block']);
            $q['role'] = $tok === $topic ? 'topic' : '';
            $list[] = $q;
        }
        $before = count($list);
        $saved = onb_q_save($list);
        admin_log('onb_questions_save', null, null, ['count' => count($saved), 'on' => !empty($_POST['on'])]);
        $dropped = $before - count($saved);
        flash_set($dropped ? 'warning' : 'success', 'اتحفظ — ' . count($saved) . ' سؤال'
            . ($dropped ? ' · ' . $dropped . ' سؤال ماتحفظش (العنوان فاضي، أو سؤال اختيار من غير اختيارين على الأقل)' : ''));
    }
    redirect('admin/onboarding-questions.php');
}

$questions = onb_q_all();
$on = onb_questions_on();
$active = 'onboarding-questions';
$page_title = 'أسئلة الهوية';
include __DIR__ . '/../templates/admin-header.php';

/** كارت سؤال واحد (نفس الشكل للموجود وللقالب الفاضي) */
function oq_card(array $q, string $tok): string
{
    $v = fn($k) => e(is_array($q[$k] ?? null) ? implode("\n", $q[$k]) : (string) ($q[$k] ?? ''));
    $kinds = '';
    foreach (ONB_Q_KINDS as $k => $l) $kinds .= '<option value="' . $k . '"' . (($q['kind'] ?? 'text') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    $n = 'q[' . $tok . ']';
    return '<div class="card oq" data-tok="' . e($tok) . '" style="padding:16px;margin-bottom:12px">'
        . '<input type="hidden" name="order[]" value="' . e($tok) . '"><input type="hidden" name="' . $n . '[id]" value="' . $v('id') . '">'
        . '<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px"><b class="oq-num" style="min-width:26px"></b>'
        . '<label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="' . $n . '[active]" value="1"' . (!empty($q['active']) ? ' checked' : '') . '> ظاهر للعملاء</label>'
        . '<span style="flex:1"></span>'
        . '<button type="button" class="btn ghost sm" data-mv="-1" title="لفوق">↑</button><button type="button" class="btn ghost sm" data-mv="1" title="لتحت">↓</button>'
        . '<button type="button" class="btn ghost sm" data-del style="color:#b3261e">حذف</button></div>'
        . '<div style="display:grid;grid-template-columns:2fr 1fr;gap:10px">'
        . '<div class="field"><label>السؤال</label><input class="input" name="' . $n . '[title]" maxlength="160" value="' . $v('title') . '" placeholder="مثلًا: إيه أكتر حاجة عملاءك بيسألوا عنها؟"></div>'
        . '<div class="field"><label>نوعه</label><select class="select" name="' . $n . '[kind]" data-kind>' . $kinds . '</select></div></div>'
        . '<div class="field"><label>شرح صغير تحته <span class="text-mute">(اختياري)</span></label><input class="input" name="' . $n . '[sub]" maxlength="200" value="' . $v('sub') . '"></div>'
        . '<div class="field" data-for="single multi"><label>الاختيارات — كل اختيار في سطر (اتنين على الأقل)</label><textarea class="textarea" rows="4" name="' . $n . '[opts]">' . $v('opts') . '</textarea>'
        . '<label style="display:flex;gap:6px;align-items:center;font-size:13px;margin-top:6px"><input type="checkbox" name="' . $n . '[block]" value="1"' . (!empty($q['block']) ? ' checked' : '') . '> الاختيارات جمل طويلة (كل اختيار في سطر لوحده)</label></div>'
        . '<div class="field" data-for="text sector"><label>مثال جوه الخانة <span class="text-mute">(اختياري)</span></label><input class="input" name="' . $n . '[ph]" maxlength="120" value="' . $v('ph') . '"></div>'
        . '<label data-for="text" style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="radio" name="topic" value="' . e($tok) . '"' . (($q['role'] ?? '') === 'topic' ? ' checked' : '') . '> إجابة السؤال ده هي موضوع أول بوست</label>'
        . '<details style="margin-top:10px"><summary style="cursor:pointer;font-size:13px">EN · النسخة الإنجليزي (للعميل اللي فاتح المنصة بالإنجليزي)</summary><div dir="ltr" style="margin-top:8px">'
        . '<div class="field"><label>Question</label><input class="input" name="' . $n . '[title_en]" maxlength="160" value="' . $v('title_en') . '"></div>'
        . '<div class="field"><label>Hint</label><input class="input" name="' . $n . '[sub_en]" maxlength="200" value="' . $v('sub_en') . '"></div>'
        . '<div class="field" data-for="single multi"><label>Choices — one per line, same order as Arabic</label><textarea class="textarea" rows="4" name="' . $n . '[opts_en]">' . $v('opts_en') . '</textarea></div>'
        . '<div class="field" data-for="text sector"><label>Placeholder</label><input class="input" name="' . $n . '[ph_en]" maxlength="120" value="' . $v('ph_en') . '"></div>'
        . '<div class="text-mute" style="font-size:12px">Empty = the ready-made English translation if there is one, otherwise the Arabic text.</div></div></details>'
        . '</div>';
}
?>
<style>.oq [hidden] { display: none !important; }</style>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <div>
                <h1>أسئلة الهوية (أول دخول) <span class="chip chip-primary"><?= count(array_filter($questions, fn($q) => !empty($q['active']))) ?> ظاهر</span></h1>
                <div class="sub">العميل الجديد بيجاوبها بعد أول دخول — الإجابات بتبني هويته (Brand Kit) وأول بوست. زوّد أو شيل أو رتّب براحتك.</div>
            </div>
        </div>

        <?= render_flash() ?>

        <form method="POST" id="oq-form">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="save">
            <div class="card" style="padding:16px;margin-bottom:14px">
                <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer">
                    <input type="checkbox" name="on" value="1" <?= $on ? 'checked' : '' ?> style="margin-top:4px">
                    <span><b>اعرض صفحة الأسئلة لأي حساب جديد بعد أول دخول</b>
                    <span class="text-mute" style="display:block;font-size:13px">الحساب اللي لسه ماعملش أول بوست بيتنقل لصفحة «ابدأ» مرة في كل دخول — وفيها «تخطّي». الحسابات اللي عندها بوستات مابتتأثرش.</span></span>
                </label>
            </div>

            <div id="oq-list">
                <?php foreach ($questions as $i => $q) echo oq_card($q, 't' . $i); ?>
            </div>
            <?php if (!$questions): ?><div class="alert warning" id="oq-empty">مفيش أسئلة — العميل هيروح على بناء الهوية من اللينك أو الملف على طول.</div><?php endif; ?>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
                <button type="button" class="btn ghost" id="oq-add">+ سؤال جديد</button>
                <span style="flex:1"></span>
                <button type="submit" class="btn">حفظ</button>
            </div>
        </form>
        <form method="POST" style="margin-top:18px" onsubmit="return confirm('ترجّع الأسئلة الأصلية الخمسة؟ أي تعديل هيتمسح.')">
            <?= csrf_field() ?><input type="hidden" name="act" value="defaults">
            <button type="submit" class="btn ghost sm">رجّع الأسئلة الأصلية</button>
        </form>
        <template id="oq-tpl"><?= oq_card(['kind' => 'single', 'active' => true, 'opts' => []], '__TOK__') ?></template>
    </main>
</div>
<script>
(function () {
    var list = document.getElementById('oq-list'), n = <?= count($questions) ?>;
    function sync() {
        list.querySelectorAll('.oq').forEach(function (c, i) {
            c.querySelector('.oq-num').textContent = (i + 1) + '.';
            var k = c.querySelector('[data-kind]').value;
            c.querySelectorAll('[data-for]').forEach(function (el) { el.hidden = el.dataset.for.split(' ').indexOf(k) < 0; });
        });
        var max = <?= ONB_Q_MAX ?>, add = document.getElementById('oq-add');
        add.disabled = list.querySelectorAll('.oq').length >= max;
        add.title = add.disabled ? 'الحد ' + max + ' سؤال' : '';
    }
    list.addEventListener('click', function (e) {
        var c = e.target.closest('.oq'); if (!c) return;
        if (e.target.matches('[data-del]')) {
            var t = c.querySelector('input[name$="[title]"]').value.trim();
            if (!t || confirm('تحذف السؤال «' + t + '»؟ (التغيير بيتحفظ لما تدوس «حفظ»)')) c.remove();
        } else if (e.target.matches('[data-mv]')) {
            var up = e.target.dataset.mv === '-1', sib = up ? c.previousElementSibling : c.nextElementSibling;
            if (sib) list.insertBefore(c, up ? sib : sib.nextSibling);
        } else return;
        sync();
    });
    list.addEventListener('change', function (e) { if (e.target.matches('[data-kind]')) sync(); });
    document.getElementById('oq-add').addEventListener('click', function () {
        var tok = 'n' + (n++), html = document.getElementById('oq-tpl').innerHTML.split('__TOK__').join(tok);
        list.insertAdjacentHTML('beforeend', html);
        var em = document.getElementById('oq-empty'); if (em) em.remove();
        sync();
        list.lastElementChild.querySelector('input[name$="[title]"]').focus();
    });
    sync();
})();
</script>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
