<?php
/**
 * Spread AI — الرسايل والإشعارات
 *   تبويب «الرسايل»: كل إشعاراتك ورسايل فريق Spread (الأحدث فوق · 20 في الصفحة)
 *   تبويب «إعدادات الواتساب»: رقمك + كود التحقق · استقبال الرسايل · التحكم في كل نوع إشعار
 * الداتا: api/inbox.php · api/whatsapp.php
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/Notifier.php';

require_login();
$user = current_user();
wa_ensure_schema();
$tab = ($_GET['tab'] ?? '') === 'whatsapp' ? 'whatsapp' : 'inbox';

$active = 'notifications';
$page_title = 'الرسايل والإشعارات';
include __DIR__ . '/../templates/header.php';
?>
<style>
.nx { max-width: 860px; margin: 0 auto; }
.nx-tabs { display: flex; gap: 8px; margin: 4px 0 16px; flex-wrap: wrap; }
.nx-tab { padding: 9px 16px; border-radius: 999px; border: 1px solid var(--line, #DCE6F0); background: var(--surface-solid, #fff);
    color: var(--ink-2, #27324A); font-weight: 600; text-decoration: none; font-size: 14px; }
.nx-tab.on, .nx a.nx-tab.on { background: var(--grad-cta, #0A5FCF); color: #fff; border-color: transparent; }
.nx-card { background: var(--surface-solid, #fff); border: 1px solid var(--line, #DCE6F0); border-radius: var(--r-lg, 20px);
    padding: 18px; box-shadow: var(--shadow-sm, none); margin-bottom: 14px; }
.nx-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
.nx-head h2 { font-size: 17px; margin: 0; }
.nx-list { display: flex; flex-direction: column; gap: 10px; }
.nx-item { border: 1px solid var(--line-2, #E6EEF6); border-radius: var(--r-md, 14px); padding: 14px; background: var(--surface-solid, #fff); cursor: pointer; }
.nx-item.unread { background: var(--primary-soft, #E3F1FF); border-color: #BFDDFB; }
.nx-item-top { display: flex; align-items: flex-start; gap: 8px; justify-content: space-between; }
.nx-item b { font-size: 15px; color: var(--ink, #0B1526); }
.nx-item p { margin: 6px 0 0; color: var(--ink-2, #27324A); white-space: pre-line; line-height: 1.7; font-size: 14px; overflow-wrap: anywhere; }
.nx-meta { color: var(--mute, #5B6478); font-size: 12px; white-space: nowrap; }
.nx-tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--teal-soft, #E6F8F6);
    color: var(--teal-deep, #0B6B78); margin-inline-start: 6px; vertical-align: middle; }
.nx-media { margin-top: 10px; max-width: 100%; border-radius: 12px; display: block; max-height: 320px; object-fit: cover; }
.nx-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
.nx-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 12px; border: 1px solid var(--line, #DCE6F0);
    background: var(--surface-solid, #fff); color: var(--ink-2, #27324A); font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; }
.nx-btn.primary, .nx a.nx-btn.primary { background: var(--grad-cta, #0A5FCF); color: #fff; border-color: transparent; }
.nx-btn[disabled] { opacity: .55; cursor: default; }
.nx-empty { text-align: center; color: var(--mute, #5B6478); padding: 30px 10px; }
.nx-pager { display: flex; justify-content: center; gap: 8px; margin-top: 12px; }
.nx-field { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
.nx-input { flex: 1 1 200px; min-width: 0; padding: 11px 12px; border-radius: 12px; border: 1px solid var(--line, #DCE6F0); font: inherit; font-size: 15px; background: #fff; }
.nx-note { color: var(--mute, #5B6478); font-size: 13px; margin-top: 8px; line-height: 1.7; }
.nx-ok { color: var(--success, #10A8A0); font-weight: 700; }
.nx-warn { background: var(--amber-soft, #FFF1DC); color: #7A4C0A; border-radius: 12px; padding: 10px 12px; font-size: 14px; margin-top: 10px; }
.nx-err { background: var(--danger-soft, #FDE8EC); color: #8A1E31; border-radius: 12px; padding: 10px 12px; font-size: 14px; margin-top: 10px; }
.nx-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.nx-table th, .nx-table td { padding: 10px 6px; border-bottom: 1px solid var(--line-2, #E6EEF6); text-align: start; }
.nx-table th { color: var(--mute, #5B6478); font-weight: 600; font-size: 13px; }
.nx-table td.c, .nx-table th.c { text-align: center; width: 90px; }
.nx-sw { position: relative; display: inline-block; width: 44px; height: 26px; }
.nx-sw input { opacity: 0; width: 0; height: 0; position: absolute; }
.nx-sw span { position: absolute; inset: 0; border-radius: 999px; background: #C9D4E0; transition: .2s; cursor: pointer; }
.nx-sw span::after { content: ""; position: absolute; width: 20px; height: 20px; top: 3px; inset-inline-start: 3px; border-radius: 50%; background: #fff; transition: .2s; }
.nx-sw input:checked + span { background: var(--primary, #0C87EF); }
.nx-sw input:checked + span::after { inset-inline-start: 21px; }
.nx-sw input:disabled + span { opacity: .55; cursor: not-allowed; }
.nx-sw input:focus-visible + span { outline: 2px solid var(--primary, #0C87EF); outline-offset: 2px; }
.nx-lock { font-size: 11px; color: var(--mute, #5B6478); display: block; }
body.ui-v2 .nx a.nx-tab, body.ui-v2 .nx a.nx-btn { color: var(--ink-2, #27324A); }
body.ui-v2 .nx a.nx-tab.on, body.ui-v2 .nx a.nx-btn.primary { color: #fff; }
@media (max-width: 560px) { .nx-card { padding: 14px; } .nx-table td.c, .nx-table th.c { width: 64px; } }
</style>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="nx">
            <nav class="nx-tabs" aria-label="الرسايل">
                <a class="nx-tab <?= $tab === 'inbox' ? 'on' : '' ?>" href="<?= url('notifications.php') ?>">الرسايل</a>
                <a class="nx-tab <?= $tab === 'whatsapp' ? 'on' : '' ?>" href="<?= url('notifications.php?tab=whatsapp') ?>">إعدادات الواتساب</a>
            </nav>

            <?php if ($tab === 'inbox'): ?>
            <section class="nx-card" aria-labelledby="nx-h-in">
                <div class="nx-head">
                    <h2 id="nx-h-in">رسايلك <span id="nx-unread" class="nx-meta"></span></h2>
                    <button type="button" class="nx-btn" id="nx-readall">قراءة الكل ✓</button>
                </div>
                <div id="nx-wa-banner"></div>
                <div class="nx-list" id="nx-list" aria-live="polite"><div class="nx-empty">بيحمّل…</div></div>
                <div class="nx-pager" id="nx-pager"></div>
            </section>
            <?php else: ?>
            <section class="nx-card" aria-labelledby="nx-h-wa">
                <div class="nx-head"><h2 id="nx-h-wa">رقم الواتساب</h2><span id="nx-wa-state" class="nx-meta"></span></div>
                <div id="nx-wa-box"><div class="nx-empty">بيحمّل…</div></div>
            </section>
            <section class="nx-card" aria-labelledby="nx-h-ev">
                <div class="nx-head"><h2 id="nx-h-ev">إيه اللي يوصلك</h2></div>
                <div style="overflow-x:auto"><table class="nx-table" id="nx-events"></table></div>
                <p class="nx-note">الإشعارات المهمة (زي فشل النشر وانتهاء الاشتراك) مفعّلة دايمًا علشان ماتفوتكش.</p>
            </section>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var API_IN = <?= json_encode(url('api/inbox.php')) ?>, API_WA = <?= json_encode(url('api/whatsapp.php')) ?>;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function b64(o) { return btoa(unescape(encodeURIComponent(JSON.stringify(o)))); }
  function get(u) { return fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }); }
  function post(u, body) {
    return fetch(u, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'text/plain', 'X-Payload': 'b64', 'X-CSRF-Token': CSRF, 'Accept': 'application/json' }, body: b64(body) })
      .then(function (r) { return r.json(); });
  }
  window.nxApi = { get: get, post: post, esc: esc, IN: API_IN, WA: API_WA };
})();
</script>

<?php if ($tab === 'inbox'): ?>
<script>
(function () {
  var A = window.nxApi, page = 1;
  var list = document.getElementById('nx-list'), pager = document.getElementById('nx-pager');
  function render(d) {
    document.getElementById('nx-unread').textContent = d.unread ? '(' + d.unread + ' جديدة)' : '';
    if (!d.items.length) { list.innerHTML = '<div class="nx-empty">مفيش رسايل لسه 📭</div>'; pager.innerHTML = ''; return; }
    list.innerHTML = d.items.map(function (m) {
      var acts = '';
      if (m.internal) acts += '<a class="nx-btn" href="' + A.esc(m.open) + '">افتح</a>';
      if (m.link) acts += '<a class="nx-btn primary" href="' + A.esc(m.link) + '" target="_blank" rel="noopener">افتح الرابط ↗</a>';
      var media = '';
      if (m.media && m.media_type === 'image') media = '<img class="nx-media" src="' + A.esc(m.media) + '" alt="" loading="lazy">';
      else if (m.media) media = '<div class="nx-actions"><a class="nx-btn" href="' + A.esc(m.media) + '" target="_blank" rel="noopener">📎 المرفق</a></div>';
      return '<article class="nx-item' + (m.read ? '' : ' unread') + '" data-id="' + m.id + '" tabindex="0">' +
        '<div class="nx-item-top"><b>' + A.esc(m.title) + (m.admin ? '<span class="nx-tag">من فريق Spread</span>' : '') + '</b>' +
        '<span class="nx-meta" title="' + A.esc(m.at) + '">' + A.esc(m.ago) + '</span></div>' +
        (m.body ? '<p>' + A.esc(m.body) + '</p>' : '') + media + (acts ? '<div class="nx-actions">' + acts + '</div>' : '') + '</article>';
    }).join('');
    pager.innerHTML = d.pages > 1 ?
      (d.page > 1 ? '<button class="nx-btn" data-p="' + (d.page - 1) + '">السابق</button>' : '') +
      '<span class="nx-meta" style="align-self:center">' + d.page + ' / ' + d.pages + '</span>' +
      (d.page < d.pages ? '<button class="nx-btn" data-p="' + (d.page + 1) + '">التالي</button>' : '') : '';
  }
  function load() { A.get(A.IN + '?action=list&page=' + page).then(function (d) { if (d.ok) render(d); }); }
  list.addEventListener('click', function (e) {
    var it = e.target.closest('.nx-item'); if (!it || !it.classList.contains('unread')) return;
    it.classList.remove('unread');
    A.post(A.IN + '?action=read', { ids: [parseInt(it.dataset.id, 10)] }).then(function (d) { if (d.ok && window.spInbox) window.spInbox.set(d.count); });
  });
  pager.addEventListener('click', function (e) { var b = e.target.closest('[data-p]'); if (b) { page = parseInt(b.dataset.p, 10); load(); window.scrollTo(0, 0); } });
  document.getElementById('nx-readall').addEventListener('click', function () {
    A.post(A.IN + '?action=read', { all: true }).then(function (d) { if (d.ok) { load(); if (window.spInbox) window.spInbox.set(0); } });
  });
  // بانر: مفيش رقم واتساب متحقق
  A.get(A.WA + '?action=home').then(function (d) {
    if (d.ok && d.enabled && !d.verified && !d.fallback) document.getElementById('nx-wa-banner').innerHTML =
      '<div class="nx-warn">📲 خلّي إشعاراتك توصلك على الواتساب — <a href="?tab=whatsapp">ضيف رقمك وأكّده</a></div>';
  });
  load();
})();
</script>
<?php else: ?>
<script>
(function () {
  var A = window.nxApi, box = document.getElementById('nx-wa-box'), state = null, msg = '', err = '', codeSent = false, changing = false;
  function sw(id, on, dis, label) {
    return '<label class="nx-sw"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + (dis ? ' disabled' : '') +
      ' aria-label="' + A.esc(label) + '"><span></span></label>';
  }
  function render() {
    var d = state;
    document.getElementById('nx-wa-state').innerHTML = d.verified ? '<span class="nx-ok">✓ متحقق</span>' : (d.fallback ? 'رقم الحساب' : 'مش متحقق');
    var h = '';
    if (!d.enabled) h += '<div class="nx-warn">رسايل الواتساب متوقفة مؤقتًا من الإدارة. هتوصلك الإشعارات جوه المنصة عادي.</div>';
    if (d.verified) {
      h += '<p style="margin:0">رقمك: <b dir="ltr">+' + A.esc(d.phone) + '</b></p>' +
           '<div class="nx-field"><span>استقبال رسايل الواتساب</span>' + sw('nx-optin', d.opt_in, false, 'استقبال رسايل الواتساب') + '</div>' +
           '<div class="nx-actions"><button class="nx-btn" id="nx-change">تغيير الرقم</button><button class="nx-btn" id="nx-remove">شيل الرقم</button></div>';
    }
    if (!d.verified && d.fallback) {
      h += '<p style="margin:0">الرسايل بتوصلك على رقم حسابك: <b dir="ltr">+' + A.esc(d.fallback) + '</b></p>' +
           '<div class="nx-field"><span>استقبال رسايل الواتساب</span>' + sw('nx-optin', d.opt_in, false, 'استقبال رسايل الواتساب') + '</div>';
    }
    if (!d.verified || changing) {
      h += '<p class="nx-note" style="margin-top:' + (d.verified || d.fallback ? '14px' : '0') + '">' + (d.fallback && !d.verified ? 'عايزها على رقم واتساب تاني؟ ' : '') + 'اكتب رقم الواتساب وهنبعتلك كود من 6 أرقام عليه.</p>' +
           '<div class="nx-field"><input class="nx-input" id="nx-phone" type="tel" dir="ltr" inputmode="tel" autocomplete="tel" placeholder="01xxxxxxxxx" value="' +
           A.esc(d.pending_phone || d.suggest || '') + '" aria-label="رقم الواتساب"><button class="nx-btn primary" id="nx-send"' + (d.enabled ? '' : ' disabled') + '>ابعت الكود</button></div>';
      if (codeSent || d.pending_phone) {
        h += '<div class="nx-field"><input class="nx-input" id="nx-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr" placeholder="الكود" aria-label="كود التحقق">' +
             '<button class="nx-btn primary" id="nx-verify">تأكيد</button></div><p class="nx-note">الكود صالح 10 دقايق · 3 محاولات · لحد 3 أكواد في الساعة</p>';
      }
    }
    if (msg) h += '<p class="nx-ok" style="margin-top:10px">' + A.esc(msg) + '</p>';
    if (err) h += '<div class="nx-err" role="alert">' + A.esc(err) + '</div>';
    box.innerHTML = h;
    var ev = '<thead><tr><th>الإشعار</th><th class="c">واتساب</th><th class="c">جوه المنصة</th></tr></thead><tbody>' +
      d.events.map(function (e) {
        return '<tr><td>' + A.esc(e.label) + (e.critical ? '<span class="nx-lock">🔒 مهم — مفعّل دايمًا</span>' : '') + '</td>' +
          '<td class="c">' + (e.has_wa ? sw('w_' + e.key, e.whatsapp, e.critical, 'واتساب: ' + e.label) : '—') + '</td>' +
          '<td class="c">' + (e.has_inapp ? sw('i_' + e.key, e.inapp, e.critical, 'جوه المنصة: ' + e.label) : '—') + '</td></tr>';
      }).join('') + '</tbody>';
    document.getElementById('nx-events').innerHTML = ev;
  }
  function done(d, okMsg) {
    err = ''; msg = '';
    if (d.ok) { state = d; if (okMsg) msg = okMsg; }
    else err = d.error || 'حصلت مشكلة — جرّب تاني';
    render();
  }
  box.addEventListener('click', function (e) {
    var t = e.target;
    if (t.id === 'nx-send') {
      t.disabled = true;
      A.post(A.WA + '?action=send_code', { phone: document.getElementById('nx-phone').value }).then(function (d) {
        if (d.ok) codeSent = true;
        done(d, d.ok ? 'بعتنالك الكود على الواتساب ✓' : '');
        var c = document.getElementById('nx-code'); if (c) c.focus();
      });
    } else if (t.id === 'nx-verify') {
      A.post(A.WA + '?action=verify', { code: document.getElementById('nx-code').value }).then(function (d) {
        if (d.ok) { codeSent = false; changing = false; }
        done(d, d.ok ? 'اتأكد رقمك ✓ هتوصلك الإشعارات على الواتساب' : '');
      });
    } else if (t.id === 'nx-change') { changing = true; render(); }
    else if (t.id === 'nx-remove') {
      if (confirm('متأكد؟ مش هتوصلك رسايل واتساب لحد ما تضيف رقم تاني')) A.post(A.WA + '?action=remove', {}).then(function (d) { changing = false; done(d, 'اتشال الرقم'); });
    }
  });
  box.addEventListener('change', function (e) {
    if (e.target.id === 'nx-optin') A.post(A.WA + '?action=opt_in', { on: e.target.checked }).then(function (d) { done(d, e.target.checked ? 'هتوصلك رسايل الواتساب ✓' : 'وقفنا رسايل الواتساب'); });
  });
  document.getElementById('nx-events').addEventListener('change', function (e) {
    var id = e.target.id || ''; var key = id.slice(2); if (!key) return;
    var w = document.getElementById('w_' + key), i = document.getElementById('i_' + key);
    A.post(A.WA + '?action=prefs', { event: key, whatsapp: w ? w.checked : true, inapp: i ? i.checked : true }).then(function (d) { done(d, ''); });
  });
  A.get(A.WA + '?action=home').then(function (d) { if (d.ok) { state = d; render(); } else box.innerHTML = '<div class="nx-err">' + A.esc(d.error) + '</div>'; });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
