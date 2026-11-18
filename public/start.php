<?php
/**
 * Spread AI — «ابدأ»: رحلة الهوية الجديدة (سيناريو التبسيط · الشاشات 1–17)
 *
 * بعد التسجيل وتأكيد الإيميل، العميل (الجديد أو الحالي اللي لسه ما اعتمدش هويته من هنا) بيمشي:
 *   لينكه ← «ده اللي لقيناه» ← أسئلة الأدمن ← اللوجو ← الألوان (3 اختيارات) ← الخدمات ← العملاء المثاليين (✓/✗)
 *   ← طريقة الكلام ← استايل التصميم (+ رفع تصميم عاجبه أو لينك) ← التواصل والمواعيد ← صور الأشخاص (بموافقة)
 *   ← لينكات تانية ← الملخص «اعتمد الهوية» ← ربط صفحة ميتا ← عرض البداية ← أول منشور.
 *
 * مفيش AI جديد هنا: كل الخطوات بتستخدم public/api/brand-kit.php و public/api/brand.php زي ما هما،
 * وأول منشور من public/ajax/generate-content.php. عرض البداية من includes/trial.php.
 * الحفظ تدريجي: كل شاشة بتحفظ قبل اللي بعدها — لو قفل الصفحة في النص مفيش حاجة بتضيع.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/brand-kit.php';
require_once __DIR__ . '/../includes/brand-brain.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/trial.php';
require_once __DIR__ . '/../includes/uploader.php';   // upload_url (اللوجو الحالي)

require_login();
$user = current_user();
$uid = (int) $user['id'];

if (!onb_start_enabled()) {
    redirect('brand-kit.php');
}

/** الحقول اللي الرحلة بتحفظها مباشرة في brand_profiles */
const IJ_FIELDS = ['business_name', 'description', 'address', 'phones', 'whatsapp', 'working_hours',
                   'website', 'social_facebook', 'social_instagram', 'social_tiktok', 'social_linkedin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!rate_limit('onb_name', 'u' . $uid, 60, 600)) {
        json_response(['ok' => false, 'error' => __('استنى شوية وجرّب تاني')], 429);
    }
    $act = (string) ($_POST['act'] ?? 'name');
    if (!db_one('SELECT id FROM brand_profiles WHERE user_id = ? LIMIT 1', [$uid])) {
        db_run('INSERT INTO brand_profiles (user_id) VALUES (?)', [$uid]);
    }
    if ($act === 'name') {
        /* اسم النشاط (register_user بيحط اسم الشخص مكانه — هنا العميل بيكتب الاسم الصح) */
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        if (mb_strlen($name) < 2) json_response(['ok' => false, 'error' => __('اكتب اسم نشاطك')]);
        db_run('UPDATE brand_profiles SET business_name = ?, updated_at = NOW() WHERE user_id = ?', [$name, $uid]);
        json_response(['ok' => true]);
    }
    if ($act === 'fields') {
        $brand = brand_for_user($uid);
        $in = (array) ($_POST['f'] ?? []);
        $n = 0;
        foreach ($in as $k => $v) {
            if (!in_array($k, IJ_FIELDS, true) || !is_string($v)) continue;
            if ((string) ($brand[$k] ?? '') === trim($v)) continue;
            if ($k === 'business_name' && trim($v) === '') continue;
            $r = brand_field_save($brand, $uid, $k, $v);
            if (!$r['ok']) json_response(['ok' => false, 'error' => __($r['error'])]);
            $brand = brand_for_user($uid);
            $n++;
        }
        json_response(['ok' => true, 'saved' => $n]);
    }
    if ($act === 'finish') {
        ij_mark_done($uid);
        $granted = trial_grant($uid);
        $_SESSION['onb_offered'] = 1;
        json_response(['ok' => true, 'trial_granted' => $granted, 'trial' => trial_status($uid), 'balance' => credits_balance($uid), 'subscribed' => plan_subscribed($uid)]);
    }
    json_response(['ok' => false, 'error' => __('حصلت مشكلة مؤقتة — جرّب تاني')], 400);
}

$verifyOnly = isset($_GET['verify']);
$verified = !empty($user['email_verified_at']);
if (!$verifyOnly && ij_done($uid) && !isset($_GET['again'])) {
    redirect('dashboard.php');
}

$gift = $verifyOnly ? 0 : onb_gift_grant($uid);
$brand = user_brand();
$bp = brand_for_user($uid) ?: [];
$bizName = trim((string) ($brand['business_name'] ?? ''));
if ($bizName === trim((string) $user['name'])) $bizName = '';   // القيمة التلقائية من التسجيل
$sectors = array_map(fn($s) => ['id' => (int) $s['id'], 'name' => (string) $s['name']], bk_sectors());
// أسئلة الهوية من الأدمن (admin/onboarding-questions.php) بلغة العميل — من غير المجال والأسلوب (ليهم شاشات هنا)
$questions = array_map(fn($q) => onb_q_for_client($q, i18n_is_en()), ij_questions());
// لغة أول بوست: لغة الهوية لو اتحددت، وإلا لغة الواجهة
$postDialect = bk_is_english((string) ($brand['dialect'] ?? '')) || (empty($brand['dialect']) && i18n_is_en()) ? 'english' : 'egyptian';
$basics = [];
foreach (IJ_FIELDS as $f) $basics[$f] = (string) ($bp[$f] ?? '');
$basics['business_name'] = $bizName;
$trialCfg = trial_cfg();
$boot = [
    'self' => url('start.php'), 'api' => url('api/brand-kit.php'), 'bapi' => url('api/brand.php'),
    'gen' => url('ajax/generate-content.php'), 'view' => url('content-view.php'), 'dash' => url('dashboard.php'),
    'social' => url('social-accounts.php?from=start'), 'packages' => url('packages.php'),
    'sectors' => $sectors, 'questions' => $questions, 'dialect' => $postDialect, 'basics' => $basics,
    'logo' => !empty($bp['logo_path']) ? upload_url($bp['logo_path']) : '',
    'trial' => ['eligible' => trial_eligible($uid), 'days' => $trialCfg['days'], 'contents' => $trialCfg['contents'], 'designs' => $trialCfg['designs']],
    'social_on' => !function_exists('feature_allows') || feature_allows($uid, 'social_publishing'),
    'verified' => $verified,
];

