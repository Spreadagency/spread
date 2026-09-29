<?php
/**
 * Spread AI v2 — Design Studio (المرحلة ⑤-أ) — petite-vue
 * الطريقة ← (Creative Brief) ← التصميم + الكابشن ← اعتماد = منشور في المكتبة
 * التوليد: ajax/studio-design.php (الموجود) · الـ Brief والمعرض: /api/studio.php
 *
 * ⚠️ ممنوع <template v-if> — بيكسر في الأجزاء المشروطة (استخدم span.pv-c)
 */
?>
<div id="st" class="st" v-cloak>

    <!-- الرأس -->
    <header class="st-head">
        <div>
            <span class="st-eyebrow">Design Studio</span>
            <h2>حوّل فكرتك إلى تصميم جاهز للنشر</h2>
        </div>
        <div class="st-usage" v-if="home">
            <span><b>{{ home.month }}</b> تصميم الشهر ده</span>
            <span class="cr">رصيدك <b>{{ home.costs.balance }}</b> كريدت</span>
        </div>
    </header>

    <!-- الهوية -->
    <div class="st-brand warn" v-if="home && !home.brand.health.unlocked">
        <span class="st-brand-ic"><?= ui_icon('brain', 18) ?></span>
        <div>
            <b>التصميم بيطلع أحسن لما هوية البراند تعدّي {{ home.brand.health.gate }}%</b>
            <small>هويتك دلوقتي {{ home.brand.health.pct }}% — كل تصميم بيستخدم ألوانك ولوجوك وأسلوبك من Brand Brain تلقائيًا.</small>
        </div>
        <a :href="base + '/brand-brain.php'" class="btn soft sm">كمّل Brand Brain</a>
    </div>
    <div class="st-brand ok" v-if="home && home.brand.health.unlocked">
        <span class="st-brand-ic"><?= ui_icon('brain', 18) ?></span>
        <div><b>بيستخدم هوية {{ home.brand.name || 'براندك' }} تلقائيًا</b><small>الألوان · اللوجو · الأسلوب — من Brand Brain</small></div>
    </div>

    <!-- دورة التصميم -->
    <ol class="st-cycle">
        <li v-for="(c, i) in cycle" :key="i" :class="{ on: i === cycleIndex(), done: i < cycleIndex() }"><i>{{ i + 1 }}</i>{{ c }}</li>
    </ol>

    <!-- مشاريع لسه مكملتش — بتتحفظ تلقائي وتقدر تكمّلها من نفس الخطوة -->
    <section class="st-drafts" v-if="step === 'method' && drafts.length">
        <h3 class="dh-title">مشاريع لسه مكملتش <small class="sub">· بتتحفظ تلقائي</small></h3>
        <div class="st-drafts-grid">
            <div class="card st-draft" v-for="dr in drafts" :key="dr.id">
                <button type="button" class="st-draft-open" @click="resume(dr)" :disabled="busy">
                    <span class="st-draft-img"><img v-if="dr.thumb" :src="dr.thumb" alt="" loading="lazy"><span v-else v-html="iconSvg('doc')"></span></span>
                    <span class="st-draft-body"><b>{{ dr.title }}</b><small>{{ methodTitle(dr.method) }} · {{ dr.step_label }}</small><small class="sub">{{ dr.ago }}</small></span>
                </button>
                <div class="st-draft-act">
                    <button type="button" class="btn sm" @click="resume(dr)" :disabled="busy">كمّل ←</button>
                    <button type="button" class="btn ghost sm" @click="removeDraft(dr)" aria-label="مسح المشروع">✕</button>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══ ① الطريقة ═══ -->
    <section v-if="step === 'method'" class="st-methods">
        <button type="button" v-for="m in methodList()" :key="m.key" class="card st-method" :class="['c-' + m.color, { soon: m.soon }]"
                @click="pick(m)" :disabled="m.soon">
            <span class="st-m-top">
                <span class="st-m-ic" v-html="iconSvg(m.icon)"></span>
                <em v-if="m.badge">{{ m.badge }}</em>
            </span>
            <b>{{ m.title }}</b>
            <small>{{ m.description }}</small>
        </button>
    </section>

    <!-- ═══ ② الإدخال ═══ -->
    <section v-if="step === 'input'" class="card st-panel">
        <button type="button" class="st-back" @click="toMethods()">→ رجوع للطرق</button>
        <small class="st-saved sub" v-if="draftId">✓ المشروع محفوظ — تقدر تخرج وترجع تكمّل</small>

        <!-- قبل / بعد -->
        <div class="pv-c" v-if="method === 'before_after'">
            <h3>قبل / بعد</h3>
            <p class="sub">ارفع الصورتين، والـ AI يعمل تصميم يوضح النتيجة مع الحفاظ على الصور زي ما هي.</p>
            <div class="st-uploads">
                <label class="st-drop" :class="{ has: prev.before }">
                    <input type="file" accept="image/*" @change="setFile('before', $event)" hidden>
                    <img v-if="prev.before" :src="prev.before" alt="">
                    <span v-else><?= ui_icon('image', 26) ?><b>الصورة الأولى — قبل</b><small>اضغط لرفع صورة</small></span>
                    <em v-if="prev.before">قبل · تغيير</em>
                </label>
                <label class="st-drop" :class="{ has: prev.after }">
                    <input type="file" accept="image/*" @change="setFile('after', $event)" hidden>
                    <img v-if="prev.after" :src="prev.after" alt="">
                    <span v-else><?= ui_icon('image', 26) ?><b>الصورة التانية — بعد</b><small>اضغط لرفع صورة</small></span>
                    <em v-if="prev.after">بعد · تغيير</em>
                </label>
            </div>
            <label class="st-lbl">وصف المطلوب (اختياري)</label>
            <textarea class="textarea" rows="2" v-model="text" @input="sizeCheck()" maxlength="1000" placeholder="مثلًا: تبييض أسنان في جلسة واحدة"></textarea>
        </div>

        <!-- من صورة -->
        <div class="pv-c" v-if="method === 'from_image'">
            <h3>صمّم من صورة</h3>
            <p class="sub">ارفع صورة منتجك أو مكانك — بتفضل زي ما هي، والـ AI يبني التصميم حواليها.</p>
            <label class="st-drop wide" :class="{ has: prev.source }">
                <input type="file" accept="image/*" @change="setFile('source', $event)" hidden>
                <img v-if="prev.source" :src="prev.source" alt="">
                <span v-else><?= ui_icon('image', 28) ?><b>ارفع الصورة</b><small>JPG · PNG · WebP</small></span>
                <em v-if="prev.source">تغيير</em>
            </label>
            <label class="st-lbl">تحب نعمل إيه بالصورة؟</label>
            <div class="st-chips">
                <button type="button" v-for="p in cur.purposes" :key="p" :class="{ on: purpose === p }" @click="purpose = p">{{ p }}</button>
            </div>
            <textarea class="textarea" rows="2" v-model="text" @input="sizeCheck()" maxlength="1000" placeholder="تفاصيل زيادة (اختياري): العرض · السعر · الرسالة"></textarea>
        </div>

        <!-- اكتب فكرتك -->
        <div class="pv-c" v-if="method === 'free'">
            <h3>اكتب فكرتك</h3>
            <p class="sub">اكتب بالعربي زي ما بتتكلم — والباقي علينا.</p>
            <textarea class="textarea" rows="4" v-model="text" @input="sizeCheck()" maxlength="1200" placeholder="مثلًا: عايز بوست لعرض الصيف على تبييض الأسنان، شكله فاخر وهادي"></textarea>
            <div class="st-understands"><span class="sub">Spread AI بيفهم:</span>
                <span v-for="u in ['الموضوع','الهدف','الهوية','التصميم','Copy']" :key="u">{{ u }}</span>
            </div>
        </div>

        <!-- Prompt احترافي -->
        <div class="pv-c" v-if="method === 'pro'">
            <h3>Prompt احترافي</h3>
            <p class="sub">اكتب الـ Prompt بتاعك — Spread AI هيضيف له الـ Brand Brain تلقائيًا.</p>
            <textarea class="textarea" rows="5" v-model="text" @input="sizeCheck()" maxlength="1200" dir="auto" placeholder="Describe your design…"></textarea>
            <div class="st-adds" v-if="home && home.brand.adds.length">
                <b>بيتضاف من Brand Brain:</b>
                <div v-for="a in home.brand.adds" :key="a.k"><span>{{ a.k }}</span>{{ a.v }}</div>
                <small class="sub">لو كتبت «صمم إعلان فاخر للعطر»، الـ AI مش هيصمم أي عطر عشوائي — هيقرا هوية براندك الأول.</small>
            </div>
        </div>

        <!-- Trend Studio -->
        <div class="pv-c" v-if="method === 'trend'">
            <h3>Trending Creatives 🔥</h3>
            <p class="sub">ترندات بيختارها فريق Spread AI — وإحنا بنفصّلها على {{ trendBrand.name || 'براندك' }}.</p>
            <div class="st-tr-loading sub" v-if="trendsLoading"><span class="btn-spin"></span> بنجيب الترندات…</div>
            <p class="sub" v-if="!trendsLoading && !trends.length">مفيش ترندات شغالة دلوقتي — فريقنا بيضيف ترندات جديدة باستمرار.</p>

            <div class="st-trends" v-if="!trend && trends.length">
                <button type="button" v-for="t in trends" :key="t.id" class="card st-tr" @click="openTrend(t)">
                    <span class="st-tr-img">
                        <img v-if="t.ref" :src="t.ref" alt="" loading="lazy">
                        <span v-else class="st-tr-ph">{{ t.type }}</span>
                        <em class="st-tr-type">{{ t.type }}</em>
                        <em class="st-tr-fit" :class="{ hi: t.fit >= 90 }">مناسب لبراندك {{ t.fit }}%</em>
                    </span>
                    <span class="st-tr-body">
                        <small class="sub" dir="ltr">#{{ t.code }}</small>
                        <b>{{ t.name }}</b>
                        <small>{{ t.description }}</small>
                        <small class="st-tr-best">مناسب لـ: {{ t.industries.join(' · ') }}</small>
                        <small class="sub" dir="ltr">{{ t.platforms.join(' · ') }}</small>
                    </span>
                </button>
            </div>

            <div class="st-tr-detail" v-if="trend">
                <button type="button" class="st-back" @click="trend = null; ideas = []; idea = null">→ كل الترندات</button>
                <div class="st-tr-found">🔥 {{ trend.fit >= 90 ? 'وجدنا Trend مناسب لبراندك' : 'الترند' }}: «{{ trend.name }}»</div>
                <div class="st-tr-grid">
                    <div class="st-tr-orig">
                        <small class="sub">الأصل</small>
                        <img v-if="trend.ref" :src="trend.ref" alt="صورة الترند الأصلية">
                        <span v-else class="st-tr-ph big">{{ trend.type }}</span>
                        <p>{{ trend.description }}</p>
                    </div>
                    <div class="st-tr-bp">
                        <b>هيكل الترند:</b>
                        <div class="st-tr-steps"><span v-for="(x, i) in trend.structure" :key="i"><i>{{ i + 1 }}</i>{{ x }}</span></div>
                        <div class="st-tr-rules">
                            <div class="ok"><b>✓ مسموح تغييره</b>{{ trend.allowed.join(' · ') || '—' }}</div>
                            <div class="no"><b>🔒 ثابت لا يتغير</b>{{ trend.locked.join(' · ') || '—' }}</div>
                        </div>
                        <b>كيف سنستخدمه لبراندك؟</b>
                        <p class="sub">Brand Brain: {{ trendBrand.name || '—' }}{{ trendBrand.summary ? ' · ' + trendBrand.summary : '' }}</p>
                    </div>
                </div>

                <div class="st-low" v-if="home && home.costs.balance < home.costs.brief + home.costs.design">
                    <span class="cr">الأفكار ({{ home.costs.brief }}) + التصميم ({{ home.costs.design }}) = {{ home.costs.brief + home.costs.design }} كريدت — رصيدك {{ home.costs.balance }}.</span>
                    <span class="cr-alt">باقة الشهر خلصت.</span>
                    <a :href="base + '/credits.php'"><span class="cr">اشحن رصيد ←</span><span class="cr-alt">رقّي باقتك ←</span></a>
                </div>
                <button v-if="!ideas.length" type="button" class="btn" @click="trendIdeas()" :disabled="busy || (home && home.costs.balance < home.costs.brief + home.costs.design)">
                    {{ busy ? 'بيفكّر…' : 'اقترح 3 أفكار لبراندك ✨' }} <small class="cr" v-if="home && home.costs.brief">· {{ home.costs.brief }} كريدت</small>
                </button>

                <div class="st-ideas" v-if="ideas.length">
                    <button type="button" v-for="(it, i) in ideas" :key="i" class="st-idea" :class="{ on: idea === it }" @click="idea = it">
                        <i>{{ i + 1 }}</i>
                        <span><b>فكرة {{ i + 1 }}: {{ it.title }}</b><small>{{ it.desc }}</small></span>
                    </button>
                    <p class="sub st-tr-note">اخترت فكرة مناسبة لهوية البراند والجمهور. هنستخدم هيكل الترند — مش نسخة منه.</p>
                    <div class="st-actions">
                        <button type="button" class="btn" @click="useIdea()" :disabled="!idea">Generate Creative ←</button>
                        <button type="button" class="btn ghost" @click="trendIdeas()" :disabled="busy">↻ أفكار تانية</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- طريقة جديدة من الأدمن -->
        <div class="pv-c" v-if="cur.kind === 'custom'">
            <h3>{{ cur.title }}</h3>
            <p class="sub" v-if="cur.description">{{ cur.description }}</p>
            <div class="st-uploads" v-if="cur.uploads.length">
                <div class="pv-c" v-for="(u, i) in cur.uploads" :key="i">
                    <label class="st-drop" :class="{ has: prev['c' + i] }">
                        <input type="file" accept="image/*" @change="setFile('c' + i, $event)" hidden>
                        <img v-if="prev['c' + i]" :src="prev['c' + i]" alt="">
                        <span v-else><?= ui_icon('image', 26) ?><b>{{ u.label }}</b><small>{{ u.required ? 'إجباري' : 'اختياري' }} · اضغط للرفع</small></span>
                        <em v-if="prev['c' + i]">{{ u.label }} · تغيير</em>
                    </label>
                </div>
            </div>
            <div class="pv-c" v-if="cur.purposes.length">
                <label class="st-lbl">اختار</label>
                <div class="st-chips"><button type="button" v-for="p in cur.purposes" :key="p" :class="{ on: purpose === p }" @click="purpose = p">{{ p }}</button></div>
            </div>
            <div class="pv-c" v-if="cur.text.label">
                <label class="st-lbl">{{ cur.text.label }}</label>
                <textarea class="textarea" rows="3" v-model="text" @input="sizeCheck()" maxlength="1200" :placeholder="cur.text.placeholder"></textarea>
            </div>
        </div>

        <div class="st-typed" v-if="typedSize">📐 كتبت «{{ typedSize.match }}» — هنصمم بمقاس <b dir="ltr">{{ typedSize.key }}</b> ({{ typedSize.w }}×{{ typedSize.h }}) بدل الاختيار</div>

        <div class="st-low" v-if="home && method !== 'trend' && home.costs.balance < (cur.brief && method !== 'pro' ? home.costs.brief : 0) + home.costs.design">
            <span class="cr">رصيدك {{ home.costs.balance }} كريدت — {{ cur.brief && method !== 'pro' ? 'الـ Brief (' + home.costs.brief + ') + ' : '' }}التصميم ({{ home.costs.design }}).</span>
            <span class="cr-alt">باقة الشهر خلصت.</span>
            <a :href="base + '/credits.php'"><span class="cr">اشحن رصيد ←</span><span class="cr-alt">رقّي باقتك ←</span></a>
        </div>
        <div class="st-actions">
            <button v-if="method !== 'pro' && method !== 'trend' && cur.brief" type="button" class="btn" @click="makeBrief()" :disabled="busy || !canBrief()">
                {{ busy ? 'بيفكّر…' : (method === 'free' ? 'افهم الفكرة ✨' : 'التالي: الـ Brief ✨') }}
                <small class="cr" v-if="home && home.costs.brief">· {{ home.costs.brief }} كريدت</small>
            </button>
            <button v-if="method === 'pro'" type="button" class="btn" @click="step = 'brief'; brief = null" :disabled="text.trim().length < 5">التالي ←</button>
            <button v-if="method === 'before_after' || method === 'from_image' || cur.kind === 'custom'" type="button" :class="cur.brief ? 'btn ghost' : 'btn'"
                    @click="brief = null; step = 'brief'" :disabled="!canBrief()">{{ cur.brief ? 'صمّم من غير Brief' : 'التالي ←' }}</button>
        </div>
    </section>

    <!-- ═══ ③ Creative Brief ═══ -->
    <section v-if="step === 'brief'" class="card st-panel">
        <button type="button" class="st-back" @click="step = 'input'">→ رجوع</button>
        <h3>{{ method === 'trend' ? 'الترند «' + (trend ? trend.name : '') + '» — راجع قبل التوليد' : (brief ? 'الـ Creative Brief' : 'إعدادات التصميم') }}</h3>
        <p class="sub" v-if="brief">ده اللي فهمته قبل ما نبدأ التوليد — راجعه وعدّل أي حاجة، علشان ماتستهلكش من باقتك على حاجة مش مظبوطة.</p>

        <div class="st-brief" v-if="brief">
            <div class="st-bf two"><label>الهدف</label><input class="input" v-model="brief.goal"></div>
            <div class="st-bf two"><label>الجمهور</label><input class="input" v-model="brief.audience"></div>
            <div class="st-bf"><label>الفكرة</label><textarea class="textarea" rows="2" v-model="brief.idea"></textarea></div>
            <div class="st-bf"><label>الاتجاه البصري</label><textarea class="textarea" rows="2" v-model="brief.visual"></textarea></div>
            <div class="st-bf two"><label>العنوان على التصميم</label><input class="input" v-model="brief.headline" maxlength="80"></div>
            <div class="st-bf two"><label>السطر التاني</label><input class="input" v-model="brief.sub" maxlength="120"></div>
            <div class="st-bf two"><label>زرار (CTA)</label><input class="input" v-model="brief.cta" maxlength="40"></div>
        </div>

        <label class="st-lbl">المنصة</label>
        <div class="st-chips">
            <button type="button" v-for="p in platforms" :key="p.key" :class="{ on: platform === p.key }" @click="setPlatform(p)">{{ p.label }}</button>
        </div>
        <label class="st-lbl">المقاس</label>
        <div class="st-typed" v-if="typedSize">📐 كتبت «{{ typedSize.match }}» — هنصمم بمقاس <b dir="ltr">{{ typedSize.key }}</b> ({{ typedSize.w }}×{{ typedSize.h }}). المقاس المكتوب هو الأساسي.</div>
        <div class="st-chips" :class="{ muted: typedSize }">
            <button type="button" v-for="r in (home ? home.ratios : [])" :key="r.key" :class="{ on: ratio === r.key }" @click="ratio = r.key" :title="r.hint">{{ r.label }}</button>
        </div>

        <div class="pv-c" v-if="(method === 'free' || method === 'pro') && home && home.refs.length">
            <label class="st-lbl">صورة مرجعية للستايل (اختياري)</label>
            <div class="st-refs">
                <button type="button" :class="{ on: !styleRef }" @click="styleRef = ''" class="st-ref-none">بدون</button>
                <button type="button" v-for="r in home.refs" :key="r.ref" :class="{ on: styleRef === r.ref }" @click="styleRef = r.ref" :title="r.tag">
                    <img :src="r.src" alt="" loading="lazy"><em>{{ r.tag }}</em>
                </button>
            </div>
        </div>

        <label class="st-toggle">
            <input type="checkbox" v-model="useBrand">
            <span><b>Use Brand Identity</b> — اللوجو والألوان والأسلوب من Brand Brain</span>
        </label>

        <div class="st-low" v-if="home && home.costs.balance < home.costs.design">
            <span class="cr">رصيدك {{ home.costs.balance }} كريدت — التصميم محتاج {{ home.costs.design }}.</span>
            <span class="cr-alt">باقة الشهر خلصت.</span>
            <a :href="base + '/credits.php'"><span class="cr">اشحن رصيد ←</span><span class="cr-alt">رقّي باقتك ←</span></a>
        </div>
        <div class="st-actions">
            <button type="button" class="btn st-gen" @click="generate()" :disabled="busy || (home && home.costs.balance < home.costs.design)">
                {{ busy ? 'بيصمّم…' : 'Generate ✨' }} <small class="cr" v-if="home">· {{ home.costs.design }} كريدت</small>
            </button>
        </div>
    </section>

    <!-- ═══ ④ النتيجة ═══ -->
    <section v-if="step === 'result' && result" class="st-result">
        <div class="card st-r-img">
            <div class="st-r-head">
                <b>التصميم جاهز ✓</b>
                <small class="sub">{{ result.mode_label }} · <span dir="ltr">{{ result.ratio }}</span>{{ lastTyped ? ' (المقاس اللي كتبته)' : '' }} · {{ result.ago }}</small>
            </div>
            <img :src="result.url" alt="التصميم">
            <div class="st-vers" v-if="result.versions && result.versions.length > 1">
                <button type="button" v-for="v in result.versions" :key="v.id" :class="{ on: v.current }" @click="openDesign(v.id)" :title="v.note" :aria-label="'النسخة ' + v.n">
                    <img :src="v.url" alt=""><b>V{{ v.n }}</b>
                </button>
            </div>

            <div class="lib-ai st-edit">
                <b>✏️ عدّل بالكلام</b>
                <div class="lib-ai-chips">
                    <button type="button" v-for="ch in editChips" :key="ch" @click="editText = ch">{{ ch }}</button>
                </div>
                <textarea class="textarea" rows="2" v-model="editText" maxlength="600" placeholder="مثلًا: كبّر اللوجو وخلّي الخلفية أفتح"></textarea>
                <div class="lib-ai-row">
                    <button type="button" class="btn" @click="editDesign()" :disabled="busy || editText.trim().length < 3 || (home && home.costs.balance < home.costs.design)">
                        {{ busy ? 'بيعدّل…' : 'عدّل التصميم ✨' }}
                    </button>
                    <small class="sub" v-if="home"><span class="cr">{{ home.costs.design }} كريدت · </span>نسخة جديدة — والقديمة بتفضل في النسخ</small>
                </div>
            </div>
            <div class="st-actions">
                <a :href="result.url" download class="btn ghost sm"><?= ui_icon('image', 16) ?> تحميل</a>
                <button type="button" class="btn ghost sm" @click="again()" :disabled="busy || !lastReq">↻ جرّب تاني</button>
                <button type="button" class="btn ghost sm" @click="reset()">＋ تصميم جديد</button>
            </div>
        </div>

        <div class="card st-r-copy">
            <div class="pv-c" v-if="result.brief">
                <b class="st-r-title">النص المصاحب</b>
                <!-- v-for و v-if على عنصرين منفصلين: في petite-vue الـ v-if بيتقيّم قبل ما متغيّر اللوب يتعرّف -->
                <div class="pv-c" v-for="f in copyFields" :key="f.k">
                    <div class="st-copy" v-if="result.brief[f.k] || editingCopy === f.k">
                        <div class="lib-row"><span>{{ f.label }}</span>
                            <span class="st-copy-act" v-if="editingCopy !== f.k">
                                <button type="button" class="st-copybtn" @click="editingCopy = f.k; copyDraft = result.brief[f.k]" :aria-label="'تعديل ' + f.label">✎ تعديل</button>
                                <button type="button" class="st-copybtn" @click="copy(result.brief[f.k])">نسخ</button>
                            </span>
                        </div>
                        <p dir="auto" v-if="editingCopy !== f.k">{{ result.brief[f.k] }}</p>
                        <div class="pv-c" v-if="editingCopy === f.k">
                            <textarea class="textarea" rows="4" v-model="copyDraft" dir="auto"></textarea>
                            <div class="st-actions"><button type="button" class="btn sm" @click="saveCopy(f.k)">حفظ</button>
                                <button type="button" class="btn ghost sm" @click="editingCopy = ''">إلغاء</button></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pv-c" v-if="!result.brief">
                <b class="st-r-title">النص المصاحب</b>
                <p class="sub">التصميم ده اتعمل من غير Brief — تقدر تخلّي الـ AI يكتبله كابشن وهاشتاجات وCTA.</p>
                <button type="button" class="btn soft sm" @click="writeCopy()" :disabled="busy">اكتب الكابشن ✨ <small class="cr" v-if="home && home.costs.brief">· {{ home.costs.brief }} كريدت</small></button>
            </div>

            <div class="st-approve">
                <b class="st-r-title">📤 نشر / جدولة</b>

                <div class="st-done" v-if="pubClosed()">
                    ✓ {{ pubDone || lockedMsg() }}
                    <a :href="base + '/content-history.php?open=' + result.post_id">افتح في المحتويات ←</a>
                </div>

                <p class="sub" v-if="!pubClosed() && home && !home.publish.allowed">النشر التلقائي مش مفعّل لحسابك — احفظه في المحتويات وانشره من هناك.</p>
                <p class="sub" v-if="!pubClosed() && home && home.publish.allowed && !home.publish.pages.length">
                    اربط صفحتك علشان تنشر أو تجدول من هنا على طول. <a :href="base + '/social-accounts.php'">اربط صفحة ←</a></p>

                <div class="st-pub" v-if="!pubClosed() && home && home.publish.allowed && home.publish.pages.length">
                    <label class="st-lbl">الصفحة</label>
                    <div class="st-pages">
                        <button type="button" v-for="pg in home.publish.pages" :key="pg.id" :class="{ on: pub.page === pg.id }" @click="setPage(pg)">
                            <img v-if="pg.avatar" :src="pg.avatar" alt=""><span v-else>{{ pg.name.slice(0, 1) }}</span>
                            <b>{{ pg.name }}</b><small v-if="pg.ig">+ إنستجرام</small>
                        </button>
                    </div>
                    <label class="st-lbl">المنصة</label>
                    <div class="st-chips">
                        <button type="button" :class="{ on: pub.platform === 'facebook' }" @click="pub.platform = 'facebook'">فيسبوك</button>
                        <button type="button" v-if="curPage() && curPage().ig" :class="{ on: pub.platform === 'instagram' }" @click="pub.platform = 'instagram'">إنستجرام</button>
                        <button type="button" v-if="curPage() && curPage().ig" :class="{ on: pub.platform === 'both' }" @click="pub.platform = 'both'">الاتنين</button>
                    </div>
                    <label class="st-lbl">إمتى؟</label>
                    <div class="st-chips">
                        <button type="button" :class="{ on: pub.when === 'now' }" @click="pub.when = 'now'">🚀 دلوقتي</button>
                        <button type="button" :class="{ on: pub.when === 'schedule' }" @click="pub.when = 'schedule'">📅 في موعد</button>
                    </div>
                    <input v-if="pub.when === 'schedule'" type="datetime-local" class="input" v-model="pub.at" :min="nowLocal()" aria-label="موعد النشر">
                    <small class="sub" v-if="pub.when === 'schedule' && pubMode() === 'queue'">⏰ الموعد قريب — هيتنشر من المنصة في معاده بالظبط</small>
                    <button type="button" class="btn st-gen" @click="publish()" :disabled="busy || (pub.when === 'schedule' && !pub.at)">
                        {{ busy ? 'بننشر…' : (pub.when === 'now' ? 'انشر دلوقتي 🚀' : 'جدول المنشور 📅') }}
                    </button>
                </div>

                <button type="button" class="btn ghost sm" v-if="!pubClosed() && !result.post_id" @click="toPost()" :disabled="busy">💾 احفظه في المحتويات بس</button>
                <a v-if="!pubClosed() && result.post_id" :href="base + '/content-history.php?open=' + result.post_id" class="btn ghost sm">افتح المنشور في المحتويات ←</a>
            </div>
        </div>
    </section>

    <!-- آخر التصميمات -->
    <section class="st-recent" v-if="home && home.recent.length && step !== 'result'">
        <h3 class="dh-title">آخر تصميماتك</h3>
        <div class="st-recent-grid">
            <button type="button" v-for="r in home.recent" :key="r.id" class="card st-rc" @click="show(r)">
                <img :src="r.url" alt="" loading="lazy" @error="$event.target.style.visibility = 'hidden'">
                <span><b>{{ r.mode_label }}</b><small>{{ r.ago }}<span v-if="r.versions_n > 1"> · V{{ r.versions_n }}</span></small></span>
                <em v-if="r.post_id">منشور ✓</em>
            </button>
        </div>
    </section>

    <div class="toast-container" v-if="toast"><div class="toast" :class="toast.type">{{ toast.msg }}</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var base = document.querySelector('meta[name="app-base"]').content;
  var ic = function (d) { return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>'; };
  var files = {};   // ملفات الرفع بره الحالة التفاعلية (petite-vue مايحوّطش File في Proxy)

  var app = SpreadApp.reactive({
    base: base, home: null, step: 'method', method: '', busy: false, toast: null,
    text: '', purpose: '', brief: null, platform: 'facebook', ratio: '1:1', useBrand: true, styleRef: '',
    prev: { before: '', after: '', source: '' }, result: null, lastReq: null,
    cycle: ['الطريقة', 'Creative Brief', 'التصميم + الكابشن', 'نشر / جدولة'],
    platforms: [{ key: 'facebook', label: 'فيسبوك', ratio: '1:1' }, { key: 'instagram', label: 'إنستجرام', ratio: '4:5' },
                { key: 'story', label: 'ستوري / ريلز', ratio: '9:16' }],
    copyFields: [{ k: 'script', label: 'سكريبت الريل' }, { k: 'caption', label: 'Caption' }, { k: 'hashtags', label: 'Hashtags' }, { k: 'cta', label: 'CTA' },
                 { k: 'first_comment', label: 'First Comment' }, { k: 'ad_copy', label: 'Ad Copy' }],
    // احتياطي لو ترحيل ⑤-ب لسه ماتشغلش (الطرق بتيجي من الأدمن)
    fallbackMethods: [
      { key: 'before_after', kind: 'built_in', color: 'sunset', icon: 'split', badge: 'مميز', title: 'قبل / بعد', description: 'اعرض نتيجة حقيقية بتصميم احترافي — مثالي للعيادات والتجميل والعقارات.' },
      { key: 'from_image', kind: 'built_in', color: 'blue', icon: 'image', title: 'من صورة', description: 'ارفع صورة منتجك، والـ AI يبني التصميم حواليها.' },
      { key: 'free', kind: 'built_in', color: 'teal', icon: 'bulb', title: 'اكتب فكرتك', description: 'اكتب بالعربي زي ما بتتكلم، والباقي علينا.' },
      { key: 'pro', kind: 'built_in', color: 'dark', icon: 'code', title: 'Prompt احترافي', description: 'للمحترفين — والهوية بتتضاف للـ Prompt تلقائيًا.' },
    ],
    cur: { key: '', kind: 'built_in', purposes: [], uploads: [], text: { label: '' }, brief: true, ratio: '1:1', title: '' },
    typedSize: null, lastTyped: false, _sizeTimer: null,
    trends: [], trendsLoading: false, trendBrand: {}, trend: null, ideas: [], idea: null,
    editText: '', editingCopy: '', copyDraft: '', pubDone: '', _booted: false,
    // مشاريع لسه مكملتش (مسودات على السيرفر)
    drafts: [], draftId: null, _lastSnap: '', _draftSaving: false,
    pub: { page: 0, platform: 'facebook', when: 'now', at: '' },
    editChips: ['كبّر اللوجو', 'خلّي الخلفية أفتح', 'استخدم ألوان البراند أكتر', 'كبّر العنوان', 'بسّط التصميم'],

    notify: function (msg, type) { SpreadApp.toast(this, msg, type || 'success'); },
    methodList: function () {
      var list = (this.home && this.home.methods && this.home.methods.length) ? this.home.methods : this.fallbackMethods;
      return list.map(function (m) { return Object.assign({ purposes: [], uploads: [], text: { label: '' }, brief: true }, m, { soon: false }); });
    },
    iconSvg: function (k) {
      var d = { split: '<rect x="3" y="4" width="8" height="16" rx="2"/><rect x="13" y="4" width="8" height="16" rx="2"/>',
        image: '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>',
        bulb: '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.3h6c0-1 .4-1.8 1-2.3A7 7 0 0 0 12 2z"/>',
        code: '<path d="M8 9l-4 3 4 3"/><path d="M16 9l4 3-4 3"/><path d="M14 5l-4 14"/>',
        trend: '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        star: '<path d="M12 2l3 6.3 7 1-5 4.8 1.2 6.9L12 17.8 5.8 21l1.2-6.9-5-4.8 7-1z"/>',
        sparkles: '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/>',
        megaphone: '<path d="M3 11l18-8v18l-18-8z"/><path d="M7 13v5a2 2 0 0 0 4 0v-3"/>',
        gift: '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        doc: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        calendar: '<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>' }[k] || '';
      return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>';
    },
    // المقاس المكتوب: بيتفحص وهو بيكتب (من غير AI) — ولو فيه، هو الأساسي
    sizeCheck: function () {
      var self = this; clearTimeout(this._sizeTimer);
      this._sizeTimer = setTimeout(async function () {
        var t = self.text.trim();
        if (t.length < 3) { self.typedSize = null; return; }
        var r = await SpreadAPI.get('studio', { action: 'size_hint', text: t });
        self.typedSize = r.ok ? r.size : null;
      }, 450);
    },
    cycleIndex: function () { if (this.pubClosed()) return 3; return { method: 0, input: 0, brief: 1, result: 2 }[this.step] || 0; },
    async load() {
      var r = await SpreadAPI.get('studio', { action: 'home' });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.home = r;
      this.drafts = r.drafts || [];
      // ?style_ref= (جاي من معرض الإلهام) — أول تحميل بس. load() بتتنادى بعد كل توليد لتحديث الرصيد،
      // ولو اتنفّذ تاني كان هيرجّع الشاشة لخطوة الإدخال بدل ما يعرض النتيجة
      if (this._booted) return;
      this._booted = true;
      var q = new URLSearchParams(location.search);
      var free = this.methodList().find(function (m) { return m.key === 'free'; });
      if (q.get('style_ref') && free) { this.styleRef = q.get('style_ref'); this.pick(free); }
    },
    async loadTrends() {
      this.trendsLoading = true;
      var r = await SpreadAPI.get('studio', { action: 'trends' });
      this.trendsLoading = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.trends = r.trends; this.trendBrand = r.brand;
    },
    openTrend: function (t) { this.trend = t; this.ideas = []; this.idea = null; window.scrollTo({ top: 0, behavior: 'smooth' }); },
    async trendIdeas() {
      this.busy = true; this.idea = null;
      if (window.SpreadThinking) SpreadThinking.start({ title: 'بفصّل الترند على براندك', steps: ['بقرا هيكل الترند', 'بقرا هوية البراند', 'بطلع 3 أفكار بنفس الهيكل', 'بكتب الكابشن'] });
      var r = await SpreadAPI.post('studio', 'trend_ideas', { trend_id: this.trend.id });
      this.busy = false;
      if (!r.ok) { if (window.SpreadThinking) SpreadThinking.fail(); return this.notify(r.error, 'danger'); }
      if (window.SpreadThinking) SpreadThinking.done('3 أفكار جاهزة ✓');
      this.ideas = r.ideas; this.home.costs.balance = r.balance;
    },
    useIdea: function () {
      var it = this.idea, t = this.trend;
      this.brief = { idea: it.desc, visual: '', headline: it.headline, sub: '', cta: it.cta, caption: it.caption,
                     hashtags: it.hashtags, script: it.script || '', first_comment: '', ad_copy: '', goal: '', audience: '' };
      // المقاس حسب نوع الترند (والمكتوب لسه الأساسي)
      var want = { Reel: '9:16', Carousel: '4:5' }[t.type] || '1:1';
      if (this.home.ratios.some(function (r) { return r.key === want; })) this.ratio = want;
      this.platform = t.type === 'Reel' ? 'story' : (t.platforms.indexOf('Facebook') >= 0 ? 'facebook' : 'instagram');
      this.step = 'brief';
    },
    /* ── مشاريع لسه مكملتش ── */
    methodTitle: function (key) {
      var m = this.methodList().find(function (x) { return x.key === key; });
      return m ? m.title : (key === 'trend' ? 'Trending' : 'تصميم');
    },
    snapshot: function () {
      var self = this;
      return { method: this.method, curKey: this.cur.key, step: this.step, text: this.text, purpose: this.purpose,
               brief: this.brief, platform: this.platform, ratio: this.ratio, useBrand: this.useBrand, styleRef: this.styleRef,
               trendId: this.trend ? this.trend.id : null, ideas: this.ideas,
               ideaIdx: this.idea ? this.ideas.indexOf(this.idea) : -1,
               resultId: this.result ? this.result.id : null, lastReq: !!this.lastReq,
               hasFiles: Object.keys(this.prev).some(function (k) { return !!self.prev[k]; }) };
    },
    draftTitle: function () {
      var b = this.brief || {}, t = (b.headline || this.text || (this.idea && this.idea.title) || this.purpose || '').trim();
      t = t.replace(/\s+/g, ' ');
      return (t ? t.slice(0, 70) : (this.cur.title || 'مشروع تصميم'));
    },
    // مشروع فيه حاجة تستاهل تتحفظ (مش مجرد ضغطة على طريقة)
    draftWorth: function (sn) { return sn.step !== 'method' && !!sn.method && (sn.text.trim().length > 2 || sn.brief || sn.hasFiles || sn.resultId || sn.ideas.length || sn.purpose); },
    async saveDraft(opts) {
      // التصميم اتحوّل منشور = المشروع خلص (السيرفر مسح مسودته) — مانرجعش نعمله تاني
      if (this.step === 'result' && this.result && this.result.post_id) return;
      var sn = this.snapshot();
      if (!this.draftWorth(sn) && !(opts && opts.force)) return;
      var snap = JSON.stringify(sn);
      if (snap === this._lastSnap && this.draftId && !(opts && opts.force)) return;
      if (this._draftSaving) return;
      this._draftSaving = true;
      var r = await SpreadAPI.post('studio', 'draft_save', { id: this.draftId || 0, title: this.draftTitle(), method: this.cur.key || this.method,
        step: sn.step, design_id: sn.resultId || 0, state: sn }, opts && opts.keepalive ? { keepalive: true } : undefined);
      this._draftSaving = false;
      if (r && r.ok) { this.draftId = r.id; this._lastSnap = snap; }
    },
    async uploadDraftFile(slot, f) {
      if (!this.draftId) await this.saveDraft({ force: true });
      if (!this.draftId) return;
      var fd = new FormData();
      fd.append('action', 'draft_file'); fd.append('id', this.draftId); fd.append('slot', slot); fd.append('file', f);
      try {
        await fetch(this.base + '/api/studio.php', { method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content, 'X-Requested-With': 'XMLHttpRequest' } });
      } catch (e) { /* الصورة لسه معانا محليًا للتوليد — بس مش هترجع لو خرج */ }
    },
    async refreshDrafts() {
      var r = await SpreadAPI.get('studio', { action: 'drafts' });
      if (r.ok) this.drafts = r.drafts;
    },
    async toMethods() {
      await this.saveDraft();
      this.step = 'method';
      this.refreshDrafts();
    },
    async resume(dr) {
      this.busy = true;
      var r = await SpreadAPI.get('studio', { action: 'draft', id: dr.id });
      this.busy = false;
      if (!r.ok) { this.refreshDrafts(); return this.notify(r.error, 'danger'); }
      var st = r.draft.state || {};
      var m = this.methodList().find(function (x) { return x.key === st.curKey; });
      if (!m) return this.notify('الطريقة دي مابقتش متاحة', 'warning');
      this.pick(m);
      this.draftId = dr.id;
      files = {};
      this.prev = Object.assign({ before: '', after: '', source: '' }, r.draft.files || {});
      this.text = st.text || ''; this.purpose = st.purpose || ''; this.brief = st.brief || null;
      this.platform = st.platform || this.platform; this.ratio = st.ratio || this.ratio;
      this.useBrand = st.useBrand !== false; this.styleRef = st.styleRef || '';
      if (st.method === 'trend' && st.trendId) {
        if (!this.trends.length) await this.loadTrends();
        var t = this.trends.find(function (x) { return x.id === st.trendId; });
        if (t) { this.trend = t; this.ideas = st.ideas || []; this.idea = st.ideaIdx >= 0 ? this.ideas[st.ideaIdx] || null : null; }
      }
      if (this.text) this.sizeCheck();
      if (st.step === 'result' && r.draft.design_id) {
        await this.openDesign(r.draft.design_id);
      } else {
        this.step = st.step === 'brief' ? 'brief' : 'input';
      }
      this._lastSnap = JSON.stringify(this.snapshot());
      this.notify('رجعنا لمشروعك — كمّل من مكان ما وقفت ✓');
    },
    async removeDraft(dr) {
      if (!confirm('تمسح المشروع ده؟ التصميمات اللي اتعملت فيه هتفضل في «آخر تصميماتك».')) return;
      var r = await SpreadAPI.post('studio', 'draft_delete', { id: dr.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.drafts = this.drafts.filter(function (x) { return x.id !== dr.id; });
      if (this.draftId === dr.id) this.draftId = null;
    },
    pick: function (m) {
      if (m.soon) return;
      this.draftId = null; this._lastSnap = '';   // طريقة جديدة = مشروع جديد
      this.cur = m; this.method = m.kind === 'custom' ? 'custom' : m.key;
      this.step = 'input'; this.brief = null; this.text = ''; this.purpose = ''; this.typedSize = null;
      this.trend = null; this.ideas = []; this.idea = null;
      if (m.key === 'trend' && !this.trends.length) this.loadTrends();
      if (m.ratio && this.home && this.home.ratios.some(function (r) { return r.key === m.ratio; })) this.ratio = m.ratio;
    },
    setFile: function (slot, e) {
      var f = e.target.files && e.target.files[0]; if (!f) return;
      if (f.size > 8 * 1024 * 1024) return this.notify('الصورة أكبر من 8 ميجا', 'danger');
      files[slot] = f; this.prev[slot] = URL.createObjectURL(f);
      this.uploadDraftFile(slot, f);   // الصورة بتتحفظ مع المشروع — لو خرج ورجع يلاقيها
    },
    setPlatform: function (p) { this.platform = p.key; this.ratio = p.ratio; },
    // بيقرا من prev (تفاعلي) مش من files (بره الحالة عن قصد) — وإلا الزرار مايتفتحش بعد الرفع
    canBrief: function () {
      if (this.method === 'before_after') return !!(this.prev.before && this.prev.after);
      if (this.method === 'from_image') return !!this.prev.source && (!!this.purpose || this.text.trim().length > 3);
      if (this.method === 'custom') {
        var self = this, c = this.cur;
        var has = function (i) { return !!self.prev['c' + i]; };
        var requiredOk = c.uploads.every(function (u, i) { return !u.required || has(i); })   // ① الصور الإجبارية
                      && (!c.text.required || this.text.trim().length > 2);                  // ② النص لو إجباري
        var somethingGiven = this.text.trim().length > 2 || !!this.purpose || c.uploads.some(function (u, i) { return has(i); });  // ③ حاجة واحدة على الأقل
        return requiredOk && somethingGiven;
      }
      return this.text.trim().length > 4;
    },
    async makeBrief() {
      this.busy = true;
      if (window.SpreadThinking) SpreadThinking.start({ title: 'بفهم فكرتك', steps: ['بقرا طلبك', 'بقرا هوية البراند', 'بكتب الفكرة والاتجاه البصري', 'بكتب الكابشن والـ CTA'] });
      var r = await SpreadAPI.post('studio', 'brief', { method: this.method === 'custom' ? 'free' : this.method, text: (this.cur.kind === 'custom' ? this.cur.title + ': ' : '') + this.text.trim(),
        purpose: this.method === 'before_after' ? 'قبل وبعد — نتيجة حقيقية' : this.purpose, platform: this.platform });
      this.busy = false;
      if (!r.ok) { if (window.SpreadThinking) SpreadThinking.fail(); return this.notify(r.error, 'danger'); }
      if (window.SpreadThinking) SpreadThinking.done('الـ Brief جاهز ✓');
      this.brief = r.brief; this.home.costs.balance = r.balance; this.step = 'brief';
      this.saveDraft({ force: true });   // الـ Brief اتدفع فيه — مايضيعش لو خرج
    },
    b64: function (s) {
      var bytes = new TextEncoder().encode(s), bin = '';
      for (var i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
      return btoa(bin);
    },
    concept: function () {
      var b = this.brief;
      if (!b) return (this.method === 'from_image' || this.method === 'custom') ? ((this.purpose ? this.purpose + '. ' : '') + this.text) : this.text;
      var t = (b.idea || '') + (b.visual ? '\nالاتجاه البصري: ' + b.visual : '');
      var words = [b.headline && '«' + b.headline + '»', b.sub && '«' + b.sub + '»', b.cta && 'زرار «' + b.cta + '»'].filter(Boolean);
      if (words.length) t += '\nالنص الظاهر على التصميم بالعربي (اكتبه بالظبط كده): ' + words.join(' — ');
      return t.trim();
    },
    async generate() {
      var mode = { before_after: 'before_after', from_image: 'from_image', free: 'free', pro: 'free', custom: 'custom', trend: 'trend' }[this.method];
      var text = this.concept();
      var req = { csrf: document.querySelector('meta[name="csrf-token"]').content, action: 'generate', mode: mode,
                  ratio: this.ratio, include_logo: this.useBrand ? '1' : '0', style_ref: this.styleRef || '' };
      var field = mode === 'free' ? 'free_prompt' : 'design_idea';
      req[field] = this.b64(text);
      // كلام العميل الأصلي — المقاس اللي كتبه بيتقري منه (الـ Brief ممكن مايكونش فيه)
      req.user_text = this.b64(this.text.trim());
      req._b64 = field + ',user_text';
      if (mode === 'trend') req.trend_id = this.trend.id;
      if (mode === 'custom') {
        req.method = this.cur.key; req.purpose = this.purpose || '';
        var self = this;
        this.cur.uploads.forEach(function (u, i) { if (files['c' + i]) req['custom_image_' + i] = files['c' + i]; });
      }
      if (this.method === 'before_after') { if (files.before) req.before_image = files.before; if (files.after) req.after_image = files.after; }
      if (this.method === 'from_image' && files.source) { req.source_image = files.source; }
      // مشروع اتكمّل بعد رجوع: الصور اللي مش معانا محليًا بتتاخد من المسودة على السيرفر
      if (this.draftId) req.draft_id = this.draftId;
      this.lastReq = { req: req, brief: this.brief ? JSON.parse(JSON.stringify(this.brief)) : null };
      await this.send(this.lastReq);
    },
    async send(job) {
      this.busy = true;
      // «Spread AI يفكر» بيظهر لوحده — متعلّق على ajaxPost لطلبات التصميم
      var r = await ajaxPost(this.base + '/ajax/studio-design.php', job.req);
      if (!r || !r.ok) { this.busy = false; return this.notify((r && r.error) || 'تعذّر التصميم', 'danger'); }
      this.lastTyped = !!r.ratio_typed;
      if (job.brief) await SpreadAPI.post('studio', 'save_brief', { id: r.design_id, brief: job.brief });
      this.busy = false;
      await this.openDesign(r.design_id);
      this.saveDraft({ force: true });   // المشروع دلوقتي «التصميم جاهز — فاضل النشر»
      this.load();   // تحديث الرصيد وآخر التصميمات
    },
    async again() { if (this.lastReq) await this.send(this.lastReq); },
    async writeCopy() {
      this.busy = true;
      var r = await SpreadAPI.post('studio', 'brief', { method: this.result.mode === 'before_after' ? 'before_after' : 'free',
        text: this.lastReq ? this.concept() : ('تصميم ' + this.result.mode_label), platform: this.platform });
      if (!r.ok) { this.busy = false; return this.notify(r.error, 'danger'); }
      await SpreadAPI.post('studio', 'save_brief', { id: this.result.id, brief: r.brief });
      this.busy = false; this.result.brief = r.brief; this.home.costs.balance = r.balance;
    },
    async toPost() {
      this.busy = true;
      var cid = await this.ensurePost();
      this.busy = false;
      if (cid) { this.notify('اتحفظ في المحتويات ✓'); this.draftId = null; this.refreshDrafts(); }
    },
    show: function (r) { this.lastReq = null; this.draftId = null; this._lastSnap = ''; this.openDesign(r.id); },
    // التصميم بنسخه (V1 · V2 …)
    async openDesign(id) {
      var d = await SpreadAPI.get('studio', { action: 'design', id: id });
      if (!d.ok) return this.notify(d.error, 'danger');
      this.result = d.design; this.step = 'result'; this.pubDone = ''; this.editingCopy = '';
      this.initPub();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    initPub: function () {
      var p = this.home && this.home.publish;
      if (!p || !p.pages.length) return;
      var self = this;
      if (!p.pages.some(function (x) { return x.id === self.pub.page; })) this.pub.page = p.pages[0].id;
      if (!this.pub.at || new Date(this.pub.at).getTime() < Date.now()) this.pub.at = p.default;
      // المنصة من اختياره في الـ Brief (ستوري/إنستجرام ← إنستجرام لو الصفحة مربوط بيها)
      var pg = this.curPage();
      this.pub.platform = (this.platform === 'story' || this.platform === 'instagram') && pg && pg.ig ? 'instagram' : 'facebook';
    },
    curPage: function () {
      var id = this.pub.page;
      return this.home && this.home.publish ? this.home.publish.pages.find(function (x) { return x.id === id; }) : null;
    },
    setPage: function (pg) { this.pub.page = pg.id; if (!pg.ig) this.pub.platform = 'facebook'; },
    postLocked: function () { return !!this.result && ['published', 'scheduled', 'processing'].indexOf(this.result.post_status || '') >= 0; },
    pubClosed: function () { return !!this.pubDone || this.postLocked(); },
    lockedMsg: function () {
      return { published: 'المنشور ده اتنشر خلاص', scheduled: 'المنشور ده متجدول على فيسبوك', processing: 'المنشور ده بيتنشر دلوقتي' }[this.result && this.result.post_status] || '';
    },
    nowLocal: function () { var d = new Date(Date.now() - new Date().getTimezoneOffset() * 60000); return d.toISOString().slice(0, 16); },
    async editDesign() {
      var txt = this.editText.trim();
      var req = { csrf: document.querySelector('meta[name="csrf-token"]').content, action: 'generate', mode: 'free',
                  design_edit: '1', edit_of: this.result.id, include_logo: '1', ratio: this.result.ratio,
                  free_prompt: this.b64(txt), user_text: this.b64(txt), _b64: 'free_prompt,user_text' };
      this.busy = true;
      var r = await ajaxPost(this.base + '/ajax/studio-design.php', req);
      this.busy = false;
      if (!r || !r.ok) return this.notify((r && r.error) || 'تعذّر التعديل', 'danger');
      this.editText = ''; this.lastTyped = !!r.ratio_typed; this.lastReq = null;
      await this.openDesign(r.design_id);
      this.load();
      this.notify('النسخة الجديدة جاهزة ✓ — والقديمة موجودة في النسخ');
    },
    async saveCopy(k) {
      this.result.brief[k] = this.copyDraft;
      var r = await SpreadAPI.post('studio', 'save_brief', { id: this.result.id, brief: this.result.brief });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.editingCopy = ''; this.notify('اتحفظ ✓');
    },
    // المنشور في المحتويات (بيتعمل مرة واحدة — أو بيتحدّث لو موجود)
    async ensurePost() {
      var b = this.result.brief || {};
      var plat = this.pub.page ? this.pub.platform : (this.platform === 'story' ? 'instagram' : this.platform);
      var r = await SpreadAPI.post('studio', 'to_post', { id: this.result.id, caption: b.caption || '', hashtags: b.hashtags || '', cta: b.cta || '', platform: plat });
      if (!r.ok) { this.notify(r.error, 'danger'); return 0; }
      this.result.post_id = r.content_id;
      if (r.existing) this.result.post_status = r.post_status || this.result.post_status || '';
      return r.content_id;
    },
    // «في موعد»: فيسبوك مابيقبلش جدولة أقرب من 10 دقايق (كان بينشر فورًا من غير ما يقول) —
    // المواعيد القريبة بتتحجز على المنصة والكرون بينشرها في معادها بالظبط
    pubMode: function () {
      if (this.pub.when === 'now') return 'now';
      var mins = (new Date(this.pub.at).getTime() - Date.now()) / 60000;
      return mins < 11 ? 'queue' : 'schedule';
    },
    async publish() {
      var b = this.result.brief || {};
      if (!(b.caption || '').trim() && !confirm('المنشور مفيهوش كابشن — تنشر الصورة لوحدها؟')) return;
      this.busy = true;
      var cid = await this.ensurePost();
      if (!cid) { this.busy = false; return; }
      // اتسلّم لفيسبوك من مكان تاني (المحتويات مثلًا) — إعادة النشر هنا كانت هتعمل بوست مكرر
      if (this.postLocked()) { this.busy = false; return this.notify(this.lockedMsg(), 'warning'); }
      // نفس مسار النشر في المحتويات: التوكنات · المحاولات · جدولة فيسبوك نفسه · الكرون
      var r = await ajaxPost(this.base + '/ajax/publish-direct.php', {
        csrf: document.querySelector('meta[name="csrf-token"]').content, content_id: cid, connection_id: this.pub.page,
        platform: this.pub.platform, mode: this.pubMode(),
        scheduled_at: this.pub.when === 'schedule' ? this.pub.at : '',
      });
      this.busy = false;
      if (!r || !r.ok) return this.notify((r && r.error) || 'تعذّر النشر', 'danger');
      this.pubDone = r.msg || (this.pub.when === 'now' ? 'اتنشر' : 'اتجدول');
      this.draftId = null;   // المشروع خلص (اتمسح من «لسه مكملتش» على السيرفر مع to_post)
      // التصميم الجاي يبدأ من «دلوقتي» — مايورثش موعد المنشور اللي فات (كان بيتحجز بيه من غير ما ياخد باله)
      this.pub.when = 'now'; this.pub.at = '';
      this.load();
    },
    reset: function () {
      if (this.draftId) this.saveDraft();
      this.draftId = null; this._lastSnap = '';
      this.refreshDrafts();
      this.step = 'method'; this.method = ''; this.brief = null; this.result = null; this.text = ''; this.purpose = ''; this.styleRef = '';
      files = {}; this.prev = { before: '', after: '', source: '' }; this.typedSize = null; this.pubDone = ''; this.editText = '';
    },
    copy: function (t) {
      var self = this;
      (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(
        function () { self.notify('اتنسخ ✓'); }, function () { self.notify('انسخ يدويًا — المتصفح مانع النسخ', 'info'); });
    },
  });

  SpreadApp.mount('#st', app);
  app.load();
  // حفظ تلقائي للمشروع كل كام ثانية (لو فيه تغيير) + قبل ما يقفل الصفحة
  setInterval(function () { if (!app.busy) app.saveDraft(); }, 4000);
  window.addEventListener('pagehide', function () { app.saveDraft({ keepalive: true }); });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') app.saveDraft({ keepalive: true }); });
});
</script>
