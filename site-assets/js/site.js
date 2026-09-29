/* ═══════════ Spread AI — حركة الموقع (مطابقة للتصميم المرجعي) ═══════════ */

(function () {
  'use strict';

  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function fmt(n) { return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

  function count(el) {
    const to = +el.dataset.to || 0;
    const suffix = el.dataset.suffix || '';
    const t0 = performance.now();
    function step(t) {
      const p = Math.min((t - t0) / 1500, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(to * eased) + suffix;
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  /* ─── استقرار العنصر ─── */
  function settle(n) {
    if (!n || n.classList.contains('on')) return;
    n.classList.add('on');
    if (n.classList.contains('pc')) {
      n.style.transform = 'translate(0,0) rotate(0deg)';
    }
  }

  /* ─── بذر البطاقات في موضعها الأولي ─── */
  function seedCards() {
    document.querySelectorAll('.pc:not(.on)').forEach(function (c) {
      if (c.style.transform) return;
      const from = (c.dataset.from || '0,40,0').split(',').map(Number);
      c.style.transform = 'translate(' + (from[0] || 0) + 'px,' + (from[1] || 0) + 'px) rotate(' + (from[2] || 0) + 'deg)';
    });
  }

  if (reduce) {
    document.querySelectorAll('.rv, .pc').forEach(function (n) { n.classList.add('on'); });
    document.querySelectorAll('.shift').forEach(function (n) { n.classList.add('dark'); });
    document.querySelectorAll('.ctr').forEach(function (n) {
      n.textContent = fmt(+n.dataset.to || 0) + (n.dataset.suffix || '');
    });
  } else {
    seedCards();

    /* مراقب الظهور بتأخير متدرّج */
    const io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e, i) {
        if (!e.isIntersecting) return;
        setTimeout(function () { settle(e.target); }, Math.min(i * 110, 480));
        io.unobserve(e.target);
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.12 });

    function observeAll() {
      seedCards();
      document.querySelectorAll('.rv:not(.on), .pc:not(.on)').forEach(function (n) { io.observe(n); });
    }
    observeAll();
    requestAnimationFrame(observeAll);
    setTimeout(observeAll, 400);
    setTimeout(observeAll, 1400);

    /* شبكة أمان */
    function fallback() {
      document.querySelectorAll('.rv:not(.on), .pc:not(.on)').forEach(function (n) {
        const r = n.getBoundingClientRect();
        if (r.top < innerHeight * 0.92 && r.bottom > 0) settle(n);
      });
    }
    addEventListener('scroll', fallback, { passive: true });
    addEventListener('resize', fallback, { passive: true });
    setTimeout(fallback, 600);

    /* تحوّل القسم للأسود عند دخوله */
    const sio = new IntersectionObserver(function (es) {
      es.forEach(function (e) { e.target.classList.toggle('dark', e.intersectionRatio > 0.35); });
    }, { threshold: [0, 0.35, 0.6] });
    document.querySelectorAll('.shift').forEach(function (n) { sio.observe(n); });

    /* عدّاد الأرقام */
    const cio = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { count(e.target); cio.unobserve(e.target); } });
    }, { threshold: 0.5 });
    document.querySelectorAll('.ctr').forEach(function (n) { cio.observe(n); });
  }

  /* ─── سلايدر الهيرو ─── */
  (function heroCarousel() {
    const stage = document.getElementById('heroStage');
    if (!stage) return;
    const slides = Array.prototype.slice.call(stage.querySelectorAll('.hs'));
    if (slides.length < 2) return;

    let idx = 0, timer = null;
    const order = ['a', 'b', 'c'];

    function paint() {
      slides.forEach(function (s, i) {
        const pos = (i - idx + slides.length) % slides.length;
        s.classList.remove('a', 'b', 'c');
        s.classList.add(order[Math.min(pos, 2)]);
        s.style.display = pos > 2 ? 'none' : '';
      });
    }
    function go(dir) { idx = (idx + (dir || 1) + slides.length) % slides.length; paint(); }
    function hold() { clearInterval(timer); if (!reduce) timer = setInterval(function () { go(1); }, 4800); }

    paint();
    const btn = document.getElementById('stageNext');
    if (btn) btn.addEventListener('click', function () { go(1); hold(); });

    let x0 = null;
    stage.addEventListener('pointerdown', function (e) { x0 = e.clientX; });
    stage.addEventListener('pointerup', function (e) {
      if (x0 === null) return;
      const dx = e.clientX - x0; x0 = null;
      if (Math.abs(dx) > 40) { go(dx < 0 ? 1 : -1); hold(); }
    });
    stage.addEventListener('mouseenter', function () { clearInterval(timer); });
    stage.addEventListener('mouseleave', hold);
    hold();
  })();

  /* ─── الخدمات اللاصقة (تبويبات + تتبع التمرير) ─── */
  (function stickyServices() {
    const tabs = Array.prototype.slice.call(document.querySelectorAll('.tab[data-tab]'));
    const panels = Array.prototype.slice.call(document.querySelectorAll('.st[data-panel]'));
    if (!tabs.length || !panels.length) return;

    function setSvc(i) {
      tabs.forEach(function (t) { t.classList.toggle('on', +t.dataset.tab === i); });
      panels.forEach(function (p) { p.classList.toggle('act', +p.dataset.panel === i); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { setSvc(+t.dataset.tab); }); });

    const marks = Array.prototype.slice.call(document.querySelectorAll('[data-mark]'));
    if (marks.length && !reduce) {
      const mio = new IntersectionObserver(function (es) {
        es.forEach(function (e) { if (e.isIntersecting) setSvc(+e.target.dataset.mark); });
      }, { rootMargin: '-45% 0px -45% 0px' });
      marks.forEach(function (m) { mio.observe(m); });
    }
    setSvc(0);
  })();

  /* ─── بارالاكس خفيف ─── */
  if (!reduce) {
    const px = Array.prototype.slice.call(document.querySelectorAll('[data-parallax]'));
    if (px.length) {
      addEventListener('scroll', function () {
        px.forEach(function (m) {
          const r = m.getBoundingClientRect();
          const p = (r.top + r.height / 2 - innerHeight / 2) / innerHeight;
          const amt = parseFloat(m.dataset.parallax) || 22;
          m.style.transform = 'translateY(' + (-p * amt) + 'px)';
        });
      }, { passive: true });
    }
  }

  /* ─── لايت بوكس ─── */
  document.addEventListener('click', function (e) {
    const img = e.target.closest('[data-lb]');
    if (!img) return;
    e.preventDefault();
    const box = document.getElementById('lb');
    const target = document.getElementById('lbImg');
    if (!box || !target) return;
    target.src = img.getAttribute('data-lb') || img.src;
    box.style.display = 'flex';
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      const b = document.getElementById('lb');
      if (b) b.style.display = 'none';
    }
  });

  /* ─── قائمة الموبايل + تمرير ناعم ─── */
  document.addEventListener('click', function (e) {
    if (e.target.closest('#navLinks a')) {
      const n = document.getElementById('navLinks');
      if (n) n.classList.remove('open');
    }
    const a = e.target.closest('a[href^="#"]');
    if (!a) return;
    const id = a.getAttribute('href').slice(1);
    if (!id) return;
    const t = document.getElementById(id);
    if (t) { e.preventDefault(); t.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); }
  });
})();
