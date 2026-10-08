/* لوحة التحكم — progressive enhancement for the server-rendered admin.
   Everything works without JS except the lead drawer, live previews,
   drag-to-reorder and the "test connection" buttons. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const csrf = $('meta[name=csrf]')?.content || '';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ic = (n, cls = '') => `<svg class="ic ${cls}"><use href="#i-${n}"/></svg>`;

  /* ---------------- toasts ---------------- */
  function toast(kind, title, text = '') {
    const icon = { ok: 'check', err: 'alert', info: 'info' }[kind] || 'info';
    const el = document.createElement('div');
    el.className = 'toast ' + kind; el.setAttribute('role', kind === 'err' ? 'alert' : 'status');
    el.innerHTML = `<span class="ti">${ic(icon)}</span><div><b>${esc(title)}</b>${text ? `<p>${esc(text)}</p>` : ''}</div><button class="x" type="button" aria-label="إغلاق">${ic('x', 'sm')}</button>`;
    el.querySelector('.x').onclick = () => el.remove();
    $('#toasts').appendChild(el); setTimeout(() => el.remove(), kind === 'err' ? 7000 : 4200);
  }
  $$('#flashes span').forEach(s => toast(s.dataset.type, s.textContent));

  /* ---------------- fetch helper ---------------- */
  async function post(url, data) {
    const body = data instanceof FormData ? data : Object.entries(data).reduce((fd, [k, v]) => { Array.isArray(v) ? v.forEach(x => fd.append(k + '[]', x)) : fd.append(k, v); return fd; }, new FormData());
    if (!body.has('csrf')) body.append('csrf', csrf);
    const res = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' } });
    let json = {};
    try { json = await res.json(); } catch (_) {}
    if (!res.ok || json.error) throw new Error(json.error || 'حصلت مشكلة (' + res.status + ')');
    return json;
  }

  /* ---------------- layout ---------------- */
  const app = $('#app');
  if (app) {
    try { if (localStorage.getItem('adm_collapsed') === '1') app.classList.add('collapsed'); } catch (_) {}
    $('#collapseBtn')?.addEventListener('click', () => { app.classList.toggle('collapsed'); try { localStorage.setItem('adm_collapsed', app.classList.contains('collapsed') ? '1' : '0'); } catch (_) {} });
    $('#menuBtn')?.addEventListener('click', e => { e.stopPropagation(); app.classList.toggle('expanded'); });
    document.addEventListener('click', e => { if (app.classList.contains('expanded') && !e.target.closest('.sidebar')) app.classList.remove('expanded'); });
  }
  const meBtn = $('#meBtn'), meMenu = $('#meMenu');
  meBtn?.addEventListener('click', e => { e.stopPropagation(); meMenu.hidden = !meMenu.hidden; meBtn.setAttribute('aria-expanded', String(!meMenu.hidden)); });
  document.addEventListener('click', e => { if (meMenu && !meMenu.hidden && !e.target.closest('.menu-wrap')) { meMenu.hidden = true; meBtn.setAttribute('aria-expanded', 'false'); } });
  document.addEventListener('keydown', e => {
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { const s = $('#globalSearch'); if (s) { e.preventDefault(); s.focus(); } }
    if (e.key === 'Escape') closeLayer();
  });

  /* ---------------- generic behaviours ---------------- */
  $$('[data-autosubmit]').forEach(el => el.addEventListener('change', () => el.form && el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit()));
  document.addEventListener('click', async e => {
    const c = e.target.closest('[data-copy]');
    if (c) { e.preventDefault(); e.stopPropagation(); try { await navigator.clipboard.writeText(c.dataset.copy); toast('ok', 'اتنسخ', c.dataset.copy); } catch (_) { toast('err', 'مقدرناش ننسخ'); } }
    const r = e.target.closest('[data-reveal]');
    if (r) { const i = document.getElementById(r.dataset.reveal); if (i) { const show = i.type === 'password'; i.type = show ? 'text' : 'password'; r.innerHTML = ic(show ? 'eyeoff' : 'eye', 'sm'); } }
  });
  document.addEventListener('submit', e => {
    const form = e.target;
    if (form.dataset.confirmTitle && !form.dataset.confirmed) {
      e.preventDefault();
      confirmModal({ title: form.dataset.confirmTitle, body: form.dataset.confirmBody, cta: form.dataset.confirmCta, tone: form.dataset.confirmTone, onYes: () => { form.dataset.confirmed = '1'; form.requestSubmit ? form.requestSubmit() : form.submit(); } });
      return;
    }
    const btn = form.querySelector('[data-loading]');
    if (btn && !e.defaultPrevented) { btn.dataset.html = btn.innerHTML; setTimeout(() => { btn.disabled = true; btn.innerHTML = '<span class="spin"></span>بيتحفظ…'; }, 0); }
  });

  /* ---------------- layer: modal / drawer ---------------- */
  let lastFocus = null;
  function openLayer(html) { lastFocus = document.activeElement; $('#layer').innerHTML = html; ($('#layer [data-autofocus]') || $('#layer button'))?.focus(); }
  function closeLayer() { $('#layer').innerHTML = ''; lastFocus?.focus?.(); }
  $('#layer').addEventListener('click', e => { if (e.target.classList.contains('scrim') || e.target.classList.contains('modal') || e.target.closest('[data-close]')) closeLayer(); });

  function confirmModal({ title, body, cta = 'امسح', tone = 'danger', onYes }) {
    const danger = tone !== 'primary';
    openLayer(`<div class="modal" role="alertdialog" aria-modal="true" aria-labelledby="cfT"><div class="modal-box">
      <span class="modal-ico${danger ? '' : ' info'}">${ic(danger ? 'trash' : 'info', 'lg')}</span>
      <h3 id="cfT">${esc(title)}</h3><p class="muted">${esc(body || '')}</p>
      ${danger ? '<label class="row small muted"><input type="checkbox" class="cbx" id="cfChk"> فاهم إن ده نهائي ومش هينفع أرجّعه</label>' : ''}
      <div class="row" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-close data-autofocus>إلغاء</button><button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" type="button" id="cfYes"${danger ? ' disabled' : ''}>${danger ? ic('trash', 'sm') : ''}${esc(cta || 'تأكيد')}</button></div>
    </div></div>`);
    $('#cfChk')?.addEventListener('change', e => { $('#cfYes').disabled = !e.target.checked; });
    $('#cfYes').onclick = () => { closeLayer(); onYes && onYes(); };
  }

  /* ---------------- lead drawer ---------------- */
  async function openLead(id) {
    openLayer(`<div class="scrim"></div><aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="dwT"><div class="drawer-h"><span class="skel" style="width:40px;height:40px;border-radius:50%"></span><div style="flex:1" class="stack-8"><div class="skel" style="height:16px;width:50%"></div><div class="skel" style="height:12px;width:30%"></div></div><button class="btn btn-icon btn-secondary" type="button" data-close aria-label="إغلاق">${ic('x', 'sm')}</button></div><div class="drawer-b">${'<div class="skel" style="height:44px"></div>'.repeat(5)}</div></aside>`);
    try {
      const res = await fetch('lead.php?id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      if (res.status === 401) { location.reload(); return; }
      $('#layer .drawer').innerHTML = await res.text();
      $('#layer [data-close]')?.focus();
    } catch (_) {
      $('#layer .drawer-b').innerHTML = '<div class="err-state">' + ic('alert') + '<span>مقدرناش نحمّل البيانات. جرّب تاني.</span></div>';
    }
  }
  document.addEventListener('click', e => {
    const opener = e.target.closest('[data-open-lead]') || (e.target.closest('tr[data-lead]') && !e.target.closest('input,button,a,select,label') ? e.target.closest('tr[data-lead]') : null);
    if (opener) { openLead(opener.dataset.openLead || opener.dataset.lead); return; }
    const act = e.target.closest('[data-lead-action]');
    if (act) {
      const form = $('#leadForm'), id = form.dataset.id, action = act.dataset.leadAction;
      const run = async () => {
        const fd = new FormData(form); fd.append('id', id); fd.append('action', action);
        const html = act.innerHTML; act.disabled = true; act.innerHTML = '<span class="spin"></span>';
        try {
          const r = await post('lead.php', fd);
          toast('ok', r.message);
          if (action === 'delete') { closeLayer(); $(`tr[data-lead="${id}"]`)?.remove(); }
          else if (action === 'save') { const cell = $(`tr[data-lead="${id}"] .badge`); if (cell && r.badge) cell.outerHTML = r.badge; closeLayer(); }
          else openLead(id);
        } catch (err) { toast('err', err.message); act.disabled = false; act.innerHTML = html; }
      };
      action === 'delete' ? confirmModal({ title: act.dataset.confirmTitle, body: act.dataset.confirmBody, onYes: run }) : run();
    }
  });
  const auto = $('[data-autoopen]'); if (auto) openLead(auto.dataset.autoopen);

  /* ---------------- bulk selection ---------------- */
  const bulkBar = $('#bulkBar');
  if (bulkBar) {
    const boxes = () => $$('[data-sel]');
    const update = () => {
      const n = boxes().filter(b => b.checked).length;
      bulkBar.hidden = n === 0; $('#selCount').textContent = n;
      boxes().forEach(b => b.closest('tr').classList.toggle('sel', b.checked));
      const all = $('#selAll'); if (all) { all.checked = n > 0 && n === boxes().length; all.indeterminate = n > 0 && n < boxes().length; }
    };
    $('#selAll')?.addEventListener('change', e => { boxes().forEach(b => { b.checked = e.target.checked; }); update(); });
    boxes().forEach(b => b.addEventListener('change', update));
    $('#bulkClear')?.addEventListener('click', () => { boxes().forEach(b => { b.checked = false; }); update(); });
    $('#bulkForm')?.addEventListener('submit', e => {
      const a = $('#bulkAction')?.value ?? 'export';
      if (!a) { e.preventDefault(); toast('err', 'اختار إجراء الأول'); return; }
      if (a === 'delete' && !e.target.dataset.confirmed) {
        e.preventDefault();
        const n = boxes().filter(b => b.checked).length;
        confirmModal({ title: `تمسح ${n} مسجلين؟`, body: 'هيتمسح المسجلين وكل الصور والأحداث المرتبطة بيهم. مش هينفع ترجعهم تاني.', cta: `امسح ${n}`, onYes: () => { e.target.dataset.confirmed = '1'; e.target.requestSubmit ? e.target.requestSubmit() : e.target.submit(); } });
      }
      if (a === 'export') setTimeout(() => { $$('[data-loading]', e.target).forEach(b => { b.disabled = false; }); }, 1500);
    });
  }

  /* ---------------- counters + live previews ---------------- */
  $$('[data-counter]').forEach(inp => {
    const out = $(`.counter[data-for="${inp.id}"]`), max = +inp.dataset.counter;
    const upd = () => { if (out) { out.textContent = inp.value.length + '/' + max; out.classList.toggle('over', inp.value.length > max); } };
    inp.addEventListener('input', upd); upd();
  });
  $$('[data-preview-of]').forEach(el => {
    const src = document.getElementById(el.dataset.previewOf), fb = el.dataset.fallback && document.getElementById(el.dataset.fallback);
    const max = el.classList.contains('t1') ? 60 : el.classList.contains('d') ? 160 : 200;
    const upd = () => { const v = (src?.value || '').trim() || (fb?.value || '').trim(); el.textContent = v.length > max ? v.slice(0, max - 1) + '…' : v; };
    [src, fb].forEach(i => i && i.addEventListener('input', upd)); upd();
  });
  const ogSwitch = $('[data-og-switch]');
  ogSwitch?.addEventListener('click', e => {
    const b = e.target.closest('[data-og]'); if (!b) return;
    $$('[data-og]', ogSwitch).forEach(x => x.classList.toggle('on', x === b));
    $('[data-og-box]').classList.toggle('wa-mode', b.dataset.og === 'wa');
  });
  $$('input[type=file][data-preview]').forEach(inp => inp.addEventListener('change', () => {
    const f = inp.files[0]; if (!f) return;
    const wrap = inp.closest('.row-16'); const old = wrap.querySelector('.img-prev');
    const img = document.createElement('img'); img.className = 'img-prev'; img.alt = ''; img.src = URL.createObjectURL(f);
    old.replaceWith(img);
    if (inp.name === 'og_image') { const og = $('[data-og-img]'); if (og) og.src = img.src; }
    toast('info', 'الصورة هتترفع لما تحفظ');
  }));
  $$('[data-color]').forEach(inp => {
    const row = inp.closest('.color-row');
    inp.addEventListener('input', () => { row.querySelector('[data-color-label]').textContent = inp.value.toUpperCase(); });
    row.querySelector('[data-color-reset]')?.addEventListener('click', b => { inp.value = b.currentTarget.dataset.colorReset; inp.dispatchEvent(new Event('input')); });
  });

  /* ---------------- connection tests ---------------- */
  $$('[data-test]').forEach(btn => btn.addEventListener('click', async () => {
    const what = btn.dataset.test, out = document.getElementById('out-' + what), html = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span>بيجرب…';
    if (out) out.innerHTML = '<span class="muted">بيتصل…</span>';
    try {
      const r = await post('test.php', { what });
      if (out) out.innerHTML = `<span class="status ${r.ok ? 'ok' : 'err'}"><i></i>${esc(r.message)}</span>`;
      toast(r.ok ? 'ok' : 'err', r.ok ? 'الاتصال شغال' : 'التجربة فشلت', r.message);
    } catch (err) {
      if (out) out.innerHTML = `<span class="status err"><i></i>${esc(err.message)}</span>`;
      toast('err', 'التجربة فشلت', err.message);
    }
    btn.disabled = false; btn.innerHTML = html;
  }));

  /* ---------------- content: toggles + drag to reorder ---------------- */
  $$('[data-toggle-item]').forEach(cb => {
    cb.closest('label').addEventListener('click', e => e.stopPropagation()); // don't open the <details>
    cb.addEventListener('change', async () => {
      try { const r = await post('content.php', { action: 'toggle', id: cb.dataset.toggleItem, on: cb.checked ? 1 : 0, kind: 'items' }); toast('ok', r.message); reloadPreview(); }
      catch (err) { cb.checked = !cb.checked; toast('err', err.message); }
    });
  });
  $$('[data-sortable]').forEach(list => {
    let dragging = null;
    $$(':scope > [data-id]', list).forEach(item => {
      const handle = item.querySelector('.drag'); if (!handle) return;
      handle.addEventListener('mousedown', () => { item.draggable = true; });
      handle.addEventListener('touchstart', () => { item.draggable = true; }, { passive: true });
      handle.addEventListener('click', e => e.preventDefault());
      item.addEventListener('dragstart', e => { dragging = item; item.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
      item.addEventListener('dragend', async () => {
        item.classList.remove('dragging'); item.draggable = false; dragging = null;
        const ids = $$(':scope > [data-id]', list).map(x => x.dataset.id);
        try { const r = await post('content.php', { action: 'reorder', kind: list.dataset.sortable, ids }); toast('ok', r.message); reloadPreview(); } catch (err) { toast('err', err.message); }
      });
    });
    list.addEventListener('dragover', e => {
      if (!dragging) return; e.preventDefault();
      const after = $$(':scope > [data-id]:not(.dragging)', list).find(el => e.clientY < el.getBoundingClientRect().top + el.offsetHeight / 2);
      after ? list.insertBefore(dragging, after) : list.appendChild(dragging);
    });
  });
  function reloadPreview() { const f = $('#prevFrame'); if (f) f.src = f.src; }
  $('[data-reload-preview]')?.addEventListener('click', reloadPreview);

  /* ---------------- image zoom ---------------- */
  document.addEventListener('click', e => {
    const img = e.target.closest('img[data-zoom]'); if (!img) return;
    openLayer(`<div class="modal lightbox" role="dialog" aria-modal="true" aria-label="${esc(img.alt)}"><img src="${esc(img.src)}" alt="${esc(img.alt)}"><button class="btn btn-icon btn-secondary lb-x" type="button" data-close aria-label="إغلاق">${ic('x', 'sm')}</button></div>`);
  });
})();
