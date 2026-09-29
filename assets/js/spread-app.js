/* ═══════════════════════════════════════════════════════════
   Spread AI — مكتبة الواجهة (المرحلة 1)
   فوق petite-vue (~7KB): بتدّي كل شاشة تفاعلية نفس الأدوات:
     SpreadAPI      — طلبات JSON (CSRF · base64 · أخطاء ودّية)
     SpreadAutosave — حفظ تلقائي بحالات واضحة ومنع فقدان البيانات
     SpreadApp      — تركيب تطبيق petite-vue على جزء من الصفحة
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var BASE = (document.querySelector('meta[name="app-base"]') || {}).content || '';
  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  }

  /* base64 لنص UTF-8 (عشان mod_security مايحجبش العربي) */
  function b64(str) {
    var bytes = new TextEncoder().encode(str);
    var bin = '';
    for (var i = 0; i < bytes.length; i += 0x8000) {
      bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    }
    return btoa(bin);
  }

  var FRIENDLY = {
    offline: 'مفيش اتصال بالإنترنت — هنحاول تاني',
    server_error: 'حصلت مشكلة مؤقتة — جرّب تاني بعد لحظة',
  };

  /* ═══════════ SpreadAPI ═══════════ */
  async function request(method, endpoint, params, body, opts) {
    opts = opts || {};
    var url = BASE + '/api/' + endpoint + '.php';
    if (params) {
      var qs = new URLSearchParams();
      Object.keys(params).forEach(function (k) {
        if (params[k] !== undefined && params[k] !== null && params[k] !== '') qs.append(k, params[k]);
      });
      var s = qs.toString();
      if (s) url += '?' + s;
    }
    var init = {
      method: method,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      keepalive: !!opts.keepalive,
    };
    if (method === 'POST') {
      init.headers['X-CSRF-Token'] = csrf();
      init.headers['X-Payload'] = 'b64';
      init.headers['Content-Type'] = 'text/plain;charset=UTF-8';
      init.body = b64(JSON.stringify(body || {}));
    }

    var res;
    try {
      res = await fetch(url, init);
    } catch (e) {
      return { ok: false, code: 'offline', error: FRIENDLY.offline };
    }

    var data = null;
    try { data = await res.json(); } catch (e) { /* رد مش JSON */ }

    if (!data) {
      // غالبًا 403 من mod_security أو صفحة خطأ من السيرفر
      return { ok: false, code: 'http_' + res.status,
               error: res.status === 403 ? 'السيرفر رفض الطلب — جرّب تاني' : FRIENDLY.server_error };
    }
    if (res.status === 401) {
      setTimeout(function () { location.href = BASE + '/login.php'; }, 1200);
    }
    return data;
  }

  window.SpreadAPI = {
    get: function (endpoint, params) { return request('GET', endpoint, params, null); },
    post: function (endpoint, action, data, opts) {
      return request('POST', endpoint, null, Object.assign({ action: action }, data || {}), opts);
    },
  };

  /* ═══════════ الوقت النسبي ═══════════ */
  window.spreadTimeAgo = function (d) {
    if (!d) return '';
    var t = typeof d === 'number' ? d : Date.parse(String(d).replace(' ', 'T'));
    if (isNaN(t)) return '';
    var s = Math.max(0, Math.round((Date.now() - t) / 1000));
    if (s < 10) return 'منذ لحظات';
    if (s < 60) return 'منذ ' + s + ' ثانية';
    var m = Math.round(s / 60);
    if (m < 60) return m === 1 ? 'منذ دقيقة' : (m === 2 ? 'منذ دقيقتين' : 'منذ ' + m + ' دقيقة');
    var h = Math.round(m / 60);
    if (h < 24) return h === 1 ? 'منذ ساعة' : (h === 2 ? 'منذ ساعتين' : 'منذ ' + h + ' ساعات');
    var dd = Math.round(h / 24);
    return dd === 1 ? 'أمس' : 'منذ ' + dd + ' يوم';
  };

  /* ═══════════ SpreadAutosave ═══════════
     const saver = SpreadAutosave({
       collect: () => ({...}),              // البيانات اللي تتحفظ
       save: async (data) => result,        // لازم ترجّع {ok:true} أو {ok:false, code}
       state: store.save,                   // كائن reactive بيتحدّث: {state, at, error}
       delay: 900,
     });
     saver.touch()   // بعد أي تعديل
     saver.flush()   // حفظ فوري
  */
  window.SpreadAutosave = function (o) {
    var delay = o.delay || 900;
    var st = o.state || {};
    st.state = st.state || 'idle';        // idle · dirty · saving · saved · error · offline · conflict
    var timer = null, inflight = null, pending = false, retry = 0;

    async function run(opts) {
      clearTimeout(timer); timer = null;
      if (inflight) { pending = true; return inflight; }
      pending = false;
      st.state = 'saving';
      st.error = '';
      var data = o.collect();
      inflight = (async function () {
        var r = await o.save(data, opts || {});
        inflight = null;
        if (r && r.ok) {
          st.state = 'saved';
          st.at = Date.now();
          retry = 0;
        } else {
          var code = (r && r.code) || 'error';
          st.state = code === 'offline' ? 'offline' : (code === 'conflict' ? 'conflict' : 'error');
          st.error = (r && r.error) || 'تعذّر الحفظ';
          // إعادة محاولة تلقائية للأخطاء المؤقتة
          if ((code === 'offline' || code === 'server_error' || code === 'rate_limit') && retry < 5) {
            retry++;
            timer = setTimeout(run, Math.min(30000, 1500 * Math.pow(2, retry)));
          }
        }
        if (pending) return run();
        return r;
      })();
      return inflight;
    }

    function touch() {
      st.state = 'dirty';
      clearTimeout(timer);
      timer = setTimeout(run, delay);
    }

    // حماية من فقدان البيانات عند قفل الصفحة
    window.addEventListener('beforeunload', function (e) {
      if (st.state === 'dirty' || st.state === 'saving' || st.state === 'error' || st.state === 'offline') {
        run({ keepalive: true });
        e.preventDefault();
        e.returnValue = '';
      }
    });
    window.addEventListener('online', function () {
      if (st.state === 'offline' || st.state === 'error') run();
    });

    return { touch: touch, flush: function () { return run(); }, state: st };
  };

  /* نص حالة الحفظ للواجهة */
  window.spreadSaveLabel = function (s) {
    if (!s) return '';
    switch (s.state) {
      case 'dirty': return 'فيه تعديلات…';
      case 'saving': return 'جارٍ الحفظ...';
      case 'saved': return 'آخر حفظ ' + window.spreadTimeAgo(s.at);
      case 'offline': return 'مفيش اتصال — هنحفظ أول ما يرجع';
      case 'conflict': return 'اتعدّلت من مكان تاني';
      case 'error': return 'تعذّر الحفظ — هنحاول تاني';
      default: return 'كل حاجة محفوظة';
    }
  };

  /* ═══════════ SpreadApp ═══════════ */
  window.SpreadApp = {
    reactive: function (o) { return window.PetiteVue.reactive(o); },
    /**
     * رسالة مؤقتة موحّدة لكل الشاشات: SpreadApp.toast(scope, 'اتحفظ ✓', 'success')
     * كل رسالة جديدة بتلغي مؤقّت اللي قبلها — كان كل شاشة بتعمل setTimeout لوحدها،
     * فمؤقّت الرسالة القديمة كان بيمسح الجديدة قبل ما العميل يقراها.
     */
    toast: function (scope, msg, type, ms) {
      clearTimeout(scope.__toastTimer);
      scope.toast = { msg: msg, type: type || 'success' };
      scope.__toastTimer = setTimeout(function () { scope.toast = null; }, ms || (type === 'danger' ? 4200 : 2800));
    },
    mount: function (selector, scope) {
      if (!window.PetiteVue) { console.error('petite-vue مش محمّل'); return null; }
      var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
      if (!el) return null;
      // مهم: بنضيف الأدوات على نفس الكائن بدل نسخه — عشان أي كود بره
      // (زي الحفظ التلقائي) يفضل ماسك نفس المرجع اللي الواجهة شغالة عليه
      if (!scope.timeAgo) scope.timeAgo = window.spreadTimeAgo;
      if (!scope.saveLabel) scope.saveLabel = window.spreadSaveLabel;
      var app = window.PetiteVue.createApp(scope);
      app.mount(el);
      el.removeAttribute('v-cloak');
      // الحقول بتتملى بعد التحميل، وبتتعاد لما الواجهة تتغير (v-if) —
      // نحدّث عدّادات الحروف بعد أي تغيير في الشاشة
      var recount = function () { if (window.spreadRecountChars) window.spreadRecountChars(el); };
      setTimeout(recount, 0);
      if (window.MutationObserver) {
        var t = null;
        new MutationObserver(function () { clearTimeout(t); t = setTimeout(recount, 30); })
          .observe(el, { childList: true, subtree: true });
      }
      return app;
    },
  };

  // تحديث «آخر حفظ منذ...» كل 20 ثانية
  setInterval(function () {
    document.querySelectorAll('[data-live-time]').forEach(function (n) {
      n.dispatchEvent(new Event('tick'));
    });
  }, 20000);
})();
