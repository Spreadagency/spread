/* ═══════════ «اصنع منشورك الآن» — قمع التجربة ═══════════ */
(function () {
  'use strict';

  var root = document.getElementById('trial');
  if (!root) return;

  var token = '';
  var ideas = [];
  var step = 1;

  function $(id) { return document.getElementById(id); }

  function b64(str) {
    return btoa(String.fromCharCode.apply(null, new TextEncoder().encode(str)))
      .replace(/\+/g, '-').replace(/\//g, '_');
  }

  /* تنقّل بين الخطوات */
  window.trialGo = function (n) {
    step = n;
    root.querySelectorAll('.trial-step').forEach(function (s) {
      s.classList.toggle('act', +s.dataset.step === n);
    });
    root.querySelectorAll('.ts').forEach(function (d) {
      var i = +d.dataset.stepDot;
      d.classList.toggle('on', i === n);
      d.classList.toggle('done', i < n);
    });
    if (n === 4) {
      buildAuthLinks();
      if (window.SpreadOrb) {
        window.SpreadOrb.mount($('tb-orb-design'));
        window.SpreadOrb.setState('idle');
      }
    } else if (window.SpreadOrb && $('tb-load').hidden) {
      window.SpreadOrb.unmount();
    }
    var top = root.getBoundingClientRect().top + window.scrollY - 100;
    if (window.scrollY > top + 120 || window.scrollY < top - 400) {
      window.scrollTo({ top: top, behavior: 'smooth' });
    }
  };

  function load(on, txt) {
    var el = $('tb-load');
    if (!el) return;
    el.hidden = !on;
    if (txt) $('tb-loadtxt').textContent = txt;
    if (!window.SpreadOrb) return;
    if (on) {
      // بيفكر: الأورب بيولع بالباليتة الساخنة
      window.SpreadOrb.mount($('tb-orb'));
      window.SpreadOrb.setState('thinking');
    } else if (step === 4) {
      // رجّعه لمكانه في خطوة التصميم بدل ما يتوقف
      window.SpreadOrb.mount($('tb-orb-design'));
      window.SpreadOrb.setState('idle');
    } else {
      window.SpreadOrb.unmount();
    }
  }

  function err(msg) {
    var e = $('tb-err1');
    if (e) e.textContent = msg || '';
  }

  /* نداء الـ API — الحقول مشفّرة base64 لتفادي حجب mod_security */
  function api(fields, cb) {
    var fd = new FormData();
    var names = [];
    Object.keys(fields).forEach(function (k) {
      var v = fields[k];
      if (v === undefined || v === null) v = '';
      v = String(v);
      // نشفّر النصوص العربية بس — الأرقام والتوكن يفضلوا زي ما هم
      if (k !== 'action' && k !== 'token' && k !== 'idea_index' && v !== '' && /[^\x00-\x7F]/.test(v)) {
        try { fd.append(k, b64(v)); names.push(k); return; } catch (e) {}
      }
      fd.append(k, v);
    });
    if (names.length) fd.append('_b64', names.join(','));

    fetch(window.TRIAL_API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(cb)
      .catch(function () { cb({ ok: false, error: 'مشكلة في الاتصال — جرّب تاني' }); });
  }

  /* ─── خطوة 1 → الأفكار ─── */
  window.trialIdeas = function () {
    var name = ($('tb-name').value || '').trim();
    var ind = ($('tb-industry').value || '').trim();
    err('');
    if (!name) { err('اكتب اسم البيزنس'); $('tb-name').focus(); return; }
    if (!ind) { err('اكتب المجال'); $('tb-industry').focus(); return; }

    load(true, 'بنجهّز أفكار مخصوص لبيزنسك...');
    api({
      action: 'ideas',
      business_name: name,
      industry: ind,
      audience: $('tb-audience').value,
      services: $('tb-services').value,
      tone: $('tb-tone').value,
      dialect: $('tb-dialect').value,
      goal: $('tb-goal').value
    }, function (d) {
      load(false);
      if (!d.ok) {
        err(d.error || 'حصل خطأ');
        if (d.signup) { buildAuthLinks(); trialGo(4); }
        return;
      }
      token = d.token;
      ideas = d.ideas || [];
      $('tb-bizname').textContent = name;
      $('tb-pname').textContent = name;
      $('tb-avatar').textContent = name.trim().charAt(0) || 'ب';
      renderIdeas();
      trialGo(2);
    });
  };

  function renderIdeas() {
    var box = $('tb-ideas');
    box.innerHTML = '';
    ideas.forEach(function (it, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'idea';
      var angle = it.angle ? '<span class="ia">' + esc(it.angle) + '</span>' : '';
      var desc = it.desc ? '<p>' + esc(it.desc) + '</p>' : '';
      b.innerHTML = angle + '<b>' + esc(it.title || ('فكرة ' + (i + 1))) + '</b>' + desc +
        '<span class="igo">اعمل المنشور ده ←</span>';
      b.addEventListener('click', function () { trialPost(i); });
      box.appendChild(b);
    });
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ─── خطوة 2 → المنشور ─── */
  function trialPost(idx) {
    load(true, 'بنكتب منشورك دلوقتي...');
    api({ action: 'post', token: token, idea_index: idx }, function (d) {
      load(false);
      if (!d.ok) {
        if (d.signup) { buildAuthLinks(); trialGo(4); return; }
        alert(d.error || 'حصل خطأ');
        return;
      }
      $('tb-post').textContent = (d.content || '') + (d.cta ? '\n\n' + d.cta : '');
      $('tb-tags').textContent = d.hashtags || '';
      trialGo(3);
    });
  }

  /* ─── نسخ ─── */
  window.trialCopy = function (btn) {
    var t = $('tb-post').innerText + '\n\n' + $('tb-tags').innerText;
    var done = function () {
      var o = btn.textContent;
      btn.textContent = '✓ اتنسخ!';
      setTimeout(function () { btn.textContent = o; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(t).then(done).catch(function () { fb(t, done); });
    } else { fb(t, done); }
    function fb(text, cb) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); cb(); } catch (e) {}
      document.body.removeChild(ta);
    }
  };

  /* ─── روابط التسجيل ومعاها التوكن ─── */
  function buildAuthLinks() {
    var q = token ? (encodeURIComponent(token)) : '';
    var reg = $('tb-reg'), lg = $('tb-login');
    if (reg) reg.href = window.TRIAL_REG + (q ? (window.TRIAL_REG.indexOf('?') > -1 ? '&' : '?') + 'trial=' + q : '');
    if (lg) lg.href = window.TRIAL_LOGIN + (q ? (window.TRIAL_LOGIN.indexOf('?') > -1 ? '&' : '?') + 'trial=' + q : '');
  }

  /* Enter في الحقول ينقل للخطوة اللي بعدها */
  root.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && step === 1 && e.target.tagName === 'INPUT') {
      e.preventDefault();
      window.trialIdeas();
    }
  });
})();
