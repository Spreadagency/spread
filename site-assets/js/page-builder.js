/* ═══════════════════════════════════════════════════════════
   Spread AI — Page Builder (لوحة الموقع)
   الأقسام: إضافة · تعديل · حذف · نسخ · فوق/تحت · سحب · إخفاء/إظهار — والحقول من تعريف البلوكات (site/blocks.php)
   ═══════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var PB = window.PB || {}, T = PB.types || {}, IC = PB.ic || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var form = $('#pb-form'), jsonEl = $('#pb-json'), list = $('#pb-list');
  if (!form || !list) return;
  var blocks = [];
  try { blocks = JSON.parse(jsonEl.value || '[]') || []; } catch (e) { blocks = []; }
  var dirty = false, editIdx = -1, work = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function uid() { return 'b' + Math.random().toString(36).slice(2, 9); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function sync(mark) { jsonEl.value = JSON.stringify(blocks); if (mark !== false) dirty = true; }
  function toast(m, t) { if (window.saToast) window.saToast(m, t); }

  function defaults(type) {
    var d = {};
    (T[type].fields || []).forEach(function (f) {
      var o = f.o || {};
      if (f.t === 'items') d[f.k] = [];
      else if (f.t === 'checkbox') d[f.k] = false;
      else if (f.t === 'number') d[f.k] = o['default'] != null ? o['default'] : (o.min || 0);
      else if (f.t === 'select') d[f.k] = Object.keys(o.options || {})[0] || '';
      else d[f.k] = '';
    });
    return d;
  }
  function summary(b) {
    var d = b.d || {}, keys = ['title', 'key', 'url', 'caption', 'alt'];
    for (var i = 0; i < keys.length; i++) {
      var v = d[keys[i]];
      if (v && typeof v === 'string') {
        if (keys[i] === 'key' && T.section) { var opts = (T.section.fields.filter(function (f) { return f.k === 'key'; })[0] || {}).o || {}; v = (opts.options || {})[v] || v; }
        return v.replace(/<[^>]*>/g, '').slice(0, 80);
      }
    }
    if (d.items && d.items.length) return d.items.length + ' عنصر';
    if (d.html) return (d.html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 60)) || 'HTML';
    if (d.body) return d.body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 70);
    return 'دوس تعديل علشان تكمّل القسم';
  }

  /* ═══ القائمة ═══ */
  function render() {
    list.innerHTML = '';
    $('#pb-count').textContent = blocks.length ? blocks.length + ' قسم' : '';
    if (!blocks.length) {
      list.innerHTML = '<div class="ad-empty"><span class="ad-empty-ic">' + (T.hero ? T.hero.icon : '') + '</span><b>الصفحة لسه فاضية</b><p>ابدأ بقسم Hero أو نص — وتقدر تضيف أسعار وآراء العملاء وأسئلة شائعة وCTA وفيديو وصور وHTML مخصص.</p></div>';
      return;
    }
    blocks.forEach(function (b, i) {
      var t = T[b.type] || { label: b.type, icon: '' };
      var row = document.createElement('div');
      row.className = 'pb-b sa-in' + (b.hidden ? ' off' : '');
      row.setAttribute('data-i', i);
      row.innerHTML = '<span class="ad-ib ad-grab" data-grab title="اسحب لإعادة الترتيب" aria-hidden="true">' + IC.grip + '</span>'
        + '<span class="pb-ic">' + t.icon + '</span>'
        + '<span class="pb-t"><b>' + esc(t.label) + (b.hidden ? ' <span class="ad-chip t-off">مخفي</span>' : '') + '</b><small>' + esc(summary(b)) + '</small></span>'
        + '<span class="ad-acts-in">'
        + '<button type="button" class="ad-ib" data-a="up" aria-label="لفوق" title="لفوق"' + (i === 0 ? ' aria-disabled="true"' : '') + '>' + IC.up + '</button>'
        + '<button type="button" class="ad-ib" data-a="down" aria-label="لتحت" title="لتحت"' + (i === blocks.length - 1 ? ' aria-disabled="true"' : '') + '>' + IC.down + '</button>'
        + '<button type="button" class="ad-ib' + (b.hidden ? '' : ' on') + '" data-a="hide" aria-pressed="' + (b.hidden ? 'false' : 'true') + '" aria-label="' + (b.hidden ? 'إظهار' : 'إخفاء') + '" title="' + (b.hidden ? 'إظهار' : 'إخفاء') + '">' + (b.hidden ? IC.eyeOff : IC.eye) + '</button>'
        + '<button type="button" class="ad-ib" data-a="edit" aria-label="تعديل" title="تعديل">' + IC.edit + '</button>'
        + '<button type="button" class="ad-ib" data-a="dup" aria-label="نسخ" title="نسخ">' + IC.copy + '</button>'
        + '<button type="button" class="ad-ib del" data-a="del" aria-label="حذف" title="حذف">' + IC.trash + '</button></span>';
      list.appendChild(row);
    });
  }
  list.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-a]'); if (!btn) return;
    var row = btn.closest('[data-i]'), i = +row.getAttribute('data-i'), a = btn.getAttribute('data-a');
    if (a === 'up' && i > 0) { var x = blocks[i - 1]; blocks[i - 1] = blocks[i]; blocks[i] = x; }
    else if (a === 'down' && i < blocks.length - 1) { var y = blocks[i + 1]; blocks[i + 1] = blocks[i]; blocks[i] = y; }
    else if (a === 'hide') { blocks[i].hidden = !blocks[i].hidden; toast(blocks[i].hidden ? 'القسم اتخفى (هيتحفظ مع الصفحة)' : 'القسم ظاهر'); }
    else if (a === 'dup') { var c = clone(blocks[i]); c.id = uid(); blocks.splice(i + 1, 0, c); toast('اتعملت نسخة من القسم'); }
    else if (a === 'del') {
      var lbl = (T[blocks[i].type] || {}).label || '';
      (window.saConfirm || function (m, y, cb) { if (confirm(m)) cb(); })('هتحذف قسم «' + lbl + '» من الصفحة — متأكد؟', 'نعم، احذف', function () { blocks.splice(i, 1); sync(); render(); });
      return;
    } else if (a === 'edit') { openEdit(i); return; }
    else return;
    sync(); render();
    var nb = list.querySelector('[data-i="' + (a === 'up' ? i - 1 : a === 'down' ? i + 1 : i) + '"] [data-a="' + a + '"]');
    if (nb) nb.focus();
  });
  // سحب
  var dragI = -1;
  list.addEventListener('mousedown', function (e) { var g = e.target.closest('[data-grab]'); if (g) g.closest('[data-i]').draggable = true; });
  list.addEventListener('dragstart', function (e) { var r = e.target.closest('[data-i]'); if (!r) return; dragI = +r.getAttribute('data-i'); r.classList.add('ad-dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', String(dragI)); } catch (x) {} });
  list.addEventListener('dragover', function (e) { if (dragI < 0) return; e.preventDefault(); $$('.ad-over', list).forEach(function (x) { x.classList.remove('ad-over'); }); var r = e.target.closest('[data-i]'); if (r) r.classList.add('ad-over'); });
  list.addEventListener('drop', function (e) {
    e.preventDefault();
    var r = e.target.closest('[data-i]'); if (!r || dragI < 0) return;
    var to = +r.getAttribute('data-i'); var b = blocks.splice(dragI, 1)[0]; blocks.splice(to, 0, b); dragI = -1; sync(); render(); toast('الترتيب اتغيّر — احفظ الصفحة');
  });
  list.addEventListener('dragend', function () { dragI = -1; $$('[draggable]', list).forEach(function (x) { x.draggable = false; x.classList.remove('ad-dragging'); }); $$('.ad-over', list).forEach(function (x) { x.classList.remove('ad-over'); }); });

  /* ═══ إضافة ═══ */
  var typesBox = $('#pb-types');
  Object.keys(T).forEach(function (k) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'pb-type';
    b.innerHTML = '<span class="pb-ic">' + T[k].icon + '</span>' + esc(T[k].label) + '<small>' + esc(T[k].desc) + '</small>';
    b.addEventListener('click', function () {
      blocks.push({ id: uid(), type: k, hidden: false, d: defaults(k) });
      sync(); render();
      window.saCloseDrawer('pb-types');
      openEdit(blocks.length - 1, true);
    });
    typesBox.appendChild(b);
  });
  $('#pb-add').addEventListener('click', function () { window.saOpenDrawer('pb-types'); });

  /* ═══ تعديل قسم ═══ */
  var box = $('#pb-fields'), isNew = false;
  function fieldHtml(f, val, path) {
    var o = f.o || {}, id = 'pbf-' + path.replace(/[^a-z0-9]/gi, '-'), req = o.req ? ' <i class="ad-req">*</i>' : '';
    var lbl = '<label class="ad-label" for="' + id + '">' + esc(f.l) + req + '</label>';
    var hint = o.hint ? '<span class="ad-hint">' + esc(o.hint) + '</span>' : '';
    var attr = ' id="' + id + '" data-path="' + esc(path) + '"';
    switch (f.t) {
      case 'textarea': return '<div class="ad-f">' + lbl + '<textarea class="ad-in" rows="3"' + attr + '>' + esc(val) + '</textarea>' + hint + '</div>';
      case 'richtext': return '<div class="ad-f">' + lbl + '<textarea class="ad-in" rows="7"' + attr + '>' + esc(val) + '</textarea>' + hint + '</div>';
      case 'code': return '<div class="ad-f">' + lbl + '<textarea class="ad-in" data-code="1" rows="12" spellcheck="false"' + attr + '>' + esc(val) + '</textarea>' + hint + '</div>';
      case 'url': return '<div class="ad-f">' + lbl + '<input class="ad-in" type="text" dir="ltr" placeholder="https://… أو /صفحة أو #قسم"' + attr + ' value="' + esc(val) + '">' + hint + '</div>';
      case 'number': return '<div class="ad-f">' + lbl + '<input class="ad-in" type="number"' + (o.min != null ? ' min="' + o.min + '"' : '') + (o.max != null ? ' max="' + o.max + '"' : '') + attr + ' value="' + esc(val) + '">' + hint + '</div>';
      case 'checkbox': return '<div class="ad-f"><div class="ad-swrow"><span class="ad-label">' + esc(f.l) + '</span><label class="ad-swl"><input type="checkbox" class="ad-swi"' + attr + (val ? ' checked' : '') + '><span class="ad-sw" aria-hidden="true"><span></span></span></label></div></div>';
      case 'select':
        var opts = o.options || {}, h = '<div class="ad-f">' + lbl + '<select class="ad-in"' + attr + '>';
        Object.keys(opts).forEach(function (k) { h += '<option value="' + esc(k) + '"' + (String(val) === k ? ' selected' : '') + '>' + esc(opts[k]) + '</option>'; });
        return h + '</select>' + hint + '</div>';
      case 'image':
        return '<div class="ad-f">' + lbl + '<div class="pb-img">' + (val ? '<img src="' + esc(val) + '" alt="" style="width:100%;max-height:160px;object-fit:cover;border-radius:14px;margin-bottom:8px">' : '')
          + '<div class="ad-drop-x" style="margin:0"><input class="ad-in sm" type="text" dir="ltr" placeholder="لينك صورة" data-media-target' + attr + ' value="' + esc(val) + '">'
          + '<button type="button" class="ad-btn ad-soft sm" data-pick>' + IC.folder + '<span>المكتبة</span></button>'
          + '<label class="ad-btn ad-sec sm" style="cursor:pointer">' + IC.upload + '<span>ارفع</span><input type="file" accept="image/*" data-upload hidden></label></div></div>' + hint + '</div>';
      case 'icon':
        var g = '<div class="ad-f">' + lbl + '<input type="hidden"' + attr + ' value="' + esc(val) + '"><div class="ad-iconset" data-iconset>';
        (PB.icons || []).filter(function (n) { return ['check', 'x', 'arrow', 'arrow-r', 'mouse', 'menu', 'compare'].indexOf(n) < 0; }).forEach(function (n) {
          g += '<button type="button" data-ic="' + n + '" title="' + n + '"' + (val === n ? ' aria-pressed="true"' : '') + '>' + ((PB.iconSvg || {})[n] || esc(n)) + '</button>';
        });
        return g + '</div></div>';
      case 'items':
        var sub = o.fields || [], items = Array.isArray(val) ? val : [], hh = '<div class="ad-f"><span class="ad-label">' + esc(f.l) + '</span><div class="pb-items" data-items="' + esc(path) + '">';
        items.forEach(function (it, n) {
          hh += '<div class="pb-item"><div class="pb-item-h"><b>#' + (n + 1) + '</b>'
            + '<button type="button" class="ad-ib" data-it="up" data-n="' + n + '" aria-label="لفوق"' + (n === 0 ? ' aria-disabled="true"' : '') + '>' + IC.up + '</button>'
            + '<button type="button" class="ad-ib" data-it="down" data-n="' + n + '" aria-label="لتحت"' + (n === items.length - 1 ? ' aria-disabled="true"' : '') + '>' + IC.down + '</button>'
            + '<button type="button" class="ad-ib del" data-it="del" data-n="' + n + '" aria-label="حذف">' + IC.trash + '</button></div>';
          sub.forEach(function (sf) { hh += fieldHtml(sf, it[sf.k] || '', path + '.' + n + '.' + sf.k); });
          hh += '</div>';
        });
        return hh + '<button type="button" class="pb-add" style="height:46px" data-it="add">' + IC.plus + 'إضافة عنصر</button></div></div>';
    }
    return '<div class="ad-f">' + lbl + '<input class="ad-in" type="text"' + attr + ' value="' + esc(val) + '">' + hint + '</div>';
  }
  function paintEdit() {
    var t = T[work.type];
    $('#pbe-t').textContent = (isNew ? 'إضافة: ' : 'تعديل: ') + t.label;
    var h = '<p class="ad-hint" style="margin:0">' + esc(t.desc) + '</p><div class="ad-form one">';
    t.fields.forEach(function (f) { h += fieldHtml(f, work.d[f.k], f.k); });
    box.innerHTML = h + '</div>';
  }
  function getPath(path) { var p = path.split('.'), o = work.d; for (var i = 0; i < p.length - 1; i++) o = o[p[i]]; return [o, p[p.length - 1]]; }
  function setVal(el) {
    var r = getPath(el.getAttribute('data-path'));
    r[0][r[1]] = el.type === 'checkbox' ? el.checked : el.value;
  }
  function openEdit(i, fresh) {
    editIdx = i; isNew = !!fresh; work = clone(blocks[i]);
    paintEdit();
    window.saOpenDrawer('pb-edit');
  }
  box.addEventListener('input', function (e) { if (e.target.hasAttribute('data-path')) setVal(e.target); });
  box.addEventListener('change', function (e) {
    if (e.target.hasAttribute('data-path')) setVal(e.target);
    if (e.target.hasAttribute('data-upload')) {
      var f = e.target.files[0], inp = e.target.closest('.ad-f').querySelector('[data-path]');
      if (!f) return;
      var fd = new FormData(); fd.append('csrf', (window.SA || {}).csrf || ''); fd.append('action', 'upload_ajax'); fd.append('file', f);
      toast('بيرفع الصورة...', 'warning');
      fetch(PB.upload, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) { inp.value = d.url; setVal(inp); paintEdit(); toast('الصورة اترفعت ✓'); } else toast(d.error || 'تعذّر الرفع', 'danger'); })
        .catch(function () { toast('تعذّر الرفع', 'danger'); });
    }
  });
  box.addEventListener('click', function (e) {
    var pk = e.target.closest('[data-pick]');
    if (pk) {
      var inp = pk.closest('.ad-f').querySelector('[data-path]');
      window.saPickMedia(inp);
      var once = function () { setVal(inp); paintEdit(); inp.removeEventListener('input', once); };
      inp.addEventListener('input', once);
      return;
    }
    var ic = e.target.closest('[data-ic]');
    if (ic) { var hid = ic.closest('.ad-f').querySelector('[data-path]'); hid.value = ic.getAttribute('data-ic'); setVal(hid); $$('[data-ic]', ic.parentNode).forEach(function (x) { x.setAttribute('aria-pressed', x === ic ? 'true' : 'false'); }); return; }
    var it = e.target.closest('[data-it]');
    if (it) {
      var path = it.closest('[data-items]').getAttribute('data-items'), r = getPath(path), arr = r[0][r[1]] = r[0][r[1]] || [], n = +it.getAttribute('data-n'), a = it.getAttribute('data-it');
      if (a === 'add') {
        var f = null; T[work.type].fields.forEach(function (x) { if (x.k === path.split('.').pop()) f = x; });
        var row = {}; ((f && f.o && f.o.fields) || []).forEach(function (sf) { row[sf.k] = ''; }); arr.push(row);
      } else if (a === 'del') arr.splice(n, 1);
      else if (a === 'up' && n > 0) { var x = arr[n - 1]; arr[n - 1] = arr[n]; arr[n] = x; }
      else if (a === 'down' && n < arr.length - 1) { var y = arr[n + 1]; arr[n + 1] = arr[n]; arr[n] = y; }
      paintEdit();
    }
  });
  $('#pb-done').addEventListener('click', function () {
    var t = T[work.type], miss = t.fields.filter(function (f) { return f.o && f.o.req && !String(work.d[f.k] || '').trim(); });
    if (miss.length) { toast('«' + miss[0].l + '» مطلوب', 'danger'); var el = box.querySelector('[data-path="' + miss[0].k + '"]'); if (el) el.focus(); return; }
    blocks[editIdx] = work; work = null; sync(); render();
    window.saCloseDrawer('pb-edit');
    toast('القسم اتحدّث — متنساش تحفظ الصفحة');
  });
  // إلغاء إضافة قسم جديد = نشيله
  $$('[data-drawer="pb-edit"] [data-close]').forEach(function (c) {
    c.addEventListener('click', function () { if (isNew && editIdx > -1 && work) { blocks.splice(editIdx, 1); sync(false); render(); } work = null; isNew = false; });
  });

  /* ═══ SEO: معاينة جوجل ═══ */
  function val(n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value.trim() : ''; }
  function serp() {
    var t = val('seo_title') || val('title') || 'عنوان الصفحة', d = val('seo_description') || val('subtitle') || 'وصف الصفحة بيظهر هنا في نتائج البحث.';
    $('#serp-t').textContent = t + ' — Spread AI';
    $('#serp-u').textContent = (PB.site || '') + '/' + (val('slug') || 'example');
    $('#serp-d').textContent = d.slice(0, 160);
  }
  form.addEventListener('input', function (e) { if (e.target.name && e.target.name !== 'blocks_json') { dirty = true; serp(); } });
  form.addEventListener('change', function () { dirty = true; });
  serp();

  /* ═══ معاينة قبل الحفظ ═══ */
  $('#pb-preview').addEventListener('click', function () {
    var data = { blocks: blocks, title: val('title'), slug: val('slug'), subtitle: val('subtitle'), featured_image: val('featured_image'), seo_title: val('seo_title'),
      seo_description: val('seo_description'), status: (form.querySelector('[name=status]:checked') || {}).value || 'draft', show_cta: (form.querySelector('[name=show_cta]') || {}).checked ? 1 : 0 };
    var pf = $('#pb-prev-form');
    pf.dataset.safeDone = '';
    $$('input[name=_b64]', pf).forEach(function (x) { x.remove(); });
    pf.querySelector('[name=payload]').value = JSON.stringify(data);
    if (pf.requestSubmit) pf.requestSubmit(); else pf.submit();
  });

  form.addEventListener('submit', function () { dirty = false; }); // الـ JSON متزامن مع كل تغيير — admin.js بيشفّره قبل الإرسال
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  sync(false);
  render();
})();
