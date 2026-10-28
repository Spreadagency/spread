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
        channel: ch || 'whatsapp', boldness: 'medium', exclusions: [], reel_cover: false, skill_answers: [] };
    };
    var pollTimer = null;
    // أسئلة المجال (Skill التخصص): قايمة ثابتة {q, a} بنفس ترتيب الأسئلة — عشان v-model مايقعش على null
    var skillAnswers = function (opts, old) {
      var qs = (opts && opts.skill && opts.skill.questions) || [];
      return qs.map(function (q) {
        var hit = (old || []).filter(function (x) { return x && x.q === q; })[0];
        return { q: q, a: hit ? String(hit.a || '') : '' };
      });
    };

    var app = {
      loading: true, busy: false, view: 'home', toast: null, worker: false,
      st: {}, opts: {}, job: null, campaigns: [], balance: 0, d: null, brandKitUrl: root.dataset.brandkit,
      p: null, dirty: false, dragFrom: -1, dragOver: -1, wr: null, wc: null, openQa: {}, dz: null, dEdit: { slot: 0, frame: 0, headline: '', subline: '', template_id: 0, font: '' }, dZoom: null,
      // نتايج بعد النشر (حلقة التعلّم) — object ثابت عشان v-model
      pEdit: { slot: 0, platform: 'facebook', published_at: '', reach: 0, impressions: 0, likes: 0, comments: 0, shares: 0, saves: 0, clicks: 0, messages: 0 },
      perfFields: [{ k: 'reach', l: 'الوصول (Reach)' }, { k: 'impressions', l: 'المشاهدات' }, { k: 'likes', l: 'لايك وريأكت' }, { k: 'comments', l: 'كومنتات' },
        { k: 'shares', l: 'مشاركات' }, { k: 'saves', l: 'حفظ' }, { k: 'clicks', l: 'ضغطات على اللينك' }, { k: 'messages', l: 'رسايل / مكالمات / حجوزات' }],
      w: { step: 'goal', b: blankBrief(), opts: null, est: null, estBusy: false, err: '', id: 0, saved: false },
      personaFields: [{ k: 'pain', l: 'بيعاني من' }, { k: 'fears', l: 'بيخاف من' }, { k: 'desires', l: 'نفسه في' }],
      statusLabel: { draft: 'مسودة', ready: 'جاهزة للتشغيل', queued: 'في الطابور', planning: 'بنعمل الخطة', review: 'الخطة مستنية مراجعتك', plan_ok: 'الخطة معتمدة', writing: 'بنكتب', done: 'خلصت', cancelled: 'اتلغت' },

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
        if (this.d && this.d.brand && this.d.brand.skill === undefined) this.d.brand.skill = '';
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
        if (this.d.brand && this.d.brand.skill === undefined) this.d.brand.skill = '';
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
        this.w.b.reel_cover = !!r.options.reel_cover_default;
        this.w.b.skill_answers = skillAnswers(r.options, []);
        this.balance = r.balance;
        this.view = 'wizard';
        window.scrollTo(0, 0);
      },
      openCampaign: async function (c) {
        if (['writing', 'done'].indexOf(c.status) >= 0) { await this.openWrite(c.id); return; }
        if (['draft', 'ready'].indexOf(c.status) < 0) { await this.openPlan(c.id); return; }
        var o = await A.get('engine', { action: 'wizard' });
        if (!o.ok) { this.say(o.error, 'danger'); return; }
        var b = Object.assign(blankBrief(o.options.default_channel), clone(c.brief || {}));
        b.reel_cover = !!b.reel_cover;
        b.skill_answers = skillAnswers(o.options, b.skill_answers);
        if (!b.offer) b.offer = blankBrief().offer;
        this.w = { step: 'summary', b: b, opts: o.options, est: null, estBusy: false, err: '', id: c.id, saved: c.status === 'ready' };
        this.balance = o.balance;
        this.view = 'wizard';
        this.estimate();
      },
      skillAnswered: function () { return (this.w.b.skill_answers || []).filter(function (x) { return x.a && x.a.trim(); }); },
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
      prev: function () { var l = this.steps(); this.w.err = ''; this.w.saved = false; this.w.step = l[Math.max(0, l.indexOf(this.w.step) - 1)]; },
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

      /* ─── خطة الحملة (المرحلة E) ─── */
      backHome: async function () {
        if (this.dirty && !confirm('عندك تعديلات على الخطة مش محفوظة — تخرج من غير حفظ؟')) return;
        clearTimeout(pollTimer); this.dirty = false; this.view = 'home'; await this.load();
      },
      openPlan: async function (id) {
        var r = await A.get('engine', { action: 'plan', id: id });
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.p = r.plan; this.balance = r.balance; this.worker = r.worker; this.dirty = false;
        this.view = 'plan';
        window.scrollTo(0, 0);
        if (this.p.job && this.p.job.active) this.pollPlan(this.p.job.id, id);
      },
      startCampaign: async function (id) {
        this.busy = true;
        var r = await A.post('engine', 'campaign_start', { id: id });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.balance = r.balance;
        await this.openPlan(id);
      },
      pollPlan: function (jobId, cid) {
        var self = this;
        clearTimeout(pollTimer);
        var tick = async function () {
          if (self.view !== 'plan') return;
          var r = self.worker ? await A.get('engine', { action: 'job', id: jobId }) : await A.post('engine', 'job_step', { id: jobId });
          if (!r.ok) { pollTimer = setTimeout(tick, 4000); return; }
          self.worker = r.worker; self.p.job = r.job;
          if (r.job.active) { pollTimer = setTimeout(tick, self.worker ? 2000 : 600); return; }
          if (r.job.status === 'done') self.say(r.job.result && r.job.result.passed ? 'الخطة جاهزة وعدّت البوابة ✓' : 'الخطة جاهزة — راجع الملاحظات', r.job.result && r.job.result.passed ? 'success' : 'warning');
          else if (r.job.status === 'failed') self.say(r.job.error || 'وقفت', 'danger');
          await self.openPlan(cid);
        };
        pollTimer = setTimeout(tick, 400);
      },
      cancelPlanJob: async function () {
        var r = await A.post('engine', 'job_cancel', { id: this.p.job.id });
        clearTimeout(pollTimer);
        if (r.ok) { this.balance = r.balance; this.say('اتلغى — الكريدت المحجوز رجع لرصيدك'); await this.openPlan(this.p.campaign.id); }
        else this.say(r.error, 'danger');
      },
      editable: function () { return this.p && this.p.campaign.status === 'review' && !(this.p.job && this.p.job.active); },
      move: function (i, d) {
        var j = i + d, l = this.p.slots;
        if (j < 0 || j >= l.length) return;
        var x = l.splice(i, 1)[0]; l.splice(j, 0, x);
        this.dirty = true;
      },
      dropAt: function (i) {
        var f = this.dragFrom;
        this.dragOver = -1; this.dragFrom = -1;
        if (f < 0 || f === i) return;
        var l = this.p.slots, x = l.splice(f, 1)[0]; l.splice(i, 0, x);
        this.dirty = true;
      },
      savePlan: async function () {
        this.busy = true;
        var slots = this.p.slots.map(function (s) {
          return { id: s.id, locked: s.locked ? 1 : 0, format: s.format, pillar: s.pillar, angle: s.angle, service_id: s.service_id,
            persona_id: s.persona_id, cta_level: s.cta_level, funnel: s.funnel };
        });
        var r = await A.post('engine', 'plan_save', { id: this.p.campaign.id, slots: slots });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.p = r.plan; this.dirty = false;
        this.say(r.plan.hard ? 'اتحفظ — بس فيه قواعد متكسّرة لازم تتصلّح' : 'اتحفظ ✓', r.plan.hard ? 'warning' : 'success');
      },
      planJob: async function (action) {
        this.busy = true;
        var r = await A.post('engine', action, { id: this.p.campaign.id });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.balance = r.balance;
        await this.openPlan(this.p.campaign.id);
      },
      approvePlan: async function () {
        this.busy = true;
        var r = await A.post('engine', 'plan_approve', { id: this.p.campaign.id });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.p = r.plan;
        this.say('الخطة اتعتمدت ✓');
      },

      /* ─── الكتابة والجودة (المرحلة F) ─── */
      openWrite: async function (id) {
        var r = await A.get('engine', { action: 'write', id: id });
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.wr = r.write; this.wc = r.campaign; this.dz = r.design; this.balance = r.balance; this.worker = r.worker;
        if (this.view !== 'write') { this.view = 'write'; this.openQa = {}; this.dEdit.slot = 0; window.scrollTo(0, 0); }
        if (this.wr.job && this.wr.job.active) this.pollWrite(this.wr.job.id, id);
        else if (this.dz && this.dz.job && this.dz.job.active) this.pollWrite(this.dz.job.id, id, true);
      },
      startWrite: async function () {
        var id = this.p.campaign.id;
        this.busy = true;
        var r = await A.post('engine', 'write_start', { id: id });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.balance = r.balance;
        await this.openWrite(id);
      },
      pollWrite: function (jobId, cid, design) {
        var self = this, seen = -1;
        clearTimeout(pollTimer);
        var tick = async function () {
          if (self.view !== 'write') return;
          var r = self.worker ? await A.get('engine', { action: 'job', id: jobId }) : await A.post('engine', 'job_step', { id: jobId });
          if (!r.ok) { pollTimer = setTimeout(tick, 4000); return; }
          self.worker = r.worker;
          if (design) self.dz.job = r.job; else self.wr.job = r.job;
          // خانة خلصت ← حدّث الكروت من غير ما نستنى الحملة كلها
          var st = r.job.progress;
          if (st !== seen) { seen = st; var w = await A.get('engine', { action: 'write', id: cid }); if (w.ok) { self.wr.slots = w.write.slots; self.dz.by_slot = w.design.by_slot; } }
          if (r.job.active) { pollTimer = setTimeout(tick, self.worker ? 2500 : 600); return; }
          if (r.job.status === 'done') self.say(design ? 'التصميمات جاهزة ✓' : 'المحتوى جاهز ✓');
          else if (r.job.status === 'failed') self.say(r.job.error || 'وقفت', 'danger');
          await self.openWrite(cid);
        };
        pollTimer = setTimeout(tick, 400);
      },
      cancelWriteJob: async function () {
        var r = await A.post('engine', 'job_cancel', { id: this.wr.job.id });
        clearTimeout(pollTimer);
        if (r.ok) { this.balance = r.balance; this.say('اتلغى — اتحسب اللي اتكتب بس والباقي رجع لرصيدك'); await this.openWrite(this.wc.id); }
        else this.say(r.error, 'danger');
      },

      /* ─── التصميم (المرحلة G) ─── */
      designBusy: function () { return this.dz && this.dz.job && this.dz.job.active; },
      designsOf: function (s) { return (this.dz && this.dz.by_slot && this.dz.by_slot[s.id]) || []; },
      tplsFor: function (fmt) { return ((this.dz && this.dz.templates) || []).filter(function (t) { return t.formats.indexOf(fmt) >= 0; }); },
      dStatus: { pending: 'لسه', generating: 'بنصمم…', pass: 'جاهز', manual: 'محتاج مراجعتك', failed: 'ماتصممش' },
      dTone: function (d) { return ({ pass: 'ok', manual: 'warn', failed: 'bad' })[d.status] || ''; },
      startDesign: async function () {
        this.busy = true;
        var r = await A.post('engine', 'design_start', { id: this.wc.id, reel_cover: this.dz.reel_cover ? 1 : 0 });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.balance = r.balance;
        await this.openWrite(this.wc.id);
      },
      cancelDesignJob: async function () {
        var r = await A.post('engine', 'job_cancel', { id: this.dz.job.id });
        clearTimeout(pollTimer);
        if (r.ok) { this.balance = r.balance; this.say('اتلغى — اتحسب اللي اتصمم بس والباقي رجع لرصيدك'); await this.openWrite(this.wc.id); }
        else this.say(r.error, 'danger');
      },
      redoDesign: async function (s, d) {
        if (!confirm('نعمل صورة جديدة للتصميم ده؟ (بيتخصم كريدت صورة)')) return;
        this.busy = true;
        var r = await A.post('engine', 'design_redo', { id: this.wc.id, slot_id: s.id, frame: d.frame });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.balance = r.balance;
        await this.openWrite(this.wc.id);
      },
      editDesign: function (s, d) {
        // كائن ثابت (مش null) — الحقول المربوطة بـ v-model مابتقراش من null وهي بتتشال
        Object.assign(this.dEdit, { slot: s.id, frame: d.frame, headline: d.headline || '', subline: d.subline || '', template_id: d.template_id || ((this.tplsFor(s.format)[0] || {}).id || 0), font: d.font || '' });
      },
      saveDesignText: async function () {
        var e = this.dEdit;
        this.busy = true;
        var r = await A.post('engine', 'design_text', { id: this.wc.id, slot_id: e.slot, frame: e.frame, headline: e.headline, subline: e.subline, template_id: e.template_id, font: e.font });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.dz = r.design; this.dEdit.slot = 0;
        this.say('اتحدّث ✓');
      },
      acceptDesign: async function (s, d) {
        var r = await A.post('engine', 'design_accept', { id: this.wc.id, slot_id: s.id, frame: d.frame });
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.dz = r.design;
        this.say('اتعتمد ✓');
      },
      swapIdea: async function (s, idea) {
        if (!confirm(idea.selected ? 'نكتب نفس الفكرة من جديد؟' : 'نكتب الخانة دي بالفكرة دي بدل الحالية؟')) return;
        this.busy = true;
        var r = await A.post('engine', 'idea_swap', { id: this.wc.id, slot_id: s.id, idea_id: idea.id });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.balance = r.balance;
        await this.openWrite(this.wc.id);
      },
      perfOpen: function (s, ev) {
        if (!ev.target.open) return;
        var p = s.content.perf || {}, e = this.pEdit;
        var today = new Date().toISOString().slice(0, 10), d = s.date && s.date <= today ? s.date : '';
        e.slot = s.id; e.platform = p.platform || 'facebook'; e.published_at = p.published_at || d;
        this.perfFields.forEach(function (f) { e[f.k] = p[f.k] || 0; });
      },
      savePerf: async function (s) {
        var e = this.pEdit, perf = { platform: e.platform, published_at: e.published_at };
        this.perfFields.forEach(function (f) { perf[f.k] = +e[f.k] || 0; });
        this.busy = true;
        var r = await A.post('engine', 'perf_save', { id: this.wc.id, content_id: s.content.id, perf: perf });
        this.busy = false;
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.wr = r.write;
        this.say('اتحفظت ✓ — معدل التفاعل ' + r.perf.eng_rate + '%');
      },
      acceptContent: async function (s) {
        var r = await A.post('engine', 'content_accept', { id: this.wc.id, slot_id: s.id });
        if (!r.ok) { this.say(r.error, 'danger'); return; }
        this.wr = r.write;
        this.say('اتعتمدت ✓');
      },
      writeBusy: function () { return (this.wr && this.wr.job && this.wr.job.active) || this.designBusy(); },
      fmtLabel: { post: 'بوست', carousel: 'كاروسيل', reel: 'ريلز' },
      dimLabel: { balance: 'التوازن', non_repetition: 'عدم التكرار', coverage: 'التغطية', sequence: 'التسلسل', cta: 'الـ CTA', goal_fit: 'مناسبة الهدف' },
      briefLabel: { layout: 'التكوين', focal_subject: 'العنصر الأساسي', characters_refs: 'الشخصيات', colors_hex: 'الألوان', headline: 'العنوان', subline: 'السطر التاني', text_safe_zone: 'مكان النص', mood: 'الإحساس', lighting: 'الإضاءة', camera_angle: 'زاوية الكاميرا', aspect_ratio: 'المقاس' },
      cStatus: { pending: 'لسه', writing: 'بنكتب…', pass: 'عدّت الجودة', manual: 'محتاجة مراجعتك', failed: 'ماتكتبتش' },
      cTone: function (s) { var c = s.content; return !c ? '' : ({ pass: 'ok', manual: 'warn', failed: 'bad' }[c.status] || ''); },
      altIdeas: function (s) { return (s.ideas || []).filter(function (i) { return !i.selected && !i.dup; }).slice(0, 4); },
      pickedIdea: function (s) { return (s.ideas || []).filter(function (i) { return i.selected; })[0] || null; },
      copyContent: function (s) {
        var b = (s.content && s.content.body) || {}, t = [];
        if (b.hooks) t.push(b.hooks[0], '', b.body || '');
        if (b.slides) b.slides.forEach(function (x) { t.push(x.n + '. ' + x.text); });
        if (b.scenes) b.scenes.forEach(function (x) { t.push('[' + x.time + '] ' + (x.voiceover || x.on_screen_text)); });
        if (b.caption) t.push('', b.caption);
        if (b.cta) t.push('', b.cta);
        if (b.hashtags) t.push('', b.hashtags.join(' '));
        var self = this;
        navigator.clipboard.writeText(t.join('\n')).then(function () { self.say('اتنسخ ✓'); }, function () { self.say('مقدرناش ننسخ', 'danger'); });
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
