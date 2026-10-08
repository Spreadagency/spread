/* شوف نفسك بعد التخسيس — public page logic (vanilla ES6, no build step).
   SITE holds everything the admin panel controls; the PHP layer will
   print it as JSON. API calls go to /api/*.php; when those endpoints are
   not reachable (static preview), the flow runs in demo mode so the
   design can be reviewed end to end. */
(() => {
  'use strict';

  const SITE = Object.assign({
    whatsapp: '20XXXXXXXXXX',
    waTemplate: 'مساء الخير، أنا {name}، جربت محاكاة التخسيس وعايز أستفسر عن عملية التكميم.',
    website: 'https://example.com',
    api: 'api/',
    showLogoChip: true,
    facts: [
      'عملية التكميم بتقلل حجم المعدة حوالي 80%',
      'أغلب المرضى بيرجعوا لحياتهم الطبيعية خلال أسبوع لأسبوعين',
      'العملية بتتم بالمنظار من غير جرح كبير',
      'المتابعة بعد العملية جزء أساسي من النجاح',
      'نزول الوزن بيحسّن السكر والضغط عند كتير من المرضى'
    ],
    faqs: [
      ['مين المناسب لعملية التكميم؟', 'القرار بيعتمد على مؤشر كتلة الجسم وحالتك الصحية ومحاولاتك السابقة في التخسيس. الدكتور بيحدد في الاستشارة لو العملية مناسبة ليك.'],
      ['العملية بتاخد وقت قد إيه؟', 'العملية نفسها بتاخد في المتوسط من ساعة لساعتين بالمنظار، والإقامة في المستشفى غالبًا يوم أو يومين.'],
      ['هحس بألم بعد العملية؟', 'بيكون فيه ألم بسيط في أول كام يوم وبيتحكم فيه بالمسكنات، ولأنها بالمنظار التعافي بيكون أسرع.'],
      ['التكلفة كام؟', 'التكلفة بتختلف حسب الحالة والمستشفى. كلّمنا على واتساب ونبعتلك التفاصيل.'],
      ['هاكل إزاي بعد العملية؟', 'هتمشي على نظام أكل متدرّج: سوائل، بعدين أكل مهروس، وبعدين أكل طبيعي بكميات صغيرة، مع متابعة مستمرة.'],
      ['الصورة اللي هتطلعلي هي النتيجة الفعلية؟', 'لأ، الصورة محاكاة تخيلية بالذكاء الاصطناعي للتوضيح بس. النتيجة الفعلية بتختلف من شخص لشخص.']
    ]
  }, window.SITE_CONFIG || {});

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const params = new URLSearchParams(location.search);
  const state = { name: '', phone: '', file: null, dataUrl: '', token: '', demo: false, attempts: 0 };

  /* ---------- tracking (Pixel / GA4 / dataLayer + server log) ---------- */
  const uid = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random().toString(16).slice(2));
  function track(type, extra = {}) {
    const eventId = uid();
    const map = { whatsapp_click: 'Contact', lead: 'Lead', view_result: 'ViewContent', share: 'ShareResult', generate: 'GenerateImage' };
    const fbName = map[type];
    try {
      if (window.fbq && fbName) {
        ['Contact', 'Lead', 'ViewContent'].includes(fbName)
          ? fbq('track', fbName, {}, { eventID: eventId })
          : fbq('trackCustom', fbName, {}, { eventID: eventId });
      }
      if (window.gtag) gtag('event', type, extra);
      (window.dataLayer = window.dataLayer || []).push({ event: type, event_id: eventId, ...extra });
      if (!state.demo) navigator.sendBeacon?.(SITE.api + 'event.php', new Blob([JSON.stringify({ type, event_id: eventId, ...extra })], { type: 'application/json' }));
    } catch (_) { /* tracking must never break the page */ }
    return eventId;
  }

  /* ---------- UTM capture ---------- */
  const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid'];
  try {
    const saved = JSON.parse(sessionStorage.getItem('utm') || '{}');
    UTM_KEYS.forEach(k => { if (params.get(k)) saved[k] = params.get(k); });
    sessionStorage.setItem('utm', JSON.stringify(saved));
  } catch (_) {}
  const utm = () => { try { return JSON.parse(sessionStorage.getItem('utm') || '{}'); } catch (_) { return {}; } };
  const cookie = n => (document.cookie.match('(^|;)\\s*' + n + '=([^;]*)') || [])[2] || '';

  /* ---------- WhatsApp + website links ---------- */
  function waHref() {
    const name = state.name || 'مهتم';
    return 'https://wa.me/' + SITE.whatsapp + '?text=' + encodeURIComponent(SITE.waTemplate.replace('{name}', name));
  }
  function refreshLinks() {
    $$('[data-wa]').forEach(a => { a.href = waHref(); a.target = '_blank'; a.rel = 'noopener'; });
    $$('[data-website]').forEach(a => { a.href = SITE.website; });
  }
  refreshLinks();
  document.addEventListener('click', e => {
    const t = e.target.closest('[data-track]');
    if (t) track(t.dataset.track);
  });

  /* ---------- phone normalization (spec §5 step 1) ---------- */
  function normalizePhone(v) {
    let p = String(v || '')
      .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
      .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
      .replace(/[\s\-().]/g, '');
    if (p.startsWith('+20')) p = '0' + p.slice(3);
    else if (p.startsWith('0020')) p = '0' + p.slice(4);
    else if (/^20\d{10}$/.test(p)) p = '0' + p.slice(2);
    return p;
  }
  const PHONE_RE = /^01[0125][0-9]{8}$/;

  /* ---------- lead form ---------- */
  const form = $('#leadForm');
  const nameEl = $('#f-name'), phoneEl = $('#f-phone'), consentEl = $('#f-consent');
  const phoneMsg = $('#h-phone');

  phoneEl.addEventListener('blur', () => {
    if (!phoneEl.value) return;
    const ok = PHONE_RE.test(normalizePhone(phoneEl.value));
    phoneEl.classList.toggle('error', !ok); phoneEl.classList.toggle('ok', ok);
    setMsg(phoneMsg, ok ? 'good' : 'err', ok ? 'رقم صحيح' : 'اكتب رقم موبايل مصري صحيح');
  });
  phoneEl.addEventListener('input', () => { phoneEl.classList.remove('error'); setMsg(phoneMsg, '', 'رقم موبايل مصري من 11 رقم'); });
  nameEl.addEventListener('input', () => { nameEl.classList.remove('error'); $('#e-name').hidden = true; });
  consentEl.addEventListener('change', () => $('#consentWrap').classList.remove('error'));

  function setMsg(el, kind, text) {
    el.className = 'msg' + (kind ? ' ' + kind : '');
    el.textContent = text;
  }

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const name = nameEl.value.trim();
    const phone = normalizePhone(phoneEl.value);
    let bad = false;
    if (name.length < 2 || name.length > 60) {
      nameEl.classList.add('error'); const m = $('#e-name'); m.hidden = false; m.textContent = 'اكتب اسمك (حرفين على الأقل)'; bad = true;
    }
    if (!PHONE_RE.test(phone)) { phoneEl.classList.add('error'); setMsg(phoneMsg, 'err', 'اكتب رقم موبايل مصري صحيح'); bad = true; }
    if (!consentEl.checked) { $('#consentWrap').classList.add('error'); bad = true; }
    if (bad) { (form.querySelector('.error') || consentEl).focus(); return; }
    if (form.website.value) return; // honeypot

    const btn = $('#leadBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span><span>ثانية واحدة…</span>';
    state.name = name; state.phone = phone;
    let res = null;
    try {
      const r = await fetch(SITE.api + 'lead.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': $('meta[name=csrf]')?.content || '' },
        body: JSON.stringify({ name, phone, consent: 1, ...utm(), fbp: cookie('_fbp'), fbc: cookie('_fbc'), event_id: uid() })
      });
      if (!r.ok) throw new Error(r.status);
      res = await r.json();
    } catch (_) { state.demo = true; }
    btn.disabled = false; btn.innerHTML = '<span>ابدأ دلوقتي</span><svg class="ic"><use href="#i-arrow"/></svg>';

    if (res && res.error) { toast(res.error); return; }
    track('lead');
    refreshLinks();
    $$('[data-first-name]').forEach(el => { el.textContent = name.split(/\s+/)[0]; });
    $('#tool').hidden = false;
    if (res && res.result) {               // returning visitor → show previous result
      $('#returning').hidden = false;
      showResult(res.result.after, res.result.before, res.result.token);
    } else {
      show('upload');
    }
    $('#tool').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  /* ---------- views ---------- */
  function show(view) {
    $$('#tool [data-view]').forEach(v => { v.hidden = v.dataset.view !== view; });
    const step = { upload: 2, preview: 2, loading: 3, error: 3, result: 3 }[view];
    $$('.stepper .st').forEach(s => {
      const n = +s.dataset.st;
      s.classList.toggle('done', n < step || (view === 'result' && n === 3));
      s.classList.toggle('cur', n === step && view !== 'result');
      s.querySelector('i').innerHTML = (n < step || (view === 'result' && n === 3)) ? '<svg class="ic sm"><use href="#i-check"/></svg>' : n;
    });
    $$('.stepper .st-line').forEach((l, i) => l.classList.toggle('done', i + 2 <= step));
    $('#live').textContent = { upload: 'ارفع صورتك', preview: 'الصورة جاهزة', loading: 'جاري التوليد', error: 'حصلت مشكلة', result: 'المحاكاة جاهزة' }[view];
  }

  /* ---------- upload ---------- */
  const drop = $('#drop'), fileInput = $('#fileInput'), camInput = $('#camInput');
  drop.addEventListener('click', e => { e.preventDefault(); if (e.target.closest('#camBtn')) camInput.click(); else fileInput.click(); });
  drop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('hover'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('hover'); }));
  drop.addEventListener('drop', e => handleFile(e.dataTransfer.files[0]));
  fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));
  camInput.addEventListener('change', () => handleFile(camInput.files[0]));
  $('#changeBtn').addEventListener('click', () => { show('upload'); fileInput.value = ''; });
  $('#reuploadBtn').addEventListener('click', () => { show('upload'); fileInput.value = ''; });

  async function handleFile(file) {
    const err = $('#e-file');
    err.hidden = true; drop.classList.remove('error');
    if (!file) return;
    const okType = /^image\/(jpeg|png|webp|heic|heif)$/i.test(file.type) || /\.(heic|heif)$/i.test(file.name);
    if (!okType || file.size > 10 * 1024 * 1024) {
      drop.classList.add('error'); err.hidden = false;
      err.textContent = !okType ? 'الملف لازم يكون صورة (JPG, PNG, WEBP, HEIC)' : 'حجم الصورة أكبر من 10 ميجا';
      return;
    }
    try {
      state.dataUrl = await resize(file, 1536);
    } catch (_) {
      drop.classList.add('error'); err.hidden = false; err.textContent = 'مقدرناش نقرا الصورة دي — جرّب صورة تانية'; return;
    }
    state.file = file;
    $('#previewImg').src = state.dataUrl;
    $('#fileMeta').textContent = 'صورة واحدة · ' + (file.size / 1048576).toFixed(1) + ' ميجا · جاهزة للمحاكاة';
    show('preview');
  }

  // Downscale to max side and re-encode (browser applies EXIF orientation on decode).
  function resize(file, max) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => {
        const s = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
        const c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(img.src);
        resolve(c.toDataURL('image/jpeg', .88));
      };
      img.onerror = reject;
      img.src = URL.createObjectURL(file);
    });
  }

  /* ---------- generate ---------- */
  let factTimer = null, progTimer = null;
  $('#genBtn').addEventListener('click', generate);
  $('#retryBtn').addEventListener('click', generate);

  function startLoading() {
    show('loading');
    const facts = SITE.facts, dots = $('#factDots'), txt = $('#factText');
    dots.innerHTML = facts.map(() => '<i></i>').join('');
    let i = 0;
    const setFact = () => { txt.style.opacity = 0; setTimeout(() => { txt.textContent = facts[i % facts.length]; txt.style.opacity = 1; }, 200); $$('i', dots).forEach((d, k) => d.classList.toggle('on', k === i % facts.length)); i++; };
    setFact(); factTimer = setInterval(setFact, 4000);
    let p = 0;
    progTimer = setInterval(() => { p = Math.min(94, p + Math.max(.6, (94 - p) / 18)); setProgress(p); }, 250);
  }
  function stopLoading() { clearInterval(factTimer); clearInterval(progTimer); }
  function setProgress(p) {
    p = Math.round(p);
    $('#pct').textContent = p + '%';
    $('#ringArc').style.strokeDashoffset = 553 - 553 * p / 100;
    $('#bar > i').style.width = p + '%';
    $('#bar').setAttribute('aria-valuenow', p);
  }

  async function generate() {
    if (!state.dataUrl) return show('upload');
    state.attempts++;
    startLoading();
    track('generate');
    let out = null, fail = null;
    if (!state.demo) {
      try {
        const up = await fetch(SITE.api + 'upload.php', { method: 'POST', body: dataUrlToForm(state.dataUrl) });
        if (!up.ok) throw new Error('upload');
        const g = await (await fetch(SITE.api + 'generate.php', { method: 'POST' })).json();
        out = g.status === 'done' ? g : await poll(g.id);
        if (out.status !== 'done') fail = out;
      } catch (_) { state.demo = true; }
    }
    if (state.demo) {
      await new Promise(r => setTimeout(r, 5200));
      if (params.get('demo') === 'error' && state.attempts === 1) fail = { status: 'failed' };
      else if (params.get('demo') === 'rejected') fail = { status: 'rejected' };
      else if (params.get('demo') === 'cap') fail = { status: 'cap' };
    }
    stopLoading();
    if (fail) return showError(fail.status, fail.message);
    setProgress(100);
    setTimeout(() => showResult(out && out.after, state.dataUrl, out && out.token), 350);
  }

  function dataUrlToForm(u) {
    const [h, b] = u.split(','), bin = atob(b), arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    const fd = new FormData(); fd.append('photo', new Blob([arr], { type: h.match(/:(.*?);/)[1] }), 'photo.jpg'); return fd;
  }
  async function poll(id) {
    for (let i = 0; i < 60; i++) {
      await new Promise(r => setTimeout(r, 2000));
      const s = await (await fetch(SITE.api + 'status.php?id=' + encodeURIComponent(id))).json();
      if (s.status !== 'processing') return s;
    }
    return { status: 'failed' };
  }

  function showError(kind, message) {
    const copy = {
      failed: ['مقدرناش نكمّل التوليد المرة دي.', 'حصلت مشكلة بسيطة، جرّب تاني', 'ممكن يكون النت ضعيف أو الصورة مش واضحة بما يكفي. جرّب تاني، ولو فضلت المشكلة جرّب صورة تانية بإضاءة أحسن ووش واضح.'],
      rejected: ['الصورة دي مش مناسبة للمحاكاة.', 'محتاجين صورة واضحة لشخص واحد بالغ — جرّب صورة تانية', 'اتأكد إن الصورة فيها شخص واحد بس، ووشه وجسمه ظاهرين بوضوح.'],
      cap: ['الخدمة عليها ضغط دلوقتي.', 'الخدمة عليها ضغط دلوقتي، جرّب بكرة أو كلّم الدكتور على واتساب', 'بياناتك اتسجلت عندنا، وفريق الدكتور هيتواصل معاك. تقدر كمان تكلمنا على واتساب على طول.']
    }[kind] || [message || 'حصلت مشكلة.', 'حصلت مشكلة بسيطة، جرّب تاني', ''];
    $('#errShort').textContent = copy[0]; $('#errTitle').textContent = copy[1]; $('#errBody').textContent = copy[2];
    $('#retryBtn').hidden = kind !== 'failed';
    show('error');
  }

  /* ---------- result ---------- */
  function showResult(afterUrl, beforeUrl, token) {
    state.token = token || '';
    const after = $('#afterLayer'), before = $('#beforeLayer');
    if (afterUrl) after.innerHTML = `<img src="${afterUrl}" alt="محاكاة بعد التخسيس">`;
    else after.innerHTML = `<img src="${beforeUrl}" alt="محاكاة بعد التخسيس (معاينة)" class="ph-slim">`; // static preview only
    before.innerHTML = `<img src="${beforeUrl}" alt="الصورة الأصلية">`;
    $('#baMark').hidden = !SITE.showLogoChip;
    show('result');
    track('view_result');
  }

  const ba = $('#ba'), range = $('#baRange');
  range.addEventListener('input', () => ba.style.setProperty('--pos', range.value + '%'));

  $('#dlBtn').addEventListener('click', () => {
    const img = $('#afterLayer img'); if (!img) return;
    track('download');
    const a = document.createElement('a'); a.href = img.src; a.download = 'slim-simulation.jpg'; document.body.appendChild(a); a.click(); a.remove();
  });

  const shareUrl = () => new URL((state.token ? 'r/' + state.token : '') + '?utm_source=share', location.href.split('?')[0].replace(/[^/]*$/, '')).href;
  $('#shareBtn').addEventListener('click', async () => {
    track('share');
    const url = shareUrl(), text = 'شوف شكلي بعد التخسيس بالذكاء الاصطناعي — جرّب إنت كمان';
    if (navigator.share && matchMedia('(pointer:coarse)').matches) { try { await navigator.share({ title: document.title, text, url }); return; } catch (_) {} }
    $('#shWa').href = 'https://wa.me/?text=' + encodeURIComponent(text + ' ' + url);
    $('#shFb').href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url);
    openModal('shareModal');
  });
  $('#shCopy').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(shareUrl()); toast('اتنسخ اللينك'); } catch (_) { toast('مقدرناش ننسخ — انسخه يدوي'); }
  });

  /* ---------- FAQ ---------- */
  const faqs = $('#faqs');
  faqs.innerHTML = SITE.faqs.map(([q, a], i) => `
    <div class="faq${i === 0 ? ' open' : ''}">
      <button class="faq-q" aria-expanded="${i === 0}" aria-controls="fa${i}" id="fq${i}"><span>${q}</span><svg class="ic sm"><use href="#i-plus"/></svg></button>
      <div class="faq-a" id="fa${i}" role="region" aria-labelledby="fq${i}" ${i === 0 ? '' : 'hidden'}>${a}</div>
    </div>`).join('');
  faqs.addEventListener('click', e => {
    const q = e.target.closest('.faq-q'); if (!q) return;
    const item = q.parentElement, open = !item.classList.contains('open');
    item.classList.toggle('open', open); q.setAttribute('aria-expanded', open); item.querySelector('.faq-a').hidden = !open;
  });
  const ld = document.createElement('script'); ld.type = 'application/ld+json';
  ld.textContent = JSON.stringify({ '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: SITE.faqs.map(([q, a]) => ({ '@type': 'Question', name: q, acceptedAnswer: { '@type': 'Answer', text: a } })) });
  document.head.appendChild(ld);

  /* ---------- modals + toast ---------- */
  let lastFocus = null;
  function openModal(id) { lastFocus = document.activeElement; const m = $('#' + id); m.hidden = false; m.querySelector('[data-close]').focus(); }
  function closeModal(m) { m.hidden = true; lastFocus && lastFocus.focus(); }
  document.addEventListener('click', e => {
    const o = e.target.closest('[data-open]'); if (o) { e.preventDefault(); openModal(o.dataset.open); }
    const c = e.target.closest('[data-close]'); if (c) closeModal(c.closest('.modal'));
    if (e.target.classList.contains('modal')) closeModal(e.target);
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $$('.modal:not([hidden])').forEach(closeModal); });
  function toast(t) {
    const el = document.createElement('div'); el.className = 'toast'; el.setAttribute('role', 'status'); el.textContent = t;
    document.body.appendChild(el); setTimeout(() => el.remove(), 2600);
  }

  /* ---------- reveal, sticky bar, misc ---------- */
  const io = 'IntersectionObserver' in window ? new IntersectionObserver(es => es.forEach(en => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } }), { threshold: .12 }) : null;
  $$('.reveal').forEach(el => io ? io.observe(el) : el.classList.add('in'));
  const sticky = $('#stickyWa'), hero = $('.hero');
  addEventListener('scroll', () => sticky.classList.toggle('show', hero.getBoundingClientRect().bottom < 0), { passive: true });
  $('#year').textContent = new Date().getFullYear();

  // Design review shortcuts: ?view=upload|preview|loading|error|result
  const v = params.get('view');
  if (v) {
    state.demo = true; state.name = 'أحمد'; $('#tool').hidden = false;
    const demoImg = 'assets/img/doctor.webp';
    state.dataUrl = demoImg; $('#previewImg').src = demoImg;
    if (v === 'loading') { startLoading(); setProgress(68); clearInterval(progTimer); }
    else if (v === 'error') showError(params.get('kind') || 'failed');
    else if (v === 'result') showResult(null, demoImg);
    else show(v);
    setTimeout(() => $('#tool').scrollIntoView(), 50);
  }
})();
