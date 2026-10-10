/* Spread AI — لوحة الأدمن (المرحلة 8): الشريط العلوي · البحث · الإشعارات · تصغير القائمة */
(function () {
    'use strict';
    var body = document.body;
    if (!body || !body.classList.contains('adm2')) return;

    // 1) نقل عناصر الشريط العلوي لـ .topbar بتاع الصفحة (أو إنشاؤه)
    var tpl = document.getElementById('a2-topx');
    var main = document.querySelector('.main');
    if (tpl && main) {
        var bar = main.querySelector(':scope > .topbar');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'topbar';
            bar.innerHTML = '<button class="menu-toggle" type="button" onclick="toggleSidebar()">☰</button>';
            main.insertBefore(bar, main.firstChild);
        }
        // الفاصل القديم (flex:1) مالوش لازمة
        Array.prototype.forEach.call(bar.children, function (c) {
            if (c.tagName === 'DIV' && c.getAttribute('style') && /flex:\s*1/.test(c.getAttribute('style')) && !c.children.length) c.remove();
        });
        var mt = bar.querySelector('.menu-toggle');
        if (mt) { mt.setAttribute('aria-label', 'القائمة'); mt.type = 'button'; }
        var frag = tpl.content.cloneNode(true);
        var extras = Array.prototype.filter.call(bar.children, function (c) { return !c.classList.contains('menu-toggle') && !c.classList.contains('badge-admin'); });
        bar.appendChild(frag);
        // أي أزرار خاصة بالصفحة كانت في الـ topbar → قبل الإشعارات
        var grow = bar.querySelector('.a2-grow');
        extras.forEach(function (x) { if (grow && grow.nextSibling) bar.insertBefore(x, grow.nextSibling); });
    }

    // 1-ب) تنبيه الترحيلات المستنية — تحت الشريط العلوي
    var upd = document.getElementById('a2-upd');
    if (upd && main) {
        var topb = main.querySelector(':scope > .topbar');
        var node = upd.content.firstElementChild ? upd.content.firstElementChild.cloneNode(true) : null;
        if (node) main.insertBefore(node, topb ? topb.nextSibling : main.firstChild);
    }

    // 2) أيقونة العنوان من العنصر النشط في القائمة
    var ph = document.querySelector('.main .page-head');
    var act = document.querySelector('.sidebar .nav a.active .ico svg');
    if (ph && !ph.querySelector('.a2-ph-ico')) {
        var t = document.createElement('div');
        t.className = 'a2-ph-t';
        var els = Array.prototype.slice.call(ph.children);
        var titleEls = els.filter(function (el) { return el.tagName === 'H1' || el.classList.contains('sub'); });
        if (!titleEls.length && els.length) titleEls = [els[0]];
        var actions = els.filter(function (el) { return titleEls.indexOf(el) === -1; });
        titleEls.forEach(function (k) { t.appendChild(k); });
        ph.insertBefore(t, ph.firstChild);
        actions.forEach(function (a) { ph.appendChild(a); });
        if (act) {
            var ic = document.createElement('span');
            ic.className = 'a2-ph-ico';
            ic.appendChild(act.cloneNode(true));
            ph.insertBefore(ic, t);
        }
    }

    // 3) قوائم منسدلة (الإشعارات · الحساب)
    function pop(btnId, popId) {
        var b = document.getElementById(btnId), p = document.getElementById(popId);
        if (!b || !p) return;
        b.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = p.hidden;
            closeAll();
            p.hidden = !open;
            b.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        p.addEventListener('click', function (e) { e.stopPropagation(); });
    }
    function closeAll() {
        ['a2-bellpop', 'a2-mepop', 'a2-qpop'].forEach(function (id) { var x = document.getElementById(id); if (x) x.hidden = true; });
        ['a2-bellbtn', 'a2-mebtn'].forEach(function (id) { var x = document.getElementById(id); if (x) x.setAttribute('aria-expanded', 'false'); });
    }
    pop('a2-bellbtn', 'a2-bellpop');
    pop('a2-mebtn', 'a2-mepop');
    document.addEventListener('click', closeAll);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });

    // 4) البحث: الأقسام + البحث عن عميل
    var data = {};
    try { data = JSON.parse((document.getElementById('a2-navdata') || {}).textContent || '{}'); } catch (e) {}
    var q = document.getElementById('a2-q'), qp = document.getElementById('a2-qpop');
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function norm(s) { return String(s || '').toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي'); }
    var sel = -1;
    function render() {
        var v = q.value.trim();
        if (!v) { qp.hidden = true; return; }
        var n = norm(v), html = '', hits = (data.nav || []).filter(function (x) { return norm(x.t).indexOf(n) > -1 || norm(x.en).indexOf(n) > -1; }).slice(0, 7);
        if (hits.length) {
            html += '<div class="a2-pop-h">الأقسام</div>';
            hits.forEach(function (x) { html += '<a href="' + esc(x.u) + '">' + esc(x.t) + '<small>' + esc(x.en) + '</small></a>'; });
        }
        var sh = (data.sets || []).filter(function (x) { return norm(x.t).indexOf(n) > -1 || norm(x.k).indexOf(n) > -1; }).slice(0, 5);
        if (sh.length) {
            html += '<div class="a2-pop-h">الإعدادات</div>';
            sh.forEach(function (x) { html += '<a href="' + esc(x.u) + '">' + esc(x.t) + '<small>' + esc(x.g) + '</small></a>'; });
        }
        if (data.users) {
            html += '<div class="a2-pop-h">العملاء</div><a href="' + esc(data.users) + '?q=' + encodeURIComponent(v) + '">ابحث عن «' + esc(v) + '» في العملاء<small>Enter</small></a>';
        }
        if (!html) html = '<div class="a2-empty">مفيش قسم بالاسم ده.</div>';
        qp.innerHTML = html;
        qp.hidden = false;
        sel = -1;
    }
    if (q && qp) {
        q.addEventListener('input', render);
        q.addEventListener('focus', render);
        q.addEventListener('click', function (e) { e.stopPropagation(); });
        q.addEventListener('keydown', function (e) {
            var links = qp.querySelectorAll('a');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!links.length) return;
                sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
                links.forEach(function (l, i) { l.classList.toggle('on', i === sel); });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var go = links[sel] || links[0]; // أول قسم مطابق، ولو مفيش → البحث في العملاء
                if (go) location.href = go.getAttribute('href');
            }
        });
        document.addEventListener('keydown', function (e) {
            if ((e.key === '/' || (e.key === 'k' && (e.ctrlKey || e.metaKey))) && !/INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '')) {
                e.preventDefault(); q.focus();
            }
        });
    }

    // 5) تصغير القائمة (ديسكتوب)
    window.a2Collapse = function () {
        var on = body.classList.toggle('a2-mini');
        try { localStorage.setItem('a2-mini', on ? '1' : '0'); } catch (e) {}
    };

    // 6) العنصر النشط يبان في القائمة
    var a = document.querySelector('.sidebar .nav a.active');
    if (a && a.scrollIntoView) { try { a.scrollIntoView({ block: 'nearest' }); } catch (e) {} }
})();

/* الرسم الخطي: إخفاء/إظهار سلسلة وإعادة القياس */
(function () {
    document.querySelectorAll('.a2-lc').forEach(function (box) {
        var svg = box.querySelector('svg'); if (!svg) return;
        var W = 1000, H = 220, P = 12;
        function redraw() {
            var lines = box.querySelectorAll('polyline'), max = 1;
            lines.forEach(function (l) { if (l.style.display !== 'none') JSON.parse(l.dataset.v || '[]').forEach(function (v) { if (v > max) max = v; }); });
            lines.forEach(function (l) {
                var vals = JSON.parse(l.dataset.v || '[]'), n = Math.max(2, vals.length);
                l.setAttribute('points', vals.map(function (v, i) { return (i * W / (n - 1)).toFixed(1) + ',' + (H - P - (v / max) * (H - 2 * P)).toFixed(1); }).join(' '));
            });
        }
        box.querySelectorAll('.a2-lg').forEach(function (b) {
            b.addEventListener('click', function () {
                var on = b.getAttribute('aria-pressed') !== 'true';
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
                var l = box.querySelector('polyline[data-s="' + b.dataset.s + '"]');
                if (l) l.style.display = on ? '' : 'none';
                redraw();
            });
        });
    });
})();
