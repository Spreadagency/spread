<?php
/**
 * Spread AI v2 — Brand Brain (petite-vue)
 * الهوية بنسبة صحة · تحليل من رابط · اقتراحات بمصدرها · ألوان اللوجو
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/uploader.php';
require_once __DIR__ . '/../includes/brand-brain.php';

require_login();
$user = current_user();

if (!ui_v2_enabled()) {
    redirect('brand-profile.php');   // الشكل القديم له صفحته
}

$brand = brand_for_user((int) $user['id']);
if (!$brand) {
    db_insert('INSERT INTO brand_profiles (user_id) VALUES (?)', [$user['id']]);
    $brand = brand_for_user((int) $user['id']);
}

$sourcesCount = (int) (db_one('SELECT COUNT(*) n FROM brand_sources WHERE brand_profile_id = ?', [$brand['id']])['n'] ?? 0);
$initial = [
    'brand'   => brand_to_api($brand),
    'files'   => $sourcesCount,
    'cost'    => max(0, (int) get_setting('brand_analyze_cost', 1)),
    'enabled' => get_setting('brand_analyze_enabled', '1') === '1',
];

$active = 'brand-brain';
$page_title = 'Brand Brain';
$use_app = true;
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>
        <?= render_flash() ?>

        <div id="bb-app" v-cloak>

            <!-- ═══ الرأس + صحة الهوية ═══ -->
            <section class="card bb-hero">
                <div class="bb-ring" :style="{ '--p': b.health.pct }">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle cx="60" cy="60" r="52" class="bb-ring-bg"/>
                        <circle cx="60" cy="60" r="52" class="bb-ring-fg" :stroke-dasharray="326.7" :stroke-dashoffset="326.7 - 326.7 * b.health.pct / 100"/>
                    </svg>
                    <div class="bb-ring-mid"><b>{{ b.health.pct }}%</b><span>Brand Health</span></div>
                </div>
                <div class="bb-hero-txt">
                    <div class="bb-eyebrow">Brand Brain · هوية البراند</div>
                    <h1 v-if="b.name">{{ b.name }}</h1>
                    <h1 v-else>ابنِ هوية براندك ✨</h1>
                    <p class="bb-status" :class="{ ok: b.health.unlocked }">
                        <span class="pv-c" v-if="b.health.unlocked">✓ المحتوى والتصميم مفتوحين في الحملات</span>
                        <span class="pv-c" v-else>🔒 المحتوى والتصميم بيفتحوا عند {{ b.health.gate }}% — فاضلك {{ b.health.gate - b.health.pct }}%</span>
                    </p>
                    <div class="bb-next" v-if="b.health.missing.length">
                        <span class="sub">أهم الناقص:</span>
                        <span v-for="m in b.health.missing.slice(0, 4)" :key="m.key" class="bb-miss">{{ m.label }} <small>+{{ m.weight }}%</small></span>
                    </div>
                    <div class="bb-approve" v-if="b.health.pct >= Math.max(50, b.health.gate)">
                        <span v-if="b.approved_at" class="bb-approved">✓ الهوية معتمدة — الـ AI بيبني عليها كل حاجة</span>
                        <button v-else class="btn" @click="approve">اعتماد الهوية</button>
                    </div>
                </div>
            </section>

            <!-- ═══ التحليل من رابط ═══ -->
            <section class="card bb-analyze" v-if="enabled">
                <h2>حلّل البراند من رابط ✨</h2>
                <p class="sub">ابعت موقعك أو صفحتك على فيسبوك/انستجرام — Spread AI هيجمع المعلومات ويحط على كل معلومة مصدرها، وانت اللي بتأكد.</p>
                <div class="bb-url">
                    <input class="input" type="url" v-model="url" @keydown.enter.prevent="analyze" dir="ltr"
                           placeholder="https://yourbrand.com" aria-label="رابط الموقع أو الصفحة" :disabled="busy">
                    <button class="btn" @click="analyze" :disabled="busy || !url.trim()">
                        <span v-if="!busy">حلّل البراند ✨</span>
                        <span v-else><span class="btn-spin"></span> بيحلل...</span>
                    </button>
                </div>
                <div class="bb-alt">
                    <span class="sub">أو</span>
                    <a :href="base + '/sources.php'" class="btn ghost sm">📄 ارفع ملف ({{ files }})</a>
                    <a :href="base + '/brand-profile.php'" class="btn ghost sm">✎ اكتب بنفسك</a>
                    <span class="sub bb-cost" v-if="cost">التحليل بـ {{ cost }} كريدت — بيرجع لو ماطلعش معلومات</span>
                </div>
                <div class="alert info" v-if="note" style="margin-top:12px">{{ note }}</div>
            </section>

            <!-- ═══ الاقتراحات ═══ -->
            <section class="card bb-sugs" v-if="b.suggestions.length">
                <div class="bb-sugs-head">
                    <div>
                        <h2>وجدنا معلومات عن براندك 🔎</h2>
                        <p class="sub">كل معلومة عليها مصدرها — راجعها وأكّد اللي صح.</p>
                    </div>
                    <button class="btn" @click="applyAll" :disabled="busy">تأكيد الكل ({{ uniqueFields() }})</button>
                </div>
                <div class="bb-sug" v-for="s in b.suggestions" :key="s.id">
                    <div class="bb-sug-top">
                        <b>{{ s.label }}</b>
                        <span class="bb-src" :style="{ '--c': s.source.color }">{{ s.source.label }}</span>
                        <a v-if="s.ref && s.ref.startsWith('http')" :href="s.ref" target="_blank" rel="noopener nofollow" class="bb-ref" dir="ltr">{{ shortRef(s.ref) }}</a>
                    </div>
                    <textarea v-if="editing === s.id" class="textarea" rows="3" v-model="draft"></textarea>
                    <div v-else class="bb-val" :dir="isLink(s.value) ? 'ltr' : 'auto'">
                        <span class="pv-c" v-if="s.field === 'colors'">
                            <span v-for="c in s.value.split(',')" class="bb-sw" :style="{ background: c.trim() }" :title="c.trim()"></span>
                        </span>
                        {{ s.value }}
                    </div>
                    <div class="bb-cur sub" v-if="s.current && editing !== s.id">الحالي: {{ s.current.length > 90 ? s.current.slice(0, 90) + '…' : s.current }}</div>
                    <div class="bb-sug-act">
                        <span class="pv-c" v-if="editing === s.id">
                            <button class="btn sm" @click="decide(s, 'apply', draft)">✓ حفظ وتأكيد</button>
                            <button class="btn ghost sm" @click="editing = null">إلغاء</button>
                        </span>
                        <span class="pv-c" v-else>
                            <button class="btn sm" @click="decide(s, 'apply')">✓ تأكيد</button>
                            <button class="btn ghost sm" @click="editing = s.id; draft = s.value">✎ تعديل</button>
                            <button class="btn ghost sm bb-rej" @click="decide(s, 'reject')">✕</button>
                        </span>
                    </div>
                </div>
            </section>

            <!-- ═══ التبويبات ═══ -->
            <div class="seg bb-tabs">
                <button :class="{ on: tab === 'identity' }" @click="tab = 'identity'">الهوية</button>
                <button :class="{ on: tab === 'assets' }" @click="tab = 'assets'">المصادر والأصول</button>
            </div>

            <!-- الهوية -->
            <section v-if="tab === 'identity'" class="bb-groups">
                <div class="card bb-group" v-for="g in groups" :key="g.key">
                    <h3>{{ g.icon }} {{ g.title }}</h3>
                    <div class="bb-row" v-for="f in fieldsOf(g.key)" :key="f.key">
                        <div class="bb-row-lbl">{{ f.label }}</div>
                        <div class="bb-row-val" v-if="f.filled">
                            <span class="pv-c" v-if="f.key === 'logo_path'"><img :src="f.value" alt="اللوجو" class="bb-logo-sm"></span>
                            <span class="pv-c" v-else-if="f.key === 'colors'">
                                <span v-for="c in f.value.split(',')" class="bb-sw" :style="{ background: c.trim() }"></span>
                            </span>
                            <span v-else :dir="isLink(f.value) ? 'ltr' : 'auto'">{{ display(f) }}</span>
                        </div>
                        <div class="bb-row-val" v-else><span class="bb-missing">ناقص</span></div>
                        <span v-if="f.source" class="bb-src" :style="{ '--c': f.source.color }">{{ f.source.label }}</span>
                    </div>
                    <a :href="base + '/brand-profile.php'" class="bb-edit">تعديل ←</a>
                </div>
            </section>

            <!-- المصادر والأصول -->
            <section v-else class="bb-assets">
                <div class="card bb-asset">
                    <h3>🎨 اللوجو</h3>
                    <div v-if="logo()" class="bb-asset-body">
                        <div class="bb-logo-box"><img :src="logo()" alt="اللوجو"></div>
                        <div class="bb-sws" v-if="logoColors.length">
                            <span class="sub">ألوان اللوجو:</span>
                            <span v-for="c in logoColors" class="bb-sw lg" :style="{ background: c }" :title="c"></span>
                        </div>
                        <button class="btn sm" @click="extractColors" :disabled="busy">
                            {{ logoColors.length ? '↻ استخرج تاني' : 'استخرج ألوان اللوجو' }}
                        </button>
                        <p class="sub bb-hint" v-if="logoColors.length">الألوان ظهرت فوق في «وجدنا معلومات» — أكّدها علشان تتطبّق على الهوية.</p>
                    </div>
                    <div v-else class="bb-asset-body">
                        <p class="sub">ارفع اللوجو علشان نستخرج ألوانه وتتطبّق على كل تصميماتك.</p>
                        <a :href="base + '/brand-profile.php'" class="btn sm">ارفع اللوجو</a>
                    </div>
                </div>

                <div class="card bb-asset">
                    <h3>🔗 الموقع والسوشيال</h3>
                    <div v-if="links().length" class="bb-links">
                        <a v-for="l in links()" :key="l.key" :href="l.value" target="_blank" rel="noopener nofollow" class="bb-link">
                            <b>{{ l.label }}</b><span dir="ltr">{{ shortRef(l.value) }}</span>
                        </a>
                    </div>
                    <p class="sub" v-else>لسه مفيش روابط — حلّل رابط من فوق وهنتعرف على نوعه لوحدنا.</p>
                </div>

                <div class="card bb-asset">
                    <h3>📄 الملفات</h3>
                    <p class="sub">بروفايل الشركة · Brand Guide · منيو أو كتالوج — بتتقرا وتتضاف لمعرفة الـ AI.</p>
                    <p><b>{{ files }}</b> ملف ومصدر</p>
                    <a :href="base + '/sources.php'" class="btn ghost sm">إدارة الملفات ←</a>
                </div>
            </section>

            <div class="toast-container" v-if="toast"><div class="toast" :class="toast.type">{{ toast.msg }}</div></div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var init = <?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var base = document.querySelector('meta[name="app-base"]').content;
  var TONES = { simple: 'بسيط', formal: 'رسمي', fun: 'مرح', professional: 'احترافي' };
  var DIALECTS = { egyptian: 'مصري', khaleeji: 'خليجي', levantine: 'شامي' };

  // ويدجت السايدبار بيترسم مع الصفحة — نحدّثه لحظيًا مع أي تغيير في الهوية
  function syncSidebar(h) {
    var w = document.querySelector('.sb-brand');
    if (!w || !h) return;
    var top = w.querySelectorAll('.sb-brand-top b');
    if (top[1]) top[1].textContent = h.pct + '%';
    var bar = w.querySelector('.bar-fill');
    if (bar) bar.style.width = h.pct + '%';
    var msg = w.querySelector('.sb-brand-msg');
    if (msg) msg.textContent = h.unlocked ? 'الهوية جاهزة ✓' : 'كمّل هويتك ←';
  }

  var app = SpreadApp.mount('#bb-app', {
    base: base,
    b: init.brand,
    files: init.files,
    cost: init.cost,
    enabled: init.enabled,
    url: '',
    busy: false,
    note: null,
    tab: 'identity',
    editing: null,
    draft: '',
    toast: null,
    logoColors: [],
    groups: [
      { key: 'basic', title: 'النشاط', icon: '🏷' },
      { key: 'audience', title: 'الجمهور والخدمات', icon: '👥' },
      { key: 'style', title: 'الأسلوب', icon: '🗣' },
      { key: 'visual', title: 'الهوية البصرية', icon: '🎨' },
      { key: 'contact', title: 'التواصل', icon: '📞' },
      { key: 'links', title: 'الروابط', icon: '🔗' },
    ],

    // دالة مش getter — petite-vue مش بيقيّم الـ getters صح جوه الـ scope
    logo: function () {
      var f = this.b.fields.find(function (x) { return x.key === 'logo_path'; });
      return f && f.filled ? f.value : '';
    },
    fieldsOf: function (g) { return this.b.fields.filter(function (f) { return f.group === g; }); },
    links: function () {
      return this.b.fields.filter(function (f) { return f.group === 'links' && f.filled; });
    },
    uniqueFields: function () {
      var s = {}; this.b.suggestions.forEach(function (x) { s[x.field] = 1; });
      return Object.keys(s).length;
    },
    display: function (f) {
      if (f.key === 'tone') return TONES[f.value] || f.value;
      if (f.key === 'dialect') return DIALECTS[f.value] || f.value;
      var v = String(f.value);
      return v.length > 140 ? v.slice(0, 140) + '…' : v;
    },
    isLink: function (v) { return /^https?:\/\//i.test(String(v || '')); },
    shortRef: function (u) {
      try { var x = new URL(u); return (x.hostname.replace(/^www\./, '') + x.pathname).replace(/\/$/, '').slice(0, 40); }
      catch (e) { return String(u).slice(0, 40); }
    },
    notify: function (msg, type) { SpreadApp.toast(this, msg, type || 'success'); },

    async analyze() {
      if (this.busy || !this.url.trim()) return;
      this.busy = true; this.note = null;
      if (window.SpreadThinking) {
        SpreadThinking.start({ title: 'بحلل براندك', steps: ['بفتح الرابط', 'بقرا المحتوى والروابط', 'بستخرج معلومات الهوية', 'بحط على كل معلومة مصدرها'] });
      }
      var r = await SpreadAPI.post('brand', 'analyze_url', { url: this.url.trim() });
      this.busy = false;
      if (!r.ok) {
        if (window.SpreadThinking) SpreadThinking.fail();
        return this.notify(r.error, 'danger');
      }
      if (window.SpreadThinking) SpreadThinking.done(r.suggested ? 'لقيت ' + r.suggested + ' معلومة ✓' : 'خلص التحليل');
      this.b = r.brand;
      this.note = r.note;
      this.url = '';
      if (!r.suggested) this.notify('ماطلعش معلومات جديدة — الكريدت رجعلك', 'info');
    },
    async decide(s, decision, value) {
      var payload = { id: s.id, decision: decision };
      if (value !== undefined) payload.value = value;
      var r = await SpreadAPI.post('brand', 'decide', payload);
      if (!r.ok) return this.notify(r.error, 'danger');
      var before = this.b.health.pct;
      this.b = r.brand; this.editing = null;
      if (decision === 'apply') {
        var d = this.b.health.pct - before;
        this.notify(d > 0 ? 'اتضافت للهوية · +' + d + '%' : 'اتحدّثت ✓');
      }
    },
    async applyAll() {
      if (!confirm('تأكيد كل المعلومات المقترحة؟ (لو فيه أكتر من اقتراح لنفس الحقل هناخد الأدق)')) return;
      this.busy = true;
      var before = this.b.health.pct;
      var r = await SpreadAPI.post('brand', 'apply_all', {});
      this.busy = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.b = r.brand;
      this.notify('اتأكدت ' + r.applied + ' معلومة · الهوية ' + this.b.health.pct + '%' + (this.b.health.pct > before ? ' ↑' : ''));
    },
    async extractColors() {
      this.busy = true;
      var r = await SpreadAPI.post('brand', 'logo_colors', {});
      this.busy = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.logoColors = r.colors; this.b = r.brand;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    async approve() {
      var r = await SpreadAPI.post('brand', 'approve', {});
      if (!r.ok) return this.notify(r.error, 'danger');
      this.b = r.brand;
      this.notify('Your Brand Brain is Ready 🎉');
    },
  });

  // بعد أي طلب للـ API نزامن السايدبار
  var origPost = SpreadAPI.post;
  SpreadAPI.post = async function (endpoint) {
    var r = await origPost.apply(SpreadAPI, arguments);
    if (endpoint === 'brand' && r && r.ok && r.brand) syncSidebar(r.brand.health);
    return r;
  };
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
