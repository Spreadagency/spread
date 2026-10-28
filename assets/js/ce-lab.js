/* Spread AI — الأدمن: البرومبتات والـ Playground (المرحلة H) · petite-vue */
(function () {
  'use strict';
  var root = document.getElementById('lab');
  if (!root || !window.PetiteVue) return;
  var API = root.dataset.api, CSRF = root.dataset.csrf;

  function b64(o) { return btoa(unescape(encodeURIComponent(JSON.stringify(o)))); }
  async function get(params) {
    var q = new URLSearchParams(params).toString();
    try { var r = await fetch(API + '?' + q, { credentials: 'same-origin' }); return await r.json(); }
    catch (e) { return { ok: false, error: 'مشكلة في الاتصال' }; }
  }
  async function post(a, data) {
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('a', a); fd.append('p', b64(data || {}));
    try { var r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }); return await r.json(); }
    catch (e) { return { ok: false, error: 'مشكلة في الاتصال' }; }
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var app = PetiteVue.reactive({
    st: null, sd: null, stage: '', tab: 'prompt', busy: false, toast: { m: '', t: 'ok' },
    tabs: { prompt: 'البرومبت والنسخ', play: 'الـ Playground', cases: 'حالات الاختبار', runs: 'اختبارات الرجوع' },
    runLabel: { running: 'شغال', passed: 'عدّى', warn: 'تحذير', failed: 'فشل', error: 'خطأ' },
    edBody: '', edNotes: '', edFrom: 'النسخة الشغالة', diffX: 0, diffY: 0, diff: null,
    pg: { uid: 0, format: 'post', src: 'active', models: [], user: '', images: [], meta: {}, vars: {}, results: [], pipeline: false },
    pipe: [], pipeAt: 0, run: null, ackWarn: false,

    say: function (m, t) { var self = this; this.toast.m = m || ''; this.toast.t = t || 'ok'; clearTimeout(this._tt); this._tt = setTimeout(function () { self.toast.m = ''; }, 3500); },
    stageLabel: function (k) { var s = (this.st.stages || []).filter(function (x) { return x.key === k; })[0]; return s ? s.label : k; },

    load: async function () {
      var r = await get({ a: 'state' });
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      this.st = r;
      var h = (location.hash || '').replace('#', '');
      await this.openStage(r.stages.some(function (s) { return s.key === h; }) ? h : r.stages[0].key);
    },
    openStage: async function (k) {
      // sd مابيبقاش null (الواجهة بتقرا منه وهي بتتحدّث) — بيتبدّل لما المرحلة الجديدة توصل
      this.stage = k; this.diff = null; this.run = null; this.pg.results = [];
      history.replaceState(null, '', '#' + k);
      await this.reloadStage(true);
    },
    reloadStage: async function (resetEditor) {
      var r = await get({ a: 'stage', stage: this.stage });
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      this.sd = r;
      var act = r.versions.filter(function (v) { return v.is_active; })[0];
      if (resetEditor) {
        this.edBody = act ? act.body : r['default']; this.edNotes = ''; this.edFrom = act ? 'v' + act.version + ' (الشغالة)' : 'v0 (الكود)';
        this.diffX = act ? act.id : 0; this.diffY = r.versions.length ? r.versions[0].id : 0;
        this.pg.models = r.current_model && r.models.some(function (m) { return m.key === r.current_model; }) ? [r.current_model] : [];
        this.pg.user = ''; this.pg.images = [];
      }
      var me = this;
      this.st.stages.forEach(function (s) { if (s.key === me.stage) { s.active_version = act ? act.version : 0; s.cases = r.cases.filter(function (c) { return c.is_active; }).length; } });
      setTimeout(function () { me.syncScroll(); }, 30);
    },
    versionsAll: function () {
      if (!this.sd) return [];
      var anyActive = this.sd.versions.some(function (v) { return v.is_active; });
      return this.sd.versions.concat([{ id: 0, version: 0, body: this.sd['default'], notes: 'البرومبت اللي في الكود (افتراضي)', author: '', created_at: '', is_active: !anyActive, was_active: true, run: null }]);
    },
    versionNo: function (id) { var v = this.sd.versions.filter(function (x) { return x.id === id; })[0]; return v ? v.version : '?'; },

    /* ─── المحرر ─── */
    hl: function (t) {
      if (!this.sd) return '';
      var known = (this.sd.vars || []).map(function (v) { return v.key; });
      return esc(t).replace(/\{\{\s*([a-z_]+)\s*\}\}/g, function (m, k) { return '<mark class="' + (known.indexOf(k) >= 0 ? 'ok' : 'bad') + '">' + m + '</mark>'; });
    },
    unknownVars: function () {
      if (!this.sd) return [];
      var known = (this.sd.vars || []).map(function (v) { return v.key; }), out = [];
      (this.edBody.match(/\{\{\s*([a-z_]+)\s*\}\}/g) || []).forEach(function (m) { var k = m.replace(/[{}\s]/g, ''); if (known.indexOf(k) < 0 && out.indexOf(k) < 0) out.push(k); });
      return out;
    },
    syncScroll: function () { var t = this.$refs && this.$refs.ed, h = this.$refs && this.$refs.hl; if (t && h) { h.scrollTop = t.scrollTop; h.scrollLeft = t.scrollLeft; } },
    insertVar: function (k) {
      var t = this.$refs.ed, tag = '{{' + k + '}}';
      var s = t ? t.selectionStart : this.edBody.length, e = t ? t.selectionEnd : s;
      this.edBody = this.edBody.slice(0, s) + tag + this.edBody.slice(e);
      var self = this;
      setTimeout(function () { if (t) { t.focus(); t.selectionStart = t.selectionEnd = s + tag.length; } self.syncScroll(); }, 0);
    },
    loadToEditor: function (v) { this.edBody = v.body; this.edFrom = 'v' + v.version; this.tab = 'prompt'; var self = this; setTimeout(function () { self.syncScroll(); }, 30); },
    saveVersion: async function () {
      this.busy = true;
      var r = await post('prompt_save', { stage: this.stage, body: this.edBody, notes: this.edNotes });
      this.busy = false;
      if (!r.ok) { this.say(r.error, 'bad'); return null; }
      this.say('اتحفظت v' + r.version + ' — اختبرها قبل التفعيل');
      await this.reloadStage(false);
      // المقارنة الافتراضية: الشغالة ↔ الجديدة
      var act = this.sd.versions.filter(function (v) { return v.is_active; })[0];
      this.diffX = act ? act.id : 0; this.diffY = r.id;
      return r;
    },
    showDiff: async function () {
      var r = await get({ a: 'diff', stage: this.stage, x: this.diffX, y: this.diffY });
      this.diff = r.ok ? r.diff : null;
    },
    activate: async function (v) {
      var rollback = v.was_active || v.id === 0;
      if (!confirm(rollback ? 'ترجع لـ v' + v.version + '؟ المحرك هيستخدمها فورًا.' : 'تفعّل v' + v.version + '؟ المحرك هيستخدمها فورًا.')) return;
      this.busy = true;
      var r = await post('prompt_activate', { stage: this.stage, version_id: v.id, ack: v.run && v.run.status === 'warn' });
      this.busy = false;
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      this.say(rollback ? 'رجعنا لـ v' + v.version + ' ✓' : 'v' + v.version + ' شغالة ✓');
      await this.reloadStage(false);
    },

    /* ─── الـ Playground ─── */
    tryDraft: function () { this.pg.src = 'draft'; this.tab = 'play'; if (!this.pg.user) this.loadInput(); },
    loadInput: async function () {
      this.busy = true;
      var r = await post('input', { stage: this.stage, uid: this.pg.uid, format: this.pg.format, prev: this.pipePrev() });
      this.busy = false;
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      this.pg.user = r.user; this.pg.images = r.images || []; this.pg.meta = r.meta || {}; this.pg.vars = r.vars || {};
    },
    srcPayload: function () {
      if (this.pg.src === 'draft') return { draft: this.edBody };
      if (this.pg.src && this.pg.src.charAt(0) === 'v') return { version: +this.pg.src.slice(1) };
      return { version: null };
    },
    runPlay: async function () {
      this.busy = true; this.pg.results = [];
      var r = await post('run', Object.assign({ stage: this.stage, user: this.pg.user, images: this.pg.images, meta: this.pg.meta, vars: this.pg.vars, models: this.pg.models }, this.srcPayload()));
      this.busy = false;
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      this.pg.results = r.results.map(function (x) { x.critic = null; return x; });
    },
    runCritic: async function (res) {
      this.busy = true;
      var r = await post('critic', { stage: this.stage, output: res.data, meta: this.pg.meta });
      this.busy = false;
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      res.critic = r.score;
    },
    criticLine: function (c) {
      if (!c.critic) return c.issues && c.issues.length ? c.issues.join(' · ') : 'فحص الشكل';
      var k = c.critic;
      if (k.total !== undefined) return 'ناقد الجودة · ' + k.verdict;
      if (k.best !== undefined) return 'أحسن فكرة عند ناقد الاختيار (المتوسط ' + k.avg + ')';
      if (k.hard !== undefined) return 'ناقد الخطة · ' + k.hard + ' قاعدة متكسّرة';
      if (k.agreement !== undefined) return 'قرب من المتوقع (' + k.compared + ' رقم)';
      return '';
    },
    saveCase: async function (res) {
      var name = prompt('اسم حالة الاختبار:', this.stageLabel(this.stage) + ' — ' + (this.st.brands.filter(function (b) { return b.uid === app.pg.uid; })[0] || {}).name);
      if (name === null) return;
      var min = prompt('أقل درجة مقبولة (0-100):', res.critic ? Math.max(0, Math.floor(res.critic.score - 10)) : 60);
      if (min === null) return;
      this.busy = true;
      var r = await post('case_save', { stage: this.stage, name: name, user: this.pg.user, vars: this.pg.vars, images: this.pg.images, meta: this.pg.meta, min_score: +min || 0, expected: res.data });
      this.busy = false;
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      this.say('اتحفظت كحالة اختبار ✓');
      await this.reloadStage(false);
    },
    promoteDraft: async function () {
      if (!this.edNotes) this.edNotes = 'من الـ Playground';
      var s = await this.saveVersion();
      if (s) await this.startRegression(s.id, null);
    },

    /* ─── الـ Pipeline ─── */
    pipeReset: function () {
      var self = this;
      this.pipe = this.st.pipeline.map(function (k) { return { stage: k, out: null, outText: '', score: null }; });
      this.pipeAt = 0;
    },
    pipePrev: function () {
      var prev = {};
      (this.pipe || []).forEach(function (p) { if (p.out) prev[p.stage] = p.out; });
      return prev;
    },
    pipeApply: function (i) {
      try { this.pipe[i].out = JSON.parse(this.pipe[i].outText); this.say('اتعدّل ✓ — المرحلة الجاية هتاخده'); }
      catch (e) { this.say('الـ JSON فيه غلط', 'bad'); }
    },
    pipeRun: async function (i) {
      var p = this.pipe[i], prev = this.pipePrev();
      // التعديلات المكتوبة في المرحلة اللي فاتت بتتاخد
      if (i > 0 && this.pipe[i - 1].outText) { try { prev[this.pipe[i - 1].stage] = JSON.parse(this.pipe[i - 1].outText); } catch (e) { this.say('ناتج المرحلة اللي فاتت فيه غلط في الـ JSON', 'bad'); return; } }
      this.busy = true;
      var inp = await post('input', { stage: p.stage, uid: this.pg.uid, format: this.pg.format, prev: prev });
      if (!inp.ok) { this.busy = false; this.say(inp.error, 'bad'); return; }
      var r = await post('run', { stage: p.stage, user: inp.user, images: inp.images, meta: inp.meta, vars: inp.vars, models: [] });
      this.busy = false;
      if (!r.ok || !r.results[0].ok) { this.say((r.results && r.results[0].error) || r.error || 'فشل', 'bad'); return; }
      p.out = r.results[0].data; p.outText = JSON.stringify(p.out, null, 2); p.score = r.results[0].check.valid ? '✓ الشكل' : '⚠';
      this.pipeAt = Math.min(this.pipe.length, i + 1);
    },

    /* ─── حالات الاختبار ─── */
    seedCase: async function () {
      this.busy = true;
      var r = await post('case_seed', { stage: this.stage });
      this.busy = false;
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      this.say('اتعملت حالة اختبار ✓');
      await this.reloadStage(false);
    },
    caseToggle: async function (c) { await post('case_toggle', { id: c.id }); await this.reloadStage(false); },
    caseDelete: async function (c) { if (!confirm('تمسح الحالة دي؟')) return; await post('case_delete', { id: c.id }); await this.reloadStage(false); },

    /* ─── اختبارات الرجوع ─── */
    runChip: function (s) { return { passed: 'chip-mint', warn: 'chip-amber', failed: 'chip-coral', error: 'chip-coral', running: 'chip-sky' }[s] || ''; },
    startRegression: async function (versionId, model) {
      this.busy = true;
      var r = await post('regression_start', { stage: this.stage, version_id: versionId, model: model });
      this.busy = false;
      if (!r.ok) {
        this.say(r.error, 'bad');
        if (r.code === 'no_cases') this.tab = 'cases';
        return;
      }
      this.tab = 'runs'; this.ackWarn = false;
      await this.openRun(r.id, true);
    },
    openRun: async function (id, step) {
      var r = await get({ a: 'run_get', id: id });
      if (!r.ok) return;
      this.run = r.run; this.tab = 'runs'; this.ackWarn = false;
      if (r.run.status === 'running' || step) this.stepRun();
    },
    stepRun: async function () {
      while (this.run && this.run.status === 'running' && this.tab === 'runs') {
        var r = await post('regression_step', { id: this.run.id });
        if (!r.ok) { this.say(r.error, 'bad'); return; }
        this.run = r.run;
      }
      if (this.run && this.run.status !== 'running') { this.say('الاختبار خلص: ' + this.runLabel[this.run.status], this.run.status === 'passed' ? 'ok' : 'bad'); await this.reloadStage(false); }
    },
    activateRun: async function (run) {
      var v = this.sd.versions.filter(function (x) { return x.id === run.candidate_version_id; })[0];
      if (!v) return;
      this.busy = true;
      var r = await post('prompt_activate', { stage: this.stage, version_id: v.id, ack: this.ackWarn });
      this.busy = false;
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      this.say('v' + v.version + ' شغالة ✓');
      await this.reloadStage(false);
    },
    applyModel: async function (run) {
      if (!confirm('الموديل ' + run.candidate_model + ' هيبقى الأساسي للمرحلة دي (والحالي بديل). تكمّل؟')) return;
      this.busy = true;
      var r = await post('model_apply', { stage: this.stage, model: run.candidate_model, ack: this.ackWarn });
      this.busy = false;
      if (!r.ok) { this.say(r.error, 'bad'); return; }
      this.say('اتطبّق ✓');
      await this.reloadStage(false);
    },

    /* ─── العرض ─── */
    pretty: function (d) { return typeof d === 'string' ? d : JSON.stringify(d, null, 2); },
    preview: function (d) {
      var s = this.stage, h = '';
      if (s === 'ce_writer') {
        if (d.hooks) h += '<b class="lab-hook">' + esc(d.hooks[0]) + '</b><p>' + esc(d.body || '').replace(/\n/g, '<br>') + '</p>';
        if (d.slides) h += '<ol>' + d.slides.map(function (x) { return '<li>' + esc(x.text || x) + '</li>'; }).join('') + '</ol>';
        if (d.scenes) h += d.scenes.map(function (x) { return '<div class="lab-scene"><b>' + esc(x.time) + '</b> ' + esc(x.voiceover || x.on_screen_text) + '</div>'; }).join('');
        if (d.cta) h += '<p class="lab-cta">👉 ' + esc(d.cta) + '</p>';
        if (d.design_brief && d.design_brief.headline) h += '<small>🎨 ' + esc(d.design_brief.headline) + '</small>';
        return h;
      }
      if (s === 'ce_ideation' && d.ideas) return d.ideas.map(function (i) { return '<div class="lab-idea"><b>' + esc(i.hook) + '</b><small>' + esc(i.trigger) + ' · ' + esc(i.core_insight) + '</small></div>'; }).join('');
      if (s === 'ce_matrix' && d.slots) return '<ol class="lab-slots">' + d.slots.slice(0, 12).map(function (x) { return '<li>' + esc(x.funnel_stage) + ' · ' + esc(x.format) + ' · ' + esc(x.angle) + ' · ' + esc(x.cta_level) + '</li>'; }).join('') + '</ol>';
      if (s === 'ce_design_prompt' && d.prompt) return '<p dir="ltr">' + esc(d.prompt) + '</p>';
      var nums = [];
      (function walk(o, p) { Object.keys(o || {}).forEach(function (k) { var v = o[k]; if (typeof v === 'number') nums.push([p + k, v]); else if (v && typeof v === 'object' && !Array.isArray(v)) walk(v, p + k + '.'); }); })(d, '');
      if (nums.length) return nums.slice(0, 14).map(function (n) { return '<div class="lab-bar"><span>' + esc(n[0].replace('.score', '')) + '</span><i style="width:' + Math.max(2, Math.min(100, n[1])) + '%"></i><b>' + n[1] + '</b></div>'; }).join('') + (d.verdict ? '<p><b>' + esc(d.verdict) + '</b></p>' : '');
      return '';
    },
  });

  PetiteVue.createApp(app).mount(root);
  app.load();
})();
