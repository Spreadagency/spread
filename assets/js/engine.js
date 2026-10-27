/* Spread AI — محرك المحتوى v3 (المرحلة D) · petite-vue */
(function () {
  'use strict';

  function start() {
    var root = document.getElementById('ce-app');
    if (!root || !window.SpreadApp) return;
    var A = window.SpreadAPI;
    var clone = function (o) { return JSON.parse(JSON.stringify(o)); };
    var STEPS = ['goal', 'services', 'personas', 'character', 'formats', 'offer', 'channel', 'boldness', 'exclusions', 'summary'];
    var blankBrief = function (ch) {
      return { goal: '', goal_other: '', all_services: false, service_ids: [], persona_ids: [], character_id: 0,
        formats: { post: 6, carousel: 2, reel: 0 }, days: 30,
        offer: { type: '', value: 0, what: '', days: 0, condition: '', condition_other: '' },
        channel: ch || 'whatsapp', boldness: 'medium', exclusions: [] };
    };
    var pollTimer = null;

    var app = {
      loading: true, busy: false, view: 'home', toast: null, worker: false,
      st: {}, opts: {}, job: null, campaigns: [], balance: 0, d: null, brandKitUrl: root.dataset.brandkit,
      w: { step: 'goal', b: blankBrief(), opts: null, est: null, estBusy: false, err: '', id: 0, saved: false },
      personaFields: [{ k: 'pain', l: 'بيعاني من' }, { k: 'fears', l: 'بيخاف من' }, { k: 'desires', l: 'نفسه في' }],
      statusLabel: { draft: 'مسودة', ready: 'جاهزة للتشغيل', queued: 'في الطابور', planning: 'بنعمل الخطة', writing: 'بنكتب', review: 'مستنية مراجعتك', done: 'خلصت', cancelled: 'اتلغت' },

      get tokensNow() {
        // تقدير تقريبي في المتصفح (نفس نسبة السيرفر تقريبًا) — الرقم النهائي بيتحسب عند الحفظ
        var s = JSON.stringify(this.d || {});
        var ar = (s.match(/[؀-ۿ]/g) || []).length;
        return Math.ceil(s.length / (4 - 1.2 * (ar / Math.max(1, s.length))));
      },

      say: function (m, t) { window.SpreadApp.toast(this, m, t); },
      fmt: function (n) { return Number(n || 0).toLocaleString('ar-EG'); },

      load: async function () {
        var r = await A.get('engine', { action: 'home' });
        if (!r.ok) { this.loading = false; this.say(r.error, 'danger'); return; }
        this.st = r.dna_state; this.opts = r.options; this.campaigns = r.campaigns; this.balance = r.balance; this.worker = r.worker;
        this.d = r.dna ? clone(r.dna) : null;
        this.job = r.dna_job;
        this.loading = false;
        if (this.job && this.job.active) this.poll(this.job.id);
      },

      /* ─── الشغلانات: متابعة كل ثانيتين — ولو الـ cron مش شغال الصفحة بتشغّل الخطوة بنفسها ─── */
      poll: function (id) {
        var self = this;
        clearTimeout(pollTimer);
        var tick = async function () {
          var r = self.worker ? await A.get('engine', { action: 'job', id: id }) : await A.post('engine', 'job_step', { id: id });
          if (!r.ok) { pollTimer = setTimeout(tick, 4000); return; }
          self.job = r.job; self.worker = r.worker;
          if (r.job.active) { pollTimer = setTimeout(tick, self.worker ? 2000 : 600); return; }
          if (r.job.status === 'done') { self.say('ملف الهوية جاهز ✓'); await self.load(); }
        };
        pollTimer = setTimeout(tick, 400);
      },
      buildDna: async function (force) {
        this.busy = true;
        var r = await A.post('engine', 'dna_build', { force: force ? 1 : 0 });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.job = r.job;
        if (r.job.active) this.poll(r.job.id);
        else if (r.existing && r.job.status === 'done') { this.say('الملف محدّث بالفعل ✓'); await this.load(); }
      },
      cancelJob: async function () {
        if (!this.job) return;
        var r = await A.post('engine', 'job_cancel', { id: this.job.id });
        clearTimeout(pollTimer);
        if (r.ok) { this.job = r.job; this.balance = r.balance; this.say('اتلغى — أي كريدت محجوز رجع لرصيدك'); }
        else this.say(r.error, 'danger');
      },

      /* ─── مراجعة الـ DNA ─── */
      openDna: function () { this.view = 'dna'; window.scrollTo(0, 0); },
      seasonsFor: function (cur) {
        var l = (this.opts.seasons || []).slice();
        if (cur && l.indexOf(cur) < 0) l.unshift(cur);
        return l;
      },
      addChip: function (list, ev, max) {
        var v = String(ev.target.value || '').trim();
        if (!v) return;
        if (list.length >= max) { this.say('أقصى عدد ' + max, 'warning'); return; }
        if (list.indexOf(v) < 0) list.push(v);
        ev.target.value = '';
      },
      saveDna: async function () {
        this.busy = true;
        var r = await A.post('engine', 'dna_save', { dna: this.d });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.d = clone(r.dna); this.st = r.dna_state;
        this.say('اتحفظ ✓ — التعديلات دي مش هتتغير لو بنيت الملف تاني');
      },
      undoDna: async function () {
        this.busy = true;
        var r = await A.post('engine', 'dna_undo', {});
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.d = clone(r.dna); this.st = r.dna_state;
        this.say('رجعنا للنسخة اللي قبلها ✓');
      },

      /* ─── الـ Wizard ─── */
      newCampaign: async function () {
        var r = await A.get('engine', { action: 'wizard' });
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.w = { step: 'goal', b: blankBrief(r.options.default_channel), opts: r.options, est: null, estBusy: false, err: '', id: 0, saved: false };
        this.balance = r.balance;
        this.view = 'wizard';
        window.scrollTo(0, 0);
      },
      openCampaign: async function (c) {
        if (['draft', 'ready'].indexOf(c.status) < 0) { this.say('الحملة دي بدأت — متابعتها بتتفتح في التحديث الجاي', 'warning'); return; }
        var o = await A.get('engine', { action: 'wizard' });
        if (!o.ok) { this.say(o.error, 'danger'); return; }
        var b = Object.assign(blankBrief(o.options.default_channel), clone(c.brief || {}));
        if (!b.offer) b.offer = blankBrief().offer;
        this.w = { step: 'summary', b: b, opts: o.options, est: null, estBusy: false, err: '', id: c.id, saved: false };
        this.balance = o.balance;
        this.view = 'wizard';
        this.estimate();
      },
      steps: function () { var g = this.w.b.goal; return STEPS.filter(function (s) { return s !== 'offer' || g === 'offer'; }); },
      stepNo: function () { return this.steps().indexOf(this.w.step) + 1; },
      pieces: function () { var f = this.w.b.formats; return (f.post || 0) + (f.carousel || 0) + (f.reel || 0); },
      toggle: function (list, v, max) {
        var i = list.indexOf(v);
        if (i >= 0) list.splice(i, 1);
        else if (list.length >= max) this.say(max === 2 ? 'خدمتين بالكتير — أو اختار «كل الخدمات»' : 'أقصى عدد ' + max, 'warning');
        else list.push(v);
      },
      customExclusions: function () { var o = this.w.opts.exclusions; return this.w.b.exclusions.filter(function (x) { return o.indexOf(x) < 0; }); },
      check: function () {
        var b = this.w.b, s = this.w.step;
        if (s === 'goal' && (!b.goal || (b.goal === 'other' && !b.goal_other.trim()))) return 'اختار هدف الحملة';
        if (s === 'services' && !b.all_services && !b.service_ids.length) return 'اختار خدمة أو اتنين (أو «كل الخدمات»)';
        if (s === 'personas' && this.w.opts.personas.length && !b.persona_ids.length) return 'اختار عميل واحد على الأقل';
        if (s === 'formats' && this.pieces() < 1) return 'حدد عدد قطعة واحدة على الأقل';
        if (s === 'formats' && this.pieces() > this.w.opts.max_pieces) return 'أقصى عدد ' + this.w.opts.max_pieces + ' قطعة';
        if (s === 'offer') {
          if (!b.offer.type) return 'اختار نوع العرض';
          if ((b.offer.type === 'percent' || b.offer.type === 'amount') && !(b.offer.value > 0)) return 'حدد قيمة العرض';
          if ((b.offer.type === 'gift' || b.offer.type === 'bundle') && !b.offer.what.trim()) return 'اكتب الهدية أو الباقة';
          if (!b.offer.condition) return 'اختار شرط العرض';
        }
        return '';
      },
      next: function () {
        var e = this.check();
        this.w.err = e;
        if (e) return;
        var l = this.steps();
        this.w.step = l[l.indexOf(this.w.step) + 1];
        if (this.w.step === 'offer' && !this.w.b.offer.days) this.w.b.offer.days = this.w.b.days; // مدة العرض = مدة الحملة لحد ما يغيّرها
        window.scrollTo(0, 0);
        if (this.w.step === 'summary') this.estimate();
      },
      prev: function () { var l = this.steps(); this.w.err = ''; this.w.step = l[Math.max(0, l.indexOf(this.w.step) - 1)]; },
      estimate: async function () {
        this.w.estBusy = true; this.w.est = null; this.w.err = '';
        var r = await A.post('engine', 'estimate', { brief: this.w.b });
        this.w.estBusy = false;
        if (!r.ok) { this.w.err = r.error; return; }
        var errs = Object.keys(r.errors || {});
        if (errs.length) { this.w.err = r.errors[errs[0]]; this.w.step = errs[0] === 'offer' ? 'offer' : (STEPS.indexOf(errs[0]) >= 0 ? errs[0] : this.w.step); return; }
        this.w.est = r.estimate; this.balance = r.balance;
      },
      saveBrief: async function () {
        this.busy = true;
        var r = await A.post('engine', 'campaign_save', { id: this.w.id, brief: this.w.b });
        this.busy = false;
        if (!r.ok) { this.w.err = r.error; return; }
        this.w.id = r.campaign.id; this.w.saved = true; this.w.est = r.campaign.estimate || this.w.est;
        this.say('اتحفظ ✓');
        var h = await A.get('engine', { action: 'home' });
        if (h.ok) this.campaigns = h.campaigns;
      },

      /* ─── نصوص الملخص ─── */
      labelOf: function (list, key) { var x = (list || []).filter(function (i) { return i.key === key; })[0]; return x ? x.label : '—'; },
      goalLabel: function () { return this.w.b.goal === 'other' ? this.w.b.goal_other : this.labelOf(this.w.opts.goals, this.w.b.goal); },
      servicesLabel: function () {
        if (this.w.b.all_services) return 'كل الخدمات';
        var ids = this.w.b.service_ids;
        return this.w.opts.services.filter(function (s) { return ids.indexOf(s.id) >= 0; }).map(function (s) { return s.name; }).join('، ') || '—';
      },
      personasLabel: function () {
        var ids = this.w.b.persona_ids;
        return this.w.opts.personas.filter(function (p) { return ids.indexOf(p.id) >= 0; }).map(function (p) { return p.name; }).join('، ') || '—';
      },
      formatsLabel: function () {
        var f = this.w.b.formats, o = this.w.opts.formats;
        return Object.keys(o).filter(function (k) { return f[k] > 0; }).map(function (k) { return f[k] + ' ' + o[k]; }).join(' · ');
      },
      offerLabel: function () {
        var o = this.w.b.offer, op = this.w.opts;
        var v = o.type === 'percent' ? 'خصم ' + o.value + '%' : (o.type === 'amount' ? 'خصم ' + o.value + ' جنيه' : o.what);
        var c = o.condition === 'other' ? o.condition_other : (op.offer_conditions[o.condition] || '');
        return v + ' · ' + o.days + ' يوم' + (c ? ' · ' + c : '');
      },
    };

    // نفس المرجع الـ reactive اللي الواجهة شغالة عليه
    var scope = window.SpreadApp.reactive(app);
    window.SpreadApp.mount(root, scope);
    scope.load();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
