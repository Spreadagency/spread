/* ═══════════════════════════════════════════════════════════
   Spread AI — الرئيسية الجديدة للموقع الخارجي
   سلايدر الهيرو · الروبوت بيتابع الماوس والسكرول · ظهور الأقسام · خط الخطوات ·
   تبويبات الخدمات · المعرض وقبل/بعد · شهري/سنوي · المنيو · وتجربة «اصنع منشورك» الحقيقية
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var CFG = window.SPREAD_HOME || {};
  var REDUCE = false;
  try { REDUCE = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var clamp = function (v, a, b) { return Math.max(a, Math.min(b, v)); };

  document.body.classList.add('rvon');

  /* ═══ المنيو (موبايل/تابلت) ═══ */
  var mBtn = $('#h-menu-btn'), mNav = $('#h-mnav');
  if (mBtn && mNav) {
    var setMenu = function (open) { mNav.classList.toggle('open', open); mBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); };
    mBtn.addEventListener('click', function () { setMenu(!mNav.classList.contains('open')); });
    $$('a', mNav).forEach(function (a) { a.addEventListener('click', function () { setMenu(false); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
  }

  /* ═══ سلايدر الهيرو ═══ */
  var hero = $('#s-hero'), slides = $$('.h-slide'), cur = 0, hover = false;
  var bots = $$('#h-bot img'), dots = $$('.h-dotsnav button'), no = $('#h-no'), c1 = $('#h-c1'), c2 = $('#h-c2');
  function show(i) {
    if (!slides.length) return;
    cur = (i + slides.length) % slides.length;
    slides.forEach(function (s, k) { s.classList.toggle('on', k === cur); s.setAttribute('aria-hidden', k === cur ? 'false' : 'true'); });
    dots.forEach(function (d, k) { d.setAttribute('aria-current', k === cur ? 'true' : 'false'); });
    var sl = slides[cur], b = sl.getAttribute('data-bot');
    bots.forEach(function (img) { img.classList.toggle('on', img.getAttribute('data-b') === b); });
    if (no) no.textContent = (cur + 1 < 10 ? '0' : '') + (cur + 1);
    if (c1) { c1.querySelector('span').textContent = sl.getAttribute('data-c1'); c1.setAttribute('href', sl.getAttribute('data-c1u')); }
    if (c2) c2.textContent = sl.getAttribute('data-c2');
  }
  if (slides.length > 1) {
    $$('[data-slide]').forEach(function (b) { b.addEventListener('click', function () { show(cur + (b.getAttribute('data-slide') === 'next' ? 1 : -1)); }); });
    dots.forEach(function (d) { d.addEventListener('click', function () { show(+d.getAttribute('data-go')); }); });
    if (!REDUCE) setInterval(function () { if (!hover && !document.hidden) show(cur + 1); }, 6500);
    if (hero) {
      hero.addEventListener('mouseenter', function () { hover = true; });
      hero.addEventListener('mouseleave', function () { hover = false; });
      var dx = null;
      hero.addEventListener('pointerdown', function (e) { dx = e.clientX; });
      hero.addEventListener('pointerup', function (e) {
        if (dx === null) return;
        var d = e.clientX - dx; dx = null;
        if (Math.abs(d) > 60) show(cur + (d < 0 ? 1 : -1));
      });
    }
  }

  /* ═══ الروبوت بيتابع الماوس والسكرول (نفس منطق التصميم) ═══ */
  var heroBot = $('#h-bot'), heroSh = $('#h-shadow'), compBot = $('#h-comp-bot');
  var m = { x: 0, y: 0, has: false }, c = { hx: 0, hy: 0, cx: 0, cy: 0 }, look = 0;
  document.addEventListener('mousemove', function (e) { m.x = e.clientX; m.y = e.clientY; m.has = true; }, { passive: true });
  document.addEventListener('mouseleave', function () { m.has = false; });
  function frame() {
    var st = window.scrollY || 0, hx = 0, hy = 0, cx, cy;
    if (heroBot && m.has) {
      var r = heroBot.getBoundingClientRect();
      hx = clamp((m.x - (r.left + r.width / 2)) / 520, -1, 1);
      hy = clamp((m.y - (r.top + r.height * 0.3)) / 420, -1, 1);
    }
    var sd = Math.min(1, st / 600);
    hy = clamp(hy + sd * 0.9, -1, 1);
    cx = look * 0.75; cy = 0.1;
    if (compBot && m.has) {
      var r2 = compBot.getBoundingClientRect();
      cx = clamp(cx + (m.x - (r2.left + r2.width / 2)) / 900, -1, 1);
      cy = clamp((m.y - (r2.top + r2.height * 0.3)) / 700, -1, 1);
    }
    var k = 0.09;
    c.hx += (hx - c.hx) * k; c.hy += (hy - c.hy) * k; c.cx += (cx - c.cx) * k; c.cy += (cy - c.cy) * k;
    if (heroBot && st < window.innerHeight * 1.5) {
      heroBot.style.transform = 'perspective(900px) translate3d(' + (c.hx * 16).toFixed(2) + 'px,' + (c.hy * 8 + sd * 40).toFixed(2) + 'px,0) rotateY(' + (c.hx * 17).toFixed(2) + 'deg) rotateX(' + (-c.hy * 9).toFixed(2) + 'deg) rotateZ(' + (c.hx * -1.6).toFixed(2) + 'deg)';
      if (heroSh) heroSh.style.transform = 'translateX(' + (-c.hx * 22).toFixed(1) + 'px) scaleX(' + (1 - Math.abs(c.hx) * 0.12).toFixed(3) + ')';
    }
    if (compBot) compBot.style.transform = 'perspective(600px) rotateY(' + (c.cx * 22).toFixed(2) + 'deg) rotateX(' + (-c.cy * 10).toFixed(2) + 'deg) translateX(' + (c.cx * 6).toFixed(1) + 'px)';
    requestAnimationFrame(frame);
  }
  if (!REDUCE) requestAnimationFrame(frame);

  /* ═══ السكرول: ظهور · أقسام داكنة · الروبوت المرافق · خط الخطوات · الخدمات · العدادات ═══ */
  var rvs = $$('.rv'), darks = $$('.ws-dark'), secs = $$('[data-say]'), paths = $$('.st-path');
  var svcItems = $$('.sv-item'), svcTabs = $$('[data-svc]'), comp = $('#h-comp'), say = $('#h-say');
  var counters = $('#h-counters'), cntDone = false, lastSay = null, lastSvc = -1;
  function runCounters() {
    cntDone = true;
    var els = $$('b[data-to]', counters), goal = els.map(function (b) { return +b.getAttribute('data-to'); });
    if (REDUCE) return;
    var t0 = null;
    var step = function (t) {
      if (!t0) t0 = t;
      var p = Math.min(1, (t - t0) / 1100), ez = 1 - Math.pow(1 - p, 3);
      els.forEach(function (b, i) { b.textContent = Math.round(goal[i] * ez); });
      if (p < 1) requestAnimationFrame(step);
    };
    els.forEach(function (b) { b.textContent = '0'; });
    requestAnimationFrame(step);
  }
  function scan() {
    var vh = window.innerHeight, st = window.scrollY || 0;
    for (var i = rvs.length - 1; i >= 0; i--) {
      if (rvs[i].getBoundingClientRect().top < vh * 0.88) { rvs[i].classList.add('in'); rvs.splice(i, 1); }
    }
    darks.forEach(function (d) { var q = d.getBoundingClientRect(); d.classList.toggle('on', q.top < vh * 0.55 && q.bottom > vh * 0.3); });
    var s = null;
    secs.forEach(function (e) { if (e.getBoundingClientRect().top < vh * 0.45) s = e; });
    if (comp) {
      comp.classList.toggle('on', st > vh * 0.75);
      var txt = s ? s.getAttribute('data-say') || '' : '';
      look = s ? +(s.getAttribute('data-look') || 0) : 0;
      if (txt !== lastSay && say) { lastSay = txt; say.textContent = txt; say.classList.remove('sa-in'); void say.offsetWidth; say.classList.add('sa-in'); }
    }
    paths.forEach(function (p) {
      var box = p.closest('.st-desk, .st-mob');
      if (!box || !box.offsetParent) return;
      var r = box.getBoundingClientRect();
      p.style.strokeDashoffset = String(1 - clamp((vh * 0.85 - r.top) / (r.height * 0.9), 0, 1));
    });
    if (svcItems.length) {
      var sv = 0;
      svcItems.forEach(function (e, k) { if (e.getBoundingClientRect().top < vh * 0.5) sv = k; });
      if (sv !== lastSvc) {
        lastSvc = sv;
        svcTabs.forEach(function (t, k) { t.setAttribute('aria-selected', k === sv ? 'true' : 'false'); });
        var tab = svcTabs[sv], bar = tab && tab.closest('.sv-tabs');
        if (bar && bar.scrollWidth > bar.clientWidth) bar.scrollTo({ left: tab.offsetLeft - (bar.clientWidth - tab.offsetWidth) / 2, behavior: REDUCE ? 'auto' : 'smooth' });
      }
    }
    if (counters && !cntDone && counters.getBoundingClientRect().top < vh * 0.9) runCounters();
  }
  var ticking = false;
  window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(function () { ticking = false; scan(); }); } }, { passive: true });
  window.addEventListener('resize', scan);
  setTimeout(scan, 60);
  var compBtn = $('#h-comp-btn');
  if (compBtn) compBtn.addEventListener('click', function () { var t = $('#s-create') || $('#s-pricing'); if (t) t.scrollIntoView({ behavior: REDUCE ? 'auto' : 'smooth' }); });

  /* ═══ المعرض: الفلاتر + قبل/بعد ═══ */
  var dzGrid = $('#dz-grid'), dzBa = $('#dz-ba');
  $$('[data-df]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.getAttribute('data-df');
      $$('[data-df]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      if (dzBa) dzBa.hidden = f !== 'ba';
      if (dzGrid) {
        dzGrid.hidden = f === 'ba';
        $$('.dz-i', dzGrid).forEach(function (it) { it.hidden = !(f === 'all' || it.getAttribute('data-c') === f); });
      }
    });
  });
  var baR = $('#ba-range'), baBox = $('#ba-box');
  if (baR && baBox) baR.addEventListener('input', function () { baBox.style.setProperty('--ba', baR.value + '%'); });

  /* ═══ الأسعار: شهري / سنوي ═══ */
  var prGrid = $('#pr-grid');
  $$('[data-per]').forEach(function (b) {
    if (b.tagName !== 'BUTTON') return;
    b.addEventListener('click', function () {
      var per = b.getAttribute('data-per'), n = 0;
      $$('.pr-toggle button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      $$('.pr-card', prGrid).forEach(function (cd) { var on = cd.getAttribute('data-per') === per; cd.hidden = !on; if (on) { n++; cd.classList.add('in'); } });
      if (prGrid) prGrid.style.setProperty('--cols', String(Math.max(1, Math.min(4, n))));
    });
  });

  /* ═══ «اصنع منشورك الآن» — تجربة حقيقية بالذكاء الاصطناعي ═══ */
  var demo = $('#demo');
  if (!demo) return;
  var D = { step: 'form', field: 'مطعم أو كافيه', goal: 'زيادة المبيعات', ideas: [], pick: -1, token: '', tpl: 0, post: null, busy: false, last: null };
  var STEPS = ['form', 'ideas', 'post', 'design', 'save'];
  var TPL = [
    { bg: 'linear-gradient(150deg, #E6FBF7 0%, #DCEBFF 100%)', fg: '#0B1526', ac: '#0A8FA3' },
    { bg: 'linear-gradient(150deg, #0B1526 0%, #12305A 100%)', fg: '#FFFFFF', ac: '#2EE3CC' },
    { bg: '#FFFFFF', fg: '#0B1526', ac: '#0C87EF' }
  ];
  var art = $('#d-art', demo), bizIn = $('#d-biz', demo), audIn = $('#d-aud', demo), otherIn = $('#d-other', demo);
  var biz = function () { return (bizIn.value || '').trim(); };

  function b64(str) {
    return btoa(String.fromCharCode.apply(null, new TextEncoder().encode(str))).replace(/\+/g, '-').replace(/\//g, '_');
  }
  function api(fields) {
    var fd = new FormData(), names = [];
    Object.keys(fields).forEach(function (k) {
      var v = fields[k] == null ? '' : String(fields[k]);
      if (k !== 'action' && k !== 'token' && k !== 'idea_index' && v !== '' && /[^\x00-\x7F]/.test(v)) {
        try { fd.append(k, b64(v)); names.push(k); return; } catch (e) {}
      }
      fd.append(k, v);
    });
    if (names.length) fd.append('_b64', names.join(','));
    return fetch(CFG.trialApi, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'مشكلة في الاتصال — جرّب تاني' }; });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); }

  function paint() {
    var di = STEPS.indexOf(D.step);
    $$('.d-steps [data-s]', demo).forEach(function (el, i) { el.classList.toggle('done', i < di); el.classList.toggle('cur', i === di); });
    $$('.d-pane', demo).forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-p') === D.step); });
    var name = biz() || 'مشروعك';
    $$('[data-biz]', demo).forEach(function (s) { s.textContent = name; });
    $('#d-av', demo).textContent = (biz() || 'S').charAt(0);
    var idea = D.ideas[D.pick] || null;
    art.className = 'd-art ' + (D.busy ? 's-busy' : (idea ? 's-content' : (D.step === 'ideas' ? 's-ideas' : '')));
    var t = TPL[D.tpl];
    art.style.background = t.bg;
    $('#d-art-t', demo).textContent = idea ? (idea.title || '') : '';
    $('#d-art-t', demo).style.color = t.fg;
    $('#d-art-s', demo).style.color = t.ac;
    $('#d-ac', demo).style.background = t.ac;
    $('#d-cap', demo).textContent = D.post ? D.post.content : (idea ? (idea.desc || '') : '');
    var postBtn = $('[data-act="post"]', demo), hint = $('[data-pick-hint]', demo);
    if (postBtn) postBtn.hidden = !idea;
    if (hint) hint.hidden = !!idea;
  }
  function go(step) {
    D.step = step;
    paint();
    if (window.innerWidth < 900) { var top = demo.getBoundingClientRect().top + window.scrollY - 90; if (window.scrollY > top) window.scrollTo({ top: top, behavior: REDUCE ? 'auto' : 'smooth' }); }
    var h = $('.d-pane.on h3', demo); if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
  }
  function msg(pane, text, signup) {
    var el = $('.d-pane[data-p="' + pane + '"] [data-msg]', demo);
    if (!el) return;
    el.hidden = !text;
    el.innerHTML = text ? esc(text) + (signup ? ' <a href="' + esc(CFG.reg) + '">إنشاء حساب مجاني ←</a>' : '') : '';
  }
  function busy(btn, on, label) {
    D.busy = on;
    if (btn) {
      btn.disabled = on;
      if (on) { btn.dataset.html = btn.innerHTML; btn.innerHTML = '<svg class="sa-spin" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="rgba(255,255,255,.95)" stroke-width="2.6" stroke-linecap="round" stroke-dasharray="14 8"></circle></svg>' + esc(label || 'Spread AI بيفكر...'); }
      else if (btn.dataset.html) btn.innerHTML = btn.dataset.html;
    }
    paint();
  }

  // المجال والهدف
  $$('[data-field]', demo).forEach(function (b) {
    b.addEventListener('click', function () {
      D.field = b.getAttribute('data-field');
      $$('[data-field]', demo).forEach(function (x) { var on = x === b; x.classList.toggle('f-on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      otherIn.hidden = D.field !== 'مجال تاني';
      if (!otherIn.hidden) otherIn.focus();
    });
  });
  $$('[data-goal]', demo).forEach(function (b) {
    b.addEventListener('click', function () {
      D.goal = b.getAttribute('data-goal');
      $$('[data-goal]', demo).forEach(function (x) { var on = x === b; x.classList.toggle('g-on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    });
  });
  bizIn.addEventListener('input', function () { if (biz()) { bizIn.classList.remove('bad'); $('#d-biz-err', demo).hidden = true; } paint(); });

  function industry() { return D.field === 'مجال تاني' ? (otherIn.value || '').trim() : D.field; }
  function genIdeas(btn, pane) {
    if (D.busy) return;
    if (!biz()) { bizIn.classList.add('bad'); $('#d-biz-err', demo).hidden = false; bizIn.focus(); return; }
    if (!industry()) { otherIn.classList.add('bad'); otherIn.focus(); return; }
    otherIn.classList.remove('bad');
    msg(pane, '');
    D.last = { action: 'ideas', business_name: biz(), industry: industry(), audience: (audIn.value || '').trim(), goal: D.goal };
    busy(btn, true);
    api(D.last).then(function (d) {
      busy(btn, false);
      if (!d.ok) { msg(pane, d.error || 'حصل خطأ — جرّب تاني', !!d.signup); return; }
      D.token = d.token || ''; D.ideas = d.ideas || []; D.pick = -1; D.post = null;
      renderIdeas();
      go('ideas');
    });
  }
  function renderIdeas() {
    var box = $('#d-ideas', demo);
    box.innerHTML = '';
    D.ideas.forEach(function (it, i) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'ws-idea'; b.setAttribute('aria-pressed', 'false');
      b.innerHTML = '<span class="top"><span class="sa-chip">' + esc(it.angle || 'فكرة') + '</span><span class="num">0' + (i + 1) + '</span></span>'
        + '<span class="tt">' + esc(it.title || ('فكرة ' + (i + 1))) + '</span>' + (it.desc ? '<span class="hk">' + esc(it.desc) + '</span>' : '');
      b.addEventListener('click', function () {
        D.pick = i; D.post = null;
        $$('.ws-idea', box).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        paint();
      });
      box.appendChild(b);
    });
  }
  $('form[data-p="form"]', demo).addEventListener('submit', function (e) { e.preventDefault(); genIdeas($('[data-act="ideas"]', demo), 'form'); });
  $('[data-act="regen"]', demo).addEventListener('click', function () { genIdeas(this, 'ideas'); });
  $('[data-act="post"]', demo).addEventListener('click', function () {
    var btn = this;
    if (D.busy || D.pick < 0) return;
    msg('ideas', '');
    busy(btn, true, 'بنكتب منشورك...');
    api({ action: 'post', token: D.token, idea_index: D.pick }).then(function (d) {
      busy(btn, false);
      if (!d.ok) { msg('ideas', d.error || 'حصل خطأ — جرّب تاني', !!d.signup); return; }
      var idea = D.ideas[D.pick] || {};
      D.post = { content: (d.content || '') + (d.cta ? '\n\n' + d.cta : ''), tags: d.hashtags || ((CFG.tags || {})[D.field] || '') };
      $('#d-post-chip', demo).textContent = (idea.angle || 'بوست') + ' · ' + (idea.title || '');
      $('#d-post-text', demo).textContent = D.post.content;
      $('#d-post-tags', demo).textContent = D.post.tags;
      go('post');
    });
  });
  $('[data-act="copy"]', demo).addEventListener('click', function () {
    var b = this, txt = (D.post ? D.post.content + '\n\n' + D.post.tags : '');
    var done = function () { var o = b.textContent; b.textContent = '✓ اتنسخ'; setTimeout(function () { b.textContent = o; }, 1600); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(txt).then(done, function () {});
    else { var ta = document.createElement('textarea'); ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(ta); }
  });
  $$('[data-tpl]', demo).forEach(function (b) {
    b.addEventListener('click', function () {
      D.tpl = +b.getAttribute('data-tpl');
      $$('[data-tpl]', demo).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      paint();
    });
  });
  $$('[data-go]', demo).forEach(function (b) {
    b.addEventListener('click', function () {
      var to = b.getAttribute('data-go');
      if (to === 'save') {
        // التسجيل بيحمل توكن التجربة ← المنشور والهوية بيتحفظوا في الحساب الجديد (trial_claim)
        var q = D.token ? 'trial=' + encodeURIComponent(D.token) : '';
        [['#d-reg', CFG.reg], ['#d-login', CFG.login]].forEach(function (x) {
          var a = $(x[0], demo); if (a && x[1]) a.href = x[1] + (q ? (x[1].indexOf('?') > -1 ? '&' : '?') + q : '');
        });
      }
      go(to);
    });
  });
  $('[data-act="restart"]', demo).addEventListener('click', function () { D.ideas = []; D.pick = -1; D.post = null; D.token = ''; go('form'); });
  paint();
})();
