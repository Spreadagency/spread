/* Spread AI — مركز الهوية (Brand Kit) · petite-vue */
(function () {
  'use strict';

  function start() {
    var root = document.getElementById('bk-app');
    if (!root || !window.SpreadApp) return;
    var D = root.dataset;
    var A = window.SpreadAPI;
    var lines = function (t) { return String(t || '').split(/[\n،,]+/).map(function (x) { return x.trim(); }).filter(Boolean); };
    var clone = function (o) { return JSON.parse(JSON.stringify(o)); };

    var app = {
      loading: true, busy: false, s: null, open: '', modal: null, toast: null,
      ed: null, prop: {}, regenText: {}, newTopic: {}, sugTopics: { sid: -1, list: [], pick: [] },
      fm: [], dragI: -1, svc: null, ch: null, versions: [],
      createUrl: D.create, sourcesUrl: D.sources, brainUrl: D.brain, base: D.base,
      bs: {}, sugMore: false, inspLink: '', srcUrl: '', gen: { style: 0, look: '' },
      genLooks: ['راجل', 'ست', 'شاب', 'في الأربعينات', 'نضارة', 'بالطو أبيض', 'لبس كاجوال', 'محجبة', 'ابتسامة واثقة', 'ستايل كرتوني 3D'],
      onb: { step: 1, url: '', linkDone: false, answers: {}, more: {}, draft: null, conf: {}, pick: {}, facts: [], skip: false },

      contentSections: [
        { key: 'basics', label: 'الأساسيات والتواصل', icon: '🪪' },
        { key: 'business', label: 'النشاط والجمهور', icon: '🏷' },
        { key: 'services', label: 'الخدمات والمواضيع', icon: '🧩' },
        { key: 'voice', label: 'طريقة الكلام', icon: '🗣' },
        { key: 'pillars', label: 'اتجاه المحتوى', icon: '🧭' },
        { key: 'formats', label: 'أشكال المحتوى', icon: '🎞' },
        { key: 'offers', label: 'العروض الحالية', icon: '🎁' },
      ],
      lookSections: [
        { key: 'logo', label: 'اللوجو', icon: '◆' },
        { key: 'colors', label: 'الألوان والاستايل', icon: '🎨' },
        { key: 'visuals', label: 'طبيعة الصور', icon: '📷' },
        { key: 'characters', label: 'الشخصيات الثابتة', icon: '🧑' },
        { key: 'inspiration', label: 'تصميمات بتعجبك', icon: '💡' },
      ],
      questions: [
        { key: 'goal', label: 'هدفك الأساسي من السوشيال؟', options: ['حجوزات', 'مبيعات', 'انتشار', 'ثقة', 'تفاعل'], multi: false, ph: 'مثلًا: عايز حجوزات أكتر يوم الجمعة' },
        { key: 'audience', label: 'مين جمهورك؟', options: ['شباب', 'عائلات', 'ستات', 'رجالة', 'شركات', 'الكل'], multi: true, ph: 'مثلًا: أمهات في التجمع' },
        { key: 'voice', label: 'تحب تتكلم إزاي؟', options: ['قريب وودود', 'رسمي وخبير', 'خفيف ومرح'], multi: false, ph: 'مثلًا: زي صاحبك اللي فاهم' },
        { key: 'formats', label: 'إيه شكل المحتوى اللي بتحبه؟', options: ['صور', 'كاروسيل', 'ريلز', 'ستوري'], multi: true, ph: '' },
        { key: 'usp', label: 'إيه اللي بيميزك؟', options: ['السعر', 'الجودة', 'السرعة', 'الخبرة', 'الخدمة', 'المكان'], multi: true, ph: 'مثلًا: 15 سنة خبرة ومواعيد بالليل' },
      ],
      prioLabel: { high: 'أولوية عالية', medium: 'متوسطة', low: 'قليلة' },
      seasonList: ['رمضان', 'العيد', 'الصيف', 'الشتا', 'الربيع', 'الخريف', 'الدراسة', 'الإجازة', 'الجمعة البيضا'],
      lengths: { short: 'قصير', medium: 'متوسط', long: 'طويل' },
      goals: { bookings: 'حجوزات', sales: 'مبيعات', reach: 'انتشار', trust: 'ثقة', engagement: 'تفاعل' },
      platforms: { facebook: 'فيسبوك', instagram: 'انستجرام', tiktok: 'تيك توك', linkedin: 'لينكدإن' },
      dialects: { egyptian: 'مصري', msa: 'فصحى بسيطة', gulf: 'خليجي', levantine: 'شامي', mixed: 'مصري + فصحى' },
      personalityWords: ['موثوق', 'قريب من الناس', 'خبير', 'شبابي', 'فخم', 'عملي', 'مرح', 'هادي', 'جريء', 'إنساني'],
      logoPos: { 'top-right': 'فوق يمين', 'top-left': 'فوق شمال', 'bottom-right': 'تحت يمين', 'bottom-left': 'تحت شمال', center: 'في النص' },
      colorKeys: { primary: 'الأساسي', secondary: 'الثانوي', accent: 'لون الإبراز', neutral: 'المحايد' },
      fontsAr: ['Cairo', 'Tajawal', 'IBM Plex Sans Arabic', 'Almarai', 'Readex Pro', 'Noto Kufi Arabic', 'El Messiri', 'Amiri'],
      fontsEn: ['Inter', 'Poppins', 'Montserrat', 'Roboto', 'Lato', 'Playfair Display'],
      iconStyles: { thin_line: 'خط رفيع', filled: 'مصمتة', hand_drawn: 'مرسومة باليد', none: 'من غير' },
      vKinds: { photo: 'صور حقيقية', '3d': '3D', flat: 'رسوم فلات', mixed: 'مزيج' },
      vLight: { bright: 'مشرقة', warm: 'دافية', dramatic: 'درامية' },
      vPeople: { egyptian: 'ناس مصريين', arab: 'ناس عرب', characters_only: 'الشخصيات بس', none: 'من غير ناس' },
      vBrandColor: { none: 'ولا حاجة', subtle: 'خفيفة', strong: 'واضحة' },
      charTypes: { real_person: 'شخص حقيقي', ai_character: 'شخصية AI', mascot: 'ماسكوت' },
      useIn: { image: 'صور', reel: 'ريلز', voice: 'فويس', story: 'قصص' },
      byLabel: { user: 'إنت', ai: 'الـ AI (بموافقتك)', admin: 'فريق Spread' },

      get showOnboard() { return !!this.s && !this.onb.skip && (this.onb.step > 1 || this.s.completeness.pct < 35); },
      get focusId() { var f = ((this.s && this.s.services) || []).filter(function (v) { return +v.is_focus === 1; })[0]; return f ? +f.id : 0; },
      get pillarSum() { return ((this.ed && this.ed.pillars) || []).reduce(function (a, p) { return a + (+p.percent || 0); }, 0); },
      get draftSections() {
        var d = this.onb.draft || {}, out = [];
        var add = function (key, label, text) { if (text) out.push({ key: key, label: label, text: text }); };
        if (d.business) add('business', 'النشاط', [d.business.name, d.business.sector, d.business.usp].filter(Boolean).join(' · '));
        if (d.audience) add('audience', 'الجمهور', [d.audience.description].concat(d.audience.pains || []).filter(Boolean).join(' · '));
        if (d.personality && d.personality.length) add('personality', 'شخصية البراند', d.personality.join('، '));
        if (d.voice) add('voice', 'طريقة الكلام', (d.voice.sample_lines || []).join(' · ') || 'نبرة مقترحة');
        if (d.pillars && d.pillars.length) add('pillars', 'اتجاه المحتوى', d.pillars.map(function (p) { return p.name + ' ' + p.percent + '%'; }).join('، '));
        if (d.content) add('content', 'الهدف والأسلوب', (this.goals[d.content.goal] || '') + ' · كابشن ' + (this.lengths[d.content.caption_length] || ''));
        if (d.visuals && d.visuals.kind) add('visuals', 'طبيعة الصور', [this.vKinds[d.visuals.kind], this.vLight[d.visuals.light], this.vPeople[d.visuals.people]].filter(Boolean).join(' · '));
        if (d.services && d.services.length) add('services', 'الخدمات', d.services.map(function (x) { return x.name; }).join('، '));
        if (d.topics && d.topics.length) add('topics', 'مواضيع', d.topics.slice(0, 6).join('، '));
        return out;
      },

      say: function (msg, type) { SpreadApp.toast(this, msg, type || 'success'); },
      fail: function (r) { this.say((r && r.error) || 'حصلت مشكلة — جرّب تاني', 'danger'); },

      set: function (state) {
        this.s = state;
        this.bs = Object.assign({}, state.basics || {});
        var k = clone(state.kit);
        k.voice.words_use_t = (k.voice.words_use || []).join('\n');
        k.voice.words_avoid_t = (k.voice.words_avoid || []).join('\n');
        k.voice.sample_lines_t = (k.voice.sample_lines || []).join('\n');
        k.audience.pains_t = (k.audience.pains || []).join('\n');
        k.audience.objections_t = (k.audience.objections || []).join('\n');
        k.visuals.places_t = (k.visuals.places || []).join('\n');
        k.visuals.avoid_t = (k.visuals.avoid || []).join('\n');
        k.design_style.avoid_t = (k.design_style.avoid || []).join('\n');
        k.forbidden_topics = (k.forbidden_topics || []).join('\n');
        ['primary', 'secondary', 'accent', 'neutral'].forEach(function (c) { if (!k.colors[c]) k.colors[c] = ''; });
        this.ed = k;
        this.fm = clone(state.formats).map(function (f) { f.is_enabled = +f.is_enabled === 1; f.settings = f.settings && !Array.isArray(f.settings) ? f.settings : {}; return f; });
      },
      load: async function () {
        var r = await A.get('brand-kit', { action: 'get' });
        this.loading = false;
        if (r.ok) this.set(r); else this.fail(r);
        var h = location.hash.replace('#bk-', '');
        if (h) this.toggle(h);
      },
      post: async function (action, data, okMsg) {
        this.busy = true;
        var r = await A.post('brand-kit', action, data || {});
        this.busy = false;
        if (!r.ok) { this.fail(r); return null; }
        if (r.state) this.set(r.state);
        if (okMsg) this.say(okMsg);
        return r;
      },

      toggle: function (k) {
        this.open = this.open === k ? '' : k;
        var el = document.getElementById('bk-' + k);
        if (this.open && el) setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 60);
      },
      openMissing: function () {
        var m = this.s.completeness.missing[0];
        this.open = '';
        this.toggle(m.section || m.key);
      },
      secGroup: { business: ['business', 'audience', 'personality'], pillars: ['pillars', 'content'], services: ['services', 'topics'] },
      isUnsure: function (k) {
        var c = (this.s.kit.confidence || {});
        return (this.secGroup[k] || [k]).some(function (x) { return (x in c) && c[x] < 0.7; });
      },
      confirmSec: async function (k) {
        var c = (this.s.kit.confidence || {}), me = this;
        var keys = (this.secGroup[k] || [k]).filter(function (x) { return x in c; });
        for (var i = 0; i < keys.length; i++) await me.post('confirm', { section: keys[i] }, i === keys.length - 1 ? 'اتأكد ✓' : '');
      },
      summary: function (k) {
        var d = this.s.kit, me = this;
        switch (k) {
          case 'business': return [d.business.name, d.business.sector].filter(Boolean).join(' · ') || 'عرّفنا بنشاطك';
          case 'services': return this.s.services.length ? this.s.services.length + ' خدمة · ' + this.s.services.reduce(function (a, v) { return a + v.topics.length; }, 0) + ' موضوع' : 'ضيف خدماتك';
          case 'voice': return this.dialects[d.voice.dialect] + (d.voice.emoji ? ' · إيموجي' : '');
          case 'pillars': return d.pillars.length ? d.pillars.map(function (p) { return p.name; }).join('، ') : 'حدد اتجاه المحتوى';
          case 'formats': return this.s.formats.filter(function (f) { return +f.is_enabled; }).map(function (f) { return me.s.labels.formats[f.format]; }).join(' · ');
          case 'offers': return d.offers.length ? d.offers.length + ' عرض' : 'مفيش عروض دلوقتي';
          case 'logo': return this.s.logo_url ? 'موجود · ' + this.logoPos[d.logo.position] : 'مفيش لوجو';
          case 'colors': return d.colors.primary ? [d.colors.primary, d.colors.secondary].filter(Boolean).join(' ') : 'اختار ألوانك';
          case 'visuals': return d.visuals.kind ? this.vKinds[d.visuals.kind] : 'إزاي صورك تبان';
          case 'characters': return this.s.characters.length ? this.s.characters.length + ' شخصية' : 'صورتك أو شخصية/ماسكوت بالـ AI';
          case 'inspiration': return this.s.insp.length ? this.s.insp.length + ' تصميم' : 'صور أو لينكات لتصميمات عاجباك';
          case 'basics': return [this.s.basics.phones || this.s.basics.whatsapp, this.s.basics.address].filter(Boolean).join(' · ') || 'الاسم والتواصل والسوشيال';
        }
        return '';
      },
      presets: function (kind) { return (this.s.presets && this.s.presets[kind]) || []; },
      toggleArr: function (arr, v, max) {
        var i = arr.indexOf(v);
        if (i > -1) arr.splice(i, 1);
        else { if (max && arr.length >= max) arr.shift(); arr.push(v); }
      },
      lines: lines,

      saveSec: function (section, value, msg) { return this.post('save_section', { section: section, value: value }, msg || 'اتحفظ ✓ — هيأثر على البوستات الجاية'); },
      saveBusiness: async function () {
        var e = this.ed;
        var a = Object.assign({}, e.audience, { pains: lines(e.audience.pains_t), objections: lines(e.audience.objections_t) });
        if (!(await this.saveSec('business', e.business, ''))) return;
        if (!(await this.saveSec('personality', this.ed.personality, ''))) return;
        this.saveSec('audience', a);
      },
      saveVoice: function () {
        var v = Object.assign({}, this.ed.voice, { words_use: lines(this.ed.voice.words_use_t), words_avoid: lines(this.ed.voice.words_avoid_t),
          sample_lines: String(this.ed.voice.sample_lines_t || '').split('\n').map(function (x) { return x.trim(); }).filter(Boolean) });
        this.saveSec('voice', v);
      },
      applyTone: function (p) {
        Object.assign(this.ed.voice, (p.data && p.data.voice) || {});
        this.ed.voice.preset_id = p.id;
      },
      savePillars: async function () {
        var ps = this.ed.pillars.filter(function (p) { return String(p.name).trim(); });
        if (!(await this.saveSec('content', this.ed.content, ''))) return;
        this.saveSec('pillars', ps);
      },
      balancePillars: function () {
        var n = this.ed.pillars.length; if (!n) return;
        var each = Math.floor(100 / n / 5) * 5;
        this.ed.pillars.forEach(function (p, i) { p.percent = i === 0 ? 100 - each * (n - 1) : each; });
      },
      applyPalette: function (p) {
        var c = (p.data && p.data.colors) || [];
        var e = this.ed.colors;
        ['primary', 'secondary', 'accent', 'neutral'].forEach(function (k, i) { if (c[i]) e[k] = c[i].toUpperCase(); });
        e.preset_id = p.id;
      },
      saveLook: async function () {
        var e = this.ed;
        if (!(await this.saveSec('colors', e.colors, ''))) return;
        if (!(await this.saveSec('typography', e.typography, ''))) return;
        this.saveSec('design_style', Object.assign({}, e.design_style, { avoid: lines(e.design_style.avoid_t) }));
      },
      applyVisual: function (p) { Object.assign(this.ed.visuals, (p.data && p.data.visuals) || {}); this.ed.visuals.preset_id = p.id; },
      saveVisuals: function () {
        var v = Object.assign({}, this.ed.visuals, { places: lines(this.ed.visuals.places_t), avoid: lines(this.ed.visuals.avoid_t) });
        this.saveSec('visuals', v);
      },

      /* «تعديل بكلمة» */
      regen: async function (section, mode) {
        if (!mode) return;
        var cur = section === 'voice' ? Object.assign({}, this.ed.voice, { sample_lines: String(this.ed.voice.sample_lines_t || '').split('\n').filter(Boolean) }) : null;
        var r = await this.post('regen_section', { section: section, mode: mode });
        if (!r) return;
        var p = {}; p[section] = r.proposal;
        this.prop = Object.assign({}, this.prop, p);
        this.regenText[section] = '';
      },
      useProp: function (section) {
        var v = this.prop[section];
        if (section === 'voice') {
          Object.assign(this.ed.voice, v);
          this.ed.voice.sample_lines_t = (v.sample_lines || []).join('\n');
          this.ed.voice.words_use_t = (v.words_use || []).join('\n');
          this.ed.voice.words_avoid_t = (v.words_avoid || []).join('\n');
        } else if (section === 'audience') {
          Object.assign(this.ed.audience, v);
          this.ed.audience.pains_t = (v.pains || []).join('\n');
          this.ed.audience.objections_t = (v.objections || []).join('\n');
        } else if (section === 'pillars') {
          this.ed.pillars = clone(v);
        } else {
          Object.assign(this.ed[section], v);
        }
        var p = Object.assign({}, this.prop); delete p[section]; this.prop = p;
        this.say('اتطبّق — راجعه واضغط حفظ');
      },

      /* الخدمات والمواضيع */
      editService: function (v) {
        this.svc = v ? { id: v.id, name: v.name, description: v.description || '', priority: v.priority, seasons: (v.seasons || []).slice(), is_active: +v.is_active === 1 }
                     : { id: 0, name: '', description: '', priority: 'medium', seasons: [], is_active: true };
        this.modal = 'service';
      },
      saveService: async function () { if (await this.post('service_save', this.svc, 'الخدمة اتحفظت ✓')) this.modal = null; },
      delService: async function () { if (confirm('تحذف الخدمة ومواضيعها؟') && await this.post('service_delete', { id: this.svc.id }, 'اتحذفت')) this.modal = null; },
      focus: function (id) { this.post('service_focus', { id: id }, id ? 'هنركّز على الخدمة دي الشهر ده ⭐' : 'رجعنا متوازن'); },
      addTopic: async function (sid) {
        var t = String(this.newTopic[sid] || '').trim(); if (!t) return;
        if (await this.post('topic_add', { service_id: sid || 0, titles: [t] })) this.newTopic[sid] = '';
      },
      addLib: function (t) { this.post('topic_add', { service_id: 0, titles: [t], source: 'library' }, 'اتضاف ✓'); },
      delTopic: function (t) { this.post('topic_delete', { id: t.id }); },
      suggestTopics: async function (sid) {
        var r = await this.post('topics_suggest', { service_id: sid || 0 });
        if (r) this.sugTopics = { sid: sid || 0, list: r.topics, pick: r.topics.map(function () { return true; }) };
      },
      addSuggested: async function () {
        var st = this.sugTopics, titles = st.list.filter(function (t, i) { return st.pick[i]; });
        if (titles.length && await this.post('topic_add', { service_id: st.sid, titles: titles, source: 'ai' }, 'اتضافوا ✓')) this.sugTopics = { sid: -1, list: [], pick: [] };
      },
      setSector: function (id) { this.post('sector_set', { sector_id: +id || 0 }, 'اتغيّر القطاع'); },

      /* أشكال المحتوى */
      moveFmt: function (i, d) { var j = i + d; if (j < 0 || j >= this.fm.length) return; var x = this.fm.splice(i, 1)[0]; this.fm.splice(j, 0, x); },
      dropFmt: function (i) { if (this.dragI < 0 || this.dragI === i) return; var x = this.fm.splice(this.dragI, 1)[0]; this.fm.splice(i, 0, x); this.dragI = -1; },
      saveFormats: async function () {
        if (!(await this.post('formats_save', { items: this.fm }, ''))) return;
        this.saveSec('content', this.ed.content);
      },

      /* الشخصيات */
      editChar: function (c) {
        this.ch = c ? { id: c.id, name: c.name, type: c.type, role: c.role || '', description: c.description || '', personality: c.personality || '',
                        use_in: (c.use_in || []).slice(), share_percent: +c.share_percent, consent_confirmed: +c.consent_confirmed === 1, is_active: +c.is_active === 1, images: c.images }
                    : { id: 0, name: '', type: 'ai_character', role: '', description: '', personality: '', use_in: ['image', 'story'], share_percent: 20, consent_confirmed: false, is_active: true, images: [] };
        this.modal = 'character';
      },
      charMissing: function (c) {
        if (c.type === 'real_person' && +c.consent_confirmed !== 1) return 'محتاجة موافقة';
        if (!c.images.length) return 'ناقصة — ارفع صور مرجعية';
        return 'متوقفة';
      },
      syncChar: function () {
        var id = this.ch && this.ch.id, c = (this.s.characters || []).filter(function (x) { return +x.id === +id; })[0];
        if (c) this.ch.images = c.images;
      },
      saveChar: async function () {
        if (this.ch.type === 'real_person' && !this.ch.consent_confirmed && !confirm('الشخص الحقيقي مش هيظهر في أي صورة غير بعد تأكيد الموافقة. تحفظ من غيرها؟')) return;
        var r = await this.post('character_save', this.ch, 'الشخصية اتحفظت ✓');
        if (r) { this.ch.id = r.id; this.syncChar(); }
      },
      delChar: async function () { if (confirm('تحذف الشخصية وصورها؟') && await this.post('character_delete', { id: this.ch.id }, 'اتحذفت')) this.modal = null; },
      charUpload: async function (ev) {
        var f = ev.target.files && ev.target.files[0]; if (!f) return;
        var fd = new FormData();
        fd.append('action', 'character_upload'); fd.append('id', this.ch.id); fd.append('image', f);
        fd.append('csrf', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
        this.busy = true;
        var r = null;
        try { r = await (await fetch(D.base + '/api/brand-kit.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (e) { r = null; }
        this.busy = false;
        ev.target.value = '';
        if (!r || !r.ok) return this.fail(r);
        this.set(r.state); this.syncChar(); this.say('الصورة اترفعت ✓');
        if (this.ch.type === 'real_person' && this.ch.images.length < 3) this.say('اترفعت ✓ — ارفع ' + (3 - this.ch.images.length) + ' كمان علشان الملامح تثبت');
      },
      charPrimary: async function (im) { if (await this.post('character_primary', { image_id: im.id })) this.syncChar(); },
      charImgDel: async function (im) { if (confirm('تحذف الصورة؟') && await this.post('character_image_delete', { image_id: im.id })) this.syncChar(); },

      /* تحديث سريع */
      quick: function (what) {
        if (what === 'service') return this.editService(null);
        if (what === 'character') return this.editChar(null);
        if (what === 'offer') { this.ed.offers.push({ title: '', details: '', until: '' }); this.open = ''; this.toggle('offers'); }
      },

      /* النسخ */
      loadVersions: async function () {
        var r = await A.get('brand-kit', { action: 'versions' });
        if (!r.ok) return this.fail(r);
        this.versions = r.versions; this.modal = 'versions';
      },
      restore: async function (v) { if (confirm('ترجع للنسخة ' + v + '؟ (النسخة الحالية هتفضل محفوظة)') && await this.post('version_restore', { version: v }, 'رجعنا للنسخة ' + v)) this.modal = null; },

      decide: async function (g, acc) {
        if (g.kind === 'fact') {
          this.busy = true;
          var r = await A.post('brand', 'decide', { id: g.id, decision: acc ? 'apply' : 'reject' });
          this.busy = false;
          if (!r.ok) return this.fail(r);
          this.say(acc ? 'اتضافت لهويتك ✓' : 'تمام');
          return this.load();
        }
        this.post('suggestion', { id: g.id, accept: acc }, acc ? 'اتحدّثت هويتك ✓' : 'تمام، مش هنغيّرها');
      },

      /* الأساسيات والتواصل: نفس حقول Brand Brain (save_field) — المتغير بس */
      saveBasics: async function () {
        var changed = Object.keys(this.bs).filter(function (k) { return k !== 'design_rules'; }).filter((function (k) { return String(this.bs[k] || '') !== String(this.s.basics[k] || ''); }).bind(this));
        if (!changed.length) return this.say('مفيش تغيير');
        this.busy = true;
        for (var i = 0; i < changed.length; i++) {
          var r = await A.post('brand', 'save_field', { field: changed[i], value: this.bs[changed[i]] || '' });
          if (!r.ok) { this.busy = false; return this.fail(r); }
        }
        this.busy = false;
        this.say('اتحفظ ✓');
        this.load();
      },
      upload: async function (endpoint, data, file, field) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fd.append(field, file);
        fd.append('csrf', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
        this.busy = true;
        var r = null;
        try { r = await (await fetch(D.base + '/api/' + endpoint + '.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (e) { r = null; }
        this.busy = false;
        return r || { ok: false, error: 'مشكلة في الاتصال' };
      },
      logoUpload: async function (ev) {
        var f = ev.target.files && ev.target.files[0]; if (!f) return;
        var r = await this.upload('brand', { action: 'upload_logo' }, f, 'logo');
        ev.target.value = '';
        if (!r.ok) return this.fail(r);
        this.say('اللوجو اترفع ✓'); this.load();
      },
      logoColors: async function () {
        this.busy = true;
        var r = await A.post('brand', 'logo_colors', {});
        this.busy = false;
        if (!r.ok) return this.fail(r);
        this.say('طلّعنا ألوان اللوجو — هتلاقيها في الاقتراحات فوق'); this.load();
      },
      inspAdd: async function () {
        this.busy = true;
        var r = await A.post('brand', 'insp_link', { url: this.inspLink });
        this.busy = false;
        if (!r.ok) return this.fail(r);
        this.inspLink = ''; this.say('اتضاف ✓'); this.load();
      },
      inspUpload: async function (ev) {
        var f = ev.target.files && ev.target.files[0]; if (!f) return;
        var r = await this.upload('brand', { action: 'insp_upload' }, f, 'file');
        ev.target.value = '';
        if (!r.ok) return this.fail(r);
        this.say('اتضاف ✓'); this.load();
      },
      inspDel: async function (it) {
        if (!confirm('تشيل التصميم ده؟')) return;
        var r = await A.post('brand', 'insp_delete', { id: it.id });
        if (!r.ok) return this.fail(r);
        this.load();
      },
      analyzeSrc: async function () {
        this.busy = true;
        var r = await A.post('brand', 'analyze_url', { url: this.srcUrl });
        this.busy = false;
        if (!r.ok) return this.fail(r);
        this.srcUrl = ''; this.say('قرينا اللينك ✓ — راجع الاقتراحات فوق'); this.load();
      },
      srcToggle: async function (d) {
        var r = await A.post('brand-kit', 'source_toggle', { id: d.id });
        if (!r.ok) return this.fail(r);
        this.s.sources = r.sources;
      },
      charGen: async function (mode) {
        if (!this.ch.id) return this.say('احفظ الشخصية الأول', 'danger');
        var r = await this.post('character_generate', { id: this.ch.id, mode: mode, look: this.gen.look, style_preset_id: this.gen.style || 0 }, 'الصورة جاهزة ✓');
        if (r) { this.syncChar(); this.gen.look = ''; }
      },

      /* Onboarding */
      onbHas: function (k, o) { var a = this.onb.answers[k]; return Array.isArray(a) ? a.indexOf(o) > -1 : a === o; },
      onbPick: function (q, o) {
        var a = this.onb.answers;
        if (!q.multi) { a[q.key] = a[q.key] === o ? '' : o; return; }
        if (!Array.isArray(a[q.key])) a[q.key] = [];
        this.toggleArr(a[q.key], o);
      },
      analyzeLink: async function () {
        this.busy = true;
        // مفيش تطبيق تلقائي: اللي اتقرا من الصفحة بيظهر في المراجعة والعميل يختار
        var r = await A.post('brand', 'analyze_url', { url: this.onb.url });
        this.busy = false;
        if (!r.ok) return this.fail(r);
        this.onb.linkDone = true;
        this.say('قرينا الصفحة ✓ — اضغط «ابني الهوية»');
      },
      buildDraft: async function () {
        var me = this, ans = {};
        this.questions.forEach(function (q) {
          var a = me.onb.answers[q.key], more = me.onb.more[q.key];
          var v = Array.isArray(a) ? a.join('، ') : (a || '');
          if (more) v = (v && v !== 'ai' ? v + ' — ' : '') + more;
          if (v) ans[q.label] = v;
        });
        this.onb.step = 2;
        var r = await this.post('draft', { answers: ans });
        if (!r) { this.onb.step = 1; return; }
        this.onb.draft = r.draft; this.onb.conf = r.confidence || {};
        var pick = {};
        this.draftSections.forEach(function (sec) { pick[sec.key] = true; });
        this.onb.pick = pick;
        this.onb.facts = (r.facts || []).map(function (f) { f.on = f.confidence >= 75; return f; });
        this.onb.step = 3;
      },
      applyDraft: async function () {
        var me = this, secs = Object.keys(this.onb.pick).filter(function (k) { return me.onb.pick[k]; });
        var unsure = secs.filter(function (k) { return (me.onb.conf[k] !== undefined) && me.onb.conf[k] < 0.7; });
        // اقتراحات اللينك اللي العميل علّم عليها ← تتطبق على البيانات الأساسية (الباقي يفضل في صندوق الاقتراحات)
        var facts = (this.onb.facts || []).filter(function (f) { return f.on; });
        this.busy = true;
        for (var i = 0; i < facts.length; i++) await A.post('brand', 'decide', { id: facts[i].id, decision: 'apply' });
        this.busy = false;
        var r = await this.post('apply_draft', { draft: this.onb.draft, sections: secs, unsure: unsure }, 'هويتك جاهزة 🎉');
        if (r) { this.onb = Object.assign(this.onb, { step: 1, draft: null, facts: [], skip: true }); }
      },
    };

    // نفس المرجع الـ reactive اللي الواجهة شغالة عليه
    var scope = SpreadApp.reactive(app);
    SpreadApp.mount(root, scope);
    scope.load();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
