<?php
/**
 * Spread AI v2 — حملاتي (petite-vue)
 * PHP بيرسم الهيكل والبيانات الأولية ← petite-vue بيتولى التفاعل
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/lifecycle.php';
require_once __DIR__ . '/../includes/campaign-flow.php';   // أرقام الأفكار والتصميم والنشر لكل حملة (⑥-ب)

require_login();
$user = current_user();

if (get_setting('campaigns_enabled', '1') !== '1') {
    flash_set('info', 'الحملات مش متاحة حاليًا');
    redirect('dashboard.php');
}
if (!ui_v2_enabled()) {
    flash_set('info', 'الحملات جزء من الشكل الجديد — فعّله من ملفك الشخصي وجرّبها ✨');
    redirect('profile.php');
}

// البيانات الأولية بتترسم مع الصفحة → بتظهر فورًا من غير انتظار أول طلب
$rows = db_all('SELECT * FROM campaigns WHERE user_id = ? AND status = "active" ORDER BY updated_at DESC LIMIT 100', [$user['id']]);
$counts = ['active' => 0, 'completed' => 0, 'archived' => 0];
foreach (db_all('SELECT status, COUNT(*) n FROM campaigns WHERE user_id = ? GROUP BY status', [$user['id']]) as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
$initial = [
    'campaigns' => array_map('campaign_to_api', $rows),
    'counts'    => $counts,
    'goals'     => campaign_goals(),
    'stages'    => campaign_stages(),
    'bases'     => campaign_flow_bases() + campaign_bases(),
];

$active = 'campaigns';
$page_title = 'حملاتي';
$use_app = true;
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>
        <?= render_flash() ?>

        <div id="campaigns-app" v-cloak @click="menu = null">
            <!-- الرأس -->
            <div class="cp-head">
                <div>
                    <h1>حملاتي</h1>
                    <div class="sub">
                        <b>{{ counts.active }}</b> قيد التنفيذ ·
                        <b>{{ counts.completed }}</b> مكتملة ·
                        كل حملة بتكمّل من نفس النقطة
                    </div>
                </div>
                <button class="btn lg" @click="create" :disabled="busy">
                    <span v-if="!busy">＋ حملة جديدة</span>
                    <span v-else><span class="btn-spin"></span> بنجهّز...</span>
                </button>
            </div>

            <!-- الفلاتر -->
            <div class="cp-bar">
                <div class="seg">
                    <button v-for="t in tabs" :key="t.k" :class="{ on: status === t.k }" @click="setStatus(t.k)">
                        {{ t.t }} <span class="cp-count">{{ counts[t.k] }}</span>
                    </button>
                </div>
                <div class="cp-search">
                    <span aria-hidden="true">⌕</span>
                    <input type="search" v-model="q" @input="search" placeholder="ابحث في حملاتك..." aria-label="بحث">
                </div>
            </div>

            <!-- تحميل -->
            <div v-if="loading" class="cp-grid">
                <div v-for="n in 3" class="card cp-card cp-skel"><i></i><i></i><i></i></div>
            </div>

            <!-- فاضي -->
            <div v-else-if="!list.length" class="empty cp-empty">
                <div class="cp-empty-ic">{{ q ? '🔍' : (status === 'active' ? '🚀' : '📁') }}</div>
                <h3 v-if="q">مفيش حملات بالاسم ده</h3>
                <h3 v-else-if="status === 'active'">ابدأ أول حملة</h3>
                <h3 v-else>مفيش حملات هنا</h3>
                <p class="sub" v-if="status === 'active' && !q">
                    الحملة بتاخدك من الفكرة لحد النشر في 6 خطوات — وكل حاجة بتتحفظ لوحدها.
                </p>
                <button v-if="status === 'active' && !q" class="btn" @click="create">＋ حملة جديدة</button>
            </div>

            <!-- الكروت -->
            <div v-else class="cp-grid">
                <article v-for="c in list" :key="c.id" class="card cp-card">
                    <div class="cp-top">
                        <div class="cp-tt">
                            <a :href="base + '/campaign.php?id=' + c.id" class="cp-title">{{ c.title }}</a>
                            <div class="sub cp-topic">{{ bases[c.basis] || (c.goal && goals[c.goal] ? goals[c.goal][0] : 'حملة') }} · {{ timeAgo(c.updated_at) }}</div>
                        </div>
                        <span class="cp-state" :class="stateOf(c).cls">{{ stateOf(c).label }}</span>
                        <div class="cp-menu">
                            <button class="icon-btn cp-dots" @click.stop="menu = menu === c.id ? null : c.id" aria-label="خيارات">⋯</button>
                            <div class="cp-pop" v-if="menu === c.id" @click.stop>
                                <button @click="act(c, 'duplicate')">⧉ تكرار</button>
                                <a :href="base + '/campaign-export.php?id=' + c.id">⬇ تصدير المحتوى</a>
                                <button v-if="c.status === 'active'" @click="act(c, 'complete')">✓ علّمها مكتملة</button>
                                <button v-if="c.status !== 'archived'" @click="act(c, 'archive')">🗄 أرشفة</button>
                                <button v-else @click="act(c, 'restore')">↩ استرجاع</button>
                                <button class="danger" @click="remove(c)">🗑 حذف</button>
                            </div>
                        </div>
                    </div>

                    <div class="cp-stage">
                        <div class="bar"><span class="bar-fill" :style="{ width: c.progress + '%' }"></span></div>
                        <b>{{ c.progress }}% مكتملة</b>
                    </div>
                    <div class="cp-steps">
                        <span v-for="n in [1,2,3,4,5,6]" :key="n" :class="stepOf(c, n)">
                            <i>{{ stepOf(c, n) === 'done' ? '✓' : (stepOf(c, n) === 'now' ? '◐' : '○') }}</i>{{ stages[n].name }}</span>
                    </div>

                    <div class="cp-stats">
                        <div><b>{{ c.flow ? c.flow.selected || c.flow.ideas : 0 }}</b><span>أفكار</span></div>
                        <div><b>{{ c.flow && c.flow.posts ? c.flow.posts : c.contents.total }}</b><span>منشورات</span></div>
                        <div><b>{{ c.flow && c.flow.designed ? c.flow.designed : designs(c) }}</b><span>تصميمات</span></div>
                        <div><b>{{ pubStat(c).v }}</b><span>{{ pubStat(c).l }}</span></div>
                    </div>

                    <div class="cp-foot">
                        <b class="cp-next">{{ c.status === 'completed' ? 'الحملة خلصت 🎉' : 'المرحلة الجاية: ' + (stages[Math.min(6, c.max_stage + (c.max_stage < 6 ? 1 : 0))] || {}).name }}</b>
                        <a :href="base + '/campaign.php?id=' + c.id" class="btn soft sm">
                            {{ c.status === 'active' ? 'متابعة ←' : 'فتح ←' }}
                        </a>
                    </div>
                </article>
            </div>

            <div class="toast-container" v-if="toast"><div class="toast" :class="toast.type">{{ toast.msg }}</div></div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var init = <?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var base = document.querySelector('meta[name="app-base"]').content;
  var timer = null;

  var app = SpreadApp.mount('#campaigns-app', {
    base: base,
    list: init.campaigns,
    counts: init.counts,
    goals: init.goals,
    stages: init.stages,
    status: 'active',
    q: '',
    loading: false,
    busy: false,
    menu: null,
    toast: null,
    tabs: [{ k: 'active', t: 'قيد التنفيذ' }, { k: 'completed', t: 'مكتملة' }, { k: 'archived', t: 'مؤرشفة' }],

    bases: init.bases,
    stateOf: function (c) {
      if (c.status === 'completed') return { label: 'مكتملة', cls: 'ok' };
      if (c.status === 'archived') return { label: 'مؤرشفة', cls: 'grey' };
      if (!c.flow || (!c.flow.ideas && !c.contents.total)) return { label: 'مسودة', cls: 'grey' };
      return { label: 'قيد التنفيذ', cls: 'blue' };
    },
    stepOf: function (c, n) {
      if (c.status === 'completed' || n < c.max_stage || (n === c.max_stage && n !== c.stage)) return 'done';
      return n === c.stage ? 'now' : 'todo';
    },
    pubStat: function (c) {
      var f = c.flow || {}, pub = f.published || c.contents.counts.published;
      if (pub) return { v: pub, l: 'اتنشر ✓' };
      if (f.queued) return { v: f.queued, l: 'مجدول' };
      return { v: 'لسه', l: 'النشر' };
    },
    designs: function (c) {
      var k = c.contents.counts;
      return k.design_ready + k.scheduled + k.ready_to_publish + k.published + k.publish_failed;
    },
    notify: function (msg, type) { SpreadApp.toast(this, msg, type || 'success'); },
    async load() {
      this.loading = true;
      var r = await SpreadAPI.get('campaigns', { action: 'list', status: this.status, q: this.q });
      this.loading = false;
      if (r.ok) { this.list = r.campaigns; this.counts = r.counts; }
      else this.notify(r.error, 'danger');
    },
    setStatus: function (s) { this.status = s; this.menu = null; this.load(); },
    search: function () {
      var self = this;
      clearTimeout(timer);
      timer = setTimeout(function () { self.load(); }, 300);
    },
    // 10: الحملة الجديدة بتبدأ ببريف سريع (الاسم · الهدف · الجمهور · المنصات · الميزانية · المدة · المتطلبات)
    create() {
      if (this.busy) return;
      this.busy = true;
      location.href = base + '/campaign-new.php';
    },
    async act(c, action) {
      this.menu = null;
      var r = await SpreadAPI.post('campaigns', action, { id: c.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      var msg = { duplicate: 'اتعملت نسخة ✓', archive: 'اتأرشفت', restore: 'رجعت للحملات النشطة', complete: 'مبروك — الحملة اكتملت 🎉' };
      this.notify(msg[action] || 'تم');
      this.load();
    },
    async remove(c) {
      this.menu = null;
      if (!confirm('تحذف «' + c.title + '»؟\nالمنشورات هتفضل موجودة في «المحتوى السابق».')) return;
      var r = await SpreadAPI.post('campaigns', 'delete', { id: c.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.notify('اتحذفت');
      this.load();
    },
  });

  // «＋ خطة جديدة» و«حملة جديدة» بيوصلوا هنا بـ ?new=1 — الإنشاء بيتم عن طريق الـ API
  // (بـ CSRF) مش بمجرد فتح رابط، عشان محدش يقدر يعمل حملات باسمك من لينك
  if (new URLSearchParams(location.search).get('new') === '1') {
    history.replaceState(null, '', location.pathname);
    var nb = document.querySelector('.cp-head .btn');
    if (nb) nb.click();
  }

});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
