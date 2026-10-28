<?php
/**
 * Spread AI — الأدمن: البرومبتات والـ Playground (المرحلة H)
 * المحرر بالنسخ والفرق والرجوع · الـ Playground (1-3 موديلات جنب بعض + الـ Pipeline الكامل) · حالات الاختبار · اختبارات الرجوع
 * كل التشغيل هنا sandbox — مفيش كتابة في بيانات العملاء ولا خصم كريدت.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ce-prompts.php';

require_admin();
require_admin_can('ai_settings');
$page_title = 'البرومبتات والـ Playground';
$active = 'ce-lab';
include __DIR__ . '/../templates/admin-header.php';
$__ver = fn(string $f) => url($f) . '?v=' . (@filemtime(__DIR__ . '/../' . $f) ?: time());
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main ce-adm">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head">
            <h1>البرومبتات والـ Playground 🧪</h1>
            <div class="sub">نسخ لكل مرحلة · مقارنة 3 موديلات جنب بعض · حالات اختبار ثابتة · اختبار رجوع قبل أي تفعيل — من غير ما نلمس بيانات العملاء أو الكريدت</div>
        </div>
        <?php if (!ce_prompts_ready()): ?>
            <div class="alert warning">شغّل ترحيل «2026_ce_lab» من <a href="<?= e(url('admin/migrations.php')) ?>">الترحيلات</a> الأول.</div>
        <?php else: ?>
        <div id="lab" class="lab" v-cloak data-api="<?= e(url('admin/ce-lab-api.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
            <div v-if="!st" class="a2-muted">بنحمّل…</div>
            <div class="bk-tpl" v-if="st">
            <!-- المراحل -->
            <div class="lab-stages" role="tablist">
                <button v-for="s in st.stages" :key="s.key" :class="{ on: stage === s.key }" @click="openStage(s.key)" role="tab">
                    <b>{{ s.label }}</b><small>v{{ s.active_version }} · {{ s.cases }} حالة</small></button>
            </div>

            <div class="a2-seg" style="margin:12px 0">
                <button v-for="(l, k) in tabs" :class="{ on: tab === k }" @click="tab = k">{{ l }}</button>
            </div>

            <div v-if="!sd" class="a2-muted">بنحمّل المرحلة…</div>
            <div class="bk-tpl" v-if="sd">

            <!-- ═══ البرومبت ═══ -->
            <div v-if="tab === 'prompt'" class="lab-grid">
                <section class="card">
                    <div class="a2-h"><h3>{{ sd.label }} — المحرر</h3><span class="a2-grow"></span>
                        <small class="a2-muted">بيبدأ من: {{ edFrom }}</small></div>
                    <div class="lab-ed">
                        <pre class="lab-hl" aria-hidden="true" ref="hl" v-html="hl(edBody) + '\n'"></pre>
                        <textarea ref="ed" v-model="edBody" spellcheck="false" dir="auto" @scroll="syncScroll" @input="syncScroll" aria-label="نص البرومبت"></textarea>
                    </div>
                    <div class="lab-unknown" v-if="unknownVars().length">⛔ متغيرات مش متاحة للمرحلة دي: <code v-for="v in unknownVars()">{{ '{' + '{' + v + '}' + '}' }}</code></div>
                    <div class="lab-row">
                        <input class="form-control" v-model="edNotes" placeholder="ملاحظات النسخة (اتغيّر إيه وليه)" maxlength="500">
                        <button class="btn" :disabled="busy || unknownVars().length > 0" @click="saveVersion()">احفظ كنسخة جديدة</button>
                        <button class="btn ghost" @click="tryDraft()">جرّبها في الـ Playground</button>
                    </div>
                    <small class="a2-muted">النسخة الجديدة بتتحفظ مسودة — مابتتفعّلش غير بعد ما تعدّي اختبار الرجوع على حالات الاختبار.</small>
                </section>
                <aside class="card lab-vars">
                    <div class="a2-h"><h3>المتغيرات</h3></div>
                    <p class="a2-muted" v-if="!sd.vars.length" style="margin:0">المرحلة دي مالهاش متغيرات — بيانات البراند والحملة بتيجي في رسالة المستخدم.</p>
                    <button v-for="v in sd.vars" class="lab-var" @click="insertVar(v.key)" :title="v.sample">
                        <code>{{ '{' + '{' + v.key + '}' + '}' }}</code><span>{{ v.label }}</span><small v-if="v.sample">{{ v.sample }}</small></button>
                    <small class="a2-muted">دوس على متغير يتحط مكان المؤشر.</small>
                </aside>

                <section class="card lab-wide">
                    <div class="a2-h"><h3>النسخ</h3></div>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>النسخة</th><th>ملاحظات</th><th>اختبار الرجوع</th><th></th></tr></thead><tbody>
                        <tr v-for="v in versionsAll()" :key="v.id" :class="{ 'lab-on': v.is_active }">
                            <td><b>v{{ v.version }}</b> <span class="chip chip-mint" v-if="v.is_active">شغالة</span><br><small class="a2-muted">{{ v.author || (v.id ? '' : 'الكود') }} {{ v.created_at || '' }}</small></td>
                            <td style="max-width:280px">{{ v.notes || '—' }}</td>
                            <td><span v-if="v.id && v.run" class="chip" :class="runChip(v.run.status)">{{ runLabel[v.run.status] }}<span v-if="v.run.delta !== null"> · {{ v.run.delta > 0 ? '+' : '' }}{{ v.run.delta }}</span></span><span v-else-if="v.id" class="a2-muted">لسه</span><span v-else class="a2-muted">—</span></td>
                            <td class="lab-acts">
                                <button class="btn sm ghost" @click="loadToEditor(v)">افتح</button>
                                <button class="btn sm ghost" @click="diffX = v.id; showDiff()">قارن</button>
                                <button class="btn sm ghost" v-if="v.id && !v.is_active && !v.was_active" :disabled="busy" @click="startRegression(v.id, null)">اختبر</button>
                                <button class="btn sm" v-if="!v.is_active && (v.was_active || v.id === 0 || (v.run && (v.run.status === 'passed' || v.run.status === 'warn')))" :disabled="busy" @click="activate(v)">{{ v.was_active || v.id === 0 ? 'ارجع لها' : 'فعّل' }}</button>
                            </td></tr>
                    </tbody></table></div>
                    <div class="lab-diff-bar">
                        <label>قارن<select class="form-control" v-model.number="diffX"><option v-for="v in versionsAll()" :value="v.id">v{{ v.version }}</option></select></label>
                        <label>مع<select class="form-control" v-model.number="diffY"><option v-for="v in versionsAll()" :value="v.id">v{{ v.version }}</option></select></label>
                        <button class="btn sm" @click="showDiff()">اعرض الفرق</button>
                    </div>
                    <pre class="lab-diff" v-if="diff"><span v-for="d in diff" :class="'d' + (d.op === '+' ? 'a' : d.op === '-' ? 'r' : 'k')">{{ d.op }} {{ d.line }}
</span></pre>
                </section>
            </div>

            <!-- ═══ الـ Playground ═══ -->
            <div v-if="tab === 'play'">
                <section class="card">
                    <div class="lab-sel">
                        <label class="ce-f"><span>البراند</span><select class="form-control" v-model.number="pg.uid"><option v-for="b in st.brands" :value="b.uid">{{ b.name }}</option></select></label>
                        <label class="ce-f"><span>الشكل</span><select class="form-control" v-model="pg.format"><option value="post">بوست</option><option value="carousel">كاروسيل</option><option value="reel">ريلز</option></select></label>
                        <label class="ce-f"><span>البرومبت</span><select class="form-control" v-model="pg.src">
                            <option value="active">النسخة الشغالة</option><option value="draft" :disabled="!edBody">المسودة اللي في المحرر</option>
                            <option v-for="v in versionsAll()" :value="'v' + v.id">v{{ v.version }}{{ v.is_active ? ' (شغالة)' : '' }}</option></select></label>
                        <div class="ce-f lab-models"><span>الموديلات (لحد 3)</span>
                            <label v-for="m in sd.models" class="lab-m" :class="{ on: pg.models.indexOf(m.key) >= 0 }"><input type="checkbox" :value="m.key" v-model="pg.models" :disabled="pg.models.length >= 3 && pg.models.indexOf(m.key) < 0"> {{ m.label }}<small v-if="m.key === sd.current_model"> · الحالي</small></label>
                            <small class="a2-muted" v-if="!sd.models.length">مفيش موديلات مناسبة في السجل — بنستخدم الحالي ({{ sd.current_model || '—' }})</small></div>
                    </div>
                    <div class="lab-row">
                        <button class="btn ghost" :disabled="busy" @click="loadInput()">جيب المدخلات من البراند</button>
                        <button class="btn" :disabled="busy || !pg.user" @click="runPlay()">شغّل {{ pg.models.length > 1 ? 'جنب بعض' : '' }}</button>
                        <label class="st-sw"><input type="checkbox" v-model="pg.pipeline"><span>الـ Pipeline الكامل (مرحلة ورا مرحلة على البراند)</span></label>
                    </div>
                    <details class="lab-input" :open="!!pg.user"><summary>رسالة المستخدم (تقدر تعدّلها) <small class="a2-muted">{{ pg.user.length }} حرف<span v-if="pg.images.length"> · {{ pg.images.length }} صورة</span></small></summary>
                        <textarea class="form-control" v-model="pg.user" rows="10" dir="auto"></textarea>
                        <img v-for="im in pg.images" :src="im" alt="" class="lab-img"></details>
                </section>

                <!-- الـ Pipeline -->
                <section class="card" v-if="pg.pipeline">
                    <div class="a2-h"><h3>الـ Pipeline الكامل</h3><span class="a2-grow"></span><button class="btn sm ghost" @click="pipeReset()">من الأول</button></div>
                    <div class="lab-pipe">
                        <div v-for="(p, i) in pipe" class="lab-step" :class="{ cur: i === pipeAt, done: p.out }">
                            <b>{{ i + 1 }}. {{ stageLabel(p.stage) }}</b>
                            <span v-if="p.out" class="chip chip-mint">✓ {{ p.score !== null ? p.score : '' }}</span>
                            <button v-if="i === pipeAt" class="btn sm" :disabled="busy" @click="pipeRun(i)">شغّل</button>
                            <details v-if="p.out"><summary>الناتج (عدّله قبل المرحلة الجاية)</summary><textarea class="form-control" rows="8" v-model="p.outText" dir="auto"></textarea>
                                <button class="btn sm ghost" v-if="i === pipeAt - 1" @click="pipeApply(i)">استخدم التعديل</button></details>
                        </div>
                    </div>
                </section>

                <div class="lab-results" v-if="pg.results.length" :style="{ gridTemplateColumns: 'repeat(' + pg.results.length + ', minmax(0, 1fr))' }">
                    <section v-for="(r, i) in pg.results" class="card lab-res">
                        <div class="a2-h"><h3 dir="ltr">{{ r.provider }} · {{ r.model }}</h3></div>
                        <div class="lab-meta" v-if="r"><span>⏱ {{ r.ms }}ms</span><span>🔤 {{ r.tokens_in }}/{{ r.tokens_out }}</span><span dir="ltr">${{ r.cost_usd }}</span>
                            <span class="chip" :class="r.ok && r.check.valid ? 'chip-mint' : 'chip-coral'">{{ r.ok ? (r.check.valid ? 'الشكل سليم' : 'الشكل فيه مشاكل') : 'فشل' }}</span></div>
                        <div class="ce-warn" v-if="r.error">{{ r.error }}</div>
                        <ul class="lab-issues" v-if="r.check && r.check.issues.length"><li v-for="x in r.check.issues">{{ x }}</li></ul>
                        <div class="lab-score" v-if="r.critic"><b>{{ r.critic.score }}</b><small>{{ criticLine(r.critic) }}</small></div>
                        <div class="lab-prev" v-if="r.data" v-html="preview(r.data)"></div>
                        <details><summary>JSON</summary><pre class="lab-json">{{ pretty(r.data || r.text) }}</pre></details>
                        <div class="lab-acts">
                            <button class="btn sm ghost" :disabled="busy || !r.ok" @click="runCritic(r)">قيّم بالناقد</button>
                            <button class="btn sm ghost" :disabled="busy || !r.ok" @click="saveCase(r)">احفظ كحالة اختبار</button>
                            <button class="btn sm ghost" v-if="r.model_key && r.model_key !== sd.current_model" :disabled="busy" @click="startRegression(null, r.model_key)">طبّق الموديل ده على المرحلة</button>
                        </div>
                    </section>
                </div>
                <section class="card" v-if="pg.src === 'draft' && pg.results.length">
                    <div class="lab-row"><span>المسودة عجبتك؟</span><button class="btn" :disabled="busy" @click="promoteDraft()">رقّيها للإنتاج (حفظ + اختبار رجوع)</button></div>
                </section>
            </div>

            <!-- ═══ حالات الاختبار ═══ -->
            <div v-if="tab === 'cases'">
                <section class="card">
                    <div class="a2-h"><h3>حالات الاختبار — {{ sd.label }}</h3><span class="a2-grow"></span>
                        <button class="btn sm" :disabled="busy" @click="seedCase()">حالة من البراند التجريبي</button></div>
                    <p class="a2-muted" style="margin:0 0 10px">كل نسخة برومبت أو موديل جديد بيتجرّب على الحالات دي قبل التفعيل. للنقاد: الرد اللي اتحفظ هو «المتوقع» والجديد بيتقاس بقربه منه. للإنتاج: الناقد بيدّي درجة ولازم توصل لأقل درجة.</p>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الحالة</th><th>أقل درجة</th><th>متوقع</th><th>شغالة</th><th></th></tr></thead><tbody>
                        <tr v-for="c in sd.cases" :key="c.id"><td><b>{{ c.name }}</b><br><small class="a2-muted">{{ c.created_at }}<span v-if="c.images"> · بصورة</span></small></td>
                            <td class="num">{{ c.min_score }}</td><td>{{ c.has_expected ? '✓' : '—' }}</td>
                            <td><label class="st-sw"><input type="checkbox" :checked="c.is_active" @change="caseToggle(c)"></label></td>
                            <td><button class="btn sm ghost" @click="caseDelete(c)">امسح</button></td></tr>
                        <tr v-if="!sd.cases.length"><td colspan="5" class="a2-muted">مفيش حالات — احفظ واحدة من الـ Playground أو اعمل حالة من البراند التجريبي.</td></tr>
                    </tbody></table></div>
                </section>
            </div>

            <!-- ═══ اختبارات الرجوع ═══ -->
            <div v-if="tab === 'runs'">
                <section class="card" v-if="run">
                    <div class="a2-h"><h3>اختبار #{{ run.id }} — {{ run.kind === 'prompt' ? 'نسخة برومبت' : 'موديل ' + run.candidate_model }}</h3><span class="a2-grow"></span>
                        <span class="chip" :class="runChip(run.status)">{{ runLabel[run.status] }}</span></div>
                    <div class="ce-bar" v-if="run.status === 'running'"><i :style="{ width: Math.max(5, 100 * run.done / Math.max(1, run.total)) + '%' }"></i></div>
                    <p v-if="run.status === 'running'" class="a2-muted">{{ run.done }} من {{ run.total }} حالة…</p>
                    <div class="lab-sum" v-if="run.avg_new !== null">
                        <div class="a2-kpi"><small>الحالي</small><b>{{ run.avg_base }}</b><em dir="ltr">{{ run.baseline_model }}</em></div>
                        <div class="a2-kpi"><small>الجديد</small><b>{{ run.avg_new }}</b></div>
                        <div class="a2-kpi"><small>الفرق</small><b :style="{ color: run.delta < -st.max_drop ? '#C2362E' : (run.delta < 0 ? '#9A5B00' : '#0B8F83') }">{{ run.delta > 0 ? '+' : '' }}{{ run.delta }}</b><em>تحذير لو نزل أكتر من {{ st.max_drop }}</em></div>
                    </div>
                    <p class="ce-warn" v-if="run.note">{{ run.note }}</p>
                    <div class="a2-tw" v-if="run.items.length"><table class="a2-tbl"><thead><tr><th>الحالة</th><th>الحالي</th><th>الجديد</th><th>أقل درجة</th></tr></thead><tbody>
                        <tr v-for="x in run.items"><td>{{ x.name || ('#' + x.case) }}</td>
                            <td class="num">{{ x.base ? x.base.score : '—' }}<small class="ce-warn" v-if="x.base && !x.base.valid"> بايظ</small></td>
                            <td class="num">{{ x.new ? x.new.score : '—' }}<small class="ce-warn" v-if="x.new && !x.new.valid"> بايظ</small><br><small class="a2-muted" v-if="x.new && x.new.issues.length">{{ x.new.issues.join(' · ') }}</small></td>
                            <td class="num">{{ x.min }}</td></tr>
                    </tbody></table></div>
                    <div class="lab-row" v-if="run.status === 'passed' || run.status === 'warn'">
                        <label class="st-sw" v-if="run.status === 'warn'"><input type="checkbox" v-model="ackWarn"><span>فاهم إن الدرجة نزلت وعايز أكمّل</span></label>
                        <button class="btn" v-if="run.kind === 'prompt'" :disabled="busy || (run.status === 'warn' && !ackWarn)" @click="activateRun(run)">فعّل النسخة دي</button>
                        <button class="btn" v-if="run.kind === 'model'" :disabled="busy || (run.status === 'warn' && !ackWarn)" @click="applyModel(run)">طبّق الموديل على المرحلة</button>
                    </div>
                </section>
                <section class="card">
                    <div class="a2-h"><h3>آخر الاختبارات</h3></div>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>#</th><th>النوع</th><th>النتيجة</th><th>الفرق</th><th>التكلفة</th><th></th></tr></thead><tbody>
                        <tr v-for="r in sd.runs"><td class="num">{{ r.id }}</td><td>{{ r.kind === 'prompt' ? 'برومبت v' + versionNo(r.candidate_version_id) : r.candidate_model }}</td>
                            <td><span class="chip" :class="runChip(r.status)">{{ runLabel[r.status] }}</span></td><td class="num">{{ r.delta !== null ? r.delta : '—' }}</td>
                            <td class="num" dir="ltr">${{ r.cost_usd }}</td><td><button class="btn sm ghost" @click="openRun(r.id)">افتح</button></td></tr>
                        <tr v-if="!sd.runs.length"><td colspan="6" class="a2-muted">مفيش اختبارات لسه.</td></tr>
                    </tbody></table></div>
                </section>
            </div>
            </div>

            <div v-if="toast.m" class="lab-toast" :class="toast.t" role="status">{{ toast.m }}</div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>
<script src="<?= $__ver('assets/vendor/petite-vue.iife.js') ?>"></script>
<script src="<?= $__ver('assets/js/ce-lab.js') ?>"></script>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
