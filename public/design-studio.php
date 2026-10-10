<?php
/**
 * Spread AI v2 — استوديو التصميم المستقل
 * تصميم من محتوى / قبل وبعد / برومبت حر / صورة شخصية — بدون الحاجة لمنشور
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';

require_login();

$user = current_user();

// الشكل الجديد: Design Studio من التصميم (المرحلة ⑤) — الاستوديو القديم للشكل القديم زي ما هو
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) {
    require_once __DIR__ . '/../includes/ui-v2.php';
    $active = 'design-studio';
    $page_title = 'Design Studio';
    $use_app = true;
    include __DIR__ . '/../templates/header.php';
    echo '<div class="app">';
    include __DIR__ . '/../templates/sidebar.php';
    echo '<main class="main">';
    include __DIR__ . '/../templates/topbar.php';
    include __DIR__ . '/../templates/v2/studio.php';
    echo '</main></div>';
    include __DIR__ . '/../templates/footer.php';
    exit;
}
$brand = user_brand();
$designCost = cost_for('content_design_cost');
$ideaCost = (int) get_setting('studio_idea_cost', 1);
$balance = user_credits();

$templates = db_all('SELECT * FROM design_templates WHERE is_active = 1 ORDER BY sort_order, id');
$myDesigns = db_all('SELECT * FROM studio_designs WHERE user_id = ? ORDER BY id DESC LIMIT 24', [$user['id']]);

$modeLabels = ['from_content' => 'من محتوى', 'before_after' => 'قبل / بعد', 'free' => 'برومبت حر', 'personal' => 'صورة شخصية'];

$active = 'design-studio';
$page_title = 'استوديو التصميم';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1>استوديو التصميم ✨</h1>
            <div class="sub">تصميمات مستقلة: من محتوى · قبل/بعد · برومبت حر · صورتك <span class="cr">— رصيدك: <b><?= $balance ?></b> ◇</span></div>
        </div>

        <?= render_flash() ?>

        <div class="split split-st">

            <!-- لوحة الإنشاء -->
            <div class="card">
                <div class="seg" style="margin-bottom:16px">
                    <button type="button" class="on" data-mode="from_content" onclick="setMode(this)">📝 <span>من محتوى</span></button>
                    <button type="button" data-mode="before_after" onclick="setMode(this)">🔄 <span>قبل/بعد</span></button>
                    <button type="button" data-mode="free" onclick="setMode(this)">🎯 <span>برومبت</span></button>
                    <button type="button" data-mode="personal" onclick="setMode(this)">🤳 <span>صورتي</span></button>
                </div>

                <!-- من محتوى -->
                <div class="mode-pane" id="pane-from_content">
                    <div class="field">
                        <label>المحتوى / النص</label>
                        <textarea id="st-content" class="textarea" rows="4" placeholder="اكتب نص المنشور أو الفكرة اللي عايز تصميم ليها..."></textarea>
                    </div>
                    <div class="field">
                        <label style="display:flex;justify-content:space-between;align-items:center">
                            <span>💡 فكرة التصميم</span>
                            <button type="button" class="btn ghost sm" id="idea-btn" onclick="generateIdea()">✦ اقترح فكرة<span class="cr"> (<?= $ideaCost ?> ◇)</span></button>
                        </label>
                        <textarea id="st-idea" class="textarea" rows="3" placeholder="اكتب فكرتك أو دوس «اقترح فكرة» والـ AI يكتبها — وتقدر تعدلها"></textarea>
                    </div>
                    <div class="field">
                        <label>📎 صورة ستايل مرجعية (اختياري — الموديل يلتزم بستايلها)</label>
                        <input type="file" id="st-source" class="input" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <?php include __DIR__ . '/../templates/style-ref-picker.php'; ?>
                </div>

                <!-- قبل / بعد -->
                <div class="mode-pane" id="pane-before_after" style="display:none">
                    <div class="field-row">
                        <div class="field">
                            <label>صورة «قبل» <span class="req">*</span></label>
                            <input type="file" id="st-before" class="input" accept="image/jpeg,image/png,image/webp">
                        </div>
                        <div class="field">
                            <label>صورة «بعد» <span class="req">*</span></label>
                            <input type="file" id="st-after" class="input" accept="image/jpeg,image/png,image/webp">
                        </div>
                    </div>
                    <div class="field">
                        <label>توجيهات إضافية (اختياري)</label>
                        <textarea id="st-idea-ba" class="textarea" rows="2" placeholder="مثال: خلفية العيادة، لون كحلي، اسم الدكتور تحت"></textarea>
                    </div>
                </div>

                <!-- برومبت حر -->
                <div class="mode-pane" id="pane-free" style="display:none">
                    <div class="field">
                        <label>اكتب برومبت التصميم <span class="req">*</span></label>
                        <textarea id="st-free" class="textarea" rows="4" placeholder="اوصف الصورة اللي عايزها بالتفصيل: المشهد، الألوان، النص الظاهر، الستايل..."></textarea>
                    </div>
                    <div class="field">
                        <label>📎 صورة ستايل مرجعية (اختياري)</label>
                        <input type="file" id="st-source-free" class="input" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <?php include __DIR__ . '/../templates/style-ref-picker.php'; ?>
                </div>

                <!-- صورة شخصية -->
                <div class="mode-pane" id="pane-personal" style="display:none">
                    <div class="field">
                        <label>صورتك الشخصية <span class="req">*</span></label>
                        <input type="file" id="st-personal" class="input" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <div class="field">
                        <label>التصور المطلوب</label>
                        <textarea id="st-idea-p" class="textarea" rows="3" placeholder="مثال: بورتريه احترافي بخلفية مكتب فاخرة — أو ستايل سينمائي درامي — أو غلاف بودكاست"></textarea>
                    </div>
                </div>

                <!-- مشترك: قالب + لوجو -->
                <div style="border-top:1px dashed var(--line);margin-top:14px;padding-top:14px">
                    <?php $ratioScope = 'studio'; $ratioCurrent = '1:1'; include __DIR__ . '/../templates/ratio-picker.php'; ?>
                    <div class="field-row">
                        <div class="field">
                            <label>🎨 قالب الستايل (من إدارة المنصة)</label>
                            <select id="st-template" class="input">
                                <option value="0">— بدون قالب —</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= $t['id'] ?>" data-mode="<?= e($t['mode']) ?>"><?= e($t['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($brand && !empty($brand['logo_path'])): ?>
                        <div class="field" style="display:flex;align-items:flex-end">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px">
                                <input type="checkbox" id="st-logo" checked> إضافة لوجو البراند
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="studio-cta">
                        <button class="btn full" id="st-generate" onclick="generateStudio()">✨ توليد التصميم<span class="cr"> (<?= $designCost ?> ◇)</span></button>
                        <div id="st-status" class="field-help" style="display:none;margin-top:8px"></div>
                    </div>
                </div>
            </div>

            <!-- تصميماتي -->
            <div class="card studio-gallery">
                <div class="card-head"><h3>تصميماتي (<?= count($myDesigns) ?>)</h3></div>
                <div id="my-designs" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
                    <?php if (!$myDesigns): ?>
                        <p class="sub" id="no-designs">أول تصميم هيظهر هنا ✨</p>
                    <?php endif; ?>
                    <?php foreach ($myDesigns as $d): ?>
                        <a href="<?= url('storage/' . $d['image_path']) ?>" data-lightbox style="display:block">
                            <img src="<?= url('storage/' . $d['image_path']) ?>" class="design-thumb" loading="lazy" alt="">
                            <div class="sub" style="font-size:11px;margin-top:3px"><?= e($modeLabels[$d['mode']] ?? $d['mode']) ?> · <?= time_ago($d['created_at']) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
let currentMode = 'from_content';
const CSRF = '<?= e(csrf_token()) ?>';

function setMode(btn) {
    currentMode = btn.dataset.mode;
    document.querySelectorAll('.seg button').forEach(b => b.classList.toggle('on', b === btn));
    document.querySelectorAll('.mode-pane').forEach(p => p.style.display = p.id === 'pane-' + currentMode ? '' : 'none');
    // فلترة القوالب المناسبة للوضع
    document.querySelectorAll('#st-template option').forEach(o => {
        if (!o.dataset.mode) return;
        o.hidden = !(o.dataset.mode === 'any' || o.dataset.mode === currentMode);
    });
}

async function generateIdea() {
    const btn = document.getElementById('idea-btn');
    const text = document.getElementById('st-content').value.trim();
    if (text.length < 10) { alert('اكتب المحتوى الأول'); return; }
    btn.disabled = true; btn.textContent = '⏳ بيفكر...';
    try {
        const fd = new FormData();
        fd.append('csrf', CSRF); fd.append('action', 'idea'); fd.append('content_text', text);
        if (typeof safeFormData === 'function') safeFormData(fd, ['content_text']);
        const r = await fetch('<?= url('ajax/studio-design.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (d.ok) { document.getElementById('st-idea').value = d.idea; }
        else alert(d.error || 'حصل خطأ');
    } catch (e) { alert('خطأ في الاتصال'); }
    btn.disabled = false; btn.textContent = '✦ اقترح فكرة' + (window.SPREAD_CR !== false ? ' (<?= $ideaCost ?> ◇)' : '');
}

async function generateStudio() {
    const btn = document.getElementById('st-generate');
    const st = document.getElementById('st-status');
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'generate');
    fd.append('mode', currentMode);
    fd.append('template_id', document.getElementById('st-template').value);
    fd.append('include_logo', document.getElementById('st-logo')?.checked ? '1' : '0');
    fd.append('style_ref', (typeof selectedStyleRef === 'function') ? selectedStyleRef(document.getElementById('pane-' + currentMode)) : '');
    fd.append('ratio', (typeof selectedRatio === 'function') ? selectedRatio('studio') : '1:1');

    if (currentMode === 'from_content') {
        fd.append('content_text', document.getElementById('st-content').value.trim());
        fd.append('design_idea', document.getElementById('st-idea').value.trim());
        const f = document.getElementById('st-source').files[0];
        if (f) fd.append('source_image', f);
    } else if (currentMode === 'before_after') {
        const b = document.getElementById('st-before').files[0];
        const a = document.getElementById('st-after').files[0];
        if (!b || !a) { alert('ارفع صورتين: قبل وبعد'); return; }
        fd.append('before_image', b);
        fd.append('after_image', a);
        fd.append('design_idea', document.getElementById('st-idea-ba').value.trim());
    } else if (currentMode === 'free') {
        const p = document.getElementById('st-free').value.trim();
        if (!p) { alert('اكتب البرومبت الأول'); return; }
        fd.append('free_prompt', p);
        const f = document.getElementById('st-source-free').files[0];
        if (f) fd.append('source_image', f);
    } else if (currentMode === 'personal') {
        const f = document.getElementById('st-personal').files[0];
        if (!f) { alert('ارفع صورتك الأول'); return; }
        fd.append('personal_image', f);
        fd.append('design_idea', document.getElementById('st-idea-p').value.trim());
    }

    btn.disabled = true; btn.textContent = '⏳ الـ AI بيصمم... (ممكن ياخد دقيقة)';
    st.style.display = ''; st.textContent = 'بيتم إرسال الصور والهوية للموديل...';

    try {
        if (typeof safeFormData === 'function') safeFormData(fd, ['content_text', 'design_idea', 'free_prompt']);
        const r = await fetch('<?= url('ajax/studio-design.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (d.ok) {
            st.textContent = '✓ التصميم جاهز!';
            const grid = document.getElementById('my-designs');
            document.getElementById('no-designs')?.remove();
            const a = document.createElement('a');
            a.href = d.image_url; a.setAttribute('data-lightbox', '');
            a.innerHTML = '<img src="' + d.image_url + '" class="design-thumb"><div class="sub" style="font-size:11px;margin-top:3px">دلوقتي ✨</div>';
            grid.prepend(a);
        } else {
            st.textContent = '';
            alert(d.error || 'حصل خطأ');
        }
    } catch (e) {
        alert('خطأ في الاتصال — جرب تاني');
    }
    btn.disabled = false; btn.textContent = '✨ توليد التصميم' + (window.SPREAD_CR !== false ? ' (<?= $designCost ?> ◇)' : '');
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
