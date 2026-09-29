<?php
/**
 * Spread AI v2 — البحث العميق (المرحلة ⑦-ب) — petite-vue
 * زي التصميم (Research / ResearchDesktop): البداية ← النطاق ← الخطة ← التنفيذ ← النتائج (10 أقسام) ← السجل
 *
 * ⚠️ ممنوع <template v-if> — استخدم span.pv-c · وممنوع v-for و v-if على نفس العنصر
 * متغيرات: $rsInit
 */

/** أرقام المصادر [n] لمعلومة (المستبعد بيختفي — ولو مفيش: «استنتاج AI») */
function rs_cites(string $expr): string
{
    return '<span class="rs-cites"><a v-for="s in cs(' . $expr . ')" :key="s.n" class="rs-cite" :href="s.url" target="_blank" rel="noopener nofollow noreferrer" :title="s.site">[{{ s.n }}]</a>'
        . '<em class="rs-ai" v-if="!cs(' . $expr . ').length">استنتاج AI</em></span>';
}
?>
<div id="rs" class="rs" v-cloak>
    <header class="rs-head">
        <button type="button" class="cf-iconbtn rs-back" v-if="phase !== 'start'" @click="back()" aria-label="رجوع"><?= ui_icon('arrow', 20) ?></button>
        <div class="rs-ttl">
            <h2>البحث العميق 🔬</h2>
            <p>خلّي Spread AI يبحث ويحلل السوق والمنافسين والجمهور والمحتوى بدلًا منك.</p>
        </div>
        <div class="rs-head-act">
            <button type="button" class="cf-btn ghost sm" :class="{ on: phase === 'history' && !savedOnly }" @click="goHistory(false)">
                <?= ui_icon('clock', 16) ?> <span class="rs-hide-m">سجل الأبحاث</span></button>
            <button type="button" class="cf-btn ghost sm" :class="{ on: phase === 'history' && savedOnly }" @click="goHistory(true)">
                <?= ui_icon('bookmark', 16) ?> <span class="rs-hide-m">المحفوظة</span></button>
            <button type="button" class="cf-btn ghost sm rs-q" @click="help = !help" :aria-expanded="help ? 'true' : 'false'" aria-label="مساعدة">?</button>
        </div>
    </header>

    <!-- المساعدة -->
    <section class="cf-card rs-help sx-in" v-if="help || (phase === 'start' && !q)">
        <b><span class="cf-orb sm"><i></i></span> مش عارف تعمل بحث عن إيه؟</b>
        <p>{{ helpLine() }}</p>
        <div class="cf-chips sm">
            <button type="button" v-for="s in suggest" :key="s.t" @click="useSuggest(s)">{{ s.t }}</button>
        </div>
    </section>

    <!-- المحرك مش متاح -->
    <div class="cf-card rs-warn" v-if="!engine.ok && phase !== 'history' && phase !== 'results'">⚠️ {{ engine.msg }}</div>

    <!-- ═══ ① البداية ═══ -->
    <div class="pv-c" v-if="phase === 'start'">
        <section class="cf-card rs-hero">
            <div class="rs-hero-top">
                <div>
                    <h3>إيه اللي عايز تعرفه؟</h3>
                    <p>اكتب سؤالك بطريقتك، وأنا هحدد نطاق البحث والمصادر والمحاور المناسبة.</p>
                </div>
                <span class="rs-bot" aria-hidden="true"><i></i><i></i><b></b></span>
            </div>
            <textarea class="cf-input" rows="4" v-model="q" maxlength="1500" aria-label="سؤال البحث"
                      placeholder="مثال: اعمل بحث شامل عن السوق المصري لعيادات الأسنان: المنافسين، الجمهور، أنواع المحتوى الجذاب، وأفضل الفرص التسويقية."></textarea>
            <div class="rs-end"><button type="button" class="cf-btn primary" @click="toScope()">كمّل ✨</button></div>
        </section>

        <b class="rs-lbl">أو اختر نوع البحث</b>
        <div class="rs-types">
            <button type="button" class="rs-type" v-for="t in types" :key="t.k" :class="{ on: type === t.k }" @click="pickType(t.k)">
                <span class="rs-type-e">{{ t.e }}</span><b>{{ t.t }}</b><small>{{ t.d }}</small>
            </button>
        </div>
        <p class="cf-muted rs-count" v-if="count > 0"><button type="button" class="cf-link" @click="goHistory(false)">عندك {{ count }} {{ count === 1 ? 'بحث' : 'أبحاث' }} في السجل ←</button></p>
    </div>

    <!-- ═══ ② النطاق ═══ -->
    <div class="pv-c" v-if="phase === 'scope'">
        <span class="cf-chip dark rs-typechip">{{ typeOf().e }} {{ typeOf().t }}</span>
        <h3 class="rs-h">خلينا نحدد البحث</h3>
        <section class="cf-card rs-scope">
            <div class="rs-scope-grid">
                <div><b class="cf-lbl">السوق</b>
                    <div class="cf-chips sm"><button type="button" v-for="m in markets" :key="m.t" :class="{ on: scope.market === m.t }" :aria-pressed="scope.market === m.t ? 'true' : 'false'" @click="setMarket(m.t)">{{ m.t }}</button></div></div>
                <div><b class="cf-lbl">المدينة / المنطقة</b>
                    <div class="cf-chips sm"><button type="button" v-for="c in cities()" :key="c" :class="{ on: scope.city === c }" :aria-pressed="scope.city === c ? 'true' : 'false'" @click="scope.city = c">{{ c }}</button></div></div>
                <div><b class="cf-lbl">الفترة</b>
                    <div class="cf-chips sm"><button type="button" v-for="p in periods" :key="p" :class="{ on: scope.period === p }" :aria-pressed="scope.period === p ? 'true' : 'false'" @click="scope.period = p">{{ p }}</button></div></div>
                <div><b class="cf-lbl">اللغة</b>
                    <div class="cf-chips sm"><button type="button" v-for="l in langs" :key="l" :class="{ on: scope.lang === l }" :aria-pressed="scope.lang === l ? 'true' : 'false'" @click="scope.lang = l">{{ l }}</button></div></div>
            </div>
            <b class="cf-lbl">عمق البحث</b>
            <div class="cf-seg rs-depth">
                <button type="button" v-for="d in depths" :key="d.k" :class="{ on: scope.depth === d.k, deep: d.k === 'deep' }" :aria-pressed="scope.depth === d.k ? 'true' : 'false'" @click="scope.depth = d.k">
                    {{ d.t }} <small class="cr">{{ d.cost }} كريدت</small></button>
            </div>
            <p class="cf-muted">البحث العميق بيقرا مصادر أكتر وبياخد وقت أطول، ونتيجته أدق.</p>
            <b class="cf-lbl">اختياري</b>
            <div class="cf-chips sm">
                <button type="button" :class="{ on: scope.opt_comp }" @click="scope.opt_comp = !scope.opt_comp">مقارنة المنافسين: {{ scope.opt_comp ? 'نعم' : 'لا' }}</button>
                <button type="button" :class="{ on: scope.opt_content }" @click="scope.opt_content = !scope.opt_content">تحليل المحتوى: {{ scope.opt_content ? 'نعم' : 'لا' }}</button>
                <button type="button" :class="{ on: scope.opt_price }" @click="scope.opt_price = !scope.opt_price">تحليل الأسعار: {{ scope.opt_price ? 'نعم' : 'لا' }}</button>
            </div>
        </section>
        <section class="cf-card rs-qcard">
            <b class="cf-lbl">سؤال البحث</b>
            <textarea class="cf-input" rows="3" v-model="q" maxlength="1500" aria-label="سؤال البحث"></textarea>
        </section>
        <div class="cf-think-pill rs-planning" v-if="busy"><span class="cf-orb sm"><i></i></span> Spread AI يجهّز خطة البحث...</div>
        <button type="button" class="cf-btn primary rs-cta" v-if="!busy" @click="toPlan()">اعمل خطة البحث ✨</button>
    </div>

    <!-- ═══ ③ الخطة ═══ -->
    <div class="pv-c" v-if="phase === 'plan' && r">
        <h3 class="rs-h">Spread AI فهم طلبك كالتالي:</h3>
        <section class="cf-card rs-plan">
            <blockquote class="rs-quote">«{{ r.question }}»</blockquote>
            <div class="cf-row">
                <span class="cf-chip blue">{{ r.scope.market }} · {{ r.scope.city }}</span>
                <span class="cf-chip blue">{{ r.scope.period }}</span>
                <span class="cf-chip blue">{{ r.scope.lang }}</span>
                <span class="cf-chip dark">بحث {{ r.depth_t }}</span>
            </div>
            <b class="cf-lbl">أهداف البحث</b>
            <ul class="rs-objs"><li v-for="o in r.objectives" :key="o"><span class="rs-ok"><?= ui_icon('check', 12) ?></span>{{ o }}</li></ul>
            <b class="cf-lbl">مصادر البحث المقترحة</b>
            <div class="cf-chips sm">
                <button type="button" v-for="k in kinds" :key="k.k" :class="{ on: scope.src_off.indexOf(k.k) < 0 }" :aria-pressed="scope.src_off.indexOf(k.k) < 0 ? 'true' : 'false'" @click="toggleKind(k.k)">{{ k.e }} {{ k.t }}</button>
            </div>
            <p class="cf-muted">اضغط على أي مصدر علشان تشيله من البحث.</p>
            <div class="rs-failed" v-if="r.status === 'failed' && r.error">⚠️ {{ r.error }}</div>
        </section>
        <p class="cf-cost cr">هيتخصم {{ r.cost }} كريدت · رصيدك {{ balance }}<span v-if="balance < r.cost"> — <a :href="base + '/credits.php'">اشحن رصيدك</a></span></p>
        <p class="cf-cost cr-alt">البحث بيتحسب من حصة «الأبحاث» في باقة الشهر<span v-if="balance < r.cost"> — باقتك خلصت، <a :href="base + '/credits.php'">رقّي باقتك</a></span></p>
        <div class="rs-row">
            <button type="button" class="cf-btn primary rs-cta" :disabled="busy || !engine.ok || balance < r.cost || null" @click="start()">{{ r.status === 'failed' ? 'جرّب البحث تاني 🔬' : 'ابدأ البحث العميق 🔬' }}</button>
            <button type="button" class="cf-btn ghost" @click="phase = 'scope'">تعديل خطة البحث</button>
        </div>
    </div>

    <!-- ═══ ④ التنفيذ ═══ -->
    <div class="pv-c" v-if="phase === 'running' && r">
        <section class="cf-card rs-run">
            <span class="rs-radar" aria-hidden="true"><i></i><i></i><b></b></span>
            <h3>Spread AI بيعمل البحث...</h3>
            <p class="rs-act" aria-live="polite">{{ r.activity || 'يجهّز النتيجة...' }}</p>
            <div class="cf-shimmer"></div>
            <small class="cf-muted" v-if="r.sources.length">لقى {{ r.sources.length }} مصدر لحد دلوقتي</small>
        </section>
        <section class="cf-card rs-steps">
            <ol>
                <li v-for="(s, i) in r.steps" :key="i" :class="{ done: s.done && !s.failed, now: s.now, failed: s.failed }">
                    <i><span v-if="s.done && !s.failed"><?= ui_icon('check', 12) ?></span><span v-if="s.failed">!</span></i>{{ s.t }}<small v-if="s.failed"> — مالقيناش نتايج هنا، كمّلنا بالباقي</small>
                </li>
            </ol>
            <p class="cf-muted">مش بنعرض نسبة مئوية، لأن مدة البحث بتختلف حسب عدد المصادر وسرعتها. تقدر تسيب الصفحة وترجعلها من السجل.</p>
            <div class="rs-row" v-if="stalled">
                <button type="button" class="cf-btn primary" @click="run()">كمّل البحث</button>
                <span class="cf-muted">{{ stalled }}</span>
            </div>
        </section>
    </div>

    <!-- ═══ ⑤ النتائج ═══ -->
    <div class="pv-c" v-if="phase === 'results' && r && r.result">
        <section class="cf-card rs-rhead">
            <div class="rs-rhead-top">
                <div class="rs-grow">
                    <small class="cf-muted">نتائج البحث</small>
                    <h3>{{ r.title }}</h3>
                    <div class="cf-row">
                        <span class="cf-chip grey">{{ r.date }}</span>
                        <span class="cf-chip dark">بحث {{ r.depth_t }}</span>
                        <span class="cf-chip blue">{{ activeSrc() }} مصادر</span>
                        <span class="cf-chip ok" v-if="r.age_days === 0">تم التحديث منذ لحظات</span>
                        <span class="cf-chip warn" v-if="r.age_days > 30">آخر تحديث منذ {{ r.age_days }} يومًا</span>
                    </div>
                </div>
                <div class="rs-rhead-act">
                    <button type="button" class="cf-btn ghost sm" @click="toggleSave()" :aria-pressed="r.saved ? 'true' : 'false'">{{ r.saved ? '✓ محفوظ' : 'احفظ البحث' }}</button>
                    <button type="button" class="cf-btn soft sm" :disabled="busy || !engine.ok || null" @click="refresh()">تحديث البحث 🔄 <small class="cr">{{ r.cost }} كريدت</small></button>
                </div>
            </div>
            <div class="rs-changes" v-if="r.changes">✨ بعد التحديث: {{ r.changes.facts }} معلومات جديدة · {{ r.changes.competitors }} منافسين جدد · {{ r.changes.trends }} اتجاهات جديدة</div>
            <div class="rs-failed" v-if="r.error">{{ r.error }}</div>
            <p class="rs-note">كل معلومة جنبها رقم مصدرها [n] وبيفتح المصدر نفسه. اللي من غير مصدر مكتوب عليه «استنتاج AI» — ومفيش أسعار أو أرقام متألفة.</p>
        </section>

        <div class="rs-res">
            <nav class="rs-nav" aria-label="أقسام البحث">
                <button type="button" v-for="s in sections" :key="s[0]" :class="{ on: section === s[0] }" :aria-current="section === s[0] ? 'true' : null" @click="go(s[0])">{{ s[1] }}</button>
            </nav>

            <div class="rs-body">
                <!-- نظرة عامة -->
                <section class="cf-card rs-sec" v-if="section === 'overview'">
                    <h4>الملخص التنفيذي</h4>
                    <div class="rs-sum">
                        <div v-for="k in sumKeys" :key="k.k" :class="'rs-sum-' + k.k">
                            <b>{{ k.t }}</b>
                            <p>{{ res().summary[k.k].t || '—' }} <?= rs_cites('res().summary[k.k].c') ?></p>
                        </div>
                    </div>
                    <p class="rs-concl" v-if="res().conclusion">{{ res().conclusion }}</p>
                    <button type="button" class="cf-btn soft sm" @click="go('summary')">إنشاء ملخص لـ Brand Brain 🧠</button>
                </section>

                <!-- السوق -->
                <section class="cf-card rs-sec" v-if="section === 'market'">
                    <h4>تحليل السوق</h4>
                    <p v-if="res().market.overview">{{ res().market.overview }}</p>
                    <ul class="rs-dots">
                        <li v-for="(p, i) in res().market.points" :key="i"><b>{{ kindName(p.k) }}:</b> {{ p.t }} <?= rs_cites('p.c') ?></li>
                    </ul>
                    <p class="cf-muted" v-if="!res().market.points.length">مالقيناش بيانات سوق كفاية في المصادر.</p>
                    <b class="cf-lbl">مؤشرات <small>(بتظهر بس لو البيانات موثوقة)</small></b>
                    <div class="rs-kpis" v-if="res().market.indicators.length">
                        <div v-for="(k, i) in res().market.indicators" :key="i"><small>{{ k.label }}</small><b>{{ k.value }}</b><?= rs_cites('k.c') ?></div>
                    </div>
                    <p class="cf-muted" v-if="!res().market.indicators.length">مالقيناش أرقام منشورة بمصدر واضح — فمش هنعرض أرقام.</p>
                </section>

                <!-- الجمهور -->
                <section class="cf-card rs-sec" v-if="section === 'audience'">
                    <h4>الجمهور المستهدف</h4>
                    <div class="rs-two">
                        <div class="rs-box ok"><span class="cf-chip ok">مؤكد من المصادر</span>
                            <ul class="rs-dots"><li v-for="(a, i) in res().audience.confirmed" :key="i">{{ a.t }} <?= rs_cites('a.c') ?></li></ul>
                            <p class="cf-muted" v-if="!res().audience.confirmed.length">مفيش معلومات مؤكدة كفاية.</p></div>
                        <div class="rs-box ai"><span class="cf-chip rs-chip-ai">استنتاج AI</span>
                            <ul class="rs-dots"><li v-for="(a, i) in res().audience.inferred" :key="i">{{ a }}</li></ul></div>
                    </div>
                    <div class="rs-prof">
                        <div v-for="p in profKeys" :key="p.k"><b>{{ p.t }}</b><span>{{ res().audience.profile[p.k] || '—' }}</span></div>
                    </div>
                </section>

                <!-- المنافسين -->
                <section class="cf-card rs-sec" v-if="section === 'competitors'">
                    <h4>تحليل المنافسين</h4>
                    <p class="cf-muted" v-if="!res().competitors.length">مالقيناش منافسين واضحين في المصادر.</p>
                    <div class="rs-comps">
                        <div class="rs-comp" v-for="(c, i) in res().competitors" :key="i" :class="{ open: openComp === i }">
                            <b>{{ c.name }} <?= rs_cites('c.c') ?></b>
                            <a class="rs-site" v-if="c.site" :href="c.site" target="_blank" rel="noopener nofollow noreferrer">{{ host(c.site) }}</a>
                            <p v-if="c.positioning"><b>التموضع:</b> {{ c.positioning }}</p>
                            <p v-if="c.services"><b>الخدمات:</b> {{ c.services }}</p>
                            <p><b>الأسعار:</b> <span class="cf-chip warn" v-if="c.prices === 'غير معلن'">غير معلن</span><span v-if="c.prices !== 'غير معلن'">{{ c.prices }}</span></p>
                            <div class="pv-c" v-if="openComp === i">
                                <p v-if="c.offers"><b>العروض:</b> {{ c.offers }}</p>
                                <p v-if="c.social"><b>السوشيال:</b> {{ c.social }}</p>
                                <p v-if="c.strength"><b>نقطة القوة:</b> {{ c.strength }}</p>
                                <p class="rs-gap" v-if="c.gap"><b>الفجوة:</b> {{ c.gap }}</p>
                            </div>
                            <button type="button" class="cf-link" @click="openComp = openComp === i ? null : i">{{ openComp === i ? 'إخفاء التفاصيل' : 'عرض تفاصيل المنافس' }}</button>
                        </div>
                    </div>
                    <div class="rs-tw" v-if="res().criteria.length && res().competitors.length">
                        <table class="rs-table">
                            <thead><tr><th>المعيار</th><th v-for="(c, i) in res().competitors" :key="i">{{ c.name }}</th></tr></thead>
                            <tbody><tr v-for="(cr, ci) in res().criteria" :key="ci"><th>{{ cr }}</th><td v-for="(c, i) in res().competitors" :key="i">{{ c.matrix[ci] }}</td></tr></tbody>
                        </table>
                    </div>
                </section>

                <!-- المحتوى -->
                <section class="cf-card rs-sec" v-if="section === 'content'">
                    <h4>تحليل المحتوى</h4>
                    <b class="cf-lbl">الموضوعات والصيغ</b>
                    <ul class="rs-dots"><li v-for="(x, i) in res().content.formats" :key="i">{{ x.t }} <?= rs_cites('x.c') ?></li></ul>
                    <b class="cf-lbl">Hooks وأنماط CTA</b>
                    <ul class="rs-dots"><li v-for="(x, i) in res().content.hooks" :key="i">{{ x.t }} <?= rs_cites('x.c') ?></li></ul>
                    <b class="cf-lbl" v-if="res().content.ideas.length">فرص محتوى محتملة</b>
                    <ul class="rs-dots"><li v-for="(x, i) in res().content.ideas" :key="i">{{ x }}</li></ul>
                    <p class="cf-muted" v-if="!res().content.formats.length && !res().content.hooks.length">مالقيناش بيانات محتوى كفاية.</p>
                </section>

                <!-- الأسعار -->
                <section class="cf-card rs-sec" v-if="section === 'pricing'">
                    <h4>الأسعار والعروض</h4>
                    <p class="rs-note">مش بنألّف أسعار. أي سعر مش معلن في مصدر واضح بيظهر «غير متاح».</p>
                    <div class="rs-tw" v-if="res().pricing.length">
                        <table class="rs-table">
                            <thead><tr><th>المنافس</th><th>السعر</th><th>هيكل العرض</th><th>الحالة</th></tr></thead>
                            <tbody><tr v-for="(p, i) in res().pricing" :key="i">
                                <td>{{ p.competitor }}</td><td>{{ p.price }} <span class="pv-c" v-if="p.c.length"><?= rs_cites('p.c') ?></span></td>
                                <td>{{ p.structure || '—' }}</td>
                                <td><span class="cf-chip" :class="p.status === 'غير معلن' ? 'grey' : 'warn'">{{ p.status }}</span></td></tr></tbody>
                        </table>
                    </div>
                    <p class="cf-muted" v-if="!res().pricing.length">مالقيناش أسعار معلنة في المصادر.</p>
                </section>

                <!-- الاتجاهات -->
                <section class="cf-card rs-sec" v-if="section === 'trends'">
                    <h4>الكلمات والاتجاهات</h4>
                    <div class="rs-tags"><span v-for="k in res().keywords" :key="k">{{ k }}</span></div>
                    <ul class="rs-dots"><li v-for="(x, i) in res().trends" :key="i">{{ x.t }} <?= rs_cites('x.c') ?></li></ul>
                    <p class="cf-muted" v-if="!res().trends.length && !res().keywords.length">مالقيناش اتجاهات واضحة.</p>
                </section>

                <!-- الفرص -->
                <section class="cf-card rs-sec" v-if="section === 'opps'">
                    <h4>الفرص التي اكتشفناها 💡</h4>
                    <p class="cf-muted" v-if="!res().opps.length">مااستخرجناش فرص — جرّب تحديث البحث.</p>
                    <div class="rs-opps">
                        <div class="rs-opp" v-for="(o, i) in res().opps" :key="i">
                            <span class="cf-chip ok">{{ o.type }}</span>
                            <b>{{ o.headline }}</b>
                            <p v-if="o.why"><b>ليه مهمة:</b> {{ o.why }}</p>
                            <p><b>الدليل:</b> <?= rs_cites('o.evidence') ?></p>
                            <p v-if="o.use"><b>استخدامها:</b> {{ o.use }}</p>
                            <button type="button" class="cf-btn soft sm" v-if="r.brain.opps.indexOf(i) < 0" :disabled="busy || null" @click="addOpp(i)">إضافة إلى Brand Brain</button>
                            <span class="cf-chip ok" v-if="r.brain.opps.indexOf(i) >= 0">✓ اتضافت</span>
                        </div>
                    </div>
                </section>

                <!-- المصادر -->
                <section class="cf-card rs-sec" v-if="section === 'sources'">
                    <h4>مصادر البحث ({{ activeSrc() }})</h4>
                    <div class="rs-src" v-for="s in r.sources" :key="s.n" :class="{ ex: s.excluded, pin: s.pinned }">
                        <div class="rs-src-top">
                            <span class="rs-n">[{{ s.n }}]</span><span>{{ s.e }}</span><b>{{ s.site }}</b>
                            <small class="rs-grow">{{ s.kind_t }}</small><small>{{ s.date }}</small>
                        </div>
                        <p>{{ s.title }}</p>
                        <small class="cf-muted" v-if="s.took">اتاخد منه: {{ s.took }}</small>
                        <div class="cf-row">
                            <a class="cf-btn ghost sm" :href="s.url" target="_blank" rel="noopener nofollow noreferrer">فتح المصدر</a>
                            <button type="button" class="cf-btn ghost sm" :disabled="busy || null" @click="srcOp(s, 'pin')">{{ s.pinned ? 'مثبّت ✓' : 'تثبيت' }}</button>
                            <button type="button" class="cf-link" :class="{ danger: !s.excluded }" :disabled="busy || null" @click="srcOp(s, 'exclude')">{{ s.excluded ? 'رجّع المصدر' : 'استبعاد' }}</button>
                        </div>
                    </div>
                    <p class="cf-muted">المثبّت بيتراجع تاني لما تحدّث البحث · المستبعد بيختفي من النتائج والتقرير.</p>
                </section>

                <!-- الخلاصة -->
                <div class="pv-c" v-if="section === 'summary'">
                    <section class="cf-card rs-sec rs-brain">
                        <h4>ماذا تريد أن نحفظ في Brand Brain؟</h4>
                        <p class="cf-muted">البحث نفسه بيفضل محفوظ هنا. اللي بتختاره بس هو اللي بيتضاف لـ Brand Brain ويستخدمه الـ AI في الحملات.</p>
                        <div class="rs-picks">
                            <button type="button" v-for="c in insightCats()" :key="c" :class="{ on: picked.indexOf(c) >= 0, done: r.brain.cats.indexOf(c) >= 0 }" :aria-pressed="picked.indexOf(c) >= 0 ? 'true' : 'false'" @click="pick(c)">
                                <span>{{ r.brain.cats.indexOf(c) >= 0 ? '✓' : (picked.indexOf(c) >= 0 ? '☑' : '☐') }}</span> {{ c }}</button>
                        </div>
                        <p class="cf-muted" v-if="!insightCats().length">مفيش رؤى كفاية تتحفظ من البحث ده.</p>
                        <p class="cf-muted" v-if="!brand.has">لازم تعمل هوية البراند الأول علشان نحفظ فيها. <a :href="base + '/brand-brain.php'">افتح Brand Brain</a></p>
                        <button type="button" class="cf-btn primary" :disabled="busy || !picked.length || !brand.has || null" @click="saveBrain()">إضافة المحدد إلى Brand Brain 🧠 ({{ picked.length }})</button>
                    </section>
                    <section class="cf-card rs-sec">
                        <h4>حوّل البحث إلى تقرير</h4>
                        <div class="cf-chips sm">
                            <button type="button" v-for="t in reportTypes" :key="t.k" :class="{ on: reportType === t.k }" @click="reportType = t.k; reportReady = false">{{ t.t }}</button>
                        </div>
                        <p class="cf-muted">التقرير ملف HTML مستقل بيفتح في أي متصفح: غلاف، ملخص تنفيذي، فهرس، السوق، المنافسين، الجمهور، المحتوى، الفرص، المصادر، وتاريخ البحث.</p>
                        <button type="button" class="cf-btn primary" v-if="!reportReady" @click="reportReady = true">إنشاء تقرير HTML</button>
                        <div class="cf-row" v-if="reportReady">
                            <a class="cf-btn primary" :href="reportUrl(false)" target="_blank" rel="noopener">فتح التقرير</a>
                            <a class="cf-btn ghost" :href="reportUrl(true)">تحميل الملف</a>
                        </div>
                    </section>
                </div>

                <button type="button" class="cf-btn ghost rs-next" v-if="nextSec()" @click="go(nextSec()[0])">التالي: {{ (nextSec() || ['', ''])[1] }} ←</button>
            </div>
        </div>
    </div>

    <!-- ═══ ⑥ السجل ═══ -->
    <div class="pv-c" v-if="phase === 'history'">
        <div class="rs-hist-head">
            <h3 class="rs-h">{{ savedOnly ? 'الأبحاث المحفوظة' : 'سجل الأبحاث' }}</h3>
            <button type="button" class="cf-btn primary sm" @click="newResearch()">+ بحث جديد</button>
        </div>
        <div class="cf-card cf-empty" v-if="!loading && !items.length">
            <span>{{ savedOnly ? 'مفيش أبحاث محفوظة لسه — افتح أي بحث واضغط «احفظ البحث».' : 'مفيش أبحاث لسه — ابدأ أول بحث.' }}</span>
        </div>
        <div class="cf-shimmer" v-if="loading"></div>
        <div class="rs-hist">
            <div class="cf-card rs-hcard" v-for="h in items" :key="h.id">
                <div class="cf-split"><b>{{ h.title }}</b>
                    <span class="cf-chip" :class="{ ok: h.status === 'done', blue: h.status === 'running', warn: h.status === 'failed' }">{{ statusName(h.status) }}</span></div>
                <small class="cf-muted">{{ h.date }} · {{ h.src_count }} مصدر · {{ h.insights }} رؤية</small>
                <small :class="h.age_days > 30 ? 'rs-old' : 'rs-fresh'" v-if="h.status === 'done'">{{ h.age_days > 0 ? 'آخر تحديث منذ ' + h.age_days + ' يومًا' : 'اتحدث النهارده' }}</small>
                <div class="rs-changes sm" v-if="h.changes">{{ h.changes.facts }} معلومات جديدة · {{ h.changes.competitors }} منافسين جدد · {{ h.changes.trends }} اتجاهات جديدة</div>
                <div class="cf-row">
                    <button type="button" class="cf-btn soft sm" @click="openItem(h, 'overview')">فتح</button>
                    <button type="button" class="cf-btn ghost sm" v-if="h.status === 'done'" @click="openItem(h, 'summary')">إنشاء تقرير</button>
                    <button type="button" class="cf-btn ghost sm" v-if="h.status === 'done'" @click="openItem(h, 'summary')">إضافة لـ Brand Brain</button>
                    <button type="button" class="cf-link danger" v-if="(h.status !== 'running' || h.stale) && h.confirm !== true" @click="h.confirm = true">حذف</button>
                    <button type="button" class="cf-link danger" v-if="h.confirm === true" @click="del(h)">متأكد؟ احذف</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container" v-if="toast"><div class="toast" :class="toast && toast.type">{{ toast && toast.msg }}</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var init = <?= json_encode($rsInit, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  var base = document.querySelector('meta[name="app-base"]').content;
  var SECTIONS = [['overview', 'نظرة عامة'], ['market', 'السوق'], ['audience', 'الجمهور'], ['competitors', 'المنافسين'], ['content', 'المحتوى'],
                  ['pricing', 'الأسعار'], ['trends', 'الاتجاهات'], ['opps', 'الفرص'], ['sources', 'المصادر'], ['summary', 'الخلاصة']];
  var wait = function (ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); };
  var copy = function (o) { return JSON.parse(JSON.stringify(o)); };
  var EMPTY = { summary: { market: { t: '', c: [] }, audience: { t: '', c: [] }, competitors: { t: '', c: [] }, content: { t: '', c: [] }, opps: { t: '', c: [] } },
                market: { overview: '', points: [], indicators: [] }, audience: { confirmed: [], inferred: [], profile: {} }, criteria: [], competitors: [],
                content: { formats: [], hooks: [], ideas: [] }, pricing: [], keywords: [], trends: [], opps: [], insights: {}, conclusion: '' };

  var app = SpreadApp.reactive({
    base: base, brand: init.brand, engine: init.engine, balance: init.balance, count: init.count,
    types: init.types, markets: init.markets, periods: init.periods, langs: init.langs, depths: init.depths, kinds: init.kinds, cats: init.cats,
    phase: 'start', q: '', type: null, scope: copy(init.scope), r: null, busy: false, stalled: '', help: false, toast: null,
    section: 'overview', openComp: null, picked: [], reportType: 'full', reportReady: false,
    items: [], savedOnly: false, loading: false, sections: SECTIONS,
    sumKeys: [{ k: 'market', t: '📊 السوق' }, { k: 'audience', t: '👥 الجمهور' }, { k: 'competitors', t: '🥊 المنافسون' }, { k: 'content', t: '📱 المحتوى' }, { k: 'opps', t: '💡 الفرص' }],
    profKeys: [{ k: 'demographics', t: 'الديموغرافيا' }, { k: 'needs', t: 'الاحتياجات' }, { k: 'pains', t: 'نقاط الألم' }, { k: 'triggers', t: 'محفزات الشراء' }],
    reportTypes: [{ k: 'short', t: 'تقرير مختصر' }, { k: 'full', t: 'تقرير كامل' }, { k: 'exec', t: 'تقرير تنفيذي' }, { k: 'html', t: 'تقرير HTML تفاعلي' }],
    suggest: [{ t: 'حلل منافسيني', k: 'competitors' }, { t: 'اعرف جمهوري', k: 'audience' }, { t: 'ابحث عن السوق', k: 'market' }, { t: 'حلل المحتوى الناجح', k: 'content' }, { t: 'اعمل بحث شامل', k: 'marketing' }],

    notify: function (m, t) { SpreadApp.toast(this, m, t || 'success'); },
    typeOf: function () { var k = this.type || 'custom'; return this.types.filter(function (t) { return t.k === k; })[0] || this.types[this.types.length - 1]; },
    cities: function () { var m = this.scope.market; return (this.markets.filter(function (x) { return x.t === m; })[0] || { cities: ['كل المدن'] }).cities; },
    helpLine: function () { return 'بما إن «' + this.brand.name + '» ' + this.brand.industry + '، أقترح تبدأ بتحليل المنافسين ثم الجمهور.'; },
    // النتيجة (أو هيكل فاضي — علشان التعبيرات ماتقعش وهي بتتشال من الشاشة)
    res: function () { return (this.r && this.r.result) || EMPTY; },
    host: function (u) { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return u; } },
    kindName: function (k) { return { trend: 'اتجاه', demand: 'إشارة طلب', opportunity: 'فرصة', risk: 'مخاطر' }[k] || 'اتجاه'; },
    statusName: function (s) { return { done: 'مكتمل', running: 'شغّال', failed: 'مااكتملش', draft: 'مسودة' }[s] || s; },
    activeSrc: function () { return this.r ? this.r.sources.filter(function (s) { return !s.excluded; }).length : 0; },
    // أرقام المصادر الظاهرة (المستبعد بيختفي)
    cs: function (list) {
      if (!this.r || !list || !list.length) return [];
      var map = {}; this.r.sources.forEach(function (s) { map[s.n] = s; });
      return list.map(function (n) { return map[n]; }).filter(function (s) { return s && !s.excluded; });
    },
    insightCats: function () { var ins = this.res().insights || {}; return this.cats.filter(function (c) { return !!ins[c]; }); },
    nextSec: function () { var i = SECTIONS.map(function (s) { return s[0]; }).indexOf(this.section); return i >= 0 && i < SECTIONS.length - 1 ? SECTIONS[i + 1] : null; },
    reportUrl: function (dl) { return base + '/research-report.php?id=' + this.r.id + '&type=' + this.reportType + (dl ? '&dl=1' : ''); },
    setUrl: function (id) { try { history.replaceState(null, '', base + '/research.php' + (id ? '?id=' + id : '')); } catch (e) {} },

    // ── التنقل ──
    back: function () {
      if (this.busy) return;
      var to = { scope: 'start', plan: 'scope', results: 'history', history: 'start', running: 'history' }[this.phase] || 'start';
      if (to === 'history') return this.goHistory(this.savedOnly);
      this.phase = to;
    },
    go: function (s) { this.section = s; this.openComp = null; window.scrollTo({ top: 0, behavior: 'smooth' }); },
    newResearch: function () { this.phase = 'start'; this.r = null; this.q = ''; this.type = null; this.scope = copy(init.scope); this.setUrl(null); },
    useSuggest: function (s) { this.type = s.k; this.q = s.t + ' — ' + this.brand.industry + ' في ' + this.scope.market; this.help = false; this.phase = 'scope'; },
    pickType: function (k) {
      var t = this.types.filter(function (x) { return x.k === k; })[0];
      this.type = k;
      if (!this.q.trim() && k !== 'custom') this.q = t.t + ' ل' + this.brand.industry + ' في ' + this.scope.market;
      this.phase = 'scope';
    },
    toScope: function () {
      if (!this.q.trim()) return this.notify('اكتب سؤالك أو اختار نوع البحث', 'danger');
      this.type = this.type || 'custom'; this.phase = 'scope';
    },
    setMarket: function (m) { this.scope.market = m; this.scope.city = 'كل المدن'; },
    toggleKind: function (k) {
      var i = this.scope.src_off.indexOf(k);
      if (i >= 0) this.scope.src_off.splice(i, 1); else if (this.scope.src_off.length < this.kinds.length - 1) this.scope.src_off.push(k);
    },

    // ── الخطة والتنفيذ ──
    savePlan: async function () {
      var id = this.r && (this.r.status === 'draft' || this.r.status === 'failed') ? this.r.id : 0;
      var res = await SpreadAPI.post('research', 'plan', { id: id, q: this.q, type: this.type || 'custom', scope: this.scope });
      if (!res.ok) { this.notify(res.error, 'danger'); return null; }
      this.r = res.research; this.balance = res.balance; this.engine = res.engine;
      return res;
    },
    toPlan: async function () {
      if (this.busy) return;
      if (this.q.trim().length < 4) return this.notify('اكتب سؤال البحث الأول', 'danger');
      this.busy = true;
      var res = await Promise.all([this.savePlan(), wait(900)]);
      this.busy = false;
      if (res[0]) this.phase = 'plan';
    },
    start: async function () {
      if (this.busy) return;
      this.busy = true;
      var ok = true;
      // الخطة ممكن تكون اتعدلت (المصادر) — نحفظها قبل البداية
      if (this.r.status === 'draft' || this.r.status === 'failed') ok = !!(await this.savePlan());
      if (!ok) { this.busy = false; return; }
      var res = await SpreadAPI.post('research', 'start', { id: this.r.id });
      this.busy = false;
      if (!res.ok) return this.notify(res.error, 'danger');
      this.r = res.research; this.balance = res.balance; this.count++;
      this.phase = 'running'; this.setUrl(this.r.id);
      this.run();
    },
    run: async function () {
      if (this.busy) return;
      this.busy = true; this.stalled = '';
      var fails = 0;
      while (this.r && this.r.status === 'running' && this.phase === 'running') {
        var res = await SpreadAPI.post('research', 'step', { id: this.r.id });
        if (!res.ok) {
          if (res.code === 'busy') { await wait(2500); continue; }
          if (++fails >= 3 || ['offline', 'rate_limit', 'not_found', 'server_error'].indexOf(res.code) >= 0 || /^http_/.test(res.code || '')) {
            this.stalled = res.error || 'الاتصال اتقطع'; break;
          }
          await wait(1500); continue;
        }
        fails = 0;
        this.r = res.research; this.balance = res.balance;
        if (res.retry) await wait(1200);
      }
      this.busy = false;
      if (!this.r || this.phase !== 'running') return;
      if (this.r.status === 'done') {
        this.phase = 'results'; this.section = 'overview'; this.picked = [];
        this.notify(this.r.changes ? 'البحث اتحدث ✓' : 'البحث خلص ✓ — ' + this.activeSrc() + ' مصدر');
      } else if (this.r.status === 'failed') {
        this.phase = 'plan'; this.q = this.r.question; this.type = this.r.type; this.scope = copy(this.r.scope);
        this.notify(this.r.error || 'البحث مااكتملش — الكريدت رجع لرصيدك', 'danger');
      }
    },
    refresh: async function () {
      if (this.busy) return;
      this.busy = true;
      var res = await SpreadAPI.post('research', 'refresh', { id: this.r.id });
      this.busy = false;
      if (!res.ok) return this.notify(res.error, 'danger');
      this.phase = 'running'; this.r = res.research; this.balance = res.balance;
      this.run();
    },

    // ── السجل ──
    goHistory: async function (saved) {
      if (this.busy) return;
      this.savedOnly = saved; this.phase = 'history'; this.loading = true; this.setUrl(null);
      var res = await SpreadAPI.get('research', { action: 'list', saved: saved ? 1 : '' });
      this.loading = false;
      if (!res.ok) return this.notify(res.error, 'danger');
      this.items = res.items.map(function (h) { h.confirm = false; return h; });
    },
    openItem: async function (h, section) {
      if (this.busy) return;
      this.busy = true;
      var res = await SpreadAPI.get('research', { action: 'get', id: h.id });
      this.busy = false;
      if (!res.ok) return this.notify(res.error, 'danger');
      this.show(res.research, section);
    },
    show: function (r, section) {
      this.r = r; this.picked = []; this.reportReady = false; this.openComp = null; this.setUrl(r.id);
      if (r.status === 'running') { this.phase = 'running'; this.run(); return; }
      if (r.status === 'done') { this.phase = 'results'; this.section = section || 'overview'; return; }
      this.q = r.question; this.type = r.type; this.scope = copy(r.scope); this.phase = 'plan';
    },
    del: async function (h) {
      var res = await SpreadAPI.post('research', 'delete', { id: h.id });
      if (!res.ok) return this.notify(res.error, 'danger');
      var i = this.items.indexOf(h); if (i >= 0) this.items.splice(i, 1);
      this.count = Math.max(0, this.count - 1);
      this.notify('اتمسح البحث');
    },

    // ── داخل النتائج ──
    toggleSave: async function () {
      var res = await SpreadAPI.post('research', 'save_toggle', { id: this.r.id });
      if (!res.ok) return this.notify(res.error, 'danger');
      this.r.saved = res.saved;
      this.notify(res.saved ? 'اتحفظ في «المحفوظة» ✓' : 'اتشال من المحفوظة');
    },
    srcOp: async function (s, op) {
      if (this.busy) return;
      this.busy = true;
      var res = await SpreadAPI.post('research', 'source', { id: this.r.id, n: s.n, op: op });
      this.busy = false;
      if (!res.ok) return this.notify(res.error, 'danger');
      // تحديث في مكانه (v-for بالـ key بيعيد استخدام نفس العنصر)
      var fresh = {}; res.research.sources.forEach(function (x) { fresh[x.n] = x; });
      this.r.sources.forEach(function (x) { if (fresh[x.n]) { x.pinned = fresh[x.n].pinned; x.excluded = fresh[x.n].excluded; } });
    },
    pick: function (c) {
      if (this.r.brain.cats.indexOf(c) >= 0) return;
      var i = this.picked.indexOf(c); if (i >= 0) this.picked.splice(i, 1); else this.picked.push(c);
    },
    brainPost: async function (cats, opps) {
      if (this.busy) return false;
      this.busy = true;
      var res = await SpreadAPI.post('research', 'brain', { id: this.r.id, cats: cats, opps: opps });
      this.busy = false;
      if (!res.ok) { this.notify(res.error, 'danger'); return false; }
      this.r.brain.cats = res.research.brain.cats; this.r.brain.opps = res.research.brain.opps;
      return true;
    },
    saveBrain: async function () {
      var n = this.picked.length;
      if (await this.brainPost(this.picked.slice(), [])) { this.picked = []; this.notify('اتضاف ' + n + (n === 1 ? ' رؤية' : ' رؤى') + ' لـ Brand Brain 🧠'); }
    },
    addOpp: async function (i) {
      if (await this.brainPost([], [i])) this.notify('الفرصة اتضافت لـ Brand Brain 🧠');
    },
  });

  SpreadApp.mount('#rs', app);
  if (init.open) app.show(init.open);
});
</script>
