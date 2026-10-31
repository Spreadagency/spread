/**
 * Spread AI — نظام الجودة في الواجهة
 *   [data-report] (+ data-content-id / data-design-id / data-category)  ← «بلّغ عن مشكلة»
 *   [data-design-rate="ID"] (+ data-v)                                  ← 👍 / 👎 للتصميم + السبب
 *   [data-design-download="ID"]                                         ← حدث تحميل التصميم
 *   SpreadQuality.postReasons(el, contentId, value)                     ← سبب تقييم المنشور
 */
(function () {
  'use strict';
  var API = (window.SPREAD_BASE || '') + '/api/quality.php';
  var csrf = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };
  var REASONS = ['مش مناسب للبراند', 'الكلام ضعيف', 'التصميم وحش', 'النص في الصورة غلط', 'مكرر', 'أخرى'];
  var CATS = [['tech', 'مشكلة تقنية'], ['content', 'المحتوى'], ['design', 'التصميم'], ['ux', 'صعوبة في الاستخدام'], ['suggestion', 'اقتراح']];

  function base() {
    var s = document.querySelector('script[src*="assets/js/quality.js"]');
    return s ? s.src.split('/assets/js/quality.js')[0] : '';
  }
  API = base() + '/api/quality.php';

  function post(action, data, file) {
    var fd = new FormData();
    Object.keys(data || {}).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
    if (file) fd.append('screenshot', file);
    return fetch(API + '?action=' + encodeURIComponent(action), {
      method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() }
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'مشكلة في الاتصال' }; }); })
      .catch(function () { return { ok: false, error: 'مشكلة في الاتصال' }; });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function toast(msg, bad) {
    var t = document.createElement('div');
    t.className = 'sq-toast' + (bad ? ' bad' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('out'); }, 3200);
    setTimeout(function () { t.remove(); }, 3700);
  }

  /* ── «بلّغ عن مشكلة» ── */
  var modal = null, ctx = {};
  function buildModal() {
    modal = document.createElement('div');
    modal.className = 'sq-modal';
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'sq-title');
    modal.innerHTML =
      '<div class="sq-sheet">' +
        '<button type="button" class="sq-x" aria-label="إغلاق">×</button>' +
        '<h3 id="sq-title">⚑ بلّغ عن مشكلة</h3>' +
        '<p class="sq-sub" data-sq-target></p>' +
        '<label class="sq-lbl">نوع المشكلة</label>' +
        '<div class="sq-chips" data-sq-cats>' + CATS.map(function (c) { return '<button type="button" data-cat="' + c[0] + '">' + c[1] + '</button>'; }).join('') + '</div>' +
        '<label class="sq-lbl" for="sq-desc">إيه اللي حصل؟</label>' +
        '<textarea id="sq-desc" rows="4" maxlength="3000" placeholder="مثلًا: دوست «ولّد تصميم» والتصميم طلع من غير الكلام العربي"></textarea>' +
        '<label class="sq-lbl" for="sq-file">صورة الشاشة <small>(اختياري — JPG أو PNG أو WEBP لحد 5 ميجا)</small></label>' +
        '<input id="sq-file" type="file" accept="image/jpeg,image/png,image/webp">' +
        '<p class="sq-err" hidden></p>' +
        '<div class="sq-row"><button type="button" class="sq-send">ابعت البلاغ</button><button type="button" class="sq-cancel">إلغاء</button></div>' +
      '</div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.closest('.sq-x') || e.target.closest('.sq-cancel')) return close();
      var c = e.target.closest('[data-cat]');
      if (c) { ctx.category = c.dataset.cat; modal.querySelectorAll('[data-cat]').forEach(function (b) { b.classList.toggle('on', b === c); }); }
      if (e.target.closest('.sq-send')) send();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal && !modal.hidden) close(); });
  }
  function open(el) {
    if (!modal) buildModal();
    ctx = { content_id: el.dataset.contentId || '', design_id: el.dataset.designId || '', category: el.dataset.category || '' };
    modal.querySelectorAll('[data-cat]').forEach(function (b) { b.classList.toggle('on', b.dataset.cat === ctx.category); });
    modal.querySelector('#sq-desc').value = '';
    modal.querySelector('#sq-file').value = '';
    modal.querySelector('.sq-err').hidden = true;
    modal.querySelector('[data-sq-target]').textContent = ctx.design_id ? 'عن التصميم ده' : (ctx.content_id ? 'عن المنشور #' + ctx.content_id : 'بنستقبل أي مشكلة أو اقتراح — وبنرد عليك في صندوق الرسايل');
    modal.hidden = false;
    document.documentElement.classList.add('sq-lock');
    setTimeout(function () { (modal.querySelector('[data-cat].on') ? modal.querySelector('#sq-desc') : modal.querySelector('[data-cat]')).focus(); }, 30);
  }
  function close() { if (modal) modal.hidden = true; document.documentElement.classList.remove('sq-lock'); }
  function err(m) { var e = modal.querySelector('.sq-err'); e.textContent = m; e.hidden = false; }
  function send() {
    var desc = modal.querySelector('#sq-desc').value.trim();
    var f = modal.querySelector('#sq-file').files[0] || null;
    if (!ctx.category) return err('اختار نوع المشكلة');
    if (desc.length < 5) return err('اكتب المشكلة في جملة على الأقل');
    if (f && f.size > 5 * 1024 * 1024) return err('الصورة أكبر من 5 ميجا');
    if (f && ['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) < 0) return err('الصورة لازم تكون JPG أو PNG أو WEBP');
    var b = modal.querySelector('.sq-send');
    b.disabled = true; b.textContent = 'بنبعت…';
    post('issue_create', {
      category: ctx.category, description: desc, content_id: ctx.content_id, design_id: ctx.design_id,
      page_url: location.pathname + location.search, viewport: window.innerWidth + 'x' + window.innerHeight
    }, f).then(function (r) {
      b.disabled = false; b.textContent = 'ابعت البلاغ';
      if (!r.ok) return err(r.error || 'تعذّر الإرسال');
      close();
      toast(r.message || 'وصلنا بلاغك ✓');
    });
  }

  /* ── تقييم التصميم ── */
  function renderRate(el) {
    var v = +el.dataset.v || 0;
    el.innerHTML = '<span class="sq-rate" role="group" aria-label="رأيك في التصميم">' +
      '<button type="button" data-rv="1" aria-pressed="' + (v === 1) + '" class="' + (v === 1 ? 'on' : '') + '" aria-label="التصميم عجبني">👍</button>' +
      '<button type="button" data-rv="-1" aria-pressed="' + (v === -1) + '" class="' + (v === -1 ? 'on' : '') + '" aria-label="التصميم ماعجبنيش">👎</button></span>' +
      '<span class="sq-reasons" hidden></span>';
  }
  function reasonsInto(box, onPick) {
    box.innerHTML = '<small>ليه؟</small> ' + REASONS.map(function (r) { return '<button type="button" data-reason="' + esc(r) + '">' + esc(r) + '</button>'; }).join('');
    box.hidden = false;
    box.onclick = function (e) {
      var b = e.target.closest('[data-reason]');
      if (!b) return;
      box.querySelectorAll('[data-reason]').forEach(function (x) { x.classList.toggle('on', x === b); });
      onPick(b.dataset.reason);
    };
  }
  document.addEventListener('click', function (e) {
    var rep = e.target.closest('[data-report]');
    if (rep) { e.preventDefault(); open(rep); return; }
    var rb = e.target.closest('[data-design-rate] [data-rv]');
    if (rb) {
      var el = rb.closest('[data-design-rate]'), id = el.dataset.designRate;
      var v = +rb.dataset.rv === (+el.dataset.v || 0) ? 0 : +rb.dataset.rv;
      post('design_rate', { design_id: id, value: v }).then(function (r) {
        if (!r.ok) return toast(r.error || 'تعذّر الحفظ', true);
        el.dataset.v = v; renderRate(el);
        if (v !== 0) {
          reasonsInto(el.querySelector('.sq-reasons'), function (reason) {
            post('design_rate', { design_id: id, value: v, reason: reason }).then(function (r2) { if (r2.ok) toast('شكرًا — بنتعلم من رأيك'); });
          });
        }
      });
      return;
    }
    var dl = e.target.closest('[data-design-download]');
    if (dl) post('design_download', { design_id: dl.dataset.designDownload });
  });
  function init() { document.querySelectorAll('[data-design-rate]').forEach(renderRate); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  window.SpreadQuality = {
    open: function (opts) { var d = document.createElement('span'); Object.keys(opts || {}).forEach(function (k) { d.dataset[k] = opts[k]; }); open(d); },
    /** سبب تقييم المنشور (بعد 👍/👎 في لوحة البوست) */
    postReasons: function (box, contentId, value) {
      if (!box) return;
      if (!value) { box.hidden = true; box.innerHTML = ''; return; }
      reasonsInto(box, function (reason) {
        post('post_reason', { content_id: contentId, reason: reason }).then(function (r) { if (r.ok) toast('شكرًا — بنتعلم من رأيك'); });
      });
    },
    refresh: init
  };
})();
