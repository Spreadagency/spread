<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/uploader.php';

require_login();

$templates = db_all('SELECT id, name, description FROM content_templates WHERE is_active = 1 ORDER BY order_num ASC');

$user = current_user();
$brand = user_brand();
$balance = user_credits();

if (!$brand || empty($brand['business_name'])) {
    flash_set('warning', 'كمّل بيانات هويتك الأساسية الأول علشان نقدر نولد محتوى مناسب');
    redirect(onb_enabled() ? 'start.php' : 'brand-profile.php');   // البداية السريعة: 3 خطوات بدل فورم الهوية
}

$cost = cost_for('content_generation_cost');
$brandImages = get_brand_images((int) $brand['id']);

$active = 'create';

// جاي من حملة؟ (المرحلة 1)
$fromCampaign = null;
if (!empty($_GET['campaign'])) {
    $fromCampaign = db_one('SELECT id, title FROM campaigns WHERE id = ? AND user_id = ? AND status <> "archived"',
        [(int) $_GET['campaign'], (int) $user['id']]) ?: null;
}
$page_title = 'إنشاء منشور';
// المنشور السريع الجديد (خدمة ← شكل ← عميل مثالي) — الشكل القديم بـ ?classic=1 · ومن الحملة بيفضل القديم
require_once __DIR__ . '/../includes/brand-kit.php';
if (empty($_GET['classic']) && !$fromCampaign && bk_enabled() && function_exists('ui_v2_enabled') && ui_v2_enabled()) {
    $active = 'create';
    $page_title = 'منشور سريع';
    $use_app = true;   // SpreadAPI
    $i18n_ready = true;   // الترجمة: المنشور السريع مترجم (الدفعة 2)
    include __DIR__ . '/../templates/header.php';
    include __DIR__ . '/../templates/v2/quick-post.php';
    include __DIR__ . '/../templates/footer.php';
    return;
}
$i18n_ready = true;   // الترجمة: إنشاء المنشور مترجم (الدفعة 2)
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <?php $__aiReady = (function_exists('smart_ai_enabled') && smart_ai_enabled()) || (defined('AI_API_KEY') && AI_API_KEY !== ''); ?>
        <?php if (!$__aiReady): ?>
            <div class="card" style="border:2px solid #f0c36d;background:#fdf6e3;margin-bottom:16px">
                ⚠️ <b><?= e(__('وضع التجربة')) ?></b> <?= e(__('— محرك الذكاء الاصطناعي مش مربوط حاليًا، فالمحتوى اللي هيطلع')) ?> <b><?= e(__('نموذج تجريبي')) ?></b> <?= e(__('مش حقيقي. تواصل مع إدارة المنصة.')) ?>
            </div>
        <?php endif; ?>

        <?php if ($fromCampaign): ?>
            <div class="alert info" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <span><?= e(__('📣 المنشور ده هيتضاف لحملة')) ?> <b><?= e($fromCampaign['title']) ?></b></span>
                <a href="<?= url('campaign.php?id=' . (int) $fromCampaign['id'] . '&stage=2') ?>" class="btn ghost sm"><?= e(__('← ارجع للحملة')) ?></a>
            </div>
        <?php endif; ?>
        <div class="page-head">
            <h1><?= e(__('إنشاء منشور جديد ✎')) ?></h1>
            <div class="sub"><?= e(__('حدّد إعدادات المنشور وخلّي الذكاء الاصطناعي يولد لك محتوى احترافي')) ?></div>
        </div>

        <?php if ($balance < $cost): ?>
            <div class="alert danger">
                ⚠ <?= e(__(function_exists('credits_short_msg') ? credits_short_msg($cost) : 'رصيدك من الكريدت غير كافي.')) ?>
                <a href="<?= url('credits.php') ?>"><span class="cr"><?= e(__('عرض الرصيد ←')) ?></span><span class="cr-alt"><?= e(__('الباقات ←')) ?></span></a>
            </div>
        <?php endif; ?>

        <div class="split split-flex" style="--c1:1.4fr;--c2:1fr;gap:20px;align-items:flex-start" id="create-grid">

            <!-- Form -->
            <div class="card">
                <div class="card-head">
                    <h3><?= e(__('إعدادات المنشور')) ?></h3>
                    <span class="chip chip-primary cr">◇ <?= $cost ?> <?= e(__('كريدت')) ?></span>
                </div>

                <?php $__adv = onb_enabled() ? ' cc-adv' : '';   // الفورم البسيط (المرحلة 1): 3 حقول ظاهرة والباقي تحت «إعدادات أكتر» بنفس القيم الافتراضية ?>
                <?php if ($__adv): ?><style>#create-form:not(.show-adv) .cc-adv { display: none; }</style><?php endif; ?>
                <form id="create-form">
                    <?php if ($fromCampaign): ?>
                        <input type="hidden" name="campaign_id" value="<?= (int) $fromCampaign['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                    <div class="field">
                        <label><?= e(__('نوع المحتوى')) ?></label>
                        <div class="seg" id="seg-type">
                            <?php foreach (['introductory' => 'تعريفي', 'marketing' => 'تسويقي', 'educational' => 'تعليمي', 'engaging' => 'تفاعلي', 'offer' => 'عرض', 'trend' => 'ترند'] as $k => $v): ?>
                                <button type="button" data-val="<?= $k ?>" class="<?= $k === 'introductory' ? 'on' : '' ?>" onclick="setSeg(this, 'content_type')"><?= e(__($v)) ?></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="content_type" value="introductory">
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label><?= e(__('المنصة')) ?></label>
                            <div class="seg">
                                <button type="button" data-val="facebook" onclick="setSeg(this, 'platform')"><?= e(__('فيسبوك')) ?></button>
                                <button type="button" data-val="instagram" onclick="setSeg(this, 'platform')"><?= e(__('إنستجرام')) ?></button>
                                <button type="button" data-val="both" class="on" onclick="setSeg(this, 'platform')"><?= e(__('الاثنين')) ?></button>
                            </div>
                            <input type="hidden" name="platform" value="both">
                        </div>

                        <div class="field<?= $__adv ?>">
                            <label><?= e(__('طول النص')) ?></label>
                            <div class="seg">
                                <button type="button" data-val="short" onclick="setSeg(this, 'length')"><?= e(__('قصير')) ?></button>
                                <button type="button" data-val="medium" class="on" onclick="setSeg(this, 'length')"><?= e(__('متوسط')) ?></button>
                                <button type="button" data-val="long" onclick="setSeg(this, 'length')"><?= e(__('طويل')) ?></button>
                            </div>
                            <input type="hidden" name="length" value="medium">
                        </div>
                    </div>

                    <div class="field<?= $__adv ?>">
                        <label><?= e(__('نبرة الكلام')) ?></label>
                        <div class="seg">
                            <?php $__bkOn = is_file(__DIR__ . '/../includes/brand-kit.php') && (require_once __DIR__ . '/../includes/brand-kit.php') && bk_enabled();
                            $__toneDef = $__bkOn ? '' : 'simple';
                            foreach (($__bkOn ? ['' => 'زي الهوية'] : []) + ['simple' => 'بسيط', 'formal' => 'رسمي', 'fun' => 'مرح', 'professional' => 'احترافي'] as $k => $v): ?>
                                <button type="button" data-val="<?= $k ?>" class="<?= $k === $__toneDef ? 'on' : '' ?>" onclick="setSeg(this, 'tone')"><?= e(__($v)) ?></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="tone" value="<?= $__toneDef ?>">

                        <div class="field">
                            <label><?= e(__('لهجة الكتابة')) ?></label>
                            <div class="seg">
                                <?php $__dl = function_exists('bk_dialect_key') ? bk_dialect_key((string) ($brand['dialect'] ?? 'egyptian')) : 'egyptian';
                                $__dl = in_array($__dl, ['egyptian', 'gulf', 'levantine', 'msa', 'english'], true) ? $__dl : 'egyptian';
                                foreach (['egyptian' => 'مصري', 'gulf' => 'خليجي', 'levantine' => 'شامي', 'msa' => 'فصحى', 'english' => 'English'] as $k => $v): ?>
                                <button type="button" data-val="<?= $k ?>" class="<?= $k === $__dl ? 'on' : '' ?>" onclick="setSeg(this, 'dialect')"><?= e(__($v)) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="dialect" value="<?= $__dl ?>">
                        </div>

                        <?php if (!empty($templates)): ?>
                        <div class="field">
                            <label><?= e(__('قالب المنشور (اختياري)')) ?></label>
                            <select name="template_id" class="input">
                                <option value="0"><?= e(__('بدون قالب — حر')) ?></option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= e(__($t['name'])) ?><?= $t['description'] ? ' — ' . e(__($t['description'])) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label><?= e(__($__adv ? 'البوست عن إيه؟' : 'تعليمات إضافية')) ?></label>
                        <textarea name="extra_notes" class="textarea auto" rows="3"
                                  placeholder="<?= e(__('مثال: ركز على الخصم اللي قدره 30%، اذكر أسم العيادة في البداية...')) ?>"></textarea>
                        <div class="field-help"><?= e(__('اختياري — أي ملاحظات تحب الـ AI ياخدها في الاعتبار')) ?></div>
                    </div>

                    <!-- Image options -->
                    <div class="field<?= $__adv ?>">
                        <label><?= e(__('إعدادات التصميم')) ?></label>
                        <div class="split split-flex" style="--c1:1fr;--c2:1fr;gap:8px">
                            <label class="checkbox">
                                <input type="checkbox" name="use_logo" value="1" checked>
                                <span><?= e(__('استخدام اللوجو')) ?></span>
                            </label>
                            <label class="checkbox">
                                <input type="checkbox" name="use_personal_image" value="1">
                                <span><?= e(__('استخدام صورة شخصية')) ?></span>
                            </label>
                        </div>
                    </div>

                    <?php if ($__adv): ?>
                        <button type="button" class="btn ghost sm" id="adv-toggle" style="margin-bottom:12px" aria-expanded="false"
                                onclick="var f=document.getElementById('create-form'),o=f.classList.toggle('show-adv');this.setAttribute('aria-expanded',o);this.textContent=o?'إخفاء الإعدادات ▴':'إعدادات أكتر ▾'"><?= e(__('إعدادات أكتر ▾')) ?></button>
                    <?php endif; ?>
                    <button type="submit" class="btn lg full" id="generate-btn" <?= $balance < $cost ? 'disabled' : '' ?>>
                        <?= e(__('✨ توليد المحتوى')) ?><span class="cr"> — <?= $cost ?> <?= e(__('كريدت')) ?></span>
                    </button>
                </form>
            </div>

            <!-- Preview / Tips -->
            <div class="card" id="info-card">
                <div class="card-head">
                    <h3><?= e(__('الهوية الحالية')) ?></h3>
                    <a href="<?= url('brand-profile.php') ?>" class="text-mute" style="font-size:12px"><?= e(__('تعديل ←')) ?></a>
                </div>

                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                    <?php if (!empty($brand['logo_path'])): ?>
                        <img src="<?= e(upload_url($brand['logo_path'])) ?>"
                             style="width:48px;height:48px;border-radius:14px;object-fit:contain;background:var(--surface-2);padding:6px;border:1px solid var(--line)">
                    <?php else: ?>
                        <div class="avi lg" style="background:<?= e(color_from_string($brand['business_name'])) ?>">
                            <?= e(initials($brand['business_name'])) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <b style="font-size:15px"><?= e($brand['business_name']) ?></b>
                        <div class="text-mute" style="font-size:12px"><?= e($brand['industry'] ?? __('بدون مجال')) ?></div>
                    </div>
                </div>

                <?php if (!empty($brand['audience'])): ?>
                    <div class="text-mute" style="font-size:12px;margin-bottom:6px"><?= e(__('الجمهور:')) ?></div>
                    <div style="font-size:13px;margin-bottom:14px"><?= e(str_limit($brand['audience'], 120)) ?></div>
                <?php endif; ?>

                <div style="background:var(--surface-2);border-radius:14px;padding:14px;font-size:12px;color:var(--ink-2);line-height:1.7">
                    <b style="color:var(--primary-ink);display:block;margin-bottom:6px"><?= e(__('💡 نصيحة')) ?></b>
                    <?= e(__('كلما كانت بيانات الهوية مكتملة، كان المحتوى أقرب لروح براندك. أضف صور مرجعية وكلمات مفتاحية للنتايج الأفضل.')) ?>
                </div>
            </div>
        </div>

        <!-- Generated content output (hidden until submit) -->
        <div id="output-area" style="display:none">
            <div class="content-output mt-20">
                <h4><?= e(__('✨ المنشور جاهز!')) ?></h4>

                <div class="content-text" id="out-content"></div>
                <div class="hashtags" id="out-hashtags"></div>
                <div class="cta-box" id="out-cta"></div>

                <div class="action-row">
                    <button type="button" class="btn ghost" onclick="copyAll()"><?= e(__('📋 نسخ الكل')) ?></button>
                    <button type="button" class="btn ghost" id="regen-btn" onclick="regenerate()"><?= e(__('⟲ إعادة توليد (')) ?><?= cost_for('content_regeneration_cost') ?> <?= e(__('كريدت)')) ?></button>
                    <a href="#" class="btn" id="edit-btn"><?= e(__('✎ تعديل وحفظ')) ?></a>
                </div>
            </div>
        </div>

    </main>
