<?php
/**
 * Spread AI — «ابدأ» (خريطة التطوير · المرحلة 1)
 * من التسجيل لأول بوست في 3 شاشات:
 *   1) لينكاتك (صفحة · موقع · ملف) + اسم النشاط   2) 5 أسئلة اختيار   3) الهوية + أول بوست
 * مفيش AI جديد هنا: القراءة والمسودة من public/api/brand-kit.php (autofill · draft · apply_draft)
 * والبوست من public/ajax/generate-content.php. الصفحة ورا الإعداد onboarding_quick.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/brand-kit.php';
require_once __DIR__ . '/../includes/rate-limit.php';

require_login();
$user = current_user();
$uid = (int) $user['id'];

if (!onb_enabled()) {
    redirect('brand-kit.php');
}

/* حفظ اسم النشاط (register_user بيحط اسم الشخص مكانه — هنا العميل بيكتب الاسم الصح) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!rate_limit('onb_name', 'u' . $uid, 20, 600)) {
        json_response(['ok' => false, 'error' => 'استنى شوية وجرّب تاني'], 429);
    }
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
    if (mb_strlen($name) < 2) {
        json_response(['ok' => false, 'error' => 'اكتب اسم نشاطك']);
    }
    if (!db_one('SELECT id FROM brand_profiles WHERE user_id = ? LIMIT 1', [$uid])) {
        db_run('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, ?)', [$uid, $name]);
    } else {
        db_run('UPDATE brand_profiles SET business_name = ?, updated_at = NOW() WHERE user_id = ?', [$name, $uid]);
    }
    json_response(['ok' => true]);
}

$verifyOnly = isset($_GET['verify']);
$verified = !empty($user['email_verified_at']);
if (!$verifyOnly && onb_done($uid)) {
    redirect('dashboard.php');
}

$gift = $verifyOnly ? 0 : onb_gift_grant($uid);
$brand = user_brand();
$bizName = trim((string) ($brand['business_name'] ?? ''));
if ($bizName === trim((string) $user['name'])) $bizName = '';   // القيمة التلقائية من التسجيل
$sectors = array_map(fn($s) => ['id' => (int) $s['id'], 'name' => (string) $s['name']], bk_sectors());

$page_title = 'ابدأ';
$__no_tabbar = true;
$__no_assistant = true;   // الصفحة خطوة واحدة في كل مرة — من غير زرار المساعد العائم
include __DIR__ . '/../templates/header.php';
?>
<style>
.onb-wrap { min-height: 100vh; display: flex; justify-content: center; padding: 20px 16px 40px; background: var(--bg); }
.onb { width: 100%; max-width: 540px; }
.onb-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.onb-dots { display: flex; gap: 6px; }
.onb-dots i { width: 28px; height: 5px; border-radius: 3px; background: var(--line); display: block; }
.onb-dots i.on { background: var(--primary, #4a3db8); }
.onb-card { background: var(--surface); border-radius: var(--r-xl, 18px); padding: 24px 20px; box-shadow: var(--shadow-lg, 0 8px 30px rgba(0,0,0,.08)); }
.onb-card h2 { margin: 0 0 6px; font-size: 20px; }
.onb-card .sub { color: var(--mute); font-size: 13.5px; margin: 0 0 18px; line-height: 1.7; }
.onb-opts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.onb-opt { border: 1.5px solid var(--line); background: var(--surface-2, #f7f7fb); border-radius: 12px; padding: 11px 14px; font: inherit; font-size: 14px; cursor: pointer; color: inherit; min-height: 44px; text-align: start; }
.onb-opt.on { border-color: var(--primary, #4a3db8); background: rgba(124,109,242,.12); font-weight: 600; }
.onb-opt.block { width: 100%; line-height: 1.7; }
.onb-row { display: flex; gap: 8px; margin-top: 16px; }
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
[hidden] { display: none !important; }
</style>
<div class="onb-wrap">
<div class="onb" id="onb"
     data-self="<?= e(url('start.php')) ?>"
     data-api="<?= e(url('api/brand-kit.php')) ?>"
     data-gen="<?= e(url('ajax/generate-content.php')) ?>"
     data-view="<?= e(url('content-view.php')) ?>"
     data-sectors="<?= e(json_encode($sectors, JSON_UNESCAPED_UNICODE)) ?>">
    <div class="onb-top">
        <?= function_exists('site_logo_html') ? site_logo_html() : '<b>' . e(APP_NAME) . '</b>' ?>
        <?php if (!$verifyOnly): ?><div class="onb-dots" aria-hidden="true"><i class="on"></i><i></i><i></i></div><?php endif; ?>
    </div>

<?php if ($verifyOnly): ?>
    <div class="onb-card" id="verify-card">
        <h2>فعّل إيميلك عشان تكمّل</h2>
        <?php if ($verified): ?>
            <p class="sub">إيميلك متفعّل ✓</p>
            <a class="btn full lg" href="<?= url('dashboard.php') ?>">روح للوحة التحكم ←</a>
        <?php else: ?>
            <p class="sub">الخطوة دي (النشر · الشراء · ربط الصفحات) محتاجة إيميل مؤكد. بعتنالك رابط التفعيل على <b dir="ltr"><?= e($user['email']) ?></b> — فتّش في الإسبام كمان.</p>
            <a class="btn full lg" href="<?= url('resend-verification.php') ?>">ابعتلي رابط التفعيل تاني</a>
            <div class="onb-row">
                <a class="btn ghost" href="<?= url(onb_done($uid) ? 'dashboard.php' : 'start.php') ?>">رجوع</a>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div id="msg" class="onb-msg err" hidden role="alert"></div>

    <!-- 1) لينكاتك -->
    <div class="onb-card" id="s1">
        <h2>أهلًا <?= e(explode(' ', trim((string) $user['name']))[0]) ?> 👋</h2>
        <p class="sub">3 خطوات صغيرة ونكتبلك أول بوست باسم نشاطك<?= $gift > 0 ? ' — <b>هدية مننا، من غير ما تصرف من رصيدك</b>' : '' ?>.</p>
        <div class="field">
            <label for="f-name">اسم نشاطك <span class="req">*</span></label>
            <input id="f-name" class="input" maxlength="150" value="<?= e($bizName) ?>" placeholder="مثلًا: عيادة د. أحمد للأسنان" autocomplete="organization">
        </div>
        <div class="field">
            <label for="f-url">لينك صفحتك أو موقعك <span class="text-mute">(اختياري)</span></label>
            <input id="f-url" class="input" dir="ltr" inputmode="url" maxlength="1000" placeholder="facebook.com/yourpage">
            <div class="field-help">فيسبوك · إنستجرام · موقع — بنقرا منه ونملا البيانات بدالك.</div>
        </div>
        <div class="onb-or">أو</div>
        <div class="field">
            <label for="f-file">ارفع منيو أو بروشور أو صورة <span class="text-mute">(اختياري)</span></label>
            <input id="f-file" type="file" class="input" accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.webp">
        </div>
        <button type="button" class="btn full lg" id="b1">كمّل ←</button>
    </div>

    <!-- 2) 5 أسئلة -->
    <div class="onb-card" id="s2" hidden>
        <div class="text-mute" style="font-size:12px;margin-bottom:6px" id="q-count"></div>
        <h2 id="q-title"></h2>
        <p class="sub" id="q-sub"></p>
        <div class="onb-opts" id="q-opts"></div>
        <input id="q-text" class="input" maxlength="300" hidden>
        <div class="onb-row">
            <button type="button" class="btn ghost" id="q-skip">تخطّي</button>
            <button type="button" class="btn" id="q-next">التالي ←</button>
        </div>
    </div>

    <!-- تحميل -->
    <div class="onb-card" id="load" hidden>
        <div class="onb-load"><div class="onb-spin"></div><b id="load-t">لحظة…</b><p class="sub" style="margin-top:8px">بياخد أقل من دقيقة — ماتقفلش الصفحة.</p></div>
    </div>

    <!-- 3) الهوية + أول بوست -->
    <div id="s3" hidden>
        <div class="onb-card">
            <h2>هويتك جاهزة ✓</h2>
            <p class="sub">ده اللي فهمناه عن نشاطك — تقدر تعدّل أي حاجة بعدين.</p>
            <dl class="onb-id" id="id-card"></dl>
            <div class="onb-row"><a class="btn ghost" href="<?= url('brand-kit.php') ?>">عدّل الهوية</a></div>
        </div>
        <div class="onb-card" style="margin-top:14px" id="post-card" hidden>
            <h2>أول بوست ليك 🎉</h2>
            <div class="onb-post" id="post-text"></div>
            <?php if (!$verified): ?>
                <div class="onb-msg info" style="margin-top:12px">عشان تنشر أو تجدول البوست: فعّل إيميلك من الرابط اللي بعتناه على <b dir="ltr"><?= e($user['email']) ?></b>.</div>
            <?php endif; ?>
            <div class="onb-row">
                <a class="btn ghost" id="post-open" href="#">افتح البوست</a>
                <a class="btn" href="<?= url('dashboard.php') ?>">لوحة التحكم ←</a>
            </div>
        </div>
        <div class="onb-card" style="margin-top:14px" id="post-fail" hidden>
            <div class="onb-msg err" id="post-fail-t"></div>
            <div class="onb-row">
                <button type="button" class="btn ghost" id="post-retry">جرّب تاني</button>
                <a class="btn" href="<?= url('dashboard.php') ?>">لوحة التحكم ←</a>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
</div>
<?php if (!$verifyOnly): ?>
<script>
(function () {
    var root = document.getElementById('onb');
    var API = root.dataset.api, SELF = root.dataset.self, GEN = root.dataset.gen;
    var SECTORS = JSON.parse(root.dataset.sectors || '[]');
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var $ = function (id) { return document.getElementById(id); };
    var state = null, answers = {}, qi = 0, picked = [];

    function show(id) {
        ['s1', 's2', 'load', 's3'].forEach(function (x) { $(x).hidden = x !== id; });
        var step = id === 's1' ? 1 : (id === 's3' ? 3 : 2);
        document.querySelectorAll('.onb-dots i').forEach(function (d, i) { d.classList.toggle('on', i < step); });
        window.scrollTo(0, 0);
    }
    function msg(t) { var m = $('msg'); m.textContent = t || ''; m.hidden = !t; }
    function loading(t) { $('load-t').textContent = t; show('load'); }

    function call(url, body, isForm) {
        var opt = { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } };
        if (isForm) { opt.body = body; } else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body || {}); }
        return fetch(url, opt).then(function (r) {
            return r.text().then(function (t) {
                var j; try { j = JSON.parse(t); } catch (e) { j = { ok: false, error: 'حصلت مشكلة مؤقتة — جرّب تاني' }; }
                return j;
            });
        }).catch(function () { return { ok: false, error: 'النت فصل — اتأكد من الاتصال وجرّب تاني' }; });
    }
    function api(action, body, isForm) { return call(API + '?action=' + action, body, isForm); }

    /* ───── 1) الاسم + اللينك/الملف ───── */
    $('b1').addEventListener('click', function () {
        msg('');
        var name = $('f-name').value.trim(), url = $('f-url').value.trim(), file = $('f-file').files[0];
        if (name.length < 2) { msg('اكتب اسم نشاطك الأول'); $('f-name').focus(); return; }
        var fd = new FormData(); fd.append('name', name); fd.append('csrf', csrf);
        loading(url || file ? 'بنقرا صفحتك وبياناتك…' : 'لحظة…');
        call(SELF, fd, true).then(function (r) {
            if (!r.ok) { show('s1'); msg(r.error); return null; }
            var f2 = new FormData(); f2.append('build', '0');
            if (url) f2.append('url', url); else if (file) f2.append('file', file);
            return api('autofill', f2, true);
        }).then(function (r) {
            if (r === null) return;
            // القراءة اختيارية: لو اللينك ماتقراش بنكمّل بالأسئلة
            if (r && r.ok) state = r.state;
            startQuestions(r && !r.ok ? r.error : (r && r.warn) || '');
        });
    });

    /* ───── 2) الأسئلة ───── */
    var Q = [
        { key: 'نشاطك إيه', title: 'نشاطك إيه؟', sub: 'بنختار طريقة الكتابة المناسبة لمجالك.', kind: 'sector' },
        { key: 'بتبيع لمين', title: 'بتبيع لمين؟', sub: 'اختار واحد أو أكتر.', multi: true,
          opts: ['سيدات', 'رجال', 'شباب', 'أسر وأطفال', 'شركات وأصحاب أعمال', 'كبار السن'] },
        { key: 'أهم خدمة أو منتج عايز تبيعه دلوقتي', title: 'أهم خدمة أو منتج عايز تبيعه دلوقتي؟', sub: 'اكتبه في كلمتين — أول بوست هيبقى عنه.', kind: 'text', ph: 'مثلًا: تبييض الأسنان' },
        { key: 'أسلوب الكلام مع العملاء', title: 'بتكلم عملاءك بأنهي أسلوب؟', sub: 'اختار الجملة الأقرب ليك.', block: true,
          opts: ['«نسعد بخدمتكم ونلتزم بأعلى معايير الجودة.» — رسمي ومحترم',
                 '«تعالى نساعدك تختار الأنسب ليك، من غير تعقيد.» — ودود وبسيط',
                 '«جاهز تغيّر الروتين؟ يلا بينا! 😄» — مرح وخفيف'] },
        { key: 'العميل بيتواصل إزاي', title: 'العميل يتواصل معاك إزاي؟', sub: 'اختار واحد أو أكتر.', multi: true,
          opts: ['واتساب', 'اتصال تليفون', 'رسايل الصفحة', 'زيارة المكان', 'الموقع الإلكتروني'] }
    ];
    var sectorId = 0;

    function startQuestions(warn) {
        qi = 0; show('s2'); msg(warn || ''); renderQ();
    }
    function optBtn(label, value, block) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'onb-opt' + (block ? ' block' : ''); b.textContent = label; b.dataset.v = value;
        return b;
    }
    function renderQ() {
        var q = Q[qi], box = $('q-opts'), txt = $('q-text');
        $('q-count').textContent = 'سؤال ' + (qi + 1) + ' من ' + Q.length;
        $('q-title').textContent = q.title; $('q-sub').textContent = q.sub;
        box.textContent = ''; picked = []; txt.hidden = true; txt.value = '';
        $('q-next').textContent = qi === Q.length - 1 ? 'ابنِ هويتي ←' : 'التالي ←';
        if (q.kind === 'text') {
            txt.hidden = false; txt.placeholder = q.ph || '';
            var s0 = state && state.services && state.services[0] ? state.services[0].name : '';
            txt.value = s0 || ''; txt.focus();
            return;
        }
        var opts = q.kind === 'sector' ? SECTORS.map(function (s) { return { l: s.name, v: String(s.id) }; })
                                       : q.opts.map(function (o) { return { l: o, v: o }; });
        opts.forEach(function (o) { box.appendChild(optBtn(o.l, o.v, q.block)); });
        if (q.kind === 'sector') {
            txt.hidden = false; txt.placeholder = 'مش لاقي مجالك؟ اكتبه هنا';
            var pre = state && state.sector ? String(state.sector.id) : '';
            if (pre) { var el = box.querySelector('[data-v="' + pre + '"]'); if (el) { el.classList.add('on'); picked = [pre]; } }
        }
    }
    $('q-opts').addEventListener('click', function (e) {
        var b = e.target.closest('.onb-opt'); if (!b) return;
        var q = Q[qi];
        if (q.multi) {
            b.classList.toggle('on');
            picked = Array.prototype.map.call($('q-opts').querySelectorAll('.on'), function (x) { return x.dataset.v; });
        } else {
            $('q-opts').querySelectorAll('.on').forEach(function (x) { x.classList.remove('on'); });
            b.classList.add('on'); picked = [b.dataset.v];
        }
    });
    function nextQ(skip) {
        var q = Q[qi], t = $('q-text').value.trim();
        if (!skip) {
            if (q.kind === 'sector') {
                sectorId = picked.length ? parseInt(picked[0], 10) : 0;
                var nm = sectorId ? (SECTORS.filter(function (s) { return s.id === sectorId; })[0] || {}).name : t;
                if (nm) answers[q.key] = nm;
            } else if (q.kind === 'text') {
                if (t) answers[q.key] = t;
            } else if (picked.length) {
                answers[q.key] = picked.join('، ');
            }
        }
        qi++;
        if (qi < Q.length) { renderQ(); return; }
        build();
    }
    $('q-next').addEventListener('click', function () { nextQ(false); });
    $('q-skip').addEventListener('click', function () { nextQ(true); });
    $('q-text').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); nextQ(false); } });

    /* ───── 3) المسودة ← الحفظ ← أول بوست ───── */
    function build() {
        msg(''); loading('بنبني هوية نشاطك…');
        var p = sectorId ? api('sector_set', { sector_id: sectorId }) : Promise.resolve({ ok: true });
        p.then(function () { return api('draft', { answers: answers }); }).then(function (r) {
            if (!r.ok) { qi = Q.length - 1; show('s2'); renderQ(); msg(r.error || 'معرفناش نبني الهوية — جرّب تاني'); return null; }
            var conf = r.confidence || {}, unsure = Object.keys(conf).filter(function (k) { return conf[k] < 0.7; });
            return api('apply_draft', { draft: r.draft, sections: Object.keys(r.draft), unsure: unsure });
        }).then(function (r) {
            if (r === null) return;
            if (r && r.ok) state = r.state;
            renderIdentity(); show('s3'); firstPost();
        });
    }
    function row(dl, label, value) {
        if (!value) return;
        var dt = document.createElement('dt'), dd = document.createElement('dd');
        dt.textContent = label;
        if (value.nodeType) dd.appendChild(value); else dd.textContent = value;
        dl.appendChild(dt); dl.appendChild(dd);
    }
    function renderIdentity() {
        var dl = $('id-card'); dl.textContent = '';
        var k = (state && state.kit) || {}, b = k.business || {}, a = k.audience || {}, v = k.voice || {}, c = k.colors || {};
        row(dl, 'النشاط', [b.name, b.sector].filter(Boolean).join(' — '));
        row(dl, 'اللي بيميّزك', b.usp);
        row(dl, 'جمهورك', a.description);
        row(dl, 'شخصية البراند', (k.personality || []).filter(Boolean).join(' · '));
        row(dl, 'مثال على أسلوبك', (v.sample_lines || []).filter(Boolean)[0]);
        row(dl, 'خدماتك', ((state && state.services) || []).slice(0, 5).map(function (s) { return s.name; }).join(' · '));
        var cols = [c.primary, c.secondary, c.accent].filter(function (x) { return /^#[0-9a-fA-F]{3,8}$/.test(x || ''); });
        if (cols.length) {
            var span = document.createElement('span');
            cols.forEach(function (x) { var i = document.createElement('i'); i.className = 'onb-sw'; i.style.background = x; span.appendChild(i); });
            row(dl, 'الألوان', span);
        }
        if (!dl.children.length) row(dl, 'النشاط', $('f-name').value.trim());
    }
    function firstPost() {
        $('post-card').hidden = true; $('post-fail').hidden = true;
        loadingInline(true);
        var fd = new FormData();
        fd.append('csrf', csrf); fd.append('content_type', 'introductory'); fd.append('platform', 'both');
        fd.append('length', 'medium'); fd.append('dialect', 'egyptian'); fd.append('onboarding', '1');
        var svc = answers['أهم خدمة أو منتج عايز تبيعه دلوقتي'];
        if (svc) fd.append('extra_notes', 'البوست عن: ' + svc);
        call(GEN, fd, true).then(function (r) {
            loadingInline(false);
            if (!r.ok) { $('post-fail-t').textContent = r.error || 'معرفناش نكتب البوست دلوقتي'; $('post-fail').hidden = false; return; }
            var t = [r.content, r.cta, r.hashtags].filter(Boolean).join('\n\n');
            $('post-text').textContent = t;
            $('post-open').href = root.dataset.view + '?id=' + encodeURIComponent(r.content_id);
            $('post-card').hidden = false;
        });
    }
    var inline = null;
    function loadingInline(on) {
        if (on && !inline) {
            inline = document.createElement('div'); inline.className = 'onb-card'; inline.style.marginTop = '14px';
            inline.innerHTML = '<div class="onb-load"><div class="onb-spin"></div><b>بنكتب أول بوست ليك…</b></div>';
            $('s3').appendChild(inline);
        } else if (!on && inline) { inline.remove(); inline = null; }
    }
    $('post-retry').addEventListener('click', firstPost);
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
