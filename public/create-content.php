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
    redirect('brand-profile.php');
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
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <?php $__aiReady = (function_exists('smart_ai_enabled') && smart_ai_enabled()) || (defined('AI_API_KEY') && AI_API_KEY !== ''); ?>
        <?php if (!$__aiReady): ?>
            <div class="card" style="border:2px solid #f0c36d;background:#fdf6e3;margin-bottom:16px">
                ⚠️ <b>وضع التجربة</b> — محرك الذكاء الاصطناعي مش مربوط حاليًا، فالمحتوى اللي هيطلع <b>نموذج تجريبي</b> مش حقيقي. تواصل مع إدارة المنصة.
            </div>
        <?php endif; ?>

        <?php if ($fromCampaign): ?>
            <div class="alert info" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <span>📣 المنشور ده هيتضاف لحملة <b><?= e($fromCampaign['title']) ?></b></span>
                <a href="<?= url('campaign.php?id=' . (int) $fromCampaign['id'] . '&stage=2') ?>" class="btn ghost sm">← ارجع للحملة</a>
            </div>
        <?php endif; ?>
        <div class="page-head">
            <h1>إنشاء منشور جديد ✎</h1>
            <div class="sub">حدّد إعدادات المنشور وخلّي الذكاء الاصطناعي يولد لك محتوى احترافي</div>
        </div>

        <?php if ($balance < $cost): ?>
            <div class="alert danger">
                ⚠ <?= e(function_exists('credits_short_msg') ? credits_short_msg($cost) : 'رصيدك من الكريدت غير كافي.') ?>
                <a href="<?= url('credits.php') ?>"><span class="cr">عرض الرصيد ←</span><span class="cr-alt">الباقات ←</span></a>
            </div>
        <?php endif; ?>

        <div class="split split-flex" style="--c1:1.4fr;--c2:1fr;gap:20px;align-items:flex-start" id="create-grid">

            <!-- Form -->
            <div class="card">
                <div class="card-head">
                    <h3>إعدادات المنشور</h3>
                    <span class="chip chip-primary cr">◇ <?= $cost ?> كريدت</span>
                </div>

                <form id="create-form">
                    <?php if ($fromCampaign): ?>
                        <input type="hidden" name="campaign_id" value="<?= (int) $fromCampaign['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                    <div class="field">
                        <label>نوع المحتوى</label>
                        <div class="seg" id="seg-type">
                            <?php foreach (['introductory' => 'تعريفي', 'marketing' => 'تسويقي', 'educational' => 'تعليمي', 'engaging' => 'تفاعلي', 'offer' => 'عرض', 'trend' => 'ترند'] as $k => $v): ?>
                                <button type="button" data-val="<?= $k ?>" class="<?= $k === 'introductory' ? 'on' : '' ?>" onclick="setSeg(this, 'content_type')"><?= $v ?></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="content_type" value="introductory">
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>المنصة</label>
                            <div class="seg">
                                <button type="button" data-val="facebook" onclick="setSeg(this, 'platform')">فيسبوك</button>
                                <button type="button" data-val="instagram" onclick="setSeg(this, 'platform')">إنستجرام</button>
                                <button type="button" data-val="both" class="on" onclick="setSeg(this, 'platform')">الاثنين</button>
                            </div>
                            <input type="hidden" name="platform" value="both">
                        </div>

                        <div class="field">
                            <label>طول النص</label>
                            <div class="seg">
                                <button type="button" data-val="short" onclick="setSeg(this, 'length')">قصير</button>
                                <button type="button" data-val="medium" class="on" onclick="setSeg(this, 'length')">متوسط</button>
                                <button type="button" data-val="long" onclick="setSeg(this, 'length')">طويل</button>
                            </div>
                            <input type="hidden" name="length" value="medium">
                        </div>
                    </div>

                    <div class="field">
                        <label>نبرة الكلام</label>
                        <div class="seg">
                            <?php foreach (['simple' => 'بسيط', 'formal' => 'رسمي', 'fun' => 'مرح', 'professional' => 'احترافي'] as $k => $v): ?>
                                <button type="button" data-val="<?= $k ?>" class="<?= $k === 'simple' ? 'on' : '' ?>" onclick="setSeg(this, 'tone')"><?= $v ?></button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="tone" value="simple">

                        <div class="field">
                            <label>لهجة الكتابة</label>
                            <div class="seg">
                                <button type="button" data-val="egyptian" class="on" onclick="setSeg(this, 'dialect')">مصري</button>
                                <button type="button" data-val="gulf" onclick="setSeg(this, 'dialect')">خليجي</button>
                                <button type="button" data-val="msa" onclick="setSeg(this, 'dialect')">فصحى</button>
                            </div>
                            <input type="hidden" name="dialect" value="egyptian">
                        </div>

                        <?php if (!empty($templates)): ?>
                        <div class="field">
                            <label>قالب المنشور (اختياري)</label>
                            <select name="template_id" class="input">
                                <option value="0">بدون قالب — حر</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= $t['id'] ?>"><?= e($t['name']) ?><?= $t['description'] ? ' — ' . e($t['description']) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label>تعليمات إضافية</label>
                        <textarea name="extra_notes" class="textarea auto" rows="3"
                                  placeholder="مثال: ركز على الخصم اللي قدره 30%، اذكر أسم العيادة في البداية..."></textarea>
                        <div class="field-help">اختياري — أي ملاحظات تحب الـ AI ياخدها في الاعتبار</div>
                    </div>

                    <!-- Image options -->
                    <div class="field">
                        <label>إعدادات التصميم</label>
                        <div class="split split-flex" style="--c1:1fr;--c2:1fr;gap:8px">
                            <label class="checkbox">
                                <input type="checkbox" name="use_logo" value="1" checked>
                                <span>استخدام اللوجو</span>
                            </label>
                            <label class="checkbox">
                                <input type="checkbox" name="use_personal_image" value="1">
                                <span>استخدام صورة شخصية</span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn lg full" id="generate-btn" <?= $balance < $cost ? 'disabled' : '' ?>>
                        ✨ توليد المحتوى<span class="cr"> — <?= $cost ?> كريدت</span>
                    </button>
                </form>
            </div>

            <!-- Preview / Tips -->
            <div class="card" id="info-card">
                <div class="card-head">
                    <h3>الهوية الحالية</h3>
                    <a href="<?= url('brand-profile.php') ?>" class="text-mute" style="font-size:12px">تعديل ←</a>
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
                        <div class="text-mute" style="font-size:12px"><?= e($brand['industry'] ?? 'بدون مجال') ?></div>
                    </div>
                </div>

                <?php if (!empty($brand['audience'])): ?>
                    <div class="text-mute" style="font-size:12px;margin-bottom:6px">الجمهور:</div>
                    <div style="font-size:13px;margin-bottom:14px"><?= e(str_limit($brand['audience'], 120)) ?></div>
                <?php endif; ?>

                <div style="background:var(--surface-2);border-radius:14px;padding:14px;font-size:12px;color:var(--ink-2);line-height:1.7">
                    <b style="color:var(--primary-ink);display:block;margin-bottom:6px">💡 نصيحة</b>
                    كلما كانت بيانات الهوية مكتملة، كان المحتوى أقرب لروح براندك. أضف صور مرجعية وكلمات مفتاحية للنتايج الأفضل.
                </div>
            </div>
        </div>

        <!-- Generated content output (hidden until submit) -->
        <div id="output-area" style="display:none">
            <div class="content-output mt-20">
                <h4>✨ المنشور جاهز!</h4>

                <div class="content-text" id="out-content"></div>
                <div class="hashtags" id="out-hashtags"></div>
                <div class="cta-box" id="out-cta"></div>

                <div class="action-row">
                    <button type="button" class="btn ghost" onclick="copyAll()">📋 نسخ الكل</button>
                    <button type="button" class="btn ghost" id="regen-btn" onclick="regenerate()">⟲ إعادة توليد (<?= cost_for('content_regeneration_cost') ?> كريدت)</button>
                    <a href="#" class="btn" id="edit-btn">✎ تعديل وحفظ</a>
                </div>
            </div>
        </div>

    </main>
</div>

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
            return { ok: false, error: 'خطأ في الاتصال' };
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
    btn.innerHTML = '⟳ جاري التوليد...';

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
    btn.innerHTML = '✨ توليد المحتوى' + (window.SPREAD_CR !== false ? ' — <?= $cost ?> كريدت' : '');

    if (result.ok) {
        lastContentId = result.content_id;
        document.getElementById('out-content').textContent = result.content || '';
        document.getElementById('out-hashtags').textContent = result.hashtags || '';
        document.getElementById('out-cta').textContent = result.cta || '';
        document.getElementById('edit-btn').href = '<?= url('content-view.php') ?>?id=' + result.content_id;
        document.getElementById('output-area').style.display = 'block';
        document.getElementById('output-area').scrollIntoView({ behavior: 'smooth' });
        showToast('تم التوليد بنجاح ✓', 'success');
    } else {
        showToast(result.error || 'فشل التوليد', 'danger');
    }
});

async function regenerate() {
    if (!lastContentId) return;
    const btn = document.getElementById('regen-btn');
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = '⟳ جاري...';

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
        showToast('تم إعادة التوليد ✓', 'success');
    } else {
        showToast(result.error || 'فشل', 'danger');
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