</div>

<?= i18n_js(['تم التوليد بنجاح ✓', 'تم إعادة التوليد ✓', '✨ توليد المحتوى', 'خطأ في الاتصال', '⟳ جاري التوليد...', 'فشل التوليد', '⟳ جاري...', 'فشل', '{n} كريدت']) ?>
<script>
// حماية: لو main.js قديم من الكاش أو ناقص — الصفحة تشتغل برضه
if (typeof window.showToast !== 'function') {
    window.showToast = function (msg, type) { alert(msg); };
}
if (typeof window.ajaxPost !== 'function') {
    window.ajaxPost = async function (url, data) {
        try {
            const fd = new FormData();
            for (const k in data) fd.append(k, data[k]);
            const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
            return await r.json();
        } catch (e) {
            return { ok: false, error: __t('خطأ في الاتصال') };
        }
    };
}

function setSeg(btn, name) {
    btn.parentElement.querySelectorAll('button').forEach(b => b.classList.remove('on'));
    btn.classList.add('on');
    document.querySelector(`input[name="${name}"]`).value = btn.dataset.val;
}

let lastContentId = null;

document.getElementById('create-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('generate-btn');
    btn.disabled = true;
    btn.innerHTML = __t('⟳ جاري التوليد...');

    const formData = new FormData(e.target);
    const data = {};
    formData.forEach((v, k) => { data[k] = v; });

    // Add unchecked checkboxes as 0
    ['use_logo', 'use_personal_image'].forEach(c => {
        if (!data[c]) data[c] = 0;
    });

    // Streaming first (live output), fallback to the normal endpoint
    let result = await generateStreaming(data);
    if (result === null) {
        result = await ajaxPost('<?= url('ajax/generate-content.php') ?>', data);
    }

    btn.disabled = false;
    btn.innerHTML = __t('✨ توليد المحتوى') + (window.SPREAD_CR !== false ? ' — ' + __t('{n} كريدت', { n: <?= (int) $cost ?> }) : '');

    if (result.ok) {
        lastContentId = result.content_id;
        document.getElementById('out-content').textContent = result.content || '';
        document.getElementById('out-hashtags').textContent = result.hashtags || '';
        document.getElementById('out-cta').textContent = result.cta || '';
        document.getElementById('edit-btn').href = '<?= url('content-view.php') ?>?id=' + result.content_id;
        document.getElementById('output-area').style.display = 'block';
        document.getElementById('output-area').scrollIntoView({ behavior: 'smooth' });
        showToast(__t('تم التوليد بنجاح ✓'), 'success');
    } else {
        showToast(result.error || __t('فشل التوليد'), 'danger');
    }
});

