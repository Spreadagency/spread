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
require_once __DIR__ . '/../includes/ui-v2.php';

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
    // كله في الصفحة الأساسية: البحث العميق · تصميمات بتعجبك · بيانات الهوية · المساعد
    'overview' => brand_overview($brand, (int) $user['id']),
    'insp'     => brand_insp_list((int) $brand['id']),
    'insp_max' => brand_insp_max(),
    'research' => function_exists('ui_research_on') ? ui_research_on() : get_setting('research_enabled', '1') === '1',
    'groups'   => array_map(fn($k, $g) => ['key' => $k, 'title' => $g[0], 'icon' => $g[1], 'hint' => $g[2]], array_keys(brand_groups()), brand_groups()),
    'inputs'   => brand_field_inputs(),
    'options'  => brand_field_options(),
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

            <!-- ═══ الاقتراحات (من التحليل · البحث العميق · اللوجو) ═══ -->
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
                        <small v-if="s.ref && !s.ref.startsWith('http')" class="sub">{{ s.ref }}</small>
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

            <!-- ═══ ① البحث العميق — صفحة مستقلة جوه Brand Brain: بيقرا الهوية وبيسجّل فيها ═══ -->
            <section class="card bb-sec" v-if="researchOn" id="research">
                <div class="bb-sec-head">
                    <div>
                        <h2>🔬 البحث العميق</h2>
                        <p class="sub">بيقرا هويتك (المجال · الجمهور · الخدمات) ويبحث في السوق والمنافسين بمصادر حقيقية — واللي يلاقيه بيتسجّل في الهوية تلقائيًا.</p>
                    </div>
                    <a :href="base + '/research.php'" class="btn">＋ بحث جديد</a>
                </div>
                <div class="bb-rs-list" v-if="ov && ov.researches.length">
                    <a v-for="r in ov.researches" :key="r.id" :href="base + '/research.php?id=' + r.id" class="bb-rs">
                        <b>{{ r.title }}</b>
                        <small>
                            <span :class="'bb-rs-st st-' + r.status">{{ rsStatus(r.status) }}</span>
                            <span v-if="r.in_brain" class="bb-rs-in">✓ في الهوية</span>
                            <span class="sub">{{ r.ago }}</span>
                        </small>
                    </a>
                </div>
                <p class="sub" v-if="ov && !ov.researches.length">لسه ماعملتش بحث — ابدأ ببحث «المنافسين» أو «الجمهور» علشان الـ AI يفهم سوقك.</p>
            </section>

            <!-- ═══ ② تصميمات بتعجبك ═══ -->
            <section class="card bb-sec" id="inspirations">
                <div class="bb-sec-head">
                    <div>
                        <h2>💜 تصميمات بتعجبك</h2>
                        <p class="sub">ارفع صور تصميمات عاجباك أو حط لينكاتها (Behance · Pinterest · إنستجرام) — بتظهر كمرجع ستايل في Design Studio.</p>
                    </div>
                    <small class="sub">{{ insp.length }} / {{ inspMax }}</small>
                </div>
                <div class="bb-insp-add">
                    <label class="btn ghost sm bb-insp-up" :class="{ disabled: inspBusy }">
                        <input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden @change="inspUpload($event)" :disabled="inspBusy">
                        ⬆ ارفع صور
                    </label>
                    <input class="input" type="url" dir="ltr" v-model="inspUrl" @keydown.enter.prevent="inspLink" placeholder="https://www.behance.net/…" aria-label="لينك تصميم" :disabled="inspBusy">
                    <button class="btn sm" @click="inspLink" :disabled="inspBusy || !inspUrl.trim()">{{ inspBusy ? 'لحظة…' : 'ضيف اللينك' }}</button>
                </div>
                <div class="bb-insp-grid" v-if="insp.length">
                    <figure v-for="it in insp" :key="it.id" class="bb-insp">
                        <a v-if="it.img" :href="it.img" data-lightbox><img :src="it.img" alt="" loading="lazy"></a>
                        <a v-else :href="it.link" target="_blank" rel="noopener nofollow" class="bb-insp-link">🔗<span dir="ltr">{{ shortRef(it.link) }}</span></a>
                        <figcaption>
                            <a v-if="it.link && it.img" :href="it.link" target="_blank" rel="noopener nofollow" class="sub" dir="ltr">{{ shortRef(it.link) }}</a>
                            <a v-if="it.ref" :href="base + '/design-studio.php?style_ref=' + encodeURIComponent(it.ref)" class="sub">صمّم بنفس الستايل ←</a>
                            <button type="button" class="bb-insp-x" @click="inspDelete(it)" aria-label="مسح">✕</button>
                        </figcaption>
                    </figure>
                </div>
                <p class="sub" v-else>لسه مفيش — ضيف 3 لـ 6 تصميمات تحس إنها «انت».</p>
            </section>

            <!-- ═══ ③ بيانات الهوية — بالترتيب الأساسي ═══ -->
            <section class="card bb-sec" id="identity">
                <div class="bb-sec-head">
                    <div>
                        <h2>📋 بيانات الهوية</h2>
                        <p class="sub">مترتبة من الأهم للأقل — دوس على أي معلومة وعدّلها هنا على طول.</p>
                    </div>
                </div>

                <div class="bb-analyze-inline" v-if="enabled">
                    <b>✨ املأها تلقائي من رابط</b>
                    <div class="bb-url">
                        <input class="input" type="url" v-model="url" @keydown.enter.prevent="analyze" dir="ltr"
                               placeholder="https://yourbrand.com" aria-label="رابط الموقع أو الصفحة" :disabled="busy">
                        <button class="btn" @click="analyze" :disabled="busy || !url.trim()">
                            <span v-if="!busy">حلّل البراند ✨</span>
                            <span v-else><span class="btn-spin"></span> بيحلل...</span>
                        </button>
                    </div>
                    <div class="bb-alt">
                        <a :href="base + '/sources.php'" class="btn ghost sm">📄 ارفع ملف ({{ files }})</a>
                        <span class="sub bb-cost" v-if="cost"><span class="cr">التحليل بـ {{ cost }} كريدت — </span>بيرجع لو ماطلعش معلومات</span>
                    </div>
                    <div class="alert info" v-if="note" style="margin-top:12px">{{ note }}</div>
                </div>

                <div class="bb-groups">
                    <div class="bb-group" v-for="g in groups" :key="g.key">
                        <div class="bb-group-head">
                            <h3>{{ g.icon }} {{ g.title }}</h3>
                            <small class="sub">{{ groupFilled(g.key) }} / {{ fieldsOf(g.key).length }}</small>
                        </div>
                        <p class="sub bb-group-hint">{{ g.hint }}</p>
                        <div class="bb-row" v-for="f in fieldsOf(g.key)" :key="f.key" :class="{ editing: editKey === f.key }">
                            <div class="bb-row-lbl">{{ f.label }}<small v-if="f.weight" class="bb-w">+{{ f.weight }}%</small></div>

                            <div class="bb-row-val" v-if="editKey !== f.key" @click="startField(f)" role="button" tabindex="0" @keydown.enter="startField(f)">
                                <span class="pv-c" v-if="f.key === 'logo_path' && f.filled"><img :src="f.value" alt="اللوجو" class="bb-logo-sm"></span>
                                <span class="pv-c" v-else-if="f.key === 'colors' && f.filled">
                                    <span v-for="c in f.value.split(',')" class="bb-sw" :style="{ background: c.trim() }"></span> <span dir="ltr">{{ f.value }}</span>
                                </span>
                                <span v-else-if="f.filled" :dir="isLink(f.value) ? 'ltr' : 'auto'">{{ display(f) }}</span>
                                <span v-else class="bb-missing">ناقص — دوس للإضافة</span>
                                <span v-if="f.source && f.filled" class="bb-src" :style="{ '--c': f.source.color }">{{ f.source.label }}</span>
                            </div>

                            <div class="bb-row-edit" v-if="editKey === f.key">
                                <div class="pv-c" v-if="f.key === 'logo_path'">
                                    <input type="file" accept="image/*" @change="uploadLogo($event)" :disabled="saving">
                                    <div class="bb-edit-act">
                                        <button class="btn ghost sm" @click="extractColors" :disabled="busy || !logo()" v-if="logo()">استخرج ألوان اللوجو</button>
                                        <button class="btn ghost sm" @click="editKey = ''">إغلاق</button>
                                    </div>
                                </div>
                                <div class="pv-c" v-if="f.key !== 'logo_path'">
                                    <select v-if="inputOf(f) === 'select'" class="input" v-model="editVal">
                                        <option value="">—</option>
                                        <option v-for="(lbl, k) in optionsOf(f)" :key="k" :value="k">{{ lbl }}</option>
                                    </select>
                                    <textarea v-else-if="inputOf(f) === 'textarea'" class="textarea" rows="3" v-model="editVal" :placeholder="f.question" dir="auto"></textarea>
                                    <input v-else class="input" v-model="editVal" :placeholder="inputOf(f) === 'colors' ? '#0C87EF, #10A8A0' : f.question" :dir="inputOf(f) === 'url' || inputOf(f) === 'colors' ? 'ltr' : 'auto'" @keydown.enter.prevent="saveField(f)">
                                    <div class="bb-sws" v-if="inputOf(f) === 'colors' && editVal.trim()">
                                        <span v-for="c in editVal.split(',')" class="bb-sw lg" :style="{ background: c.trim() }"></span>
                                    </div>
                                    <div class="bb-edit-act">
                                        <button class="btn sm" @click="saveField(f)" :disabled="saving">{{ saving ? 'بنحفظ…' : 'حفظ' }}</button>
                                        <button class="btn ghost sm" @click="editKey = ''" :disabled="saving">إلغاء</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="sub bb-adv">قواعد التصميم · الكلمات الممنوعة · صورك وصورتك الشخصية: <a :href="base + '/brand-profile.php'">التفاصيل المتقدمة ←</a></p>
            </section>

            <!-- ═══ ④ المساعد الذكي — اللي اتعمل + يبدأ من الناقص ═══ -->
            <section class="card bb-sec bb-assist" id="assistant" v-if="ov">
                <div class="bb-sec-head">
                    <div>
                        <h2>🤖 المساعد الذكي</h2>
                        <p class="sub">ملخص اللي اتعمل في هويتك — وبنكمّل مع بعض من المعلومات الناقصة.</p>
                    </div>
                    <a :href="base + '/brand-agent.php'" class="btn ghost sm">محادثة كاملة ←</a>
                </div>
                <div class="bb-done">
                    <div><b>{{ ov.done.pct }}%</b><small>صحة الهوية</small></div>
                    <div><b>{{ ov.done.filled }}/{{ ov.done.total }}</b><small>معلومة جاهزة</small></div>
                    <div><b>{{ ov.done.researches }}</b><small>بحث عميق</small></div>
                    <div><b>{{ ov.done.inspirations }}</b><small>تصميم بيعجبك</small></div>
                    <div><b>{{ ov.done.sources }}</b><small>ملف ومصدر</small></div>
                    <div v-if="ov.done.pending"><b>{{ ov.done.pending }}</b><small>معلومة مستنية تأكيدك</small></div>
                </div>
                <div class="bb-done-tags" v-if="ov.done.filled_labels.length">
                    <span class="sub">جاهز:</span>
                    <span v-for="l in ov.done.filled_labels" :key="l" class="bb-tag">✓ {{ l }}</span>
                </div>
                <p class="bb-summary sub" v-if="ov.done.summary">{{ ov.done.summary }}</p>

                <div class="bb-ask" v-if="ov.missing.length && askItem()">
                    <div class="bb-ask-q">
                        <span class="bb-ask-n">فاضل {{ ov.missing.length }}</span>
                        <b>{{ askItem().question }}</b>
                        <small class="sub">{{ askItem().label }}<span v-if="askItem().weight"> · +{{ askItem().weight }}% للهوية</span></small>
                    </div>
                    <select v-if="askItem().input === 'select'" class="input" v-model="askVal">
                        <option value="">اختار…</option>
                        <option v-for="(lbl, k) in askItem().options" :key="k" :value="k">{{ lbl }}</option>
                    </select>
                    <textarea v-else-if="askItem().input === 'textarea'" class="textarea" rows="3" v-model="askVal" dir="auto"></textarea>
                    <input v-else class="input" v-model="askVal" :dir="askItem().input === 'url' || askItem().input === 'colors' ? 'ltr' : 'auto'" @keydown.enter.prevent="answer()">
                    <div class="bb-edit-act">
                        <button class="btn" @click="answer()" :disabled="saving || !askVal.trim()">{{ saving ? 'بنحفظ…' : 'حفظ والتالي ←' }}</button>
                        <button class="btn ghost sm" @click="skipAsk()" v-if="ov.missing.length > 1">تخطّي</button>
                    </div>
                </div>
                <div class="bb-ask ok" v-if="!ov.missing.length">✓ كل المعلومات الأساسية موجودة — تقدر تعدّل أي حاجة من «بيانات الهوية».</div>
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
  var DIALECTS = { egyptian: 'مصري', khaleeji: 'خليجي', levantine: 'شامي', msa: 'فصحى' };

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
    editing: null,
    draft: '',
    toast: null,
    logoColors: [],
    groups: init.groups,
    ov: init.overview,
    researchOn: init.research,
    insp: init.insp, inspMax: init.insp_max, inspUrl: '', inspBusy: false,
    editKey: '', editVal: '', saving: false,
    askSkip: 0, askVal: '',

    /* ── بيانات الهوية: تعديل مباشر ── */
    inputOf: function (f) { return init.inputs[f.key] || 'text'; },
    optionsOf: function (f) { return init.options[f.key] || {}; },
    groupFilled: function (g) { return this.fieldsOf(g).filter(function (f) { return f.filled; }).length; },
    startField: function (f) {
      this.editKey = f.key;
      this.editVal = f.key === 'logo_path' ? '' : String(f.value || '');
    },
    async saveField(f) {
      this.saving = true;
      var before = this.b.health.pct;
      var r = await SpreadAPI.post('brand', 'save_field', { field: f.key, value: this.editVal });
      this.saving = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.b = r.brand; this.ov = r.overview; this.editKey = '';
      var d = this.b.health.pct - before;
      this.notify(d > 0 ? 'اتحفظ ✓ · +' + d + '%' : 'اتحفظ ✓');
    },
    async uploadLogo(e) {
      var file = e.target.files && e.target.files[0];
      if (!file) return;
      this.saving = true;
      var r = await this.postFile('upload_logo', { logo: file });
      this.saving = false;
      if (!r.ok) return this.notify(r.error || 'تعذّر الرفع', 'danger');
      this.b = r.brand; this.ov = r.overview;
      this.notify('اتحفظ اللوجو ✓ — استخرج ألوانه علشان تتطبّق');
    },
    async postFile(action, fields) {
      var fd = new FormData();
      fd.append('action', action);
      Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
      try {
        var res = await fetch(base + '/api/brand.php', { method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content, 'X-Requested-With': 'XMLHttpRequest' } });
        return await res.json();
      } catch (err) { return { ok: false, error: 'خطأ في الاتصال' }; }
    },

    /* ── تصميمات بتعجبك ── */
    async inspUpload(e) {
      var list = Array.prototype.slice.call(e.target.files || []);
      if (!list.length) return;
      this.inspBusy = true;
      var ok = 0, err = '';
      for (var i = 0; i < list.length; i++) {
        var r = await this.postFile('insp_upload', { file: list[i] });
        if (r.ok) { ok++; this.insp = r.items; } else { err = r.error; break; }
      }
      this.inspBusy = false; e.target.value = '';
      if (err) this.notify(err, ok ? 'warning' : 'danger'); else this.notify('اتضاف ' + ok + (ok === 1 ? ' تصميم' : ' تصميمات') + ' ✓');
      this.refreshOverview();
    },
    async inspLink() {
      if (!this.inspUrl.trim()) return;
      this.inspBusy = true;
      var r = await SpreadAPI.post('brand', 'insp_link', { url: this.inspUrl.trim() });
      this.inspBusy = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.insp = r.items; this.inspUrl = '';
      this.notify(r.image ? 'اتضاف ✓' : 'اتحفظ اللينك — مالقيناش صورة فيه، فمش هيظهر كمرجع ستايل', r.image ? 'success' : 'info');
      this.refreshOverview();
    },
    async inspDelete(it) {
      if (!confirm('تمسح التصميم ده من «بتعجبك»؟')) return;
      var r = await SpreadAPI.post('brand', 'insp_delete', { id: it.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.insp = r.items;
      this.refreshOverview();
    },

    /* ── المساعد: يبدأ من الناقص ── */
    askItem: function () {
      var m = this.ov ? this.ov.missing : [];
      return m.length ? m[this.askSkip % m.length] : null;
    },
    skipAsk: function () { this.askSkip++; this.askVal = ''; },
    async answer() {
      var it = this.askItem();
      if (!it || !this.askVal.trim()) return;
      this.saving = true;
      var before = this.b.health.pct;
      var r = await SpreadAPI.post('brand', 'save_field', { field: it.key, value: this.askVal.trim() });
      this.saving = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.b = r.brand; this.ov = r.overview; this.askVal = ''; this.askSkip = 0;
      var d = this.b.health.pct - before;
      this.notify(d > 0 ? 'تمام ✓ · الهوية +' + d + '%' : 'تمام ✓');
    },
    async refreshOverview() {
      var r = await SpreadAPI.get('brand', { action: 'overview' });
      if (r.ok) this.ov = r.overview;
    },
    rsStatus: function (s) { return { done: 'خلص', running: 'شغّال…', failed: 'فشل', queued: 'مستني' }[s] || s; },

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
      this.refreshOverview();
      if (!r.suggested) this.notify('ماطلعش معلومات جديدة — الكريدت رجعلك', 'info');
    },
    async decide(s, decision, value) {
      var payload = { id: s.id, decision: decision };
      if (value !== undefined) payload.value = value;
      var r = await SpreadAPI.post('brand', 'decide', payload);
      if (!r.ok) return this.notify(r.error, 'danger');
      var before = this.b.health.pct;
      this.b = r.brand; this.editing = null;
      this.refreshOverview();
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
      this.refreshOverview();
      this.notify('اتأكدت ' + r.applied + ' معلومة · الهوية ' + this.b.health.pct + '%' + (this.b.health.pct > before ? ' ↑' : ''));
    },
    async extractColors() {
      this.busy = true;
      var r = await SpreadAPI.post('brand', 'logo_colors', {});
      this.busy = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.logoColors = r.colors; this.b = r.brand; this.editKey = '';
      this.notify('ألوان اللوجو ظهرت فوق في «وجدنا معلومات» — أكّدها ✓');
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
