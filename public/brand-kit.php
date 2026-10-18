<?php
/**
 * Spread AI — مركز الهوية (Brand Kit)
 * صفحة واحدة على الموبايل: الاكتمال · الشكل · المحتوى · تحديث سريع · «اعمل محتوى بالهوية دي»
 * كل قسم: اختيار سريع فوق ← تعديل بكلمة ← «إعدادات متقدمة» مقفولة
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/brand-brain.php';
require_once __DIR__ . '/../includes/brand-kit.php';
if (is_file(__DIR__ . '/../includes/ui-v2.php')) require_once __DIR__ . '/../includes/ui-v2.php';

require_login();
$user = current_user();
if (!bk_enabled()) redirect(function_exists('ui_v2_enabled') && ui_v2_enabled() ? 'brand-brain.php' : 'brand-profile.php');
if (!brand_for_user((int) $user['id'])) db_insert('INSERT INTO brand_profiles (user_id) VALUES (?)', [$user['id']]);
bk_get((int) $user['id']);

$__v2 = function_exists('ui_v2_enabled') && ui_v2_enabled();
$active = 'brand-kit';
$page_title = 'هوية البراند';
$use_app = true;
$body_class = 'bk-page';
include __DIR__ . '/../templates/header.php';
$__ver = fn(string $f) => url($f) . '?v=' . (@filemtime(__DIR__ . '/../' . $f) ?: time());
?>
<link rel="stylesheet" href="<?= $__ver('assets/css/brand-kit.css') ?>">
<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php if ($__v2) include __DIR__ . '/../templates/topbar.php'; ?>
        <?= render_flash() ?>

        <div id="bk-app" class="bk" v-cloak
             data-base="<?= e(rtrim(url(''), '/')) ?>"
             data-create="<?= e(url($__v2 ? 'campaigns.php' : 'create-content.php')) ?>"
             data-sources="<?= e(url('sources.php')) ?>"
             data-brain="<?= e(url($__v2 ? 'brand-brain.php' : 'brand-profile.php')) ?>">

            <div v-if="loading" class="bk-loading" role="status">بنجهّز هويتك…</div>

            <div v-if="!loading && s">
            <!-- ═══ «ابدأ هنا»: لينك أو ملف ← الـ AI يسحب المتاح ويبني الهوية ═══ -->
            <section class="bk-card bk-magic" id="bk-magic">
                <h2>✨ خلّي الـ AI يبني هويتك</h2>
                <p class="bk-mute">حط لينك صفحتك أو موقعك، أو ارفع ملف (بروفايل · منيو · بروشور · صورة لتصميم أو لوجو) — والـ AI يسحب اللي متاح ويملا هويتك ويكمّل الناقص.</p>
                <div class="bk-magic-in">
                    <input class="bk-in" dir="ltr" type="url" v-model="mg.url" placeholder="facebook.com/yourpage أو yoursite.com" @keyup.enter="magic()" :disabled="busy">
                    <label class="bk-btn" :class="{on: mg.file}"><input type="file" hidden accept=".pdf,.docx,.txt,image/png,image/jpeg,image/webp" @change="mg.file = $event.target.files[0] || null">📎 {{ mg.file ? mg.file.name : 'ارفع ملف' }}</label>
                </div>
                <input v-if="!s.basics.business_name && !mg.url && !mg.file" class="bk-in" v-model="mg.name" placeholder="مفيش لينك؟ اكتب اسم نشاطك ومجاله (مثلًا: عيادة سمايل للأسنان)">
                <div class="bk-row">
                    <button class="bk-btn pri" :disabled="busy" @click="magic()">{{ mg.url || mg.file ? 'اسحب البيانات وابني هويتي' : 'كمّل الناقص بالـ AI' }} <span class="cr" v-if="magicCost">· {{ magicCost }} كريدت</span></button>
                    <button class="bk-btn ghost sm" @click="onb.open = true; onb.skip = false">أو جاوب 5 أسئلة سريعة</button>
                </div>
                <div v-if="mg.running" class="bk-magic-run" role="status">{{ mg.running }}</div>
                <div v-if="mg.res" class="bk-magic-res">
                    <p v-if="mg.res.read">✓ {{ mg.res.read }}</p>
                    <p v-if="mg.res.filled && mg.res.filled.length">📥 اتملا من المتاح: <b>{{ mg.res.filled.join('، ') }}</b></p>
                    <p v-if="mg.res.built && mg.res.built.length">✨ الـ AI بنى: <b>{{ mg.res.built.join('، ') }}</b> — الأقسام اللي عليها «راجع» بصّ عليها بسرعة وأكّدها.</p>
                    <p v-if="mg.res.warn" class="bk-warn-t">⚠ {{ mg.res.warn }}</p>
                    <p v-if="!(mg.res.filled && mg.res.filled.length) && !(mg.res.built && mg.res.built.length) && !mg.res.warn">هويتك متكاملة — مفيش حاجة ناقصة نكمّلها ✓</p>
                    <div v-if="!s.logo_url || !s.kit.colors.primary" class="bk-row">
                        <span class="bk-mute">الخطوة الجاية:</span>
                        <button v-if="!s.logo_url" class="bk-chip ai" @click="open = ''; toggle('logo')">🎨 صمّم لوجو بالـ AI</button>
                        <button v-if="!s.kit.colors.primary" class="bk-chip ai" @click="open = ''; toggle('colors'); palSuggest(true)">✨ اقترح ألوان</button>
                    </div>
                </div>
            </section>

            <!-- ═══ الرأس: الاكتمال + الناقص + زراير سريعة ═══ -->
            <section class="bk-hero">
                <div class="bk-ring" :style="{'--p': s.completeness.pct}" :aria-label="'اكتمال الهوية ' + s.completeness.pct + '%'">
                    <b>{{ s.completeness.pct }}<small>%</small></b><span>اكتمال الهوية</span>
                </div>
                <div class="bk-hero-txt">
                    <h1>هوية البراند</h1>
                    <p v-if="s.completeness.missing.length">أهم حاجة ناقصة: <b>{{ s.completeness.missing[0].label }}</b></p>
                    <p v-else>هويتك كاملة ✓ — كل بوست هيطلع بيها.</p>
                    <div class="bk-hero-act">
                        <button v-if="s.completeness.missing.length" class="bk-btn pri" @click="openMissing()">كمّل دلوقتي</button>
                        <a class="bk-btn" :class="{pri: !s.completeness.missing.length}" :href="createUrl">اعمل محتوى بالهوية دي ←</a>
                    </div>
                </div>
            </section>

            <div class="bk-quick" role="group" aria-label="تحديث سريع">
                <button class="bk-chip" @click="quick('service')">+ خدمة</button>
                <button class="bk-chip" @click="quick('offer')">+ عرض حالي</button>
                <button class="bk-chip" @click="quick('character')">+ شخصية</button>
                <button class="bk-chip ghost" @click="loadVersions()">النسخ السابقة</button>
            </div>

            <!-- ═══ صندوق الاقتراحات (من اللينك · الملفات · البحث · اللوجو · تعديلاتك) ═══ -->
            <section v-if="s.suggestions.length" class="bk-card bk-learn">
                <h2>💡 اقتراحات لهويتك <small class="bk-mute">({{ s.suggestions.length }})</small></h2>
                <p class="bk-mute" style="margin:2px 0 6px">مفيش حاجة بتتغيّر في هويتك غير لما توافق.</p>
                <div v-for="g in s.suggestions.slice(0, sugMore ? 50 : 4)" :key="g.kind + g.id" class="bk-sug">
                    <p><span class="bk-pill">{{ g.source }}</span> {{ g.message }}<span v-if="g.value" class="bk-sug-v">{{ g.value }}</span></p>
                    <div><button class="bk-btn pri sm" :disabled="busy" @click="decide(g, 1)">{{ g.kind === 'fact' ? 'ضيفها' : 'أيوه، غيّرها' }}</button><button class="bk-btn sm" :disabled="busy" @click="decide(g, 0)">لأ</button></div>
                </div>
                <button v-if="s.suggestions.length > 4" class="bk-link" @click="sugMore = !sugMore">{{ sugMore ? 'أقل' : 'كل الاقتراحات (' + s.suggestions.length + ')' }}</button>
            </section>

            <!-- ═══ Onboarding: ابدأ في 3 خطوات ═══ -->
            <section v-if="showOnboard" class="bk-card bk-onb">
                <div class="bk-onb-steps"><span :class="{on: onb.step >= 1}">1 ابدأ</span><span :class="{on: onb.step >= 2}">2 الـ AI يبني</span><span :class="{on: onb.step >= 3}">3 راجع</span></div>

                <div class="bk-tpl" v-if="onb.step === 1">
                    <h2>ابدأ هويتك في أقل من 3 دقايق</h2>
                    <p class="bk-mute">اختار طريقة أو أكتر — وSpread AI يكمّل الباقي.</p>
                    <div class="bk-onb-opt">
                        <h3>🔗 لينك صفحتك أو موقعك</h3>
                        <div class="bk-row"><input class="bk-in" dir="ltr" type="url" v-model="onb.url" placeholder="facebook.com/yourpage أو yoursite.com">
                            <button class="bk-btn" :disabled="busy || !onb.url" @click="analyzeLink()">{{ onb.linkDone ? '✓ اتقرا' : 'اقرأ' }}</button></div>
                    </div>
                    <div class="bk-onb-opt">
                        <h3>⚡ 5 أسئلة سريعة</h3>
                        <div v-for="q in questions" :key="q.key" class="bk-q">
                            <span class="bk-lbl">{{ q.label }}</span>
                            <div class="bk-chips">
                                <button v-for="o in q.options" class="bk-chip" :class="{on: onbHas(q.key, o)}" @click="onbPick(q, o)">{{ o }}</button>
                                <button class="bk-chip ghost" :class="{on: onb.answers[q.key] === 'ai'}" @click="onb.answers[q.key] = 'ai'">سيبها للـ AI</button>
                            </div>
                            <details><summary>تفاصيل أكتر (اختياري)</summary><input class="bk-in" v-model="onb.more[q.key]" :placeholder="q.ph"></details>
                        </div>
                    </div>
                    <div class="bk-onb-opt">
                        <h3>📄 ملفات</h3>
                        <p class="bk-mute">ملف تعريفي، منيو، بروشور… <a :href="sourcesUrl">ارفع ملفاتك</a> <span v-if="s.files">(عندك {{ s.files }} ملف)</span></p>
                    </div>
                    <div class="bk-row end">
                        <button class="bk-btn ghost" @click="onb.skip = true">بعدين</button>
                        <button class="bk-btn pri" :disabled="busy" @click="buildDraft()">ابني الهوية <span class="cr" v-if="s.costs.draft">· {{ s.costs.draft }} كريدت</span></button>
                    </div>
                </div>

                <div class="bk-tpl" v-if="onb.step === 2"><div class="bk-loading">Spread AI بيبني هويتك… ثواني</div></div>

                <div class="bk-tpl" v-if="onb.step === 3 && onb.draft">
                    <h2>ده اللي فهمناه 👇</h2>
                    <p class="bk-mute">علّم على اللي موافق عليه — والحاجة اللي عليها إطار أصفر الـ AI مش متأكد منها، راجعها.</p>
                    <label v-for="sec in draftSections" :key="sec.key" class="bk-draft" :class="{unsure: (onb.conf[sec.key] ?? 1) < 0.7}">
                        <input type="checkbox" v-model="onb.pick[sec.key]">
                        <span><b>{{ sec.label }}</b><span v-if="(onb.conf[sec.key] ?? 1) < 0.7" class="bk-warn">مش متأكدين — راجع</span><br><small>{{ sec.text }}</small></span>
                    </label>
                    <div v-if="onb.facts && onb.facts.length" class="bk-facts">
                        <h3>اللي لقيناه في صفحتك <small class="bk-mute">— علّم على الصح</small></h3>
                        <label v-for="f in onb.facts" :key="f.id" class="bk-draft" :class="{unsure: f.confidence < 75}">
                            <input type="checkbox" v-model="f.on">
                            <span><b>{{ f.label }}</b> <small class="bk-mute">· {{ f.source }}</small><span v-if="f.confidence < 75" class="bk-warn">مش متأكدين</span><br><small>{{ f.value }}</small></span>
                        </label>
                    </div>
                    <div class="bk-row end"><button class="bk-btn ghost" @click="onb.step = 1">رجوع</button><button class="bk-btn pri" :disabled="busy" @click="applyDraft()">احفظ الهوية</button></div>
                </div>
            </section>

            <!-- ═══ الأقسام ═══ -->
            <div class="bk-group">
                <h2 class="bk-gt">المحتوى</h2>
                <article v-for="sec in contentSections" :key="sec.key" class="bk-card bk-sec" :class="{open: open === sec.key, unsure: isUnsure(sec.key)}" :id="'bk-' + sec.key">
                    <button class="bk-sec-h" @click="toggle(sec.key)" :aria-expanded="open === sec.key">
                        <span class="ic">{{ sec.icon }}</span><span class="t"><b>{{ sec.label }}</b><small>{{ summary(sec.key) }}</small></span>
                        <span v-if="isUnsure(sec.key)" class="bk-warn">راجع</span><span class="ar">⌄</span>
                    </button>
                    <div v-if="open === sec.key" class="bk-sec-b">
                        <div v-if="isUnsure(sec.key)" class="bk-unsure">الـ AI مش متأكد من القسم ده. <button class="bk-btn sm" @click="confirmSec(sec.key)">تمام، أكّد</button></div>

                        <!-- الأساسيات والتواصل (نفس بيانات Brand Brain) -->
                        <div class="bk-tpl" v-if="sec.key === 'basics'">
                            <div class="bk-two">
                                <label class="bk-f">اسم النشاط<input class="bk-in" v-model="bs.business_name"></label>
                                <label class="bk-f">المجال<input class="bk-in" v-model="bs.industry" placeholder="مثلًا: عيادة أسنان"></label>
                            </div>
                            <div class="bk-sector">
                                <span>القطاع: <b>{{ s.sector ? s.sector.name : 'من غير قالب' }}</b><small v-if="s.sector && s.sector_auto" class="bk-mute"> — اتعرف تلقائي من مجالك</small></span>
                                <select class="bk-in xs" style="width:auto" :value="s.sector ? s.sector.id : ''" @change="setSector($event.target.value)" aria-label="اختار القطاع">
                                    <option value="">— من غير قالب —</option><option v-for="x in s.sectors" :value="x.id">{{ x.name }}</option></select>
                                <button v-if="s.sector && s.sector_auto" class="bk-link" @click="post('sector_confirm', {}, 'تمام ✓')">صح</button>
                            </div>
                            <label class="bk-f">وصف النشاط في جملتين<textarea class="bk-in" rows="2" v-model="bs.description"></textarea></label>
                            <div class="bk-two">
                                <label class="bk-f">أرقام التواصل<input class="bk-in" dir="ltr" v-model="bs.phones" placeholder="01xxxxxxxxx"></label>
                                <label class="bk-f">واتساب<input class="bk-in" dir="ltr" v-model="bs.whatsapp" placeholder="2010xxxxxxxx"></label>
                                <label class="bk-f">العنوان<input class="bk-in" v-model="bs.address"></label>
                                <label class="bk-f">مواعيد العمل<input class="bk-in" v-model="bs.working_hours" placeholder="يوميًا 12 الضهر لـ 12 بالليل"></label>
                            </div>
                            <details class="bk-adv"><summary>الموقع والسوشيال</summary>
                                <div class="bk-two">
                                    <label class="bk-f">الموقع<input class="bk-in" dir="ltr" v-model="bs.website" placeholder="https://"></label>
                                    <label class="bk-f">فيسبوك<input class="bk-in" dir="ltr" v-model="bs.social_facebook"></label>
                                    <label class="bk-f">انستجرام<input class="bk-in" dir="ltr" v-model="bs.social_instagram"></label>
                                    <label class="bk-f">تيك توك<input class="bk-in" dir="ltr" v-model="bs.social_tiktok"></label>
                                    <label class="bk-f">لينكدإن<input class="bk-in" dir="ltr" v-model="bs.social_linkedin"></label>
                                </div>
                            </details>
                            <p class="bk-mute">بيانات التواصل بتتحط في الـ CTA بس لما تكون مناسبة — والـ AI مابيخترعش أرقام.</p>
                            <button class="bk-btn pri" :disabled="busy" @click="saveBasics()">حفظ</button>
                        </div>

                        <!-- الخدمات والمواضيع -->
                        <div class="bk-tpl" v-if="sec.key === 'services'">
                            <div class="bk-lbl">ركّز الشهر ده على</div>
                            <div class="bk-chips">
                                <button class="bk-chip" :class="{on: !focusId}" @click="focus(0)">متوازن</button>
                                <button v-for="v in s.services" class="bk-chip" :class="{on: focusId === +v.id}" @click="focus(v.id)">⭐ {{ v.name }}</button>
                            </div>
                            <div v-for="v in s.services" :key="v.id" class="bk-svc" :class="{off: +v.is_active === 0}">
                                <div class="bk-svc-h">
                                    <b>{{ v.name }}</b>
                                    <span class="bk-pill" :class="v.priority">{{ prioLabel[v.priority] }}</span>
                                    <span v-if="+v.is_focus" class="bk-pill focus">خدمة الشهر</span>
                                    <button class="bk-link" @click="editService(v)">تعديل</button>
                                </div>
                                <div class="bk-topics">
                                    <span v-for="t in v.topics" :key="t.id" class="bk-topic" :class="{off: +t.is_active === 0}">{{ t.title }}<i v-if="+t.times_used">×{{ t.times_used }}</i><button @click="delTopic(t)" aria-label="شيل الموضوع">×</button></span>
                                    <span v-if="!v.topics.length" class="bk-mute">مفيش مواضيع لسه</span>
                                </div>
                                <div class="bk-row">
                                    <input class="bk-in" v-model="newTopic[v.id]" placeholder="موضوع جديد…" @keyup.enter="addTopic(v.id)">
                                    <button class="bk-btn sm" @click="addTopic(v.id)">إضافة</button>
                                    <button class="bk-btn sm ai" :disabled="busy" @click="suggestTopics(v.id)">✨ اقترح 5 <span class="cr" v-if="s.costs.ai">· {{ s.costs.ai }}</span></button>
                                </div>
                                <div v-if="sugTopics.sid === +v.id && sugTopics.list.length" class="bk-sugt">
                                    <label v-for="(t, i) in sugTopics.list" :key="i"><input type="checkbox" v-model="sugTopics.pick[i]"> {{ t }}</label>
                                    <button class="bk-btn sm pri" @click="addSuggested()">ضيف المختار</button>
                                </div>
                            </div>
                            <button class="bk-btn" @click="editService(null)">+ خدمة</button>

                            <div class="bk-lbl" style="margin-top:16px">مواضيع عامة (مش لخدمة معينة)</div>
                            <div class="bk-topics">
                                <span v-for="t in s.topics" :key="t.id" class="bk-topic">{{ t.title }}<button @click="delTopic(t)" aria-label="شيل">×</button></span>
                            </div>
                            <div class="bk-row">
                                <input class="bk-in" v-model="newTopic[0]" placeholder="موضوع عام…" @keyup.enter="addTopic(0)">
                                <button class="bk-btn sm" @click="addTopic(0)">إضافة</button>
                                <button class="bk-btn sm ai" :disabled="busy" @click="suggestTopics(0)">✨ اقترح 5</button>
                            </div>
                            <div v-if="sugTopics.sid === 0 && sugTopics.list.length" class="bk-sugt">
                                <label v-for="(t, i) in sugTopics.list" :key="i"><input type="checkbox" v-model="sugTopics.pick[i]"> {{ t }}</label>
                                <button class="bk-btn sm pri" @click="addSuggested()">ضيف المختار</button>
                            </div>
                            <div v-if="s.library_topics.length" class="bk-lib">
                                <div class="bk-lbl">من مكتبة {{ s.sector ? s.sector.name : 'Spread' }}</div>
                                <div class="bk-chips"><button v-for="t in s.library_topics" class="bk-chip ghost" @click="addLib(t.title)">+ {{ t.title }}</button></div>
                            </div>

                            <details class="bk-adv"><summary>إعدادات متقدمة</summary>
                                <div class="bk-lbl">مواضيع ممنوعة</div>
                                <textarea class="bk-in" rows="3" v-model="ed.forbidden_topics" placeholder="موضوع في كل سطر"></textarea>
                                <button class="bk-btn sm" @click="saveSec('forbidden_topics', lines(ed.forbidden_topics))">حفظ</button>
                                <div class="bk-lbl" style="margin-top:12px">القطاع</div>
                                <select class="bk-in" :value="s.sector ? s.sector.id : ''" @change="setSector($event.target.value)">
                                    <option value="">— من غير قالب —</option><option v-for="x in s.sectors" :value="x.id">{{ x.name }}</option>
                                </select>
                            </details>
                        </div>

                        <!-- أشكال المحتوى -->
                        <div class="bk-tpl" v-if="sec.key === 'formats'">
                            <p class="bk-mute">رتّب بالسحب (أو بالأسهم) — الأول هو المفضل. وحدد كام في الأسبوع.</p>
                            <ol class="bk-fmts">
                                <li v-for="(f, i) in fm" :key="f.format" draggable="true" @dragstart="dragI = i" @dragover.prevent @drop="dropFmt(i)" :class="{off: !f.is_enabled}">
                                    <span class="grip" aria-hidden="true">⋮⋮</span>
                                    <b>{{ s.labels.formats[f.format] }}</b>
                                    <label class="bk-num">في الأسبوع <input type="number" min="0" max="21" v-model.number="f.per_week"></label>
                                    <input v-if="f.format === 'carousel'" class="bk-in xs" v-model="f.settings.slides" placeholder="5-7" aria-label="عدد الشرائح">
                                    <input v-if="f.format === 'reel'" class="bk-in xs" v-model="f.settings.duration" placeholder="15-30" aria-label="مدة الريل بالثانية">
                                    <span class="mv"><button @click="moveFmt(i, -1)" aria-label="لفوق">↑</button><button @click="moveFmt(i, 1)" aria-label="لتحت">↓</button></span>
                                    <label class="bk-sw"><input type="checkbox" v-model="f.is_enabled"><span></span></label>
                                </li>
                            </ol>
                            <div class="bk-lbl">نوع الـ Hook</div>
                            <div class="bk-chips"><button v-for="(l, k) in s.labels.hooks" class="bk-chip" :class="{on: ed.content.hooks.includes(k)}" @click="toggleArr(ed.content.hooks, k)">{{ l }}</button></div>
                            <div class="bk-lbl">طول الكابشن</div>
                            <div class="bk-chips"><button v-for="(l, k) in lengths" class="bk-chip" :class="{on: ed.content.caption_length === k}" @click="ed.content.caption_length = k">{{ l }}</button></div>
                            <details class="bk-adv"><summary>إعدادات متقدمة</summary>
                                <div class="bk-lbl">المنصات</div>
                                <div class="bk-chips"><button v-for="(l, k) in platforms" class="bk-chip" :class="{on: ed.content.platforms.includes(k)}" @click="toggleArr(ed.content.platforms, k)">{{ l }}</button></div>
                                <label class="bk-lbl">عدد البوستات في الشهر <input class="bk-in xs" type="number" min="1" max="90" v-model.number="ed.content.posts_per_month"></label>
                            </details>
                            <button class="bk-btn pri" @click="saveFormats()">حفظ</button>
                        </div>

                        <!-- طريقة الكلام -->
                        <div class="bk-tpl" v-if="sec.key === 'voice'">
                            <div class="bk-chips"><button v-for="p in presets('tone')" class="bk-chip" :class="{on: +ed.voice.preset_id === +p.id}" @click="applyTone(p)">{{ p.name }}</button></div>
                            <div class="bk-slider"><span>رسمي</span><input type="range" min="0" max="100" v-model.number="ed.voice.formal_friendly" aria-label="رسمي ← ودود"><span>ودود</span></div>
                            <div class="bk-slider"><span>جاد</span><input type="range" min="0" max="100" v-model.number="ed.voice.serious_fun" aria-label="جاد ← مرح"><span>مرح</span></div>
                            <div class="bk-slider"><span>علمي</span><input type="range" min="0" max="100" v-model.number="ed.voice.scientific_simple" aria-label="علمي ← بسيط"><span>بسيط</span></div>
                            <div class="bk-chips">
                                <button v-for="(l, k) in dialects" class="bk-chip" :class="{on: ed.voice.dialect === k}" @click="ed.voice.dialect = k">{{ l }}</button>
                                <button class="bk-chip" :class="{on: ed.voice.emoji}" @click="ed.voice.emoji = !ed.voice.emoji">{{ ed.voice.emoji ? '😊 إيموجي' : 'من غير إيموجي' }}</button>
                            </div>
                            <div class="bk-regen"><span class="bk-mute">عدّل بكلمة:</span><button class="bk-chip ai" :disabled="busy" @click="regen('voice', 'simpler')">أبسط</button><button class="bk-chip ai" :disabled="busy" @click="regen('voice', 'fancier')">أفخم</button><button class="bk-chip ai" :disabled="busy" @click="regen('voice', 'alternatives')">اقتراحات تانية</button><input class="bk-in" v-model="regenText.voice" placeholder="أو اكتب اللي عايزه… واضغط Enter" @keyup.enter="regen('voice', regenText.voice)"></div>
                            <div v-if="prop.voice" class="bk-prop"><b>اقتراح:</b> {{ ((prop.voice && prop.voice.sample_lines) || []).join(' · ') }}<button class="bk-btn sm pri" @click="useProp('voice')">استخدمه</button></div>
                            <details class="bk-adv"><summary>إعدادات متقدمة</summary>
                                <div class="bk-lbl">كلمات بنحب نستخدمها</div><textarea class="bk-in" rows="2" v-model="ed.voice.words_use_t" placeholder="كلمة في كل سطر أو بفاصلة"></textarea>
                                <div class="bk-lbl">⛔ كلمات ممنوعة (عمرها ما تظهر في أي كابشن)</div><textarea class="bk-in" rows="2" v-model="ed.voice.words_avoid_t"></textarea>
                                <div class="bk-lbl">جمل بأسلوبك (الـ AI بيقلّد روحها)</div><textarea class="bk-in" rows="3" v-model="ed.voice.sample_lines_t" placeholder="جملة في كل سطر"></textarea>
                            </details>
                            <button class="bk-btn pri" @click="saveVoice()">حفظ</button>
                        </div>

                        <!-- اتجاه المحتوى -->
                        <div class="bk-tpl" v-if="sec.key === 'pillars'">
                            <div class="bk-lbl">هدف المحتوى</div>
                            <div class="bk-chips"><button v-for="(l, k) in goals" class="bk-chip" :class="{on: ed.content.goal === k}" @click="ed.content.goal = k">{{ l }}</button></div>
                            <div class="bk-lbl">الاتجاهات ونسبها <small :class="{bad: pillarSum !== 100}">({{ pillarSum }}%)</small></div>
                            <div v-for="(p, i) in ed.pillars" :key="i" class="bk-pillar">
                                <input class="bk-in" v-model="p.name" placeholder="مثلًا: توعية">
                                <input type="range" min="0" max="100" step="5" v-model.number="p.percent" aria-label="النسبة"><b>{{ p.percent }}%</b>
                                <button class="bk-link" @click="ed.pillars.splice(i, 1)" aria-label="شيل">×</button>
                            </div>
                            <div class="bk-row"><button class="bk-btn sm" @click="ed.pillars.push({name: '', percent: 10})">+ اتجاه</button><button class="bk-btn sm" @click="balancePillars()">وزّع بالتساوي</button></div>
                            <div class="bk-regen"><span class="bk-mute">عدّل بكلمة:</span><button class="bk-chip ai" :disabled="busy" @click="regen('pillars', 'simpler')">أبسط</button><button class="bk-chip ai" :disabled="busy" @click="regen('pillars', 'fancier')">أفخم</button><button class="bk-chip ai" :disabled="busy" @click="regen('pillars', 'alternatives')">اقتراحات تانية</button><input class="bk-in" v-model="regenText.pillars" placeholder="أو اكتب اللي عايزه… واضغط Enter" @keyup.enter="regen('pillars', regenText.pillars)"></div>
                            <div v-if="prop.pillars" class="bk-prop"><b>اقتراح:</b> {{ (prop.pillars || []).map(p => p.name + ' ' + p.percent + '%').join(' · ') }}<button class="bk-btn sm pri" @click="useProp('pillars')">استخدمه</button></div>
                            <button class="bk-btn pri" @click="savePillars()">حفظ</button>
                        </div>

                        <!-- العروض -->
                        <div class="bk-tpl" v-if="sec.key === 'offers'">
                            <div v-for="(o, i) in ed.offers" :key="i" class="bk-offer">
                                <input class="bk-in" v-model="o.title" placeholder="العرض (مثلًا: خصم 20% على التبييض)">
                                <input class="bk-in" v-model="o.details" placeholder="تفاصيل (اختياري)">
                                <label class="bk-num">لحد <input type="date" class="bk-in xs" v-model="o.until"></label>
                                <button class="bk-link" @click="ed.offers.splice(i, 1)">شيل</button>
                            </div>
                            <div class="bk-row"><button class="bk-btn sm" @click="ed.offers.push({title: '', details: '', until: ''})">+ عرض</button><button class="bk-btn pri" @click="saveSec('offers', ed.offers)">حفظ</button></div>
                        </div>

                        <!-- النشاط والجمهور -->
                        <div class="bk-tpl" v-if="sec.key === 'business'">
                            <div class="bk-two">
                                <label class="bk-f">اسم النشاط<input class="bk-in" v-model="ed.business.name"></label>
                                <label class="bk-f">المجال<input class="bk-in" v-model="ed.business.sector"></label>
                            </div>
                            <label class="bk-f">إيه اللي بيميّزك؟<input class="bk-in" v-model="ed.business.usp" placeholder="مثلًا: أقدم عيادة في المنطقة، مواعيد بالليل"></label>
                            <div class="bk-lbl">شخصية البراند (3 كلمات)</div>
                            <div class="bk-chips"><button v-for="w in personalityWords" class="bk-chip" :class="{on: ed.personality.includes(w)}" @click="toggleArr(ed.personality, w, 4)">{{ w }}</button></div>
                            <label class="bk-f">مين جمهورك؟<textarea class="bk-in" rows="2" v-model="ed.audience.description"></textarea></label>
                            <label class="bk-f">مشاكلهم (سطر لكل مشكلة)<textarea class="bk-in" rows="2" v-model="ed.audience.pains_t"></textarea></label>
                            <div class="bk-regen"><span class="bk-mute">عدّل بكلمة:</span><button class="bk-chip ai" :disabled="busy" @click="regen('audience', 'simpler')">أبسط</button><button class="bk-chip ai" :disabled="busy" @click="regen('audience', 'fancier')">أفخم</button><button class="bk-chip ai" :disabled="busy" @click="regen('audience', 'alternatives')">اقتراحات تانية</button><input class="bk-in" v-model="regenText.audience" placeholder="أو اكتب اللي عايزه… واضغط Enter" @keyup.enter="regen('audience', regenText.audience)"></div>
                            <div v-if="prop.audience" class="bk-prop"><b>اقتراح:</b> {{ prop.audience && prop.audience.description }} — {{ ((prop.audience && prop.audience.pains) || []).join('، ') }}<button class="bk-btn sm pri" @click="useProp('audience')">استخدمه</button></div>
                            <details class="bk-adv"><summary>إعدادات متقدمة</summary>
                                <div class="bk-two">
                                    <label class="bk-f">السن<input class="bk-in" v-model="ed.audience.age" placeholder="25-45"></label>
                                    <label class="bk-f">النوع<input class="bk-in" v-model="ed.audience.gender" placeholder="الكل"></label>
                                </div>
                                <label class="bk-f">اعتراضاتهم (سطر لكل اعتراض)<textarea class="bk-in" rows="2" v-model="ed.audience.objections_t"></textarea></label>
                                <div class="bk-two">
                                    <label class="bk-f">المكان<input class="bk-in" v-model="ed.business.location"></label>
                                    <label class="bk-f">مستوى الأسعار<input class="bk-in" v-model="ed.business.price_range"></label>
                                </div>
                            </details>
                            <button class="bk-btn pri" @click="saveBusiness()">حفظ</button>
                        </div>
                    </div>
                </article>

                <h2 class="bk-gt">الشكل</h2>
                <article v-for="sec in lookSections" :key="sec.key" class="bk-card bk-sec" :class="{open: open === sec.key, unsure: isUnsure(sec.key)}" :id="'bk-' + sec.key">
                    <button class="bk-sec-h" @click="toggle(sec.key)" :aria-expanded="open === sec.key">
                        <span class="ic">{{ sec.icon }}</span><span class="t"><b>{{ sec.label }}</b><small>{{ summary(sec.key) }}</small></span>
                        <span v-if="isUnsure(sec.key)" class="bk-warn">راجع</span><span class="ar">⌄</span>
                    </button>
                    <div v-if="open === sec.key" class="bk-sec-b">
                        <div v-if="isUnsure(sec.key)" class="bk-unsure">الـ AI مش متأكد من القسم ده. <button class="bk-btn sm" @click="confirmSec(sec.key)">تمام، أكّد</button></div>

                        <!-- اللوجو -->
                        <div class="bk-tpl" v-if="sec.key === 'logo'">
                            <div class="bk-logo"><img v-if="s.logo_url" :src="s.logo_url" alt="اللوجو"><span v-else class="bk-mute">مفيش لوجو لسه</span>
                                <label class="bk-btn sm"><input type="file" accept="image/png,image/jpeg,image/webp" hidden @change="logoUpload($event)">{{ s.logo_url ? 'غيّر اللوجو' : 'ارفع اللوجو' }}</label>
                                <button v-if="s.logo_url" class="bk-btn sm ghost" :disabled="busy" @click="logoColors()">🎨 ألوانه</button></div>
                            <div class="bk-gen">
                                <div class="bk-lbl">✨ صمّم لوجو بالـ AI من هويتك</div>
                                <div class="bk-chips"><button v-for="(l, k) in logoStyles" class="bk-chip" :class="{on: lg.style === k}" @click="lg.style = k">{{ l }}</button></div>
                                <input class="bk-in" v-model="lg.notes" placeholder="ملاحظة (اختياري): مثلًا سنّة مبتسمة · ألوان هادية">
                                <div class="bk-row"><button class="bk-btn sm ai" :disabled="busy" @click="logoGen()">{{ s.logo_candidates.length ? 'صمّم اقتراح تاني' : 'صمّم لوجو' }} <span class="cr" v-if="s.costs.logo">· {{ s.costs.logo }}</span></button>
                                    <button v-if="s.logo_candidates.length" class="bk-btn sm ghost" :disabled="busy" @click="logoDiscard('')">امسح الاقتراحات</button></div>
                                <div v-if="s.logo_candidates.length" class="bk-imgs logo">
                                    <div v-for="c in s.logo_candidates" :key="c.path" class="bk-img"><img :src="c.url" alt="اقتراح لوجو">
                                        <button class="pick" @click="logoPick(c)" title="اختاره">✓</button><button @click="logoDiscard(c.path)" aria-label="امسح">×</button></div>
                                </div>
                                <small class="bk-mute">الـ AI بيستخدم اسمك ومجالك وشخصية البراند وألوانك. اختار اللي يعجبك يبقى اللوجو الأساسي.</small>
                            </div>
                            <div class="bk-lbl">مكان اللوجو في التصميم</div>
                            <div class="bk-chips"><button v-for="(l, k) in logoPos" class="bk-chip" :class="{on: ed.logo.position === k}" @click="ed.logo.position = k; saveSec('logo', ed.logo)">{{ l }}</button></div>
                        </div>

                        <!-- الألوان والاستايل -->
                        <div class="bk-tpl" v-if="sec.key === 'colors'">
                            <div class="bk-lbl">باليتة جاهزة</div>
                            <div class="bk-chips">
                                <button v-for="p in presets('palette')" class="bk-chip pal" :class="{on: +ed.colors.preset_id === +p.id}" @click="applyPalette(p)">
                                    <i v-for="c in (p.data.colors || [])" :style="{background: c}"></i>{{ p.name }}</button>
                            </div>
                            <div class="bk-row"><button class="bk-btn sm ai" :disabled="busy" @click="palSuggest(true)">✨ اقترح ألوان لهويتي <span class="cr" v-if="s.costs.ai">· {{ s.costs.ai }}</span></button>
                                <button class="bk-btn sm ghost" :disabled="busy" @click="palSuggest(false)">🎨 من تصميماتي واللوجو</button></div>
                            <div v-if="pals.length" class="bk-pals">
                                <div v-for="(p, i) in pals" :key="i" class="bk-pal">
                                    <span class="sw"><i v-for="c in [p.primary, p.secondary, p.accent, p.neutral].filter(Boolean)" :style="{background: c}"></i></span>
                                    <span class="t"><b>{{ p.name }}</b><small>{{ p.why }}</small></span>
                                    <button class="bk-btn sm pri" :disabled="busy" @click="palUse(p)">استخدمها</button>
                                </div>
                            </div>
                            <div class="bk-colors">
                                <label v-for="(l, k) in colorKeys" class="bk-color"><input type="color" v-model="ed.colors[k]" :aria-label="l"><span>{{ l }}<small dir="ltr">{{ ed.colors[k] || '—' }}</small></span></label>
                            </div>
                            <div class="bk-lbl">استايل التصميم</div>
                            <div class="bk-cards">
                                <button v-for="p in presets('design_style')" class="bk-preset" :class="{on: +ed.design_style.preset_id === +p.id}" @click="ed.design_style.preset_id = p.id">
                                    <img v-if="p.preview" :src="p.preview" alt=""><b>{{ p.name }}</b><small>{{ p.data.desc }}</small></button>
                            </div>
                            <div class="bk-regen"><span class="bk-mute">عدّل بكلمة:</span><button class="bk-chip ai" :disabled="busy" @click="regen('design_style', 'simpler')">أبسط</button><button class="bk-chip ai" :disabled="busy" @click="regen('design_style', 'fancier')">أفخم</button><button class="bk-chip ai" :disabled="busy" @click="regen('design_style', 'alternatives')">اقتراحات تانية</button><input class="bk-in" v-model="regenText.design_style" placeholder="أو اكتب اللي عايزه… واضغط Enter" @keyup.enter="regen('design_style', regenText.design_style)"></div>
                            <div v-if="prop.design_style" class="bk-prop"><b>اقتراح:</b> {{ prop.design_style && prop.design_style.notes }}<button class="bk-btn sm pri" @click="useProp('design_style')">استخدمه</button></div>
                            <details class="bk-adv"><summary>إعدادات متقدمة</summary>
                                <div class="bk-two">
                                    <label class="bk-f">الخط العربي<select class="bk-in" v-model="ed.typography.ar"><option v-for="f in fontsAr">{{ f }}</option></select></label>
                                    <label class="bk-f">الخط الإنجليزي<select class="bk-in" v-model="ed.typography.en"><option v-for="f in fontsEn">{{ f }}</option></select></label>
                                </div>
                                <div class="bk-lbl">نسبة الألوان</div>
                                <div class="bk-chips"><button v-for="r in ['60-30-10', '70-20-10', '50-30-20']" class="bk-chip" :class="{on: ed.colors.ratio === r}" @click="ed.colors.ratio = r">{{ r }}</button></div>
                                <div class="bk-lbl">الأيقونات</div>
                                <div class="bk-chips"><button v-for="(l, k) in iconStyles" class="bk-chip" :class="{on: ed.design_style.icons === k}" @click="ed.design_style.icons = k">{{ l }}</button></div>
                                <label class="bk-f">ملاحظات التصميم<textarea class="bk-in" rows="2" v-model="ed.design_style.notes"></textarea></label>
                                <label class="bk-f">⛔ ممنوع في التصميم (سطر لكل حاجة)<textarea class="bk-in" rows="2" v-model="ed.design_style.avoid_t"></textarea></label>
                            </details>
                            <button class="bk-btn pri" @click="saveLook()">حفظ</button>
                        </div>

                        <!-- طبيعة الصور -->
                        <div class="bk-tpl" v-if="sec.key === 'visuals'">
                            <div class="bk-chips"><button v-for="p in presets('visual')" class="bk-chip" :class="{on: +ed.visuals.preset_id === +p.id}" @click="applyVisual(p)">{{ p.name }}</button></div>
                            <div class="bk-lbl">نوع الصور</div>
                            <div class="bk-chips"><button v-for="(l, k) in vKinds" class="bk-chip" :class="{on: ed.visuals.kind === k}" @click="ed.visuals.kind = k">{{ l }}</button></div>
                            <div class="bk-lbl">الإضاءة والمود</div>
                            <div class="bk-chips"><button v-for="(l, k) in vLight" class="bk-chip" :class="{on: ed.visuals.light === k}" @click="ed.visuals.light = k">{{ l }}</button></div>
                            <div class="bk-lbl">الناس في الصور</div>
                            <div class="bk-chips"><button v-for="(l, k) in vPeople" class="bk-chip" :class="{on: ed.visuals.people === k}" @click="ed.visuals.people = k">{{ l }}</button></div>
                            <details class="bk-adv"><summary>إعدادات متقدمة</summary>
                                <label class="bk-f">الأماكن (سطر لكل مكان)<textarea class="bk-in" rows="2" v-model="ed.visuals.places_t" placeholder="العيادة&#10;الاستقبال"></textarea></label>
                                <div class="bk-lbl">ألوان البراند في الصور</div>
                                <div class="bk-chips"><button v-for="(l, k) in vBrandColor" class="bk-chip" :class="{on: ed.visuals.brand_color_in_photos === k}" @click="ed.visuals.brand_color_in_photos = k">{{ l }}</button></div>
                                <label class="bk-f">⛔ ممنوع يظهر (سطر لكل حاجة)<textarea class="bk-in" rows="2" v-model="ed.visuals.avoid_t" placeholder="دم&#10;أدوات حادة"></textarea></label>
                            </details>
                            <button class="bk-btn pri" @click="saveVisuals()">حفظ</button>
                        </div>

                        <!-- الشخصيات -->
                        <div class="bk-tpl" v-if="sec.key === 'characters'">
                            <div v-for="c in s.characters" :key="c.id" class="bk-char" :class="{off: +c.is_active === 0}">
                                <div class="bk-char-h">
                                    <img v-if="c.images.length" :src="c.images[0].url" alt=""><span v-else class="ph">{{ c.name.charAt(0) }}</span>
                                    <div><b>{{ c.name }}</b> <small>{{ charTypes[c.type] }}{{ c.role ? ' · ' + c.role : '' }}</small><br>
                                        <span v-if="c.ready" class="bk-pill ok">جاهزة · تظهر {{ c.share_percent }}%</span>
                                        <span v-else class="bk-pill warn">{{ charMissing(c) }}</span></div>
                                    <button class="bk-link" @click="editChar(c)">تعديل</button>
                                </div>
                            </div>
                            <button class="bk-btn" @click="editChar(null)">+ شخصية</button>
                            <p class="bk-mute" style="margin-top:8px">ارفع صور لشخص حقيقي (بموافقته)، أو ابني شخصية أو ماسكوت بالـ AI — الصورة الأساسية بتبقى مرجع ثابت لملامحها في كل التصميمات.</p>
                        </div>

                        <!-- تصميمات بتعجبك -->
                        <div class="bk-tpl" v-if="sec.key === 'inspiration'">
                            <p class="bk-mute">ارفع صور أو حط لينكات (Behance · Pinterest · إنستجرام · صورة) لتصميمات عاجباك — بنستلهم منها الإحساس والألوان والتكوين (من غير ما ننسخها).</p>
                            <div class="bk-imgs insp">
                                <div v-for="it in s.insp" :key="it.id" class="bk-img">
                                    <img v-if="it.img" :src="it.img" alt=""><a v-else :href="it.link" target="_blank" rel="noopener nofollow" class="bk-insp-link">🔗 {{ it.link.replace(/^https?:\/\/(www\.)?/, '').slice(0, 28) }}</a>
                                    <button @click="inspDel(it)" aria-label="حذف">×</button>
                                </div>
                                <label v-if="s.insp.length < s.insp_max" class="bk-img add"><input type="file" accept="image/png,image/jpeg,image/webp" @change="inspUpload($event)" hidden>+ صورة</label>
                            </div>
                            <div class="bk-row"><input class="bk-in" dir="ltr" v-model="inspLink" placeholder="لينك تصميم عاجبك…" @keyup.enter="inspAdd()"><button class="bk-btn sm" :disabled="busy || !inspLink" @click="inspAdd()">إضافة</button></div>
                            <label class="bk-check"><input type="checkbox" v-model="ed.design_style.use_inspirations" @change="saveSec('design_style', Object.assign({}, ed.design_style, {avoid: lines(ed.design_style.avoid_t)}), 'اتحفظ ✓')"> استخدمها كمرجع للاستايل في التصميمات لما مايكونش فيه مرجع مختار</label>
                        </div>
                    </div>
                </article>

                <h2 class="bk-gt">المصادر</h2>
                <article class="bk-card bk-sec" :class="{open: open === 'sources'}" id="bk-sources">
                    <button class="bk-sec-h" @click="toggle('sources')" :aria-expanded="open === 'sources'">
                        <span class="ic">📚</span><span class="t"><b>اللينكات والمستندات والبحث</b><small>{{ s.sources.length }} مستند · اللي الـ AI بيتعلم منه عن نشاطك</small></span><span class="ar">⌄</span>
                    </button>
                    <div v-if="open === 'sources'" class="bk-sec-b">
                        <div class="bk-lbl">حلّل لينك (موقعك · صفحتك · منيو)</div>
                        <div class="bk-row"><input class="bk-in" dir="ltr" v-model="srcUrl" placeholder="https://"><button class="bk-btn sm" :disabled="busy || !srcUrl" @click="analyzeSrc()">حلّل <span class="cr" v-if="s.analyze_cost">· {{ s.analyze_cost }}</span></button></div>
                        <p class="bk-mute">اللي بنلاقيه بيظهر في «اقتراحات لهويتك» فوق — وانت اللي بتوافق.</p>
                        <div class="bk-lbl">المستندات <small>(علّم على اللي يدخل في المحتوى)</small></div>
                        <div v-for="d in s.sources" :key="d.id" class="bk-src">
                            <span>{{ d.type === 'link' ? '🔗' : '📄' }} {{ d.title }}</span>
                            <label class="bk-sw" :title="d.use ? 'بيدخل في المحتوى' : 'مش بيدخل'"><input type="checkbox" :checked="d.use" @change="srcToggle(d)"><span></span></label>
                        </div>
                        <p v-if="!s.sources.length" class="bk-mute">مفيش مستندات لسه.</p>
                        <div class="bk-row">
                            <a class="bk-btn sm" :href="sourcesUrl">📄 ارفع ملف</a>
                            <a v-if="s.research" class="bk-btn sm" :href="base + '/research.php'">🔬 بحث عميق عن السوق</a>
                            <a class="bk-btn sm" :href="base + '/brand-agent.php'">🤖 اتكلم مع مساعد الهوية</a>
                        </div>
                    </div>
                </article>
            </div>

            <!-- ═══ نوافذ ═══ -->
            <div v-if="modal" class="bk-modal" @click.self="modal = null" role="dialog" aria-modal="true">
                <div class="bk-sheet">
                    <button class="bk-x" @click="modal = null" aria-label="إغلاق">×</button>

                    <div class="bk-tpl" v-if="modal === 'service' && svc">
                        <h3>{{ svc.id ? 'تعديل الخدمة' : 'خدمة جديدة' }}</h3>
                        <label class="bk-f">اسم الخدمة<input class="bk-in" v-model="svc.name"></label>
                        <label class="bk-f">وصف ومميزات (اختياري)<textarea class="bk-in" rows="3" v-model="svc.description"></textarea></label>
                        <div class="bk-lbl">الأولوية</div>
                        <div class="bk-chips"><button v-for="(l, k) in prioLabel" class="bk-chip" :class="{on: svc.priority === k}" @click="svc.priority = k">{{ l }}</button></div>
                        <div class="bk-lbl">المواسم</div>
                        <div class="bk-chips"><button v-for="x in seasonList" class="bk-chip" :class="{on: svc.seasons.includes(x)}" @click="toggleArr(svc.seasons, x)">{{ x }}</button></div>
                        <label class="bk-check"><input type="checkbox" v-model="svc.is_active"> الخدمة شغالة</label>
                        <div class="bk-row end"><button v-if="svc.id" class="bk-btn danger" @click="delService()">حذف</button><button class="bk-btn pri" :disabled="busy" @click="saveService()">حفظ</button></div>
                    </div>

                    <div class="bk-tpl" v-if="modal === 'character' && ch">
                        <h3>{{ ch.id ? 'تعديل الشخصية' : 'شخصية جديدة' }}</h3>
                        <div class="bk-chips"><button v-for="(l, k) in charTypes" class="bk-chip" :class="{on: ch.type === k}" @click="ch.type = k">{{ l }}</button></div>
                        <div class="bk-two">
                            <label class="bk-f">الاسم<input class="bk-in" v-model="ch.name"></label>
                            <label class="bk-f">الدور<input class="bk-in" v-model="ch.role" placeholder="صاحب البراند · المريضة القلقانة"></label>
                        </div>
                        <label class="bk-f">وصف ثابت للملامح واللبس (بيتحط في كل صورة)<textarea class="bk-in" rows="3" v-model="ch.description" placeholder="راجل في الأربعينات، شعر قصير رمادي، نضارة، بالطو أبيض"></textarea></label>
                        <label class="bk-f">طريقة كلامها (لو بتتكلم)<input class="bk-in" v-model="ch.personality"></label>
                        <div class="bk-lbl">تظهر في</div>
                        <div class="bk-chips"><button v-for="(l, k) in useIn" class="bk-chip" :class="{on: ch.use_in.includes(k)}" @click="toggleArr(ch.use_in, k)">{{ l }}</button></div>
                        <div class="bk-slider"><span>نسبة الظهور</span><input type="range" min="0" max="100" step="5" v-model.number="ch.share_percent"><b>{{ ch.share_percent }}%</b></div>
                        <label v-if="ch.type === 'real_person'" class="bk-check consent"><input type="checkbox" v-model="ch.consent_confirmed"> أأكد إن عندي موافقة الشخص ده على استخدام صوره في المحتوى (إجباري قبل الاستخدام)</label>
                        <div class="bk-tpl">
                            <div class="bk-lbl">الصور المرجعية <small>({{ ch.images.length }}/8 — {{ ch.type === 'real_person' ? 'محتاجين 3 على الأقل' : 'صورة واحدة كفاية' }})</small></div>
                            <div class="bk-imgs">
                                <div v-for="im in ch.images" :key="im.id" class="bk-img" :class="{pri: +im.is_primary}">
                                    <img :src="im.url" alt=""><button @click="charPrimary(im)" title="الأساسية">★</button><button @click="charImgDel(im)" aria-label="حذف">×</button></div>
                                <label v-if="ch.images.length < 8" class="bk-img add"><input type="file" accept="image/png,image/jpeg,image/webp" @change="charUpload($event)" hidden>+ ارفع صورة</label>
                            </div>
                            <div v-if="ch.type !== 'real_person'" class="bk-gen">
                                <div class="bk-lbl">✨ أو ابنيها بالـ AI</div>
                                <div class="bk-chips"><button v-for="p in presets('character_style')" class="bk-chip" :class="{on: +gen.style === +p.id}" @click="gen.style = +gen.style === +p.id ? 0 : p.id">{{ p.name }}</button></div>
                                <div class="bk-chips"><button v-for="x in genLooks" class="bk-chip ghost" @click="gen.look = (gen.look ? gen.look + '، ' : '') + x">+ {{ x }}</button></div>
                                <textarea class="bk-in" rows="2" v-model="gen.look" placeholder="الملامح واللبس والإحساس — أو سيبها فاضية والـ AI يتخيّل شخصية مناسبة لنشاطك"></textarea>
                                <div class="bk-row">
                                    <button class="bk-btn sm ai" :disabled="busy" @click="charGen('new')">{{ ch.images.length ? 'ولّد صورة جديدة' : 'ابنيها' }} <span class="cr" v-if="s.costs.character">· {{ s.costs.character }}</span></button>
                                    <button v-if="ch.images.length" class="bk-btn sm ghost" :disabled="busy" @click="charGen('variation')">نفس الملامح في وضع تاني</button>
                                </div>
                                <div class="bk-photo">
                                    <div class="bk-lbl">📷 أو ارفع صورة ونحولها لشخصية البراند</div>
                                    <small class="bk-mute">صورة شخص أو رسمة أو منتج — الـ AI يعملها شخصية بنفس الملامح وبالاستايل اللي اخترته.</small>
                                    <label class="bk-check"><input type="checkbox" v-model="photoOk"> عندي إذن باستخدام الصورة دي</label>
                                    <label class="bk-btn sm" :class="{dis: busy || !photoOk}"><input type="file" accept="image/png,image/jpeg,image/webp" hidden :disabled="busy || !photoOk" @change="charPhoto($event)">ارفع الصورة وحوّلها <span class="cr" v-if="s.costs.character">· {{ s.costs.character }}</span></label>
                                </div>
                                <small class="bk-mute">الصورة اللي عليها ★ هي الأساس — كل التصميمات بتحافظ على نفس ملامحها.</small>
                            </div>
                        </div>
                        <label class="bk-check"><input type="checkbox" v-model="ch.is_active"> مفعّلة</label>
                        <div class="bk-row end"><button v-if="ch.id" class="bk-btn danger" @click="delChar()">حذف</button><button class="bk-btn pri" :disabled="busy" @click="saveChar()">حفظ</button></div>
                    </div>

                    <div class="bk-tpl" v-if="modal === 'versions'">
                        <h3>النسخ السابقة</h3>
                        <div v-for="v in versions" :key="v.version" class="bk-ver">
                            <span><b>نسخة {{ v.version }}</b> <small>{{ byLabel[v.changed_by] }} · {{ v.created_at }}</small><br><small>{{ v.note }}</small></span>
                            <button v-if="v.version !== s.version" class="bk-btn sm" @click="restore(v.version)">ارجع لها</button><span v-else class="bk-pill ok">الحالية</span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="toast" class="bk-toast" :class="toast && toast.type" role="status">{{ toast && toast.msg }}</div>
            </div>
        </div>
    </main>
</div>
<script src="<?= $__ver('assets/js/brand-kit.js') ?>" defer></script>
<?php include __DIR__ . '/../templates/footer.php'; ?>