async function regenerate() {
    if (!lastContentId) return;
    const btn = document.getElementById('regen-btn');
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = __t('⟳ جاري...');

    const result = await ajaxPost('<?= url('ajax/regenerate-content.php') ?>', {
        csrf: '<?= e(csrf_token()) ?>',
        content_id: lastContentId
    });

    btn.disabled = false;
    btn.textContent = orig;

    if (result.ok) {
        document.getElementById('out-content').textContent = result.content || '';
        document.getElementById('out-hashtags').textContent = result.hashtags || '';
        document.getElementById('out-cta').textContent = result.cta || '';
        showToast(__t('تم إعادة التوليد ✓'), 'success');
    } else {
        showToast(result.error || __t('فشل'), 'danger');
    }
}

// SSE streaming generation (feature 24). Returns final result object, or null to trigger fallback.
async function generateStreaming(data) {
    try {
        const resp = await fetch('<?= url('ajax/generate-stream.php') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        });
        if (!resp.ok || !resp.body) return null;

        // Show output area in live mode
        const outArea = document.getElementById('output-area');
        const outContent = document.getElementById('out-content');
        outContent.textContent = '';
        document.getElementById('out-hashtags').textContent = '';
        document.getElementById('out-cta').textContent = '';
        outArea.style.display = 'block';
        outArea.scrollIntoView({ behavior: 'smooth' });

        const reader = resp.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let liveText = '';
        let finalResult = null;

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            let idx;
            while ((idx = buffer.indexOf('\n\n')) !== -1) {
                const block = buffer.slice(0, idx);
                buffer = buffer.slice(idx + 2);
                const line = block.split('\n').find(l => l.startsWith('data:'));
                if (!line) continue;
                let j;
                try { j = JSON.parse(line.slice(5).trim()); } catch (e) { continue; }
                if (j.t) {
                    liveText += j.t;
                    // strip format tags while streaming for clean live view
                    outContent.textContent = liveText
                        .replace(/\[\/?(CONTENT|HASHTAGS|CTA)\]/g, '')
                        .trimStart();
                }
                if (j.done) finalResult = j;
            }
        }
        return finalResult; // may be {ok:false,...} which shows the error normally
    } catch (e) {
        return null; // network/SSE unsupported -> fallback
    }
}

function copyAll() {
    const text = [
        document.getElementById('out-content').textContent,
        '',
        document.getElementById('out-hashtags').textContent,
        '',
        document.getElementById('out-cta').textContent
    ].join('\n');
    copyToClipboard(text);
}
</script>
<style>
@media (max-width: 980px) { #create-grid { grid-template-columns: 1fr !important; } }
</style>

<?php include __DIR__ . '/../templates/footer.php'; ?>
