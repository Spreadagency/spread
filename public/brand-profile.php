<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/uploader.php';

require_login();

$user = current_user();
$brand = user_brand();

if (!$brand) {
    // Auto-create empty brand
    db_run('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, ?)', [$user['id'], $user['name']]);
    $brand = user_brand();
}

$errors = [];

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();

    $fields = [
        'business_name', 'industry', 'description', 'audience', 'tone',
        'colors', 'dialect', 'keywords_use', 'keywords_avoid', 'notes',
        'design_rules', 'ai_summary', 'visual_identity',
        'services', 'address', 'phones', 'whatsapp', 'website',
        'social_facebook', 'social_instagram', 'social_tiktok', 'social_linkedin', 'working_hours'
    ];

    $data = [];
    foreach ($fields as $f) {
        $data[$f] = trim($_POST[$f] ?? '');
    }

    // Logo upload
    $logoPath = $brand['logo_path'];
    if (!empty($_FILES['logo']['name'])) {
        $up = upload_image($_FILES['logo'], 'logos');
        if ($up['ok']) {
            // Delete old logo
            if ($logoPath) delete_upload($logoPath);
            $logoPath = $up['path'];
        } else {
            $errors[] = 'فشل رفع اللوجو: ' . $up['error'];
        }
    }

    if (empty($errors)) {
        db_run(
            'UPDATE brand_profiles SET business_name=?, industry=?, description=?, audience=?, tone=?, colors=?, dialect=?, keywords_use=?, keywords_avoid=?, notes=?, design_rules=?, ai_summary=?, visual_identity=?,
             services=?, address=?, phones=?, whatsapp=?, website=?, social_facebook=?, social_instagram=?, social_tiktok=?, social_linkedin=?, working_hours=?,
             logo_path=? WHERE id=?',
            [
                $data['business_name'], $data['industry'], $data['description'],
                $data['audience'], $data['tone'], $data['colors'], $data['dialect'],
                $data['keywords_use'], $data['keywords_avoid'], $data['notes'],
                $data['design_rules'], $data['ai_summary'], $data['visual_identity'],
                $data['services'], $data['address'], $data['phones'], $data['whatsapp'], $data['website'],
                $data['social_facebook'], $data['social_instagram'], $data['social_tiktok'], $data['social_linkedin'], $data['working_hours'],
                $logoPath, $brand['id']
            ]
        );
        // Brand Brain: الحقول اللي العميل كتبها بنفسه مصدرها «مؤكد»
        try {
            require_once __DIR__ . '/../includes/brand-brain.php';
            $fresh = db_one('SELECT * FROM brand_profiles WHERE id = ?', [$brand['id']]);
            $changed = [];
            foreach (array_keys(brand_fields()) as $__f) {
                if ((string) ($brand[$__f] ?? '') !== (string) ($fresh[$__f] ?? '')) $changed[] = $__f;
            }
            if ($changed) brand_mark_manual($fresh, (int) $user['id'], $changed);
        } catch (\Throwable $e) { /* مايوقفش الحفظ */ }

        flash_set('success', 'تم حفظ بيانات الهوية بنجاح ✓');
        redirect(ui_v2_enabled() ? 'brand-brain.php' : 'brand-profile.php');
    }
}

$brand = user_brand(); // refresh
$images = get_brand_images((int) $brand['id']);

