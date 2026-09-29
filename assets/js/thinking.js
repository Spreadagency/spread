/* ═══════════════════════════════════════════════════════════
   Spread AI — «Spread AI يفكر...» — لغة الـ AI الموحّدة
   مفيش لودينج عادي: كل عملية AI بتظهر بالأورب + خطوات مكتوبة.

   تلقائي: بيتعلّق على ajaxPost المشترك، فكل استدعاء AI في المنصة
   بيظهر بيه من غير ما نعدّل أي صفحة.

   يدوي:
     SpreadThinking.start({ title, steps: [...], mode: 'overlay'|'pill' })
     SpreadThinking.step(2)
     SpreadThinking.done('تم ✓')   /  SpreadThinking.fail('...')
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  if (!document.body || !document.body.classList.contains('ui-v2')) {
    return; // الواجهة القديمة: السلوك الحالي زي ما هو
  }

  /* ── خطوات كل عملية ── */
  var FLOWS = {
    'generate-content':   { t: 'بكتب منشورك', s: ['بقرا هوية براندك', 'بحدد الزاوية المناسبة', 'بكتب المنشور بأسلوبك', 'بضيف الهاشتاجات والـ CTA'] },
    'regenerate-content': { t: 'بعيد صياغة المنشور', s: ['براجع النسخة الحالية', 'بجرّب زاوية مختلفة', 'بكتب نسخة جديدة'] },
    'produce-idea':       { t: 'بحوّل الفكرة لمنشور', s: ['بقرا الفكرة', 'بربطها بهوية براندك', 'بكتب المنشور'] },
    'generate-plan-ideas':{ t: 'بجهّز أفكار خطتك', s: ['بدرس نشاطك وجمهورك', 'بدوّر على زوايا مختلفة', 'بقيّم الأفكار', 'برتّب أفضلها'] },
    'generate-design':    { t: 'بصمّم منشورك', s: ['بجهّز ألوان ولوجو البراند', 'ببني فكرة التصميم', 'برسم التصميم', 'بضبط التفاصيل النهائية'] },
    'studio-design':      { t: 'بصمّم في الاستوديو', s: ['بحلل الصور المرجعية', 'بطبّق هوية البراند', 'برسم التصميم', 'بضبط التفاصيل النهائية'] },
    'generate-logo':      { t: 'بصمّم لوجو', s: ['بحلل شخصية البراند', 'بجرّب اتجاهات مختلفة', 'بجهّز الأفكار'] },
    'analyze-visual-identity': { t: 'بحلل هويتك البصرية', s: ['بفحص اللوجو والصور', 'بستخرج الألوان والخطوط', 'بكتب دليل الستايل'] },
    'generate-brand-summary':  { t: 'بلخّص براندك', s: ['بقرا بيانات البراند', 'بستخرج أهم النقط', 'بكتب الملخص'] },
    'summarize-source':   { t: 'بقرا المستند', s: ['بقرا المحتوى', 'بستخرج المعلومات المهمة', 'بضيفها لمعرفة البراند'] },
  };
  // عمليات مش محتاجة شاشة كاملة (المستخدم شايف النتيجة وهي بتتكتب)
  var PILL = { 'generate-stream': { t: 'Spread AI بيكتب...', s: [] }, 'brand-agent': { t: 'Spread AI بيفكر...', s: [] } };

  var STEP_MS = 2600;      // مدة كل خطوة
  var SHOW_DELAY = 250;    // منع الوميض للعمليات السريعة
  var DONE_MS = 700;

  var root = null, timer = null, delayT = null, cur = 0, steps = [], active = false, mode = 'overlay';

  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function build() {
    if (root) return root;
    root = el('div', 'sat-root');
    root.setAttribute('role', 'status');
    root.setAttribute('aria-live', 'polite');
    root.innerHTML =
      '<div class="sat-card">' +
        '<div class="sat-orb" id="sat-orb"></div>' +
        '<div class="sat-body">' +
          '<div class="sat-title"><span class="sat-name">Spread AI يفكر</span><span class="sat-dots"><i></i><i></i><i></i></span></div>' +
          '<div class="sat-sub" id="sat-sub"></div>' +
          '<ol class="sat-steps" id="sat-steps"></ol>' +
          '<div class="sat-bar"><span id="sat-bar"></span></div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(root);
    return root;
  }

  function renderSteps() {
    var ol = document.getElementById('sat-steps');
    if (!ol) return;
    ol.innerHTML = steps.map(function (s, i) {
      var st = i < cur ? 'done' : (i === cur ? 'now' : 'next');
      var ic = st === 'done' ? '✓' : (st === 'now' ? '<span class="sat-spin"></span>' : '');
      return '<li class="' + st + '"><span class="sat-ic">' + ic + '</span>' + esc(s) + '</li>';
    }).join('');
    var bar = document.getElementById('sat-bar');
    if (bar) {
      var pct = steps.length ? Math.min(92, Math.round(((cur + .5) / steps.length) * 100)) : 60;
      bar.style.width = pct + '%';
    }
  }

  function show() {
    build();
    root.className = 'sat-root ' + (mode === 'pill' ? 'is-pill' : 'is-overlay');
    // لو العملية خلصت قبل الفريم ده، مانظهرش — كان الـ overlay بيفضل ظاهر للأبد
    requestAnimationFrame(function () { if (active && root) root.classList.add('on'); });
    if (window.SpreadOrb && mode === 'overlay') {
      window.SpreadOrb.mount(document.getElementById('sat-orb'));
      window.SpreadOrb.setState('thinking');
    }
  }

  function start(opts) {
    opts = opts || {};
    stopTimers();
    active = true;
    mode = opts.mode === 'pill' ? 'pill' : 'overlay';
    steps = opts.steps || [];
    cur = 0;

    delayT = setTimeout(function () {
      show();
      var sub = document.getElementById('sat-sub');
      if (sub) sub.textContent = opts.title || '';
      var nm = root.querySelector('.sat-name');
      if (nm) nm.textContent = mode === 'pill' ? (opts.title || 'Spread AI يفكر') : 'Spread AI يفكر';
      renderSteps();
      if (steps.length > 1) {
        timer = setInterval(function () {
          if (cur < steps.length - 1) { cur++; renderSteps(); }
        }, STEP_MS);
      }
    }, SHOW_DELAY);
  }

  function stopTimers() {
    clearTimeout(delayT); clearInterval(timer);
    delayT = timer = null;
  }

  function hide() {
    if (!root) return;
    root.classList.remove('on');
    setTimeout(function () {
      if (active) return;
      if (window.SpreadOrb) window.SpreadOrb.unmount();
      // بعد ما يختفي: بره الصفحة خالص — مايمسكش أي ضغطة (سبب «السيستم بيهنج» بعد التوليد)
      if (root && !root.classList.contains('on')) root.className = 'sat-root is-hidden';
    }, 320);
  }

  function done(msg) {
    var wasShown = root && root.classList.contains('on');
    active = false;
    stopTimers();
    if (!wasShown) { hide(); return; }      // خلصت قبل ما تظهر — نتأكد إنها مش هتظهر
    cur = steps.length; renderSteps();
    var bar = document.getElementById('sat-bar'); if (bar) bar.style.width = '100%';
    var nm = root.querySelector('.sat-name'); if (nm) nm.textContent = msg || 'Spread AI جاهز ✓';
    root.classList.add('is-done');
    if (window.SpreadOrb) window.SpreadOrb.setState('idle');
    setTimeout(function () { root.classList.remove('is-done'); hide(); }, DONE_MS);
  }

  function fail() {
    active = false;
    stopTimers();
    hide();
  }

  window.SpreadThinking = {
    start: start,
    step: function (i) { cur = Math.max(0, Math.min(i, steps.length - 1)); renderSteps(); },
    done: done,
    fail: fail,
    isActive: function () { return active; },
  };

  /* ── التعليق التلقائي على ajaxPost ── */
  function flowFor(url) {
    var m = String(url).match(/ajax\/([a-z-]+)\.php/);
    if (!m) return null;
    if (FLOWS[m[1]]) return { mode: 'overlay', title: FLOWS[m[1]].t, steps: FLOWS[m[1]].s };
    if (PILL[m[1]])  return { mode: 'pill', title: PILL[m[1]].t, steps: [] };
    return null;
  }

  function hook() {
    if (typeof window.ajaxPost !== 'function' || window.ajaxPost.__sat) return;
    var orig = window.ajaxPost;
    var wrapped = async function (url, data) {
      var f = flowFor(url);
      // _quiet: الشاشة عندها مؤشر التفكير بتاعها (الحملة بتصمم/بتنشر بالجملة) — مانغطّيهاش بالـ overlay
      if (!f || (data && data._quiet)) return orig.apply(this, arguments);
      start(f);
      try {
        var res = await orig.apply(this, arguments);
        if (res && res.ok) done(); else fail();
        return res;
      } catch (e) {
        fail();
        throw e;
      }
    };
    wrapped.__sat = true;
    window.ajaxPost = wrapped;
  }

  // البث المباشر (generate-stream) بيستخدم fetch مباشرة
  var origFetch = window.fetch;
  window.fetch = function (input) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    var f = /generate-stream\.php/.test(url) ? flowFor(url) : null;
    var p = origFetch.apply(this, arguments);
    if (f) {
      start(f);
      p.then(function (r) {
        // الرد بيتقرا تدريجيًا — نقفل لما البث يخلص
        if (r && r.body && r.clone) {
          r.clone().text().then(function () { done('خلص الكتابة ✓'); }, fail);
        } else { done(); }
      }, fail);
    }
    return p;
  };

  hook();
  document.addEventListener('DOMContentLoaded', hook);
})();
