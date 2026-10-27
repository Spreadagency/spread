<?php
/**
 * Spread AI — محرك المحتوى v3
 * صفحة واحدة: ملف الهوية المختصر (Brand DNA) ← حملة جديدة بالاختيارات (9 خطوات) ← التقدير ← الخطة والبوابة ← الكتابة ومراجعة الجودة
 * متاحة بس للعملاء اللي الأدمن فتحلهم الميزة (الميزات ← محرك المحتوى الجديد). الحملة القديمة شغالة زي ما هي.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ce-jobs.php';
if (is_file(__DIR__ . '/../includes/ui-v2.php')) require_once __DIR__ . '/../includes/ui-v2.php';

require_login();
$user = current_user();
if (!ce_enabled_for((int) $user['id'])) redirect('campaigns.php');

$__v2 = function_exists('ui_v2_enabled') && ui_v2_enabled();
$active = 'engine';
$page_title = 'محرك المحتوى';
$use_app = true;
$body_class = 'bk-page';
include __DIR__ . '/../templates/header.php';
$__ver = fn(string $f) => url($f) . '?v=' . (@filemtime(__DIR__ . '/../' . $f) ?: time());
?>
<link rel="stylesheet" href="<?= $__ver('assets/css/brand-kit.css') ?>">
<link rel="stylesheet" href="<?= $__ver('assets/css/engine.css') ?>">
<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php if ($__v2) include __DIR__ . '/../templates/topbar.php'; ?>
        <?= render_flash() ?>

        <div id="ce-app" class="bk ce" v-cloak data-brandkit="<?= e(url('brand-kit.php')) ?>">
            <div v-if="loading" class="bk-loading" role="status">بنجهّز المحرك…</div>

            <div class="bk-tpl" v-if="!loading">
            <!-- ═══ الرئيسية ═══ -->
            <div v-if="view === 'home'">
                <section class="bk-card ce-hero">
                    <h1>محرك المحتوى ✨</h1>
                    <p class="bk-mute">بنلخّص هويتك في ملف صغير (Brand DNA)، وبعدين تعمل حملة كاملة بالاختيارات — وتعرف تكلفتها قبل ما تبدأ.</p>
                </section>

                <section class="bk-card">
                    <div class="ce-h"><h2>🧬 ملف هويتك المختصر</h2>
                        <span v-if="st.exists && !st.stale" class="bk-pill ok">محدّث</span>
                        <span v-else-if="st.exists" class="bk-pill warn">هويتك اتغيرت — حدّثه</span>
                        <span v-else class="bk-pill">لسه ماتعملش</span></div>
                    <p class="bk-mute" v-if="!st.exists">بنجمع خدماتك وعملائك وطريقة كلامك وألوانك في ملف واحد مختصر. هو ده اللي بيتبعت للـ AI في كل خطوة بدل الوثائق كلها — أسرع وأرخص وأدق.</p>
                    <p class="bk-mute" v-else>نسخة {{ st.version }} · حوالي {{ st.tokens }} توكن من {{ opts.max_tokens }} · {{ st.built_by === 'ai' ? 'اتكتب بالـ AI' : (st.built_by === 'user' ? 'عدّلته بإيدك' : 'من بيانات هويتك') }}<span v-if="st.edited && st.edited.length"> · {{ st.edited.length }} حقل معدّل يدويًا</span></p>

                    <div v-if="job && job.active" class="ce-job" role="status">
                        <div class="ce-bar"><i :style="{ width: Math.max(6, job.progress) + '%' }"></i></div>
                        <div class="ce-job-row"><span>{{ job.label }}</span><button class="bk-link" @click="cancelJob()">إلغاء</button></div>
                        <small class="bk-mute" v-if="!worker">خلّي الصفحة مفتوحة لحد ما يخلص</small>
                    </div>
                    <div v-if="job && job.status === 'failed'" class="ce-err">{{ job.error }}</div>

                    <div class="bk-row" v-if="!job || !job.active">
                        <button class="bk-btn pri" :disabled="busy" @click="buildDna(st.exists && !st.stale)">{{ st.exists ? (st.stale ? 'حدّث الملف' : 'ابنيه من جديد') : 'ابني الملف' }}</button>
                        <button v-if="st.exists" class="bk-btn" @click="openDna()">راجع وعدّل</button>
                        <a class="bk-btn ghost" :href="brandKitUrl">هويتك الكاملة</a>
                    </div>
                </section>

                <section class="bk-card">
                    <div class="ce-h"><h2>📅 حملاتك</h2>
                        <button class="bk-btn pri sm" :disabled="!st.exists" @click="newCampaign()">+ حملة جديدة</button></div>
                    <p class="bk-mute" v-if="!st.exists">ابني ملف الهوية الأول — منه بنجيب الخدمات والعملاء.</p>
                    <p class="bk-mute" v-else-if="!campaigns.length">مفيش حملات لسه.</p>
                    <div v-for="c in campaigns" :key="c.id" class="ce-camp" @click="openCampaign(c)">
                        <div><b>{{ c.title || 'حملة' }}</b><br><small class="bk-mute">{{ statusLabel[c.status] || c.status }} · {{ timeAgo(c.updated_at || c.created_at) }}</small></div>
                        <span v-if="c.estimate" class="ce-cr">{{ c.estimate.pieces }} قطعة · ~{{ c.est_credits }} كريدت</span>
                    </div>
                </section>
            </div>

            <!-- ═══ مراجعة الـ DNA ═══ -->
            <div v-if="view === 'dna' && d">
                <div class="ce-top"><button class="bk-link" @click="view = 'home'">→ رجوع</button>
                    <span class="bk-mute">~{{ tokensNow }} توكن من {{ opts.max_tokens }}</span></div>

                <section class="bk-card">
                    <h2>🎯 التموضع</h2>
                    <p class="bk-mute">جملة واحدة: أنت لمين وبتحل إيه وبتختلف في إيه.</p>
                    <textarea class="bk-in" rows="2" maxlength="260" v-model="d.positioning"></textarea>
                    <h3>نقط التميّز</h3>
                    <div class="bk-chips"><span v-for="(x, i) in d.usp" class="bk-chip on">{{ x }} <button class="ce-x" @click="d.usp.splice(i, 1)" aria-label="شيل">×</button></span>
                        <input class="ce-add" placeholder="+ إضافة" @keyup.enter="addChip(d.usp, $event, 4)"></div>
                    <h3>الإثباتات (أرقام · شهادات · آراء)</h3>
                    <div class="bk-chips"><span v-for="(x, i) in d.proof_assets" class="bk-chip on">{{ x }} <button class="ce-x" @click="d.proof_assets.splice(i, 1)" aria-label="شيل">×</button></span>
                        <input class="ce-add" placeholder="+ إضافة" @keyup.enter="addChip(d.proof_assets, $event, 5)"></div>
                </section>

                <section class="bk-card">
                    <h2>🧩 الخدمات</h2>
                    <div v-for="s in d.services" :key="s.id" class="ce-item">
                        <b>{{ s.name }}</b>
                        <div class="ce-sel">
                            <select class="bk-in" v-model="s.priority" aria-label="الأولوية"><option v-for="(l, k) in opts.priorities" :value="k">{{ l }}</option></select>
                            <select class="bk-in" v-model="s.season" aria-label="الموسم"><option v-for="x in seasonsFor(s.season)" :value="x">{{ x }}</option></select>
                        </div>
                        <small class="bk-mute">اعتراضات العميل:</small>
                        <div class="bk-chips"><span v-for="(x, i) in s.objections" class="bk-chip">{{ x }} <button class="ce-x" @click="s.objections.splice(i, 1)" aria-label="شيل">×</button></span>
                            <input class="ce-add" placeholder="+ اعتراض" @keyup.enter="addChip(s.objections, $event, 3)"></div>
                    </div>
                    <p class="bk-mute" v-if="!d.services.length">مفيش خدمات — ضيفها من <a :href="brandKitUrl">هويتك</a>.</p>
                </section>

                <section class="bk-card">
                    <h2>👥 العملاء</h2>
                    <div v-for="p in d.personas" :key="p.id" class="ce-item">
                        <div class="ce-sel"><b>{{ p.name }}</b>
                            <select class="bk-in" v-model="p.platform" aria-label="المنصة"><option v-for="(l, k) in opts.platforms" :value="k">{{ l }}</option></select></div>
                        <div class="bk-tpl" v-for="f in personaFields" :key="f.k">
                            <small class="bk-mute">{{ f.l }}</small>
                            <div class="bk-chips"><span v-for="(x, i) in p[f.k]" class="bk-chip">{{ x }} <button class="ce-x" @click="p[f.k].splice(i, 1)" aria-label="شيل">×</button></span>
                                <input class="ce-add" placeholder="+ إضافة" @keyup.enter="addChip(p[f.k], $event, 3)"></div>
                        </div>
                    </div>
                </section>

                <section class="bk-card">
                    <h2>🗣 طريقة الكلام</h2>
                    <div class="ce-sel">
                        <select class="bk-in" v-model="d.tone.dialect" aria-label="اللهجة"><option v-for="(l, k) in opts.dialects" :value="k">{{ l }}</option></select>
                        <select class="bk-in" v-model="d.tone.formality" aria-label="الرسمية"><option v-for="(l, k) in opts.formality" :value="k">{{ l }}</option></select>
                    </div>
                    <div class="bk-chips"><button class="bk-chip" :class="{ on: d.tone.emoji }" @click="d.tone.emoji = !d.tone.emoji">😊 إيموجي {{ d.tone.emoji ? 'أيوه' : 'لأ' }}</button></div>
                    <h3>كلمات بنحبها</h3>
                    <div class="bk-chips"><span v-for="(x, i) in d.tone.do_words" class="bk-chip on">{{ x }} <button class="ce-x" @click="d.tone.do_words.splice(i, 1)" aria-label="شيل">×</button></span>
                        <input class="ce-add" placeholder="+ كلمة" @keyup.enter="addChip(d.tone.do_words, $event, 8)"></div>
                    <h3>كلمات ممنوعة</h3>
                    <div class="bk-chips"><span v-for="(x, i) in d.tone.dont_words" class="bk-chip no">{{ x }} <button class="ce-x" @click="d.tone.dont_words.splice(i, 1)" aria-label="شيل">×</button></span>
                        <input class="ce-add" placeholder="+ كلمة" @keyup.enter="addChip(d.tone.dont_words, $event, 10)"></div>
                </section>

                <section class="bk-card">
                    <h2>🎨 الشكل</h2>
                    <div class="ce-colors"><label v-for="(c, i) in d.visual.colors_hex" class="ce-sw" :style="{ background: c }">
                        <input type="color" :value="c" @input="d.visual.colors_hex[i] = $event.target.value.toUpperCase()" :aria-label="'لون ' + (i + 1)"></label></div>
                    <h3>الخطوط</h3>
                    <div class="bk-chips"><span v-for="(x, i) in d.visual.fonts" class="bk-chip">{{ x }} <button class="ce-x" @click="d.visual.fonts.splice(i, 1)" aria-label="شيل">×</button></span>
                        <input class="ce-add" placeholder="+ خط" @keyup.enter="addChip(d.visual.fonts, $event, 3)"></div>
                    <h3>ستايل الصور</h3>
                    <input class="bk-in" maxlength="160" v-model="d.visual.photo_style">
                    <h3>الشخصيات</h3>
                    <div class="ce-chars"><span v-for="c in d.visual.characters" class="bk-chip">{{ c.name }} · {{ c.ref_image_ids.length }} صورة</span>
                        <span class="bk-mute" v-if="!d.visual.characters.length">مفيش — ضيفها من هويتك</span></div>
                </section>

                <section class="bk-card">
                    <h2>🛡 القيود والأنماط</h2>
                    <h3>لازم المحتوى يلتزم بـ</h3>
                    <div class="bk-chips"><span v-for="(x, i) in d.compliance" class="bk-chip no">{{ x }} <button class="ce-x" @click="d.compliance.splice(i, 1)" aria-label="شيل">×</button></span>
                        <input class="ce-add" placeholder="+ قيد" @keyup.enter="addChip(d.compliance, $event, 6)"></div>
                    <h3>أنماط بتنجح معاك</h3>
                    <div class="bk-chips"><span v-for="(x, i) in d.winning_patterns" class="bk-chip on">{{ x }} <button class="ce-x" @click="d.winning_patterns.splice(i, 1)" aria-label="شيل">×</button></span>
                        <input class="ce-add" placeholder="+ نمط" @keyup.enter="addChip(d.winning_patterns, $event, 5)"></div>
                </section>

                <div class="ce-actions">
                    <button class="bk-btn pri" :disabled="busy" @click="saveDna()">احفظ التعديلات</button>
                    <button v-if="st.can_undo" class="bk-btn ghost" :disabled="busy" @click="undoDna()">ارجع للنسخة اللي قبلها</button>
                </div>
            </div>

            <!-- ═══ حملة جديدة (Wizard) ═══ -->
            <div v-if="view === 'wizard' && w.opts">
                <div class="ce-top"><button class="bk-link" @click="view = 'home'">→ رجوع</button>
                    <span class="bk-mute">خطوة {{ stepNo() }} من {{ steps().length }}</span></div>
                <div class="ce-steps"><i v-for="s in steps()" :class="{ on: s === w.step, done: steps().indexOf(s) < steps().indexOf(w.step) }"></i></div>

                <section class="bk-card" v-if="w.step === 'goal'">
                    <h2>هدف الحملة إيه؟</h2>
                    <div class="ce-grid"><button v-for="g in w.opts.goals" class="ce-opt" :class="{ on: w.b.goal === g.key }" @click="w.b.goal = g.key"><span>{{ g.icon }}</span>{{ g.label }}</button></div>
                    <input v-if="w.b.goal === 'other'" class="bk-in" maxlength="120" v-model="w.b.goal_other" placeholder="اكتب الهدف">
                </section>

                <section class="bk-card" v-if="w.step === 'services'">
                    <h2>الحملة عن أنهي خدمة؟</h2>
                    <p class="bk-mute">خدمة أو اتنين — أو كل الخدمات.</p>
                    <div class="bk-chips">
                        <button class="bk-chip" :class="{ on: w.b.all_services }" @click="w.b.all_services = !w.b.all_services; w.b.service_ids = []">كل الخدمات</button>
                        <button v-for="s in w.opts.services" class="bk-chip" :class="{ on: w.b.service_ids.includes(s.id) }" :disabled="w.b.all_services" @click="toggle(w.b.service_ids, s.id, 2)">{{ s.name }}<small v-if="s.priority === 'high'"> ⭐</small></button>
                    </div>
                </section>

                <section class="bk-card" v-if="w.step === 'personas'">
                    <h2>بتكلّم مين؟</h2>
                    <div class="ce-grid"><button v-for="p in w.opts.personas" class="ce-opt ce-per" :class="{ on: w.b.persona_ids.includes(p.id) }" @click="toggle(w.b.persona_ids, p.id, 10)">
                        <b>{{ p.name }}</b><small v-if="p.pain.length">بيعاني من: {{ p.pain.join('، ') }}</small></button></div>
                </section>

                <section class="bk-card" v-if="w.step === 'character'">
                    <h2>مين يظهر في المحتوى؟</h2>
                    <div class="ce-grid">
                        <button v-for="c in w.opts.characters" class="ce-opt" :class="{ on: w.b.character_id === c.id }" @click="w.b.character_id = c.id">
                            <img v-if="c.images.length" :src="c.images[0].url" alt="" class="ce-av">{{ c.name }}<small>{{ c.label }}</small></button>
                        <button class="ce-opt" :class="{ on: !w.b.character_id }" @click="w.b.character_id = 0"><span>🚫</span>من غير شخصيات</button>
                    </div>
                </section>

                <section class="bk-card" v-if="w.step === 'formats'">
                    <h2>الأشكال والعدد</h2>
                    <div v-for="(l, k) in w.opts.formats" class="ce-slide">
                        <label :for="'f-' + k">{{ l }}</label>
                        <input :id="'f-' + k" type="range" min="0" max="30" v-model.number="w.b.formats[k]"><b>{{ w.b.formats[k] }}</b>
                    </div>
                    <p class="bk-mute">الإجمالي: {{ pieces() }} قطعة (أقصى عدد {{ w.opts.max_pieces }})</p>
                    <h3>مدة الحملة</h3>
                    <div class="bk-chips"><button v-for="dd in w.opts.days" class="bk-chip" :class="{ on: w.b.days === dd }" @click="w.b.days = dd">{{ dd }} يوم</button></div>
                </section>

                <section class="bk-card" v-if="w.step === 'offer'">
                    <h2>تفاصيل العرض</h2>
                    <div class="bk-chips"><button v-for="(l, k) in w.opts.offer_types" class="bk-chip" :class="{ on: w.b.offer.type === k }" @click="w.b.offer.type = k">{{ l }}</button></div>
                    <div v-if="w.b.offer.type === 'percent' || w.b.offer.type === 'amount'" class="ce-slide">
                        <label for="ce-ov">{{ w.b.offer.type === 'percent' ? 'النسبة %' : 'المبلغ (جنيه)' }}</label>
                        <input id="ce-ov" class="bk-in ce-num" type="number" min="1" :max="w.b.offer.type === 'percent' ? 90 : 1000000" v-model.number="w.b.offer.value"></div>
                    <input v-if="w.b.offer.type === 'gift' || w.b.offer.type === 'bundle'" class="bk-in" maxlength="120" v-model="w.b.offer.what" placeholder="الهدية أو الباقة إيه؟">
                    <h3>مدة العرض</h3>
                    <div class="bk-chips"><button v-for="dd in w.opts.days" class="bk-chip" :class="{ on: w.b.offer.days === dd }" @click="w.b.offer.days = dd">{{ dd }} يوم</button></div>
                    <h3>الشرط</h3>
                    <div class="bk-chips"><button v-for="(l, k) in w.opts.offer_conditions" class="bk-chip" :class="{ on: w.b.offer.condition === k }" @click="w.b.offer.condition = k">{{ l }}</button></div>
                    <input v-if="w.b.offer.condition === 'other'" class="bk-in" maxlength="120" v-model="w.b.offer.condition_other" placeholder="اكتب الشرط">
                </section>

                <section class="bk-card" v-if="w.step === 'channel'">
                    <h2>العميل يتواصل معاك إزاي؟</h2>
                    <div class="ce-grid"><button v-for="c in w.opts.channels" class="ce-opt" :class="{ on: w.b.channel === c.key }" @click="w.b.channel = c.key"><span>{{ c.icon }}</span>{{ c.label }}</button></div>
                </section>

                <section class="bk-card" v-if="w.step === 'boldness'">
                    <h2>مستوى الجرأة</h2>
                    <div class="ce-grid"><button v-for="b in w.opts.boldness" class="ce-opt" :class="{ on: w.b.boldness === b.key }" @click="w.b.boldness = b.key"><b>{{ b.label }}</b><small>{{ b.hint }}</small></button></div>
                </section>

                <section class="bk-card" v-if="w.step === 'exclusions'">
                    <h2>حاجات مش عايزها في الحملة دي</h2>
                    <div class="bk-chips">
                        <button v-for="x in w.opts.exclusions" class="bk-chip" :class="{ no: w.b.exclusions.includes(x) }" @click="toggle(w.b.exclusions, x, 12)">{{ x }}</button>
                        <span v-for="x in customExclusions()" class="bk-chip no">{{ x }} <button class="ce-x" @click="w.b.exclusions.splice(w.b.exclusions.indexOf(x), 1)" aria-label="شيل">×</button></span>
                        <input class="ce-add" placeholder="+ حاجة تانية" @keyup.enter="addChip(w.b.exclusions, $event, 12)">
                    </div>
                </section>

                <section class="bk-card ce-sum" v-if="w.step === 'summary'">
                    <h2>الملخص والتكلفة</h2>
                    <ul class="ce-list">
                        <li><b>الهدف:</b> {{ goalLabel() }}</li>
                        <li><b>الخدمات:</b> {{ servicesLabel() }}</li>
                        <li><b>العملاء:</b> {{ personasLabel() }}</li>
                        <li><b>الأشكال:</b> {{ formatsLabel() }} · {{ w.b.days }} يوم</li>
                        <li v-if="w.b.goal === 'offer'"><b>العرض:</b> {{ offerLabel() }}</li>
                        <li><b>التواصل:</b> {{ labelOf(w.opts.channels, w.b.channel) }} · <b>الجرأة:</b> {{ labelOf(w.opts.boldness, w.b.boldness) }}</li>
                        <li v-if="w.b.exclusions.length"><b>من غير:</b> {{ w.b.exclusions.join('، ') }}</li>
                    </ul>
                    <div v-if="w.estBusy" class="bk-mute" role="status">بنحسب التكلفة…</div>
                    <div v-if="w.est" class="ce-est">
                        <div class="ce-est-main"><div><b>{{ w.est.credits }}</b><span>كريدت تقريبًا</span></div>
                            <div><b>{{ fmt(w.est.tokens_total) }}</b><span>توكن</span></div>
                            <div><b>{{ w.est.pieces }}</b><span>قطعة · {{ w.est.images }} صورة</span></div></div>
                        <details><summary>تفاصيل كل مرحلة</summary>
                            <div v-for="s in w.est.stages" class="ce-est-row"><span>{{ s.label }}</span><span>{{ fmt(s.tokens) }} توكن</span></div></details>
                        <p class="bk-mute">رصيدك {{ balance }} كريدت. الكريدت بيتحجز لما الحملة تبدأ، واللي مايتصرفش بيرجعلك تلقائي.</p>
                        <p v-if="balance < w.est.credits" class="ce-err">رصيدك مش كفاية للحملة دي — قلّل العدد أو اشحن.</p>
                    </div>
                    <div v-if="w.saved" class="ce-ok">✓ اتحفظ البريف. دوس «ابدأ الحملة» علشان نبني الخطة ونراجعها.</div>
                </section>

                <div v-if="w.err" class="ce-err" role="alert">{{ w.err }}</div>
                <div class="ce-actions">
                    <button v-if="stepNo() > 1" class="bk-btn ghost" @click="prev()">السابق</button>
                    <button v-if="w.step !== 'summary'" class="bk-btn pri" @click="next()">التالي</button>
                    <button v-else-if="!w.saved" class="bk-btn pri" :disabled="busy || !w.est" @click="saveBrief()">{{ w.id ? 'احفظ التعديل' : 'احفظ البريف' }}</button>
                    <button v-if="w.step === 'summary' && w.saved" class="bk-btn pri" :disabled="busy" @click="startCampaign(w.id)">ابدأ الحملة ✨</button>
                </div>
                <p v-if="w.step === 'summary' && w.saved" class="bk-mute">هنحجز كريدت الخطة بس دلوقتي (واللي مايتصرفش بيرجع). كريدت الكتابة بيتحجز لما تعتمد الخطة.</p>
            </div>


            <!-- ═══ خطة الحملة (المرحلة E) ═══ -->
            <div v-if="view === 'plan' && p">
                <div class="ce-top"><button class="bk-link" @click="backHome()">→ رجوع</button>
                    <span class="bk-mute">{{ statusLabel[p.campaign.status] || p.campaign.status }}</span></div>
                <section class="bk-card ce-hero"><h1>{{ p.campaign.title }}</h1>
                    <p class="bk-mute" v-if="p.slots.length">{{ p.slots.length }} خانة · من {{ p.slots[0].date }} لـ {{ p.slots[p.slots.length - 1].date }}</p></section>

                <section class="bk-card" v-if="p.job && p.job.active">
                    <h2>بنبني الخطة…</h2>
                    <div class="ce-job" role="status"><div class="ce-bar"><i :style="{ width: Math.max(6, p.job.progress) + '%' }"></i></div>
                        <div class="ce-job-row"><span>{{ p.job.label }}</span><button class="bk-link" @click="cancelPlanJob()">إلغاء</button></div>
                        <small class="bk-mute" v-if="!worker">خلّي الصفحة مفتوحة لحد ما تخلص</small></div>
                </section>
                <div v-if="p.job && p.job.status === 'failed' && !p.slots.length" class="ce-err">{{ p.job.error }}</div>

                <section class="bk-card" v-if="p.campaign.status === 'ready' && !(p.job && p.job.active)">
                    <h2>جاهزة للتشغيل</h2>
                    <p class="bk-mute">هنبني خطة المحتوى ونراجعها بالقواعد وبناقد. لو الدرجة أقل من {{ p.threshold }} بنحسّنها لوحدنا.</p>
                    <button class="bk-btn pri" :disabled="busy" @click="startCampaign(p.campaign.id)">ابدأ الحملة ✨</button>
                </section>

                <div class="bk-tpl" v-if="p.slots.length && !(p.job && p.job.active)">
                <section class="bk-card ce-score">
                    <div class="ce-score-top">
                        <div class="ce-ring" :class="{ ok: p.score_now >= p.threshold && !p.hard }"><b>{{ p.score_now }}</b><small>من 100</small></div>
                        <div>
                            <h2>درجة الخطة</h2>
                            <p class="bk-mute" v-if="p.campaign.status === 'plan_ok'">✓ الخطة معتمدة — جاهزين نكتب المحتوى.</p>
                            <p class="bk-mute" v-else-if="['writing', 'done'].indexOf(p.campaign.status) >= 0">✓ الخطة معتمدة · <button class="bk-link" @click="openWrite(p.campaign.id)">افتح المحتوى</button></p>
                            <p class="bk-mute" v-else-if="p.can_approve">الخطة عدّت البوابة ({{ p.threshold }}+) — راجعها واعتمدها.</p>
                            <p class="bk-mute" v-else>لازم {{ p.threshold }} أو أكتر ومفيش قواعد متكسّرة علشان تتعتمد.</p>
                            <small class="bk-mute" v-if="p.eval">آخر تقييم: جولة {{ p.eval.round }} · {{ timeAgo(p.eval.at) }}<span v-if="dirty"> · عندك تعديلات مش محفوظة</span></small>
                        </div>
                    </div>
                    <div class="ce-dims"><div v-for="(l, d) in p.labels.dims" class="ce-dim">
                        <span>{{ l }}</span><div class="ce-bar"><i :class="{ low: (p.dims_now || {})[d] < 80 }" :style="{ width: ((p.dims_now || {})[d] || 0) + '%' }"></i></div><b>{{ Math.round((p.dims_now || {})[d] || 0) }}</b></div></div>
                    <div v-if="p.issues.length" class="ce-issues"><div v-for="x in p.issues" :class="{ hard: x.hard }">{{ x.hard ? '⛔' : '⚠️' }} {{ x.msg }}</div></div>
                    <details v-if="p.eval && p.eval.critic"><summary>ملاحظات الناقد</summary>
                        <p class="bk-mute">{{ p.eval.critic.summary }}</p>
                        <div v-for="f in p.eval.critic.fixes" class="ce-est-row"><span>الخانة {{ f.slot }}: {{ f.issue }}</span><span>{{ f.fix }}</span></div></details>
                </section>

                <section class="bk-card">
                    <div class="ce-h"><h2>الخطة</h2><small class="bk-mute">اسحب للترتيب · 🔒 = ماتتغيرش لما نعيد البناء</small></div>
                    <div class="ce-mix bk-mute">المطلوب: <span v-for="(v, k) in p.mix">{{ p.labels.funnels[k] }} {{ v }}% · </span></div>
                    <div v-for="(s, i) in p.slots" :key="s.id" class="ce-slot" :class="['f-' + s.funnel, { locked: s.locked, tail: i >= p.tail_from, over: dragOver === i }]"
                         :draggable="editable()" @dragstart="dragFrom = i" @dragover.prevent="dragOver = i" @dragleave="dragOver = -1" @drop.prevent="dropAt(i)">
                        <div class="ce-slot-h">
                            <span class="ce-grip" aria-hidden="true">⋮⋮</span><b>{{ i + 1 }}</b><small>{{ s.date }}</small>
                            <span class="ce-badge">{{ p.labels.funnels[s.funnel] }}</span><span class="ce-badge" v-if="i === p.tail_from">آخر 30%</span>
                            <span class="ce-sp"></span>
                            <button class="ce-ic" v-if="editable()" :disabled="i === 0" @click="move(i, -1)" aria-label="لفوق">↑</button>
                            <button class="ce-ic" v-if="editable()" :disabled="i === p.slots.length - 1" @click="move(i, 1)" aria-label="لتحت">↓</button>
                            <button class="ce-ic" v-if="editable()" :class="{ on: s.locked }" @click="s.locked = !s.locked; dirty = true" :aria-label="s.locked ? 'فك القفل' : 'اقفل'">{{ s.locked ? '🔒' : '🔓' }}</button>
                        </div>
                        <div class="ce-slot-g">
                            <select class="bk-in" v-model="s.format" :disabled="!editable()" @change="dirty = true" aria-label="الشكل"><option v-for="(l, k) in p.labels.formats" :value="k">{{ l }}</option></select>
                            <select class="bk-in" v-model="s.pillar" :disabled="!editable()" @change="dirty = true" aria-label="العمود"><option v-for="(l, k) in p.labels.pillars" :value="k">{{ l }}</option></select>
                            <select class="bk-in" v-model="s.angle" :disabled="!editable()" @change="dirty = true" aria-label="الزاوية"><option v-for="(l, k) in p.labels.angles" :value="k">{{ l }}</option></select>
                            <select class="bk-in" v-model.number="s.service_id" :disabled="!editable()" @change="dirty = true" aria-label="الخدمة"><option v-for="(l, k) in p.labels.services" :value="Number(k)">{{ l }}</option></select>
                            <select class="bk-in" v-model.number="s.persona_id" :disabled="!editable()" @change="dirty = true" aria-label="العميل"><option v-for="(l, k) in p.labels.personas" :value="Number(k)">{{ l }}</option></select>
                            <select class="bk-in" v-model="s.cta_level" :disabled="!editable()" @change="dirty = true" aria-label="الـ CTA"><option v-for="(l, k) in p.labels.cta" :value="k">CTA {{ l }}</option></select>
                        </div>
                        <p class="ce-obj">{{ s.objective }}</p>
                    </div>
                </section>

                <section class="bk-card ce-go" v-if="p.campaign.status === 'plan_ok'">
                    <h2>✍️ اكتب المحتوى</h2>
                    <p class="bk-mute">لكل خانة: 3 أفكار من زوايا مختلفة ← بنختار أقواها ← بنكتب (بوست · كاروسيل · ريلز) + وصف التصميم ← مراجع جودة من موديل تاني بيقيّم 10 معايير، واللي أقل من {{ p.write_rules ? p.write_rules.pass_total : 85 }} بنحسّنه لوحدنا.</p>
                    <div class="ce-est-row"><span>محجوز من رصيدك</span><b>~{{ fmt(p.write_credits) }} كريدت</b></div>
                    <small class="bk-mute">بيتحسب الفعلي بس في الآخر، والباقي بيرجع لرصيدك · رصيدك {{ fmt(balance) }}</small>
                    <div class="bk-row"><button class="bk-btn pri" :disabled="busy" @click="startWrite()">ابدأ الكتابة ✨</button></div>
                </section>

                <div class="ce-actions" v-if="editable()">
                    <button v-if="dirty" class="bk-btn pri" :disabled="busy" @click="savePlan()">احفظ التعديلات</button>
                    <button v-if="!dirty" class="bk-btn pri" :disabled="busy || !p.can_approve" @click="approvePlan()">اعتمد الخطة ✓</button>
                    <button v-if="!dirty" class="bk-btn" :disabled="busy" @click="planJob('plan_rescore')">قيّم تاني</button>
                    <button v-if="!dirty" class="bk-btn ghost" :disabled="busy" @click="planJob('plan_rebuild')">ابني من جديد (المقفول يفضل)</button>
                </div>
                </div>
            </div>

            <!-- ═══ المحتوى والجودة (المرحلة F) ═══ -->
            <div v-if="view === 'write' && wr">
                <div class="ce-top"><button class="bk-link" @click="backHome()">→ رجوع</button>
                    <button class="bk-link" @click="openPlan(wc.id)">الخطة</button></div>
                <section class="bk-card ce-hero"><h1>{{ wc.title }}</h1>
                    <p class="bk-mute">{{ wr.slots.length }} قطعة · {{ statusLabel[wc.status] || wc.status }}</p></section>

                <section class="bk-card" v-if="writeBusy()">
                    <h2>بنكتب…</h2>
                    <div class="ce-job" role="status"><div class="ce-bar"><i :style="{ width: Math.max(6, wr.job.progress) + '%' }"></i></div>
                        <div class="ce-job-row"><span>{{ wr.job.label }}</span><button class="bk-link" @click="cancelWriteJob()">إلغاء</button></div>
                        <small class="bk-mute" v-if="!worker">خلّي الصفحة مفتوحة لحد ما تخلص</small></div>
                </section>
                <div v-if="wr.job && wr.job.status === 'failed'" class="ce-err">{{ wr.job.error }}</div>

                <section class="bk-card ce-score" v-if="wr.final">
                    <div class="ce-score-top">
                        <div class="ce-ring" :class="{ ok: wr.final.score >= 80 }"><b>{{ Math.round(wr.final.score) }}</b><small>من 100</small></div>
                        <div><h2>تقرير الحملة</h2>
                            <p class="bk-mute">{{ wr.final.pass }} عدّت الجودة<span v-if="wr.final.manual"> · {{ wr.final.manual }} محتاجة مراجعتك</span><span v-if="wr.final.failed"> · {{ wr.final.failed }} ماتكتبتش</span><span v-if="wr.final.avg_qa"> · متوسط الجودة {{ wr.final.avg_qa }}</span></p>
                            <small class="bk-mute">{{ wr.final.summary }}</small></div>
                    </div>
                    <div class="ce-dims"><div v-for="(v, d) in wr.final.dims" class="ce-dim">
                        <span>{{ (p && p.labels && p.labels.dims[d]) || dimLabel[d] || d }}</span><div class="ce-bar"><i :class="{ low: v < 80 }" :style="{ width: v + '%' }"></i></div><b>{{ Math.round(v) }}</b></div></div>
                    <div v-if="wr.final.notes && wr.final.notes.length" class="ce-notes"><b>أهم الملاحظات</b><ol><li v-for="n in wr.final.notes">{{ n }}</li></ol></div>
                </section>

                <section v-for="(s, i) in wr.slots" :key="s.id" class="bk-card ce-piece" :class="cTone(s)">
                    <div class="ce-slot-h">
                        <b>{{ i + 1 }}</b><small>{{ s.date }}</small><span class="ce-badge">{{ fmtLabel[s.format] || s.format }}</span>
                        <span class="ce-sp"></span>
                        <span class="bk-pill" :class="cTone(s)">{{ s.content ? cStatus[s.content.status] : 'لسه' }}<span v-if="s.content && s.content.qa_score !== null"> · {{ s.content.qa_score }}</span></span>
                    </div>
                    <p class="ce-obj">{{ s.objective }}</p>

                    <div class="bk-tpl" v-if="s.content && s.content.body">
                        <div class="ce-body" v-if="s.content.body.hooks">
                            <div class="ce-hooks"><span v-for="(h, k) in s.content.body.hooks" :class="{ on: k === 0 }">{{ h }}</span></div>
                            <p class="ce-text">{{ s.content.body.body }}</p>
                        </div>
                        <ol class="ce-slides" v-if="s.content.body.slides"><li v-for="x in s.content.body.slides">{{ x.text }}</li></ol>
                        <div class="ce-scenes" v-if="s.content.body.scenes"><div v-for="x in s.content.body.scenes" class="ce-scene">
                            <b>{{ x.time }}</b><span>🎬 {{ x.visual }}</span><span v-if="x.on_screen_text">🅣 {{ x.on_screen_text }}</span><span v-if="x.voiceover">🎙 {{ x.voiceover }}</span></div></div>
                        <p class="ce-text" v-if="s.content.body.caption">{{ s.content.body.caption }}</p>
                        <p class="ce-cta" v-if="s.content.body.cta">👉 {{ s.content.body.cta }}</p>
                        <details v-if="s.content.body.design_brief"><summary>🎨 وصف التصميم</summary>
                            <div class="ce-brief"><span v-for="(v, k) in s.content.body.design_brief" v-show="v && (!Array.isArray(v) || v.length)"><b>{{ briefLabel[k] || k }}</b> {{ Array.isArray(v) ? v.join('، ') : v }}</span></div></details>
                    </div>

                    <details v-if="s.content && s.content.qa && s.content.qa.criteria"><summary>📊 الجودة: {{ s.content.qa.total }} <small class="bk-mute">· {{ s.content.rounds ? ('بعد ' + s.content.rounds + ' تحسين') : 'من أول مرة' }}</small></summary>
                        <div class="ce-dims"><div v-for="(c, k) in s.content.qa.criteria" class="ce-dim">
                            <span>{{ wr.qa_labels[k] }} <small class="bk-mute">×{{ wr.qa_weights[k] }}</small></span><div class="ce-bar"><i :class="{ low: c.score < wr.rules.min_criterion }" :style="{ width: c.score + '%' }"></i></div><b>{{ Math.round(c.score) }}</b></div></div>
                        <div v-if="s.content.qa.compliance_flags && s.content.qa.compliance_flags.length" class="ce-issues"><div v-for="f in s.content.qa.compliance_flags" class="hard">⛔ {{ f }}</div></div>
                        <div v-for="(c, k) in s.content.qa.criteria" v-show="c.fix" class="ce-est-row"><span>{{ wr.qa_labels[k] }}</span><span>{{ c.fix }}</span></div>
                    </details>

                    <details v-if="s.ideas.length" class="ce-ideas"><summary>💡 الأفكار ({{ s.ideas.length }})</summary>
                        <div v-for="idea in s.ideas" class="ce-idea" :class="{ on: idea.selected, dup: idea.dup }">
                            <div><b>{{ idea.hook }}</b> <span class="ce-badge" v-if="idea.trigger">{{ wr.triggers[idea.trigger] }}</span>
                                <span class="ce-badge" v-if="idea.score !== null">{{ idea.score }}</span><span class="ce-badge" v-if="idea.dup">شبه فكرة قديمة</span></div>
                            <small class="bk-mute">{{ idea.insight }}</small>
                            <button v-if="!idea.dup && !writeBusy()" class="bk-link" :disabled="busy" @click="swapIdea(s, idea)">{{ idea.selected ? 'اكتبها من جديد' : 'اكتب بالفكرة دي' }}</button>
                        </div></details>

                    <div class="bk-row" v-if="s.content && s.content.body && !writeBusy()">
                        <button class="bk-btn sm" @click="copyContent(s)">انسخ</button>
                        <button v-if="s.content.status === 'manual'" class="bk-btn sm pri" @click="acceptContent(s)">اعتمدها زي ما هي</button>
                    </div>
                </section>
            </div>

            <div v-if="toast" class="bk-toast" :class="toast && toast.type" role="status">{{ toast && toast.msg }}</div>
            </div>
        </div>
    </main>
</div>
<script src="<?= $__ver('assets/js/engine.js') ?>" defer></script>
<?php include __DIR__ . '/../templates/footer.php'; ?>
