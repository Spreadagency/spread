<?php
/**
 * Spread AI v2 — «رحلتك الأولى» (المرحلة ⑦) — petite-vue
 * زي التصميم (Onboarding / OnboardingDesktop): كارت التقدم + الخطوة الحالية + «الفكرة من الرحلة»
 * كل خطوة بتنادي نفس الـ APIs بتاعة صفحتها — فاللي بيتعمل هنا حقيقي وبيظهر في الحملة والمكتبة
 *
 * ⚠️ ممنوع <template v-if> — استخدم span.pv-c · وممنوع v-for و v-if على نفس العنصر
 * متغيرات: $jrInit
 */
?>
<div id="jr" class="jr" v-cloak>
    <header class="jr-head">
        <h2>رحلتك الأولى في Spread AI</h2>
        <p class="sub">نمشي معاك خطوة بخطوة من الهوية لحد النشر — وانت بتعمل الخطوات بنفسك.</p>
    </header>

    <div class="jr-grid">
        <!-- ═══ التقدم ═══ -->
        <section class="cf-card jr-prog" aria-label="تقدّم الرحلة">
            <div class="cf-split"><b class="jr-lbl">رحلتك الأولى</b><b class="jr-pct">{{ j.pct }}%</b></div>
            <div class="cf-bar"><span :style="{ width: Math.max(2, j.pct) + '%' }"></span></div>
            <small class="cf-muted">خلصت {{ j.done }} من {{ j.total }} خطوات — تقدر تسيبها وترجع في أي وقت.</small>
            <ol class="jr-steps">
                <li v-for="s in j.steps" :key="s.n" :class="{ done: s.done, now: s.n === cur && !finished, locked: !s.open }">
                    <button type="button" @click="open(s)" :disabled="!s.open" :aria-current="s.n === cur ? 'step' : null">
                        <span class="jr-dot"><span v-if="s.done"><?= ui_icon('check', 14) ?></span><span v-if="!s.done">{{ s.n }}</span></span>
                        <span>{{ s.t }}</span>
                    </button>
                </li>
            </ol>
        </section>

        <div class="jr-main">
            <!-- ═══ الخطوة ═══ -->
            <section class="cf-card jr-step" v-if="!finished" :key="cur">
                <span class="jr-chip">الخطوة {{ cur }} من 8</span>
                <div class="jr-step-head">
                    <span class="jr-ic" v-html="icon(step().key)"></span>
                    <div><h3>{{ step().t }}</h3><p>{{ step().tip }}</p></div>
                </div>

                <!-- التفكير -->
                <div class="cf-think jr-think" v-if="busy" aria-live="polite">
                    <div class="cf-think-top"><span class="cf-orb lg"><i></i></span>
                        <div><b>Spread AI يفكر...</b><small>{{ msg }}</small></div></div>
                    <div class="cf-think-prog" v-if="prog.total > 1">
                        <span>{{ prog.label }} <b>{{ prog.done }} من {{ prog.total }}</b></span>
                        <div class="cf-bar"><span :style="{ width: Math.round(prog.done / prog.total * 100) + '%' }"></span></div>
                    </div>
                    <div class="cf-shimmer" v-if="prog.total <= 1"></div>
                </div>

                <!-- تمت الخطوة -->
                <div class="jr-ok sx-in" v-if="!busy && ok">✓ {{ ok }}</div>

                <div class="pv-c" v-if="!busy">
                    <!-- ① الهوية -->
                    <div class="pv-c" v-if="cur === 1">
                        <div class="jr-row">
                            <input class="cf-input" dir="ltr" v-model="url" placeholder="https://yourwebsite.com" aria-label="رابط موقعك أو صفحتك" @keyup.enter="doStep()">
                        </div>
                        <p class="cf-muted" v-if="j.health.pct">هويتك دلوقتي {{ j.health.pct }}% — محتاجين {{ j.health.gate }}%<span v-if="j.health.missing.length"> · ناقص: {{ j.health.missing.join('، ') }}</span></p>
                        <p class="cf-muted">مفيش موقع؟ <a :href="base + '/brand-agent.php'">اتكلم مع المساعد الذكي</a> أو <a :href="base + '/sources.php'">ارفع ملف عن مشروعك</a>.</p>
                    </div>
                    <!-- ② أول حملة -->
                    <div class="cf-chips" v-if="cur === 2">
                        <button type="button" v-for="o in goals" :key="o.k" :class="{ on: pick === o.k }" :aria-pressed="pick === o.k ? 'true' : 'false'" @click="pick = o.k">{{ o.t }}</button>
                    </div>
                    <!-- ③ الأفكار -->
                    <div class="pv-c" v-if="cur === 3">
                        <div class="cf-chips">
                            <button type="button" v-for="n in [5, 10, 15]" :key="n" :class="{ on: pick === n }" @click="pick = n">{{ n }} {{ n === 5 || n === 10 ? 'أفكار' : 'فكرة' }}</button>
                        </div>
                        <small class="cf-muted cr" v-if="pick">{{ costs.ideas[pick] || 0 }} كريدت · رصيدك {{ balance }}</small>
                    </div>
                    <!-- ④–⑦ -->
                    <p class="cf-muted" v-if="cur >= 4 && cur <= 7 && stepNote()">{{ stepNote() }}</p>
                    <!-- ⑧ النشر -->
                    <div class="pv-c" v-if="cur === 8">
                        <p class="cf-muted" v-if="j.publish.allowed && j.publish.pages">صفحتك مربوطة ✓ — هتختار الصفحة وتجدول الحملة كلها من شاشة النشر.</p>
                        <p class="cf-muted" v-if="j.publish.allowed && !j.publish.pages">اربط صفحة فيسبوك (ومعاها إنستجرام بيزنس) علشان Spread AI ينشر في المواعيد.</p>
                        <p class="cf-muted" v-if="!j.publish.allowed">النشر المباشر مش مفعّل في باقتك — صدّر المحتوى (CSV بالتصميمات والمواعيد) وانشره بنفسك.</p>
                    </div>

                    <div class="jr-actions">
                        <button type="button" class="cf-btn primary" v-if="!(step().done && cur <= 3)" @click="doStep()" :disabled="!canAct()">{{ actLabel() }} ✨</button>
                        <a class="cf-link" :href="realLink()">افتح الصفحة الحقيقية ←</a>
                        <button type="button" class="cf-link muted" v-if="step().done && cur < 8" @click="open(j.steps[cur])">الخطوة الجاية ←</button>
                    </div>
                </div>
            </section>

            <!-- ═══ خلصت ═══ -->
            <section class="cf-card jr-end sx-in" v-if="finished">
                <span class="jr-end-ic"><?= ui_icon('check', 30) ?></span>
                <h3>خلصت رحلتك الأولى 🎉</h3>
                <p>عندك دلوقتي هوية براند، وحملة كاملة من الفكرة للنشر. الخطوة الجاية: كمّل شغلك على الصفحات الحقيقية.</p>
                <div class="jr-actions center">
                    <a class="cf-btn primary" :href="j.campaign ? base + '/campaign.php?id=' + j.campaign.id : base + '/campaigns.php'">افتح حملتي</a>
                    <a class="cf-btn ghost" :href="base + '/design-studio.php'">Design Studio</a>
                    <a class="cf-btn ghost" :href="base + '/campaign-new.php'">حملة جديدة</a>
                </div>
            </section>

            <section class="cf-card jr-idea">
                <b>الفكرة من الرحلة</b>
                <p>مش شرح نظري — كل خطوة بتعمل الفعل بنفسك، وأول ما تخلّصها بننتقل للي بعدها لوحدنا. تقدر تسيب الرحلة في أي وقت وترجع من نفس الخطوة، واللي بتعمله من الصفحات نفسها بيتحسب هنا كمان.</p>
            </section>
        </div>
    </div>

    <div class="toast-container" v-if="toast"><div class="toast" :class="toast.type">{{ toast.msg }}</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var init = <?= json_encode($jrInit, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  var base = document.querySelector('meta[name="app-base"]').content;
  var csrf = function () { return document.querySelector('meta[name="csrf-token"]').content; };
  var svg = function (d) { return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>'; };
  var ICONS = {
    brand: svg('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>'),
    campaign: svg('<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/>'),
    ideas: svg('<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.3h6c0-1 .4-1.8 1-2.3A7 7 0 0 0 12 2z"/>'),
    content: svg('<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>'),
    eval: svg('<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2-5.5-2.9-5.5 2.9 1-6.2L3 9.6l6.2-.9z"/>'),
    design: svg('<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>'),
    schedule: svg('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
    publish: svg('<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>'),
  };
  var THINK = {
    1: ['بيقرا الموقع...', 'بيستنتج الجمهور والأسلوب...', 'بيبني Brand Brain...'],
    2: ['بيجهّز الحملة...'],
    3: ['بيحلل هدف الحملة...', 'بيبحث عن زوايا إبداعية...', 'بيبني الأفكار...'],
    4: ['بيكتب المحتوى...', 'بيضبط الـ Hook...'],
    5: ['بيراجع جودة المنشور...', 'بيقيس قوة الـ Hook...'],
    6: ['بيطبق هوية البراند...', 'بيضبط التكوين...', 'بينشئ التصميم...'],
    7: ['بيوزع المحتوى على الشهر...'],
  };

  var app = SpreadApp.reactive({
    base: base, j: init.journey, balance: init.balance, cur: init.journey.current, finished: init.journey.finished,
    costs: { ideas: { 5: 0, 10: 0, 15: 0 }, content: 1, design: 2, eval: 0 },
    busy: false, msg: '', ok: '', toast: null, url: '', pick: null, prog: { done: 0, total: 0, label: '' },
    goals: [{ k: 'sales', t: 'زيادة المبيعات' }, { k: 'offer', t: 'عرض جديد' }, { k: 'awareness', t: 'زيادة الوعي' }, { k: 'season', t: 'موسم أو مناسبة' }],

    notify: function (m, t) { SpreadApp.toast(this, m, t || 'success'); },
    step: function () { return this.j.steps[this.cur - 1]; },
    icon: function (k) { return ICONS[k] || ''; },
    open: function (s) { if (!s.open || this.busy) return; this.cur = s.n; this.finished = false; this.ok = s.done ? this.doneMsg(s.n) : ''; this.pick = null; },
    doneMsg: function (n) {
      var q = this.j.counts;
      return ['', 'هويتك جاهزة — ' + this.j.health.pct + '%', 'حملتك «' + (this.j.campaign ? this.j.campaign.title : '') + '» جاهزة',
              q.selected + ' فكرة مختارة', q.posts + ' منشور و' + q.scripts + ' سكريبت جاهزين', q.evaluated + ' منشور اتقيّم',
              q.designed + ' تصميم جاهز بهوية البراند', 'اتوزّع ' + q.planned + ' منشور على الشهر', 'حملتك اتجدولت أو اتصدّرت'][n];
    },
    stepNote: function () {
      var q = this.j.counts, c = this.costs;
      var cr = window.SPREAD_CR !== false;
      if (this.cur === 4) return (q.selected - q.posts) > 0 ? (q.selected - q.posts) + ' فكرة هتتكتب' + (cr ? ' · ' + c.content + ' كريدت لكل منشور · رصيدك ' + this.balance : ' من باقة الشهر') : '';
      if (this.cur === 5) return c.eval ? (cr ? c.eval + ' كريدت لكل منشور' : 'التقييم من باقة الشهر') : 'التقييم مجاني';
      if (this.cur === 6) return 'المنشورات المعتمدة هتتصمم' + (cr ? ' · ' + c.design + ' كريدت للتصميم · رصيدك ' + this.balance : ' من حصة التصميمات');
      return '';
    },
    canAct: function () {
      if (this.busy) return false;
      if (this.cur === 1) return this.url.trim().length > 3;
      if (this.cur === 2 || this.cur === 3) return !!this.pick;
      return true;
    },
    actLabel: function () {
      if (this.cur === 8) return !this.j.publish.allowed ? 'صدّر المحتوى' : (this.j.publish.pages ? 'جهّز النشر' : 'اربط صفحتك');
      return this.step().done ? 'كمّل الباقي' : this.step().act;
    },
    realLink: function () {
      var c = this.j.campaign, map = { 3: 1, 4: 2, 5: 3, 6: 4, 7: 5, 8: 6 };
      if (this.cur === 1) return base + '/brand-brain.php';
      if (this.cur === 2 || !c) return base + '/campaigns.php';
      return base + '/campaign.php?id=' + c.id + '&stage=' + Math.min(map[this.cur], c.max_stage + 1);
    },

    /* ── التنفيذ ── */
    async think(n, work) {
      var msgs = THINK[n] || ['بيشتغل...'], k = 0, self = this;
      this.busy = true; this.ok = ''; this.msg = msgs[0]; this.prog = { done: 0, total: 0, label: '' };
      var t = setInterval(function () { k = (k + 1) % msgs.length; self.msg = msgs[k]; }, 1300);
      var r;
      try { r = await work(); } finally { clearInterval(t); this.busy = false; }
      return r;
    },
    async loop(list, label, fn) {
      var ok = 0, err = '';
      this.prog = { done: 0, total: list.length, label: label };
      for (var k = 0; k < list.length; k++) {
        var r = await fn(list[k]);
        if (r && r.ok) ok++; else { err = (r && r.error) || err; if (r && ['credits', 'quota', 'subscription', 'rate_limit', 'brand_gate', 'state'].indexOf(r.code) >= 0) break; }
        this.prog.done = k + 1;
      }
      return { ok: ok, err: err, total: list.length };
    },
    async board() {
      var r = await SpreadAPI.get('campaign-flow', { action: 'board', id: this.j.campaign.id });
      if (r.ok) { this.costs = r.costs; this.balance = r.costs.balance; }
      return r;
    },
    // الحملة لازم توصل لنفس المرحلة علشان «افتح الصفحة الحقيقية» يفتح عليها
    async advance(to) {
      var c = this.j.campaign;
      if (!c) return;
      for (var s = c.max_stage + 1; s <= to; s++) {
        var r = await SpreadAPI.post('campaigns', 'stage', { id: c.id, stage: s });
        if (!r.ok) break;
        c.max_stage = r.campaign.max_stage;
      }
    },
    async refresh(n) {
      var r = await SpreadAPI.get('journey', { action: 'state' });
      if (!r.ok) return;
      this.j = r.journey; this.balance = r.balance;
      var s = this.j.steps[n - 1];
      if (s && s.done) {
        this.ok = this.doneMsg(n);
        var self = this;
        setTimeout(function () {
          if (self.busy) return;
          if (self.j.finished) { self.finished = true; self.ok = ''; }
          else if (self.cur === n && n < 8) { self.cur = self.j.current; self.ok = ''; self.pick = null; }
        }, 1600);
      }
    },
    async doStep() {
      if (!this.canAct()) return;
      var n = this.cur, self = this, res;
      if (n === 8) return this.publishStep();
      res = await this.think(n, async function () {
        if (n === 1) {
          var a = await SpreadAPI.post('brand', 'analyze_url', { url: self.url.trim() });
          if (!a.ok) return a;
          if (a.suggested) await SpreadAPI.post('brand', 'apply_all', {});
          return a.suggested ? a : { ok: false, error: a.note || 'مالقيناش معلومات كفاية في الرابط ده — جرّب موقعك أو ارفع ملف' };
        }
        if (n === 2) return SpreadAPI.post('journey', 'start_campaign', { basis: self.pick });
        await self.board();
        var cid = self.j.campaign.id, b;
        if (n === 3) {
          var batches = Math.ceil(self.pick / 5), list = []; for (var k = 0; k < batches; k++) list.push(k);
          var r3 = await self.loop(list, 'دفعة الأفكار', function (bt) { return SpreadAPI.post('campaign-flow', 'ideas_generate', { id: cid, batch: bt, total: self.pick }); });
          await SpreadAPI.post('campaign-flow', 'idea_select_all', { id: cid, selected: 1 });
          return r3.ok ? { ok: true } : { ok: false, error: r3.err };
        }
        b = await self.board();
        if (!b.ok) return b;
        var sel = b.ideas.filter(function (i) { return i.selected; });
        if (n === 4) {
          await self.advance(2);
          var r4 = await self.loop(sel.filter(function (i) { return !i.content; }), 'بيكتب المنشور', function (i) { return SpreadAPI.post('campaign-flow', 'content_generate', { id: cid, idea_id: i.id }); });
          return r4.ok || !r4.total ? { ok: true } : { ok: false, error: r4.err };
        }
        if (n === 5) {
          await self.advance(3);
          var r5 = await self.loop(sel.filter(function (i) { return i.content && !i.eval; }), 'بيقيّم', function (i) { return SpreadAPI.post('campaign-flow', 'evaluate', { id: cid, idea_id: i.id }); });
          await SpreadAPI.post('campaign-flow', 'approve', { id: cid });
          return r5.ok || !r5.total ? { ok: true } : { ok: false, error: r5.err };
        }
        if (n === 6) {
          await self.advance(4);
          var skip = self.j.campaign.eval_skipped;
          // ⑦-ج: الفيديو مابيتصممش · الكاروسيل تصميم لكل شريحة ناقصة · الستوري 9:16
          var todo = [];
          sel.forEach(function (i) {
            if (!i.content || i.format === 'video' || !(i.approved || skip || (i.eval && i.eval.ready))) return;
            if (i.format === 'carousel') (i.slides || []).forEach(function (sl) { if (!sl.designs.length) todo.push({ i: i, slide: sl.n }); });
            else if (!i.designs.length) todo.push({ i: i, slide: 0 });
          });
          if (!todo.length) return { ok: false, error: 'مفيش منشورات معتمدة من غير تصميم — راجع التقييم في صفحة الحملة' };
          var r6 = await self.loop(todo, 'بيصمم', function (t) {
            var p = { csrf: csrf(), _quiet: '1', content_id: t.i.content.id, bulk: '1', ratio: t.i.format === 'story' ? '9:16' : '1:1', include_logo: '1' };
            if (t.slide) p.slide_no = t.slide;
            else { p.custom_prompt = btoa(unescape(encodeURIComponent(t.i.content.design_idea || ''))); p._b64 = 'custom_prompt'; }
            return ajaxPost(base + '/ajax/generate-design.php', p);
          });
          return r6.ok ? { ok: true } : { ok: false, error: r6.err || 'تعذّر التصميم' };
        }
        if (n === 7) {
          await self.advance(5);
          var r7 = await SpreadAPI.post('campaign-flow', 'auto_plan', { id: cid, platform: 'facebook', only_unplanned: 1 });
          await self.advance(6);
          return r7.ok && !r7.planned ? { ok: false, error: 'مفيش منشورات معتمدة للجدولة لسه' } : r7;
        }
      });
      if (!res || !res.ok) return this.notify((res && res.error) || 'حصلت مشكلة — جرّب تاني', 'danger');
      await this.refresh(n);
    },
    async publishStep() {
      var c = this.j.campaign;
      if (!this.j.publish.allowed) {
        var r = await SpreadAPI.post('journey', 'mark_export', {});
        if (!r.ok) return this.notify(r.error, 'danger');
        location.href = r.export;
        this.j = r.journey; this.finished = r.journey.finished;
        return;
      }
      if (!this.j.publish.pages) { location.href = base + '/social/connect.php'; return; }
      await this.advance(6);
      location.href = base + '/campaign.php?id=' + c.id + '&stage=6';
    },
  });
  SpreadApp.mount('#jr', app);
  if (app.step() && app.step().done) app.ok = app.doneMsg(app.cur);
});
</script>
