/* ═══════════════════════════════════════════════════════════
   Spread AI — المساعدين (المرحلة 7): زرار ثابت في كل صفحات العميل
   🛟 الدعم: بيحل المشكلة من بيانات حسابك أو يبلّغ الفريق
   🤖 الوكيل: بيفهم المطلوب ← يقولك التكلفة ← توافق ← ينفّذ الحملة كاملة
   (من غير مكتبات — شغال في الواجهة القديمة والجديدة)
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var BASE = (document.querySelector('meta[name="app-base"]') || {}).content || '';
  var API = BASE + '/api/assistant.php';
  var FMT = { post: 'بوست', carousel: 'كاروسيل', reel: 'ريلز' };
  var st = { open: false, kind: 'support', data: {}, busy: false, poll: null };

  function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
  function b64(s) { var b = new TextEncoder().encode(s), o = ''; for (var i = 0; i < b.length; i += 0x8000) o += String.fromCharCode.apply(null, b.subarray(i, i + 0x8000)); return btoa(o); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function md(s) { return esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>'); }
  function store(k, v) { try { if (v === undefined) return localStorage.getItem('sa_' + k); localStorage.setItem('sa_' + k, v); } catch (e) { return null; } }

  async function call(action, body, get) {
    try {
      var res = get
        ? await fetch(API + '?' + new URLSearchParams(Object.assign({ action: action }, body || {})), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        : await fetch(API, { method: 'POST', credentials: 'same-origin', body: b64(JSON.stringify(Object.assign({ action: action }, body || {}))),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf(), 'X-Payload': 'b64', 'Content-Type': 'text/plain;charset=UTF-8' } });
      return (await res.json()) || { ok: false, error: 'حصلت مشكلة' };
    } catch (e) { return { ok: false, error: 'مفيش اتصال — جرّب تاني' }; }
  }

  /* ─── الهيكل ─── */
  var root = document.createElement('div');
  root.className = 'sa-root';
  root.innerHTML =
    '<button type="button" class="sa-fab" aria-haspopup="dialog" aria-expanded="false" aria-label="مساعدك"><span aria-hidden="true">🤖</span><b>مساعدك</b></button>' +
    '<section class="sa-panel" role="dialog" aria-label="مساعدك" hidden>' +
      '<header class="sa-head"><div class="sa-tabs" role="tablist">' +
        '<button type="button" role="tab" data-k="support">🛟 الدعم</button><button type="button" role="tab" data-k="agent">🤖 الوكيل</button></div>' +
        '<button type="button" class="sa-ic sa-new" title="محادثة جديدة" aria-label="محادثة جديدة">↻</button>' +
        '<button type="button" class="sa-ic sa-x" aria-label="قفل">✕</button></header>' +
      '<div class="sa-body" aria-live="polite"></div>' +
      '<form class="sa-form" data-no-busy="1" novalidate><textarea rows="1" placeholder="اكتب رسالتك…" aria-label="رسالتك"></textarea><button type="submit" class="sa-send" aria-label="ابعت">➤</button></form>' +
    '</section>';
  document.body.appendChild(root);
  var fab = root.querySelector('.sa-fab'), panel = root.querySelector('.sa-panel'), body = root.querySelector('.sa-body');
  var form = root.querySelector('.sa-form'), input = form.querySelector('textarea');

  function setOpen(v) {
    st.open = v; panel.hidden = !v; fab.setAttribute('aria-expanded', v ? 'true' : 'false'); root.classList.toggle('on', v);
    store('open', v ? '1' : '0');
    if (v) { load(st.kind); setTimeout(function () { input.focus(); }, 50); } else stopPoll();
  }
  fab.addEventListener('click', function () { setOpen(!st.open); });
  root.querySelector('.sa-x').addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && st.open) setOpen(false); });
  root.querySelectorAll('.sa-tabs button').forEach(function (b) { b.addEventListener('click', function () { load(b.dataset.k); }); });
  root.querySelector('.sa-new').addEventListener('click', async function () {
    if (st.busy || !confirm('تبدأ محادثة جديدة؟')) return;
    var r = await call('reset', { kind: st.kind });
    if (r.ok) { st.data.messages = r.messages; render(); }
  });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
  input.addEventListener('input', function () { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 120) + 'px'; });
  form.addEventListener('submit', function (e) { e.preventDefault(); send(); });

  async function load(kind) {
    st.kind = kind; store('kind', kind); stopPoll();
    root.querySelectorAll('.sa-tabs button').forEach(function (b) { var on = b.dataset.k === kind; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    body.innerHTML = '<p class="sa-mute">…</p>';
    var r = await call('load', { kind: kind }, true);
    if (!r.ok) { body.innerHTML = '<p class="sa-mute">' + esc(r.error) + '</p>'; return; }
    st.data = r;
    render();
    if (r.run && r.run.active) startPoll();
  }

  async function send() {
    var t = input.value.trim().slice(0, 1500);
    if (!t || st.busy || st.data.blocked) return;
    st.busy = true; input.value = ''; input.style.height = 'auto';
    (st.data.messages = st.data.messages || []).push({ role: 'user', content: t });
    render(true);
    var r = await call('send', { kind: st.kind, message: t, page: location.pathname + location.search });
    st.busy = false;
    if (!r.ok) { st.data.messages.push({ role: 'assistant', content: '⚠️ ' + (r.error || 'حصلت مشكلة'), err: true }); render(); return; }
    st.data.messages.push({ role: 'assistant', content: r.reply, links: r.links || [], ticket: r.ticket || null });
    if (r.run) st.data.run = r.run;
    render();
  }

  /* ─── الرسم ─── */
  function render(typing) {
    var d = st.data, h = '';
    form.hidden = !!d.blocked;
    if (d.blocked) {
      h = '<div class="sa-lock"><b>' + (st.kind === 'agent' ? '🤖 الوكيل' : '🛟 الدعم') + '</b><p>' + esc(d.blocked) + '</p>' +
        (st.kind === 'agent' && d.upgrade_url ? '<a class="sa-btn" href="' + esc(d.upgrade_url) + '">⬆️ شوف الباقات</a>' : '') + '</div>';
      body.innerHTML = h; return;
    }
    (d.messages || []).forEach(function (m) {
      h += '<div class="sa-msg ' + (m.role === 'user' ? 'me' : 'bot') + (m.err ? ' err' : '') + '">' + md(m.content);
      if (m.links && m.links.length) h += '<div class="sa-links">' + m.links.map(function (l) { return '<a href="' + esc(l.url) + '">' + esc(l.label) + '</a>'; }).join('') + '</div>';
      h += '</div>';
    });
    if (typing) h += '<div class="sa-msg bot sa-typing" aria-label="بيكتب"><i></i><i></i><i></i></div>';
    if (st.kind === 'agent' && d.run) h += runCard(d.run);
    if (!typing && (d.messages || []).length <= 1) h += hints();
    body.innerHTML = h;
    bindRun();
    body.querySelectorAll('.sa-hint').forEach(function (b) { b.addEventListener('click', function () { input.value = b.textContent; send(); }); });
    body.scrollTop = body.scrollHeight;
  }

  function hints() {
    var list = st.kind === 'agent'
      ? ['اعمل حملة عرض للشهر ده 6 بوستات و2 كاروسيل', 'محتوى توعوي أسبوعين عن أهم خدمة عندي', 'حملة لمناسبة جاية وجدولها على صفحتي']
      : ['ليه رصيدي قل؟', 'طلب الدفع بتاعي لسه ماتفعّلش', 'النشر على فيسبوك فشل', 'إزاي أزوّد التصميمات؟'];
    return '<div class="sa-hints">' + list.map(function (x) { return '<button type="button" class="sa-hint">' + esc(x) + '</button>'; }).join('') + '</div>';
  }

  function runCard(r) {
    var fm = Object.keys(r.formats || {}).filter(function (k) { return r.formats[k] > 0; }).map(function (k) { return r.formats[k] + ' ' + (FMT[k] || k); }).join(' · ');
    var h = '<div class="sa-run ' + esc(r.status) + '"><div class="sa-run-h"><b>' + esc(r.title) + '</b><small>' + r.pieces + ' قطعة' + (fm ? ' (' + esc(fm) + ')' : '') + (r.days ? ' · ' + r.days + ' يوم' : '') + '</small></div>';
    if (r.status === 'pending') {
      var conns = st.data.connections || [];
      h += '<ul class="sa-cost"><li>الخطة والكتابة (تقدير): <b>~' + r.credits + ' كريدت</b></li>' + (r.designs ? '<li>التصميمات: <b>' + r.designs + '</b> من حصتك</li>' : '') +
        '<li>رسوم تنفيذ الوكيل: <b>' + r.fee + ' كريدت</b></li></ul>' +
        '<fieldset class="sa-pub"><legend>بعد ما يخلص</legend>' +
        '<label><input type="radio" name="sa-pub" value="none" ' + (r.publish !== 'schedule' || !conns.length ? 'checked' : '') + '> سيبه لي أراجعه</label>' +
        (conns.length
          ? '<label><input type="radio" name="sa-pub" value="schedule" ' + (r.publish === 'schedule' ? 'checked' : '') + '> جدوله على صفحتي في مواعيد الخطة</label>' +
            '<div class="sa-pub-opts"><select class="sa-conn" aria-label="الصفحة">' + conns.map(function (c) { return '<option value="' + c.id + '" data-ig="' + (c.ig ? 1 : 0) + '">' + esc(c.name) + '</option>'; }).join('') + '</select>' +
            '<label><input type="checkbox" class="sa-pl" value="facebook" checked> فيسبوك</label><label><input type="checkbox" class="sa-pl" value="instagram"> إنستجرام</label></div>'
          : '<small class="sa-mute">للجدولة اربط صفحتك الأول من «ربط الصفحات»</small>') +
        '</fieldset><div class="sa-row"><button type="button" class="sa-btn sa-go">✓ نفّذ</button><button type="button" class="sa-btn ghost sa-cancel">مش دلوقتي</button></div>';
    } else {
      h += '<ol class="sa-steps">' + r.stages.map(function (s) { return '<li class="' + esc(s.state) + '">' + (s.state === 'done' ? '✓ ' : (s.state === 'running' ? '⏳ ' : (s.state === 'paused' ? '⏸ ' : ''))) + esc(s.label) + '</li>'; }).join('') + '</ol>';
      if (r.note) h += '<p class="sa-note">' + md(r.note) + '</p>';
      if (r.log && r.log.length) h += '<details class="sa-log"><summary>اللي اتعمل</summary>' + r.log.map(function (l) { return '<div><small>' + esc(l.at.slice(11)) + '</small> ' + esc(l.msg) + '</div>'; }).join('') + '</details>';
      h += '<div class="sa-row">' + (r.campaign_url ? '<a class="sa-btn" href="' + esc(r.campaign_url) + '">افتح الحملة</a>' : '') +
        (r.active ? '<button type="button" class="sa-btn ghost sa-cancel">إلغاء المهمة</button>' : '') + '</div>';
    }
    return h + '</div>';
  }

  function bindRun() {
    var r = st.data.run;
    if (!r) return;
    var go = body.querySelector('.sa-go'), cancel = body.querySelector('.sa-cancel');
    if (go) go.addEventListener('click', async function () {
      var pub = (body.querySelector('input[name=sa-pub]:checked') || {}).value || 'none';
      var conn = body.querySelector('.sa-conn');
      var pls = Array.prototype.map.call(body.querySelectorAll('.sa-pl:checked'), function (x) { return x.value; });
      go.disabled = true; go.textContent = 'بيبدأ…';
      var x = await call('agent_confirm', { run_id: r.id, publish: pub, connection_id: conn ? +conn.value : 0, platforms: pls });
      if (!x.ok) { go.disabled = false; go.textContent = '✓ نفّذ'; alert(x.error); return; }
      st.data.run = x.run; render(); startPoll();
    });
    if (cancel) cancel.addEventListener('click', async function () {
      if (r.status !== 'pending' && !confirm('تلغي المهمة؟ اللي اتعمل في الحملة بيفضل.')) return;
      var x = await call('agent_cancel', { run_id: r.id });
      if (x.ok) { st.data.run = x.run.status === 'cancelled' && r.status === 'pending' ? null : x.run; stopPoll(); render(); } else alert(x.error);
    });
  }

  function startPoll() {
    stopPoll();
    var tick = async function () {
      var r = st.data.run;
      if (!st.open || st.kind !== 'agent' || !r || !r.active) return;
      var x = await call('agent_status', { run_id: r.id });
      if (x.ok) {
        var changed = JSON.stringify(x.run) !== JSON.stringify(r);
        st.data.run = x.run;
        if (changed) { if (!x.run.active) return load('agent'); render(); }
      }
      st.poll = setTimeout(tick, x.ok && x.run.status === 'paused' ? 10000 : 3500);
    };
    st.poll = setTimeout(tick, 1500);
  }
  function stopPoll() { clearTimeout(st.poll); st.poll = null; }

  st.kind = store('kind') === 'agent' ? 'agent' : 'support';
  if (store('open') === '1' && window.innerWidth > 760) setOpen(true);
})();
