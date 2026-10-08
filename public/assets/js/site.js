/* شوف نفسك بعد التخسيس — public page logic (vanilla ES6, no build step).
   window.SITE_CONFIG is printed by index.php. When the page is opened as a
   static file (no PHP), the flow runs in demo mode so the design can still
   be reviewed end to end. */
(() => {
  'use strict';

  const LIVE = !!window.SITE_CONFIG;
  const SITE = Object.assign({
    api: 'api/',
    csrf: '',
    whatsapp: '',
    waTemplate: 'مساء الخير، أنا {name}، جربت محاكاة التخسيس وعايز أستفسر عن عملية التكميم.',
    website: '',
    showLogoChip: true,
    pixel: false,
    pixelEvents: {},
    turnstile: '',
    facts: [
      'عملية التكميم بتقلل حجم المعدة حوالي 80%',
      'أغلب المرضى بيرجعوا لحياتهم الطبيعية خلال أسبوع لأسبوعين',
      'العملية بتتم بالمنظار من غير جرح كبير'
    ]
  }, window.SITE_CONFIG || {});

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const params = new URLSearchParams(location.search);
  const csrf = SITE.csrf || $('meta[name=csrf]')?.content || '';
  const state = { name: '', dataUrl: '', genId: 0, result: null, demo: !LIVE, attempts: 0 };

  /* ---------- tracking: browser Pixel + GA4 + dataLayer, mirrored server-side ---------- */
  const uid = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(16) + '-' + Math.random().toString(16).slice(2, 10) + '-4abc-8def-' + Math.random().toString(16).slice(2, 14));
  const FB_NAME = { whatsapp_click: 'Contact', lead: 'Lead', view_result: 'ViewContent', share: 'ShareResult', generate: 'GenerateImage' };
  const STANDARD = ['Contact', 'Lead', 'ViewContent'];
  const SERVER_TYPES = ['whatsapp_click', 'website_click', 'share', 'download', 'call_click', 'directions_click', 'view_result'];

  /**
   * @param type     our event name
   * @param extra    extra params (place…)
   * @param eventId  reuse an id the server already used (Lead) so Meta dedups
   */
  function track(type, extra = {}, eventId = uid()) {
    const fbName = FB_NAME[type];
    try {
      if (window.fbq && fbName && SITE.pixelEvents[fbName] !== false) {
        STANDARD.includes(fbName) ? fbq('track', fbName, {}, { eventID: eventId }) : fbq('trackCustom', fbName, {}, { eventID: eventId });
      }
      if (window.gtag) gtag('event', type, extra);
      (window.dataLayer = window.dataLayer || []).push({ event: type, event_id: eventId, ...extra });
      if (!state.demo && SERVER_TYPES.includes(type)) {
        const body = JSON.stringify({ type, event_id: eventId, csrf, page_url: location.href, ...extra });
        const blob = new Blob([body], { type: 'application/json' });
        if (!(navigator.sendBeacon && navigator.sendBeacon(SITE.api + 'event.php', blob))) {
          fetch(SITE.api + 'event.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body, keepalive: true, credentials: 'same-origin' }).catch(() => {});
        }
      }
    } catch (_) { /* tracking must never break the page */ }
    return eventId;
  }

  /* ---------- API helper ---------- */
  class ApiError extends Error {
    constructor(msg, status, data) { super(msg); this.status = status; this.data = data || {}; }
  }
  async function api(path, { method = 'POST', json, form } = {}) {
    let res;
    try {
      res = await fetch(SITE.api + path, {
        method,
        credentials: 'same-origin',
        headers: json ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : { 'X-CSRF-Token': csrf },
        body: json ? JSON.stringify({ ...json, csrf }) : form
      });
    } catch (e) {
      throw new ApiError('مفيش اتصال بالإنترنت، اتأكد من النت وجرّب تاني.', 0);
    }
    let data = {};
    try { data = await res.json(); } catch (_) { /* non-JSON (e.g. static preview 404) */ }
    if (!res.ok && !(res.status === 202)) {
      throw new ApiError(data.error || data.message || 'حصلت مشكلة، جرّب تاني.', res.status, data);
    }
    return data;
  }

  /* ---------- UTM capture ---------- */
  const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid'];
  try {
    const saved = JSON.parse(sessionStorage.getItem('utm') || '{}');
    UTM_KEYS.forEach(k => { if (params.get(k)) saved[k] = params.get(k); });
    sessionStorage.setItem('utm', JSON.stringify(saved));
  } catch (_) {}
  const utm = () => { try { return JSON.parse(sessionStorage.getItem('utm') || '{}'); } catch (_) { return {}; } };
  const cookie = n => decodeURIComponent((document.cookie.match('(^|;)\\s*' + n + '=([^;]*)') || [])[2] || '');

  /* ---------- WhatsApp + website links ---------- */
  function waHref() {
    if (!SITE.whatsapp) return '#';
    const name = state.name || 'مهتم';
    return 'https://wa.me/' + SITE.whatsapp + '?text=' + encodeURIComponent(SITE.waTemplate.replace('{name}', name));
  }
  function refreshLinks() {
    $$('[data-wa]').forEach(a => { a.href = waHref(); a.target = '_blank'; a.rel = 'noopener'; });
    if (SITE.website) $$('[data-website]').forEach(a => { a.href = SITE.website; });
    else $$('[data-website]').forEach(a => { a.hidden = true; });
  }
  refreshLinks();
  document.addEventListener('click', e => {
    const t = e.target.closest('[data-track]');
    if (t) track(t.dataset.track, t.dataset.place ? { place: t.dataset.place } : {});
  });

  /* ---------- phone normalization (same rules as the server) ---------- */
  function normalizePhone(v) {
    let p = String(v || '')
      .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
      .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
      .replace(/[\s\-().‎‏]/g, '');
    if (p.startsWith('+20')) p = '0' + p.slice(3);
    else if (p.startsWith('0020')) p = '0' + p.slice(4);
    else if (/^20\d{10}$/.test(p)) p = '0' + p.slice(2);
    return p;
  }
  const PHONE_RE = /^01[0125][0-9]{8}$/;

  /* ---------- lead form ---------- */
  const form = $('#leadForm');
  const nameEl = $('#f-name'), phoneEl = $('#f-phone'), consentEl = $('#f-consent');
  const phoneMsg = $('#h-phone'), formError = $('#formError');
  const btnLabel = $('#leadBtn').innerHTML;

  phoneEl.addEventListener('blur', () => {
    if (!phoneEl.value) return;
    const ok = PHONE_RE.test(normalizePhone(phoneEl.value));
    phoneEl.classList.toggle('error', !ok); phoneEl.classList.toggle('ok', ok);
    setMsg(phoneMsg, ok ? 'good' : 'err', ok ? 'رقم صحيح' : 'اكتب رقم موبايل مصري صحيح');
  });
  phoneEl.addEventListener('input', () => { phoneEl.classList.remove('error'); setMsg(phoneMsg, '', 'رقم موبايل مصري من 11 رقم'); });
  nameEl.addEventListener('input', () => { nameEl.classList.remove('error'); $('#e-name').hidden = true; });
  consentEl.addEventListener('change', () => $('#consentWrap').classList.remove('error'));

  function setMsg(el, kind, text) { el.className = 'msg' + (kind ? ' ' + kind : ''); el.textContent = text; }
  function showFormError(text) { if (!formError) return toast(text); formError.hidden = !text; formError.textContent = text || ''; }

  form.addEventListener('submit', async e => {
    e.preventDefault();
    showFormError('');
    const name = nameEl.value.trim();
    const phone = normalizePhone(phoneEl.value);
    let bad = false;
    if (name.length < 2 || name.length > 60) {
      nameEl.classList.add('error'); const m = $('#e-name'); m.hidden = false; m.textContent = 'اكتب اسمك (حرفين على الأقل)'; bad = true;
    }
    if (!PHONE_RE.test(phone)) { phoneEl.classList.add('error'); setMsg(phoneMsg, 'err', 'اكتب رقم موبايل مصري صحيح'); bad = true; }
    if (!consentEl.checked) { $('#consentWrap').classList.add('error'); bad = true; }
    if (bad) { (form.querySelector('.error') || consentEl).focus(); return; }

    const btn = $('#leadBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span><span>ثانية واحدة…</span>';
    state.name = name.split(/\s+/)[0];
    let res = null;
    try {
      if (!state.demo) {
        res = await api('lead.php', {
          json: {
            name, phone, consent: 1, website: form.website.value,
            cf_turnstile: form.querySelector('[name="cf-turnstile-response"]')?.value || '',
            ...utm(), fbp: cookie('_fbp'), fbc: cookie('_fbc'),
            event_id: uid(), page_url: location.href
          }
        });
      }
    } catch (err) {
      btn.disabled = false; btn.innerHTML = btnLabel;
      if (err.data && err.data.fields) {
        if (err.data.fields.name) { nameEl.classList.add('error'); const m = $('#e-name'); m.hidden = false; m.textContent = err.data.fields.name; }
        if (err.data.fields.phone) { phoneEl.classList.add('error'); setMsg(phoneMsg, 'err', err.data.fields.phone); }
        if (err.data.fields.consent) $('#consentWrap').classList.add('error');
      } else {
        showFormError(err.message);
      }
      try { window.turnstile && window.turnstile.reset(); } catch (_) {}
      return;
    }
    btn.disabled = false; btn.innerHTML = btnLabel;

    if (res && res.event_id) track('lead', {}, res.event_id); // same id as the server CAPI event
    else if (state.demo) track('lead');
    if (res && res.lead && res.lead.first_name) state.name = res.lead.first_name;
    refreshLinks();
    $$('[data-first-name]').forEach(el => { el.textContent = state.name; });
    $('#tool').hidden = false;
    if (res && res.result && res.result.status === 'done') {   // returning visitor
      $('#returning').hidden = false;
      showResult(res.result);
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
      const n = +s.dataset.st, done = n < step || (view === 'result' && n === 3);
      s.classList.toggle('done', done);
      s.classList.toggle('cur', n === step && view !== 'result');
      s.querySelector('i').innerHTML = done ? '<svg class="ic sm"><use href="#i-check"/></svg>' : n;
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
  $('#reuploadBtn').addEventListener('click', () => { show('upload'); fileInput.value = ''; state.genId = 0; });

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
      drop.classList.add('error'); err.hidden = false; err.textContent = 'مقدرناش نقرا الصورة دي — جرّب صورة تانية أو صوّرها JPG'; return;
    }
    state.genId = 0;
    $('#previewImg').src = state.dataUrl;
    $('#fileMeta').textContent = 'صورة واحدة · ' + (file.size / 1048576).toFixed(1) + ' ميجا · جاهزة للمحاكاة';
    show('preview');
  }

  // Downscale to max side and re-encode as JPEG (the browser applies EXIF orientation and decodes HEIC where supported).
  function resize(file, max) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => {
        const s = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
        const c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s);
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(img.src);
        resolve(c.toDataURL('image/jpeg', .88));
      };
      img.onerror = reject;
      img.src = URL.createObjectURL(file);
    });
  }
  function dataUrlToBlob(u) {
    const [h, b] = u.split(','), bin = atob(b), arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return new Blob([arr], { type: h.match(/:(.*?);/)[1] });
  }

  /* ---------- generate ---------- */
  let factTimer = null, progTimer = null;
  $('#genBtn').addEventListener('click', generate);
  $('#retryBtn').addEventListener('click', generate);

  function startLoading() {
    show('loading');
    const facts = SITE.facts.length ? SITE.facts : ['ثواني ونوريك النتيجة…'], dots = $('#factDots'), txt = $('#factText');
    dots.innerHTML = facts.map(() => '<i></i>').join('');
    let i = 0;
    const setFact = () => { txt.style.opacity = 0; const k = i % facts.length; setTimeout(() => { txt.textContent = facts[k]; txt.style.opacity = 1; }, 200); $$('i', dots).forEach((d, j) => d.classList.toggle('on', j === k)); i++; };
    clearInterval(factTimer); clearInterval(progTimer);
    setFact(); factTimer = setInterval(setFact, 4000);
    let p = 0;
    progTimer = setInterval(() => { p = Math.min(94, p + Math.max(.35, (94 - p) / 40)); setProgress(p); }, 250);
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
    let out = null;
    try {
      if (state.demo) {
        await new Promise(r => setTimeout(r, 5200));
        const d = params.get('demo');
        out = d === 'error' && state.attempts === 1 ? { status: 'failed' } : ['rejected', 'cap', 'limit'].includes(d) ? { status: d } : { status: 'done', after: null, before: state.dataUrl };
      } else {
        if (!state.genId) {
          const fd = new FormData();
          fd.append('photo', dataUrlToBlob(state.dataUrl), 'photo.jpg');
          fd.append('csrf', csrf);
          const up = await api('upload.php', { form: fd });
          if (up.status === 'done') { out = up; }      // already has a result
          else state.genId = up.id;
        }
        if (!out) {
          const g = await api('generate.php', { json: { id: state.genId, event_id: uid() } });
          out = g.status === 'processing' ? await poll(g.id || state.genId) : g;
        }
      }
    } catch (err) {
      stopLoading();
      if (err.status === 401) { toast('سجّل اسمك ورقمك الأول'); $('#top').scrollIntoView({ behavior: 'smooth' }); return; }
      if (err.data && err.data.code === 'bad_image') { show('upload'); const m = $('#e-file'); m.hidden = false; m.textContent = err.message; drop.classList.add('error'); state.genId = 0; return; }
      return showError(err.data && err.data.status ? err.data.status : 'failed', err.message);
    }
    stopLoading();
    if (!out || out.status !== 'done') {
      if (out && out.status === 'rejected') state.genId = 0; // needs a new photo
      return showError(out ? out.status : 'failed', out && out.message);
    }
    setProgress(100);
    setTimeout(() => showResult(out), 350);
  }

  async function poll(id) {
    const started = Date.now();
    while (Date.now() - started < 200000) {
      await new Promise(r => setTimeout(r, 2000));
      try {
        const s = await api('status.php?id=' + encodeURIComponent(id), { method: 'GET' });
        if (s.status !== 'processing') return s;
      } catch (err) {
        if (err.status && err.status !== 0) throw err; // real error; network blips keep polling
      }
    }
    return { status: 'failed' };
  }

  function showError(kind, message) {
    const copy = {
      failed: ['مقدرناش نكمّل التوليد المرة دي.', 'حصلت مشكلة بسيطة، جرّب تاني', 'ممكن يكون النت ضعيف أو الصورة مش واضحة بما يكفي. جرّب تاني، ولو فضلت المشكلة جرّب صورة تانية بإضاءة أحسن ووش واضح.'],
      rejected: ['الصورة دي مش مناسبة للمحاكاة.', 'محتاجين صورة واضحة لشخص واحد بالغ — جرّب صورة تانية', 'اتأكد إن الصورة فيها شخص واحد بس، ووشه وجسمه ظاهرين بوضوح.'],
      cap: ['الخدمة عليها ضغط دلوقتي.', 'الخدمة عليها ضغط دلوقتي، جرّب بكرة أو كلّم الدكتور على واتساب', 'بياناتك اتسجلت عندنا، وفريق الدكتور هيتواصل معاك. تقدر كمان تكلمنا على واتساب على طول.'],
      limit: ['وصلت للحد المسموح من المحاولات.', 'استخدمت المحاكاة قبل كده من الجهاز ده', 'بياناتك اتسجلت عندنا. لو محتاج تجرب تاني أو عندك سؤال كلّم الدكتور على واتساب.']
    }[kind] || [message || 'حصلت مشكلة.', 'حصلت مشكلة بسيطة، جرّب تاني', message || ''];
    $('#errShort').textContent = copy[0]; $('#errTitle').textContent = copy[1]; $('#errBody').textContent = copy[2];
    $('#retryBtn').hidden = kind !== 'failed';
    $('#reuploadBtn').hidden = !['failed', 'rejected'].includes(kind);
    show('error');
  }

  /* ---------- result ---------- */
  function showResult(r) {
    state.result = r;
    const after = $('#afterLayer'), before = $('#beforeLayer');
    const beforeUrl = r.before || state.dataUrl;
    after.innerHTML = r.after
      ? `<img src="${r.after}" alt="محاكاة بعد التخسيس">`
      : `<img src="${beforeUrl}" alt="محاكاة بعد التخسيس (معاينة)" class="ph-slim">`; // static demo only
    before.innerHTML = beforeUrl ? `<img src="${beforeUrl}" alt="الصورة الأصلية">` : '';
    $('#baMark').hidden = !SITE.showLogoChip;
    show('result');
    track('view_result');
  }

  const ba = $('#ba'), range = $('#baRange');
  range.addEventListener('input', () => ba.style.setProperty('--pos', range.value + '%'));

  $('#dlBtn').addEventListener('click', () => {
    const src = state.result && state.result.after ? state.result.after + '&dl=1' : $('#afterLayer img')?.src;
    if (!src) return;
    track('download');
    const a = document.createElement('a'); a.href = src; a.download = 'slim-simulation'; document.body.appendChild(a); a.click(); a.remove();
  });

  const shareUrl = () => (state.result && state.result.share_url) || (location.origin + location.pathname + '?utm_source=share');
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

  /* ---------- FAQ (server-rendered; static preview fills it from SITE.faqs) ---------- */
  const faqs = $('#faqs');
  if (faqs && !faqs.children.length && Array.isArray(SITE.faqs) && SITE.faqs.length) {
    faqs.innerHTML = SITE.faqs.map(([q, a], i) => `
      <div class="faq${i === 0 ? ' open' : ''}">
        <button class="faq-q" aria-expanded="${i === 0}" aria-controls="fa${i}" id="fq${i}"><span>${q}</span><svg class="ic sm"><use href="#i-plus"/></svg></button>
        <div class="faq-a" id="fa${i}" role="region" aria-labelledby="fq${i}" ${i === 0 ? '' : 'hidden'}>${a}</div>
      </div>`).join('');
  }
  faqs && faqs.addEventListener('click', e => {
    const q = e.target.closest('.faq-q'); if (!q) return;
    const item = q.parentElement, open = !item.classList.contains('open');
    item.classList.toggle('open', open); q.setAttribute('aria-expanded', open); item.querySelector('.faq-a').hidden = !open;
  });

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

  /* ---------- reveal, sticky bar ---------- */
  const io = 'IntersectionObserver' in window ? new IntersectionObserver(es => es.forEach(en => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } }), { threshold: .12 }) : null;
  $$('.reveal').forEach(el => io ? io.observe(el) : el.classList.add('in'));
  const sticky = $('#stickyWa'), hero = $('.hero');
  if (sticky && hero) addEventListener('scroll', () => sticky.classList.toggle('show', hero.getBoundingClientRect().bottom < 0), { passive: true });
  const year = $('#year'); if (year) year.textContent = new Date().getFullYear();

  // Design review shortcuts (static preview only): ?view=upload|preview|loading|error|result
  const v = !LIVE && params.get('view');
  if (v) {
    state.name = 'أحمد'; $('#tool').hidden = false;
    const demoImg = 'assets/img/doctor.webp';
    state.dataUrl = demoImg; $('#previewImg').src = demoImg;
    if (v === 'loading') { startLoading(); setProgress(68); clearInterval(progTimer); }
    else if (v === 'error') showError(params.get('kind') || 'failed');
    else if (v === 'result') showResult({ status: 'done', after: null, before: demoImg });
    else show(v);
    setTimeout(() => $('#tool').scrollIntoView(), 50);
  }
})();
