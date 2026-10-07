<?php
/**
 * Spread AI v2 — الحملة كاملة جوه الشاشة (المرحلة ⑥-ب) — petite-vue
 * زي التصميم (Campaign / CampaignDesktop): عمود المراحل · الرأس · 6 مراحل · شريط التنقل
 *
 * الأفكار ← المحتوى ← التقييم ← التصميم ← الجدولة ← النشر — كل حاجة جماعي (فكرة فكرة من الواجهة)
 * الداتا: /api/campaign-flow.php · البريف: /api/campaigns.php (حفظ تلقائي)
 * التصميم: ajax/generate-design.php (bulk=1) · النشر: ajax/publish-direct.php
 *
 * ⚠️ ممنوع <template v-if> — استخدم span.pv-c · وممنوع v-for و v-if على نفس العنصر
 * متغيرات: $cfInit (البيانات الأولية)
 */
?>
<div id="cf" class="cf" v-cloak @click="menu = false; pick = null">

    <!-- ═══════════ عمود المراحل (ديسكتوب) ═══════════ -->
    <aside class="cf-rail" aria-label="مراحل الحملة">
        <a href="<?= url('dashboard.php') ?>" class="v2-logo cf-logo">
            <img src="<?= url('assets/img/spread-mark-128.png') ?>" alt="" width="36" height="30">
            <span><b>Spread <i>AI</i></b></span>
        </a>
        <div class="cf-camp">
            <div class="cf-camp-top"><b>{{ c.title }}</b><span class="cf-chip" :class="statusChip().cls">{{ statusChip().label }}</span></div>
            <small class="cf-save" :class="save.state"><?= ui_icon('check', 13) ?> {{ saveText() }}</small>
        </div>
        <ol class="cf-steps">
            <li v-for="n in [1,2,3,4,5,6]" :key="n" :class="stepCls(n)">
                <button type="button" @click.stop="go(n)" :disabled="!canGo(n)" :aria-current="c.stage === n ? 'step' : null">
                    <span class="cf-step-ic" v-html="n < c.stage || (n <= c.max_stage && n !== c.stage) ? icons.check : icons[stageKeys[n]]"></span>
                    <span class="cf-step-txt"><b>{{ n }}. {{ stages[n].name }}</b><small>{{ stages[n].desc }}</small></span>
                </button>
            </li>
        </ol>
        <div class="cf-ai-card" :class="{ on: !!ai.on }">
            <span class="cf-orb" aria-hidden="true"><i></i></span>
            <div><b>{{ ai.on ? 'Spread AI يفكر...' : 'Spread AI جاهز' }}</b>
                <small>{{ ai.on ? ai.msg : 'تقدر تسيب الحملة وترجعلها في أي وقت، كل حاجة بتتحفظ.' }}</small></div>
        </div>
    </aside>

    <!-- ═══════════ المحتوى ═══════════ -->
    <section class="cf-main">
        <header class="cf-top">
            <a :href="base + '/campaigns.php'" class="cf-iconbtn" aria-label="رجوع لحملاتي"><?= ui_icon('chevron', 20) ?></a>
            <div class="cf-crumb">
                <small class="cf-crumb-d"><a :href="base + '/campaigns.php'">حملاتي</a> / {{ c.title }}</small>
                <div class="cf-crumb-m"><b>{{ c.title }}</b><span class="cf-chip" :class="statusChip().cls">{{ statusChip().label }}</span></div>
                <small class="cf-crumb-m cf-save" :class="save.state"><?= ui_icon('check', 12) ?> {{ saveText() }}</small>
                <h1>المرحلة {{ c.stage }} من 6 · {{ stages[c.stage].name }}</h1>
            </div>
            <div class="cf-think-pill" v-if="ai.on" aria-live="polite"><span class="cf-orb sm"><i></i></span>{{ ai.msg }}</div>
            <div class="cf-menu" @click.stop>
                <button type="button" class="cf-iconbtn" @click="menu = !menu" aria-label="خيارات" :aria-expanded="menu">⋯</button>
                <div class="cf-pop" v-if="menu">
                    <button type="button" @click="saveAndExit()">💾 حفظ والخروج</button>
                    <button type="button" @click="duplicate()">⧉ تكرار الحملة</button>
                    <a :href="base + '/campaign-export.php?id=' + c.id">⬇ تصدير المحتوى (CSV)</a>
                    <button type="button" v-if="c.status === 'active'" @click="complete()">✓ علّمها مكتملة</button>
                </div>
            </div>
        </header>

        <!-- موبايل: المراحل أفقي -->
        <ol class="cf-hsteps" aria-label="مراحل الحملة">
            <li v-for="n in [1,2,3,4,5,6]" :key="n" :class="stepCls(n)">
                <button type="button" @click.stop="go(n)" :disabled="!canGo(n)">
                    <span class="cf-step-ic"><em>{{ n }}</em><span v-html="n < c.stage || (n <= c.max_stage && n !== c.stage) ? icons.check : icons[stageKeys[n]]"></span></span>
                    <small>{{ stages[n].name }}</small>
                </button>
            </li>
        </ol>

        <div class="cf-body">
        <!-- قفل الهوية -->
        <div class="cf-card cf-gate" v-if="c.stage > 1 && !health.unlocked">
            <b>🔒 لازم هوية البراند تعدّي {{ health.gate }}%</b>
            <p class="sub">هويتك دلوقتي {{ health.pct }}%. المحتوى والتصميم بيتبنوا على Brand Brain علشان الـ AI يكتب ويصمم بأسلوبك.</p>
            <div class="cf-bar"><span :style="{ width: health.pct + '%' }"></span></div>
            <a :href="base + '/brand-brain.php'" class="cf-btn primary sm">🧠 كمّل Brand Brain</a>
        </div>

        <!-- التفكير (أي شغل جماعي) -->
        <section class="cf-card cf-think" v-if="bulk.on" aria-live="polite">
            <div class="cf-think-top">
                <span class="cf-orb lg"><i></i></span>
                <div><b>Spread AI يفكر...</b><small>{{ ai.msg }}</small></div>
                <button type="button" class="cf-link" @click="bulk.stop = true" :disabled="bulk.stop">{{ bulk.stop ? 'بيوقف…' : 'إيقاف' }}</button>
            </div>
            <div class="cf-think-steps">
                <span v-for="(m, k) in bulk.steps" :key="k" :class="{ done: k < bulk.stepI, now: k === bulk.stepI }">
                    <i>{{ k < bulk.stepI ? '✓' : '' }}</i>{{ m }}</span>
            </div>
            <div class="cf-think-prog" v-if="bulk.total > 1">
                <span>{{ bulk.label }} <b>{{ bulk.done }} من {{ bulk.total }}</b></span>
                <div class="cf-bar"><span :style="{ width: Math.round(bulk.done / bulk.total * 100) + '%' }"></span></div>
            </div>
            <div class="cf-shimmer" v-if="bulk.total <= 1"></div>
        </section>

        <!-- اقتراح تحديث المحتوى بعد تعديل فكرة -->
        <div class="cf-card cf-propagate sx-in" v-if="propagate">
            <span><b>تم تعديل الفكرة.</b> هل تريد تحديث المحتوى المرتبط بهذه الفكرة؟</span>
            <div class="cf-row">
                <button type="button" class="cf-btn primary sm" @click="propagateYes()" :disabled="bulk.on">تحديث تلقائي ✨ <small class="cr">· {{ costs.regen }} كريدت</small></button>
                <button type="button" class="cf-btn ghost sm" @click="propagate = null">احتفظ بالمحتوى الحالي</button>
            </div>
        </div>

        <!-- ══════════════ ① الأفكار ══════════════ -->
        <div class="pv-c" v-if="c.stage === 1">
            <div class="cf-headline">
                <h2>خلينا نبدأ بفكرة الحملة 💡</h2>
                <p>قولّي هدفك، وأنا هبني لك مجموعة أفكار مناسبة لعلامتك التجارية.</p>
            </div>
            <div class="cf-ideas-grid">
                <section class="cf-card cf-brief">
                    <label class="cf-lbl" for="cf-title">اسم الحملة</label>
                    <input id="cf-title" class="cf-input" v-model="c.title" @input="touch()" maxlength="150">
                    <div class="cf-ask">
                        <span class="cf-bot"><img :src="base + '/assets/img/robot.webp'" alt="" width="34" height="37" @error="$event.target.style.display='none'"></span>
                        <b>إيه الأساس اللي تحب نبني عليه الحملة؟</b>
                    </div>
                    <div class="cf-chips">
                        <button type="button" v-for="(label, k) in bases" :key="k" :class="{ on: c.basis === k }" @click="c.basis = k; touch()">{{ label }}</button>
                    </div>
                    <label class="cf-lbl">كام فكرة تحب نطلع لك؟</label>
                    <div class="cf-seg" role="radiogroup" aria-label="عدد الأفكار">
                        <button type="button" v-for="n in [5,10,15,20]" :key="n" role="radio" :aria-checked="count === n" :class="{ on: count === n }" @click="count = n; c.ideas_count = n; touch()">{{ n }}</button>
                    </div>
                    <label class="cf-lbl" for="cf-notes">تفاصيل إضافية <small>(اختياري)</small></label>
                    <textarea id="cf-notes" class="cf-input" rows="3" v-model="c.notes" @input="touch()" maxlength="2000" placeholder="أضف أي تفاصيل تحب... مثلًا: عرض 30% على التبييض لحد آخر الشهر"></textarea>
                    <button type="button" class="cf-btn primary big" @click="genIdeas()" :disabled="bulk.on || !c.basis">
                        {{ ideas.length ? 'إعادة توليد الأفكار ✨' : 'ابدأ توليد الأفكار ✨' }}
                    </button>
                    <small class="cf-cost"><span class="cr">{{ costs.ideas[count] || 0 }} كريدت · رصيدك {{ costs.balance }}</span><span v-if="ideas.length"><span class="cr"> · </span>الأفكار المختارة بتفضل زي ما هي</span></small>
                </section>

                <section class="cf-ideas">
                    <div class="cf-split">
                        <h3>أفكار حملتك</h3>
                        <span class="cf-muted" v-if="ideas.length">
                            <button type="button" class="cf-link" @click="selectAll(selectedCount() < ideas.length)">{{ selectedCount() < ideas.length ? 'اختار الكل' : 'إلغاء الكل' }}</button>
                            · {{ ideas.length }} فكرة
                        </span>
                    </div>
                    <div class="cf-empty" v-if="!ideas.length && !bulk.on">
                        <span class="cf-orb lg"><i></i></span>
                        <p>اختار الأساس وعدد الأفكار، والأفكار هتظهر هنا.</p>
                    </div>
                    <div class="cf-idea-cards">
                        <article class="cf-idea" v-for="i in ideas" :key="i.id" :class="{ on: i.selected }">
                            <div class="cf-idea-top">
                                <button type="button" class="cf-check" :class="{ on: i.selected }" role="checkbox" :aria-checked="i.selected ? 'true' : 'false'"
                                        :aria-label="'اختيار ' + i.title" @click="toggleIdea(i)"><?= ui_icon('check', 14) ?></button>
                                <span class="cf-angle">{{ i.angle }}</span>
                                <small class="cf-n">#{{ i.n }}</small>
                            </div>
                            <div class="pv-c" v-if="editing !== i.id">
                                <h4>{{ i.title }}</h4>
                                <p>{{ i.desc }}</p>
                                <dl class="cf-idea-meta" v-if="i.audience || i.hook">
                                    <div v-if="i.audience"><dt>الجمهور</dt><dd>{{ i.audience }}</dd></div>
                                    <div v-if="i.hook"><dt>الـ Hook</dt><dd><b>«{{ i.hook }}»</b></dd></div>
                                </dl>
                                <div class="cf-fmts" role="radiogroup" :aria-label="'شكل المحتوى للفكرة ' + i.n">
                                    <button type="button" v-for="f in fmtList" :key="f.k" role="radio" :aria-checked="i.format === f.k ? 'true' : 'false'"
                                            :class="{ on: i.format === f.k, sug: suggested(i, f.k) }" :title="suggested(i, f.k) ? 'مقترح للفكرة دي' : ''"
                                            :disabled="bulk.on || locked(i) || null" @click="setFormat(i, f.k)">{{ f.e }} {{ f.t }}</button>
                                </div>
                                <div class="cf-slides-n" v-if="i.format === 'carousel'">
                                    <span>عدد الشرائح</span>
                                    <button type="button" @click="setSlides(i, -1)" :disabled="bulk.on || i.slides_count <= limits.min || locked(i) || null" aria-label="أقل">−</button>
                                    <b>{{ i.slides_count }}</b>
                                    <button type="button" @click="setSlides(i, 1)" :disabled="bulk.on || i.slides_count >= limits.max || locked(i) || null" aria-label="أكتر">+</button>
                                    <small>= {{ i.slides_count }} تصميمات</small>
                                </div>
                                <div class="cf-idea-foot">
                                    <span class="cf-grow"></span>
                                    <span class="cf-have" v-if="i.content">✓ ليها محتوى</span>
                                    <button type="button" class="cf-link" @click="startEdit(i)"><?= ui_icon('edit', 14) ?> تعديل</button>
                                </div>
                            </div>
                            <div class="cf-form sx-in" v-if="editing === i.id">
                                <input class="cf-input" v-model="draft.title" maxlength="255" aria-label="عنوان الفكرة">
                                <textarea class="cf-input" rows="3" v-model="draft.desc" maxlength="1000" aria-label="شرح الفكرة"></textarea>
                                <input class="cf-input" v-model="draft.audience" maxlength="255" placeholder="الجمهور" aria-label="الجمهور">
                                <input class="cf-input" v-model="draft.hook" maxlength="500" placeholder="الـ Hook" aria-label="الـ Hook">
                                <div class="cf-row">
                                    <button type="button" class="cf-btn primary sm" @click="saveIdea(i)">حفظ</button>
                                    <button type="button" class="cf-btn ghost sm" @click="editing = 0">إلغاء</button>
                                    <span class="cf-grow"></span>
                                    <button type="button" class="cf-link danger" @click="deleteIdea(i)">حذف الفكرة</button>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </div>

        <!-- ══════════════ ② المحتوى ══════════════ -->
        <div class="pv-c" v-if="c.stage === 2 && health.unlocked">
            <div class="cf-headline">
                <h2>نحوّل أفكارك إلى محتوى</h2>
                <p>كل فكرة بتتكتب حسب شكلها: منشور، كاروسيل بشرائحه، سكريبت فيديو، أو ستوري — مع Hook وكابشن وCTA وهاشتاجات.</p>
            </div>
            <div class="cf-content-grid">
                <aside class="cf-idea-list">
                    <b class="cf-lbl">الأفكار المختارة</b>
                    <button type="button" v-for="i in selected()" :key="i.id" class="cf-idea-pill" :class="{ on: active === i.id }" @click="active = i.id; mode = ''">
                        <small>الفكرة #{{ i.n }}</small><b>{{ i.title }}</b>
                        <em :class="i.content ? 'ok' : (running[i.id] ? 'run' : '')">{{ i.content ? '✓' : (running[i.id] ? '…' : '○') }}</em>
                    </button>
                </aside>
                <section class="cf-editor-wrap">
                    <div class="cf-split">
                        <div class="cf-tabs" role="tablist" v-if="cur() && modesFor(cur()).length > 1">
                            <button type="button" role="tab" v-for="m in modesFor(cur())" :key="m[0]" :aria-selected="curMode() === m[0]" :class="{ on: curMode() === m[0] }" @click="mode = m[0]">{{ m[1] }}</button>
                        </div>
                        <span class="cf-chip dark" v-if="cur() && modesFor(cur()).length === 1">{{ fmtOf(cur()).e }} {{ cur().format_label }}</span>
                        <button type="button" class="cf-btn soft sm" @click="genContent()" :disabled="bulk.on || !missingContent().length">
                            توليد المحتوى ✨ <small v-if="missingContent().length">({{ missingContent().length }})</small>
                        </button>
                    </div>
                    <div class="cf-card cf-editor" v-if="cur()">
                        <div class="cf-split">
                            <div><small class="cf-n blue">الفكرة #{{ cur().n }} · {{ fmtOf(cur()).e }} {{ cur().format_label }}<span v-if="cur().format === 'carousel'"> · {{ cur().slides_count }} شرائح</span></small><h3>{{ cur().title }}</h3></div>
                            <div class="cf-row" v-if="cur().content">
                                <button type="button" class="cf-pill" @click="regen(cur())" :disabled="bulk.on || locked(cur())">↻ إعادة توليد</button>
                                <button type="button" class="cf-pill" @click="copyCur()">نسخ</button>
                            </div>
                        </div>
                        <div class="cf-empty sm" v-if="!cur().content">
                            <p>{{ running[cur().id] ? 'بيتكتب دلوقتي…' : 'الفكرة دي لسه ملهاش محتوى.' }}</p>
                            <button type="button" class="cf-btn primary sm" v-if="!running[cur().id]" @click="genOne(cur())" :disabled="bulk.on">اكتبها ✨ <small class="cr">· {{ costs.content }} كريدت</small></button>
                        </div>
                        <div class="pv-c" v-if="cur().content && curMode() === 'post'">
                            <p class="cf-lock" v-if="locked(cur())">🔒 المنشور ده اتسلّم للنشر — التعديل مقفول.</p>
                            <label class="cf-field"><span class="dot teal">Hook</span>
                                <textarea class="cf-input" rows="2" v-model="cur().content.hook" @input="edited(cur())" :readonly="locked(cur()) || null"></textarea></label>
                            <label class="cf-field"><span class="dot blue">المحتوى / الكابشن</span>
                                <textarea class="cf-input" rows="6" v-model="cur().content.body" @input="edited(cur())" :readonly="locked(cur()) || null"></textarea></label>
                            <label class="cf-field"><span class="dot navy">CTA</span>
                                <input class="cf-input" v-model="cur().content.cta" @input="edited(cur())" :readonly="locked(cur()) || null"></label>
                            <label class="cf-field"><span class="dot teal">Hashtags</span>
                                <input class="cf-input" dir="auto" v-model="cur().content.tags" @input="edited(cur())" :readonly="locked(cur()) || null"></label>
                            <label class="cf-field" v-if="cur().format !== 'video'"><span class="dot dark">{{ cur().format === 'carousel' ? 'الهوية البصرية للشرائح' : 'فكرة التصميم' }}</span>
                                <textarea class="cf-input" rows="2" v-model="cur().content.design_idea" @input="edited(cur())" :readonly="locked(cur()) || null"></textarea></label>
                        </div>
                        <!-- ⑦-ج شرائح الكاروسيل -->
                        <div class="pv-c" v-if="cur().content && curMode() === 'slides'">
                            <div class="cf-slide-ed" v-for="sl in cur().slides" :key="sl.n">
                                <span class="cf-slide-no">{{ String(sl.n).padStart(2, '0') }}</span>
                                <div class="cf-grow">
                                    <input class="cf-input" v-model="sl.title" maxlength="200" :placeholder="sl.n === 1 ? 'عنوان الغلاف' : 'عنوان الشريحة'" @input="edited(cur(), 'slides')" :readonly="locked(cur()) || null">
                                    <textarea class="cf-input" rows="2" v-model="sl.text" maxlength="600" placeholder="نص الشريحة" @input="edited(cur(), 'slides')" :readonly="locked(cur()) || null"></textarea>
                                    <input class="cf-input xs" v-model="sl.design" maxlength="600" placeholder="فكرة تصميم الشريحة" @input="edited(cur(), 'slides')" :readonly="locked(cur()) || null">
                                </div>
                            </div>
                        </div>
                        <div class="pv-c" v-if="cur().content && curMode() === 'script'">
                            <p class="cf-muted" v-if="!cur().script">السكريبت مااتكتبش مع المنشور ده — اضغط «إعادة توليد» علشان يتكتب.</p>
                            <div class="pv-c" v-if="cur().script">
                                <label class="cf-field"><span class="dot teal">Hook</span>
                                    <textarea class="cf-input" rows="2" v-model="scr().hook" @input="edited(cur(), 'script')"></textarea></label>
                                <label class="cf-field"><span class="dot blue">المشاهد</span>
                                    <textarea class="cf-input" rows="7" v-model="scr().body" @input="edited(cur(), 'script')"></textarea></label>
                                <label class="cf-field"><span class="dot navy">CTA</span>
                                    <input class="cf-input" v-model="scr().cta" @input="edited(cur(), 'script')"></label>
                                <label class="cf-field"><span class="dot dark">فكرة التصوير</span>
                                    <textarea class="cf-input" rows="2" v-model="scr().design" @input="edited(cur(), 'script')"></textarea></label>
                            </div>
                            <p class="cf-muted">🎬 الـ AI بيجهّز السكريبت — والتنفيذ يدوي بفريق Spread AI من مرحلة «التصميم».</p>
                        </div>
                        <small class="cf-save-line" v-if="cur().content">{{ fieldSave[cur().id] || 'التعديلات بتتحفظ لوحدها' }}</small>
                    </div>
                </section>
            </div>
        </div>

        <!-- ══════════════ ③ التقييم ══════════════ -->
        <div class="pv-c" v-if="c.stage === 3 && health.unlocked">
            <div class="cf-headline cf-split">
                <div><h2>خلينا نراجع المحتوى</h2><p>Spread AI سيقيّم كل قطعة محتوى قبل الانتقال للتصميم.</p></div>
                <button type="button" class="cf-link" @click="skipEval()" :disabled="bulk.on">تخطي التقييم</button>
            </div>
            <div class="cf-row cf-sum-chips">
                <span class="cf-chip ok">{{ evalCount('ready') }} جاهزة</span>
                <span class="cf-chip warn">{{ evalCount('weak') }} تحتاج تحسين</span>
                <span class="cf-chip grey" v-if="evalCount('none')">{{ evalCount('none') }} لسه</span>
                <span class="cf-grow"></span>
                <button type="button" class="cf-btn soft sm" v-if="evalCount('none')" @click="runEval()" :disabled="bulk.on">قيّم الكل ✨ <small class="cr" v-if="costs.eval">· {{ costs.eval }} لكل منشور</small></button>
            </div>
            <div class="cf-eval-grid">
                <article class="cf-card cf-eval" v-for="i in withContent()" :key="i.id">
                    <div class="cf-eval-top">
                        <div class="cf-ring" :class="i.eval ? (i.eval.ready ? 'ok' : 'warn') : 'none'" :style="{ '--p': (i.eval ? i.eval.score : 0) }">
                            <b>{{ i.eval ? i.eval.score : '–' }}</b><small>/ 100</small>
                        </div>
                        <div>
                            <small class="cf-muted">الفكرة #{{ i.n }} · {{ fmtOf(i).e }} {{ i.format_label }}</small>
                            <h4>{{ i.title }}</h4>
                            <span class="cf-chip ok" v-if="i.eval && i.eval.ready">محتوى جاهز ✅</span>
                            <span class="cf-chip warn" v-if="i.eval && !i.eval.ready">يحتاج تحسين</span>
                            <span class="cf-chip grey" v-if="!i.eval">{{ running[i.id] ? 'بيتقيّم…' : 'لسه ماتقيّمش' }}</span>
                            <span class="cf-chip blue" v-if="i.approved && i.eval && !i.eval.ready">✓ معتمد</span>
                        </div>
                    </div>
                    <p class="cf-quote">«{{ i.content.hook || i.content.body.slice(0, 90) }}»</p>
                    <div class="cf-cats" v-if="i.eval && i.eval.cats">
                        <div v-for="(v, k) in i.eval.cats" :key="k">
                            <span class="cf-split"><small>{{ evalCats[k] }}</small><b>{{ v }}</b></span>
                            <div class="cf-bar sm"><span :class="{ hot: v < 80 }" :style="{ width: v + '%' }"></span></div>
                        </div>
                    </div>
                    <p class="cf-comment ok" v-if="i.eval && i.eval.ready && i.eval.comment">✦ {{ i.eval.comment }}</p>
                    <div class="cf-comment warn" v-if="i.eval && !i.eval.ready">
                        <span>ⓘ {{ i.eval.issues || 'الـ Hook والـ CTA محتاجين يبقوا أقوى.' }}</span>
                        <div class="cf-row">
                            <button type="button" class="cf-btn primary sm" @click="improve(i)" :disabled="bulk.on || locked(i)">تحسين المحتوى ✨ <small class="cr">· {{ costs.regen }} كريدت</small></button>
                            <button type="button" class="cf-link" v-if="!i.approved" @click="approveOne(i)">اعتمده كده</button>
                        </div>
                    </div>
                    <button type="button" class="cf-btn soft sm" v-if="!i.eval && !running[i.id]" @click="evalOne(i)" :disabled="bulk.on">قيّم المنشور ده</button>
                </article>
            </div>
        </div>

        <!-- ══════════════ ④ التصميم ══════════════ -->
        <div class="pv-c" v-if="c.stage === 4 && health.unlocked">
            <div class="cf-headline"><h2>خلينا نحول المحتوى لتصميمات 🎨</h2></div>
            <div class="cf-brandline">
                <span class="cf-dots"><i v-for="col in brandColors()" :key="col" :style="{ background: col }"></i></span>
                سيتم استخدام ألوان وهوية مشروعك<span v-if="brand.logo"> واللوجو</span> تلقائيًا.
            </div>
            <div class="cf-card cf-toolbar">
                <button type="button" class="cf-link" @click="designSelAll()">{{ designSelCount() < designable().length ? 'تحديد الكل' : 'إلغاء التحديد' }}</button>
                <div class="cf-seg sm" aria-label="المقاس">
                    <button type="button" v-for="r in ['1:1','4:5','9:16']" :key="r" :class="{ on: ratio === r }" @click="ratio = r" dir="ltr">{{ r }}</button>
                </div>
                <span class="cf-grow"></span>
                <small class="cf-cost"><span class="cr">{{ costs.design }} كريدت لكل تصميم · </span>المحدد = {{ designTaskCount() }} تصميم<span class="cr"> · رصيدك {{ costs.balance }}</span></small>
                <button type="button" class="cf-btn primary sm" @click="designSelected()" :disabled="bulk.on || !designTaskCount()">
                    تصميم المحدد ({{ designTaskCount() }}) ✨</button>
            </div>
            <div class="cf-design-grid">
                <article class="cf-card cf-dcard" v-for="i in designable()" :key="i.id" :class="{ sel: dsel[i.id], video: i.format === 'video' }">
                    <!-- 🎬 الفيديو: مفيش توليد تلقائي — سكريبت ← اعتماد ← واتساب ← تنفيذ يدوي -->
                    <div class="pv-c" v-if="i.format === 'video'">
                        <div class="cf-vprev"><span>🎬</span><b>{{ i.video ? i.video.status.label : 'السكريبت جاهز' }}</b>
                            <small>{{ i.video ? i.video.brief.type + ' · ' + i.video.brief.duration + ' · ' + i.video.brief.ratio : '' }}</small></div>
                        <div class="cf-split">
                            <div><small class="cf-muted">#{{ i.n }} · فيديو</small><b class="cf-dtitle">{{ i.title }}</b></div>
                            <span class="cf-chip" :class="i.video ? i.video.status.cls : 'grey'">{{ i.video ? i.video.status.label : '' }}</span>
                        </div>
                        <div class="cf-row" v-if="i.video">
                            <button type="button" class="cf-btn ghost sm" @click="active = i.id; mode = 'script'; go(2)">راجع السكريبت</button>
                            <button type="button" class="cf-btn soft sm grow" v-if="i.video.status.key === 'script'" @click="videoAct(i, 'video_approve')" :disabled="bulk.on">✓ اعتمد السكريبت</button>
                            <button type="button" class="cf-btn primary sm grow cf-wa" v-if="i.video.status.key === 'approved'" @click="videoRequest(i)" :disabled="bulk.on || !i.video.wa || null">📲 تواصل معنا لتنفيذ الفيديو</button>
                            <a class="cf-btn ghost sm grow" v-if="['sent', 'in_production', 'ready', 'delivered'].indexOf(i.video.status.key) >= 0" :href="base + '/content-history.php?open=' + i.content.id" target="_blank" rel="noopener">متابعة الطلب</a>
                        </div>
                    </div>
                    <div class="pv-c" v-if="i.format !== 'video'">
                    <div class="cf-dprev" :class="{ story: i.format === 'story' }">
                        <button type="button" class="cf-check" :class="{ on: dsel[i.id] }" role="checkbox" :aria-checked="dsel[i.id] ? 'true' : 'false'"
                                :aria-label="'تحديد ' + i.title" @click="dsel[i.id] = !dsel[i.id]"><?= ui_icon('check', 14) ?></button>
                        <img v-if="coverOf(i)" :src="coverOf(i)" alt="" loading="lazy">
                        <div class="cf-dtext" v-if="!coverOf(i)"><small>معاينة المحتوى</small><p>{{ i.content.hook || i.content.body.slice(0, 110) }}</p></div>
                        <div class="cf-dbusy" v-if="running[i.id]"><span class="cf-orb"><i></i></span><small>{{ i.format === 'carousel' ? 'بيصمم الشريحة ' + running[i.id] + '…' : 'بيصمم…' }}</small></div>
                    </div>
                    <!-- 🎠 شرائح الكاروسيل -->
                    <div class="cf-strip" v-if="i.format === 'carousel'">
                        <span v-for="sl in i.slides" :key="sl.n" :class="{ ok: sl.designs.length, run: running[i.id] === sl.n }" :title="'شريحة ' + sl.n + (sl.title ? ' — ' + sl.title : '')">
                            <img v-if="sl.designs.length" :src="sl.designs[0].url" alt=""><em v-if="!sl.designs.length">{{ sl.n }}</em></span>
                    </div>
                    <div class="cf-vars" v-if="i.format !== 'carousel' && i.designs.length > 1">
                        <button type="button" v-for="d in i.designs" :key="d.id" :class="{ on: d.id === (i.content.selected_design || i.designs[0].id) }"
                                @click="useDesign(i, d)" :aria-label="'اختيار التصميم ' + d.id"><img :src="d.url" alt=""></button>
                    </div>
                    <div class="cf-split">
                        <div><small class="cf-muted">#{{ i.n }} · {{ fmtOf(i).e }} {{ i.format_label }}<span v-if="i.format === 'carousel'"> · {{ i.slides_count }} شرائح</span><span v-if="i.format === 'story'"> · 9:16</span></small><b class="cf-dtitle">{{ i.title }}</b></div>
                        <span class="cf-chip" :class="designDone(i) ? 'ok' : 'grey'">{{ designChip(i) }}</span>
                    </div>
                    <div class="cf-row">
                        <button type="button" class="cf-btn soft sm grow" @click="designOne(i)" :disabled="bulk.on || running[i.id] || locked(i)">
                            {{ designBtn(i) }}</button>
                        <a v-if="i.designs.length" class="cf-pill" :href="base + '/content-history.php?open=' + i.content.id" target="_blank" rel="noopener">تعديل</a>
                    </div>
                    </div>
                </article>
            </div>
            <p class="cf-muted" v-if="!designable().length">مفيش منشورات معتمدة لسه — ارجع للتقييم واعتمد المحتوى.</p>
            <p class="cf-note" v-if="designable().length && pendingReview()">⚑ {{ pendingReview() }} منشورات لسه محتاجة تحسين ومش هنا — <button type="button" class="cf-link" @click="go(3)">ارجع للتقييم</button> وحسّنها أو اعتمدها كده.</p>
        </div>

        <!-- ══════════════ ⑤ الجدولة ══════════════ -->
        <div class="pv-c" v-if="c.stage === 5 && health.unlocked">
            <div class="cf-headline"><h2>وزّع حملتك على مدار الشهر 📅</h2><p>اسحب المحتوى على الأيام، أو اضغط على قطعة ثم على اليوم.</p></div>
            <div class="cf-row cf-plan-bar">
                <div class="cf-seg sm" aria-label="المنصة">
                    <button type="button" v-for="p in platformOpts()" :key="p.k" :class="{ on: plat === p.k }" @click="plat = p.k">{{ p.label }}</button>
                </div>
                <span class="cf-grow"></span>
                <button type="button" class="cf-btn primary" @click="autoPlan()" :disabled="bulk.on || !plannable().length">وزّعها تلقائيًا ✨</button>
            </div>
            <div class="cf-plan-grid">
                <section class="cf-card cf-cal" @click.stop>
                    <div class="cf-split">
                        <div class="cf-row"><button type="button" class="cf-pill sm" @click="shiftMonth(1)" aria-label="الشهر اللي بعده">›</button>
                            <b>{{ monthLabel() }}</b>
                            <button type="button" class="cf-pill sm" @click="shiftMonth(-1)" aria-label="الشهر اللي فات">‹</button></div>
                        <span class="cf-legend"><i class="fb"></i>فيسبوك <i class="ig"></i>إنستجرام <i class="both"></i>الاتنين</span>
                    </div>
                    <div class="cf-cal-head"><span v-for="d in ['س','ح','ن','ث','ر','خ','ج']" :key="d">{{ d }}</span></div>
                    <div class="cf-cal-grid">
                        <div v-for="cell in monthCells()" :key="cell.key" class="cf-day"
                             :class="{ blank: !cell.day, past: cell.past, today: cell.today, drop: dropOn === cell.date, pickable: pick && cell.day && !cell.past }"
                             @dragover.prevent="cell.day && !cell.past ? dropOn = cell.date : null" @dragleave="dropOn = ''"
                             @drop.prevent="dropTo(cell)" @click.stop="pickTo(cell)">
                            <span class="cf-day-n" v-if="cell.day">{{ cell.day }}</span>
                            <span class="cf-day-items">
                                <button type="button" v-for="i in cell.items" :key="i.id" class="cf-chipitem" draggable="true"
                                        @dragstart="dragId = i.id" @click.stop="pick && pick !== i.id ? pickTo(cell) : (pick = pick === i.id ? null : i.id)" :class="[i.plan.platform, { picked: pick === i.id }]" :title="i.title + ' — ' + i.plan.at.slice(11)">#{{ i.n }}</button>
                            </span>
                        </div>
                    </div>
                </section>
                <aside class="cf-plan-side">
                    <section class="cf-card" @click.stop>
                        <b class="cf-lbl">محتوى غير مجدول ({{ unplanned().length }})</b>
                        <p class="cf-muted" v-if="!unplanned().length">✓ كل المنشورات ليها مواعيد</p>
                        <div class="cf-chip-wrap">
                            <button type="button" v-for="i in unplanned()" :key="i.id" class="cf-chipitem big" :class="{ picked: pick === i.id }" draggable="true"
                                    @dragstart="dragId = i.id" @click.stop="pick = pick === i.id ? null : i.id">#{{ i.n }} {{ i.title.slice(0, 18) }}</button>
                        </div>
                        <small class="cf-muted" v-if="pick">اضغط على يوم في التقويم علشان تحط #{{ ideaById(pick).n }} فيه</small>
                    </section>
                    <div class="cf-tabs wide" role="tablist">
                        <button type="button" v-for="w in [1,2,3,4,5]" :key="w" v-show="w < 5 || weekItems(5).length" :class="{ on: week === w }" @click="week = w">{{ weekName(w) }}</button>
                    </div>
                    <section class="cf-card cf-week">
                        <p class="cf-muted" v-if="!weekItems(week).length">مفيش محتوى مجدول في {{ weekName(week) }} لسه.</p>
                        <div class="cf-witem" v-for="i in weekItems(week)" :key="i.id">
                            <div class="cf-grow"><b>#{{ i.n }} {{ i.title }}</b><small>{{ dayName(i.plan.at) }}</small></div>
                            <input type="time" class="cf-input xs" :value="i.plan.at.slice(11, 16)" @change="setTime(i, $event.target.value)" aria-label="الساعة">
                            <select class="cf-input xs" :value="i.plan.platform" @change="setPlat(i, $event.target.value)" aria-label="المنصة">
                                <option v-for="p in platformOpts()" :key="p.k" :value="p.k">{{ p.label }}</option>
                            </select>
                            <button type="button" class="cf-x" @click="unplan(i)" aria-label="شيل الموعد">×</button>
                        </div>
                    </section>
                </aside>
            </div>
        </div>

        <!-- ══════════════ ⑥ النشر ══════════════ -->
        <div class="pv-c" v-if="c.stage === 6 && health.unlocked">
            <div class="cf-headline"><h2>حملتك جاهزة للنشر 🚀</h2><p>ملخص سريع لكل اللي اتعمل في {{ c.title }}.</p></div>
            <div class="cf-stats">
                <div v-for="s in summary()" :key="s.l" class="cf-card cf-stat"><b>{{ s.v }}</b><small>{{ s.l }}</small></div>
            </div>
            <small class="cf-muted">المنصات: {{ platformsText() }}</small>
            <div class="cf-pub-grid">
                <section class="cf-card cf-check-list">
                    <b class="cf-lbl">قائمة المراجعة</b>
                    <div class="cf-ck" v-for="k in [1,2,3,4,5]" :key="k">
                        <span class="cf-ck-ic" :class="{ ok: stageDone(k) }"><?= ui_icon('check', 13) ?></span>
                        <b>{{ stages[k].name }}</b><small class="cf-muted">{{ stageNote(k) }}</small>
                        <span class="cf-grow"></span>
                        <button type="button" class="cf-link" @click="go(k)">تعديل</button>
                    </div>
                </section>

                <!-- النشر مش متاح للباقة -->
                <section class="cf-card cf-upgrade" v-if="!pub.allowed">
                    <span class="cf-chip dark">باقتك: الأساسية</span>
                    <h3>النشر المباشر متاح في الباقات الأعلى</h3>
                    <p>يمكنك تجهيز وجدولة حملتك بالكامل، ثم الترقية للنشر مباشرة من Spread AI.</p>
                    <div class="cf-row">
                        <a class="cf-btn primary grow" :href="base + '/profile.php?open=plans'">ترقية الباقة</a>
                        <a class="cf-btn ghost" :href="base + '/campaign-export.php?id=' + c.id">⬇ تصدير المحتوى</a>
                    </div>
                </section>

                <!-- متاح ومفيش صفحة -->
                <section class="cf-card cf-upgrade" v-if="pub.allowed && !pub.pages.length">
                    <h3>اربط صفحتك علشان تنشر</h3>
                    <p>اربط صفحة فيسبوك (ومعاها إنستجرام بيزنس) وSpread AI هينشر كل حملتك في مواعيدها.</p>
                    <div class="cf-row">
                        <a class="cf-btn primary grow" :href="base + '/social/connect.php'">اربط صفحة</a>
                        <a class="cf-btn ghost" :href="base + '/campaign-export.php?id=' + c.id">⬇ تصدير المحتوى</a>
                    </div>
                </section>

                <!-- النشر -->
                <section class="cf-card cf-publish" v-if="pub.allowed && pub.pages.length">
                    <b class="cf-lbl">المنصات المربوطة</b>
                    <div class="cf-plats">
                        <button type="button" v-for="p in pub.pages" :key="p.id" class="cf-page" :class="{ on: page === p.id }" @click="page = p.id">
                            <img v-if="p.avatar" :src="p.avatar" alt=""><span v-if="!p.avatar">{{ p.name.slice(0, 1) }}</span>
                            <b>{{ p.name }}</b><small>Facebook{{ p.ig ? ' · Instagram' : '' }}</small>
                        </button>
                        <span class="cf-page soon"><b>TikTok</b><small>قريبًا</small></span>
                        <span class="cf-page soon"><b>LinkedIn</b><small>قريبًا</small></span>
                    </div>
                    <div class="cf-pub-list">
                        <div class="cf-pub-row" v-for="i in publishable()" :key="i.id">
                            <img v-if="coverOf(i)" :src="coverOf(i)" alt="">
                            <span v-if="!coverOf(i)" class="cf-pub-noimg">✎</span>
                            <div class="cf-grow"><b>#{{ i.n }} {{ i.title }}</b>
                                <small>{{ i.plan ? dayName(i.plan.at) + ' · ' + platLabel(i.plan.platform) : 'مالوش ميعاد — مش هيتنشر' }}</small></div>
                            <span class="cf-chip" :class="pubState(i).cls">{{ pubState(i).label }}</span>
                        </div>
                    </div>
                    <div class="cf-vlist" v-if="videoIdeas().length">
                        <b class="cf-lbl">🎬 الفيديوهات ({{ videoIdeas().length }}) — بتتنشر بعد التنفيذ اليدوي</b>
                        <div class="cf-pub-row" v-for="i in videoIdeas()" :key="i.id">
                            <span class="cf-pub-noimg">🎬</span>
                            <div class="cf-grow"><b>#{{ i.n }} {{ i.title }}</b><small>{{ i.video ? i.video.brief.type + ' · ' + i.video.brief.duration : '' }}</small></div>
                            <span class="cf-chip" :class="i.video ? i.video.status.cls : 'grey'">{{ i.video ? i.video.status.label : '' }}</span>
                        </div>
                    </div>
                    <p class="cf-note" v-if="unplannedPub()">🗓 {{ unplannedPub() }} منشور مالوش ميعاد — <button type="button" class="cf-link" @click="go(5)">حدّدله ميعاد</button> علشان يتنشر.</p>
                    <p class="cf-muted" v-if="noDesignIg()">⚠ إنستجرام محتاج تصميم — المنشورات من غير تصميم هتتنشر على فيسبوك بس.</p>
                    <button type="button" class="cf-btn primary big" @click="publishAll()" :disabled="bulk.on || !toPublish().length">
                        ابدأ النشر 🚀 <small v-if="toPublish().length">({{ toPublish().length }})</small></button>
                    <a class="cf-link" :href="base + '/campaign-export.php?id=' + c.id">⬇ تصدير المحتوى (CSV)</a>
                </section>
            </div>
        </div>
        </div>

        <!-- ═══════════ شريط التنقل ═══════════ -->
        <footer class="cf-nav">
            <div class="cf-nav-txt"><b>{{ navInfo().title }}</b><small>{{ navInfo().sub }}</small></div>
            <button type="button" class="cf-btn ghost" @click="go(c.stage - 1)" :disabled="c.stage === 1 || bulk.on"><?= ui_icon('chevron', 16) ?> السابق</button>
            <button type="button" class="cf-btn primary cf-next" v-if="c.stage < 6" @click="next()" :disabled="!canNext() || bulk.on">
                {{ navInfo().cta }} <span class="cf-arrow"><?= ui_icon('chevron', 16) ?></span></button>
            <a class="cf-btn ghost" v-if="c.stage === 6" :href="base + '/campaigns.php'" @click="saveAndExit()">حفظ والعودة</a>
        </footer>
    </section>

    <div class="toast-container" v-if="toast"><div class="toast" :class="toast && toast.type">{{ toast && toast.msg }}</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var init = <?= json_encode($cfInit, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  var base = document.querySelector('meta[name="app-base"]').content;
  var csrf = function () { return document.querySelector('meta[name="csrf-token"]').content; };
  var svg = function (d) { return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>'; };
  var MSG = {
    ideas: ['يحلل هوية مشروعك...', 'يبحث عن زوايا إبداعية...', 'يبني أفكار الحملة...'],
    content: ['يحلل هدف الحملة...', 'يراجع هوية البراند...', 'يكتب المحتوى...'],
    eval: ['يراجع جودة المنشور...', 'يقيس قوة الـ Hook...', 'يقارن بهوية البراند...'],
    improve: ['يراجع ملاحظات التقييم...', 'يعيد صياغة المنشور...', 'يحدّث التقييم...'],
    design: ['Spread AI يصمم...', 'يطبق هوية البراند...', 'يضبط التكوين...', 'ينشئ النسخة النهائية...'],
    plan: ['يحلل هدف الحملة ونوع المحتوى...', 'يراجع الجمهور والمنصات...', 'يوزع المحتوى على الشهر...'],
    publish: ['بيجهّز المنشورات...', 'بيبعتها للمنصات...', 'بيأكد المواعيد...'],
  };

  var app = SpreadApp.reactive({
    base: base, c: init.campaign, ideas: init.ideas, counts: init.counts, bases: init.bases, stages: init.stages,
    health: init.health, costs: init.costs, pub: init.publish, brand: init.brand,
    stageKeys: { 1: 'bulb', 2: 'doc', 3: 'star', 4: 'image', 5: 'calendar', 6: 'send' },
    icons: {
      check: svg('<path d="M20 6L9 17l-5-5"/>'),
      bulb: svg('<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.3h6c0-1 .4-1.8 1-2.3A7 7 0 0 0 12 2z"/>'),
      doc: svg('<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M8 13h8M8 17h5"/>'),
      star: svg('<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2-5.5-2.9-5.5 2.9 1-6.2L3 9.6l6.2-.9z"/>'),
      image: svg('<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>'),
      calendar: svg('<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/>'),
      send: svg('<path d="M4.5 16.5c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2.1-.1-2.9a2.2 2.2 0 0 0-2.9-.1z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.9A12.9 12.9 0 0 1 22 2c0 2.7-.8 7.5-6 11a22.4 22.4 0 0 1-4 2z"/>'),
    },
    evalCats: ['قوة الفكرة', 'قوة الـ Hook', 'مناسبة الجمهور', 'وضوح الرسالة', 'قوة CTA', 'التوافق مع البراند'],
    save: { state: 'saved', at: Date.now() }, menu: false, toast: null,
    count: [5, 10, 15, 20].indexOf(init.campaign.ideas_count) >= 0 ? init.campaign.ideas_count : 10,
    editing: 0, draft: {}, propagate: null, active: 0, mode: '', running: {}, fieldSave: {},
    ai: { on: false, msg: '' }, bulk: { on: false, stop: false, done: 0, total: 0, label: '', steps: [], stepI: 0 },
    // ⑦-ج أشكال المحتوى
    fmtList: [{ k: 'post', e: '🖼', t: 'منشور' }, { k: 'carousel', e: '🎠', t: 'كاروسيل' }, { k: 'video', e: '🎬', t: 'فيديو' }, { k: 'story', e: '📱', t: 'ستوري' }],
    limits: init.limits,
    dsel: {}, ratio: '1:1', plat: (init.campaign.prefs && init.campaign.prefs.platform) || 'facebook',
    month: '', week: 1, pick: null, dragId: null, dropOn: '', page: 0, pubRes: {},

    notify: function (m, t) { SpreadApp.toast(this, m, t || 'success'); },
    saveText: function () {
      if (this.save.state === 'saving' || this.save.state === 'dirty') return 'بيحفظ...';
      if (this.save.state === 'error' || this.save.state === 'offline') return 'تعذّر الحفظ — هنحاول تاني';
      if (this.save.state === 'conflict') return 'اتعدّلت من مكان تاني';
      return 'آخر حفظ ' + (window.spreadTimeAgo(this.save.at) || 'منذ لحظات');
    },
    statusChip: function () {
      if (this.c.status === 'completed') return { label: 'مكتملة', cls: 'ok' };
      if (this.counts.published || this.counts.queued) return { label: 'قيد النشر', cls: 'blue' };
      if (!this.ideas.length) return { label: 'مسودة', cls: 'grey' };
      if (this.c.max_stage >= 5) return { label: 'جاهزة تقريبًا', cls: 'grey' };
      return { label: 'قيد التنفيذ', cls: 'blue' };
    },

    /* ── القوايم ── */
    selected: function () { return this.ideas.filter(function (i) { return i.selected; }); },
    selectedCount: function () { return this.selected().length; },
    withContent: function () { return this.selected().filter(function (i) { return !!i.content; }); },
    missingContent: function () { return this.selected().filter(function (i) { return !i.content; }); },
    designable: function () { var skip = this.c.eval_skipped; return this.withContent().filter(function (i) { return i.approved || skip || (i.eval && i.eval.ready); }); },
    // الفيديو مش بيتجدول ولا بيتنشر من هنا — بيتنشر بعد التنفيذ اليدوي
    plannable: function () { return this.designable().filter(function (i) { return i.format !== 'video'; }); },
    videoIdeas: function () { return this.designable().filter(function (i) { return i.format === 'video'; }); },
    pendingReview: function () { return this.withContent().length - this.designable().length; },
    unplanned: function () { return this.plannable().filter(function (i) { return !i.plan; }); },
    ideaById: function (id) { return this.ideas.find(function (i) { return i.id === id; }) || {}; },
    cur: function () {
      var s = this.selected(), a = this.active;
      return s.find(function (i) { return i.id === a; }) || s[0] || null;
    },
    locked: function (i) { var p = i && i.content && i.content.publish && i.content.publish.status; return ['published', 'scheduled', 'processing', 'pending'].indexOf(p) >= 0; },
    coverOf: function (i) {
      if (i.format === 'carousel') { var s1 = (i.slides || []).find(function (s) { return s.designs.length; }); return s1 ? s1.designs[0].url : ''; }
      if (!i.designs.length) return '';
      var s = i.content && i.content.selected_design;
      var d = i.designs.find(function (x) { return x.id === s; }) || i.designs[0];
      return d.url;
    },
    brandColors: function () { return this.brand.colors.length ? this.brand.colors : ['#2EE3CC', '#0C87EF', '#0B1526']; },

    /* ── ⑦-ج أشكال المحتوى ── */
    // السكريبت الحالي (أو كائن فاضي — علشان التعبيرات ماتقعش وهي بتتشال من الشاشة)
    scr: function () { var i = this.cur(); return (i && i.script) || (this._blankScr = this._blankScr || { hook: '', body: '', cta: '', design: '' }); },
    fmtOf: function (i) { var k = i.format || 'post'; return this.fmtList.find(function (f) { return f.k === k; }) || this.fmtList[0]; },
    suggested: function (i, k) {
      var map = { post: ['منشور'], carousel: ['كاروسيل'], video: ['ريلز', 'فيديو'], story: ['ستوري', 'ستوري تفاعلية'] };
      return (i.formats || []).some(function (f) { return map[k].indexOf(f) >= 0; });
    },
    modesFor: function (i) {
      if (i.format === 'carousel') return [['slides', 'الشرائح'], ['post', 'الكابشن']];
      if (i.format === 'video') return [['script', 'السكريبت'], ['post', 'الكابشن']];
      return [['post', i.format === 'story' ? 'الستوري' : 'المنشور']];
    },
    curMode: function () { var i = this.cur(); if (!i) return 'post'; var m = this.modesFor(i).map(function (x) { return x[0]; }); return m.indexOf(this.mode) >= 0 ? this.mode : m[0]; },
    async setFormat(i, f, slides) {
      if (this.bulk.on || this.locked(i)) return;
      var n = f === 'carousel' ? (slides || i.slides_count || this.limits.def) : 0;
      if (i.format === f && (f !== 'carousel' || n === i.slides_count)) return;
      if (i.content) {
        if (!confirm('الفكرة دي ليها محتوى — تغيير الشكل لـ «' + this.fmtList.find(function (x) { return x.k === f; }).t + (f === 'carousel' ? ' ' + n + ' شرائح' : '') + '» هيعيد كتابتها' + (window.SPREAD_CR !== false ? ' (' + this.costs.regen + ' كريدت)' : '') + '. نكمّل؟')) return;
        var self = this;
        await this.runBulk([i], 'بيعيد الكتابة', MSG.content, async function (x) {
          self.running[x.id] = true;
          var r = await SpreadAPI.post('campaign-flow', 'content_generate', { id: self.c.id, idea_id: x.id, regen: 1, format: f, slides: n });
          self.running[x.id] = false; if (r.ok) { self.putIdea(r.idea); self.notify('اتكتبت كـ ' + r.idea.format_label + ' ✓'); } return r;
        });
        return;
      }
      var r = await SpreadAPI.post('campaign-flow', 'idea_format', { id: this.c.id, idea_id: i.id, format: f, slides: n });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.putIdea(r.idea);
    },
    setSlides: function (i, d) {
      var n = Math.max(this.limits.min, Math.min(this.limits.max, (i.slides_count || this.limits.def) + d));
      var self = this;
      if (i.content) return this.setFormat(i, 'carousel', n);
      i.slides_count = n;   // يبان فورًا — والحفظ بعد ثانية (لو ضغط كذا مرة)
      clearTimeout(this._sl); this._sl = setTimeout(function () { SpreadAPI.post('campaign-flow', 'idea_format', { id: self.c.id, idea_id: i.id, format: 'carousel', slides: i.slides_count }); }, 700);
    },
    designDone: function (i) {
      if (i.format === 'carousel') return (i.slides || []).length > 0 && i.slides.every(function (s) { return s.designs.length; });
      if (i.format === 'video') return false;
      return i.designs.length > 0;
    },
    missingSlides: function (i) { return (i.slides || []).filter(function (s) { return !s.designs.length; }).map(function (s) { return s.n; }); },
    // مهام التصميم لفكرة: كاروسيل = الشرائح الناقصة (أو كلها لو «إعادة») · منشور/ستوري = تصميم واحد · فيديو = مفيش
    designTasks: function (i, redo) {
      if (i.format === 'video') return [];
      if (i.format === 'carousel') { var m = this.missingSlides(i); if (!m.length && redo) m = i.slides.map(function (s) { return s.n; }); return m.map(function (n) { return { i: i, slide: n }; }); }
      return [{ i: i, slide: 0 }];
    },
    designTaskCount: function () {
      var s = this.dsel, self = this;
      return this.designable().filter(function (i) { return s[i.id] && !self.locked(i); }).reduce(function (t, i) { return t + self.designTasks(i, false).length; }, 0);
    },
    designChip: function (i) {
      if (i.format === 'carousel') { var d = i.slides.length - this.missingSlides(i).length; return d === i.slides.length ? '✓ ' + d + ' شرائح' : d + ' من ' + i.slides.length + ' شرائح'; }
      return i.designs.length ? '✓ مصمم' : 'بانتظار التصميم';
    },
    designBtn: function (i) {
      if (i.format === 'carousel') { var m = this.missingSlides(i).length; return m ? 'صمّم الشرائح (' + m + ') ✨' : '↻ إعادة الشرائح (' + i.slides.length + ')'; }
      return i.designs.length ? '↻ إعادة التصميم' : 'تصميم ✨';
    },
    async videoAct(i, action) {
      var r = await SpreadAPI.post('contents', action, { id: i.content.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      i.video = r.video; this.notify('اعتمدت السكريبت ✓ — اطلب التنفيذ من فريقنا');
    },
    async videoRequest(i) {
      var w = window.open('about:blank', '_blank');
      var r = await SpreadAPI.post('contents', 'video_request', { id: i.content.id });
      if (!r.ok) { if (w) w.close(); return this.notify(r.error, 'danger'); }
      i.video = r.video;
      if (w) w.location.href = r.wa; else location.href = r.wa;
      this.notify('جهّزنا رسالة الطلب على واتساب 📲');
    },

    /* ── المراحل ── */
    canGo: function (n) { return n <= this.c.max_stage + 1 && n >= 1 && !(n > 1 && !this.health.unlocked && n !== this.c.stage); },
    stepCls: function (n) { return { on: this.c.stage === n, done: n <= this.c.max_stage && n !== this.c.stage, locked: !this.canGo(n) }; },
    stageDone: function (k) {
      var q = this.counts;
      return [0, q.selected > 0, q.posts > 0 && q.posts >= q.selected, q.evaluated > 0 || this.c.eval_skipped, q.designed > 0, q.planned > 0][k];
    },
    stageNote: function (k) {
      var q = this.counts;
      return ['', q.selected + ' فكرة مختارة', q.posts + ' منشور · ' + q.scripts + ' سكريبت', this.c.eval_skipped ? 'اتخطى' : q.evaluated + ' اتقيّم',
              q.designed + ' تصميم', q.planned + ' مجدول'][k];
    },
    canNext: function () {
      var s = this.c.stage;
      if (s === 1) return this.selectedCount() > 0 && this.health.unlocked;
      if (s === 2) return this.withContent().length > 0;
      return true;
    },
    navInfo: function () {
      var s = this.c.stage, sel = this.selectedCount(), wc = this.withContent().length;
      if (s === 1) return { title: 'تم اختيار ' + sel + ' ' + (sel === 1 ? 'فكرة' : 'أفكار'), sub: !this.health.unlocked ? 'كمّل هوية البراند لـ ' + this.health.gate + '% علشان نكمل' : 'التالي: المحتوى', cta: 'تحويل الأفكار إلى محتوى' };
      if (s === 2) return { title: sel + ' أفكار · ' + (wc === sel ? 'المحتوى جاهز' : wc + ' جاهز'), sub: 'التالي: التقييم', cta: 'التالي: تقييم المحتوى' };
      if (s === 3) return { title: this.evalCount('ready') + ' من ' + wc + ' جاهزة', sub: 'التالي: التصميم', cta: 'اعتماد المحتوى' };
      if (s === 4) { var self4 = this, dd = this.plannable(), d = dd.filter(function (i) { return self4.designDone(i); }).length; return { title: d + ' من ' + dd.length + ' تم تصميمها' + (this.videoIdeas().length ? ' · ' + this.videoIdeas().length + ' فيديو' : ''), sub: 'التالي: الجدولة', cta: 'التالي: جدولة المحتوى' }; }
      if (s === 5) { var p = this.plannable(); return { title: p.filter(function (i) { return i.plan; }).length + ' من ' + p.length + ' مجدولة', sub: 'التالي: النشر', cta: 'التالي: النشر' }; }
      return { title: 'كل المراحل محفوظة', sub: 'آخر مرحلة', cta: '' };
    },
    async go(n) {
      if (n < 1 || n > 6 || n === this.c.stage || this.bulk.on || !this.canGo(n)) return;
      await saver.flush();
      var r = await SpreadAPI.post('campaigns', 'stage', { id: this.c.id, stage: n });
      if (!r.ok) { if (r.health) this.health = r.health; return this.notify(r.error, 'danger'); }
      this.c.stage = r.campaign.stage; this.c.max_stage = r.campaign.max_stage; this.c.revision = r.campaign.revision;
      this.menu = false; this.pick = null;
      window.scrollTo({ top: 0, behavior: 'smooth' });
      var m = document.querySelector('.cf-main'); if (m) m.scrollTop = 0;
    },
    async next() {
      var s = this.c.stage;
      if (!this.canNext()) return;
      if (s === 3) { await SpreadAPI.post('campaign-flow', 'approve', { id: this.c.id }); await this.reload(); }
      await this.go(s + 1);
      // الانتقال بالزرار = ابدأ الشغل تلقائي (زي التصميم)
      if (s === 1 && this.missingContent().length) this.genContent();
      if (s === 2 && this.withContent().some(function (i) { return !i.eval; }) && !this.c.eval_skipped && !this.costs.eval) this.runEval();
      if (s === 4 && !this.plannable().some(function (i) { return i.plan; }) && this.plannable().length) this.autoPlan();
    },
    async reload() {
      var r = await SpreadAPI.get('campaign-flow', { action: 'board', id: this.c.id });
      if (!r.ok) return;
      this.setIdeas(r.ideas); this.counts = r.counts; this.costs = r.costs; this.pub = r.publish; this.health = r.health;
      this.c.eval_skipped = r.campaign.eval_skipped;
    },
    // تحديث الفكرة في مكانها — v-for بالـ key بيعيد استخدام نفس العنصر، فاستبدال الكائن كله مابيوصلش للشاشة
    // قايمة جديدة من السيرفر: نفس الكائنات القديمة بتتحدّث (نفس سبب putIdea)
    setIdeas: function (list) {
      var old = {}; this.ideas.forEach(function (i) { old[i.id] = i; });
      this.ideas = list.map(function (n) { var ex = old[n.id]; if (!ex) return n; Object.keys(n).forEach(function (k) { ex[k] = n[k]; }); return ex; });
    },
    putIdea: function (idea) {
      var ex = this.ideas.find(function (i) { return i.id === idea.id; });
      if (ex) Object.keys(idea).forEach(function (k) { ex[k] = idea[k]; });
    },

    /* ── محرك الشغل الجماعي: فكرة فكرة + رسائل التفكير + إيقاف ── */
    async runBulk(list, label, steps, fn) {
      if (this.bulk.on || !list.length) return { ok: 0, fail: 0 };
      var b = this.bulk, self = this;
      b.on = true; b.stop = false; b.done = 0; b.total = list.length; b.label = label; b.steps = steps; b.stepI = 0;
      this.ai.on = true; this.ai.msg = steps[0];
      var t = setInterval(function () { b.stepI = (b.stepI + 1) % steps.length; self.ai.msg = steps[b.stepI]; }, 1400);
      var ok = 0, fail = 0, lastErr = '';
      for (var k = 0; k < list.length; k++) {
        if (b.stop) break;
        var r = await fn(list[k], k);
        if (r && r.ok) ok++; else { fail++; lastErr = (r && r.error) || lastErr; if (r && (r.code === 'credits' || r.code === 'quota' || r.code === 'rate_limit' || r.code === 'brand_gate' || r.code === 'state')) break; }
        b.done = k + 1;
      }
      clearInterval(t);
      b.on = false; this.ai.on = false;
      if (r && r.balance !== undefined) this.costs.balance = r.balance;
      this.refreshCounts();
      if (fail) this.notify('اتعمل ' + ok + ' من ' + list.length + (lastErr ? ' — ' + lastErr : ''), ok ? 'warning' : 'danger');
      else if (b.stop) this.notify('وقفنا — اتعمل ' + ok + ' من ' + list.length, 'warning');
      return { ok: ok, fail: fail };
    },
    async refreshCounts() {
      var r = await SpreadAPI.get('campaign-flow', { action: 'board', id: this.c.id });
      if (r.ok) { this.counts = r.counts; this.costs.balance = r.costs.balance; }
    },

    /* ── ① الأفكار ── */
    touch: function () { saver.touch(); },
    async genIdeas() {
      var total = this.count, batches = Math.ceil(total / 5), self = this, list = [];
      if (!this.c.basis) return this.notify('اختار أساس الحملة الأول', 'warning');
      if (this.ideas.length && !confirm('الأفكار اللي مش مختارة هتتبدل بأفكار جديدة — نكمّل؟')) return;
      await saver.flush();
      for (var k = 0; k < batches; k++) list.push(k);
      var res = await this.runBulk(list, 'دفعة الأفكار', MSG.ideas, async function (batch) {
        var r = await SpreadAPI.post('campaign-flow', 'ideas_generate', { id: self.c.id, batch: batch, total: total });
        if (r.ok) { self.setIdeas(r.ideas); self.costs.balance = r.balance; }
        return r;
      });
      if (res.ok) this.notify('جهزنا أفكار حملتك ✨');
    },
    async toggleIdea(i) {
      i.selected = !i.selected;
      var r = await SpreadAPI.post('campaign-flow', 'idea_toggle', { id: this.c.id, idea_id: i.id, selected: i.selected ? 1 : 0 });
      if (!r.ok) { i.selected = !i.selected; this.notify(r.error, 'danger'); }
      this.counts.selected = this.selectedCount();
    },
    async selectAll(v) {
      var r = await SpreadAPI.post('campaign-flow', 'idea_select_all', { id: this.c.id, selected: v ? 1 : 0 });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.ideas.forEach(function (i) { i.selected = v; });
    },
    startEdit: function (i) { this.editing = i.id; this.draft = { title: i.title, desc: i.desc, audience: i.audience, hook: i.hook }; },
    async saveIdea(i) {
      var r = await SpreadAPI.post('campaign-flow', 'idea_save', Object.assign({ id: this.c.id, idea_id: i.id }, this.draft));
      if (!r.ok) return this.notify(r.error, 'danger');
      this.putIdea(r.idea); this.editing = 0;
      this.notify('تم تعديل الفكرة.');
      if (r.has_content && !this.locked(r.idea)) this.propagate = r.idea.id;
    },
    async deleteIdea(i) {
      if (!confirm('حذف الفكرة؟' + (i.content ? ' (المنشور بتاعها هيفضل في المكتبة)' : ''))) return;
      var r = await SpreadAPI.post('campaign-flow', 'idea_delete', { id: this.c.id, idea_id: i.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.ideas = this.ideas.filter(function (x) { return x.id !== i.id; }); this.editing = 0;
    },
    async propagateYes() {
      var i = this.ideaById(this.propagate); this.propagate = null;
      if (i.id) await this.regen(i, true);
    },

    /* ── ② المحتوى ── */
    async genContent() {
      var self = this, list = this.missingContent();
      if (!list.length) return;
      if (this.costs.balance < list.length * this.costs.content) {
        if (!confirm(window.SPREAD_CR !== false ? 'رصيدك (' + this.costs.balance + ') يكفي ' + Math.floor(this.costs.balance / Math.max(1, this.costs.content)) + ' منشور بس من ' + list.length + ' — نكمّل باللي يكفي؟' : 'باقة الشهر مش هتكفي كل المنشورات دي — نكمّل باللي يكفي؟')) return;
      }
      if (!this.active && list[0]) this.active = list[0].id;
      await this.runBulk(list, 'بيكتب المنشور', MSG.content, async function (i) {
        self.running[i.id] = true;
        var r = await SpreadAPI.post('campaign-flow', 'content_generate', { id: self.c.id, idea_id: i.id });
        self.running[i.id] = false;
        if (r.ok) { self.putIdea(r.idea); self.costs.balance = r.balance !== undefined ? r.balance : self.costs.balance; }
        return r;
      });
    },
    async genOne(i) { var self = this; await this.runBulk([i], 'بيكتب', MSG.content, async function (x) {
      self.running[x.id] = true; var r = await SpreadAPI.post('campaign-flow', 'content_generate', { id: self.c.id, idea_id: x.id });
      self.running[x.id] = false; if (r.ok) self.putIdea(r.idea); return r; }); },
    async regen(i, auto) {
      if (!auto && !confirm('نكتب نسخة جديدة؟ (' + (window.SPREAD_CR !== false ? this.costs.regen + ' كريدت — ' : '') + 'النسخة الحالية بتتحفظ في سجل النسخ)')) return;
      var self = this;
      await this.runBulk([i], 'بيعيد الكتابة', MSG.content, async function (x) {
        self.running[x.id] = true;
        var r = await SpreadAPI.post('campaign-flow', 'content_generate', { id: self.c.id, idea_id: x.id, regen: 1 });
        self.running[x.id] = false; if (r.ok) { self.putIdea(r.idea); self.notify('نسخة جديدة جاهزة ✓'); } return r;
      });
    },
    edited: function (i, mode) {
      var self = this, key = i.id + (mode || 'post');
      this.fieldSave[i.id] = 'فيه تعديلات…';
      clearTimeout((this._t = this._t || {})[key]);
      this._t[key] = setTimeout(async function () {
        self.fieldSave[i.id] = 'بيحفظ...';
        var src = mode === 'script' ? i.script : i.content;
        var payload = mode === 'slides'
          ? { id: self.c.id, idea_id: i.id, mode: 'slides', slides: i.slides.map(function (x) { return { title: x.title, text: x.text, design: x.design }; }) }
          : { id: self.c.id, idea_id: i.id, mode: mode || 'post', hook: src.hook, body: src.body, cta: src.cta, tags: src.tags, design: mode === 'script' ? src.design : src.design_idea };
        var r = await SpreadAPI.post('campaign-flow', 'content_save', payload);
        if (r.ok && r.video_status && i.video) i.video.status = r.video_status;
        self.fieldSave[i.id] = r.ok ? '✓ اتحفظ' : (r.error || 'تعذّر الحفظ');
        if (r.ok && r.eval_reset) { i.eval = null; i.approved = false; }
      }, 900);
    },
    copyCur: function () {
      var i = this.cur(), co = i.content, t = this.curMode() === 'script' && i.script ? (i.script.hook + '\n\n' + i.script.body) : (co.hook + '\n\n' + co.body + '\n\n' + co.cta + '\n\n' + co.tags);
      var self = this;
      (navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(t) : Promise.reject()).then(
        function () { self.notify('اتنسخ ✓'); }, function () { self.notify('انسخ يدويًا', 'warning'); });
    },

    /* ── ③ التقييم ── */
    evalCount: function (k) {
      return this.withContent().filter(function (i) { return k === 'none' ? !i.eval : (i.eval && (k === 'ready' ? i.eval.ready : !i.eval.ready)); }).length;
    },
    async runEval() {
      var self = this, list = this.withContent().filter(function (i) { return !i.eval; });
      await this.runBulk(list, 'بيقيّم', MSG.eval, async function (i) {
        self.running[i.id] = true;
        var r = await SpreadAPI.post('campaign-flow', 'evaluate', { id: self.c.id, idea_id: i.id });
        self.running[i.id] = false; if (r.ok) self.putIdea(r.idea); return r;
      });
    },
    async evalOne(i) { await this.runBulk([i], 'بيقيّم', MSG.eval, async (x) => {
      var r = await SpreadAPI.post('campaign-flow', 'evaluate', { id: this.c.id, idea_id: x.id }); if (r.ok) this.putIdea(r.idea); return r; }); },
    async improve(i) {
      var self = this;
      await this.runBulk([i], 'بيحسّن', MSG.improve, async function (x) {
        var r = await SpreadAPI.post('campaign-flow', 'improve', { id: self.c.id, idea_id: x.id });
        if (!r.ok) return r;
        self.putIdea(r.idea);
        // التحسين بيتقيّم تاني تلقائي
        var e = await SpreadAPI.post('campaign-flow', 'evaluate', { id: self.c.id, idea_id: x.id });
        if (e.ok) { self.putIdea(e.idea); self.notify('بعد التحسين: ' + e.idea.eval.score + ' / 100'); }
        return e;
      });
    },
    async approveOne(i) {
      var r = await SpreadAPI.post('campaign-flow', 'approve', { id: this.c.id, idea_ids: [i.id] });
      if (!r.ok) return this.notify(r.error, 'danger');
      i.approved = true; this.notify('اتعتمد ✓');
    },
    async skipEval() {
      var r = await SpreadAPI.post('campaign-flow', 'skip_eval', { id: this.c.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.c.eval_skipped = true; this.ideas.forEach(function (i) { if (i.content && i.selected) i.approved = true; });
      this.notify('تم تخطي التقييم');
      await this.go(4);
    },

    /* ── ④ التصميم ── */
    designSelCount: function () { var s = this.dsel; return this.designable().filter(function (i) { return s[i.id]; }).length; },
    designSelAll: function () {
      var all = this.designSelCount() < this.designable().length, s = this.dsel;
      this.designable().forEach(function (i) { s[i.id] = all; });
    },
    async designReq(i, slide) {
      var p = { csrf: csrf(), _quiet: '1', content_id: i.content.id, bulk: '1', ratio: i.format === 'story' ? '9:16' : this.ratio, include_logo: '1' };
      if (slide) p.slide_no = slide;   // الكاروسيل: الشريحة بنصها وفكرة تصميمها (من السيرفر) + الشريحة الأولى مرجع
      else { p.custom_prompt = this.b64(i.content.design_idea || ''); p._b64 = 'custom_prompt'; }
      var r = await ajaxPost(this.base + '/ajax/generate-design.php', p);
      return r || { ok: false, error: 'تعذّر التصميم' };
    },
    // تصميم اتعمل ← نحدّث الفكرة في مكانها
    designAdd: function (i, r, slide) {
      var d = { id: r.design_id, url: r.image_url, ratio: r.ratio || this.ratio, slide: slide || 0 };
      i.designs.unshift(d);
      if (slide) { var sl = i.slides.find(function (s) { return s.n === slide; }); if (sl) sl.designs.unshift(d); }
      else i.content.selected_design = r.design_id;
      this.costs.balance -= this.costs.design;
    },
    b64: function (s) { return btoa(unescape(encodeURIComponent(s))); },
    async designSelected() {
      var self = this, s = this.dsel, tasks = [];
      this.designable().filter(function (i) { return s[i.id] && !self.locked(i); }).forEach(function (i) { tasks = tasks.concat(self.designTasks(i, false)); });
      if (this.costs.balance < tasks.length * this.costs.design && !confirm(window.SPREAD_CR !== false ? 'رصيدك يكفي ' + Math.floor(this.costs.balance / Math.max(1, this.costs.design)) + ' تصميم بس من ' + tasks.length + ' — نكمّل؟' : 'باقة الشهر مش هتكفي كل التصميمات دي — نكمّل باللي يكفي؟')) return;
      var res = await this.runBulk(tasks, 'بيصمم', MSG.design, async function (t) {
        self.running[t.i.id] = t.slide || true;
        var r = await self.designReq(t.i, t.slide);
        self.running[t.i.id] = false;
        if (r.ok) { self.designAdd(t.i, r, t.slide); if (self.designDone(t.i)) s[t.i.id] = false; }
        return r;
      });
      if (res.ok && !res.fail) this.notify('اتعمل ' + res.ok + ' تصميم 🎨');
    },
    async designOne(i) {
      var self = this, tasks = this.designTasks(i, true);
      if (i.format === 'carousel' && !this.missingSlides(i).length && !confirm('نعيد تصميم الـ ' + tasks.length + ' شرائح؟' + (window.SPREAD_CR !== false ? ' (' + tasks.length * this.costs.design + ' كريدت)' : ''))) return;
      await this.runBulk(tasks, i.format === 'carousel' ? 'بيصمم الشرائح' : 'بيصمم', MSG.design, async function (t) {
        self.running[t.i.id] = t.slide || true; var r = await self.designReq(t.i, t.slide); self.running[t.i.id] = false;
        if (r.ok) self.designAdd(t.i, r, t.slide);
        return r;
      });
    },
    async useDesign(i, d) {
      var r = await SpreadAPI.post('contents', 'use_design', { id: i.content.id, design_id: d.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      i.content.selected_design = d.id; this.notify('اتختار التصميم ✓');
    },

    /* ── ⑤ الجدولة ── */
    platformOpts: function () {
      var ig = this.pub.pages.some(function (p) { return p.ig; });
      var o = [{ k: 'facebook', label: 'فيسبوك' }];
      if (ig || !this.pub.pages.length) o.push({ k: 'instagram', label: 'إنستجرام' }, { k: 'both', label: 'الاتنين' });
      return o;
    },
    platLabel: function (k) { return { facebook: 'فيسبوك', instagram: 'إنستجرام', both: 'فيسبوك + إنستجرام' }[k] || k; },
    curMonth: function () {
      if (this.month) return this.month;
      var first = this.plannable().filter(function (i) { return i.plan; }).map(function (i) { return i.plan.at.slice(0, 7); }).sort()[0];
      var now = new Date(), cm = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
      var pm = this.c.period_month && this.c.period_month >= cm ? this.c.period_month : cm;
      return first || pm;
    },
    shiftMonth: function (d) {
      var p = this.curMonth().split('-').map(Number), dt = new Date(p[0], p[1] - 1 + d, 1);
      this.month = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0');
    },
    monthLabel: function () {
      var p = this.curMonth().split('-').map(Number);
      return ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'][p[1] - 1] + ' ' + p[0];
    },
    monthCells: function () {
      var p = this.curMonth().split('-').map(Number), y = p[0], m = p[1];
      var first = new Date(y, m - 1, 1), days = new Date(y, m, 0).getDate();
      var lead = (first.getDay() + 1) % 7;   // الأسبوع بيبدأ السبت
      var today = new Date(); today.setHours(0, 0, 0, 0);
      var byDate = {}, cells = [];
      this.plannable().forEach(function (i) { if (i.plan) { var d = i.plan.at.slice(0, 10); (byDate[d] = byDate[d] || []).push(i); } });
      for (var k = 0; k < lead; k++) cells.push({ key: 'e' + k, day: 0, items: [] });
      for (var d = 1; d <= days; d++) {
        var ds = y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0'), dt = new Date(y, m - 1, d);
        cells.push({ key: ds, day: d, date: ds, items: byDate[ds] || [], past: dt < today, today: dt.getTime() === today.getTime() });
      }
      return cells;
    },
    weekOf: function (at) { var d = Number(at.slice(8, 10)); return d <= 7 ? 1 : d <= 14 ? 2 : d <= 21 ? 3 : d <= 28 ? 4 : 5; },
    weekName: function (w) { return ['', 'أسبوع 1', 'أسبوع 2', 'أسبوع 3', 'أسبوع 4', 'آخر الشهر'][w]; },
    weekItems: function (w) {
      var m = this.curMonth(), self = this;
      return this.plannable().filter(function (i) { return i.plan && i.plan.at.slice(0, 7) === m && self.weekOf(i.plan.at) === w; })
        .sort(function (a, b) { return a.plan.at < b.plan.at ? -1 : 1; });
    },
    dayName: function (at) {
      if (!at) return '';
      var d = new Date(at.replace(' ', 'T'));
      return ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'][d.getDay()] + ' ' + d.getDate() + '/' + (d.getMonth() + 1) + ' · ' + at.slice(11, 16);
    },
    async planSet(i, at, platform) {
      var r = await SpreadAPI.post('campaign-flow', 'plan_set', { id: this.c.id, idea_id: i.id, at: at || '', platform: platform || this.plat });
      if (!r.ok) return this.notify(r.error, 'danger');
      i.plan = r.plan; this.counts.planned = this.plannable().filter(function (x) { return x.plan; }).length;
    },
    hourDefault: function () { return String(this.pub.hour || 21).padStart(2, '0') + ':00'; },
    dropTo: function (cell) {
      this.dropOn = '';
      if (!cell.day || cell.past || !this.dragId) return;
      var i = this.ideaById(this.dragId); this.dragId = null;
      this.planSet(i, cell.date + ' ' + (i.plan ? i.plan.at.slice(11, 16) : this.hourDefault()), i.plan ? i.plan.platform : this.plat);
    },
    pickTo: function (cell) {
      if (!this.pick || !cell.day || cell.past) return;
      var i = this.ideaById(this.pick); this.pick = null;
      this.planSet(i, cell.date + ' ' + (i.plan ? i.plan.at.slice(11, 16) : this.hourDefault()), i.plan ? i.plan.platform : this.plat);
    },
    setTime: function (i, t) { if (t) this.planSet(i, i.plan.at.slice(0, 10) + ' ' + t, i.plan.platform); },
    setPlat: function (i, p) { this.planSet(i, i.plan.at, p); },
    unplan: function (i) { this.planSet(i, '', i.plan.platform); },
    async autoPlan() {
      var self = this, only = this.plannable().some(function (i) { return i.plan; }) && !confirm('نعيد توزيع كل المنشورات؟ (إلغاء = نوزّع اللي مالوش ميعاد بس)');
      var n = 0;
      await this.runBulk([1], 'بيوزّع', MSG.plan, async function () {
        var r = await SpreadAPI.post('campaign-flow', 'auto_plan', { id: self.c.id, platform: self.plat, only_unplanned: only ? 1 : 0 });
        if (r.ok) { self.setIdeas(r.ideas); n = r.planned; self.month = ''; }
        return r;
      });
      if (n) { this.notify('تم توزيع ' + n + ' ' + (n > 10 ? 'منشورًا' : 'منشورات') + ' على الشهر.'); this.week = this.firstWeek(); }
    },
    firstWeek: function () { for (var w = 1; w <= 5; w++) if (this.weekItems(w).length) return w; return 1; },

    /* ── ⑥ النشر ── */
    summary: function () {
      var q = this.counts, plats = this.platformsSet();
      return [{ l: 'الأفكار', v: q.selected }, { l: 'المحتوى', v: q.posts }, { l: 'الفيديوهات', v: this.videoIdeas().length },
              { l: 'التصميمات', v: q.designed }, { l: 'أيام النشر', v: q.days }, { l: 'المنصات', v: plats.length }];
    },
    platformsSet: function () {
      var s = {};
      this.plannable().forEach(function (i) { if (i.plan) { if (i.plan.platform === 'both') { s.facebook = 1; s.instagram = 1; } else s[i.plan.platform] = 1; } });
      return Object.keys(s);
    },
    platformsText: function () { var p = this.platformsSet(); return p.length ? p.map(this.platLabel).join('، ') : 'لسه متحددتش'; },
    publishable: function () { return this.plannable(); },
    pubState: function (i) {
      var r = this.pubRes[i.id];
      if (r) return r;
      var s = i.content.publish.status;
      if (s === 'published') return { label: '✓ اتنشر', cls: 'ok' };
      if (s === 'scheduled' || s === 'pending' || s === 'processing') return { label: '🗓 مجدول', cls: 'blue' };
      if (s === 'failed') return { label: 'فشل', cls: 'warn' };
      return { label: 'جاهز', cls: 'grey' };
    },
    // بس اللي ليه ميعاد — المنشور من غير ميعاد مايتنشرش لوحده بالغلط
    toPublish: function () { var self = this; return this.publishable().filter(function (i) { return i.plan && !self.locked(i) && !(self.pubRes[i.id] && self.pubRes[i.id].cls === 'ok'); }); },
    unplannedPub: function () { var self = this; return this.publishable().filter(function (i) { return !i.plan && !self.locked(i); }).length; },
    noDesignIg: function () { var self = this; return this.publishable().some(function (i) { return i.plan && i.plan.platform !== 'facebook' && !self.designDone(i); }); },
    async publishAll() {
      var self = this, list = this.toPublish(), pg = this.pub.pages.find(function (p) { return p.id === self.page; }) || this.pub.pages[0];
      if (!pg) return;
      this.page = pg.id;
      if (!confirm('هننشر/نجدول ' + list.length + ' منشور على «' + pg.name + '» — نبدأ؟')) return;
      var res = await this.runBulk(list, 'بينشر', MSG.publish, async function (i) {
        var plat = i.plan ? i.plan.platform : 'facebook';
        if (plat !== 'facebook' && !self.designDone(i)) plat = 'facebook';
        if (i.format === 'carousel' && !self.designDone(i)) return { ok: false, error: 'كمّل شرائح #' + i.n };
        if (!pg.ig) plat = 'facebook';
        var at = new Date(i.plan.at.replace(' ', 'T')), mins = (at - Date.now()) / 60000;
        var mode = mins <= 2 ? 'now' : (mins < 11 ? 'queue' : 'schedule');
        var r = await ajaxPost(self.base + '/ajax/publish-direct.php', { csrf: csrf(), _quiet: '1', content_id: i.content.id, connection_id: pg.id,
          platform: plat, mode: mode, scheduled_at: mode === 'now' ? '' : i.plan.at.replace(' ', 'T') });
        r = r || { ok: false, error: 'تعذّر النشر' };
        self.pubRes[i.id] = r.ok ? { label: mode === 'now' ? '✓ اتنشر' : '🗓 اتجدول', cls: r.ok && mode === 'now' ? 'ok' : 'blue' } : { label: 'فشل', cls: 'warn', err: r.error };
        if (r.ok) i.content.publish.status = mode === 'now' ? 'published' : 'scheduled';
        return r;
      });
      if (res.ok && !res.fail) this.notify('تمام — ' + res.ok + ' منشور اتنشر أو اتجدول 🚀');
    },

    /* ── عام ── */
    async saveAndExit() { await saver.flush(); location.href = base + '/campaigns.php'; },
    async duplicate() {
      this.menu = false;
      var r = await SpreadAPI.post('campaigns', 'duplicate', { id: this.c.id });
      if (r.ok) location.href = base + '/campaign.php?id=' + r.campaign.id; else this.notify(r.error, 'danger');
    },
    async complete() {
      this.menu = false;
      var r = await SpreadAPI.post('campaigns', 'complete', { id: this.c.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.c.status = 'completed'; this.notify('مبروك — الحملة اكتملت 🎉');
    },
  });

  // حفظ البريف تلقائي (بيبعت رقم النسخة لمنع مسح تعديل من مكان تاني)
  var saver = SpreadAutosave({
    state: app.save, delay: 900,
    collect: function () { var c = app.c; return { id: c.id, rev: c.revision, title: c.title, basis: c.basis, notes: c.notes, ideas_count: app.count }; },
    save: async function (data, opts) {
      var r = await SpreadAPI.post('campaigns', 'save', data, opts);
      if (r.ok) { app.c.revision = r.campaign.revision; }
      else if (r.code === 'conflict' && r.campaign) { app.c.revision = r.campaign.revision; }
      return r;
    },
  });

  if (app.pub.pages.length) app.page = app.pub.pages[0].id;
  app.active = (app.selected()[0] || {}).id || 0;
  SpreadApp.mount('#cf', app);
  if (new URLSearchParams(location.search).get('resumed') !== '0' && app.c.max_stage > 1) app.notify('رجعناك لآخر مكان وقفت فيه', 'info');
});
</script>
