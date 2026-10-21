/* Spread AI — Brand Kit على صفحة البوست (vanilla) */
(function () {
  'use strict';
  function b64(str) {
    var bytes = new TextEncoder().encode(str), bin = '';
    for (var i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return btoa(bin);
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function start() {
    var box = document.getElementById('bkp');
    if (!box) return;
    var D = box.dataset, id = +D.id, st = null;

    function api(method, action, data) {
      var url = D.api + '?action=' + action + (method === 'GET' ? '&content_id=' + id : '');
      var init = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
      if (method === 'POST') {
        init.headers['X-CSRF-Token'] = D.csrf; init.headers['X-Payload'] = 'b64'; init.headers['Content-Type'] = 'text/plain;charset=UTF-8';
        init.body = b64(JSON.stringify(Object.assign({ action: action, content_id: id }, data || {})));
      }
      return fetch(url, init).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'مشكلة في الاتصال' }; });
    }
    function note(t) { document.getElementById('bkp-note').textContent = t || ''; }

    function chip(key, label, value, options, changed) {
      var opts = '<option value="">— زي الهوية —</option>' + options.map(function (o) {
        return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
      }).join('');
      return '<label class="bkp-chip' + (changed ? ' ch' : '') + '"><span>' + esc(label) + ':</span> <b>' + esc(changed ? (options.filter(function (o) { return String(o[0]) === String(value); })[0] || [0, value])[1] : (value || '—')) + '</b>'
        + '<select data-k="' + key + '" aria-label="' + esc(label) + '">' + opts + '</select></label>';
    }

    function render() {
      var b = st.brief || {}, o = st.overrides || {}, op = st.options;
      var h = '';
      h += chip('service', 'الخدمة', o.service_id || b.service || '', op.services.map(function (s) { return [s.id, s.name]; }), 'service_id' in o);
      h += chip('topic', 'الموضوع', o.topic_id || b.topic || '', op.topics.map(function (t) { return [t.id, t.title]; }), 'topic_id' in o);
      h += chip('character', 'الشخصية', 'character_id' in o ? o.character_id : (b.character || ''), [[0, 'من غير شخصية']].concat(op.characters.filter(function (c) { return c.ready; }).map(function (c) { return [c.id, c.name]; })), 'character_id' in o);
      h += chip('format', 'الشكل', o.format || (op.formats[b.format] || ''), Object.keys(op.formats).map(function (k) { return [k, op.formats[k]]; }), 'format' in o);
      h += chip('tone', 'النبرة', o.tone || b.tone || '', op.tones.map(function (t) { return [t, t]; }), 'tone' in o);
      h += chip('length', 'الطول', o.caption_length || (op.lengths[b.caption_length] || ''), Object.keys(op.lengths).map(function (k) { return [k, op.lengths[k]]; }), 'caption_length' in o);
      h += chip('goal', 'الهدف', o.goal || (op.goals[b.goal] || ''), Object.keys(op.goals).map(function (k) { return [k, op.goals[k]]; }), 'goal' in o);
      document.getElementById('bkp-chips').innerHTML = h;

      var why = document.getElementById('bkp-why');
      why.hidden = !(st.reasons && st.reasons.length);
      why.innerHTML = '<li style="list-style:none;font-weight:700;margin-bottom:2px">ليه الـ AI عمل كده؟</li>' + (st.reasons || []).map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('');

      var bits = [];
      if (b.pillar) bits.push('الاتجاه: ' + b.pillar);
      if (b.method) bits.push('طريقة الكتابة: ' + b.method);
      var HK = { question: 'سؤال', story: 'قصة', number: 'رقم', problem: 'مشكلة', myth: 'خرافة شائعة', offer: 'عرض', tip: 'نصيحة' };
      if (b.hook) bits.push('الـ Hook: ' + (HK[b.hook] || b.hook));
      if (st.snapshot && st.snapshot.kit_version) bits.push('اتعمل بنسخة الهوية رقم ' + st.snapshot.kit_version);
      document.getElementById('bkp-brief').innerHTML = bits.length ? '📋 البريف — ' + bits.map(esc).join(' · ') : 'البوست ده اتعمل قبل مركز الهوية — «نسخة تانية» هتطلعه بالهوية.';

      box.querySelectorAll('.bkp-fb button').forEach(function (x) { x.classList.toggle('on', +x.dataset.v === st.feedback); });
    }

    function load() {
      api('GET', 'post_chips').then(function (r) {
        if (!r.ok) return;
        st = r; box.hidden = false; render();
        var ap = document.getElementById('bkp-approve'); ap.href = D.schedule;
      });
    }

    box.addEventListener('change', function (e) {
      var s = e.target.closest('select[data-k]');
      if (!s) return;
      api('POST', 'post_override', { key: s.dataset.k, value: s.value }).then(function (r) {
        if (!r.ok) return note(r.error);
        st.overrides = r.overrides; render();
        note(r.warning || 'اتغيّرت للبوست ده بس — اضغط «نسخة تانية» علشان تطلع بيها');
      });
    });
    box.querySelector('.bkp-fb').addEventListener('click', function (e) {
      var bt = e.target.closest('button[data-v]');
      if (!bt) return;
      var v = st.feedback === +bt.dataset.v ? 0 : +bt.dataset.v;
      api('POST', 'post_feedback', { value: v }).then(function (r) {
        if (!r.ok) return;
        st.feedback = v || null; render(); note(v ? 'شكرًا — قولّنا السبب علشان نتعلم أسرع' : '');
        if (window.SpreadQuality) window.SpreadQuality.postReasons(document.getElementById('bkp-reasons'), id, v);   // سبب التقييم (نظام الجودة)
      });
    });
    document.getElementById('bkp-regen').addEventListener('click', function () {
      if (!confirm('نسخة تانية هتكلفك كريدت إضافية. متأكد؟')) return;
      var btn = this; btn.disabled = true; note('بنكتب نسخة تانية…');
      var fd = new FormData(); fd.append('csrf', D.csrf); fd.append('content_id', id);
      fetch(D.regen, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
        btn.disabled = false;
        if (!r.ok) return note(r.error || 'فشل');
        note('اتكتبت ✓'); setTimeout(function () { location.reload(); }, 600);
      }).catch(function () { btn.disabled = false; note('مشكلة في الاتصال'); });
    });
    load();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
