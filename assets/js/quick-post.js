/* Spread AI — المنشور السريع: خدمة ← شكل ← عميل مثالي ← بوست + CTA + فكرة التصميم */
(function () {
  'use strict';

  function start() {
    var root = document.getElementById('qp');
    if (!root || !window.SpreadAPI) return;
    var $ = function (id) { return document.getElementById(id); };
    var st = { service: 0, format: 'post', persona: 0, last: null, busy: false, opts: null };

    function chip(box, value, label, on) {
      var b = document.createElement('button');
      b.type = 'button'; b.dataset.v = value; b.textContent = label;
      if (on) b.classList.add('on');
      box.appendChild(b);
      return b;
    }
    function single(box, key, cb) {
      box.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b || st.busy) return;
        box.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        st[key] = key === 'format' ? b.dataset.v : (+b.dataset.v || 0);
        if (cb) cb();
      });
    }
    // العميل المثالي اللي مهتم بالخدمة المختارة يتعلّم «مناسب»
    function markPersonas() {
      $('qp-personas').querySelectorAll('button').forEach(function (b) {
        var p = (st.opts.personas || []).filter(function (x) { return +x.id === +b.dataset.v; })[0];
        b.classList.toggle('fit', !!(p && st.service && (p.service_ids || []).indexOf(st.service) > -1));
      });
    }

    async function load() {
      var r = await SpreadAPI.get('quick-post', { action: 'options' });
      if (!r.ok) { $('qp-err').textContent = r.error || 'تعذّر التحميل'; $('qp-err').hidden = false; return; }
      st.opts = r;
      r.services.forEach(function (s) { chip($('qp-services'), s.id, s.name); });
      $('qp-no-svc').hidden = r.services.length > 0;
      Object.keys(r.formats).forEach(function (k) { chip($('qp-formats'), k, r.formats[k][0] + ' ' + r.formats[k][1] + (k === 'carousel' ? ' (' + r.slides + ' صور)' : ''), k === 'post'); });
      r.personas.forEach(function (p) { chip($('qp-personas'), p.id, p.emoji + ' ' + p.name); });
      $('qp-no-per').hidden = r.personas.length > 0;
      if (window.SPREAD_CR !== false && r.cost) $('qp-cost').textContent = '· ' + r.cost + ' كريدت';
      single($('qp-services'), 'service', markPersonas);
      single($('qp-formats'), 'format');
      single($('qp-personas'), 'persona');
    }

    async function create() {
      if (st.busy) return;
      st.busy = true; $('qp-err').hidden = true;
      var go = $('qp-go'), t = go.innerHTML; go.disabled = true; go.textContent = 'بيكتب… ثواني';
      $('qp-again').disabled = true;
      var r = await SpreadAPI.post('quick-post', 'create', { service_id: st.service, format: st.format, persona_id: st.persona, note: $('qp-note').value.trim() });
      st.busy = false; go.disabled = false; go.innerHTML = t; $('qp-again').disabled = false;
      if (!r.ok) { $('qp-err').textContent = r.error || 'تعذّر الكتابة'; $('qp-err').hidden = false; return; }
      st.last = r;
      $('qp-out-fmt').textContent = (st.opts.formats[r.format] || ['', ''])[1];
      $('qp-hook').textContent = r.hook || '—';
      $('qp-body').textContent = r.body || '';
      $('qp-cta').textContent = r.cta || '—';
      $('qp-tags').textContent = r.tags || '';
      $('qp-design').textContent = r.design_idea || '';
      $('qp-design-w').hidden = r.format === 'video';
      $('qp-design-btn').hidden = r.format === 'video';
      var sl = $('qp-slides'); sl.innerHTML = '';
      (r.slides || []).forEach(function (s) {
        var li = document.createElement('li');
        li.innerHTML = '<b></b><span></span><small></small>';
        li.children[0].textContent = s.title; li.children[1].textContent = s.text; li.children[2].textContent = s.design ? '🎨 ' + s.design : '';
        sl.appendChild(li);
      });
      $('qp-slides-w').hidden = !(r.slides || []).length;
      $('qp-script').textContent = r.script || '';
      $('qp-script-w').hidden = !r.script;
      $('qp-open').href = r.url; $('qp-design-btn').href = r.design_url;
      $('qp-out').hidden = false;
      $('qp-out').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    $('qp-go').addEventListener('click', create);
    $('qp-again').addEventListener('click', create);
    $('qp-copy').addEventListener('click', function () {
      var r = st.last; if (!r) return;
      var txt = [r.hook, r.body, r.cta, r.tags].filter(Boolean).join('\n\n');
      (navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(txt) : Promise.reject()).then(
        function () { $('qp-copy').textContent = 'اتنسخ ✓'; setTimeout(function () { $('qp-copy').textContent = 'نسخ'; }, 1500); },
        function () { alert('انسخ يدويًا'); });
    });
    load();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
