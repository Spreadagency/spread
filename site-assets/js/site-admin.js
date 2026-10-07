/* ═══════════════════════════════════════════════════════════
   Spread AI — Website OS · سلوك لوحة الإدارة
   القائمة (تصغير · موبايل) · الدرج/الشيت · التأكيد · الإشعارات · مكتبة الوسائط ·
   البحث اللحظي · الترتيب بالسحب (يتحفظ تلقائيًا) · الإظهار/الإخفاء من غير إعادة تحميل
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var SA = window.SA || {};
  var csrf = function () { return SA.csrf || ($('meta[name="csrf"]') || {}).content || ''; };
  var html = document.documentElement, body = document.body;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  /* ═══ إشعارات ═══ */
  function toast(msg, type) {
    var box = $('[data-toasts]'); if (!box) return;
    var t = document.createElement('div');
    t.className = 'ad-toast t-' + (type || 'success'); t.setAttribute('role', 'status');
    t.innerHTML = '<span>' + esc(msg) + '</span>';
    box.appendChild(t);
    setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 450); }, 3200);
  }
  window.saToast = toast;
  $$('[data-toast]').forEach(function (t) { setTimeout(function () { t.style.transition = 'opacity .5s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 520); }, 6000); });
  document.addEventListener('click', function (e) { var b = e.target.closest('[data-toast-close]'); if (b) b.closest('.ad-toast').remove(); });

  function post(url, data) {
    var fd = new FormData();
    fd.append('csrf', csrf());
    Object.keys(data).forEach(function (k) {
      var v = data[k];
      if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); }); else fd.append(k, v);
    });
    return fetch(url || location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'مشكلة في الاتصال — جرّب تاني' }; });
  }
  window.saPost = post;

  /* ═══ القائمة ═══ */
  function setMenu(open) { body.classList.toggle('ad-menu', open); body.classList.toggle('ad-lock', open); }
  $$('[data-menu-open]').forEach(function (b) { b.addEventListener('click', function () { setMenu(true); var i = $('[data-nav-filter]'); }); });
  $$('[data-menu-close]').forEach(function (b) { b.addEventListener('click', function () { setMenu(false); }); });
  var col = $('[data-collapse]');
  if (col) col.addEventListener('click', function () {
    var on = !html.classList.contains('ad-collapsed');
    html.classList.toggle('ad-collapsed', on);
    try { localStorage.setItem('sa-collapsed', on ? '1' : '0'); } catch (e) {}
  });
  var nf = $('[data-nav-filter]');
  if (nf) nf.addEventListener('input', function () {
    var q = nf.value.trim().toLowerCase();
    $$('.ad-nav[data-nav]').forEach(function (a) { a.hidden = q !== '' && a.getAttribute('data-nav').toLowerCase().indexOf(q) < 0; });
    $$('[data-grp]').forEach(function (g) { g.hidden = q !== ''; });
  });
  // البحث في التوب بار: اختار قسم ← روح له
  var jump = $('[data-jump]');
  if (jump) {
    var go = function () {
      var v = jump.value.trim().toLowerCase(); if (!v) return;
      var hit = $$('#ad-jump-list option').filter(function (o) { return (o.value + ' ' + o.textContent).toLowerCase().indexOf(v) > -1; })[0];
      if (hit) location.href = hit.getAttribute('data-href');
    };
    jump.addEventListener('change', go);
    jump.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
  }
  // قائمة الحساب
  $$('[data-pop]').forEach(function (p) {
    var b = $('[data-pop-btn]', p), m = $('.ad-popm', p);
    b.addEventListener('click', function (e) { e.stopPropagation(); var o = m.hidden; m.hidden = !o; b.setAttribute('aria-expanded', o ? 'true' : 'false'); });
    document.addEventListener('click', function (e) { if (!p.contains(e.target)) { m.hidden = true; b.setAttribute('aria-expanded', 'false'); } });
  });

  /* ═══ الدرج / الشيت ═══ */
  var lastFocus = null;
  function openDrawer(ov) {
    if (!ov) return;
    lastFocus = document.activeElement;
    ov.hidden = false; ov.classList.add('open'); body.classList.add('ad-lock');
    var f = $('input:not([type=hidden]):not([type=file]),textarea,select', ov);
    setTimeout(function () { if (f && window.innerWidth > 900) f.focus({ preventScroll: true }); }, 80);
  }
  function closeDrawer(ov) {
    if (!ov) return;
    ov.hidden = true; ov.classList.remove('open');
    if (!$('.ad-ov.open')) body.classList.remove('ad-lock');
    // الدرج اتفتح من السيرفر (?edit= / ?new=) — شيل البارامتر من غير إعادة تحميل
    if (/[?&](edit|new|add)=/.test(location.search) && history.replaceState) {
      var u = new URL(location.href); u.searchParams.delete('edit'); u.searchParams.delete('new'); u.searchParams.delete('add');
      history.replaceState(null, '', u.pathname + (u.search || ''));
    }
    if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
  }
  window.saOpenDrawer = function (id) { openDrawer($('[data-drawer="' + id + '"]')); };
  window.saCloseDrawer = function (id) { closeDrawer($('[data-drawer="' + id + '"]')); };
  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-open-drawer]');
    if (o) { e.preventDefault(); openDrawer($('[data-drawer="' + o.getAttribute('data-open-drawer') + '"]')); return; }
    var c = e.target.closest('[data-close]');
    if (c) { closeDrawer(c.closest('.ad-ov')); }
  });
  if ($('.ad-ov.open')) body.classList.add('ad-lock');
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var cf = $('[data-confirm-modal]:not([hidden])'); if (cf) { cf.hidden = true; return; }
    var mm = $('[data-media-modal]:not([hidden])'); if (mm) { mm.hidden = true; return; }
    var ov = $$('.ad-ov.open').pop(); if (ov) { closeDrawer(ov); return; }
    if (body.classList.contains('ad-menu')) setMenu(false);
  });

  /* ═══ التأكيد قبل العمليات اللي مش بترجع ═══ */
  var cfm = $('[data-confirm-modal]'), cfmCb = null;
  function confirmBox(msg, yes, cb) {
    if (!cfm) { if (window.confirm(msg)) cb(); return; }
    $('#ad-cf-b', cfm).textContent = msg || 'العملية دي مش هترجع.';
    $('[data-confirm-yes]', cfm).textContent = yes || 'نعم، احذف';
    cfmCb = cb; cfm.hidden = false; $('[data-confirm-yes]', cfm).focus();
  }
  window.saConfirm = confirmBox;
  if (cfm) {
    $('[data-confirm-yes]', cfm).addEventListener('click', function () { cfm.hidden = true; var f = cfmCb; cfmCb = null; if (f) f(); });
    $$('[data-confirm-no]', cfm).forEach(function (b) { b.addEventListener('click', function () { cfm.hidden = true; cfmCb = null; }); });
  }
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.hasAttribute || !f.hasAttribute('data-confirm') || f.dataset.confirmed === '1') return;
    e.preventDefault(); e.stopImmediatePropagation();
    confirmBox(f.getAttribute('data-confirm'), f.getAttribute('data-confirm-yes'), function () { f.dataset.confirmed = '1'; if (f.requestSubmit) f.requestSubmit(); else f.submit(); });
  }, true);

  /* ═══ حالة التحميل على زرار الحفظ ═══ */
  document.addEventListener('submit', function (e) {
    var f = e.target; if (e.defaultPrevented || !f.querySelector) return;
    var b = f.querySelector('button[type=submit].ad-pri');
    if (b && !b.disabled) { setTimeout(function () { b.disabled = true; b.insertAdjacentHTML('afterbegin', '<svg class="sa-spin" width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.6" stroke-dasharray="14 8"/></svg>'); }, 0); }
  });

  /* ═══ رفع صورة: معاينة ═══ */
  $$('[data-drop]').forEach(function (d) {
    var inp = $('input[type=file]', d), pv = $('.ad-drop-pv', d), nm = $('[data-drop-name]', d);
    ['dragenter', 'dragover'].forEach(function (ev) { d.addEventListener(ev, function () { d.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { d.addEventListener(ev, function () { d.classList.remove('drag'); }); });
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0]; if (!f) return;
      nm.textContent = f.name; d.classList.add('has');
      if (/^image\//.test(f.type)) { var r = new FileReader(); r.onload = function () { pv.innerHTML = '<img src="' + r.result + '" alt="">'; }; r.readAsDataURL(f); }
    });
  });

  /* ═══ مكتبة الوسائط: اختيار ═══ */
  var mm = $('[data-media-modal]'), mmTarget = null, mmLoaded = false;
  function mmRender(items) {
    var g = $('[data-media-grid]', mm);
    if (!items.length) { g.innerHTML = '<p class="ad-hint">المكتبة فاضية — ارفع صور من «مكتبة الوسائط».</p>'; return; }
    g.innerHTML = '';
    items.forEach(function (it) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'ad-mi';
      b.innerHTML = '<span class="im" style="background-image:url(\'' + esc(it.url) + '\')"></span><span class="mt"><b>' + esc(it.name || '') + '</b><small>' + esc(it.dim || '') + '</small></span>';
      b.addEventListener('click', function () {
        if (mmTarget) {
          mmTarget.value = it.url;
          mmTarget.dispatchEvent(new Event('input', { bubbles: true }));
          var f = mmTarget.closest('.ad-f'), pv = f && $('.ad-drop-pv', f), nm = f && $('[data-drop-name]', f);
          if (pv) { pv.innerHTML = '<img src="' + esc(it.url) + '" alt="">'; pv.closest('.ad-drop').classList.add('has'); }
          if (nm) nm.textContent = it.name || 'من المكتبة';
        }
        mm.hidden = true;
      });
      g.appendChild(b);
    });
  }
  window.saPickMedia = function (input) {
    if (!mm) return;
    mmTarget = input; mm.hidden = false;
    if (!mmLoaded) {
      fetch(SA.media, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); })
        .then(function (d) { mmLoaded = true; mmRender(d.items || []); })
        .catch(function () { $('[data-media-grid]', mm).innerHTML = '<p class="ad-hint">تعذّر تحميل المكتبة.</p>'; });
    }
  };
  document.addEventListener('click', function (e) {
    var p = e.target.closest('[data-media-pick]');
    if (p) { var f = p.closest('.ad-f') || p.parentNode; window.saPickMedia($('[data-media-target]', f)); }
    if (e.target.closest('[data-media-close]')) mm.hidden = true;
  });

  /* ═══ الأيقونات والألوان ═══ */
  $$('.ad-iconset').forEach(function (set) {
    var inp = $('[data-icon-input]', set.parentNode);
    $$('[data-icon]', set).forEach(function (b) {
      b.addEventListener('click', function () {
        inp.value = b.getAttribute('data-icon');
        $$('[data-icon]', set).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      });
    });
  });
  $$('[data-color-sync]').forEach(function (c) {
    var t = c.parentNode.querySelector('input[type=text]');
    c.addEventListener('input', function () { t.value = c.value; t.dispatchEvent(new Event('input', { bubbles: true })); });
    t.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(t.value)) c.value = t.value; });
  });

  /* ═══ البحث اللحظي في القوائم ═══ */
  $$('[data-filter-list]').forEach(function (inp) {
    var scope = inp.closest('.ad-main') || document;
    var filterSel = $$('[data-filter-select]', scope);
    function run() {
      var q = inp.value.trim().toLowerCase(), n = 0;
      var fv = {};
      filterSel.forEach(function (s) { if (s.value !== '') fv[s.getAttribute('data-filter-select')] = s.value; });
      $$('[data-row]', scope).forEach(function (r) {
        var ok = q === '' || r.textContent.toLowerCase().indexOf(q) > -1;
        Object.keys(fv).forEach(function (k) { if ((r.getAttribute('data-' + k) || '') !== fv[k]) ok = false; });
        r.hidden = !ok; if (ok) n++;
      });
      $$('[data-count]', scope).forEach(function (c) { c.textContent = n; });
    }
    inp.addEventListener('input', run);
    filterSel.forEach(function (s) { s.addEventListener('change', run); });
    if (inp.value) run();
    var form = inp.closest('form'); if (form) form.addEventListener('submit', function (e) { e.preventDefault(); run(); });
  });

  /* ═══ الترتيب بالسحب — بيتحفظ تلقائيًا ═══ */
  $$('[data-sortable]').forEach(function (list) {
    var drag = null, url = list.getAttribute('data-sortable') || location.pathname;
    function rows() { return $$(':scope > [data-id]', list); }
    function renumber() { rows().forEach(function (r, i) { var n = $('[data-n]', r); if (n) n.textContent = i + 1; }); }
    function save() {
      var ids = rows().map(function (r) { return r.getAttribute('data-id'); });
      post(url, { action: 'reorder_ajax', ids: ids }).then(function (d) {
        if (d && d.ok) { toast(d.message || 'تم حفظ الترتيب ✓'); if (window.saAfterReorder) window.saAfterReorder(ids); }
        else toast((d && d.error) || 'تعذّر حفظ الترتيب', 'danger');
      });
    }
    rows().forEach(function (r) {
      var h = $('[data-grab]', r); if (!h) return;
      h.addEventListener('mousedown', function () { r.draggable = true; });
      h.addEventListener('touchstart', function () { r.draggable = true; }, { passive: true });
      r.addEventListener('dragstart', function (e) { drag = r; r.classList.add('ad-dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', r.getAttribute('data-id')); } catch (x) {} });
      r.addEventListener('dragend', function () { r.draggable = false; r.classList.remove('ad-dragging'); $$('.ad-over', list).forEach(function (x) { x.classList.remove('ad-over'); }); if (drag) { drag = null; renumber(); save(); } });
      r.addEventListener('dragover', function (e) {
        if (!drag || drag === r) return; e.preventDefault();
        var b = r.getBoundingClientRect(), after = (e.clientY - b.top) > b.height / 2;
        list.insertBefore(drag, after ? r.nextSibling : r);
      });
    });
    // أسهم فوق/تحت من غير إعادة تحميل
    list.addEventListener('click', function (e) {
      var b = e.target.closest('[data-move]'); if (!b) return;
      e.preventDefault();
      var r = b.closest('[data-id]'), dir = b.getAttribute('data-move');
      var sib = dir === 'up' ? r.previousElementSibling : r.nextElementSibling;
      while (sib && !sib.hasAttribute('data-id')) sib = dir === 'up' ? sib.previousElementSibling : sib.nextElementSibling;
      if (!sib) return;
      list.insertBefore(r, dir === 'up' ? sib : sib.nextSibling);
      renumber(); save();
      b.focus();
    });
  });

  /* ═══ إظهار/إخفاء من غير إعادة تحميل ═══ */
  document.addEventListener('change', function (e) {
    var sw = e.target.closest('[data-toggle-id]'); if (!sw) return;
    var row = sw.closest('[data-id]');
    post(sw.getAttribute('data-url') || location.pathname, { action: sw.getAttribute('data-action') || 'toggle_ajax', id: sw.getAttribute('data-toggle-id'), on: sw.checked ? 1 : 0, key: sw.getAttribute('data-key') || '' })
      .then(function (d) {
        if (d && d.ok) { if (row) row.classList.toggle('off', !sw.checked); toast(d.message || (sw.checked ? 'ظاهر ✓' : 'اتخفى')); if (window.saAfterToggle) window.saAfterToggle(sw); }
        else { sw.checked = !sw.checked; toast((d && d.error) || 'حصل خطأ', 'danger'); }
      });
  });

  /* ═══ نسخ لينك ═══ */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b) return;
    var t = b.getAttribute('data-copy');
    var done = function () { toast('اتنسخ ✓'); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(done);
    else { var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (x) {} ta.remove(); }
  });
})();
