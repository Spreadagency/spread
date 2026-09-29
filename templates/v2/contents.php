<?php
/**
 * Spread AI v2 — مكتبة المحتوى «منشوراتي» (زي التصميم) — petite-vue
 * فلاتر (المنصة · النوع · الحملة · الحالة) · «يحتاج إجراء» · كروت بنسبة اكتمال
 * + لوحة جانبية: المعاينة · تعديل الحقول · سجل النسخ والاسترجاع · التصميمات
 *
 * ⚠️ ممنوع <template v-if> هنا — بيكسر في الأجزاء المشروطة (استخدم span.pv-c)
 */
?>
<div id="lib" class="lib" v-cloak @keydown.esc="close()">

    <div class="lib-head">
        <div>
            <h2>منشوراتي</h2>
            <p class="sub">كل المحتوى اللي أنشأته في Spread AI</p>
        </div>
        <div class="lib-head-act">
            <button type="button" class="lib-need" :class="{ on: f.needs }" @click="toggleNeeds()" :disabled="!actionCount && !f.needs">
                ⚡ يحتاج إجراء ({{ actionCount }})
            </button>
            <a :href="base + '/create-content.php'" class="btn sm"><?= ui_icon('plus', 16) ?> منشور جديد</a>
        </div>
    </div>

    <!-- الفلاتر -->
    <div class="lib-filters">
        <label class="lib-f"><span>المنصة</span>
            <select v-model="f.platform" @change="reload()">
                <option value="">الكل</option><option value="facebook">فيسبوك</option>
                <option value="instagram">إنستجرام</option><option value="both">الاتنين</option>
            </select>
        </label>
        <label class="lib-f"><span>النوع</span>
            <select v-model="f.type" @change="reload()">
                <option value="">الكل</option>
                <option v-for="t in types" :key="t.key" :value="t.key">{{ t.label }}</option>
            </select>
        </label>
        <label class="lib-f"><span>الحملة</span>
            <select v-model="f.campaign" @change="reload()">
                <option value="">الكل</option><option value="none">بدون حملة</option>
                <option v-for="c in campaigns" :key="c.id" :value="String(c.id)">{{ c.title }}</option>
            </select>
        </label>
        <label class="lib-f"><span>الحالة</span>
            <select v-model="f.status" @change="f.needs = false; reload()">
                <option value="">الكل</option>
                <option v-for="s in statuses" :key="s.key" :value="s.key">{{ s.label }} ({{ counts[s.key] || 0 }})</option>
            </select>
        </label>
        <div class="lib-q" v-if="f.q">
            بحث: <b>{{ f.q }}</b>
            <button type="button" @click="f.q = ''; reload()" aria-label="مسح البحث"><?= ui_icon('x', 14) ?></button>
        </div>
        <button type="button" class="lib-clear" v-if="filtered()" @click="clearAll()">مسح الفلاتر</button>
    </div>

    <p class="lib-count sub" v-if="!loading">عرض {{ items.length }} من {{ total }} منشور</p>

    <!-- تحميل -->
    <div class="lib-grid" v-if="loading">
        <div v-for="n in 6" :key="n" class="card lib-card lib-skel"><i class="sa-shimmer"></i><i class="sa-shimmer"></i><i class="sa-shimmer"></i></div>
    </div>

    <!-- فاضي -->
    <div class="card lib-empty" v-else-if="!items.length">
        <span class="lib-empty-ic"><?= ui_icon('folder', 26) ?></span>
        <h3 v-if="filtered()">مفيش منشورات بالفلاتر دي</h3>
        <h3 v-else>لسه مفيش منشورات</h3>
        <p class="sub" v-if="!filtered()">اعمل أول منشور والـ AI يكتبه بأسلوب براندك.</p>
        <button v-if="filtered()" type="button" class="btn ghost sm" @click="clearAll()">مسح الفلاتر</button>
        <a v-else :href="base + '/create-content.php'" class="btn sm">اعمل أول منشور</a>
    </div>

    <!-- الكروت -->
    <div class="lib-grid" v-else>
        <article v-for="c in items" :key="c.id" class="card lib-card" :class="{ fail: c.fail }">
            <button type="button" class="lib-cover" @click="open(c.id)" :aria-label="'فتح ' + c.hook">
                <?= ui_icon('image', 26) ?>
                <img v-if="c.cover" :src="c.cover" alt="" loading="lazy" decoding="async" @error="$event.target.remove()">
                <em class="lib-type">{{ c.type_label }}</em>
                <em class="lib-fmt" v-if="c.format && c.format !== 'post'">{{ c.format_emoji }} {{ c.format_label }}<span v-if="c.slides_count"> · {{ c.slides_count }}</span></em>
            </button>
            <div class="lib-body">
                <div class="lib-row">
                    <span class="cm-st" :style="{ '--st': c.status.color }">{{ c.status.label }}</span>
                    <small class="sub">{{ c.ago }}</small>
                </div>
                <b class="lib-hook" @click="open(c.id)">{{ c.hook || 'بدون نص' }}</b>
                <div class="lib-meta">
                    <span>{{ c.platform }}</span>
                    <a v-if="c.campaign" :href="base + '/campaign.php?id=' + c.campaign.id" class="lib-camp">{{ c.campaign.title }}</a>
                </div>
                <div class="lib-pct">
                    <span class="bar"><span class="bar-fill" :style="{ width: c.pct + '%' }"></span></span>
                    <b>{{ c.pct }}%</b>
                </div>
                <small class="lib-miss" v-if="c.missing">{{ c.missing }}</small>
                <small class="lib-sched" v-if="c.scheduled">🗓 مجدول: <span dir="ltr">{{ c.scheduled }}</span></small>
                <small class="lib-fail" v-if="c.fail">✕ {{ c.fail }}</small>
                <div class="lib-act">
                    <button type="button" class="btn ghost sm" @click="open(c.id)">فتح المنشور</button>
                    <span class="pv-c" v-if="c.next.kind === 'link'"><a :href="c.next.url" class="btn sm">{{ c.next.label }}</a></span>
                    <span class="pv-c" v-if="c.next.kind === 'external'"><a :href="c.next.url" class="btn soft sm" target="_blank" rel="noopener">{{ c.next.label }}</a></span>
                    <span class="pv-c" v-if="c.next.kind === 'panel'"><button type="button" class="btn sm" @click="open(c.id)">{{ c.next.label }}</button></span>
                </div>
            </div>
        </article>
    </div>

    <div class="lib-more" v-if="more && !loading">
        <button type="button" class="btn ghost" @click="loadMore()" :disabled="loadingMore">{{ loadingMore ? 'بنحمّل…' : 'حمّل المزيد' }}</button>
    </div>

    <!-- ═══ اللوحة الجانبية ═══ -->
    <div class="lib-shade" v-if="d || dLoading" @click="close()"></div>
    <aside class="lib-panel" v-if="d || dLoading" role="dialog" aria-modal="true" :aria-label="d ? d.hook : 'تفاصيل المنشور'">
        <div class="lib-p-loading" v-if="dLoading && !d"><span class="btn-spin"></span> بنفتح المنشور…</div>

        <div class="pv-c" v-if="d">
            <header class="lib-p-head">
                <div>
                    <h3>{{ d.hook || 'بدون نص' }}</h3>
                    <div class="lib-p-meta">
                        <span v-if="d.campaign">📣 {{ d.campaign.title }}</span>
                        <span class="lib-fmt-chip">{{ d.format_emoji }} {{ d.format_label }}</span><span>{{ d.type_label }}</span><span>{{ d.platform }}</span><span>{{ d.date }}</span>
                    </div>
                </div>
                <button type="button" class="v2-iconbtn lib-x" @click="close()" aria-label="إغلاق"><?= ui_icon('x', 18) ?></button>
            </header>

            <section class="lib-p-status">
                <div class="lib-row">
                    <span class="cm-st" :style="{ '--st': d.status.color }">{{ d.status.label }}</span>
                    <b>{{ d.pct }}% مكتمل</b>
                </div>
                <span class="bar"><span class="bar-fill" :style="{ width: d.pct + '%' }"></span></span>
                <div class="lib-fail-box" v-if="d.fail">❌ فشل النشر — {{ d.fail }}</div>
                <small class="lib-miss" v-if="d.missing">{{ d.missing }}</small>
                <div class="lib-p-next">
                    <span class="pv-c" v-if="d.next.kind === 'link'"><a :href="d.next.url" class="btn sm">{{ d.next.label }}</a></span>
                    <span class="pv-c" v-if="d.next.kind === 'external'"><a :href="d.next.url" class="btn soft sm" target="_blank" rel="noopener">{{ d.next.label }}</a></span>
                    <span class="pv-c" v-if="d.status.key === 'needs_review'">
                        <button type="button" class="btn sm" @click="flag('needs_design')">✓ تمام — للتصميم</button>
                    </span>
                    <a v-if="d.campaign" :href="base + '/campaign.php?id=' + d.campaign.id" class="btn ghost sm">← العودة للحملة</a>
                </div>
            </section>

            <!-- المعاينة -->
            <section class="lib-preview">
                <div class="lib-pv-head">
                    <span class="lib-pv-av"><img v-if="d.brand.logo" :src="d.brand.logo" alt=""><span v-else>{{ (d.brand.name || 'S').slice(0, 1) }}</span></span>
                    <span><b>{{ d.brand.name || 'براندك' }}</b><small>{{ d.platform }} · {{ d.type_label }}</small></span>
                </div>
                <p class="lib-pv-text" dir="auto">{{ d.text }}</p>
                <p class="lib-pv-cta" v-if="d.cta" dir="auto">{{ d.cta }}</p>
                <p class="lib-pv-tags" v-if="d.hashtags" dir="auto">{{ d.hashtags }}</p>
                <div class="lib-pv-img" v-if="d.cover"><img :src="d.cover" alt="" @error="$event.target.remove()"></div>
            </section>

            <nav class="lib-tabs">
                <button type="button" :class="{ on: tab === 'content' }" @click="tab = 'content'">📝 المحتوى</button>
                <button type="button" :class="{ on: tab === 'design' }" @click="tab = 'design'">{{ designTabLabel() }}</button>
            </nav>

            <!-- المحتوى -->
            <section class="lib-tab" v-if="tab === 'content'">
                <div class="lib-published" v-if="d.published">المنشور ده اتنشر خلاص — التعديل هنا بيغيّر نسختك بس، مش المنشور اللي على فيسبوك.</div>

                <div class="lib-field">
                    <div class="lib-row"><b>النص</b>
                        <button type="button" class="lib-edit" v-if="!editing" @click="startEdit()">✎ تعديل</button>
                    </div>
                    <textarea v-if="editing" class="textarea" rows="8" v-model="draft.text" dir="auto" maxlength="20000"></textarea>
                    <p v-else class="lib-val" dir="auto">{{ d.text }}</p>
                </div>
                <div class="lib-field">
                    <b>الهاشتاجات</b>
                    <input v-if="editing" class="input" v-model="draft.hashtags" dir="auto" maxlength="1000">
                    <p v-else class="lib-val" dir="auto">{{ d.hashtags || '—' }}</p>
                </div>
                <div class="lib-field">
                    <b>الـ CTA</b>
                    <input v-if="editing" class="input" v-model="draft.cta" dir="auto" maxlength="500">
                    <p v-else class="lib-val" dir="auto">{{ d.cta || '—' }}</p>
                </div>
                <div class="lib-save" v-if="editing">
                    <button type="button" class="btn" @click="save()" :disabled="saving">{{ saving ? 'بنحفظ…' : 'حفظ' }}</button>
                    <button type="button" class="btn ghost" @click="editing = false" :disabled="saving">إلغاء</button>
                    <small class="sub">كل حفظ بيعمل نسخة جديدة — تقدر ترجعلها في أي وقت</small>
                </div>

                <!-- ✨ التعديل بالكلام -->
                <div class="lib-ai" v-if="!editing && !proposal">
                    <b>✨ عدّل المنشور بالذكاء الاصطناعي</b>
                    <p class="sub">اكتب طلبك بالعربي — الـ AI بياخد المنشور الحالي + هوية البراند + سياق الحملة، ويعدّل اللي طلبته بس.</p>
                    <div class="lib-ai-chips">
                        <button type="button" v-for="ch in chips" :key="ch" @click="instruction = ch">{{ ch }}</button>
                    </div>
                    <textarea class="textarea" rows="2" v-model="instruction" maxlength="500" placeholder="مثلًا: خلّي الـ CTA أقوى وركّز على الخصم"></textarea>
                    <div class="lib-ai-row">
                        <button type="button" class="btn" @click="aiEdit()" :disabled="aiBusy || instruction.trim().length < 3">
                            {{ aiBusy ? 'بيعدّل…' : 'عدّل ✨' }}
                        </button>
                        <small class="sub"><span class="cr">{{ d.costs.ai_edit }} كريدت · رصيدك {{ d.costs.balance }} · </span>مش هيتحفظ غير لما تعتمده</small>
                    </div>
                </div>

                <!-- المقارنة: القديمة ↔ الجديدة -->
                <div class="lib-cmp" v-if="proposal">
                    <div class="lib-row"><b>مقارنة: القديمة ↔ الجديدة</b><small class="sub">«{{ proposal.instruction }}»</small></div>
                    <div class="lib-intent" v-if="proposal.wants_design && d.designs.length">
                        فهمت إنك عايز: 📝 تعديل في المحتوى + 🎨 تعديل في التصميم
                        <button type="button" class="btn soft sm" @click="approve(true)" :disabled="saving">نفّذ التعديلين</button>
                    </div>
                    <div class="lib-cmp-grid">
                        <div class="lib-cmp-old"><em>القديمة</em>
                            <p dir="auto">{{ proposal.old.text }}</p>
                            <small v-if="proposal.old.cta" dir="auto">{{ proposal.old.cta }}</small>
                        </div>
                        <div class="lib-cmp-new"><em>الجديدة</em>
                            <p dir="auto">{{ proposal.proposal.text }}</p>
                            <small v-if="proposal.proposal.cta" dir="auto">{{ proposal.proposal.cta }}</small>
                            <small class="lib-cmp-tags" v-if="proposal.proposal.hashtags !== proposal.old.hashtags" dir="auto">{{ proposal.proposal.hashtags }}</small>
                        </div>
                    </div>
                    <div class="lib-save">
                        <button type="button" class="btn" @click="approve(false)" :disabled="saving">{{ saving ? 'بنحفظ…' : '✓ اعتماد النسخة' }}</button>
                        <button type="button" class="btn ghost" @click="proposal = null" :disabled="saving">رجوع للقديمة</button>
                    </div>
                </div>

                <h4 class="lib-h4">إصدارات المحتوى</h4>
                <p class="sub" v-if="!d.versions.length">لسه مفيش إصدارات محفوظة.</p>
                <ol class="lib-vers" v-else>
                    <li v-for="(v, i) in d.versions" :key="v.id">
                        <span class="lib-vn">v{{ v.n }}</span>
                        <span class="lib-vt"><b>{{ v.type }}</b><small>{{ v.note || '' }} {{ v.ago ? '· ' + v.ago : '' }}</small></span>
                        <span class="lib-vcur" v-if="i === 0">الحالية</span>
                        <button v-else type="button" class="btn ghost sm" @click="restore(v)">استعادة</button>
                    </li>
                </ol>
            </section>

            <!-- ⑦-ج الكاروسيل: تصميم لكل شريحة -->
            <section class="lib-tab" v-if="tab === 'design' && d.format === 'carousel'">
                <div class="lib-car-head">
                    <b>🎠 كاروسيل · {{ d.slides.length }} شرائح</b>
                    <small class="sub">{{ d.progress.done }} من {{ d.progress.needed }} متصممة · كل شريحة = تصميم<span class="cr"> ({{ d.costs.design }} كريدت)</span></small>
                </div>
                <button type="button" class="btn sm" v-if="d.progress.missing.length" @click="designSlides(d.progress.missing)" :disabled="dBusy">
                    {{ dBusy ? 'بيصمّم ' + slideBusy + '…' : 'صمّم الشرائح الناقصة (' + d.progress.missing.length + ') ✨' }}</button>
                <p class="sub">الشرائح بتتصمم بنفس الهوية والمقاس — والشريحة الأولى بتبقى المرجع لباقي الشرائح علشان يطلعوا كاروسيل واحد متناسق.</p>
                <div class="lib-slides">
                    <div class="lib-slide" v-for="sl in d.slides" :key="sl.n">
                        <div class="lib-slide-img">
                            <img v-if="sl.designs.length" :src="sl.designs[0].url" alt="" loading="lazy">
                            <span v-if="!sl.designs.length">{{ String(sl.n).padStart(2, '0') }}</span>
                            <em :class="sl.designs.length ? 'ok' : ''">{{ sl.designs.length ? '✓' : '○' }}</em>
                        </div>
                        <div class="lib-slide-txt">
                            <small>شريحة {{ sl.n }}{{ sl.designs.length > 1 ? ' · ' + sl.designs.length + ' نسخ' : '' }}</small>
                            <input class="input" v-model="sl.title" maxlength="200" placeholder="عنوان الشريحة" @input="slidesDirty = true" :readonly="d.published || null">
                            <textarea class="textarea" rows="2" v-model="sl.text" maxlength="600" placeholder="نص الشريحة" @input="slidesDirty = true" :readonly="d.published || null"></textarea>
                            <button type="button" class="btn ghost sm" @click="designSlides([sl.n])" :disabled="dBusy || d.published || null">{{ sl.designs.length ? '↻ إعادة تصميم الشريحة' : 'صمّم الشريحة ✨' }}</button>
                        </div>
                    </div>
                </div>
                <div class="lib-save" v-if="slidesDirty">
                    <button type="button" class="btn" @click="saveSlides()" :disabled="saving">{{ saving ? 'بنحفظ…' : 'حفظ نصوص الشرائح' }}</button>
                </div>
            </section>

            <!-- ⑦-ج الفيديو: سكريبت ← اعتماد ← واتساب ← تنفيذ يدوي ← تسليم -->
            <section class="lib-tab" v-if="tab === 'design' && d.format === 'video'">
                <ol class="lib-vsteps">
                    <li v-for="(st, k) in videoSteps" :key="st[0]" :class="{ done: k + 1 < d.video.status.step, now: k + 1 === d.video.status.step }"><i>{{ k + 1 < d.video.status.step ? '✓' : k + 1 }}</i>{{ st[1] }}</li>
                </ol>
                <div class="lib-vbox ok" v-if="d.video.status.key === 'ready'">
                    <b>🎉 الفيديو جاهز!</b>
                    <a v-if="d.video.delivery_url" :href="d.video.delivery_url" target="_blank" rel="noopener nofollow" class="btn sm">افتح / حمّل الفيديو</a>
                    <p v-if="d.video.note" class="sub">{{ d.video.note }}</p>
                    <button type="button" class="btn soft sm" @click="videoAct('video_received')" :disabled="vBusy">استلمت الفيديو ✓</button>
                </div>
                <div class="lib-vbox" v-if="d.video.status.key === 'delivered'"><b>✓ اتسلّم</b>
                    <a v-if="d.video.delivery_url" :href="d.video.delivery_url" target="_blank" rel="noopener nofollow" class="btn ghost sm">رابط الفيديو</a></div>
                <div class="lib-vbox" v-if="['sent', 'in_production'].indexOf(d.video.status.key) >= 0">
                    <b>{{ d.video.status.key === 'sent' ? '📨 طلبك وصل لفريق Spread AI' : '🎬 الفيديو قيد التنفيذ' }}</b>
                    <p class="sub">هنبلّغك هنا أول ما يجهز. {{ d.video.note }}</p>
                    <button type="button" class="btn ghost sm" @click="videoRequest()" :disabled="vBusy">📲 كلّمنا على واتساب تاني</button>
                </div>

                <div class="lib-field">
                    <b>بريف الفيديو</b>
                    <div class="lib-vgrid">
                        <label v-for="k in ['type', 'duration', 'platform', 'ratio']" :key="k"><small>{{ briefLbl[k] }}</small>
                            <select class="input" v-model="d.video.brief[k]" @change="videoDirty = true" :disabled="videoLocked() || null">
                                <option v-for="o in d.video_options[k]" :key="o" :value="o">{{ o }}</option></select></label>
                    </div>
                </div>
                <div class="lib-field"><b>Hook (أول 3 ثواني)</b>
                    <textarea class="textarea" rows="2" v-model="d.video.brief.script.hook" @input="videoDirty = true" :readonly="videoLocked() || null"></textarea></div>
                <div class="lib-field"><b>السكريبت (المشاهد)</b>
                    <textarea class="textarea" rows="8" v-model="d.video.brief.script.body" @input="videoDirty = true" :readonly="videoLocked() || null"></textarea></div>
                <div class="lib-field"><b>CTA</b>
                    <input class="input" v-model="d.video.brief.script.cta" @input="videoDirty = true" :readonly="videoLocked() || null"></div>
                <div class="lib-field"><b>فكرة التصوير</b>
                    <textarea class="textarea" rows="2" v-model="d.video.brief.script.shoot" @input="videoDirty = true" :readonly="videoLocked() || null"></textarea></div>
                <div class="lib-field"><b>ملاحظاتك لفريق التنفيذ</b>
                    <textarea class="textarea" rows="2" v-model="d.video.brief.notes" maxlength="1000" @input="videoDirty = true" :readonly="videoLocked() || null" placeholder="مثلًا: عايز الدكتور يظهر بنفسه · التصوير في العيادة"></textarea></div>

                <div class="lib-save" v-if="!videoLocked()">
                    <button type="button" class="btn ghost" v-if="videoDirty" @click="videoSave()" :disabled="vBusy">حفظ التعديلات</button>
                    <button type="button" class="btn" v-if="d.video.status.key === 'script'" @click="videoAct('video_approve')" :disabled="vBusy">✓ اعتمد السكريبت</button>
                    <button type="button" class="btn lib-wa" v-if="d.video.status.key === 'approved'" @click="videoRequest()" :disabled="vBusy || !d.video.wa || null">📲 تواصل معنا لتنفيذ الفيديو</button>
                    <small class="sub" v-if="d.video.status.key === 'approved' && !d.video.wa">رقم واتساب التنفيذ مش مضبوط — كلّم الإدارة</small>
                </div>
                <p class="sub">🎬 الـ AI بيجهّز السكريبت، والتنفيذ بيتم يدويًا بواسطة فريق Spread AI.</p>
            </section>

            <!-- التصميم -->
            <section class="lib-tab" v-if="tab === 'design' && (d.format === 'post' || d.format === 'story')">
                <div class="lib-p-next">
                    <a :href="base + '/content-view.php?id=' + d.id + '#design'" class="btn sm">{{ d.designs.length ? '🎨 تصميم جديد' : '🎨 صمّم له' }}</a>
                    <small class="sub" v-if="d.format === 'story'">📱 ستوري — التصميم رأسي 9:16</small>
                </div>
                <div class="lib-ai" v-if="d.designs.length">
                    <b>🎨 عايز تعدّل إيه في التصميم؟</b>
                    <p class="sub">مش محتاج Prompt — اكتب طلبك، والـ AI ياخد التصميم الحالي + هوية البراند ويعمل نسخة جديدة منه.</p>
                    <div class="lib-ai-chips">
                        <button type="button" v-for="ch in dChips" :key="ch" @click="dInstruction = ch">{{ ch }}</button>
                    </div>
                    <textarea class="textarea" rows="2" v-model="dInstruction" maxlength="600" placeholder="مثلًا: كبّر اللوجو وخلّي الخلفية أفتح"></textarea>
                    <div class="lib-ai-row">
                        <button type="button" class="btn" @click="designEdit()" :disabled="dBusy || dInstruction.trim().length < 3">
                            {{ dBusy ? 'بيصمّم…' : 'تعديل التصميم ✨' }}
                        </button>
                        <small class="sub"><span class="cr">{{ d.costs.design }} كريدت · </span>النسخة الجديدة هتبقى الغلاف، والقديمة موجودة تحت</small>
                    </div>
                </div>
                <p class="sub" v-if="!d.designs.length">لسه مفيش تصميمات للمنشور ده.</p>
                <h4 class="lib-h4" v-if="d.designs.length">التصميمات السابقة</h4>
                <div class="lib-designs" v-if="d.designs.length">
                    <figure v-for="(g, i) in d.designs" :key="g.id" :class="{ cur: g.current }">
                        <img :src="g.url" alt="" loading="lazy" @error="$event.target.style.visibility = 'hidden'">
                        <figcaption>
                            <b>V{{ d.designs.length - i }}</b><small>{{ g.ago }}</small>
                            <span class="lib-vcur" v-if="g.current">الحالي</span>
                            <button v-else type="button" class="btn ghost sm" @click="useDesign(g)">استخدام</button>
                        </figcaption>
                    </figure>
                </div>
            </section>
        </div>
    </aside>

    <div class="toast-container" v-if="toast"><div class="toast" :class="toast.type">{{ toast.msg }}</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var base = document.querySelector('meta[name="app-base"]').content;
  var qs = new URLSearchParams(location.search);

  var app = SpreadApp.reactive({
    base: base,
    items: [], total: 0, page: 1, more: false, counts: {}, actionCount: 0,
    campaigns: [], types: [], statuses: [],
    loading: true, loadingMore: false,
    f: { platform: '', type: '', campaign: '', status: qs.get('status') || '', needs: qs.get('needs') === '1', q: qs.get('q') || '' },
    d: null, dLoading: false, tab: 'content', editing: false, saving: false,
    draft: { text: '', hashtags: '', cta: '' },
    toast: null,
    // ④-ب: التعديل بالكلام
    instruction: '', aiBusy: false, proposal: null,
    dInstruction: '', dBusy: false,
    // ⑦-ج
    slidesDirty: false, slideBusy: '', videoDirty: false, vBusy: false,
    videoSteps: [['script', 'السكريبت جاهز'], ['approved', 'اعتمدت السكريبت'], ['sent', 'اتبعت للتنفيذ'], ['in_production', 'قيد التنفيذ'], ['ready', 'جاهز'], ['delivered', 'اتسلّم']],
    briefLbl: { type: 'نوع الفيديو', duration: 'المدة', platform: 'المنصة', ratio: 'المقاس' },
    chips: ['خلّي الـ CTA أقوى', 'اختصره للنص', 'أضف إيموجي بسيطة', 'خلّيه رسمي أكتر', 'ابدأ بسؤال يشد'],
    dChips: ['كبّر اللوجو', 'خلّي الخلفية أفتح', 'استخدم ألوان البراند أكتر', 'بسّط التصميم', 'كبّر العنوان'],

    notify: function (msg, type) { SpreadApp.toast(this, msg, type || 'success'); },
    filtered: function () { var f = this.f; return !!(f.platform || f.type || f.campaign || f.status || f.needs || f.q); },
    params: function (page, meta) {
      var f = this.f;
      return { action: 'library', platform: f.platform, type: f.type, campaign: f.campaign,
               status: f.needs ? '' : f.status, needs: f.needs ? 1 : '', q: f.q, page: page, meta: meta ? 1 : '' };
    },
    async reload(meta) {
      this.loading = true; this.page = 1;
      var r = await SpreadAPI.get('contents', this.params(1, meta));
      this.loading = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.items = r.items; this.total = r.total; this.more = r.more; this.counts = r.counts; this.actionCount = r.actionCount;
      if (r.campaigns) { this.campaigns = r.campaigns; this.types = r.types; this.statuses = r.statuses; }
      this.syncUrl();
    },
    async loadMore() {
      this.loadingMore = true;
      var r = await SpreadAPI.get('contents', this.params(this.page + 1));
      this.loadingMore = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.page = r.page; this.items = this.items.concat(r.items); this.more = r.more;
    },
    toggleNeeds: function () { this.f.needs = !this.f.needs; if (this.f.needs) this.f.status = ''; this.reload(); },
    clearAll: function () { this.f = { platform: '', type: '', campaign: '', status: '', needs: false, q: '' }; this.reload(); },
    syncUrl: function () {
      var p = new URLSearchParams();
      if (this.f.q) p.set('q', this.f.q);
      if (this.f.needs) p.set('needs', '1'); else if (this.f.status) p.set('status', this.f.status);
      if (this.d) p.set('open', this.d.id);
      var s = p.toString(); history.replaceState(null, '', location.pathname + (s ? '?' + s : ''));
    },

    async open(id) {
      this.dLoading = true; this.editing = false; this.tab = 'content'; this.proposal = null;
      document.body.classList.add('lib-lock');
      var r = await SpreadAPI.get('contents', { action: 'get', id: id });
      this.dLoading = false;
      if (!r.ok) { this.close(); return this.notify(r.error, 'danger'); }
      this.d = r.content; this.slidesDirty = false; this.videoDirty = false; this.syncUrl();
      if (this.d.format === 'video' && ['ready', 'approved'].indexOf(this.d.video.status.key) >= 0) this.tab = 'design';
      setTimeout(function () { var x = document.querySelector('.lib-x'); if (x) x.focus(); }, 30);
    },
    close: function () { this.d = null; this.dLoading = false; this.editing = false; document.body.classList.remove('lib-lock'); this.syncUrl(); },
    startEdit: function () { this.draft = { text: this.d.text, hashtags: this.d.hashtags, cta: this.d.cta }; this.editing = true; },
    async save() {
      this.saving = true;
      var r = await SpreadAPI.post('contents', 'save', { id: this.d.id, rev: this.d.rev, text: this.draft.text, hashtags: this.draft.hashtags, cta: this.draft.cta });
      this.saving = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.editing = false;
      if (r.unchanged) return this.notify('مفيش تغيير', 'info');
      await this.open(this.d.id); this.refreshCard(this.d);
      this.notify('اتحفظ ✓ — نسخة جديدة اتعملت');
    },
    async restore(v) {
      if (!confirm('ترجع للنسخة v' + v.n + '؟ النسخة الحالية هتفضل محفوظة في الإصدارات.')) return;
      var r = await SpreadAPI.post('contents', 'restore', { id: this.d.id, version_id: v.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      await this.open(this.d.id); this.refreshCard(this.d);
      this.notify('رجعت للنسخة v' + v.n + ' ✓');
    },
    async aiEdit() {
      if (this.aiBusy) return;
      this.aiBusy = true;
      if (window.SpreadThinking) SpreadThinking.start({ title: 'بعدّل المنشور', steps: ['بقرا المنشور الحالي', 'بفهم طلبك', 'بعدّل اللي طلبته بس', 'بجهّز المقارنة'] });
      var r = await SpreadAPI.post('contents', 'ai_edit', { id: this.d.id, instruction: this.instruction.trim() });
      this.aiBusy = false;
      if (!r.ok) { if (window.SpreadThinking) SpreadThinking.fail(); return this.notify(r.error, 'danger'); }
      if (window.SpreadThinking) SpreadThinking.done('جاهز للمقارنة ✓');
      this.proposal = r; this.d.costs.balance = r.balance;
    },
    async approve(alsoDesign) {
      this.saving = true;
      var p = this.proposal;
      var r = await SpreadAPI.post('contents', 'save', { id: this.d.id, rev: this.d.rev, text: p.proposal.text,
        hashtags: p.proposal.hashtags, cta: p.proposal.cta, source: 'ai_edit', note: p.instruction });
      this.saving = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      var instr = p.instruction;
      this.proposal = null; this.instruction = '';
      await this.open(this.d.id); this.refreshCard(this.d);
      this.notify('اتعتمدت النسخة الجديدة ✓');
      if (alsoDesign) { this.tab = 'design'; this.dInstruction = instr; this.designEdit(); }
    },
    b64: function (s) {
      var bytes = new TextEncoder().encode(s), bin = '';
      for (var i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
      return btoa(bin);
    },
    async designEdit() {
      if (this.dBusy || !this.d.designs.length) return;
      var cur = this.d.designs.find(function (x) { return x.current; }) || this.d.designs[0];
      var prompt = 'عدّل التصميم المرفق (الصورة المرجعية) — حافظ على نفس التكوين والعناصر والنصوص، وغيّر بس: ' + this.dInstruction.trim();
      this.dBusy = true;
      // نفس مسار توليد التصميم الموجود (الكريدت · حد المحاولات · العلامة المائية · سجل الـ AI)
      // و«Spread AI يفكر» بيظهر لوحده لأنه متعلّق على ajaxPost
      var r = await ajaxPost(this.base + '/ajax/generate-design.php', {
        csrf: document.querySelector('meta[name="csrf-token"]').content,
        content_id: this.d.id, style_ref: 'design:' + cur.id, include_logo: '1', ratio: cur.ratio || '',
        design_edit: '1', custom_prompt: this.b64(prompt), _b64: 'custom_prompt',
      });
      this.dBusy = false;
      if (!r || !r.ok) return this.notify((r && r.error) || 'تعذّر تعديل التصميم', 'danger');
      this.dInstruction = '';
      var keepTab = this.tab;
      await this.open(this.d.id); this.tab = keepTab; this.refreshCard(this.d);
      this.notify('النسخة الجديدة بقت الغلاف ✓');
    },
    async useDesign(g) {
      var r = await SpreadAPI.post('contents', 'use_design', { id: this.d.id, design_id: g.id });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d.designs.forEach(function (x) { x.current = x.id === g.id; });
      this.d.cover = g.url; this.refreshCard(this.d);
      this.notify('بقى الغلاف ✓');
    },
    async flag(f) {
      var r = await SpreadAPI.post('contents', 'flag', { id: this.d.id, flag: f });
      if (!r.ok) return this.notify(r.error, 'danger');
      await this.open(this.d.id); this.refreshCard(this.d);
      this.notify('راح للتصميم ✓');
    },
    /* ── ⑦-ج ── */
    designTabLabel: function () {
      var d = this.d;
      if (d.format === 'carousel') return '🎠 الشرائح (' + d.progress.done + '/' + d.progress.needed + ')';
      if (d.format === 'video') return '🎬 الفيديو';
      return '🎨 التصميم (' + d.designs.length + ')';
    },
    videoLocked: function () { return ['sent', 'in_production', 'ready', 'delivered'].indexOf(this.d.video.status.key) >= 0; },
    async saveSlides() {
      this.saving = true;
      var r = await SpreadAPI.post('contents', 'slides_save', { id: this.d.id, slides: this.d.slides.map(function (s) { return { title: s.title, text: s.text, design: s.design }; }) });
      this.saving = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.slidesDirty = false; this.notify('اتحفظت نصوص الشرائح ✓');
    },
    async designSlides(nums) {
      if (this.dBusy) return;
      if (this.slidesDirty) await this.saveSlides();
      var self = this, ok = 0, err = '';
      if (this.d.costs.balance < nums.length * this.d.costs.design && !confirm(window.SPREAD_CR !== false ? 'رصيدك يكفي ' + Math.floor(this.d.costs.balance / Math.max(1, this.d.costs.design)) + ' شريحة بس — نكمّل؟' : 'باقة الشهر مش هتكفي كل الشرائح — نكمّل باللي يكفي؟')) return;
      this.dBusy = true;
      for (var k = 0; k < nums.length; k++) {
        this.slideBusy = 'الشريحة ' + nums[k] + ' (' + (k + 1) + ' من ' + nums.length + ')';
        var r = await ajaxPost(this.base + '/ajax/generate-design.php', { csrf: document.querySelector('meta[name="csrf-token"]').content,
          _quiet: nums.length > 1 ? '1' : '', content_id: this.d.id, slide_no: nums[k], include_logo: '1', bulk: nums.length > 1 ? '1' : '' });
        if (r && r.ok) { ok++; self.d.costs.balance -= self.d.costs.design; } else { err = (r && r.error) || 'تعذّر التصميم'; break; }
      }
      this.dBusy = false; this.slideBusy = '';
      var keepTab = this.tab;
      await this.open(this.d.id); this.tab = keepTab; this.refreshCard(this.d);
      if (err) this.notify('اتصمم ' + ok + ' من ' + nums.length + ' — ' + err, ok ? 'warning' : 'danger'); else this.notify('اتصمم ' + ok + (ok === 1 ? ' شريحة' : ' شرائح') + ' 🎨');
    },
    async videoSave() {
      this.vBusy = true;
      var r = await SpreadAPI.post('contents', 'video_save', { id: this.d.id, brief: this.d.video.brief });
      this.vBusy = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.videoDirty = false; this.d.video = r.video; this.notify('اتحفظ ✓');
      return true;
    },
    async videoAct(action) {
      if (this.videoDirty && !(await this.videoSave())) return;
      this.vBusy = true;
      var r = await SpreadAPI.post('contents', action, { id: this.d.id });
      this.vBusy = false;
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d.video = r.video; this.refreshCardLight();
      this.notify(action === 'video_approve' ? 'اعتمدت السكريبت ✓ — اطلب التنفيذ من فريقنا' : 'تمام — اتسجّل الاستلام ✓');
    },
    async videoRequest() {
      if (this.videoDirty && !(await this.videoSave())) return;
      // الشباك بيتفتح مع الضغطة نفسها (علشان مانع النوافذ) وبعدين بياخد رابط واتساب
      var w = window.open('about:blank', '_blank');
      this.vBusy = true;
      var r = await SpreadAPI.post('contents', 'video_request', { id: this.d.id, notes: this.d.video.brief.notes });
      this.vBusy = false;
      if (!r.ok) { if (w) w.close(); return this.notify(r.error, 'danger'); }
      this.d.video = r.video; this.refreshCardLight();
      if (w) w.location.href = r.wa; else location.href = r.wa;
      this.notify('جهّزنا رسالة الطلب على واتساب 📲');
    },
    refreshCardLight: function () {
      var d = this.d, c = this.items.find(function (x) { return x.id === d.id; });
      if (c && d.video) { c.video_status = d.video.status; c.missing = '🎬 ' + d.video.status.label; }
    },
    refreshCard: function (d) {
      var c = this.items.find(function (x) { return x.id === d.id; });
      if (!c) return;
      ['hook', 'status', 'pct', 'missing', 'next', 'cover', 'fail', 'scheduled'].forEach(function (k) { c[k] = d[k]; });
    },
  });

  SpreadApp.mount('#lib', app);
  app.reload(true).then(function () { if (qs.get('open')) app.open(parseInt(qs.get('open'), 10)); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && (app.d || app.dLoading)) app.close(); });
});
</script>