$active = 'brand';
$page_title = 'هوية البراند';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1>هوية البراند ◈</h1>
            <div class="sub">دي البيانات اللي الـ AI هيستخدمها علشان يكتب محتوى يشبه براندك تمامًا</div>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="alert danger"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="POST" data-safe-post enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="split split-l">

                <!-- Main: data fields -->
                <div class="card">
                    <div class="card-head">
                        <h3>بيانات النشاط</h3>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>اسم النشاط <span class="req">*</span></label>
                            <input type="text" name="business_name" class="input" required
                                   value="<?= e($brand['business_name']) ?>"
                                   placeholder="مثال: عيادة د. أحمد للأسنان">
                        </div>
                        <div class="field">
                            <label>المجال</label>
                            <input type="text" name="industry" class="input"
                                   value="<?= e($brand['industry'] ?? '') ?>"
                                   placeholder="مثال: طب أسنان، تجارة، خدمات...">
                        </div>
                    </div>

                    <div class="field">
                        <label>وصف البراند</label>
                        <textarea name="description" class="textarea auto" rows="3"
                                  placeholder="وصف قصير عن النشاط، الخدمات، الميزة التنافسية..."><?= e($brand['description'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label>الجمهور المستهدف</label>
                        <textarea name="audience" class="textarea auto" rows="2"
                                  placeholder="مثال: شباب من 25 إلى 40، أمهات، رجال أعمال..."><?= e($brand['audience'] ?? '') ?></textarea>
                    </div>

                    <div class="field-row-3">
                        <div class="field">
                            <label>نبرة الكتابة</label>
                            <select name="tone" class="select">
                                <option value="">اختر...</option>
                                <?php foreach (['simple' => 'بسيط', 'formal' => 'رسمي', 'fun' => 'مرح', 'professional' => 'احترافي'] as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= ($brand['tone'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>اللهجة</label>
                            <select name="dialect" class="select">
                                <option value="">فصحى</option>
                                <option value="egyptian" <?= ($brand['dialect'] ?? '') === 'egyptian' ? 'selected' : '' ?>>مصرية</option>
                                <option value="khaleeji" <?= ($brand['dialect'] ?? '') === 'khaleeji' ? 'selected' : '' ?>>خليجية</option>
                                <option value="levantine" <?= ($brand['dialect'] ?? '') === 'levantine' ? 'selected' : '' ?>>شامية</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>الألوان الأساسية</label>
                            <input type="text" name="colors" class="input"
                                   value="<?= e($brand['colors'] ?? '') ?>"
                                   placeholder="#7c6df2, #f0b967">
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>كلمات تحب استخدامها</label>
                            <textarea name="keywords_use" class="textarea auto" rows="2"
                                      placeholder="كلمات بتعبر عن براندك، افصلها بفواصل"><?= e($brand['keywords_use'] ?? '') ?></textarea>
                        </div>
                        <div class="field">
                            <label>كلمات تتجنبها</label>
                            <textarea name="keywords_avoid" class="textarea auto" rows="2"
                                      placeholder="كلمات لا تريد ظهورها في المحتوى"><?= e($brand['keywords_avoid'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="field">
                        <label>ملاحظات إضافية</label>
                        <textarea name="notes" class="textarea auto" rows="3"
                                  placeholder="أي تعليمات إضافية تساعد الـ AI في فهم براندك بشكل أفضل..."><?= e($brand['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label>🎨 شروط التصميم <span class="text-mute" style="font-size:12px">(بتدخل في كل تصميم بيتولد)</span></label>
                        <textarea name="design_rules" class="textarea auto" rows="3"
                                  placeholder="مثال: اللوجو دايمًا أعلى اليمين — ممنوع اللون الأحمر — الخط عريض وواضح — ستايل فاخر وهادي"><?= e($brand['design_rules'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                            <span>✦ ملخص الهوية الذكي <span class="text-mute" style="font-size:12px">(الـ AI بيبنيه من صورك ومستنداتك وبيدخل في المحتوى والتصميمات)</span></span>
                            <button type="button" class="btn ghost sm" id="brand-summary-btn" onclick="generateBrandSummary()">✦ توليد الملخص (<?= (int) get_setting('brand_summary_cost', 2) ?> ◇)</button>
                        </label>
                        <textarea name="ai_summary" id="ai-summary-box" class="textarea auto" rows="4"
                                  placeholder="دوس «توليد الملخص» — الـ AI هيحلل هويتك وصورك ومستنداتك ويكتب ملخص شامل هنا (وتقدر تعدله)"><?= e($brand['ai_summary'] ?? '') ?></textarea>
                    </div>

                    <div class="field">
                        <label style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                            <span>🖌 هوية التصميم البصرية <span class="text-mute" style="font-size:12px">(الـ AI بيدرس صورك وتصميماتك ويكتب نقط الستايل — وبتتطبق في كل تصميم جديد)</span></span>
                            <button type="button" class="btn ghost sm" id="visual-btn" onclick="analyzeVisual()">🖌 حلّل تصميماتي (<?= (int) get_setting('visual_identity_cost', 2) ?> ◇)</button>
                        </label>
                        <textarea name="visual_identity" id="visual-box" class="textarea auto" rows="6"
                                  placeholder="دوس «حلّل تصميماتي» — الـ AI هيبص على اللوجو وصورك وتصميماتك السابقة والمفضلات، ويكتب: الألوان الفعلية، الخطوط، التكوين، الأسلوب البصري، والحاجات اللي تتجنبها (وتقدر تعدل النص)"><?= e($brand['visual_identity'] ?? '') ?></textarea>
                        <?php if (!empty($brand['visual_identity_at'])): ?>
                            <div class="field-help">آخر تحليل: <?= e(fmt_date($brand['visual_identity_at'], true)) ?></div>
                        <?php endif; ?>
                        <div id="visual-status" class="field-help" style="display:none"></div>
                    </div>


                    <!-- الخدمات -->
                    <div style="border-top:1px dashed var(--line);margin-top:18px;padding-top:16px">
                        <h3 style="font-size:15px;margin-bottom:4px">🛠 الخدمات والمنتجات</h3>
                        <p class="field-help" style="margin-bottom:10px">اكتب كل خدمة في سطر — الـ AI هيستخدمها كمصدر حقائق في المحتوى والعروض</p>
                        <div class="field">
                            <textarea name="services" class="textarea auto" rows="5"
                                      placeholder="تنظيف وتلميع الأسنان — 300 جنيه&#10;تبييض بالليزر — 1500 جنيه&#10;زراعة الأسنان&#10;تقويم شفاف"><?= e($brand['services'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- التواصل -->
                    <div style="border-top:1px dashed var(--line);margin-top:18px;padding-top:16px">
                        <h3 style="font-size:15px;margin-bottom:10px">📍 العنوان والتواصل</h3>
                        <div class="field">
                            <label>العنوان</label>
                            <input type="text" name="address" class="input" value="<?= e($brand['address'] ?? '') ?>" placeholder="15 شارع الجمهورية، المنصورة، الدقهلية">
                        </div>
                        <div class="field-row">
                            <div class="field">
                                <label>أرقام التواصل</label>
                                <input type="text" name="phones" class="input" dir="ltr" value="<?= e($brand['phones'] ?? '') ?>" placeholder="0501234567 / 0123456789">
                            </div>
                            <div class="field">
                                <label>واتساب</label>
                                <input type="text" name="whatsapp" class="input" dir="ltr" value="<?= e($brand['whatsapp'] ?? '') ?>" placeholder="201012345678">
                            </div>
                            <div class="field">
                                <label>مواعيد العمل</label>
                                <input type="text" name="working_hours" class="input" value="<?= e($brand['working_hours'] ?? '') ?>" placeholder="السبت–الخميس 10ص – 10م">
                            </div>
                        </div>
                        <div class="field">
                            <label>الموقع الإلكتروني</label>
                            <input type="text" name="website" class="input" dir="ltr" value="<?= e($brand['website'] ?? '') ?>" placeholder="https://example.com">
                        </div>
                    </div>

                    <!-- السوشيال -->
                    <div style="border-top:1px dashed var(--line);margin-top:18px;padding-top:16px">
                        <h3 style="font-size:15px;margin-bottom:10px">🔗 صفحاتك على السوشيال</h3>
                        <div class="field-row">
                            <div class="field">
                                <label>📘 فيسبوك</label>
                                <input type="text" name="social_facebook" class="input" dir="ltr" value="<?= e($brand['social_facebook'] ?? '') ?>" placeholder="facebook.com/yourpage">
                            </div>
                            <div class="field">
                                <label>📸 انستجرام</label>
                                <input type="text" name="social_instagram" class="input" dir="ltr" value="<?= e($brand['social_instagram'] ?? '') ?>" placeholder="@yourbrand">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field">
                                <label>🎵 تيك توك</label>
                                <input type="text" name="social_tiktok" class="input" dir="ltr" value="<?= e($brand['social_tiktok'] ?? '') ?>" placeholder="@yourbrand">
                            </div>
                            <div class="field">
                                <label>💼 لينكدإن</label>
                                <input type="text" name="social_linkedin" class="input" dir="ltr" value="<?= e($brand['social_linkedin'] ?? '') ?>" placeholder="linkedin.com/company/...">
                            </div>
                        </div>
                    </div>

                    <div class="save-cta">
                        <button type="submit" class="btn lg full">
                            ✓ حفظ البيانات
                        </button>
                    </div>
                </div>

                <!-- Right: Logo -->
                <div class="card brand-side">
                    <div class="card-head">
                        <h3>اللوجو</h3>
                    </div>

                    <div style="text-align:center">
                        <?php if (!empty($brand['logo_path'])): ?>
                            <img src="<?= e(upload_url($brand['logo_path'])) ?>" alt="logo"
                                 style="max-width:160px;max-height:160px;border-radius:18px;background:var(--surface-2);padding:14px;border:1px solid var(--line);margin-bottom:14px">
                        <?php else: ?>
                            <div style="width:160px;height:160px;border-radius:18px;background:var(--surface-2);border:2px dashed var(--line-2);display:grid;place-items:center;font-size:48px;color:var(--mute);margin:0 auto 14px">
                                ◈
                            </div>
                        <?php endif; ?>

                        <label class="btn ghost full" style="cursor:pointer">
                            <input type="file" name="logo" id="logo-input" accept="image/*" style="display:none" onchange="previewLogo(this)">
                            📤 رفع لوجو جديد
                        </label>
                        <div id="logo-filename" class="field-help mt-10" style="display:none;color:var(--primary-ink);font-weight:600"></div>
                        <div class="field-help mt-10">PNG / SVG / JPG · حد أقصى 5 ميجا — <b>ولازم تدوس «حفظ» تحت</b></div>

                        <div style="border-top:1px dashed var(--line);margin-top:14px;padding-top:14px">
                            <button type="button" class="btn full sm" onclick="openLogoAI()">✨ اعمللي لوجو بالذكاء الاصطناعي (<?= (int) get_setting('logo_generate_cost', 3) ?> ◇)</button>
                        </div>
                    </div>
                </div>

            </div>
        </form>

        <!-- Brand images -->
        <div class="card mt-20">
            <div class="card-head">
                <h3>صور الهوية والمراجع <span class="count">• <?= count($images) ?> / <?= MAX_BRAND_IMAGES ?></span></h3>
            </div>

            <p class="text-mute mb-16" style="font-size:13px">
                ارفع صور خاصة بالعميل أو الطبيب، صور مرجعية لتصاميم تحبها، أو صور تلهم النظام بأسلوب التصميم المطلوب.
            </p>

            <div class="img-grid">
                <?php foreach ($images as $img): ?>
                    <div class="img-tile" data-id="<?= $img['id'] ?>">
                        <img src="<?= e(upload_url($img['image_path'])) ?>" alt="<?= e($img['title'] ?? '') ?>">
                        <span class="chip <?= e(image_type_chip($img['image_type'])) ?> img-type">
                            <?= e(image_type_label($img['image_type'])) ?>
                        </span>
                        <button type="button" class="img-del"
                                onclick="deleteImage(<?= $img['id'] ?>, this)" title="حذف">×</button>
                    </div>
                <?php endforeach; ?>

                <?php if (count($images) < MAX_BRAND_IMAGES): ?>
                    <label class="img-tile upload" id="upload-tile" for="img-upload-input">
                        <div>
                            <div style="font-size:32px;line-height:1">＋</div>
                            <div style="font-size:11.5px;margin-top:6px">رفع صورة</div>
                            <div style="font-size:10px;margin-top:2px">ضغط أو اختر من الجهاز</div>
                        </div>
                        <input type="file" id="img-upload-input" accept="image/*" style="display:none" onchange="uploadImage(this)">
                    </label>
                <?php else: ?>
                    <div class="img-tile upload" style="cursor:default;color:var(--mute)">
                        وصلت للحد الأقصى<br>(<?= MAX_BRAND_IMAGES ?> صور)
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<!-- Modal: image type selector -->
<div class="modal-bg" id="type-modal">
    <div class="modal">
        <h3>نوع الصورة</h3>
        <p class="text-mute mb-16" style="font-size:13px">حدد نوع الصورة دي علشان النظام يستخدمها صح</p>
        <div style="display:grid;gap:8px">
            <button type="button" class="btn ghost full" onclick="setImageType('personal')">
                🧑 صورة شخصية / للعميل (مثل صور الطبيب)
            </button>
            <button type="button" class="btn ghost full" onclick="setImageType('reference')">
                🔗 صورة مرجعية (للأشكال والاستلهام)
            </button>
            <button type="button" class="btn ghost full" onclick="setImageType('design')">
                🎨 تصميم ملهم (لتقليد الروح العامة)
            </button>
        </div>
        <div class="mt-16">
            <button class="btn ghost full sm" onclick="closeModal('type-modal')">إلغاء</button>
        </div>
    </div>
</div>

<script>
let pendingFile = null;

function uploadImage(input) {
    if (!input.files || !input.files[0]) return;
    pendingFile = input.files[0];
    openModal('type-modal');
}

async function setImageType(type) {
    if (!pendingFile) return;
    closeModal('type-modal');
    showToast('جاري رفع الصورة...', 'info');

    const result = await ajaxPost('<?= url('ajax/upload-image.php') ?>', {
        csrf: '<?= e(csrf_token()) ?>',
        image: pendingFile,
        image_type: type
    });

    if (result.ok) {
        showToast('تم رفع الصورة ✓', 'success');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(result.error || 'فشل الرفع', 'danger');
    }
    pendingFile = null;
}

async function deleteImage(id, btn) {
    if (!confirm('حذف الصورة دي؟')) return;
    btn.disabled = true;
    const result = await ajaxPost('<?= url('ajax/delete-image.php') ?>', {
        csrf: '<?= e(csrf_token()) ?>',
        id: id
    });
    if (result.ok) {
        showToast('تم الحذف', 'success');
        setTimeout(() => location.reload(), 500);
    } else {
        showToast(result.error || 'فشل الحذف', 'danger');
        btn.disabled = false;
    }
}
</script>

<!-- نافذة توليد اللوجو بالـ AI -->
<div id="logo-ai-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:900;align-items:center;justify-content:center;padding:20px">
    <div class="card" style="max-width:520px;width:100%;max-height:90vh;overflow-y:auto">
        <div class="card-head" style="display:flex;justify-content:space-between;align-items:center">
            <h3>✨ توليد لوجو بالذكاء الاصطناعي</h3>
            <button type="button" class="btn ghost sm" onclick="closeLogoAI()">✕</button>
        </div>
        <div class="field">
            <label>وصف اللوجو اللي عايزه</label>
            <textarea id="logo-desc" class="textarea" rows="3" placeholder="مثال: لوجو لعيادة أسنان باسم «النور» — سنة مبسطة بخط عصري، كحلي وذهبي، بسيط وأنيق"></textarea>
        </div>
        <div class="field">
            <label>الستايل</label>
            <select id="logo-style" class="input">
                <option value="minimal flat vector logo, clean geometric shapes">بسيط وعصري (Minimal)</option>
                <option value="elegant luxury logo with refined serif lettering and gold accents">فاخر وأنيق (Luxury)</option>
                <option value="playful friendly rounded mascot-style logo">ودود ومرح (Playful)</option>
                <option value="bold strong modern lettermark logo">جريء وقوي (Bold)</option>
                <option value="detailed emblem badge style logo">شعار كلاسيكي (Emblem)</option>
            </select>
        </div>
        <div class="field-help">الـ AI بيستخدم اسم نشاطك ومجالك وألوانك تلقائيًا. النتيجة بتتحفظ كلوجو مباشرة.</div>
        <button type="button" class="btn full" id="logo-gen-btn" onclick="generateLogo()">✨ ولّد اللوجو</button>
        <div id="logo-ai-result" style="margin-top:14px;text-align:center"></div>
    </div>
</div>

<script>
function previewLogo(input) {
    const f = input.files && input.files[0];
    if (!f) return;
    const label = document.getElementById('logo-filename');
    label.style.display = '';
    label.textContent = '✓ ' + f.name + ' — دوس «حفظ» عشان يتسجل';
    const img = input.closest('div').parentElement.querySelector('img');
    const reader = new FileReader();
    reader.onload = e => {
        if (img) { img.src = e.target.result; }
        else {
            const ph = input.closest('div').parentElement.querySelector('div[style*="dashed"]');
            if (ph) ph.innerHTML = '<img src="' + e.target.result + '" style="max-width:100%;max-height:100%;border-radius:14px">';
        }
    };
    reader.readAsDataURL(f);
}

function openLogoAI() { document.getElementById('logo-ai-modal').style.display = 'flex'; }
function closeLogoAI() { document.getElementById('logo-ai-modal').style.display = 'none'; }

async function generateLogo() {
    const btn = document.getElementById('logo-gen-btn');
    const out = document.getElementById('logo-ai-result');
    const desc = document.getElementById('logo-desc').value.trim();
    if (desc.length < 5) { alert('اكتب وصف اللوجو الأول'); return; }
    btn.disabled = true; btn.textContent = '⏳ بيرسم اللوجو...';
    out.innerHTML = '';
    try {
        const fd = new FormData();
        fd.append('csrf', '<?= e(csrf_token()) ?>');
        fd.append('description', desc);
        fd.append('style', document.getElementById('logo-style').value);
        if (typeof safeFormData === 'function') safeFormData(fd, ['description']);
        const r = await fetch('<?= url('ajax/generate-logo.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (d.ok) {
            out.innerHTML = '<img src="' + d.image_url + '" style="max-width:220px;border-radius:16px;background:#fff;padding:10px;border:1px solid var(--line)">'
                + '<p class="field-help" style="margin-top:8px">✓ اتحفظ كلوجو براندك — حدّث الصفحة تشوفه</p>'
                + '<a class="btn sm" href="' + d.image_url + '" download>⬇ تحميل</a>';
        } else {
            out.innerHTML = '<p style="color:#c0392b">' + (d.error || 'حصل خطأ') + '</p>';
        }
    } catch (e) {
        out.innerHTML = '<p style="color:#c0392b">خطأ في الاتصال</p>';
    }
    btn.disabled = false; btn.textContent = '✨ ولّد اللوجو';
}

async function analyzeVisual() {
    const btn = document.getElementById('visual-btn');
    const box = document.getElementById('visual-box');
    const st = document.getElementById('visual-status');
    btn.disabled = true; btn.textContent = '⏳ بيحلل التصميمات...';
    st.style.display = ''; st.textContent = 'الـ AI بيبص على اللوجو وصورك وتصميماتك...';
    try {
        const fd = new FormData();
        fd.append('csrf', '<?= e(csrf_token()) ?>');
        const r = await fetch('<?= url('ajax/analyze-visual-identity.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (d.ok) {
            box.value = d.visual_identity;
            box.style.height = 'auto'; box.style.height = box.scrollHeight + 'px';
            st.textContent = '✓ اتحلل ' + d.images_analyzed + ' صورة — راجع النقط وعدّل اللي تحبه ودوس حفظ';
        } else {
            st.textContent = '⚠️ ' + (d.error || 'حصل خطأ');
        }
    } catch (e) {
        st.textContent = '⚠️ خطأ في الاتصال';
    }
    btn.disabled = false; btn.textContent = '🖌 حلّل تصميماتي (<?= (int) get_setting('visual_identity_cost', 2) ?> ◇)';
}

async function generateBrandSummary() {
    const btn = document.getElementById('brand-summary-btn');
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = '⏳ الـ AI بيحلل هويتك...';
    try {
        const fd = new FormData();
        fd.append('csrf', '<?= e(csrf_token()) ?>');
        const r = await fetch('<?= url('ajax/generate-brand-summary.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (d.ok) {
            document.getElementById('ai-summary-box').value = d.summary;
            btn.textContent = '✓ اتولّد — راجعه واحفظ';
            setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 2500);
        } else {
            alert(d.error || 'حصل خطأ');
            btn.textContent = orig; btn.disabled = false;
        }
    } catch (e) {
        alert('خطأ في الاتصال');
        btn.textContent = orig; btn.disabled = false;
    }
}
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>
