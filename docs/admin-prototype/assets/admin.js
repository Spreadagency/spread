/* لوحة التحكم — high-fidelity interactive prototype (vanilla JS, no build).
   Every screen renders from mock data so the design can be reviewed in
   all states (data / loading / empty / error). The PHP admin will replace
   MOCK with real queries and keep the same markup. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ic = (n, cls = '') => `<svg class="ic ${cls}"><use href="#i-${n}"/></svg>`;
  const fmt = n => n.toLocaleString('en-US');

  /* ---------------- mock data ---------------- */
  let seed = 7;
  const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
  const pick = a => a[Math.floor(rnd() * a.length)];
  const FIRST = ['أحمد', 'محمد', 'منى', 'سارة', 'محمود', 'ياسمين', 'عمرو', 'هبة', 'كريم', 'نورهان', 'إسلام', 'دينا', 'مصطفى', 'رحاب', 'خالد', 'شيماء', 'أسماء', 'حسام', 'مروة', 'طارق'];
  const LAST = ['السيد', 'عبد الله', 'إبراهيم', 'حسن', 'الشناوي', 'المرسي', 'فتحي', 'عادل', 'سليمان', 'رمضان', 'جمال', 'الدسوقي'];
  const CAMPAIGNS = [['facebook', 'sleeve_oct_lal'], ['facebook', 'sleeve_oct_interest'], ['instagram', 'reels_before_after'], ['share', 'organic_share'], ['google', 'brand_search']];
  const STATUSES = [['new', 'جديد'], ['contacted', 'اتكلم'], ['booked', 'حجز'], ['lost', 'مهتمش']];
  const GEN = [['done', 'اتولدت'], ['done', 'اتولدت'], ['done', 'اتولدت'], ['failed', 'فشلت'], ['rejected', 'اترفضت'], ['none', 'من غير صورة']];
  const NOTES = ['', '', 'عايز يعرف التكلفة', 'هتيجي الخميس', 'مش بيرد', 'سأل على التقسيط', '', 'محتاج تحاليل الأول'];
  const now = new Date(2026, 9, 8, 14, 30);
  const LEADS = Array.from({ length: 64 }, (_, i) => {
    const [src, camp] = pick(CAMPAIGNS);
    const st = i < 12 ? STATUSES[0] : pick(STATUSES);
    const d = new Date(now - (i * 5.3 + rnd() * 4) * 3600e3);
    return {
      id: 1240 - i, name: pick(FIRST) + ' ' + pick(LAST),
      phone: '01' + pick(['0', '1', '2', '5']) + String(Math.floor(rnd() * 1e8)).padStart(8, '0'),
      date: d, src, camp, status: st[0], gen: pick(GEN)[0], wa: rnd() > .45, note: pick(NOTES)
    };
  });
  const DAYS = Array.from({ length: 30 }, (_, i) => {
    const d = new Date(now - (29 - i) * 864e5);
    const leads = Math.round(28 + 22 * Math.sin(i / 4) + rnd() * 18 + i * .9);
    return { d, leads, visits: Math.round(leads * (6 + rnd() * 2)) };
  });
  const STATUS_LABEL = Object.fromEntries(STATUSES);
  const GEN_LABEL = { done: 'اتولدت', failed: 'فشلت', rejected: 'اترفضت', none: 'من غير صورة', processing: 'جاري' };
  const dfmt = d => d.toLocaleDateString('ar-EG-u-nu-latn', { day: 'numeric', month: 'short' }) + ' · ' + d.toLocaleTimeString('ar-EG-u-nu-latn', { hour: 'numeric', minute: '2-digit' });

  /* ---------------- state ---------------- */
  const S = { route: 'dashboard', viewState: 'data', selected: new Set(), filters: { status: '', camp: '', hasImg: false, wa: false }, q: '', page: 1 };

  /* ---------------- shell ---------------- */
  const TITLES = { dashboard: 'لوحة التحكم', leads: 'المسجلين', images: 'الصور', seo: 'SEO', tracking: 'التتبع (Pixel)', integrations: 'الربط والـ API', content: 'محتوى الصفحة', settings: 'الإعدادات', components: 'شيت الكومبوننتس' };
  const app = $('#app');
  $('#collapseBtn').onclick = () => app.classList.toggle('collapsed');
  $('#menuBtn').onclick = () => app.classList.toggle('expanded');
  document.addEventListener('click', e => { if (app.classList.contains('expanded') && !e.target.closest('.sidebar,#menuBtn')) app.classList.remove('expanded'); });
  document.addEventListener('keydown', e => {
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { e.preventDefault(); $('#globalSearch').focus(); }
    if (e.key === 'Escape') closeLayer();
  });
  $('#globalSearch').addEventListener('keydown', e => { if (e.key === 'Enter') { S.q = e.target.value; location.hash = 'leads'; render(); } });
  $('#dateBtn').onclick = () => {
    const opts = ['النهارده', 'آخر 7 أيام', 'آخر 30 يوم', 'الشهر ده', 'مدة مخصصة…'];
    const cur = $('#dateLabel').textContent;
    $('#dateLabel').textContent = opts[(opts.indexOf(cur) + 1) % 4];
    toast('info', 'اتغيرت المدة', $('#dateLabel').textContent);
  };
  $$('#stateBar button').forEach(b => b.onclick = () => {
    S.viewState = b.dataset.state; $$('#stateBar button').forEach(x => x.classList.toggle('on', x === b)); render();
    if (S.viewState === 'error') toast('err', 'مقدرناش نحمّل البيانات', 'اتأكد من الاتصال وجرّب تاني.');
  });

  addEventListener('hashchange', () => { S.selected.clear(); render(); $('#view').focus({ preventScroll: true }); scrollTo(0, 0); });

  function render() {
    S.route = (location.hash.slice(1).split('?')[0]) || 'dashboard';
    if (!VIEWS[S.route]) S.route = 'dashboard';
    $('#pageTitle').textContent = TITLES[S.route];
    document.title = TITLES[S.route] + ' — لوحة التحكم';
    $$('#nav a').forEach(a => a.classList.toggle('active', a.dataset.route === S.route));
    $('#stateBar').hidden = !['dashboard', 'leads', 'images'].includes(S.route);
    app.classList.remove('expanded');
    $('#newCount').textContent = LEADS.filter(l => l.status === 'new').length;
    $('#view').innerHTML = VIEWS[S.route]();
    (BIND[S.route] || (() => {}))();
  }

  /* ---------------- toasts / modals / drawer ---------------- */
  function toast(kind, title, text = '') {
    const icon = { ok: 'check', err: 'alert', info: 'info' }[kind];
    const el = document.createElement('div');
    el.className = 'toast ' + kind; el.setAttribute('role', kind === 'err' ? 'alert' : 'status');
    el.innerHTML = `<span class="ti">${ic(icon)}</span><div><b>${esc(title)}</b>${text ? `<p>${esc(text)}</p>` : ''}</div><button class="x" aria-label="إغلاق">${ic('x', 'sm')}</button>`;
    el.querySelector('.x').onclick = () => el.remove();
    $('#toasts').appendChild(el); setTimeout(() => el.remove(), 4200);
  }
  let lastFocus = null;
  function openLayer(html) { lastFocus = document.activeElement; $('#layer').innerHTML = html; const f = $('#layer [data-autofocus]') || $('#layer button'); f && f.focus(); }
  function closeLayer() { $('#layer').innerHTML = ''; lastFocus && lastFocus.focus && lastFocus.focus(); }
  $('#layer').addEventListener('click', e => { if (e.target.classList.contains('scrim') || e.target.classList.contains('modal') || e.target.closest('[data-close]')) closeLayer(); });

  function confirmDelete({ title, body, cta = 'امسح', onYes }) {
    openLayer(`<div class="modal" role="alertdialog" aria-modal="true" aria-labelledby="cfT"><div class="modal-box">
      <span class="modal-ico">${ic('trash', 'lg')}</span>
      <h3 id="cfT">${esc(title)}</h3><p class="muted">${esc(body)}</p>
      <label class="row small muted"><input type="checkbox" class="cbx" id="cfChk"> فاهم إن المسح نهائي ومش هينفع أرجّعه</label>
      <div class="row" style="justify-content:flex-end"><button class="btn btn-secondary" data-close data-autofocus>إلغاء</button><button class="btn btn-danger" id="cfYes" disabled>${ic('trash', 'sm')}${esc(cta)}</button></div>
    </div></div>`);
    $('#cfChk').onchange = e => { $('#cfYes').disabled = !e.target.checked; };
    $('#cfYes').onclick = () => { closeLayer(); onYes && onYes(); };
  }

  /* ---------------- small builders ---------------- */
  const badge = s => `<span class="badge b-${s}">${STATUS_LABEL[s]}</span>`;
  const genBadge = g => g === 'done' ? `<span class="status ok"><i></i>${GEN_LABEL[g]}</span>` : g === 'none' ? `<span class="status"><i></i>${GEN_LABEL[g]}</span>` : `<span class="status err"><i></i>${GEN_LABEL[g]}</span>`;
  const initials = n => n.split(' ').map(w => w[0]).slice(0, 2).join('');
  const phoneCell = p => `<span class="phone"><span class="ltr mono">${p.slice(0, 3)} ${p.slice(3, 7)} ${p.slice(7)}</span><button class="wa-mini" data-wa="${p}" title="افتح واتساب" aria-label="واتساب">${ic('wa', 'sm')}</button><button class="copy-mini" data-copy="${p}" title="انسخ الرقم" aria-label="انسخ الرقم">${ic('copy', 'sm')}</button></span>`;
  const srcCell = l => `<span class="src"><span>${esc(l.src)}</span><small class="ltr">${esc(l.camp)}</small></span>`;

  // silhouettes used as image placeholders (no real patient photos in the prototype)
  const silhouette = (slim, tone = 0) => {
    const bg = ['#E8F3FB', '#EEF2F6', '#F3EEE6', '#E9F2EE'][tone % 4];
    const fill = slim ? '#1F73B7' : '#A5CFEE';
    const body = slim
      ? 'M100 84C122 84 139 98 142 124C146 154 147 180 143 204C140 226 138 262 137 340L109 340L104 246L96 246L91 340L63 340C62 262 60 226 57 204C53 180 54 154 58 124C61 98 78 84 100 84Z'
      : 'M100 84C128 84 150 98 154 126C160 160 168 188 162 214C158 236 150 262 148 340L112 340L106 252L94 252L88 340L52 340C50 262 42 236 38 214C32 188 40 160 46 126C50 98 72 84 100 84Z';
    return `<svg viewBox="0 0 200 266" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="200" height="266" fill="${bg}"/><circle cx="100" cy="150" r="90" fill="#fff" opacity=".6"/><g transform="translate(0,-40) scale(1)" fill="${fill}"><circle cx="100" cy="86" r="24"/><path transform="translate(0,40) scale(1 .9)" d="${body}"/></g></svg>`;
  };
  const pair = tone => `<div class="pair"><figure>${silhouette(false, tone)}<figcaption>قبل</figcaption></figure><figure class="after">${silhouette(true, tone)}<figcaption>بعد</figcaption></figure></div>`;

  const spark = (vals, color = '#1F73B7') => {
    const w = 120, h = 32, mx = Math.max(...vals), mn = Math.min(...vals);
    const pts = vals.map((v, i) => [w - i * (w / (vals.length - 1)), h - 3 - (v - mn) / (mx - mn || 1) * (h - 6)]);
    return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" width="100%"><path d="M${pts.map(p => p.join(',')).join('L')}" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/></svg>`;
  };

  // RTL line chart: newest day on the left (time flows right→left)
  function lineChart(data) {
    const W = 760, H = 260, P = { t: 16, r: 40, b: 28, l: 24 };
    const max = Math.ceil(Math.max(...data.map(d => d.leads)) / 20) * 20;
    const x = i => W - P.r - i * ((W - P.r - P.l) / (data.length - 1));
    const y = v => P.t + (H - P.t - P.b) * (1 - v / max);
    const line = data.map((d, i) => `${x(i)},${y(d.leads)}`).join(' L');
    const grid = [0, .25, .5, .75, 1].map(f => `<line x1="${P.l}" x2="${W - P.r}" y1="${y(max * f)}" y2="${y(max * f)}" stroke="#E2EAF2" ${f ? 'stroke-dasharray="3 4"' : ''}/><text x="${W - P.r + 8}" y="${y(max * f) + 4}" font-size="11" fill="#8597A8" text-anchor="start">${Math.round(max * f)}</text>`).join('');
    const labels = data.map((d, i) => i % 5 === 0 || i === data.length - 1 ? `<text x="${x(i)}" y="${H - 6}" font-size="11" fill="#8597A8" text-anchor="middle">${d.d.getDate()}/${d.d.getMonth() + 1}</text>` : '').join('');
    const dots = data.map((d, i) => `<g class="pt"><circle cx="${x(i)}" cy="${y(d.leads)}" r="10" fill="transparent"/><circle cx="${x(i)}" cy="${y(d.leads)}" r="3.5" fill="#fff" stroke="#1F73B7" stroke-width="2"><title>${d.d.getDate()}/${d.d.getMonth() + 1}: ${d.leads} ليد</title></circle></g>`).join('');
    return `<svg viewBox="0 0 ${W} ${H}" width="100%" height="100%" preserveAspectRatio="none" role="img" aria-label="عدد الليدز في اليوم لآخر 30 يوم">
      <defs><linearGradient id="lg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1F73B7" stop-opacity=".22"/><stop offset="1" stop-color="#1F73B7" stop-opacity="0"/></linearGradient></defs>
      ${grid}<path d="M${line} L${x(data.length - 1)},${y(0)} L${x(0)},${y(0)}Z" fill="url(#lg)"/><path d="M${line}" fill="none" stroke="#1F73B7" stroke-width="2.5" stroke-linejoin="round"/>${dots}${labels}</svg>`;
  }
  function campaignBars() {
    const rows = [['sleeve_oct_lal', 412, 9.8], ['sleeve_oct_interest', 318, 8.1], ['reels_before_after', 241, 11.4], ['organic_share', 126, 0], ['brand_search', 88, 6.3]];
    const max = rows[0][1];
    return `<div class="stack" style="gap:14px">${rows.map(([n, v, cpl], i) => `
      <div class="stack-8" style="gap:6px"><div class="row between small"><span class="ltr mono" style="color:var(--ink);font-weight:600">${n}</span><span class="muted">${cpl ? `<span class="ltr">$${cpl}</span> / ليد · ` : ''}<b style="color:var(--ink)">${v}</b> ليد</span></div>
      <div class="bar" style="height:12px"><i style="width:${v / max * 100}%;background:${i === 2 ? 'var(--gold)' : 'var(--primary)'}"></i></div></div>`).join('')}</div>`;
  }

  const skelRows = n => Array.from({ length: n }, () => `<tr>${'<td><div class="skel" style="height:14px;width:' + (40 + rnd() * 50) + '%"></div></td>'.repeat(7)}</tr>`).join('');
  const emptyBlock = (icon, title, body, cta = '') => `<div class="empty"><span class="eico">${ic(icon, 'lg')}</span><h3>${title}</h3><p>${body}</p>${cta}</div>`;
  const errorBlock = `<div class="card-b"><div class="err-state">${ic('alert')}<div class="stack-8"><b>مقدرناش نحمّل البيانات</b><span class="small">السيرفر مردّش في الوقت المحدد (timeout). جرّب تاني، ولو المشكلة فضلت راجع صفحة اللوجز.</span><div><button class="btn btn-sm btn-danger-ghost" data-retry>${ic('refresh', 'sm')}حاول تاني</button></div></div></div></div>`;

  /* =========================================================
     VIEWS
     ========================================================= */
  const VIEWS = {};
  const BIND = {};

  /* ---------- 1. Dashboard ---------- */
  VIEWS.dashboard = () => {
    const st = S.viewState;
    const tot = DAYS.reduce((a, d) => a + d.leads, 0), vis = DAYS.reduce((a, d) => a + d.visits, 0);
    const kpis = [
      ['زوار', fmt(vis), '+12.4%', 'up', 'eye', '', DAYS.map(d => d.visits)],
      ['ليدز', fmt(tot), '+18.1%', 'up', 'users', '', DAYS.map(d => d.leads)],
      ['صور اتولدت', fmt(Math.round(tot * .78)), '+9.6%', 'up', 'image', '', DAYS.map(d => d.leads * .8)],
      ['ضغطات واتساب', fmt(Math.round(tot * .41)), '-3.2%', 'down', 'wa', 'green', DAYS.map(d => d.leads * (.3 + rnd() * .2))],
      ['نسبة التحويل', ((tot / vis) * 100).toFixed(1) + '%', '+1.3 نقطة', 'up', 'pct', 'gold', DAYS.map(d => d.leads / d.visits)]
    ];
    const kpiHtml = st === 'loading'
      ? kpis.map(() => `<div class="card kpi"><div class="skel" style="height:40px;width:40px"></div><div class="skel" style="height:12px;width:50%"></div><div class="skel" style="height:28px;width:70%"></div><div class="skel" style="height:32px"></div></div>`).join('')
      : kpis.map(([l, v, d, dir, i, tone, s], k) => `<div class="card kpi ${k === 4 ? 'accent' : ''}">
          <div class="top"><span class="ico ${tone}">${ic(i)}</span><span class="delta ${dir}">${ic(dir === 'up' ? 'up' : 'dn', 'sm')}<span class="ltr">${st === 'empty' ? '—' : d}</span></span></div>
          <span class="lab">${l}</span><span class="val ltr" style="text-align:right">${st === 'empty' ? '0' : v}</span>
          ${st === 'empty' ? '<div class="spark"></div>' : spark(s, k === 4 ? '#C9A24B' : k === 3 ? '#137A4B' : '#1F73B7')}
        </div>`).join('');
    const body = (inner, h = 280) => st === 'loading' ? `<div class="skel" style="height:${h}px"></div>` : st === 'error' ? errorBlock : st === 'empty' ? emptyBlock('spark', 'لسه مفيش بيانات', 'أول ما الإعلانات تبدأ وتيجي زيارات، الأرقام هتظهر هنا.') : inner;
    const funnel = [['زيارة', vis], ['ليد', tot], ['رفع صورة', Math.round(tot * .86)], ['توليد', Math.round(tot * .78)], ['واتساب', Math.round(tot * .41)]];
    return `
    <div class="page-head"><div><h2 class="greet">صباح الخير يا أدمن</h2><p>ده ملخص الحملة <span id="rangeTxt">لآخر 30 يوم</span> — آخر تحديث من دقيقتين.</p></div>
      <div class="row"><a class="btn btn-secondary" href="../" target="_blank" rel="noopener">${ic('globe', 'sm')}افتح الصفحة</a><button class="btn btn-primary" data-export>${ic('excel', 'sm')}تقرير Excel</button></div></div>
    <section class="kpis" aria-label="المؤشرات">${kpiHtml}</section>
    <section class="grid g-2-1">
      <div class="card"><div class="card-h"><div><h2>الليدز في اليوم</h2><p>عدد المسجلين الجدد كل يوم</p></div><div class="seg"><button class="on">يومي</button><button>أسبوعي</button></div></div>
        <div class="card-b"><div class="chart-box">${body(lineChart(DAYS))}</div></div></div>
      <div class="card"><div class="card-h"><div><h2>القمع (Funnel)</h2><p>من الزيارة لحد واتساب</p></div></div>
        <div class="card-b">${body(`<div class="funnel">${funnel.map(([l, v], i) => `<div class="fn-row"><span class="lab">${l}</span><span class="fn-bar"><i style="width:${Math.max(4, v / funnel[0][1] * 100)}%"></i></span><span class="pct"><b class="ltr">${fmt(v)}</b><small>${i ? (v / funnel[i - 1][1] * 100).toFixed(0) + '%' : '100%'}</small></span></div>`).join('')}</div>
          <p class="small muted" style="margin-top:16px">${ic('info', 'sm')} أكبر نزول بين <b style="color:var(--ink)">زيارة → ليد</b>. جرّب تغيّر عنوان الهيرو أو صورة الإعلان.</p>`, 260)}</div></div>
    </section>
    <section class="grid g-1-1">
      <div class="card"><div class="card-h"><div><h2>الليدز حسب الحملة</h2><p>من <span class="ltr mono">utm_campaign</span></p></div></div><div class="card-b">${body(campaignBars(), 220)}</div></div>
      <div class="card"><div class="card-h"><div><h2>استهلاك الـ API النهارده</h2><p>Gemini · الحد اليومي 300 صورة</p></div><span class="status ok"><i></i>شغال</span></div>
        <div class="card-b">${body(`<div class="stack">
          <div class="row between"><span class="val ltr" style="font-size:32px;font-weight:700;color:var(--ink)">184 <span class="muted" style="font-size:16px">/ 300</span></span><span class="badge b-gold">61% من الحد</span></div>
          <div class="bar gold" style="height:12px"><i style="width:61%"></i></div>
          <div class="grid g3" style="gap:12px">
            <div class="card" style="padding:12px;box-shadow:none"><span class="small muted">نجحت</span><b class="ltr" style="display:block;font-size:20px;color:var(--success)">171</b></div>
            <div class="card" style="padding:12px;box-shadow:none"><span class="small muted">فشلت</span><b class="ltr" style="display:block;font-size:20px;color:var(--danger)">9</b></div>
            <div class="card" style="padding:12px;box-shadow:none"><span class="small muted">اترفضت</span><b class="ltr" style="display:block;font-size:20px;color:var(--warn)">4</b></div>
          </div>
          <p class="small muted">متوسط وقت التوليد <b class="ltr" style="color:var(--ink)">14.2s</b> · بيتجدد الساعة 12 بالليل (Africa/Cairo)</p></div>`, 220)}</div></div>
    </section>
    <section class="card">
      <div class="card-h"><div><h2>آخر 10 مسجلين</h2><p>بيتحدث تلقائي</p></div><a href="#leads" class="btn btn-ghost btn-sm">كل المسجلين ${ic('left', 'sm')}</a></div>
      ${st === 'error' ? errorBlock : st === 'empty' ? emptyBlock('users', 'لسه مفيش مسجلين', 'أول ما حد يسجل اسمه ورقمه في الصفحة هيظهر هنا على طول.', '<a class="btn btn-secondary" href="../" target="_blank">' + ic('globe', 'sm') + 'جرّب الصفحة بنفسك</a>') : `
      <div class="table-wrap"><table class="t"><thead><tr><th>الاسم</th><th>الرقم</th><th>التاريخ</th><th class="hide-tab">المصدر / الحملة</th><th>الصورة</th><th>الحالة</th></tr></thead>
      <tbody>${st === 'loading' ? skelRows(6) : LEADS.slice(0, 10).map(l => `<tr data-lead="${l.id}"><td><span class="name"><span class="avatar sm">${initials(l.name)}</span>${esc(l.name)}</span></td><td>${phoneCell(l.phone)}</td><td class="muted">${dfmt(l.date)}</td><td class="hide-tab">${srcCell(l)}</td><td>${genBadge(l.gen)}</td><td>${badge(l.status)}</td></tr>`).join('')}</tbody></table></div>`}
    </section>`;
  };
  BIND.dashboard = () => bindTableCommon();

  /* ---------- 2. Leads ---------- */
  function filteredLeads() {
    const f = S.filters, q = S.q.trim();
    return LEADS.filter(l => (!f.status || l.status === f.status) && (!f.camp || l.camp === f.camp) && (!f.hasImg || l.gen === 'done') && (!f.wa || l.wa) && (!q || l.name.includes(q) || l.phone.includes(q.replace(/\D/g, '') || '@@')));
  }
  VIEWS.leads = () => {
    const st = S.viewState, list = st === 'empty' ? [] : filteredLeads(), per = 12;
    const pages = Math.max(1, Math.ceil(list.length / per)); S.page = Math.min(S.page, pages);
    const rows = list.slice((S.page - 1) * per, S.page * per);
    const counts = Object.fromEntries(STATUSES.map(([k]) => [k, LEADS.filter(l => l.status === k).length]));
    const f = S.filters;
    return `
    <div class="page-head"><div><h2>المسجلين</h2><p><b class="ltr" style="color:var(--ink)">${LEADS.length}</b> مسجل · <b style="color:var(--primary-700)">${counts.new}</b> جديد محتاجين تواصل</p></div>
      <div class="row"><button class="btn btn-secondary" data-export>${ic('excel', 'sm')}Export Excel</button></div></div>
    <div class="grid" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:12px">
      ${STATUSES.map(([k, l]) => `<button class="card" data-fstatus="${k}" style="padding:12px 16px;text-align:right;cursor:pointer;${f.status === k ? 'border-color:var(--primary);box-shadow:0 0 0 3px rgba(31,115,183,.12)' : ''}"><div class="row between">${badge(k)}<b class="ltr" style="font-size:20px;color:var(--ink)">${counts[k]}</b></div></button>`).join('')}
    </div>
    <section class="card">
      ${S.selected.size ? `<div class="bulkbar" role="region" aria-label="إجراءات جماعية"><b>${S.selected.size} محددين</b>
        <select class="select" style="width:180px;height:32px;background-color:var(--navy-2);color:#fff;border-color:var(--navy-3)" id="bulkStatus" aria-label="غيّر الحالة"><option value="">غيّر الحالة إلى…</option>${STATUSES.map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select>
        <button class="btn btn-sm btn-secondary" data-export>${ic('excel', 'sm')}Export</button>
        <button class="btn btn-sm btn-danger" id="bulkDel">${ic('trash', 'sm')}امسح</button>
        <span style="flex:1"></span><button class="btn btn-sm btn-ghost" style="color:#fff" id="bulkClear">إلغاء التحديد</button></div>` : `
      <div class="toolbar">
        <label class="search"><span class="sr-only">بحث</span>${ic('search')}<input id="leadSearch" placeholder="ابحث بالاسم أو الرقم" value="${esc(S.q)}"></label>
        <div class="chips">
          <select class="select fchip ${f.status ? 'on' : ''}" style="width:auto;border-radius:999px" id="fStatus" aria-label="الحالة"><option value="">الحالة: الكل</option>${STATUSES.map(([k, l]) => `<option value="${k}" ${f.status === k ? 'selected' : ''}>الحالة: ${l}</option>`).join('')}</select>
          <select class="select fchip ${f.camp ? 'on' : ''}" style="width:auto;border-radius:999px" id="fCamp" aria-label="الحملة"><option value="">الحملة: الكل</option>${[...new Set(CAMPAIGNS.map(c => c[1]))].map(c => `<option ${f.camp === c ? 'selected' : ''}>${c}</option>`).join('')}</select>
          <button class="fchip" id="fDate">${ic('cal', 'sm')}${$('#dateLabel').textContent}</button>
          <button class="fchip ${f.hasImg ? 'on' : ''}" data-ftoggle="hasImg">${ic('image', 'sm')}عنده صورة</button>
          <button class="fchip ${f.wa ? 'on' : ''}" data-ftoggle="wa">${ic('wa', 'sm')}ضغط واتساب</button>
          ${f.status || f.camp || f.hasImg || f.wa || S.q ? `<button class="btn btn-sm btn-ghost" id="fClear">مسح الفلاتر</button>` : ''}
        </div>
      </div>`}
      ${st === 'error' ? errorBlock : !list.length && st !== 'loading' ? (st === 'empty' ? emptyBlock('users', 'لسه مفيش مسجلين', 'أول ما حد يسجل في الصفحة هيظهر هنا. اتأكد إن الإعلانات شغالة واللينك صح.', '<a class="btn btn-primary" href="#tracking">' + ic('target', 'sm') + 'راجع التتبع</a>') : emptyBlock('search', 'مفيش نتايج', 'مفيش مسجلين بالفلاتر دي. جرّب تشيل فلتر أو تغيّر كلمة البحث.', '<button class="btn btn-secondary" id="fClear2">مسح الفلاتر</button>')) : `
      <div class="table-wrap"><table class="t"><thead><tr>
        <th class="w-check"><input type="checkbox" class="cbx" id="selAll" aria-label="حدد الكل" ${rows.length && rows.every(r => S.selected.has(r.id)) ? 'checked' : ''}></th>
        <th>الاسم</th><th>الرقم</th><th class="hide-tab">التاريخ</th><th class="hide-tab">المصدر / الحملة</th><th>الصورة</th><th>الحالة</th><th class="hide-tab">ملاحظات</th><th></th></tr></thead>
        <tbody>${st === 'loading' ? skelRows(8) : rows.map(l => `<tr data-lead="${l.id}" class="${S.selected.has(l.id) ? 'sel' : ''}">
          <td><input type="checkbox" class="cbx" data-sel="${l.id}" aria-label="حدد ${esc(l.name)}" ${S.selected.has(l.id) ? 'checked' : ''}></td>
          <td><span class="name"><span class="avatar sm">${initials(l.name)}</span>${esc(l.name)}</span></td>
          <td>${phoneCell(l.phone)}</td><td class="muted hide-tab">${dfmt(l.date)}</td><td class="hide-tab">${srcCell(l)}</td>
          <td>${genBadge(l.gen)}</td><td>${badge(l.status)}</td><td class="hide-tab"><span class="note" style="display:block">${esc(l.note) || '<span style="color:var(--muted-2)">—</span>'}</span></td>
          <td><button class="btn btn-icon btn-ghost" aria-label="تفاصيل">${ic('left', 'sm')}</button></td></tr>`).join('')}</tbody></table></div>
      <div class="pager"><span>عرض <span class="ltr">${(S.page - 1) * per + 1}–${Math.min(S.page * per, list.length)}</span> من <span class="ltr">${list.length}</span></span>
        <div class="pages">${Array.from({ length: pages }, (_, i) => `<button class="${i + 1 === S.page ? 'on' : ''}" data-page="${i + 1}">${i + 1}</button>`).join('')}</div></div>`}
    </section>`;
  };
  BIND.leads = () => {
    bindTableCommon();
    const re = () => { $('#view').innerHTML = VIEWS.leads(); BIND.leads(); };
    $('#leadSearch')?.addEventListener('input', e => { S.q = e.target.value; S.page = 1; clearTimeout(S._t); S._t = setTimeout(() => { re(); const i = $('#leadSearch'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }, 250); });
    $('#fStatus')?.addEventListener('change', e => { S.filters.status = e.target.value; S.page = 1; re(); });
    $('#fCamp')?.addEventListener('change', e => { S.filters.camp = e.target.value; S.page = 1; re(); });
    $$('[data-fstatus]').forEach(b => b.onclick = () => { S.filters.status = S.filters.status === b.dataset.fstatus ? '' : b.dataset.fstatus; S.page = 1; re(); });
    $$('[data-ftoggle]').forEach(b => b.onclick = () => { S.filters[b.dataset.ftoggle] = !S.filters[b.dataset.ftoggle]; S.page = 1; re(); });
    $$('#fClear,#fClear2').forEach(b => b.onclick = () => { S.filters = { status: '', camp: '', hasImg: false, wa: false }; S.q = ''; re(); });
    $('#fDate')?.addEventListener('click', () => $('#dateBtn').click());
    $$('[data-page]').forEach(b => b.onclick = () => { S.page = +b.dataset.page; re(); });
    $('#selAll')?.addEventListener('change', e => { $$('[data-sel]').forEach(c => e.target.checked ? S.selected.add(+c.dataset.sel) : S.selected.delete(+c.dataset.sel)); re(); });
    $$('[data-sel]').forEach(c => { c.onclick = e => e.stopPropagation(); c.onchange = () => { c.checked ? S.selected.add(+c.dataset.sel) : S.selected.delete(+c.dataset.sel); re(); }; });
    $('#bulkClear')?.addEventListener('click', () => { S.selected.clear(); re(); });
    $('#bulkStatus')?.addEventListener('change', e => { if (!e.target.value) return; LEADS.forEach(l => S.selected.has(l.id) && (l.status = e.target.value)); toast('ok', `اتغيرت حالة ${S.selected.size} مسجلين`, 'إلى: ' + STATUS_LABEL[e.target.value]); S.selected.clear(); re(); });
    $('#bulkDel')?.addEventListener('click', () => confirmDelete({ title: `تمسح ${S.selected.size} مسجلين؟`, body: 'هيتمسح المسجلين وكل الصور والأحداث المرتبطة بيهم. مش هينفع ترجعهم تاني.', cta: `امسح ${S.selected.size}`, onYes: () => { const n = S.selected.size; for (let i = LEADS.length - 1; i >= 0; i--) if (S.selected.has(LEADS[i].id)) LEADS.splice(i, 1); S.selected.clear(); re(); toast('ok', `اتمسح ${n} مسجلين`); } }));
  };

  function bindTableCommon() {
    $$('[data-wa]').forEach(b => b.onclick = e => { e.stopPropagation(); window.open('https://wa.me/2' + b.dataset.wa, '_blank', 'noopener'); });
    $$('[data-copy]').forEach(b => b.onclick = async e => { e.stopPropagation(); try { await navigator.clipboard.writeText(b.dataset.copy); } catch (_) {} toast('ok', 'اتنسخ الرقم', b.dataset.copy); });
    $$('tr[data-lead]').forEach(tr => tr.onclick = e => { if (e.target.closest('input,button,select')) return; openLead(+tr.dataset.lead); });
    $$('[data-export]').forEach(b => b.onclick = exportCsv);
    $$('[data-retry]').forEach(b => b.onclick = () => { S.viewState = 'loading'; render(); setTimeout(() => { S.viewState = 'data'; $$('#stateBar button').forEach(x => x.classList.toggle('on', x.dataset.state === 'data')); render(); toast('ok', 'اتحمّلت البيانات'); }, 900); });
  }

  function exportCsv() {
    const rows = (S.selected.size ? LEADS.filter(l => S.selected.has(l.id)) : filteredLeads());
    const head = ['الاسم', 'الرقم', 'التاريخ', 'المصدر', 'الحملة', 'الصورة', 'الحالة', 'ملاحظات'];
    const csv = [head, ...rows.map(l => [l.name, l.phone, l.date.toISOString(), l.src, l.camp, GEN_LABEL[l.gen], STATUS_LABEL[l.status], l.note])]
      .map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\r\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })); // BOM so Arabic opens in Excel
    a.download = 'leads-' + new Date().toISOString().slice(0, 10) + '.csv'; a.click();
    toast('ok', 'اتصدّر الملف', rows.length + ' صف · UTF-8 مع BOM');
  }

  function openLead(id) {
    const l = LEADS.find(x => x.id === id); if (!l) return;
    const ev = [
      ['check', 'سجّل اسمه ورقمه', dfmt(l.date), ''],
      ['upload', 'رفع صورة', dfmt(new Date(+l.date + 64e3)), ''],
      l.gen === 'failed' ? ['x', 'التوليد فشل — timeout من Gemini', dfmt(new Date(+l.date + 140e3)), 'fail'] : l.gen === 'rejected' ? ['x', 'الصورة اترفضت — أكتر من شخص', dfmt(new Date(+l.date + 90e3)), 'fail'] : ['spark', 'الصورة اتولدت (13.8 ثانية)', dfmt(new Date(+l.date + 98e3)), ''],
      ...(l.wa ? [['wa', 'ضغط اسأل الدكتور على واتساب', dfmt(new Date(+l.date + 160e3)), 'wa']] : []),
      ['share', 'شارك اللينك', dfmt(new Date(+l.date + 220e3)), '']
    ].slice(0, l.gen === 'none' ? 1 : 5);
    openLayer(`<div class="scrim"></div><aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="dwT">
      <div class="drawer-h"><span class="avatar">${initials(l.name)}</span><div style="flex:1"><h3 id="dwT" style="font-size:18px;color:var(--ink)">${esc(l.name)}</h3><p class="small muted">ليد #${l.id} · ${dfmt(l.date)}</p></div><button class="btn btn-icon btn-secondary" data-close aria-label="إغلاق" data-autofocus>${ic('x', 'sm')}</button></div>
      <div class="drawer-b">
        <div class="row wrap"><a class="btn btn-wa btn-sm" href="https://wa.me/2${l.phone}" target="_blank" rel="noopener">${ic('wa', 'sm')}كلّمه واتساب</a><a class="btn btn-secondary btn-sm" href="tel:${l.phone}">${ic('phone', 'sm')}اتصل</a><button class="btn btn-secondary btn-sm" data-copy="${l.phone}">${ic('copy', 'sm')}انسخ الرقم</button></div>
        <div class="grid g2" style="gap:12px">
          <div class="field"><label for="dwS">الحالة</label><select class="select" id="dwS">${STATUSES.map(([k, t]) => `<option value="${k}" ${l.status === k ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
          <div class="field"><label>الصورة</label><div style="height:40px;display:flex;align-items:center">${genBadge(l.gen)}</div></div>
        </div>
        <dl class="kv"><dt>الرقم</dt><dd class="ltr mono">${l.phone}</dd><dt>المصدر</dt><dd class="ltr">${l.src}</dd><dt>الحملة</dt><dd class="ltr mono">${l.camp}</dd><dt>الجهاز</dt><dd>موبايل · Android</dd><dt>الموافقة</dt><dd>${ic('check', 'sm')} وافق على استخدام الصورة</dd></dl>
        ${l.gen === 'done' ? `<div class="stack-8"><span class="h-sec">الصور</span>${pair(l.id % 4)}<div class="row"><button class="btn btn-sm btn-secondary">${ic('dl', 'sm')}حمّل</button><button class="btn btn-sm btn-ghost">${ic('link', 'sm')}لينك المشاركة</button></div></div>` : l.gen === 'none' ? `<div class="banner info"><span class="bi">${ic('image')}</span><div class="small">لسه مرفعش صورة. ممكن تبعتله رسالة تفكير على واتساب.</div></div>` : `<div class="banner"><span class="bi">${ic('alert')}</span><div class="small grow" style="flex:1">التوليد ${GEN_LABEL[l.gen]}. تقدر تسمحله يجرب تاني.</div><button class="btn btn-sm btn-gold" id="dwRegen">${ic('refresh', 'sm')}اسمح بإعادة التوليد</button></div>`}
        <div class="field"><label for="dwN">ملاحظات</label><textarea class="textarea" id="dwN" placeholder="اكتب ملاحظة عن المكالمة…">${esc(l.note)}</textarea></div>
        <div class="stack-8"><span class="h-sec">الأحداث</span><ol class="timeline">${ev.map(([i, t, d, c]) => `<li class="tl ${c}"><i>${ic(i)}</i><b>${t}</b><small>${d}</small></li>`).join('')}</ol></div>
      </div>
      <div class="drawer-f"><button class="btn btn-primary" id="dwSave">${ic('check', 'sm')}احفظ</button><button class="btn btn-secondary" data-close>إلغاء</button><span style="flex:1"></span><button class="btn btn-danger-ghost" id="dwDel">${ic('trash', 'sm')}امسح الليد</button></div>
    </aside>`);
    $('#dwSave').onclick = () => { l.status = $('#dwS').value; l.note = $('#dwN').value; closeLayer(); render(); toast('ok', 'اتحفظت التغييرات', l.name + ' · ' + STATUS_LABEL[l.status]); };
    $('#dwRegen') && ($('#dwRegen').onclick = () => { toast('ok', 'تم', 'المسجل يقدر يولّد صورة تانية دلوقتي'); });
    $$('#layer [data-copy]').forEach(b => b.onclick = () => toast('ok', 'اتنسخ الرقم', l.phone));
    $('#dwDel').onclick = () => confirmDelete({ title: `تمسح ${l.name}؟`, body: 'هيتمسح الليد وصوره (الأصلية والمتولدة) وكل الأحداث. مش هينفع ترجعه.', onYes: () => { LEADS.splice(LEADS.indexOf(l), 1); render(); toast('ok', 'اتمسح الليد'); } });
  }

  /* ---------- 3. Images ---------- */
  VIEWS.images = () => {
    const st = S.viewState;
    const items = LEADS.filter(l => l.gen !== 'none').slice(0, 16);
    return `
    <div class="page-head"><div><h2>الصور</h2><p>الصور الأصلية والمتولدة · محفوظة برّه الـ public ومتاحة بلينكات موقّعة بس</p></div>
      <div class="seg" role="tablist"><button class="on">الكل <span class="ltr">${items.length}</span></button><button>اتولدت</button><button>فشلت</button><button>اترفضت</button></div></div>
    <div class="banner"><span class="bi">${ic('clock')}</span>
      <div style="flex:1" class="stack-8"><b style="color:var(--ink)">المسح التلقائي شغال</b><span class="small muted">الصور الأصلية بتتمسح بعد <b style="color:var(--ink)">30 يوم</b> والمتولدة بعد <b style="color:var(--ink)">90 يوم</b>. آخر تشغيل: النهارده 3:00 ص — اتمسح 42 صورة.</span>
        <code class="cron">0 3 * * * /usr/local/bin/php /home/USER/slim/cron/cleanup.php &gt;/dev/null 2&gt;&amp;1</code></div>
      <a class="btn btn-secondary btn-sm" href="#settings">${ic('cog', 'sm')}غيّر المدة</a></div>
    ${st === 'error' ? `<div class="card">${errorBlock}</div>` : st === 'empty' ? `<div class="card">${emptyBlock('image', 'مفيش صور لسه', 'أول ما حد يرفع صورة ويولّد المحاكاة، الصورتين هيظهروا هنا جنب بعض.')}</div>` : `
    <div class="gallery">${(st === 'loading' ? Array(8).fill(null) : items).map((l, i) => l ? `
      <article class="card gcard">${pair(i)}
        <div class="meta"><div><b>${esc(l.name)}</b><small>${dfmt(l.date)}</small></div>${genBadge(l.gen)}</div>
        <div class="row" style="padding:0 12px 12px"><button class="btn btn-sm btn-secondary" style="flex:1" data-dl>${ic('dl', 'sm')}حمّل</button><button class="btn btn-sm btn-icon btn-secondary" data-lead-open="${l.id}" aria-label="تفاصيل الليد">${ic('users', 'sm')}</button><button class="btn btn-sm btn-icon btn-danger-ghost" data-del-img="${l.id}" aria-label="امسح الصور">${ic('trash', 'sm')}</button></div>
      </article>` : `<div class="card gcard" style="padding:8px"><div class="pair"><div class="skel" style="aspect-ratio:3/4"></div><div class="skel" style="aspect-ratio:3/4"></div></div><div style="padding:8px 4px" class="stack-8"><div class="skel" style="height:14px;width:60%"></div><div class="skel" style="height:12px;width:40%"></div></div></div>`).join('')}</div>`}`;
  };
  BIND.images = () => {
    bindTableCommon();
    $$('[data-lead-open]').forEach(b => b.onclick = () => openLead(+b.dataset.leadOpen));
    $$('[data-dl]').forEach(b => b.onclick = () => toast('ok', 'بدأ التحميل', 'slim-result.webp'));
    $$('[data-del-img]').forEach(b => b.onclick = () => confirmDelete({ title: 'تمسح الصورتين؟', body: 'هتتمسح الصورة الأصلية والمتولدة نهائيًا من السيرفر. الليد نفسه هيفضل موجود.', onYes: () => { b.closest('.gcard').remove(); toast('ok', 'اتمسحت الصور'); } }));
  };

  /* ---------- 4. SEO ---------- */
  const SEO = { title: 'شوف نفسك بعد التخسيس — د. محمد حسام الدين المرسي', desc: 'ارفع صورتك واحصل على محاكاة تقريبية بالذكاء الاصطناعي لشكلك بعد التخسيس — مجانًا. استشاري جراحات الغدد وجراحات المناظير والسمنة في دكرنس.', kw: 'تكميم المعدة, عملية التكميم دكرنس, جراحة السمنة, دكتور سمنة المنصورة', canonical: 'https://slim.doctor-domain.com/', url: 'slim.doctor-domain.com' };
  VIEWS.seo = () => `
    <div class="page-head"><div><h2>SEO والمشاركة</h2><p>العنوان والوصف وشكل اللينك في جوجل وواتساب وفيسبوك</p></div><div class="row"><button class="btn btn-secondary">${ic('refresh', 'sm')}رجّع الافتراضي</button><button class="btn btn-primary" data-save>${ic('check', 'sm')}احفظ التغييرات</button></div></div>
    <div class="grid g-1-1" style="align-items:start">
      <div class="stack" style="gap:24px">
        <section class="card"><div class="card-h"><h2>الأساسيات</h2></div><div class="card-b form-grid">
          <div class="field full"><div class="row between"><label for="sT">عنوان الصفحة (Title)</label><span class="counter" id="cT"></span></div><input class="input" id="sT" value="${esc(SEO.title)}"><span class="hint">الأفضل من 50 لـ 60 حرف</span></div>
          <div class="field full"><div class="row between"><label for="sD">الوصف (Meta description)</label><span class="counter" id="cD"></span></div><textarea class="textarea" id="sD" rows="3">${esc(SEO.desc)}</textarea><span class="hint">الأفضل من 120 لـ 160 حرف</span></div>
          <div class="field full"><label for="sK">الكلمات المفتاحية</label><input class="input" id="sK" value="${esc(SEO.kw)}"><span class="hint">افصل بينهم بفاصلة</span></div>
          <div class="field"><label for="sC">الرابط الأساسي (Canonical)</label><input class="input" dir="ltr" id="sC" value="${SEO.canonical}"></div>
          <div class="field"><label for="sL">اللغة / المنطقة</label><select class="select" id="sL"><option>ar_EG — عربي مصر</option><option>ar — عربي</option></select></div>
        </div></section>
        <section class="card"><div class="card-h"><div><h2>صورة المشاركة (OG Image)</h2><p>1200×630 بكسل · JPG أو PNG</p></div></div><div class="card-b row-16 wrap">
          <div style="width:220px;flex:none" class="og"><div class="img" style="padding:0 10px"><span class="ot" style="font-size:12px">شوف نفسك بعد التخسيس</span><img class="doc" src="../../public/assets/img/doctor.webp" alt=""></div></div>
          <div class="stack-8" style="flex:1;min-width:200px"><label class="btn btn-secondary" style="align-self:flex-start">${ic('upload', 'sm')}ارفع صورة<input type="file" accept="image/*" class="sr-only"></label><span class="hint">لو مفيش صورة، بنعمل واحدة تلقائي من لوجو الدكتور وصورته.</span>
            <label class="switch"><input type="checkbox" checked><span class="tr"></span><span class="small">Twitter card: summary_large_image</span></label></div>
        </div></section>
        <section class="card"><div class="card-h"><div><h2>Schema (JSON-LD)</h2><p>بيساعد جوجل يفهم إن الصفحة لدكتور وعيادة</p></div></div><div class="list">
          ${[['Physician', 'بيانات الدكتور: الاسم، التخصص، الصورة', 1], ['MedicalClinic', 'العيادات والفروع كـ locations', 1], ['FAQPage', 'الأسئلة الشائعة تظهر في نتائج البحث', 1]].map(([n, d, on]) => `<div class="li"><div class="grow"><b class="ltr mono">${n}</b><small>${d}</small></div><label class="switch"><input type="checkbox" ${on ? 'checked' : ''} aria-label="${n}"><span class="tr"></span></label></div>`).join('')}
        </div></section>
        <section class="card"><div class="card-h"><h2>robots.txt و sitemap</h2><span class="status ok"><i></i>sitemap.xml شغال</span></div><div class="card-b stack">
          <textarea class="code" style="min-height:120px" aria-label="robots.txt">User-agent: *
Allow: /
Disallow: /admin/
Disallow: /r/
Sitemap: ${SEO.canonical}sitemap.xml</textarea>
          <code class="cron">&lt;url&gt;&lt;loc&gt;${SEO.canonical}&lt;/loc&gt;&lt;lastmod&gt;2026-10-08&lt;/lastmod&gt;&lt;priority&gt;1.0&lt;/priority&gt;&lt;/url&gt;</code>
          <label class="row small"><input type="checkbox" class="cbx" checked> صفحات المشاركة <span class="ltr mono">/r/*</span> عليها noindex</label>
        </div></section>
      </div>
      <div class="stack" style="gap:24px;position:sticky;top:96px">
        <section class="card"><div class="card-h"><h2>معاينة جوجل</h2><div class="seg"><button class="on">${ic('phone', 'sm')}</button><button>${ic('globe', 'sm')}</button></div></div><div class="card-b">
          <div class="serp"><div class="u"><span class="fav"><img src="../../public/assets/img/logo.png" alt=""></span><div><div style="font-size:14px">د. محمد حسام الدين</div><div class="ltr" style="color:#4d5156">https://${SEO.url}</div></div></div>
          <div class="t1" id="pT"></div><div class="d" id="pD"></div></div></div></section>
        <section class="card"><div class="card-h"><h2>معاينة المشاركة</h2><div class="seg" id="ogSeg"><button class="on" data-og="fb">فيسبوك</button><button data-og="wa">واتساب</button></div></div><div class="card-b" id="ogBox"></div></section>
      </div>
    </div>`;
  BIND.seo = () => {
    const upd = () => {
      const t = $('#sT').value, d = $('#sD').value;
      $('#pT').textContent = t.length > 60 ? t.slice(0, 58) + '…' : t;
      $('#pD').textContent = d.length > 160 ? d.slice(0, 157) + '…' : d;
      $('#cT').textContent = t.length + '/60'; $('#cT').classList.toggle('over', t.length > 60);
      $('#cD').textContent = d.length + '/160'; $('#cD').classList.toggle('over', d.length > 160);
      const card = `<div class="og"><div class="img"><span class="ot">شوف نفسك<br>بعد التخسيس<br><small style="font-size:12px;color:var(--primary);font-weight:600">محاكاة مجانية بالذكاء الاصطناعي</small></span><img class="doc" src="../../public/assets/img/doctor.webp" alt=""></div><div class="b"><small>${SEO.url}</small><b>${esc(t)}</b><p>${esc(d.slice(0, 90))}…</p></div></div>`;
      $('#ogBox').innerHTML = S.og === 'wa' ? `<div style="background:#EFEAE2;padding:16px;border-radius:10px"><div class="wa-og">${card}<p class="small" style="padding:6px 4px 0;color:#1F7AEB;direction:ltr;text-align:left">https://${SEO.url}</p></div></div>` : card;
    };
    ['#sT', '#sD'].forEach(s => $(s).addEventListener('input', upd));
    $$('#ogSeg button').forEach(b => b.onclick = () => { S.og = b.dataset.og; $$('#ogSeg button').forEach(x => x.classList.toggle('on', x === b)); upd(); });
    upd(); bindSave();
  };
  function bindSave() { $$('[data-save]').forEach(b => b.onclick = () => { b.disabled = true; const h = b.innerHTML; b.innerHTML = '<span class="spin"></span>بيتحفظ…'; setTimeout(() => { b.disabled = false; b.innerHTML = h; toast('ok', 'اتحفظت التغييرات', 'الصفحة اتحدثت على طول'); }, 700); }); }

  /* ---------- 5. Tracking ---------- */
  const secretField = (id, label, val, hint = '') => `<div class="field"><label for="${id}">${label}</label><div class="input-group"><input class="input mono" dir="ltr" id="${id}" type="password" value="${val}" autocomplete="off"><span class="addon"><button type="button" data-reveal="${id}" aria-label="إظهار">${ic('eye', 'sm')}</button><span class="ltr">••••${val.slice(-4)}</span></span></div>${hint ? `<span class="hint">${hint}</span>` : ''}</div>`;
  VIEWS.tracking = () => `
    <div class="page-head"><div><h2>التتبع (Pixel)</h2><p>Meta Pixel + Conversions API و GA4 و GTM — لو الحقل فاضي الكود مش بيتحط في الصفحة</p></div><button class="btn btn-primary" data-save>${ic('check', 'sm')}احفظ</button></div>
    <div class="grid g3" style="gap:16px">
      ${[['Meta Pixel', 'ok', 'متصل', 'آخر حدث من دقيقة'], ['Conversions API', 'ok', 'متصل', 'Dedup 98% · Match quality 7.8/10'], ['Google Analytics 4', 'warn', 'مفيش أحداث النهارده', 'اتأكد من الـ Measurement ID']].map(([n, s, t, d]) => `<div class="card" style="padding:16px"><div class="row between"><b style="color:var(--ink)" class="ltr">${n}</b><span class="status ${s}"><i></i>${t}</span></div><p class="small muted" style="margin-top:6px">${d}</p></div>`).join('')}
    </div>
    <div class="grid g-1-1" style="align-items:start">
      <div class="stack" style="gap:24px">
        <section class="card"><div class="card-h"><div><h2>Meta Pixel + Conversions API</h2><p>نفس الـ event_id في المتصفح والسيرفر عشان ميتحسبش مرتين</p></div><span class="status ok"><i></i>Connected</span></div>
          <div class="card-b form-grid">
            <div class="field"><label for="px">Pixel ID</label><input class="input mono" dir="ltr" id="px" value="1234567890123456"></div>
            <div class="field"><label for="gv">Graph API version</label><select class="select" id="gv"><option>v21.0</option><option>v20.0</option></select></div>
            <div class="field full">${secretField('capi', 'Conversions API access token', 'EAAGm0PX4ZCpsBAKZB1x9pQr8tYk2ZA7a1f')}</div>
            <div class="field"><label for="tc">Test event code</label><input class="input mono" dir="ltr" id="tc" placeholder="TEST12345" value="TEST48213"><span class="hint">امسحه قبل ما تبدأ الإعلانات الحقيقية</span></div>
          </div>
          <div class="list" style="border-top:1px solid var(--line)">
            ${[['Lead', 'لما يسجل اسمه ورقمه', 1, 'متصفح + سيرفر'], ['ViewContent', 'لما النتيجة تظهر', 1, 'متصفح + سيرفر'], ['Contact', 'ضغطة اسأل الدكتور على واتساب', 1, 'متصفح + سيرفر'], ['Share', 'ShareResult — لما يشارك اللينك', 0, 'متصفح']].map(([n, d, on, w]) => `<div class="li"><div class="grow"><b class="ltr mono">${n}</b><small>${d} · ${w}</small></div><label class="switch"><input type="checkbox" ${on ? 'checked' : ''} aria-label="${n}"><span class="tr"></span></label></div>`).join('')}
          </div>
          <div class="card-f" style="justify-content:space-between"><span class="small muted" id="testRes">ابعت حدث تجريبي وشوفه في Events Manager → Test events</span><button class="btn btn-secondary" id="testEv">${ic('send', 'sm')}ابعت حدث تجريبي</button></div>
        </section>
        <section class="card"><div class="card-h"><h2>Google</h2></div><div class="card-b form-grid">
          <div class="field"><label for="ga">GA4 Measurement ID</label><input class="input mono" dir="ltr" id="ga" value="G-8XK2L9QZ1M"><span class="hint">نفس الأحداث بتتبعت لـ gtag و dataLayer</span></div>
          <div class="field"><label for="gtm">GTM Container ID</label><input class="input mono" dir="ltr" id="gtm" placeholder="GTM-XXXXXXX"></div>
        </div></section>
      </div>
      <div class="stack" style="gap:24px">
        <section class="card"><div class="card-h"><div><h2>كود مخصص</h2><p>بيتحط زي ما هو — اتأكد إنه من مصدر موثوق</p></div></div><div class="tabs" id="codeTabs"><button class="on" data-code="head">داخل &lt;head&gt;</button><button data-code="body">بعد &lt;body&gt;</button></div>
          <div class="card-b"><textarea class="code" id="codeBox" style="min-height:260px" spellcheck="false" aria-label="كود مخصص">&lt;!-- TikTok Pixel --&gt;
&lt;script&gt;
  !function (w, d, t) {
    w.TiktokAnalyticsObject = t;
    /* … */
  }(window, document, 'ttq');
&lt;/script&gt;</textarea><p class="hint" style="margin-top:8px">${ic('shield', 'sm')} اللي بيعدّل هنا لازم يكون Owner. كل تعديل بيتسجل في سجل النشاط.</p></div></section>
        <section class="card"><div class="card-h"><h2>آخر الأحداث</h2><span class="small muted">مباشر</span></div><div class="list">
          ${[['Contact', 'ok', 'أحمد السيد', 'من 40 ثانية'], ['ViewContent', 'ok', 'منى حسن', 'من دقيقتين'], ['Lead', 'ok', 'كريم عادل', 'من 3 دقايق'], ['Lead', 'err', 'CAPI: Invalid fbc parameter', 'من 12 دقيقة']].map(([e, s, w, t]) => `<div class="li"><span class="status ${s}"><i></i></span><div class="grow"><b class="ltr mono">${e}</b><small>${w}</small></div><small class="muted">${t}</small></div>`).join('')}
        </div></section>
      </div>
    </div>`;
  BIND.tracking = () => {
    bindSave(); bindReveal();
    const code = { head: $('#codeBox').value, body: '<!-- noscript fallbacks -->\n' };
    let cur = 'head';
    $$('#codeTabs button').forEach(b => b.onclick = () => { code[cur] = $('#codeBox').value; cur = b.dataset.code; $('#codeBox').value = code[cur]; $$('#codeTabs button').forEach(x => x.classList.toggle('on', x === b)); });
    $('#testEv').onclick = () => testBtn($('#testEv'), $('#testRes'), true, 'اتبعت Lead تجريبي · events_received: 1 · fbtrace_id: A7x…Q2', 'الحدث اتبعت', 'ظاهر في Test events دلوقتي');
  };
  function bindReveal() { $$('[data-reveal]').forEach(b => b.onclick = () => { const i = $('#' + b.dataset.reveal); const show = i.type === 'password'; i.type = show ? 'text' : 'password'; b.innerHTML = ic(show ? 'eyeoff' : 'eye', 'sm'); }); }
  function testBtn(btn, out, ok, msg, t1, t2) {
    const h = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spin"></span>بيجرب…';
    out.className = 'small muted'; out.textContent = 'بيتصل…';
    setTimeout(() => {
      btn.disabled = false; btn.innerHTML = h;
      out.innerHTML = `<span class="status ${ok ? 'ok' : 'err'}"><i></i>${esc(msg)}</span>`;
      toast(ok ? 'ok' : 'err', t1, t2);
    }, 1100);
  }

  /* ---------- 6. Integrations & API ---------- */
  const PROMPT = 'Edit this photo to show the same person after healthy, realistic weight loss (approximately 25–35% body fat reduction). Keep the exact same face identity, facial features, skin tone, hairstyle, age, clothing style, pose, camera angle, lighting, and background. Only slim down the body, face fullness, neck, arms, belly, and legs in a natural, believable, medically realistic way. Clothes should fit the slimmer body naturally. Photorealistic, high quality, no filters, no beautification beyond weight loss, no nudity, do not change gender or ethnicity, no text or watermark.';
  const hook = (id, name, url, state) => `<div class="card" style="box-shadow:none"><div class="card-h"><div class="row"><span class="ibox">${ic('link', 'sm')}</span><div><h3>${name}</h3><p>عند ليد جديد وضغطة واتساب</p></div></div><span id="${id}St">${state === 'ok' ? '<span class="status ok"><i></i>آخر إرسال نجح</span>' : state === 'err' ? '<span class="status err"><i></i>آخر إرسال فشل (500)</span>' : '<span class="status"><i></i>مش متجرب</span>'}</span></div>
    <div class="card-b stack"><div class="field"><label for="${id}">Webhook URL</label><input class="input mono" dir="ltr" id="${id}" value="${url}"></div>
    <div class="row wrap"><label class="switch"><input type="checkbox" checked><span class="tr"></span><span class="small">ليد جديد</span></label><label class="switch"><input type="checkbox" ${id === 'n8n' ? 'checked' : ''}><span class="tr"></span><span class="small">ضغطة واتساب</span></label><span style="flex:1"></span><button class="btn btn-secondary btn-sm" data-test-hook="${id}" data-ok="${state !== 'err'}">${ic('send', 'sm')}جرّب الويب هوك</button></div>
    <div class="small" id="${id}Out"></div></div></div>`;
  VIEWS.integrations = () => `
    <div class="page-head"><div><h2>الربط والـ API</h2><p>مفتاح Gemini، البرومبت، الحدود، والويب هوكس</p></div><button class="btn btn-primary" data-save>${ic('check', 'sm')}احفظ</button></div>
    <div class="grid g-1-1" style="align-items:start">
      <div class="stack" style="gap:24px">
        <section class="card"><div class="card-h"><div class="row"><span class="ibox gold">${ic('spark')}</span><div><h2>Google Gemini</h2><p>توليد الصور · المفتاح بيتخزن متشفر ومش بيوصل للمتصفح</p></div></div><span id="gemSt"><span class="status ok"><i></i>متصل</span></span></div>
          <div class="card-b form-grid">
            <div class="full">${secretField('gk', 'API key', 'AIzaSyD8kQ0aR7xW3-Hm2pL9vTn4bE5cYf1Q7Zk', 'بيظهر آخر 4 حروف بس')}</div>
            <div class="field"><label for="gm">الموديل</label><select class="select ltr" id="gm" style="text-align:left"><option>gemini-2.5-flash-image</option><option>gemini-2.5-flash-image-preview</option><option>gemini-3-pro-image</option></select></div>
            <div class="field"><label for="gto">Timeout (ثانية)</label><input class="input ltr" id="gto" type="number" value="90" style="text-align:left"></div>
            <div class="full row between"><label class="switch"><input type="checkbox" checked><span class="tr"></span><span class="small">إعادة محاولة تلقائية مرة واحدة لو فشل</span></label><button class="btn btn-secondary btn-sm" id="gemTest">${ic('refresh', 'sm')}اختبر الاتصال</button></div>
            <div class="full small" id="gemOut"></div>
          </div></section>
        <section class="card"><div class="card-h"><div><h2>برومبت التوليد</h2><p>بالإنجليزي أفضل للنتايج</p></div><button class="btn btn-ghost btn-sm" id="pReset">${ic('refresh', 'sm')}الافتراضي</button></div>
          <div class="tabs" id="pTabs"><button class="on" data-p="gen">برومبت التوليد</button><button data-p="safe">برومبت الأمان</button></div>
          <div class="card-b stack"><textarea class="code" id="pBox" style="min-height:200px;white-space:pre-wrap" spellcheck="false">${PROMPT}</textarea>
            <div class="row between small"><span class="muted">متغيرات متاحة: <code class="mono">{gender}</code> <code class="mono">{intensity}</code></span><span class="counter" id="pCount"></span></div>
            <div class="stack-8"><span class="lbl">معاينة على صورة تجريبية</span><div class="row-16"><div style="width:220px">${pair(0)}</div><div class="stack-8 small muted"><span>${ic('clock', 'sm')} آخر تجربة: 12.6 ثانية</span><span>${ic('check', 'sm')} الوش محفوظ</span><button class="btn btn-secondary btn-sm" id="pPrev">${ic('spark', 'sm')}جرّب البرومبت</button></div></div></div>
          </div></section>
      </div>
      <div class="stack" style="gap:24px">
        <section class="card"><div class="card-h"><div><h2>الحدود</h2><p>عشان مفيش تحقق برقم، الحدود دي بتحمي الرصيد</p></div></div><div class="card-b form-grid">
          ${[['lc', 'الحد اليومي للتوليد', 300, 'لما يخلص بتظهر رسالة "الخدمة عليها ضغط"'], ['lip', 'لكل IP في 24 ساعة', 3, ''], ['lck', 'لكل جهاز (cookie)', 2, ''], ['lph', 'لكل رقم موبايل', 1, 'الأدمن يقدر يسمح بإعادة'], ['lle', 'تسجيل ليد لكل IP في الساعة', 10, '']].map(([id, l, v, h]) => `<div class="field"><label for="${id}">${l}</label><input class="input ltr" type="number" id="${id}" value="${v}" style="text-align:left">${h ? `<span class="hint">${h}</span>` : ''}</div>`).join('')}
          <div class="field full"><span class="lbl">استهلاك النهارده</span><div class="row-16"><div class="bar gold" style="flex:1;height:10px"><i style="width:61%"></i></div><b class="ltr">184 / 300</b></div></div>
        </div></section>
        <section class="card"><div class="card-h"><div><h2>Cloudflare Turnstile</h2><p>حماية من البوتس (Invisible) — لو فاضي بيتخطى</p></div><span class="status ok"><i></i>مفعّل</span></div><div class="card-b form-grid">
          <div class="field"><label for="tsk">Site key</label><input class="input mono" dir="ltr" id="tsk" value="0x4AAAAAAAB1cDeFgHiJkL"></div>
          <div class="field">${secretField('tss', 'Secret key', '0x4AAAAAAAB1cDeFgHiJkL_secret_9f2A')}</div></div></section>
        <section class="stack" style="gap:16px">
          <h3 class="h-sec">الويب هوكس</h3>
          ${hook('n8n', 'n8n', 'https://n8n.spreadagency.net/webhook/slim-leads', 'ok')}
          ${hook('crm', 'Spread CRM', 'https://crm.spreadagency.net/api/hooks/leads/7f3a', 'err')}
          <div class="field"><label for="hs">Secret header (اختياري)</label><div class="input-group"><input class="input mono" dir="ltr" id="hs" value="X-Spread-Signature"><span class="addon">HMAC-SHA256</span></div></div>
          <details class="card" style="box-shadow:none"><summary class="card-h" style="cursor:pointer;border:0"><h3>شكل البيانات اللي بتتبعت (JSON)</h3></summary><pre class="code" style="margin:0 16px 16px;min-height:0;white-space:pre">{
  "lead_id": 1240,
  "name": "أحمد السيد",
  "phone": "01012345678",
  "utm_source": "facebook",
  "utm_campaign": "sleeve_oct_lal",
  "status": "new",
  "result_url": "https://slim…/r/9f2c…",
  "created_at": "2026-10-08T14:30:00+02:00"
}</pre></details>
        </section>
      </div>
    </div>`;
  BIND.integrations = () => {
    bindSave(); bindReveal();
    const prompts = { gen: PROMPT, safe: 'Before editing, check the image. Reject (return REJECT:<reason>) if: no clearly visible human, more than one person, the person appears under 18, or any nudity. Otherwise return OK.' };
    let cur = 'gen';
    const cnt = () => { $('#pCount').textContent = $('#pBox').value.length + ' chars'; };
    $('#pBox').addEventListener('input', cnt); cnt();
    $$('#pTabs button').forEach(b => b.onclick = () => { prompts[cur] = $('#pBox').value; cur = b.dataset.p; $('#pBox').value = prompts[cur]; cnt(); $$('#pTabs button').forEach(x => x.classList.toggle('on', x === b)); });
    $('#pReset').onclick = () => { $('#pBox').value = cur === 'gen' ? PROMPT : prompts.safe; cnt(); toast('info', 'رجع البرومبت الافتراضي'); };
    $('#pPrev').onclick = () => testBtn($('#pPrev'), $('#gemOut'), true, 'المعاينة جاهزة · 12.9s', 'المعاينة جاهزة', 'شوف الصورة تحت البرومبت');
    $('#gemTest').onclick = () => testBtn($('#gemTest'), $('#gemOut'), true, 'الاتصال شغال · gemini-2.5-flash-image · 412ms', 'Gemini متصل', 'المفتاح صالح والموديل متاح');
    $$('[data-test-hook]').forEach(b => b.onclick = () => {
      const id = b.dataset.testHook, ok = b.dataset.ok === 'true';
      testBtn(b, $('#' + id + 'Out'), ok, ok ? 'HTTP 200 · 184ms · {"received":true}' : 'HTTP 500 · Internal Server Error — اتعملت إعادة محاولة وفشلت', ok ? 'الويب هوك اشتغل' : 'الويب هوك فشل', ok ? id + ' استلم البيانات' : 'راجع اللينك أو السيرفر التاني');
      setTimeout(() => { $('#' + id + 'St').innerHTML = ok ? '<span class="status ok"><i></i>آخر إرسال نجح</span>' : '<span class="status err"><i></i>آخر إرسال فشل (500)</span>'; }, 1150);
    });
  };

  /* ---------- 7. Page content ---------- */
  const CONTENT_TABS = [['doctor', 'الدكتور'], ['stats', 'الأرقام'], ['exp', 'الخبرات'], ['branches', 'الفروع'], ['faq', 'الأسئلة'], ['texts', 'النصوص والتواصل']];
  S.ctab = 'doctor';
  const crudList = (items, kind) => `<div class="list">${items.map(([t, s, on]) => `<div class="li"><span class="drag" title="اسحب لإعادة الترتيب">${ic('drag')}</span><div class="grow"><b>${esc(t)}</b><small>${esc(s)}</small></div><label class="switch"><input type="checkbox" ${on !== false ? 'checked' : ''} aria-label="نشط"><span class="tr"></span></label><button class="btn btn-icon btn-ghost" aria-label="تعديل">${ic('edit', 'sm')}</button><button class="btn btn-icon btn-ghost" style="color:var(--danger)" data-del-item aria-label="مسح">${ic('trash', 'sm')}</button></div>`).join('')}</div><div class="card-f" style="justify-content:flex-start"><button class="btn btn-secondary btn-sm">${ic('plus', 'sm')}إضافة ${kind}</button></div>`;
  const CT = {
    doctor: () => `<div class="card-b form-grid">
      <div class="full row-16"><img src="../../public/assets/img/doctor.webp" alt="" style="width:72px;height:72px;border-radius:12px;object-fit:cover;object-position:top;background:var(--primary-100)"><div class="stack-8"><span class="lbl">الصورة الشخصية</span><label class="btn btn-secondary btn-sm">${ic('upload', 'sm')}غيّر الصورة<input type="file" accept="image/*" class="sr-only"></label></div>
        <img src="../../public/assets/img/logo.png" alt="" style="height:56px;margin-inline-start:auto"><label class="btn btn-secondary btn-sm">${ic('upload', 'sm')}اللوجو<input type="file" accept="image/*" class="sr-only"></label></div>
      <div class="field"><label for="dN">الاسم</label><input class="input" id="dN" value="د. محمد حسام الدين المرسي" data-live></div>
      <div class="field"><label for="dT">اللقب</label><input class="input" id="dT" value="استشاري جراحات الغدد وجراحات المناظير والسمنة"></div>
      <div class="field full"><label for="dB">نبذة</label><textarea class="textarea" id="dB">دكتوراه الجراحة العامة، ومدرس بكلية الطب جامعة المنصورة، متخصص في جراحات الغدد والمناظير والسمنة.</textarea></div>
      <label class="switch full"><input type="checkbox" checked><span class="tr"></span><span class="small">اعرض لوجو الدكتور على صورة النتيجة</span></label></div>`,
    stats: () => crudList([['سنين خبرة', '+12 · أيقونة: ساعة'], ['عدد العمليات', '+1500'], ['سنين نجاح', '10'], ['رضا المرضى', '98%']], 'رقم') + `<p class="hint" style="padding:0 20px 16px">${ic('info', 'sm')} الأرقام دي لازم تتأكد من الدكتور قبل النشر.</p>`,
    exp: () => crudList([['دكتوراه الجراحة العامة', 'السنة · الجهة المانحة'], ['مدرس بكلية الطب', 'جامعة المنصورة'], ['استشاري جراحات الغدد والمناظير والسمنة', 'حاليًا · عيادة دكرنس'], ['زمالة أو شهادة إضافية', 'مسودة', false]], 'خبرة'),
    branches: () => `<div class="card-b grid g2" style="gap:16px">
      <div class="card branch-card" style="box-shadow:none"><div class="row between"><b style="color:var(--ink)">${ic('pin', 'sm')} عيادة دكرنس</b><span class="badge b-booked">نشط</span></div><p class="small muted">شارع العروبة، بجوار عمر أفندي، أعلى زكي سنتر — دكرنس</p><p class="small">${ic('clock', 'sm')} السبت–الخميس · 5م – 10م</p><p class="small ltr mono" style="text-align:right">+20 10 XXXX XXXX</p><div class="row"><button class="btn btn-secondary btn-sm">${ic('edit', 'sm')}تعديل</button><button class="btn btn-danger-ghost btn-sm" data-del-item>${ic('trash', 'sm')}</button></div></div>
      <div class="card branch-card" style="box-shadow:none;border-style:dashed"><div class="row between"><b style="color:var(--ink)">${ic('pin', 'sm')} فرع المنصورة</b><span class="badge b-lost">مسودة</span></div><p class="small muted">العنوان بالتفصيل</p><p class="small">${ic('clock', 'sm')} الأيام والساعات</p><div class="row"><button class="btn btn-secondary btn-sm">${ic('edit', 'sm')}تعديل</button><button class="btn btn-danger-ghost btn-sm" data-del-item>${ic('trash', 'sm')}</button></div></div>
      <button class="add-card">${ic('plus', 'lg')}<span>إضافة فرع</span></button></div>`,
    faq: () => crudList([['مين المناسب لعملية التكميم؟', 'القرار بيعتمد على مؤشر كتلة الجسم…'], ['العملية بتاخد وقت قد إيه؟', 'من ساعة لساعتين بالمنظار…'], ['هحس بألم بعد العملية؟', 'ألم بسيط في أول كام يوم…'], ['التكلفة كام؟', 'بتختلف حسب الحالة…'], ['هاكل إزاي بعد العملية؟', 'نظام أكل متدرّج…'], ['الصورة اللي هتطلعلي هي النتيجة الفعلية؟', 'لأ، الصورة محاكاة تخيلية…']], 'سؤال'),
    texts: () => `<div class="card-b form-grid">
      <div class="field"><label for="tH">عنوان الهيرو</label><input class="input" id="tH" value="شوف نفسك بعد التخسيس" data-live></div>
      <div class="field"><label for="tBtn">زرار البداية</label><input class="input" id="tBtn" value="ابدأ دلوقتي"></div>
      <div class="field full"><label for="tSub">الوصف تحت العنوان</label><input class="input" id="tSub" value="ارفع صورتك واحصل على محاكاة تقريبية بالذكاء الاصطناعي في ثواني — مجانًا"></div>
      <div class="field"><label for="tWa">رقم الواتساب (دولي)</label><input class="input mono" dir="ltr" id="tWa" placeholder="2010XXXXXXXX" value="2010"></div>
      <div class="field"><label for="tWeb">رابط الموقع</label><input class="input mono" dir="ltr" id="tWeb" value="https://doctor-domain.com"></div>
      <div class="field full"><label for="tMsg">رسالة واتساب الجاهزة</label><textarea class="textarea" id="tMsg" rows="2">مساء الخير، أنا {name}، جربت محاكاة التخسيس وعايز أستفسر عن عملية التكميم.</textarea><span class="hint"><code class="mono">{name}</code> بيتبدل باسم المسجل</span></div>
      <div class="field full"><label for="tDis">نص التنبيه (Disclaimer)</label><textarea class="textarea" id="tDis" rows="2">الصورة دي محاكاة تخيلية بالذكاء الاصطناعي، والنتيجة الفعلية بتختلف من شخص لشخص حسب الحالة. الاستشارة الطبية هي اللي بتحدد المناسب ليك.</textarea></div>
      <div class="field full"><label>معلومات شاشة التحميل</label>${crudList([['عملية التكميم بتقلل حجم المعدة حوالي 80%', 'بتظهر كل 4 ثواني'], ['أغلب المرضى بيرجعوا لحياتهم خلال أسبوع لأسبوعين', '']], 'معلومة')}</div>
      <div class="field"><label>فيسبوك</label><input class="input mono" dir="ltr" placeholder="https://facebook.com/…"></div><div class="field"><label>إنستجرام</label><input class="input mono" dir="ltr" placeholder="https://instagram.com/…"></div></div>`
  };
  VIEWS.content = () => `
    <div class="page-head"><div><h2>محتوى الصفحة</h2><p>كل اللي بيتغير هنا بيظهر في الصفحة من غير ما حد يلمس الكود</p></div><div class="row"><a class="btn btn-secondary" href="../" target="_blank">${ic('globe', 'sm')}افتح الصفحة</a><button class="btn btn-primary" data-save>${ic('check', 'sm')}انشر التغييرات</button></div></div>
    <div class="grid" style="grid-template-columns:minmax(0,1fr) 360px;align-items:start" id="cGrid">
      <section class="card"><div class="tabs" id="cTabs">${CONTENT_TABS.map(([k, l]) => `<button class="${S.ctab === k ? 'on' : ''}" data-ct="${k}">${l}</button>`).join('')}</div><div id="cBody">${CT[S.ctab]()}</div></section>
      <aside class="stack" style="gap:12px"><div class="row between"><span class="h-sec">معاينة موبايل</span><span class="status ok"><i></i>مباشر</span></div><div class="phone-frame"><div class="screen"><span class="notch"></span><iframe src="../../public/index.php" title="معاينة الصفحة على الموبايل" loading="lazy" id="prevFrame"></iframe></div></div></aside>
    </div>`;
  BIND.content = () => {
    bindSave();
    const grid = $('#cGrid'); const fit = () => { grid.style.gridTemplateColumns = innerWidth < 1180 ? '1fr' : 'minmax(0,1fr) 360px'; }; fit(); addEventListener('resize', fit);
    const bindBody = () => {
      $$('#cBody [data-del-item]').forEach(b => b.onclick = () => confirmDelete({ title: 'تمسح العنصر ده؟', body: 'هيختفي من الصفحة على طول.', onYes: () => { (b.closest('.li') || b.closest('.card')).remove(); toast('ok', 'اتمسح'); } }));
      $$('#cBody [data-live]').forEach(inp => inp.addEventListener('input', () => {
        try { const d = $('#prevFrame').contentDocument; if (inp.id === 'tH') d.querySelector('.h1').textContent = inp.value; if (inp.id === 'dN') d.querySelector('.portrait-tag b').textContent = inp.value; } catch (_) {}
      }));
    };
    $$('#cTabs button').forEach(b => b.onclick = () => { S.ctab = b.dataset.ct; $$('#cTabs button').forEach(x => x.classList.toggle('on', x === b)); $('#cBody').innerHTML = CT[S.ctab](); bindBody(); });
    bindBody();
  };

  /* ---------- 8. Settings ---------- */
  VIEWS.settings = () => `
    <div class="page-head"><div><h2>الإعدادات</h2><p>المستخدمين، الخصوصية، مدة الاحتفاظ بالصور، وألوان البراند</p></div><button class="btn btn-primary" data-save>${ic('check', 'sm')}احفظ</button></div>
    <section class="card"><div class="card-h"><div><h2>مستخدمين لوحة التحكم</h2><p>Owner: كل حاجة · Editor: من غير الإعدادات والـ API · Viewer: قراءة بس</p></div><button class="btn btn-secondary btn-sm" id="addUser">${ic('plus', 'sm')}مستخدم جديد</button></div>
      <div class="table-wrap"><table class="t"><thead><tr><th>الاسم</th><th>الإيميل</th><th>الصلاحية</th><th class="hide-tab">آخر دخول</th><th></th></tr></thead><tbody>
      ${[['أدمن الحملة', 'admin@spreadagency.net', 'owner', 'دلوقتي'], ['فريق السوشيال', 'social@spreadagency.net', 'editor', 'من ساعتين'], ['سكرتارية العيادة', 'clinic@doctor-domain.com', 'viewer', 'امبارح']].map(([n, e, r, l], i) => `<tr style="cursor:default"><td><span class="name"><span class="avatar sm">${initials(n)}</span>${n}</span></td><td class="ltr mono">${e}</td><td><select class="select" style="width:130px;height:32px" ${i === 0 ? 'disabled' : ''} aria-label="الصلاحية"><option ${r === 'owner' ? 'selected' : ''}>Owner</option><option ${r === 'editor' ? 'selected' : ''}>Editor</option><option ${r === 'viewer' ? 'selected' : ''}>Viewer</option></select></td><td class="muted hide-tab">${l}</td><td>${i ? `<button class="btn btn-icon btn-ghost" style="color:var(--danger)" data-del-user aria-label="امسح">${ic('trash', 'sm')}</button>` : '<span class="badge b-gold plain">إنت</span>'}</td></tr>`).join('')}
      </tbody></table></div></section>
    <div class="grid g-1-1" style="align-items:start">
      <div class="stack" style="gap:24px">
        <section class="card"><div class="card-h"><div><h2>الاحتفاظ بالصور</h2><p>المسح التلقائي عن طريق cron</p></div></div><div class="card-b form-grid">
          <div class="field"><label for="rO">الصور الأصلية (يوم)</label><input class="input ltr" type="number" id="rO" value="30" style="text-align:left"></div>
          <div class="field"><label for="rR">الصور المتولدة (يوم)</label><input class="input ltr" type="number" id="rR" value="90" style="text-align:left"></div>
          <div class="field full"><label>صفحة المشاركة بتعرض</label><div class="seg" style="align-self:flex-start"><button class="on">"بعد" بس</button><button>قبل وبعد</button></div><span class="hint">الافتراضي "بعد" بس عشان الخصوصية</span></div></div></section>
        <section class="card"><div class="card-h"><h2>عام</h2></div><div class="card-b form-grid">
          <div class="field"><label for="tz">المنطقة الزمنية</label><select class="select" id="tz"><option>Africa/Cairo</option><option>Asia/Riyadh</option></select></div>
          <div class="field"><label>وضع الصيانة</label><label class="switch" style="height:40px"><input type="checkbox"><span class="tr"></span><span class="small">الصفحة تعرض "راجعين قريب"</span></label></div>
          <div class="field full"><label for="ipA">IP allowlist للوحة التحكم (اختياري)</label><input class="input mono" dir="ltr" id="ipA" placeholder="41.33.0.0/16, 197.55.10.4"></div>
          <div class="full row"><button class="btn btn-secondary btn-sm">${ic('shield', 'sm')}غيّر الباسورد</button><button class="btn btn-ghost btn-sm">سجل النشاط</button></div></div></section>
      </div>
      <div class="stack" style="gap:24px">
        <section class="card"><div class="card-h"><div><h2>ألوان البراند</h2><p>بتتكتب كـ CSS variables في الصفحة</p></div><button class="btn btn-ghost btn-sm" id="colReset">الافتراضي</button></div><div class="card-b stack">
          ${[['primary', 'الأساسي', '#1F73B7'], ['secondary', 'الثانوي (Navy)', '#0E2B45'], ['accent', 'لون التمييز (Gold)', '#C9A24B']].map(([k, l, v]) => `<div class="color-row"><input type="color" value="${v}" data-col="${k}" aria-label="${l}"><div style="flex:1"><b style="color:var(--ink);font-size:14px">${l}</b><div class="ltr mono muted" style="text-align:right" data-colv="${k}">${v}</div></div></div>`).join('')}
          <div class="card" style="box-shadow:none;padding:16px;display:flex;gap:8px;flex-wrap:wrap" id="colPrev"><span class="btn btn-sm" style="background:#1F73B7;color:#fff" data-cp="primary">ابدأ دلوقتي</span><span class="btn btn-sm" style="background:#0E2B45;color:#fff" data-cp="secondary">Navy</span><span class="btn btn-sm" style="background:#C9A24B;color:#0E2B45" data-cp="accent">Gold</span><span class="badge b-booked">تباين AA ✓</span></div></div></section>
        <section class="card"><div class="card-h"><h2>نص سياسة الخصوصية</h2></div><div class="card-b"><textarea class="textarea" rows="7">بنستخدم اسمك ورقمك عشان فريق الدكتور يقدر يتواصل معاك بخصوص استفسارك بس.
صورتك بتتحفظ في مكان آمن مش متاح للعامة، وبتتستخدم لإنشاء المحاكاة فقط، وبتتمسح تلقائيًا بعد مدة محددة.
مش بنشارك بياناتك مع أي طرف تالت لأغراض تسويقية. تقدر تطلب مسح بياناتك في أي وقت على واتساب.</textarea></div></section>
      </div>
    </div>`;
  BIND.settings = () => {
    bindSave();
    $$('[data-col]').forEach(i => i.oninput = () => { $(`[data-colv="${i.dataset.col}"]`).textContent = i.value.toUpperCase(); $(`[data-cp="${i.dataset.col}"]`).style.background = i.value; });
    $('#colReset').onclick = () => { render(); toast('info', 'رجعت الألوان الافتراضية'); };
    $$('[data-del-user]').forEach(b => b.onclick = () => confirmDelete({ title: 'تمسح المستخدم ده؟', body: 'مش هيقدر يدخل لوحة التحكم تاني.', onYes: () => { b.closest('tr').remove(); toast('ok', 'اتمسح المستخدم'); } }));
    $('#addUser').onclick = () => {
      openLayer(`<div class="modal" role="dialog" aria-modal="true" aria-labelledby="auT"><div class="modal-box"><h3 id="auT">مستخدم جديد</h3>
        <div class="field"><label for="auN">الاسم</label><input class="input" id="auN" data-autofocus></div>
        <div class="field"><label for="auE">الإيميل</label><input class="input" dir="ltr" id="auE" type="email"><span class="hint err" id="auErr" hidden>اكتب إيميل صحيح</span></div>
        <div class="field"><label for="auR">الصلاحية</label><select class="select" id="auR"><option>Editor</option><option>Viewer</option><option>Owner</option></select></div>
        <p class="hint">هيوصله إيميل فيه لينك يعمل منه باسورد.</p>
        <div class="row" style="justify-content:flex-end"><button class="btn btn-secondary" data-close>إلغاء</button><button class="btn btn-primary" id="auOk">ابعت الدعوة</button></div></div></div>`);
      $('#auOk').onclick = () => { const e = $('#auE'); if (!/^\S+@\S+\.\S+$/.test(e.value)) { e.classList.add('error'); $('#auErr').hidden = false; e.focus(); return; } closeLayer(); toast('ok', 'اتبعتت الدعوة', e.value); };
    };
  };

  /* ---------- Component sheet ---------- */
  VIEWS.components = () => {
    const sw = [['Primary', '#1F73B7'], ['Primary 700', '#134E80'], ['Primary 100', '#E8F3FB'], ['Navy (sidebar)', '#0E2B45'], ['Navy 2', '#143857'], ['Gold accent', '#C9A24B'], ['Gold 50', '#FBF6EA'], ['Background', '#F4F7FA'], ['Line', '#E2EAF2'], ['Text', '#1B2A38'], ['Muted', '#5B6F83'], ['Success / WA', '#137A4B'], ['Warning', '#B7791F'], ['Danger', '#C23B3B']];
    const sec = (n, t, inner) => `<section class="card"><div class="card-h"><div><span class="small" style="color:var(--primary);font-weight:700">${n}</span><h2>${t}</h2></div></div><div class="card-b">${inner}</div></section>`;
    return `
    <div class="page-head"><div><h2>شيت الكومبوننتس والحالات</h2><p>IBM Plex Sans Arabic · شبكة 8px · كروت 12px · نفس ألوان الصفحة العامة + Navy للسايدبار + Gold للتمييز</p></div></div>
    ${sec('01', 'الألوان', `<div class="sw-grid">${sw.map(([n, v]) => `<div class="sw"><i style="background:${v}"></i><b>${n}</b><span class="spec">${v}</span></div>`).join('')}</div>`)}
    ${sec('02', 'الخطوط والمسافات', `<div class="grid g3"><div class="stack-8"><span class="spec">H1 page · 24/700</span><b style="font-size:24px;color:var(--ink)">المسجلين</b><span class="spec">H2 card · 16/600</span><b style="font-size:16px;color:var(--ink)">الليدز في اليوم</b><span class="spec">Body · 15/400 · lh 1.6</span><span>ده ملخص الحملة لآخر 30 يوم.</span><span class="spec">Caption · 13 / 12</span><span class="small muted">آخر تحديث من دقيقتين</span></div>
      <div class="stack-8"><span class="spec">KPI value · 28/700</span><b class="ltr" style="font-size:28px;color:var(--ink);text-align:right">1,284</b><span class="spec">Radius</span><div class="row">${[8, 10, 12, 16].map(r => `<span style="width:48px;height:48px;border-radius:${r}px;background:var(--primary-100);border:1px solid var(--primary-200);display:grid;place-items:center;font-size:11px" class="ltr">${r}</span>`).join('')}</div></div>
      <div class="stack-8"><span class="spec">Spacing · 8px grid</span>${[8, 16, 24, 32, 48].map(s => `<div class="row"><span style="height:12px;width:${s * 2}px;background:var(--gold);border-radius:3px"></span><span class="spec">${s}</span></div>`).join('')}<span class="spec">Shadow</span><div class="card" style="height:40px"></div></div></div>`)}
    ${sec('03', 'الأزرار', `<div class="row wrap" style="gap:12px"><button class="btn btn-primary">${ic('check', 'sm')}Primary</button><button class="btn btn-secondary">Secondary</button><button class="btn btn-ghost">Ghost</button><button class="btn btn-wa">${ic('wa', 'sm')}واتساب</button><button class="btn btn-gold">Gold</button><button class="btn btn-danger">${ic('trash', 'sm')}Danger</button><button class="btn btn-danger-ghost">Danger ghost</button><button class="btn btn-primary" disabled>Disabled</button><button class="btn btn-primary" disabled><span class="spin"></span>Loading</button></div>
      <div class="row wrap" style="gap:12px;margin-top:16px"><button class="btn btn-primary btn-lg">Large 48</button><button class="btn btn-primary">Default 40</button><button class="btn btn-primary btn-sm">Small 32</button><button class="btn btn-secondary btn-icon">${ic('edit', 'sm')}</button><div class="seg"><button class="on">يومي</button><button>أسبوعي</button></div></div>`)}
    ${sec('04', 'الحقول', `<div class="form-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))"><div class="field"><label>عادي</label><input class="input" placeholder="اكتب هنا"></div><div class="field"><label>Focus</label><input class="input" value="أحمد" style="border-color:var(--primary);box-shadow:0 0 0 3px rgba(31,115,183,.15)"></div><div class="field"><label>خطأ</label><input class="input error" value="0101234"><span class="hint err">الرقم لازم يكون 11 رقم</span></div>
      <div class="field"><label>Select</label><select class="select"><option>جديد</option></select></div>${secretField('csK', 'Secret (masked)', 'sk_live_51HxQ2a8f')}<div class="field"><label>Textarea</label><textarea class="textarea" rows="2">ملاحظة…</textarea></div>
      <div class="field"><label>Toggle</label><div class="row-16"><label class="switch"><input type="checkbox" checked><span class="tr"></span>On</label><label class="switch"><input type="checkbox"><span class="tr"></span>Off</label></div></div><div class="field"><label>Checkbox</label><label class="row"><input type="checkbox" class="cbx" checked> متعلّم</label></div><div class="field"><label>Code editor</label><textarea class="code" style="min-height:60px">&lt;script&gt;…&lt;/script&gt;</textarea></div></div>`)}
    ${sec('05', 'البادجز والحالات', `<div class="row wrap" style="gap:12px">${STATUSES.map(([k]) => badge(k)).join('')}<span class="badge b-gold">Gold</span><span class="badge b-danger">خطأ</span></div>
      <div class="row wrap" style="gap:24px;margin-top:16px"><span class="status ok"><i></i>Connected</span><span class="status err"><i></i>فشل الاتصال</span><span class="status warn"><i></i>مفيش أحداث</span><span class="status"><i></i>مش متجرب</span>${genBadge('done')}${genBadge('failed')}</div>`)}
    ${sec('06', 'KPI والجداول', `<div class="kpis" style="grid-template-columns:repeat(3,minmax(0,1fr))"><div class="card kpi"><div class="top"><span class="ico">${ic('users')}</span><span class="delta up">${ic('up', 'sm')}<span class="ltr">+18.1%</span></span></div><span class="lab">ليدز</span><span class="val">1,284</span>${spark(DAYS.map(d => d.leads))}</div><div class="card kpi accent"><div class="top"><span class="ico gold">${ic('pct')}</span><span class="delta down">${ic('dn', 'sm')}<span class="ltr">-0.4</span></span></div><span class="lab">نسبة التحويل</span><span class="val">14.2%</span>${spark(DAYS.map(d => d.visits), '#C9A24B')}</div><div class="card kpi"><div class="skel" style="height:40px;width:40px"></div><div class="skel" style="height:12px;width:50%"></div><div class="skel" style="height:28px;width:70%"></div><div class="skel" style="height:32px"></div></div></div>
      <div class="card" style="margin-top:16px;box-shadow:none"><table class="t"><thead><tr><th>الاسم</th><th>الرقم</th><th>الحالة</th></tr></thead><tbody><tr><td><span class="name"><span class="avatar sm">أس</span>أحمد السيد</span></td><td>${phoneCell('01012345678')}</td><td>${badge('new')}</td></tr><tr class="sel"><td><span class="name"><span class="avatar sm">من</span>منى حسن (محدد)</span></td><td>${phoneCell('01198765432')}</td><td>${badge('booked')}</td></tr><tr>${'<td><div class="skel" style="height:14px;width:60%"></div></td>'.repeat(3)}</tr></tbody></table></div>`)}
    ${sec('07', 'فاضي · تحميل · خطأ', `<div class="grid g3"><div class="card" style="box-shadow:none">${emptyBlock('users', 'لسه مفيش مسجلين', 'أول ما حد يسجل هيظهر هنا.')}</div><div class="card" style="box-shadow:none;padding:20px" class="stack"><div class="stack">${[70, 90, 50, 80].map(w => `<div class="skel" style="height:14px;width:${w}%"></div>`).join('')}<div class="skel" style="height:120px"></div></div></div><div class="card" style="box-shadow:none">${errorBlock}</div></div>`)}
    ${sec('08', 'التنبيهات والمودال', `<div class="grid g2"><div class="stack">${[['ok', 'اتحفظت التغييرات', 'الصفحة اتحدثت على طول'], ['err', 'الويب هوك فشل', 'HTTP 500 — راجع السيرفر'], ['info', 'اتغيرت المدة', 'آخر 7 أيام']].map(([k, t, p]) => `<div class="toast ${k}" style="animation:none"><span class="ti">${ic({ ok: 'check', err: 'alert', info: 'info' }[k])}</span><div><b>${t}</b><p>${p}</p></div></div>`).join('')}
      <div class="banner"><span class="bi">${ic('clock')}</span><div class="small">Banner (gold) — المسح التلقائي شغال</div></div><div class="banner info"><span class="bi">${ic('info')}</span><div class="small">Banner (info)</div></div></div>
      <div class="modal-box" style="box-shadow:var(--shadow);border:1px solid var(--line)"><span class="modal-ico">${ic('trash', 'lg')}</span><h3>تمسح أحمد السيد؟</h3><p class="muted">هيتمسح الليد وصوره وكل الأحداث. مش هينفع ترجعه.</p><label class="row small muted"><input type="checkbox" class="cbx" checked> فاهم إن المسح نهائي</label><div class="row" style="justify-content:flex-end"><button class="btn btn-secondary">إلغاء</button><button class="btn btn-danger">${ic('trash', 'sm')}امسح</button></div><button class="btn btn-primary" id="demoModal">جرّب المودال الحقيقي</button></div></div>`)}
    ${sec('09', 'صور قبل / بعد', `<div class="gallery" style="grid-template-columns:repeat(4,minmax(0,1fr))">${[0, 1, 2, 3].map(i => `<div class="card gcard">${pair(i)}<div class="meta"><div><b>${FIRST[i]} ${LAST[i]}</b><small>8 أكتوبر</small></div>${genBadge('done')}</div></div>`).join('')}</div>`)}`;
  };
  BIND.components = () => { bindReveal(); $('#demoModal').onclick = () => confirmDelete({ title: 'تمسح أحمد السيد؟', body: 'ده مودال تجريبي — مفيش حاجة هتتمسح.', onYes: () => toast('ok', 'اتمسح (تجريبي)') }); };

  /* boot */
  if (!location.hash) history.replaceState(null, '', '#dashboard');
  const qs = new URLSearchParams(location.search);
  if (qs.get('state')) { S.viewState = qs.get('state'); $$('#stateBar button').forEach(x => x.classList.toggle('on', x.dataset.state === S.viewState)); }
  if (qs.get('collapsed')) app.classList.add('collapsed');
  if (qs.get('shot')) $('#stateBar').style.display = 'none';
  render();
  if (qs.get('drawer')) openLead(LEADS[+qs.get('drawer')].id);
  if (qs.get('modal')) confirmDelete({ title: 'تمسح أحمد السيد؟', body: 'هيتمسح الليد وصوره (الأصلية والمتولدة) وكل الأحداث. مش هينفع ترجعه.' });
  if (qs.get('toast')) { toast('ok', 'اتحفظت التغييرات', 'الصفحة اتحدثت على طول'); toast('err', 'الويب هوك فشل', 'HTTP 500 — راجع اللينك أو السيرفر التاني'); }
})();