$page_title = 'ابدأ';
$i18n_ready = true;   // الترجمة: الصفحة دي مترجمة بالكامل
$__no_tabbar = true;
$__no_assistant = true;   // الصفحة خطوة واحدة في كل مرة — من غير زرار المساعد العائم
include __DIR__ . '/../templates/header.php';
?>
<style>
.onb-wrap { min-height: 100vh; display: flex; justify-content: center; padding: 20px 16px 40px; background: var(--bg); }
.onb { width: 100%; max-width: 560px; }
.onb-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.onb-bar { flex: 1; max-width: 260px; height: 6px; border-radius: 4px; background: var(--line); overflow: hidden; }
.onb-bar i { display: block; height: 100%; width: 0; background: var(--primary, #4a3db8); transition: width .3s; }
.onb-card { background: var(--surface); border-radius: var(--r-xl, 18px); padding: 24px 20px; box-shadow: var(--shadow-lg, 0 8px 30px rgba(0,0,0,.08)); }
.onb-card + .onb-card { margin-top: 14px; }
.onb-card h2 { margin: 0 0 6px; font-size: 20px; }
.onb-card .sub { color: var(--mute); font-size: 13.5px; margin: 0 0 18px; line-height: 1.7; }
.onb-step { color: var(--mute); font-size: 12px; margin-bottom: 6px; }
.onb-opts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.onb-opt { border: 1.5px solid var(--line); background: var(--surface-2, #f7f7fb); border-radius: 12px; padding: 11px 14px; font: inherit; font-size: 14px; cursor: pointer; color: inherit; min-height: 44px; text-align: start; }
.onb-opt.on { border-color: var(--primary, #4a3db8); background: rgba(124,109,242,.12); font-weight: 600; }
.onb-opt.block { width: 100%; line-height: 1.7; }
.onb-opt[disabled] { opacity: .75; cursor: default; }
.onb-row { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; }
.onb-row .btn { flex: 1; }
.onb-or { text-align: center; color: var(--mute); font-size: 12px; margin: 10px 0; }
.onb-msg { border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; line-height: 1.7; }
.onb-msg.err { background: rgba(220,60,60,.1); color: #b3261e; }
.onb-msg.ok { background: rgba(60,170,120,.12); color: #1c7a52; }
.onb-msg.info { background: rgba(124,109,242,.1); }
.onb-load { text-align: center; padding: 30px 0; }
.onb-spin { width: 34px; height: 34px; border: 3px solid var(--line); border-top-color: var(--primary, #4a3db8); border-radius: 50%; margin: 0 auto 14px; animation: onbspin 1s linear infinite; }
@keyframes onbspin { to { transform: rotate(360deg); } }
.onb-id dt { color: var(--mute); font-size: 12px; margin-top: 10px; }
.onb-id dd { margin: 2px 0 0; font-size: 14px; line-height: 1.7; }
.onb-sw { display: inline-block; width: 22px; height: 22px; border-radius: 6px; border: 1px solid var(--line); margin-inline-end: 6px; vertical-align: middle; }
.onb-post { white-space: pre-wrap; font-size: 14.5px; line-height: 1.9; background: var(--surface-2, #f7f7fb); border-radius: 12px; padding: 14px; margin-top: 10px; }
.onb-found { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.onb-found span { font-size: 12px; background: rgba(60,170,120,.12); color: #1c7a52; border-radius: 20px; padding: 4px 10px; }
.onb-logo { width: 110px; height: 110px; border-radius: 16px; border: 1px dashed var(--line); display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; overflow: hidden; background: #fff; font-size: 30px; }
.onb-logo img { width: 100%; height: 100%; object-fit: contain; }
.onb-pals { display: grid; gap: 10px; }
.onb-pal { display: flex; align-items: center; gap: 10px; border: 1.5px solid var(--line); border-radius: 14px; padding: 10px 12px; cursor: pointer; background: var(--surface-2, #f7f7fb); font: inherit; color: inherit; text-align: start; }
.onb-pal.on { border-color: var(--primary, #4a3db8); background: rgba(124,109,242,.12); }
.onb-pal .sw { display: flex; }
.onb-pal .sw i { width: 30px; height: 30px; display: block; border: 1px solid rgba(0,0,0,.06); }
.onb-pal .sw i:first-child { border-start-start-radius: 8px; border-end-start-radius: 8px; }
.onb-pal .sw i:last-child { border-start-end-radius: 8px; border-end-end-radius: 8px; }
.onb-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.onb-cardopt { border: 1.5px solid var(--line); border-radius: 14px; padding: 12px; cursor: pointer; background: var(--surface-2, #f7f7fb); font: inherit; color: inherit; text-align: start; line-height: 1.6; }
.onb-cardopt b { display: block; font-size: 14px; margin-bottom: 4px; }
.onb-cardopt span { font-size: 12.5px; color: var(--mute); }
.onb-cardopt.on { border-color: var(--primary, #4a3db8); background: rgba(124,109,242,.12); }
.onb-cardopt .em { font-size: 22px; display: block; margin-bottom: 4px; }
.onb-per { border: 1.5px solid var(--line); border-radius: 14px; padding: 12px; margin-bottom: 10px; }
.onb-per.no { opacity: .45; }
.onb-per h4 { margin: 0 0 4px; font-size: 15px; }
.onb-per p { margin: 0; font-size: 13px; color: var(--mute); line-height: 1.7; }
.onb-per .acts { display: flex; gap: 6px; margin-top: 8px; }
.onb-per .acts button { flex: 1; }
.onb-people { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.onb-people span { font-size: 13px; background: var(--surface-2, #f7f7fb); border: 1px solid var(--line); border-radius: 20px; padding: 5px 12px; }
.onb-offer { text-align: center; }
.onb-offer .big { display: flex; justify-content: center; gap: 12px; margin: 14px 0; }
.onb-offer .big div { background: rgba(124,109,242,.1); border-radius: 14px; padding: 12px 16px; min-width: 96px; }
.onb-offer .big b { display: block; font-size: 26px; }
.onb-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 420px) { .onb-cards, .onb-grid2 { grid-template-columns: 1fr; } }
.onb-pick { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 8px; margin: 4px 0 12px; }
.onb-pick button { position: relative; aspect-ratio: 1; border: 2px solid var(--line); border-radius: 12px; padding: 0; overflow: hidden; background: #fff; cursor: pointer; }
.onb-pick button img { width: 100%; height: 100%; object-fit: cover; display: block; }
.onb-pick.logos button img { object-fit: contain; padding: 6px; }
.onb-pick button.on { border-color: var(--primary, #4a3db8); box-shadow: 0 0 0 3px rgba(124,109,242,.25); }
.onb-pick button.on::after { content: '✓'; position: absolute; top: 4px; inset-inline-end: 4px; width: 22px; height: 22px; border-radius: 50%; background: var(--primary, #4a3db8); color: #fff; font-size: 13px; line-height: 22px; text-align: center; }
.onb-meta { display: flex; gap: 10px; align-items: center; border: 1px dashed var(--line); border-radius: 12px; padding: 10px 12px; margin: 12px 0 4px; font-size: 13px; line-height: 1.6; }
.onb-meta .btn { flex: none; }
[hidden] { display: none !important; }
</style>
<div class="onb-wrap">
<div class="onb" id="onb" data-boot="<?= e(json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <div class="onb-top">
        <?= function_exists('site_logo_html') ? site_logo_html() : '<b>' . e(APP_NAME) . '</b>' ?>
        <?php if (!$verifyOnly): ?><div class="onb-bar" aria-hidden="true"><i id="bar"></i></div><?php endif; ?>
    </div>

<?php if ($verifyOnly): ?>
    <div class="onb-card" id="verify-card">
        <h2><?= e(__('فعّل إيميلك عشان تكمّل')) ?></h2>
        <?php if ($verified): ?>
            <p class="sub"><?= e(__('إيميلك متفعّل ✓')) ?></p>
            <a class="btn full lg" href="<?= url('dashboard.php') ?>"><?= e(__('روح للوحة التحكم ←')) ?></a>
        <?php else: ?>
            <p class="sub"><?= e(__('الخطوة دي (النشر · الشراء · ربط الصفحات) محتاجة إيميل مؤكد. بعتنالك رابط التفعيل على')) ?> <b dir="ltr"><?= e($user['email']) ?></b> <?= e(__('— فتّش في الإسبام كمان.')) ?></p>
            <a class="btn full lg" href="<?= url('resend-verification.php') ?>"><?= e(__('ابعتلي رابط التفعيل تاني')) ?></a>
            <div class="onb-row">
                <a class="btn ghost" href="<?= url(ij_done($uid) ? 'dashboard.php' : 'start.php') ?>"><?= e(__('رجوع')) ?></a>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div id="msg" class="onb-msg err" hidden role="alert"></div>

    <!-- 1) اللينك -->
    <div class="onb-card" id="s-link" data-step>
        <h2><?= e(__('أهلًا')) ?> <?= e(explode(' ', trim((string) $user['name']))[0]) ?> 👋</h2>
        <p class="sub"><?= e(__('حط لينك صفحتك أو موقعك — هنقراه ونبنيلك هوية براندك خطوة بخطوة. كل خطوة اختيار بسيط وتقدر تعدّله.')) ?><?= $gift > 0 ? ' <b>' . e(__('هدية مننا، من غير ما تصرف من رصيدك')) . '</b>' : '' ?></p>
        <div class="field">
            <label for="f-name"><?= e(__('اسم نشاطك')) ?> <span class="req">*</span></label>
            <input id="f-name" class="input" maxlength="150" value="<?= e($bizName) ?>" placeholder="<?= e(__('مثلًا: عيادة د. أحمد للأسنان')) ?>" autocomplete="organization">
        </div>
        <div class="field">
            <label for="f-url"><?= e(__('لينك صفحتك أو موقعك')) ?> <span class="text-mute"><?= e(__('(اختياري)')) ?></span></label>
            <input id="f-url" class="input" dir="ltr" inputmode="url" maxlength="1000" placeholder="facebook.com/yourpage" value="<?= e($basics['website'] ?: $basics['social_facebook']) ?>">
            <div class="field-help"><?= e(__('فيسبوك · إنستجرام · موقع — بنقرا منه ونملا البيانات بدالك.')) ?></div>
        </div>
        <div class="onb-or"><?= e(__('أو')) ?></div>
        <div class="field">
            <label for="f-file"><?= e(__('ارفع منيو أو بروشور أو صورة')) ?> <span class="text-mute"><?= e(__('(اختياري)')) ?></span></label>
            <input id="f-file" type="file" class="input" accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.webp">
        </div>
        <?php if ($boot['social_on'] && $verified): ?>
        <div class="onb-meta" id="meta-early"><span><?= e(__('صفحتك على فيسبوك أو إنستجرام؟ اربطها ونجيبلك اللوجو وتصميماتك منها على طول.')) ?></span>
            <a class="btn ghost sm" href="<?= e(url('social-accounts.php?from=start')) ?>" target="_blank" rel="noopener" data-meta-link><?= e(__('اربط')) ?></a></div>
        <?php endif; ?>
        <button type="button" class="btn full lg" id="b-link"><?= e(__('حلّل وكمّل ←')) ?></button>
        <div style="text-align:center;margin-top:12px"><a class="text-mute" style="font-size:13px" href="<?= url('dashboard.php?onb=skip') ?>"><?= e(__('تخطّي دلوقتي — روح للوحة التحكم')) ?></a></div>
    </div>

    <!-- 2) ده اللي لقيناه -->
    <div class="onb-card" id="s-found" data-step hidden>
        <h2><?= e(__('ده اللي لقيناه 🔍')) ?></h2>
        <p class="sub"><?= e(__('راجع وعدّل لو فيه حاجة مش مظبوطة.')) ?></p>
        <div class="onb-found" id="found-chips"></div>
        <div class="field"><label for="g-name"><?= e(__('اسم نشاطك')) ?></label><input id="g-name" class="input" maxlength="150"></div>
        <div class="field"><label for="g-sector"><?= e(__('مجال نشاطك')) ?></label><select id="g-sector" class="select"><option value=""><?= e(__('اختار مجالك')) ?></option></select></div>
        <div class="field"><label for="g-desc"><?= e(__('نشاطك في سطرين')) ?></label><textarea id="g-desc" class="textarea" rows="3" maxlength="1500" placeholder="<?= e(__('بتقدّم إيه ولمين')) ?>"></textarea></div>
        <div class="onb-row"><button type="button" class="btn" data-next="found"><?= e(__('صح، كمّل ✓')) ?></button></div>
    </div>

    <!-- 3) أسئلة الأدمن -->
    <div class="onb-card" id="s-qs" data-step hidden>
        <div class="onb-step" id="q-count"></div>
        <h2 id="q-title"></h2>
        <p class="sub" id="q-sub"></p>
        <div class="onb-opts" id="q-opts"></div>
        <input id="q-text" class="input" maxlength="300" hidden>
        <div class="onb-row">
            <button type="button" class="btn ghost" id="q-skip"><?= e(__('تخطّي')) ?></button>
            <button type="button" class="btn" id="q-next"><?= e(__('التالي ←')) ?></button>
        </div>
    </div>

    <!-- 4) اللوجو -->
    <div class="onb-card" id="s-logo" data-step hidden>
        <h2><?= e(__('لوجو نشاطك')) ?></h2>
        <p class="sub"><?= e(__('بنحطه على تصميماتك وبناخد منه الألوان.')) ?></p>
        <div id="pa-logos-wrap" hidden>
            <p class="sub" style="margin-bottom:6px"><b><?= e(__('لقينا دول في صفحتك — ده اللوجو بتاعك؟')) ?></b></p>
            <div class="onb-pick logos" id="pa-logos"></div>
            <div class="onb-or"><?= e(__('أو ارفعه من جهازك')) ?></div>
        </div>
        <div class="onb-logo" id="logo-box">🏷️</div>
        <input id="logo-file" type="file" class="input" accept=".jpg,.jpeg,.png,.webp">
        <div class="onb-row">
            <button type="button" class="btn ghost" data-next="logo-skip"><?= e(__('معنديش لوجو دلوقتي')) ?></button>
            <button type="button" class="btn" data-next="logo"><?= e(__('كمّل ←')) ?></button>
        </div>
    </div>

    <!-- 5) الألوان -->
    <div class="onb-card" id="s-colors" data-step hidden>
        <h2><?= e(__('ألوان براندك 🎨')) ?></h2>
        <p class="sub"><?= e(__('اخترنالك كام اختيار من لوجوك ومجالك — اختار الأقرب ليك.')) ?></p>
        <div class="onb-pals" id="pals"></div>
        <div class="onb-row"><button type="button" class="btn" data-next="colors"><?= e(__('كمّل ←')) ?></button></div>
    </div>

    <!-- 6) الخدمات -->
    <div class="onb-card" id="s-services" data-step hidden>
        <h2><?= e(__('خدماتك أو منتجاتك')) ?></h2>
        <p class="sub"><?= e(__('خمّناها من مجالك وصفحتك — شيل اللي مش عندك وزوّد اللي ناقص.')) ?></p>
        <div class="onb-opts" id="svc"></div>
        <div style="display:flex;gap:8px"><input id="svc-new" class="input" maxlength="150" placeholder="<?= e(__('خدمة تانية…')) ?>"><button type="button" class="btn ghost" id="svc-add">+</button></div>
        <div class="onb-row"><button type="button" class="btn" data-next="services"><?= e(__('كمّل ←')) ?></button></div>
    </div>

    <!-- 7) العملاء المثاليين -->
    <div class="onb-card" id="s-personas" data-step hidden>
        <h2><?= e(__('مين عملاءك؟ 👥')) ?></h2>
        <p class="sub"><?= e(__('دول الناس اللي هنكتبلهم — وافق على اللي يشبه عملاءك وارفض الباقي.')) ?></p>
        <div id="pers"></div>
        <div class="onb-row"><button type="button" class="btn" data-next="personas"><?= e(__('كمّل ←')) ?></button></div>
    </div>

    <!-- 8) طريقة الكلام -->
    <div class="onb-card" id="s-voice" data-step hidden>
        <h2><?= e(__('بتكلم عملاءك إزاي؟')) ?></h2>
        <p class="sub"><?= e(__('اختار الجملة الأقرب لأسلوبك.')) ?></p>
        <div class="onb-opts" id="voices"></div>
        <div class="onb-row"><button type="button" class="btn" data-next="voice"><?= e(__('كمّل ←')) ?></button></div>
    </div>

    <!-- 9) استايل التصميم -->
    <div class="onb-card" id="s-style" data-step hidden>
        <h2><?= e(__('شكل تصميماتك')) ?></h2>
        <p class="sub"><?= e(__('اختار الاستايل اللي يعجبك — أو ارفع تصميم عاجبك وإحنا نفهم ذوقك.')) ?></p>
        <div class="onb-cards" id="styles"></div>
        <div id="pa-imgs-wrap" hidden>
            <p class="sub" style="margin:14px 0 6px"><b><?= e(__('دي تصميمات وصور من صفحتك — اختار اللي عاجبك (هنصمم على ذوقها)')) ?></b></p>
            <div class="onb-pick" id="pa-imgs"></div>
        </div>
        <?php if ($boot['social_on'] && $verified): ?>
        <div class="onb-meta" id="meta-style" hidden><span><?= e(__('اربط صفحتك ونجيبلك تصميماتك منها تختار اللي عاجبك.')) ?></span>
            <a class="btn ghost sm" href="<?= e(url('social-accounts.php?from=start')) ?>" target="_blank" rel="noopener" data-meta-link><?= e(__('اربط')) ?></a></div>
        <?php endif; ?>
        <div class="onb-or"><?= e(__('تصميم عاجبك؟ (اختياري)')) ?></div>
        <input id="insp-file" type="file" class="input" accept=".jpg,.jpeg,.png,.webp">
        <input id="insp-url" class="input" dir="ltr" inputmode="url" maxlength="1000" placeholder="<?= e(__('أو حط لينك تصميم أو صفحة')) ?>" style="margin-top:8px">
        <div class="onb-row"><button type="button" class="btn" data-next="style"><?= e(__('كمّل ←')) ?></button></div>
    </div>

    <!-- 10) التواصل -->
    <div class="onb-card" id="s-contact" data-step hidden>
        <h2><?= e(__('العملاء يوصلولك إزاي؟ 📍')) ?></h2>
        <p class="sub"><?= e(__('بنحطها في آخر المنشورات والتصميمات.')) ?></p>
        <div class="field"><label for="c-address"><?= e(__('العنوان')) ?></label><input id="c-address" class="input" maxlength="300"></div>
        <div class="onb-grid2">
            <div class="field"><label for="c-phones"><?= e(__('رقم التليفون')) ?></label><input id="c-phones" class="input" dir="ltr" inputmode="tel" maxlength="100"></div>
            <div class="field"><label for="c-whatsapp"><?= e(__('واتساب')) ?></label><input id="c-whatsapp" class="input" dir="ltr" inputmode="tel" maxlength="100"></div>
        </div>
        <div class="field"><label for="c-working_hours"><?= e(__('المواعيد')) ?></label><input id="c-working_hours" class="input" maxlength="300" placeholder="<?= e(__('مثلًا: كل يوم من 10 الصبح لـ 10 بالليل ما عدا الجمعة')) ?>"></div>
        <div class="onb-row">
            <button type="button" class="btn ghost" data-next="contact-skip"><?= e(__('تخطّي')) ?></button>
            <button type="button" class="btn" data-next="contact"><?= e(__('كمّل ←')) ?></button>
        </div>
    </div>

    <!-- 11) صور الأشخاص -->
    <div class="onb-card" id="s-people" data-step hidden>
        <h2><?= e(__('حد هيظهر في تصميماتك؟ 📸')) ?></h2>
        <p class="sub"><?= e(__('ارفع صورة ليك أو لحد من فريقك (دكتور · شيف · مدرب…) علشان نستخدمها في التصميمات. اختياري.')) ?></p>
        <div class="onb-people" id="people-list"></div>
        <div class="onb-grid2">
            <div class="field"><label for="p-name"><?= e(__('الاسم')) ?></label><input id="p-name" class="input" maxlength="100"></div>
            <div class="field"><label for="p-role"><?= e(__('دوره')) ?></label><input id="p-role" class="input" maxlength="150" placeholder="<?= e(__('مثلًا: صاحب المكان')) ?>"></div>
        </div>
        <input id="p-file" type="file" class="input" accept=".jpg,.jpeg,.png,.webp">
        <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;margin-top:10px"><input type="checkbox" id="p-consent" style="margin-top:3px"> <span><?= e(__('أوافق — وعندي موافقة الشخص ده — على استخدام صوره في المحتوى')) ?></span></label>
        <div class="onb-row">
            <button type="button" class="btn ghost" id="p-add"><?= e(__('+ ضيف الشخص ده')) ?></button>
            <button type="button" class="btn" data-next="people"><?= e(__('كمّل ←')) ?></button>
        </div>
    </div>

    <!-- 12) لينكات تانية -->
    <div class="onb-card" id="s-links" data-step hidden>
        <h2><?= e(__('لينكاتك التانية 🔗')) ?></h2>
        <p class="sub"><?= e(__('اختياري — بنحطها في المنشورات لما تحتاجها.')) ?></p>
        <div class="field"><label for="l-website"><?= e(__('الموقع')) ?></label><input id="l-website" class="input" dir="ltr" inputmode="url" maxlength="300"></div>
        <div class="field"><label for="l-social_facebook">Facebook</label><input id="l-social_facebook" class="input" dir="ltr" inputmode="url" maxlength="300"></div>
        <div class="field"><label for="l-social_instagram">Instagram</label><input id="l-social_instagram" class="input" dir="ltr" inputmode="url" maxlength="300"></div>
        <div class="field"><label for="l-social_tiktok">TikTok</label><input id="l-social_tiktok" class="input" dir="ltr" inputmode="url" maxlength="300"></div>
        <div class="onb-row"><button type="button" class="btn" data-next="links"><?= e(__('كمّل ←')) ?></button></div>
    </div>

    <!-- 13) الملخص -->
    <div class="onb-card" id="s-summary" data-step hidden>
        <h2><?= e(__('هوية براندك جاهزة ✓')) ?></h2>
        <p class="sub"><?= e(__('ده اللي فهمناه عن نشاطك — اعتمده وتقدر تعدّل أي حاجة بعدين من «الهوية».')) ?></p>
        <dl class="onb-id" id="id-card"></dl>
        <div class="onb-row">
            <a class="btn ghost" href="<?= url('brand-kit.php') ?>"><?= e(__('عدّل الهوية')) ?></a>
            <button type="button" class="btn" data-next="summary"><?= e(__('اعتمد الهوية ✓')) ?></button>
        </div>
    </div>

    <!-- 14) ربط ميتا -->
    <div class="onb-card" id="s-meta" data-step hidden>
        <h2><?= e(__('اربط صفحتك على فيسبوك وإنستجرام')) ?></h2>
        <p class="sub"><?= e(__('علشان ننشر منشوراتك في مواعيدها من غير ما تفتح الصفحة.')) ?></p>
        <?php if (!$verified): ?><div class="onb-msg info"><?= e(__('الربط محتاج إيميل مؤكد — فعّل إيميلك الأول من الرابط اللي بعتناه.')) ?></div><?php endif; ?>
        <div class="onb-row">
            <button type="button" class="btn ghost" data-next="meta"><?= e(__('بعدين')) ?></button>
            <a class="btn" id="meta-go" href="<?= e(url('social-accounts.php?from=start')) ?>" target="_blank" rel="noopener"><?= e(__('اربط الصفحة')) ?></a>
        </div>
    </div>

    <!-- 15) عرض البداية -->
    <div class="onb-card onb-offer" id="s-offer" data-step hidden>
        <h2 id="offer-title"></h2>
        <p class="sub" id="offer-sub"></p>
        <div class="big" id="offer-big"></div>
        <p class="sub" id="offer-note"></p>
        <div class="onb-row">
            <a class="btn ghost" id="offer-pk" href="<?= e(url('packages.php')) ?>" hidden><?= e(__('شوف الباقات')) ?></a>
            <button type="button" class="btn" data-next="offer"><?= e(__('اكتب أول منشور ←')) ?></button>
        </div>
    </div>

    <!-- تحميل -->
    <div class="onb-card" id="s-load" data-step hidden>
        <div class="onb-load"><div class="onb-spin"></div><b id="load-t"><?= e(__('لحظة…')) ?></b><p class="sub" style="margin-top:8px"><?= e(__('بياخد أقل من دقيقة — ماتقفلش الصفحة.')) ?></p></div>
    </div>

    <!-- 16) أول منشور -->
    <div id="s-post" data-step hidden>
        <div class="onb-card" id="post-card" hidden>
            <h2><?= e(__('أول بوست ليك 🎉')) ?></h2>
            <div class="onb-post" id="post-text"></div>
            <?php if (!$verified): ?>
                <div class="onb-msg info" style="margin-top:12px"><?= e(__('عشان تنشر أو تجدول البوست: فعّل إيميلك من الرابط اللي بعتناه على')) ?> <b dir="ltr"><?= e($user['email']) ?></b>.</div>
            <?php endif; ?>
            <div class="onb-row">
                <a class="btn ghost" id="post-open" href="#"><?= e(__('افتح البوست')) ?></a>
                <a class="btn" href="<?= url('dashboard.php') ?>"><?= e(__('لوحة التحكم ←')) ?></a>
            </div>
        </div>
        <div class="onb-card" id="post-fail" hidden>
            <div class="onb-msg err" id="post-fail-t"></div>
            <div class="onb-row">
                <button type="button" class="btn ghost" id="post-retry"><?= e(__('جرّب تاني')) ?></button>
                <a class="btn" href="<?= url('dashboard.php') ?>"><?= e(__('لوحة التحكم ←')) ?></a>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
</div>
<?php if (!$verifyOnly): ?>
<?= i18n_js(['حصلت مشكلة مؤقتة — جرّب تاني', 'النت فصل — اتأكد من الاتصال وجرّب تاني', 'بنقرا صفحتك وبياناتك…', 'لحظة…', 'التالي ←', 'كمّل ←',
    'مش لاقي مجالك؟ اكتبه هنا', 'معرفناش نبني الهوية — جرّب تاني', 'النشاط', 'اللي بيميّزك', 'جمهورك', 'شخصية البراند', 'مثال على أسلوبك', 'خدماتك', 'الألوان',
    'معرفناش نكتب البوست دلوقتي', 'بنكتب أول بوست ليك…', 'سؤال {n} من {total}', 'اكتب اسم نشاطك الأول', 'بنفهم نشاطك ونبني هويتك…', 'بنجهّز العملاء المثاليين…',
    'قرينا: {x}', 'ملينا: {x}', 'ألوانك الحالية', 'من لوجوك وصورك', 'مقترح لمجالك', 'اقتراح {n}', 'اختار ألوان', 'موجودة', 'ده عميلي ✓', 'مش عميلي ✗',
    'اللي اقترحناه ليك', 'رسمي ومحترم', 'ودود وبسيط', 'مرح وخفيف', '«نسعد بخدمتكم ونلتزم بأعلى معايير الجودة.»', '«تعالى نساعدك تختار الأنسب ليك، من غير تعقيد.»',
    '«جاهز تغيّر الروتين؟ يلا بينا! 😄»', 'بسيط ونضيف', 'مساحات بيضا وعناصر قليلة', 'فخم وهادي', 'ألوان غامقة وتفاصيل راقية', 'مرح وملوّن', 'ألوان مبهجة وأشكال دايرية',
    'موثوق ومهني', 'صور حقيقية وأيقونات خط رفيع', '3D حديث', 'مجسمات وإضاءة ناعمة', 'دافي وطبيعي', 'صور حقيقية بإضاءة دافية',
    'اكتب الاسم واختار صورة', 'لازم توافق على استخدام الصور', 'التواصل', 'المواعيد', 'العملاء المثاليين', 'الاستايل', 'الأشخاص في التصميمات',
    'عرض البداية 🎁', 'هدية لحسابك الجديد — جرّب المنصة كاملة', 'محتوى', 'تصميم', 'يوم', 'تصميمات التجربة عليها علامة مائية بتتشال تلقائي لما تشترك. ولما التجربة تخلص تقدر تدخل وتشوف محتواك عادي.',
    'هويتك اتحفظت ✓', 'كمّل بباقتك الحالية — أو شوف الباقات.', 'عندك رصيد تبدأ بيه.', 'اشترك علشان تكمّل إنتاج محتوى وتصميمات.', 'اختار استايل', 'اختار جملة',
    'بنحفظ…', 'التصميم اللي عجبك', 'جرّب تاني', 'كفاية 6 تصميمات']) ?>
<script>
(function () {
    var T = window.__t || function (s) { return s; };
    var root = document.getElementById('onb');
    var B = JSON.parse(root.dataset.boot || '{}');
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var $ = function (id) { return document.getElementById(id); };
    var state = null, draft = null, conf = {}, answers = {}, sectorId = 0, readInfo = { read: [], filled: [] };
    var ORDER = ['s-link', 's-found', 's-qs', 's-logo', 's-colors', 's-services', 's-personas', 's-voice', 's-style', 's-contact', 's-people', 's-links', 's-summary', 's-meta', 's-offer', 's-post'];

    function show(id) {
        document.querySelectorAll('[data-step]').forEach(function (x) { x.hidden = x.id !== id; });
        var i = ORDER.indexOf(id);
        if (i >= 0) $('bar').style.width = Math.round((i + 1) / ORDER.length * 100) + '%';
        msg('');
        window.scrollTo(0, 0);
    }
    function msg(t, kind) { var m = $('msg'); m.textContent = t || ''; m.className = 'onb-msg ' + (kind || 'err'); m.hidden = !t; }
    function loading(t) { $('load-t').textContent = t; show('s-load'); }
    function el(tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text != null) x.textContent = text; return x; }

    function call(url, body, isForm) {
        var opt = { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } };
        if (isForm) { body.append('csrf', csrf); opt.body = body; } else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body || {}); }
        return fetch(url, opt).then(function (r) {
            return r.text().then(function (t) {
                var j; try { j = JSON.parse(t); } catch (e) { j = { ok: false, error: T('حصلت مشكلة مؤقتة — جرّب تاني') }; }
                return j;
            });
        }).catch(function () { return { ok: false, error: T('النت فصل — اتأكد من الاتصال وجرّب تاني') }; });
    }
    function api(action, body, isForm) { return call(B.api + '?action=' + action, body, isForm); }
    function bapi(action, body, isForm) { return call(B.bapi + '?action=' + action, body, isForm); }
    function self(act, data) {
        var fd = new FormData(); fd.append('act', act);
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        return call(B.self, fd, true);
    }
    function saveFields(map) {
        var fd = new FormData(); fd.append('act', 'fields');
        Object.keys(map).forEach(function (k) { fd.append('f[' + k + ']', map[k]); B.basics[k] = map[k]; });
        return call(B.self, fd, true);
    }
    function upState(r) { if (r && r.ok && r.state) state = r.state; return r; }
    function kit() { return (state && state.kit) || {}; }

    /* ───── اللوجو والتصميمات من صفحة العميل (الموقع · صورة صفحة فيسبوك · الصفحات المربوطة بميتا) ───── */
    var PA = { logos: [], images: [], connected: false }, paUrl = '', paBusy = null, metaOpened = false;
    function loadPA() {
        paBusy = api('page_assets', { url: paUrl }).then(function (r) {
            if (r && r.ok) PA = { logos: r.logos || [], images: r.images || [], connected: !!r.connected };
            paBusy = null;
            if (!$('s-logo').hidden) drawLogoPicks();
            if (!$('s-style').hidden) drawImgPicks();
            return PA;
        });
        return paBusy;
    }
    // رجع من صفحة الربط (تاب تاني) ← نجيب اللوجو والتصميمات من الصفحة المربوطة
    document.querySelectorAll('[data-meta-link]').forEach(function (a) { a.addEventListener('click', function () { metaOpened = true; }); });
    window.addEventListener('focus', function () { if (metaOpened && !paBusy) { metaOpened = false; loadPA(); } });
    function picks(box, list, multi, sel) {
        box.textContent = '';
        list.forEach(function (u) {
            var b = el('button'); b.type = 'button'; b.dataset.u = u; if (sel.indexOf(u) > -1) b.classList.add('on');
            var i = el('img'); i.src = u; i.alt = ''; i.loading = 'lazy'; i.referrerPolicy = 'no-referrer';
            i.onerror = function () { b.remove(); };   // صورة مابتفتحش برا الصفحة ← نشيلها
            b.appendChild(i); box.appendChild(b);
        });
    }
    var logoPick = '', logoAuto = false, imgPicks = [];
    function drawLogoPicks() {
        $('pa-logos-wrap').hidden = !PA.logos.length;
        if (PA.logos.length && !logoAuto && !logoPick && !B.logo) { logoPick = PA.logos[0]; logoAuto = true; }   // أول اقتراح متعلّم مرة واحدة (يقدر يشيله)
        picks($('pa-logos'), PA.logos, false, logoPick ? [logoPick] : []);
        if (logoPick) { $('logo-box').textContent = ''; var i = el('img'); i.src = logoPick; i.referrerPolicy = 'no-referrer'; $('logo-box').appendChild(i); }
    }
    $('pa-logos').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        logoPick = b.classList.contains('on') ? '' : b.dataset.u;
        $('logo-file').value = '';
        drawLogoPicks();
        if (!logoPick) { $('logo-box').textContent = '🏷️'; if (B.logo) renderLogo(); }
    });
    function drawImgPicks() {
        $('pa-imgs-wrap').hidden = !PA.images.length;
        var ms = $('meta-style'); if (ms) ms.hidden = PA.connected || PA.images.length > 3;
        picks($('pa-imgs'), PA.images, true, imgPicks);
    }
    $('pa-imgs').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        var u = b.dataset.u, k = imgPicks.indexOf(u);
        if (k > -1) imgPicks.splice(k, 1); else if (imgPicks.length < 6) imgPicks.push(u); else return msg(T('كفاية 6 تصميمات'), 'info');
        b.classList.toggle('on', k < 0);
    });

    /* ───── 1) اللينك ───── */
    $('b-link').addEventListener('click', function () {
        var name = $('f-name').value.trim(), url = $('f-url').value.trim(), file = $('f-file').files[0];
        if (name.length < 2) { msg(T('اكتب اسم نشاطك الأول')); $('f-name').focus(); return; }
        paUrl = url; loadPA();   // في الخلفية — بيجهز على ما العميل يراجع «ده اللي لقيناه»
        loading(url || file ? T('بنقرا صفحتك وبياناتك…') : T('لحظة…'));
        self('name', { name: name }).then(function (r) {
            if (!r.ok) { show('s-link'); msg(r.error); return null; }
            B.basics.business_name = name;
            var f2 = new FormData(); f2.append('build', '0');
            if (url) f2.append('url', url); else if (file) f2.append('file', file);
            return api('autofill', f2, true);
        }).then(function (r) {
            if (r === null) return;
            if (r && r.ok) { state = r.state; readInfo = { read: r.read || [], filled: r.filled || [] }; if (state.basics) Object.keys(state.basics).forEach(function (k) { if (k in B.basics && state.basics[k]) B.basics[k] = state.basics[k]; }); }
            else if (!state) api('get', {}).then(upState);
            renderFound(r && !r.ok ? r.error : (r && r.warn) || '');
        });
    });

    /* ───── 2) ده اللي لقيناه ───── */
    function renderFound(warn) {
        show('s-found');
        if (warn) msg(warn, 'info');
        var chips = $('found-chips'); chips.textContent = '';
        var list = [].concat(readInfo.read || []).map(function (x) { return typeof x === 'string' ? x : (x.title || x.label || ''); }).filter(Boolean);
        if (list.length) chips.appendChild(el('span', '', T('قرينا: {x}', { x: list.slice(0, 3).join('، ') })));
        if ((readInfo.filled || []).length) chips.appendChild(el('span', '', T('ملينا: {x}', { x: readInfo.filled.slice(0, 5).join('، ') })));
        $('g-name').value = B.basics.business_name || $('f-name').value.trim();
        $('g-desc').value = B.basics.description || '';
        var sel = $('g-sector');
        if (sel.options.length < 2) B.sectors.forEach(function (s) { var o = el('option', '', s.name); o.value = s.id; sel.appendChild(o); });
        sel.value = state && state.sector ? String(state.sector.id) : '';
    }
    var STEPS = {};
    STEPS.found = function () {
        var name = $('g-name').value.trim();
        if (name.length < 2) { msg(T('اكتب اسم نشاطك الأول')); return; }
        sectorId = parseInt($('g-sector').value || '0', 10) || 0;
        var nm = sectorId ? $('g-sector').options[$('g-sector').selectedIndex].text : '';
        if (nm) answers['نشاطك إيه'] = nm;
        loading(T('بنحفظ…'));
        saveFields({ business_name: name, description: $('g-desc').value.trim() }).then(function (r) {
            if (!r.ok) { show('s-found'); msg(r.error); return; }
            return (sectorId ? api('sector_set', { sector_id: sectorId }) : Promise.resolve({ ok: true })).then(function () { startQuestions(); });
        });
    };

    /* ───── 3) أسئلة الأدمن ───── */
    var Q = B.questions || [], qi = 0, picked = [];
    function startQuestions() { qi = 0; if (!Q.length) { build(); return; } show('s-qs'); renderQ(); }
    function optBtn(label, value, block) { var b = el('button', 'onb-opt' + (block ? ' block' : ''), label); b.type = 'button'; b.dataset.v = value; return b; }
    function renderQ() {
        var q = Q[qi], box = $('q-opts'), txt = $('q-text');
        $('q-count').textContent = T('سؤال {n} من {total}', { n: qi + 1, total: Q.length });
        $('q-title').textContent = q.title; $('q-sub').textContent = q.sub || '';
        box.textContent = ''; picked = []; txt.hidden = true; txt.value = '';
        $('q-next').textContent = T('التالي ←');
        if (q.kind === 'text') { txt.hidden = false; txt.placeholder = q.ph || ''; txt.focus(); return; }
        (q.opts || []).forEach(function (o) { box.appendChild(optBtn(o, o, q.block)); });
    }
    $('q-opts').addEventListener('click', function (e) {
        var b = e.target.closest('.onb-opt'); if (!b) return;
        if (Q[qi].multi) { b.classList.toggle('on'); }
        else { $('q-opts').querySelectorAll('.on').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); }
        picked = Array.prototype.map.call($('q-opts').querySelectorAll('.on'), function (x) { return x.dataset.v; });
    });
    function nextQ(skip) {
        var q = Q[qi], t = $('q-text').value.trim();
        if (!skip) { if (q.kind === 'text') { if (t) answers[q.key] = t; } else if (picked.length) answers[q.key] = picked.join('، '); }
        qi++;
        if (qi < Q.length) { renderQ(); return; }
        build();
    }
    $('q-next').addEventListener('click', function () { nextQ(false); });
    $('q-skip').addEventListener('click', function () { nextQ(true); });
    $('q-text').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); nextQ(false); } });

    /* ───── المسودة: الـ AI يخمّن الباقي (بيتحفظ كله ما عدا الخدمات والألوان — ليهم شاشات اختيار) ───── */
    function build() {
        loading(T('بنفهم نشاطك ونبني هويتك…'));
        api('draft', { answers: answers }).then(function (r) {
            if (!r.ok) { msg(r.error || T('معرفناش نبني الهوية — جرّب تاني')); return api('get', {}).then(upState); }
            draft = r.draft || {}; conf = r.confidence || {};
            // الاسم اللي العميل كتبه بنفسه هو الصح — الـ AI مايغيّروش
            if (draft.business && B.basics.business_name) draft.business.name = B.basics.business_name;
            var secs = ['business', 'audience', 'personality', 'voice', 'pillars', 'content', 'visuals', 'topics'].filter(function (s) { return s in draft; });
            var unsure = Object.keys(conf).filter(function (k) { return conf[k] < 0.7 && secs.indexOf(k) >= 0; });
            return api('apply_draft', { draft: draft, sections: secs, unsure: unsure }).then(upState);
        }).then(function () { renderLogo(); });
    }

    /* ───── 4) اللوجو ───── */
    function renderLogo() {
        show('s-logo');
        var box = $('logo-box');
        if (B.logo) { box.textContent = ''; var i = el('img'); i.src = B.logo; i.alt = ''; box.appendChild(i); }
        drawLogoPicks();
    }
    $('logo-file').addEventListener('change', function () {
        var f = this.files[0]; if (!f) return;
        logoPick = ''; $('pa-logos').querySelectorAll('.on').forEach(function (x) { x.classList.remove('on'); });
        var box = $('logo-box'); box.textContent = ''; var i = el('img'); i.src = URL.createObjectURL(f); box.appendChild(i);
    });
    STEPS.logo = function () {
        var f = $('logo-file').files[0];
        if (!f && logoPick) {
            // اللوجو من صفحته ← بيتنزل ويتحفظ (ولو مانزلش: نطلب منه يرفعه)
            loading(T('بنحفظ…'));
            api('page_logo_use', { url: logoPick }).then(function (r) {
                if (!r.ok) { renderLogo(); msg(r.error); return; }
                upState(r); B.logo = r.logo_url || logoPick; logoPick = '';
                renderColors();
            });
            return;
        }
        if (!f) { renderColors(); return; }
        loading(T('بنحفظ…'));
        var fd = new FormData(); fd.append('logo', f);
        bapi('upload_logo', fd, true).then(function (r) {
            if (!r.ok) { renderLogo(); msg(r.error); return; }
            B.logo = URL.createObjectURL(f); $('logo-file').value = '';
            renderColors();
        });
    };
    STEPS['logo-skip'] = function () { renderColors(); };

    /* ───── 5) الألوان: الحالية · من اللوجو/الصور · مقترح المجال (من المسودة) · اقتراحات AI ───── */
    var pals = [], palPick = -1;
    function palKey(p) { return [p.primary, p.secondary, p.accent].join('|').toUpperCase(); }
    function addPal(p, label) {
        if (!p || !/^#[0-9a-f]{6}$/i.test(p.primary || '')) return;
        if (pals.some(function (x) { return palKey(x) === palKey(p); })) return;
        pals.push({ primary: p.primary, secondary: p.secondary || '', accent: p.accent || '', neutral: p.neutral || '', label: label });
    }
    function renderColors() {
        loading(T('لحظة…'));
        pals = []; palPick = -1;
        var c = kit().colors || {};
        addPal(c, T('ألوانك الحالية'));
        api('palette_suggest', { ai: '1' }).then(function (r) {
            if (r.ok) (r.palettes || []).forEach(function (p, i) { addPal(p, i === 0 && (r.images || 0) ? T('من لوجوك وصورك') : T('اقتراح {n}', { n: pals.length + 1 })); });
            if (draft && draft.colors) addPal(draft.colors, T('مقترح لمجالك'));
            show('s-colors');
            if (!r.ok && !pals.length) msg(r.error, 'info');
            var box = $('pals'); box.textContent = '';
            pals.slice(0, 4).forEach(function (p, i) {
                var b = el('button', 'onb-pal'); b.type = 'button'; b.dataset.i = i;
                var sw = el('span', 'sw');
                [p.primary, p.secondary, p.accent, p.neutral].filter(function (x) { return /^#[0-9a-f]{6}$/i.test(x || ''); }).forEach(function (x) { var s = el('i'); s.style.background = x; sw.appendChild(s); });
                b.appendChild(sw); b.appendChild(el('span', '', p.label));
                box.appendChild(b);
            });
            if (pals.length) { palPick = 0; box.firstChild.classList.add('on'); }
        });
    }
    $('pals').addEventListener('click', function (e) {
        var b = e.target.closest('.onb-pal'); if (!b) return;
        $('pals').querySelectorAll('.on').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on'); palPick = parseInt(b.dataset.i, 10);
    });
    STEPS.colors = function () {
        if (palPick < 0) { renderServices(); return; }
        var p = pals[palPick], c = kit().colors || {};
        if (palKey(p) === palKey(c)) { renderServices(); return; }
        api('palette_apply', p).then(function (r) { upState(r); if (!r.ok) { msg(r.error); return; } renderServices(); });
    };

    /* ───── 6) الخدمات ───── */
    function svcChip(name, on, existing) {
        var b = optBtn(existing ? name + ' · ' + T('موجودة') : name, name);
        if (on) b.classList.add('on');
        if (existing) { b.disabled = true; b.dataset.existing = '1'; }
        return b;
    }
    function renderServices() {
        show('s-services');
        var box = $('svc'); box.textContent = '';
        var have = ((state && state.services) || []).map(function (s) { return s.name; });
        have.forEach(function (n) { box.appendChild(svcChip(n, true, true)); });
        var low = have.map(function (n) { return n.toLowerCase(); });
        ((draft && draft.services) || []).forEach(function (s) { if (low.indexOf(s.name.toLowerCase()) < 0) box.appendChild(svcChip(s.name, true, false)); });
    }
    $('svc').addEventListener('click', function (e) { var b = e.target.closest('.onb-opt'); if (b && !b.disabled) b.classList.toggle('on'); });
    function addSvc() { var v = $('svc-new').value.trim(); if (v.length < 2) return; $('svc').appendChild(svcChip(v, true, false)); $('svc-new').value = ''; }
    $('svc-add').addEventListener('click', addSvc);
    $('svc-new').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addSvc(); } });
    STEPS.services = function () {
        var picks = Array.prototype.filter.call($('svc').querySelectorAll('.onb-opt.on'), function (b) { return !b.dataset.existing; }).map(function (b) { return b.dataset.v; });
        var desc = {}; ((draft && draft.services) || []).forEach(function (s) { desc[s.name] = s; });
        loading(T('بنحفظ…'));
        picks.reduce(function (p, n) {
            return p.then(function () { var d = desc[n] || {}; return api('service_save', { name: n, description: d.description || '', priority: d.priority || 'medium' }).then(upState); });
        }, Promise.resolve()).then(renderPersonas);
    };

    /* ───── 7) العملاء المثاليين: موجودين ← وإلا نولّدهم ───── */
    var perNo = {};
    function renderPersonas() {
        var list = (state && state.personas) || [];
        if (list.length) { drawPersonas(list); return; }
        loading(T('بنجهّز العملاء المثاليين…'));
        api('personas_generate', { n: 3 }).then(function (r) {
            upState(r);
            drawPersonas((state && state.personas) || []);
            if (!r.ok) msg(r.error, 'info');
        });
    }
    function arr(v) { if (Array.isArray(v)) return v; try { var j = JSON.parse(v || '[]'); return Array.isArray(j) ? j : []; } catch (e) { return v ? [String(v)] : []; } }
    function drawPersonas(list) {
        show('s-personas');
        var box = $('pers'); box.textContent = ''; perNo = {};
        if (!list.length) {
            // التوليد ماطلعش حاجة (رصيد · AI): جرّب تاني أو كمّل — تقدر تضيفهم بعدين من «الهوية»
            var rb = el('button', 'btn ghost full', T('جرّب تاني')); rb.type = 'button';
            rb.addEventListener('click', renderPersonas);
            box.appendChild(rb);
            return;
        }
        list.forEach(function (p) {
            var c = el('div', 'onb-per'); c.dataset.id = p.id;
            c.appendChild(el('h4', '', (p.emoji ? p.emoji + ' ' : '') + p.name));
            c.appendChild(el('p', '', [p.age, p.job, p.location].filter(Boolean).join(' · ')));
            var pains = arr(p.pains).slice(0, 2); if (pains.length) c.appendChild(el('p', '', '😟 ' + pains.join(' · ')));
            var acts = el('div', 'acts');
            var yes = el('button', 'btn sm', T('ده عميلي ✓')), no = el('button', 'btn ghost sm', T('مش عميلي ✗'));
            yes.type = no.type = 'button'; yes.dataset.yes = p.id; no.dataset.no = p.id;
            acts.appendChild(yes); acts.appendChild(no); c.appendChild(acts);
            box.appendChild(c);
        });
    }
    $('pers').addEventListener('click', function (e) {
        var y = e.target.closest('[data-yes]'), n = e.target.closest('[data-no]');
        if (!y && !n) return;
        var id = (y || n).dataset.yes || (y || n).dataset.no, card = (y || n).closest('.onb-per');
        perNo[id] = !!n; card.classList.toggle('no', !!n);
    });
    STEPS.personas = function () {
        var ids = Object.keys(perNo).filter(function (k) { return perNo[k]; });
        ids.reduce(function (p, id) { return p.then(function () { return api('persona_delete', { id: parseInt(id, 10) }).then(upState); }); }, Promise.resolve()).then(renderVoice);
    };

    /* ───── 8) طريقة الكلام: اقتراح المسودة + 3 أساليب ───── */
    var VOICES = [];
    function renderVoice() {
        show('s-voice');
        var v = kit().voice || {}, line = (v.sample_lines || []).filter(Boolean)[0];
        VOICES = [];
        if (line) VOICES.push({ t: '«' + line + '»', l: T('اللي اقترحناه ليك'), v: {} });
        VOICES.push({ t: T('«نسعد بخدمتكم ونلتزم بأعلى معايير الجودة.»'), l: T('رسمي ومحترم'), v: { formal_friendly: 25, serious_fun: 15, scientific_simple: 45, emoji: false } });
        VOICES.push({ t: T('«تعالى نساعدك تختار الأنسب ليك، من غير تعقيد.»'), l: T('ودود وبسيط'), v: { formal_friendly: 80, serious_fun: 45, scientific_simple: 80, emoji: true } });
        VOICES.push({ t: T('«جاهز تغيّر الروتين؟ يلا بينا! 😄»'), l: T('مرح وخفيف'), v: { formal_friendly: 90, serious_fun: 80, scientific_simple: 90, emoji: true } });
        var box = $('voices'); box.textContent = '';
        VOICES.forEach(function (o, i) { var b = optBtn(o.t + ' — ' + o.l, String(i), true); if (i === 0) b.classList.add('on'); box.appendChild(b); });
    }
    $('voices').addEventListener('click', function (e) {
        var b = e.target.closest('.onb-opt'); if (!b) return;
        $('voices').querySelectorAll('.on').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on');
    });
    STEPS.voice = function () {
        var on = $('voices').querySelector('.on'), o = on ? VOICES[parseInt(on.dataset.v, 10)] : null;
        if (!o || !Object.keys(o.v).length) { renderStyle(); return; }
        var cur = Object.assign({}, kit().voice || {}, o.v);
        cur.sample_lines = [o.t.replace(/^«|»$/g, '')].concat((cur.sample_lines || []).filter(Boolean)).slice(0, 5);
        api('save_section', { section: 'voice', value: cur }).then(function (r) { upState(r); renderStyle(); });
    };

    /* ───── 9) استايل التصميم: 6 كروت + تصميم عاجبه (رفع أو لينك) ───── */
    var STYLES = [
        { e: '⚪', n: 'بسيط ونضيف', d: 'مساحات بيضا وعناصر قليلة', notes: 'minimal, clean, lots of white space, one focal element', vis: { kind: 'photo', light: 'bright' } },
        { e: '✨', n: 'فخم وهادي', d: 'ألوان غامقة وتفاصيل راقية', notes: 'premium, elegant, dark tones with subtle gold accents', vis: { kind: 'photo', light: 'dramatic' } },
        { e: '🎈', n: 'مرح وملوّن', d: 'ألوان مبهجة وأشكال دايرية', notes: 'playful, colorful, rounded shapes, youthful', vis: { kind: 'flat', light: 'bright' } },
        { e: '🛡️', n: 'موثوق ومهني', d: 'صور حقيقية وأيقونات خط رفيع', notes: 'trustworthy professional look, thin line icons, real photos', vis: { kind: 'photo', light: 'bright' } },
        { e: '🧊', n: '3D حديث', d: 'مجسمات وإضاءة ناعمة', notes: 'modern 3D renders, soft studio lighting', vis: { kind: '3d', light: 'bright' } },
        { e: '☕', n: 'دافي وطبيعي', d: 'صور حقيقية بإضاءة دافية', notes: 'warm natural photography, cozy feel', vis: { kind: 'photo', light: 'warm' } }
    ];
    var stylePick = -1;
    function renderStyle() {
        show('s-style');
        drawImgPicks();
        var box = $('styles'); box.textContent = '';
        STYLES.forEach(function (s, i) {
            var b = el('button', 'onb-cardopt'); b.type = 'button'; b.dataset.i = i;
            b.appendChild(el('span', 'em', s.e)); b.appendChild(el('b', '', T(s.n))); b.appendChild(el('span', '', T(s.d)));
            box.appendChild(b);
        });
    }
    $('styles').addEventListener('click', function (e) {
        var b = e.target.closest('.onb-cardopt'); if (!b) return;
        $('styles').querySelectorAll('.on').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); stylePick = parseInt(b.dataset.i, 10);
    });
    STEPS.style = function () {
        var f = $('insp-file').files[0], u = $('insp-url').value.trim(), jobs = [];
        if (stylePick >= 0) {
            var s = STYLES[stylePick], k = kit();
            var ds = Object.assign({}, k.design_style || {}, { notes: T(s.n) + ' — ' + s.notes, use_inspirations: true });
            var vis = Object.assign({}, k.visuals || {}, s.vis);
            jobs.push(function () { return api('save_section', { section: 'design_style', value: ds }).then(upState); });
            jobs.push(function () { return api('save_section', { section: 'visuals', value: vis }).then(upState); });
        }
        if (imgPicks.length) jobs.push(function () { return api('page_insp_add', { urls: imgPicks.slice() }).then(function (r) { if (r.ok) imgPicks = []; return r; }); });
        if (f) jobs.push(function () { var fd = new FormData(); fd.append('file', f); fd.append('note', T('التصميم اللي عجبك')); return bapi('insp_upload', fd, true); });
        else if (u) jobs.push(function () { return bapi('insp_link', { url: u, note: T('التصميم اللي عجبك') }); });
        if (!jobs.length) { renderContact(); return; }
        loading(T('بنحفظ…'));
        jobs.reduce(function (p, j) { return p.then(function (r) { if (r && r.ok === false) msg(r.error, 'info'); return j(); }); }, Promise.resolve()).then(function (r) {
            renderContact(); if (r && r.ok === false) msg(r.error, 'info');
        });
    };

    /* ───── 10) التواصل ───── */
    var CONTACT = ['address', 'phones', 'whatsapp', 'working_hours'], LINKS = ['website', 'social_facebook', 'social_instagram', 'social_tiktok'];
    function fill(prefix, keys) { keys.forEach(function (k) { $(prefix + k).value = B.basics[k] || ''; }); }
    function grab(prefix, keys) { var o = {}; keys.forEach(function (k) { o[k] = $(prefix + k).value.trim(); }); return o; }
    function renderContact() { show('s-contact'); fill('c-', CONTACT); }
    STEPS.contact = function () { saveFields(grab('c-', CONTACT)).then(function (r) { if (!r.ok) { msg(r.error); return; } renderPeople(); }); };
    STEPS['contact-skip'] = function () { renderPeople(); };

    /* ───── 11) صور الأشخاص (بموافقة) ───── */
    function renderPeople() {
        show('s-people');
        var box = $('people-list'); box.textContent = '';
        ((state && state.characters) || []).forEach(function (c) { box.appendChild(el('span', '', '👤 ' + c.name)); });
    }
    $('p-add').addEventListener('click', function () {
        var name = $('p-name').value.trim(), f = $('p-file').files[0];
        if (name.length < 2 || !f) { msg(T('اكتب الاسم واختار صورة')); return; }
        if (!$('p-consent').checked) { msg(T('لازم توافق على استخدام الصور')); return; }
        msg(''); var btn = this; btn.disabled = true;
        api('character_save', { name: name, type: 'real_person', role: $('p-role').value.trim(), use_in: ['image'], consent_confirmed: 1, require_consent: 1 }).then(function (r) {
            if (!r.ok) return r;
            var fd = new FormData(); fd.append('image', f); fd.append('id', r.id);
            return api('character_upload', fd, true);
        }).then(function (r) {
            btn.disabled = false;
            if (!r.ok) { msg(r.error); return; }
            upState(r); $('p-name').value = ''; $('p-role').value = ''; $('p-file').value = ''; $('p-consent').checked = false;
            renderPeople();
        });
    });
    STEPS.people = function () { show('s-links'); fill('l-', LINKS); };

    /* ───── 12) اللينكات ───── */
    STEPS.links = function () { saveFields(grab('l-', LINKS)).then(function (r) { if (!r.ok) { msg(r.error); return; } api('get', {}).then(function (g) { upState(g); renderSummary(); }); }); };

    /* ───── 13) الملخص ───── */
    function row(dl, label, value) {
        if (!value) return;
        var dt = el('dt', '', label), dd = el('dd');
        if (value.nodeType) dd.appendChild(value); else dd.textContent = value;
        dl.appendChild(dt); dl.appendChild(dd);
    }
    function renderSummary() {
        show('s-summary');
        var dl = $('id-card'); dl.textContent = '';
        var k = kit(), b = k.business || {}, a = k.audience || {}, v = k.voice || {}, c = k.colors || {};
        row(dl, T('النشاط'), [b.name || B.basics.business_name, b.sector].filter(Boolean).join(' — '));
        row(dl, T('اللي بيميّزك'), b.usp);
        row(dl, T('جمهورك'), a.description);
        row(dl, T('العملاء المثاليين'), ((state && state.personas) || []).map(function (p) { return (p.emoji || '') + ' ' + p.name; }).join(' · '));
        row(dl, T('شخصية البراند'), (k.personality || []).filter(Boolean).join(' · '));
        row(dl, T('مثال على أسلوبك'), (v.sample_lines || []).filter(Boolean)[0]);
        row(dl, T('خدماتك'), ((state && state.services) || []).slice(0, 6).map(function (s) { return s.name; }).join(' · '));
        var cols = [c.primary, c.secondary, c.accent].filter(function (x) { return /^#[0-9a-fA-F]{3,8}$/.test(x || ''); });
        if (cols.length) { var span = el('span'); cols.forEach(function (x) { var i = el('i', 'onb-sw'); i.style.background = x; span.appendChild(i); }); row(dl, T('الألوان'), span); }
        if (stylePick >= 0) row(dl, T('الاستايل'), T(STYLES[stylePick].n));
        row(dl, T('التواصل'), [B.basics.address, B.basics.phones, B.basics.whatsapp].filter(Boolean).join(' · '));
        row(dl, T('المواعيد'), B.basics.working_hours);
        row(dl, T('الأشخاص في التصميمات'), ((state && state.characters) || []).map(function (x) { return x.name; }).join(' · '));
    }
    var finish = null;
    STEPS.summary = function () {
        loading(T('بنحفظ…'));
        self('finish', {}).then(function (r) {
            if (!r.ok) { renderSummary(); msg(r.error); return; }
            finish = r;
            if (B.social_on) show('s-meta'); else renderOffer();
        });
    };

    /* ───── 14) ميتا ← 15) العرض ← 16) أول منشور ───── */
    $('meta-go').addEventListener('click', function () { setTimeout(renderOffer, 400); });
    STEPS.meta = function () { renderOffer(); };
    function renderOffer() {
        show('s-offer');
        var t = (finish && finish.trial) || {}, big = $('offer-big'); big.textContent = '';
        $('offer-pk').hidden = true;
        if (t.state === 'active') {
            $('offer-title').textContent = T('عرض البداية 🎁');
            $('offer-sub').textContent = T('هدية لحسابك الجديد — جرّب المنصة كاملة');
            [[t.contents.total, T('محتوى')], [t.designs.total, T('تصميم')], [t.days_left, T('يوم')]].forEach(function (x) {
                var d = el('div'); d.appendChild(el('b', '', String(x[0]))); d.appendChild(el('span', '', x[1])); big.appendChild(d);
            });
            $('offer-note').textContent = T('تصميمات التجربة عليها علامة مائية بتتشال تلقائي لما تشترك. ولما التجربة تخلص تقدر تدخل وتشوف محتواك عادي.');
        } else {
            $('offer-title').textContent = T('هويتك اتحفظت ✓');
            $('offer-sub').textContent = finish && finish.subscribed ? T('كمّل بباقتك الحالية — أو شوف الباقات.') : ((finish && finish.balance) > 0 ? T('عندك رصيد تبدأ بيه.') : T('اشترك علشان تكمّل إنتاج محتوى وتصميمات.'));
            $('offer-note').textContent = '';
            $('offer-pk').hidden = false;
        }
    }
    STEPS.offer = function () { show('s-post'); firstPost(); };

    function firstPost() {
        $('post-card').hidden = true; $('post-fail').hidden = true;
        loadingInline(true);
        var fd = new FormData();
        fd.append('content_type', 'introductory'); fd.append('platform', 'both');
        fd.append('length', 'medium'); fd.append('dialect', B.dialect || 'egyptian'); fd.append('onboarding', '1');
        var tq = Q.filter(function (q) { return q.topic; })[0], svc = tq ? answers[tq.key] : '';
        if (!svc && state && state.services && state.services[0]) svc = state.services[0].name;
        if (svc) fd.append('extra_notes', 'البوست عن: ' + svc);
        call(B.gen, fd, true).then(function (r) {
            loadingInline(false);
            if (!r.ok) { $('post-fail-t').textContent = r.error || T('معرفناش نكتب البوست دلوقتي'); $('post-fail').hidden = false; return; }
            $('post-text').textContent = [r.content, r.cta, r.hashtags].filter(Boolean).join('\n\n');
            $('post-open').href = B.view + '?id=' + encodeURIComponent(r.content_id);
            $('post-card').hidden = false;
        });
    }
    var inline = null;
    function loadingInline(on) {
        if (on && !inline) {
            inline = el('div', 'onb-card');
            var w = el('div', 'onb-load'); w.appendChild(el('div', 'onb-spin')); w.appendChild(el('b', '', T('بنكتب أول بوست ليك…'))); inline.appendChild(w);
            $('s-post').appendChild(inline);
        } else if (!on && inline) { inline.remove(); inline = null; }
    }
    $('post-retry').addEventListener('click', firstPost);

    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-next]'); if (!b) return;
        var fn = STEPS[b.dataset.next]; if (fn) fn();
    });
    show('s-link');
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
