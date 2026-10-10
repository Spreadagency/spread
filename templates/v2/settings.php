<?php
/**
 * Spread AI v2 — مركز الإعدادات (المرحلة ⑥-أ) — petite-vue
 * زي التصميم (Settings / SettingsDesktop):
 *   الحساب الشخصي · الباقة والاستخدام · اكسب استخدام إضافي · ربط الحسابات · الأمان
 * الداتا: /api/settings.php — التفاصيل الكاملة لسه في تبويباتها (الرصيد · اربح كريدت · ربط السوشيال · دليل المنصة)
 *
 * ⚠️ ممنوع <template v-if> — استخدم span.pv-c · وممنوع v-for و v-if على نفس العنصر
 */
$__v1Csrf = csrf_field();
?>
<div id="sx" class="sx" v-cloak>

    <p class="sx-head sub">حسابك، استخدامك، والحسابات المربوطة</p>

    <!-- تحميل -->
    <div class="sx-grid" v-if="!d">
        <div class="sx-col"><div class="sx-card sx-skel"></div><div class="sx-card sx-skel"></div></div>
        <div class="sx-col"><div class="sx-card sx-skel dark"></div><div class="sx-card sx-skel"></div></div>
    </div>

    <div class="sx-grid" v-if="d">
        <div class="sx-col">

            <!-- ═══ الحساب الشخصي ═══ -->
            <section class="sx-card sx-o1" aria-labelledby="sx-h-prof">
                <h3 class="sx-h" id="sx-h-prof"><span class="sx-ic blue"><?= ui_icon('user', 17) ?></span>الحساب الشخصي</h3>
                <div class="sx-me">
                    <label class="sx-avatar" :class="{ busy: avatarBusy }" title="تغيير الصورة">
                        <span class="sx-avatar-in">
                            <img v-if="d.profile.avatar" :src="d.profile.avatar" alt="">
                            <b v-if="!d.profile.avatar">{{ d.profile.initials }}</b>
                        </span>
                        <span class="sx-cam"><?= ui_icon('camera', 13) ?></span>
                        <input type="file" accept="image/jpeg,image/png,image/webp" @change="pickAvatar($event)" aria-label="تغيير الصورة" :disabled="avatarBusy">
                    </label>
                    <div class="sx-me-txt" v-if="!editing">
                        <b>{{ d.profile.name }}</b>
                        <span dir="ltr">{{ d.profile.email }}</span>
                        <span dir="ltr" v-if="d.profile.phone">{{ d.profile.phone }}</span>
                        <small v-if="!d.profile.phone" class="sx-mute">مفيش رقم موبايل</small>
                    </div>
                </div>
                <div class="sx-form sx-in" v-if="editing">
                    <label><span>الاسم</span><input class="sx-input" v-model="draft.name" maxlength="120" autocomplete="name"></label>
                    <label><span>البريد الإلكتروني</span><input class="sx-input" dir="ltr" :value="d.profile.email" disabled>
                        <small class="sx-mute">الإيميل مابيتغيّرش من هنا — كلّم الدعم لو محتاج تغيّره</small></label>
                    <label><span>رقم الموبايل</span><input class="sx-input" dir="ltr" type="tel" v-model="draft.phone" maxlength="30" placeholder="01xxxxxxxxx" autocomplete="tel"></label>
                    <div class="sx-row-btns">
                        <button type="button" class="sx-btn primary sm" @click="saveProfile()" :disabled="busy === 'profile'">{{ busy === 'profile' ? 'بيحفظ…' : 'حفظ' }}</button>
                        <button type="button" class="sx-btn ghost" @click="editing = false">إلغاء</button>
                    </div>
                </div>
                <div class="sx-row-btns" v-if="!editing">
                    <button type="button" class="sx-btn soft" @click="startEdit()">تعديل البيانات</button>
                    <button type="button" class="sx-link muted" v-if="d.profile.avatar" @click="removeAvatar()">إزالة الصورة</button>
                </div>
                <small class="sx-mute">عضو من {{ d.profile.since }}<span v-if="d.profile.google"> · مربوط بجوجل</span></small>
            </section>

            <!-- ═══ الباقة والاستخدام ═══ -->
            <section class="sx-card sx-o3" aria-labelledby="sx-h-plan">
                <div class="sx-split">
                    <h3 class="sx-h" id="sx-h-plan"><span class="sx-ic teal"><?= ui_icon('chart', 17) ?></span>الباقة والاستخدام</h3>
                    <span class="sx-chip dark">{{ d.plan.name }}</span>
                </div>
                <div class="sx-split end">
                    <div class="sx-usage-txt" v-if="d.plan.show">
                        <span class="sx-eyebrow">الاستخدام من آخر شحن</span>
                        <span v-if="d.plan.expires">الرصيد صالح لحد {{ d.plan.expires }} · باقي {{ d.plan.days_left }} يوم</span>
                        <span v-if="!d.plan.expires">باقي لك {{ d.plan.balance }} كريدت من {{ d.plan.total }}</span>
                    </div>
                    <div class="sx-usage-txt" v-if="!d.plan.show">
                        <span class="sx-eyebrow">استهلاك باقة الشهر</span>
                        <span>بتتجدد يوم {{ d.plan.renews || d.plan.expires }} · باقي {{ d.plan.days_left }} يوم</span>
                    </div>
                    <span class="sx-pct">{{ d.plan.pct }}%</span>
                </div>
                <div class="sx-bar big" role="progressbar" :aria-valuenow="d.plan.pct" aria-valuemin="0" aria-valuemax="100" aria-label="نسبة الاستخدام">
                    <span :class="{ hot: high() }" :style="{ width: Math.max(2, d.plan.pct) + '%' }"></span>
                </div>
                <div class="sx-warn sx-in" v-if="high()">
                    <?= ui_icon('alert', 18) ?>
                    <span><b v-if="d.plan.pct >= 85">استخدمت {{ d.plan.pct }}% من {{ d.plan.show ? 'رصيدك' : 'باقة الشهر' }}.</b><b v-if="d.plan.pct < 85">{{ d.plan.show ? 'رصيدك قرب يخلص.' : 'باقتك قربت تخلص.' }}</b>
                        {{ d.plan.show ? 'اشحن دلوقتي' : 'رقّي باقتك' }} علشان إنشاء المحتوى والتصميمات مايتوقفش.</span>
                </div>

                <!-- 8-ب: حصص الشهر بالنسبة % -->
                <div class="sx-quotas" v-if="!d.plan.show && d.plan.units && d.plan.units.some(function (u) { return u.pct !== null; })">
                    <div class="pv-c" v-for="u in d.plan.units" :key="u.k">
                        <div class="sx-use" v-if="u.pct !== null">
                            <div class="sx-split"><b>{{ u.e }} {{ u.t }}<small class="sx-mute" style="display:block;font-weight:500" v-if="u.limit">{{ u.limit }} في الباقة</small></b><span><b>{{ u.pct }}%</b></span></div>
                            <div class="sx-bar"><span :class="{ hot: u.pct >= 85 }" :style="{ width: Math.max(2, u.pct) + '%' }"></span></div>
                        </div>
                    </div>
                </div>

                <div class="sx-sub sx-in" v-if="show.usage && d.plan.show">
                    <p class="sx-mute" v-if="!d.plan.used">لسه ماستخدمتش حاجة من آخر شحن.</p>
                    <div class="pv-c" v-for="u in d.plan.breakdown" :key="u.t">
                        <div class="sx-use" v-if="u.credits > 0">
                            <div class="sx-split"><b>{{ u.t }}</b><span>{{ u.ops }} عملية · <b>{{ u.credits }} كريدت</b></span></div>
                            <div class="sx-bar"><span :style="{ width: part(u.credits) + '%' }"></span></div>
                        </div>
                    </div>
                    <a :href="base + '/credits.php'" class="sx-link">كل العمليات والرصيد ←</a>
                </div>

                <div class="sx-sub plain sx-in" v-if="show.plans">
                    <p class="sx-mute" v-if="!d.plan.packages.length">مفيش باقات متاحة دلوقتي — كلّم الدعم.</p>
                    <div class="sx-pkg" v-for="p in d.plan.packages" :key="p.id" :class="{ feat: p.featured }">
                        <div>
                            <b>{{ p.name }}</b>
                            <span v-if="d.plan.show">{{ p.credits }} كريدت · صلاحية {{ p.days }} يوم</span>
                            <span v-if="!d.plan.show && p.quotas.length">{{ p.quotas.map(function (q) { return q.n + ' ' + q.t; }).join(' · ') }} · كل {{ p.days }} يوم</span>
                            <span v-if="!d.plan.show && !p.quotas.length">صلاحية {{ p.days }} يوم</span>
                            <small>{{ money(p.price) }} جنيه<span v-if="p.badge"> · {{ p.badge }}</span></small>
                        </div>
                        <span class="sx-chip blue" v-if="p.name === d.plan.name">باقتك الحالية</span>
                        <a v-if="p.name !== d.plan.name" :href="base + '/checkout.php?package=' + p.id" class="sx-btn soft sm">اشترك</a>
                    </div>
                </div>

                <div class="sx-row-btns">
                    <button type="button" class="sx-btn ghost" v-if="d.plan.show" @click="show.usage = !show.usage; show.plans = false" :aria-expanded="show.usage">{{ show.usage ? 'إخفاء التفاصيل' : 'عرض تفاصيل الاستخدام' }}</button>
                    <a class="sx-btn ghost" v-if="!d.plan.show && d.plan.upgrade" :href="d.plan.upgrade" target="_blank" rel="noopener">كلمنا واتساب</a>
                    <button type="button" class="sx-btn primary sm" @click="show.plans = !show.plans; show.usage = false" :aria-expanded="show.plans">{{ show.plans ? 'إخفاء الباقات' : 'ترقية الباقة' }}</button>
                </div>
                <!-- 10: الاشتراك والفواتير -->
                <div class="sx-row-btns sx-billing">
                    <a class="sx-link" :href="base + '/packages.php'">كل الباقات ←</a>
                    <a class="sx-link" :href="base + '/payments.php'">طلبات الدفع والفواتير ←</a>
                </div>
            </section>
        </div>

        <div class="sx-col">
            <!-- ═══ اكسب استخدام إضافي ═══ -->
            <section class="sx-ref sx-o2" v-if="d.referral" aria-labelledby="sx-h-ref">
                <span class="sx-ref-glow" aria-hidden="true"></span>
                <h3 class="sx-h light" id="sx-h-ref"><span class="sx-ic glass"><?= ui_icon('gift', 17) ?></span>اكسب استخدام إضافي</h3>
                <p v-if="d.plan.show">شارك Spread AI — كل صاحب يسجّل من لينكك بياخد <b>{{ d.referral.give }} كريدت هدية</b>، ولما يفعّل حسابه تاخد انت <b>+{{ d.referral.get }} كريدت</b>.</p>
                <p v-if="!d.plan.show">شارك Spread AI — كل صاحب يسجّل من لينكك بياخد <b>رصيد هدية</b> يجرّب بيه، ولما يفعّل حسابه تاخد انت <b>رصيد إضافي</b> على باقتك.</p>
                <div class="sx-ref-link">
                    <span dir="ltr">{{ shortLink() }}</span>
                    <button type="button" @click="copy(d.referral.link, 'اتنسخ الرابط ✓')">نسخ الرابط</button>
                </div>
                <div class="sx-ref-foot">
                    <div class="sx-ref-stat"><b>{{ d.referral.joined }}</b><small>أصدقاء سجّلوا</small></div>
                    <div class="sx-ref-stat" v-if="d.plan.show"><b class="mint" dir="ltr">+{{ d.referral.earned }}</b><small>كريدت كسبته</small></div>
                    <div class="sx-ref-stat" v-if="!d.plan.show"><b class="mint">{{ d.referral.rewarded }}</b><small>فعّلوا حسابهم</small></div>
                    <button type="button" class="sx-ref-share" @click="share()">مشاركة</button>
                </div>
                <a :href="base + '/referrals.php'" class="sx-ref-more">تفاصيل دعواتك ←</a>
            </section>

            <!-- ═══ ربط الحسابات ═══ -->
            <section class="sx-card sx-o4" aria-labelledby="sx-h-acc">
                <h3 class="sx-h" id="sx-h-acc"><span class="sx-ic link"><?= ui_icon('link', 17) ?></span>ربط الحسابات</h3>
                <p class="sx-mute sx-note" v-if="!d.accounts.allowed">النشر التلقائي مش مفعّل لحسابك لسه — كلّم الدعم علشان يفعّلوه، وبعدها تربط صفحتك من هنا.</p>

                <div class="sx-acc" v-for="a in accounts()" :key="a.k">
                    <div class="sx-rowline">
                        <span class="sx-plat">{{ a.short }}</span>
                        <div class="sx-grow">
                            <b dir="ltr">{{ a.name }}</b>
                            <small>{{ a.sub }}</small>
                        </div>
                        <span class="pv-c" v-if="a.on">
                            <span class="sx-chip" :class="a.warn ? 'warn' : 'ok'">{{ a.warn ? '⚠ محتاج ربط' : '✓ مربوط' }}</span>
                            <button type="button" class="sx-pill" @click="toggleAcc(a.k)" :aria-expanded="open === a.k">{{ open === a.k ? 'إغلاق' : 'إدارة' }}</button>
                        </span>
                        <a v-if="a.canConnect" :href="base + '/social/connect.php'" class="sx-btn soft sm">+ ربط</a>
                        <span v-if="a.soon" class="sx-chip grey">قريبًا</span>
                        <span v-if="a.locked" class="sx-chip grey">مش مفعّل</span>
                    </div>
                    <div class="sx-pages sx-in" v-if="a.on && open === a.k">
                        <div class="sx-page" v-for="p in a.pages" :key="p.id">
                            <span class="sx-page-av"><img v-if="p.avatar" :src="p.avatar" alt=""><b v-if="!p.avatar">{{ p.name.slice(0, 1) }}</b></span>
                            <div class="sx-grow">
                                <b>{{ a.k === 'ig' ? '@' + p.ig : p.name }}</b>
                                <small>{{ a.k === 'ig' ? 'عن طريق صفحة ' + p.name : statusLabel(p.status) }}</small>
                            </div>
                            <a v-if="p.status !== 'active'" :href="base + '/social/connect.php'" class="sx-pill">أعد الربط</a>
                            <button type="button" v-if="a.k === 'fb'" class="sx-link danger" @click="disconnect(p)" :disabled="busy === 'disc' + p.id">فصل</button>
                        </div>
                        <small class="sx-mute">Spread AI بينشر ويجدول على الصفحات دي بس. {{ a.k === 'ig' ? 'إنستجرام بيتربط تلقائيًا مع صفحة فيسبوك المربوط بيها.' : '' }}</small>
                        <div class="sx-row-btns">
                            <a v-if="d.accounts.can_add" :href="base + '/social/connect.php'" class="sx-btn ghost sm">+ إضافة صفحة</a>
                            <span v-if="!d.accounts.can_add && d.accounts.allowed" class="sx-mute">وصلت لحد الصفحات ({{ d.accounts.max }})</span>
                            <a :href="base + '/social-diagnose.php'" class="sx-link">🩺 فحص الربط</a>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- ═══ الأمان ═══ -->
        <section class="sx-card sx-full sx-o5" aria-labelledby="sx-h-sec">
            <h3 class="sx-h" id="sx-h-sec"><span class="sx-ic dark"><?= ui_icon('shield', 17) ?></span>الأمان</h3>

            <!-- كلمة المرور -->
            <div class="sx-sec">
                <button type="button" class="sx-rowline sx-rowbtn" @click="pw.open = !pw.open" :aria-expanded="pw.open">
                    <span class="sx-grow">
                        <b>{{ d.security.has_password ? 'تغيير كلمة المرور' : 'ضبط كلمة مرور' }}</b>
                        <small v-if="!d.security.has_password">حسابك بجوجل — ضيف كلمة مرور علشان تقدر تدخل بالطريقتين</small>
                        <small v-if="d.security.has_password">{{ d.security.password_at ? 'آخر تغيير ' + d.security.password_at : 'ماتغيّرتش من وقت التسجيل' }}</small>
                    </span>
                    <span class="sx-chev" :class="{ open: pw.open }"><?= ui_icon('chevron', 18) ?></span>
                </button>
                <div class="sx-form sx-in" v-if="pw.open">
                    <input v-if="d.security.has_password" class="sx-input" type="password" v-model="pw.current" placeholder="كلمة المرور الحالية" aria-label="كلمة المرور الحالية" autocomplete="current-password">
                    <input class="sx-input" type="password" v-model="pw.new1" placeholder="كلمة المرور الجديدة (6 حروف على الأقل)" aria-label="كلمة المرور الجديدة" autocomplete="new-password">
                    <input class="sx-input" type="password" v-model="pw.new2" placeholder="تأكيد كلمة المرور" aria-label="تأكيد كلمة المرور" autocomplete="new-password">
                    <small class="sx-err" v-if="pw.new2 && pw.new1 !== pw.new2">كلمتين المرور مش متطابقين</small>
                    <small class="sx-mute">بعد التغيير هنخرّج أي جهاز تاني داخل على حسابك.</small>
                    <button type="button" class="sx-btn primary sm" @click="savePassword()"
                            :disabled="busy === 'pw' || pw.new1.length < 6 || pw.new1 !== pw.new2 || (d.security.has_password && !pw.current)">{{ busy === 'pw' ? 'بيحفظ…' : 'حفظ كلمة المرور' }}</button>
                </div>
            </div>

            <!-- التحقق بخطوتين -->
            <div class="sx-sec">
                <div class="sx-rowline">
                    <span class="sx-grow">
                        <b>التحقق بخطوتين</b>
                        <small>{{ d.security.two_fa ? 'مفعّل — كود على إيميلك مع كل تسجيل دخول' : 'غير مفعّل — فعّله علشان محدش يدخل حسابك بالباسورد لوحده' }}</small>
                    </span>
                    <button type="button" role="switch" class="sx-switch" :class="{ on: d.security.two_fa }" :aria-checked="d.security.two_fa ? 'true' : 'false'"
                            aria-label="التحقق بخطوتين" @click="startTwoFa()" :disabled="busy === '2fa' || !!tf.purpose"><span></span></button>
                </div>
                <div class="sx-form sx-in sx-code" v-if="tf.purpose">
                    <small>بعتنا كود من 6 أرقام على <b dir="ltr">{{ d.profile.email }}</b> — اكتبه علشان {{ tf.purpose === 'enable' ? 'نفعّل' : 'نوقف' }} التحقق بخطوتين.</small>
                    <div class="sx-row-btns">
                        <input class="sx-input code" v-model="tf.code" inputmode="numeric" maxlength="6" placeholder="••••••" aria-label="كود التحقق" autocomplete="one-time-code" dir="ltr" @keyup.enter="confirmTwoFa()">
                        <button type="button" class="sx-btn primary sm" @click="confirmTwoFa()" :disabled="busy === '2fa' || tf.code.length !== 6">تأكيد</button>
                        <button type="button" class="sx-btn ghost" @click="tf.purpose = ''; tf.code = ''">إلغاء</button>
                    </div>
                </div>
            </div>

            <!-- الجلسات النشطة -->
            <div class="sx-sec sx-sessions">
                <div class="sx-split">
                    <b>الجلسات النشطة</b>
                    <button type="button" class="sx-link" v-if="d.security.sessions.length > 1" @click="endOthers()" :disabled="busy === 'sess'">خروج من الأجهزة الأخرى</button>
                </div>
                <p class="sx-mute" v-if="!d.security.sessions.length">مفيش جلسات مسجّلة لسه.</p>
                <div class="sx-session" v-for="s in d.security.sessions" :key="s.id">
                    <span class="sx-grow"><b dir="ltr">{{ s.device }}</b><small>{{ s.where }}</small></span>
                    <span class="sx-chip ok" v-if="s.current">الجهاز ده</span>
                    <button type="button" class="sx-pill sm" v-if="!s.current" @click="endSession(s)" :disabled="busy === 'sess'">إنهاء</button>
                </div>
            </div>

            <!-- حذف الحساب -->
            <div class="sx-del">
                <div class="sx-danger sx-in" v-if="d.security.deletion">
                    <span><b>حسابك هيتمسح نهائيًا يوم {{ d.security.deletion.date }}</b> (باقي {{ d.security.deletion.days }} يوم) — لو غيّرت رأيك ألغِ الطلب قبلها.</span>
                    <button type="button" class="sx-btn ghost" @click="cancelDelete()" :disabled="busy === 'del'">إلغاء طلب الحذف</button>
                </div>
                <div class="sx-danger sx-in" v-if="!d.security.deletion && del.open">
                    <span>حذف الحساب هيمسح Brand Brain والحملات والمحتوى والتصميمات والرصيد، وهيفصل كل الحسابات المربوطة. تقدر تلغي الطلب خلال 14 يوم.</span>
                    <input v-if="d.security.has_password" class="sx-input" type="password" v-model="del.confirm" placeholder="اكتب كلمة المرور للتأكيد" aria-label="كلمة المرور للتأكيد" autocomplete="current-password">
                    <input v-if="!d.security.has_password" class="sx-input" dir="ltr" type="email" v-model="del.confirm" :placeholder="d.profile.email" aria-label="اكتب إيميلك للتأكيد">
                    <div class="sx-row-btns">
                        <button type="button" class="sx-btn red" @click="requestDelete()" :disabled="busy === 'del' || !del.confirm">تأكيد الحذف</button>
                        <button type="button" class="sx-btn ghost" @click="del.open = false; del.confirm = ''">إلغاء</button>
                    </div>
                </div>
                <button type="button" class="sx-link danger" v-if="!d.security.deletion && !del.open" @click="del.open = true">حذف الحساب</button>
            </div>
        </section>
    </div>

    <footer class="sx-foot" v-if="d">
        <span>إعدادات الهوية والحملات والـ AI موجودة جوه كل قسم.</span>
        <a :href="base + '/logout.php'" class="sx-link danger">تسجيل الخروج</a>
        <form method="POST" :action="base + '/profile.php'" v-if="d.ui_v1_allowed">
            <?= $__v1Csrf ?>
            <input type="hidden" name="action" value="ui_pref"><input type="hidden" name="ui_pref" value="v1">
            <button type="submit" class="sx-link muted">↩ ارجع للشكل القديم</button>
        </form>
    </footer>

    <div class="toast-container" v-if="toast"><div class="toast" :class="toast.type">{{ toast.msg }}</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var base = document.querySelector('meta[name="app-base"]').content;
  var app = SpreadApp.reactive({
    base: base, d: null, toast: null, busy: '', editing: false, open: '',
    draft: { name: '', phone: '' }, avatarBusy: false,
    show: { usage: false, plans: false },
    pw: { open: false, current: '', new1: '', new2: '' },
    tf: { purpose: '', code: '' },
    del: { open: false, confirm: '' },

    notify: function (msg, type) { SpreadApp.toast(this, msg, type || 'success'); },
    async load() {
      var r = await SpreadAPI.get('settings', { action: 'home' });
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d = r;
      // ?open=plans / usage (جاي من الجرس أو القائمة)
      var q = new URLSearchParams(location.search).get('open');
      if (q === 'plans' || q === 'usage') this.show[q] = true;
    },
    high: function () { return this.d && (this.d.plan.pct >= 85 || (this.d.plan.show && this.d.plan.balance <= 3) || (this.d.plan.days_left !== null && this.d.plan.days_left <= 3)); },
    part: function (c) { return this.d.plan.used ? Math.max(3, Math.round(c / this.d.plan.used * 100)) : 0; },
    share: function () {
      var t = this.d.referral.share;
      if (navigator.share) { navigator.share({ title: 'Spread AI', text: t }).catch(function () {}); return; }
      window.open('https://wa.me/?text=' + encodeURIComponent(t), '_blank', 'noopener');
    },
    money: function (n) { return Number(n || 0).toLocaleString('ar-EG'); },
    shortLink: function () { return (this.d.referral.link || '').replace(/^https?:\/\//, ''); },
    copy: function (t, ok) {
      var self = this;
      var fb = function () {
        var ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); self.notify(ok); } catch (e) { self.notify('انسخ يدويًا', 'warning'); }
        document.body.removeChild(ta);
      };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t).then(function () { self.notify(ok); }, fb); else fb();
    },

    /* ── الحساب ── */
    startEdit: function () { this.draft.name = this.d.profile.name; this.draft.phone = this.d.profile.phone; this.editing = true; },
    async saveProfile() {
      this.busy = 'profile';
      var r = await SpreadAPI.post('settings', 'profile_save', { name: this.draft.name, phone: this.draft.phone });
      this.busy = '';
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d.profile.name = r.name; this.d.profile.phone = r.phone; this.d.profile.initials = r.initials;
      this.editing = false; this.notify('اتحفظت بياناتك ✓');
      document.querySelectorAll('.v2-me-txt b').forEach(function (b) { b.textContent = r.name.split(' ')[0].slice(0, 14); });
    },
    pickAvatar: function (ev) {
      var f = ev.target.files && ev.target.files[0], self = this;
      ev.target.value = '';
      if (!f) return;
      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) return this.notify('اختار صورة JPG أو PNG', 'danger');
      if (f.size > 15 * 1024 * 1024) return this.notify('الصورة كبيرة قوي', 'danger');
      var img = new Image(), url = URL.createObjectURL(f);
      img.onload = async function () {
        // قص مربع من النص وتصغير لـ 256px — الرفع بيبقى ~30KB
        var s = Math.min(img.width, img.height), c = document.createElement('canvas');
        c.width = c.height = 256;
        c.getContext('2d').drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, 0, 0, 256, 256);
        URL.revokeObjectURL(url);
        self.avatarBusy = true;
        var r = await SpreadAPI.post('settings', 'avatar', { image: c.toDataURL('image/jpeg', 0.86) });
        self.avatarBusy = false;
        if (!r.ok) return self.notify(r.error, 'danger');
        self.d.profile.avatar = r.avatar; self.notify('اتغيّرت الصورة ✓');
        self.syncTopAvatar(r.avatar);
      };
      img.onerror = function () { URL.revokeObjectURL(url); self.notify('تعذّر قراءة الصورة', 'danger'); };
      img.src = url;
    },
    async removeAvatar() {
      var r = await SpreadAPI.post('settings', 'avatar_remove', {});
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d.profile.avatar = null; this.notify('اتشالت الصورة'); this.syncTopAvatar(null);
    },
    syncTopAvatar: function (src) {
      var ini = this.d.profile.initials;
      document.querySelectorAll('.v2-avatar').forEach(function (a) {
        a.textContent = '';
        if (src) { var i = document.createElement('img'); i.src = src; i.alt = ''; a.appendChild(i); } else { a.textContent = ini; }
      });
    },

    /* ── الحسابات المربوطة ── */
    accounts: function () {
      var A = this.d.accounts, pages = A.pages, ig = pages.filter(function (p) { return p.has_ig; });
      var names = function (l, f) { return l.map(f).join('، '); };
      var bad = function (l) { return l.some(function (p) { return p.status !== 'active'; }); };
      return [
        { k: 'fb', name: 'Facebook', short: 'FB', on: !!pages.length, pages: pages, warn: bad(pages),
          sub: pages.length ? names(pages, function (p) { return p.name; }) : (A.allowed ? 'غير مربوط' : 'النشر التلقائي مش مفعّل'),
          canConnect: A.allowed && !pages.length, locked: !A.allowed && !pages.length },
        { k: 'ig', name: 'Instagram', short: 'IG', on: !!ig.length, pages: ig, warn: bad(ig),
          sub: ig.length ? names(ig, function (p) { return '@' + p.ig; }) : (pages.length ? 'اربط حساب إنستجرام بيزنس بصفحتك على فيسبوك' : 'بيتربط مع صفحة فيسبوك'),
          canConnect: A.allowed && !ig.length, locked: !A.allowed && !ig.length },
        { k: 'li', name: 'LinkedIn', short: 'in', sub: 'قريبًا', soon: true },
        { k: 'tt', name: 'TikTok', short: 'TT', sub: 'قريبًا', soon: true },
        { k: 'yt', name: 'YouTube', short: 'YT', sub: 'قريبًا', soon: true },
      ];
    },
    toggleAcc: function (k) { this.open = this.open === k ? '' : k; },
    statusLabel: function (s) { return { active: 'نشطة — بتنشر عادي', expired: 'الربط انتهى — أعد الربط', revoked: 'الصلاحية اتلغت — أعد الربط', error: 'فيه مشكلة — أعد الربط' }[s] || s; },
    async disconnect(p) {
      if (!confirm('فصل «' + p.name + '»؟ البوستات المحجوزة عليها هتتلغي، والتوكن هيتمسح نهائيًا.')) return;
      this.busy = 'disc' + p.id;
      var r = await SpreadAPI.post('settings', 'disconnect', { id: p.id });
      this.busy = '';
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d.accounts.pages = this.d.accounts.pages.filter(function (x) { return x.id !== p.id; });
      this.d.accounts.can_add = this.d.accounts.allowed && this.d.accounts.pages.length < this.d.accounts.max;
      if (!this.d.accounts.pages.length) this.open = '';
      this.notify(r.msg);
    },

    /* ── الأمان ── */
    async savePassword() {
      this.busy = 'pw';
      var r = await SpreadAPI.post('settings', 'password', { current: this.pw.current, 'new': this.pw.new1, confirm: this.pw.new2 });
      this.busy = '';
      if (!r.ok) return this.notify(r.error, 'danger');
      this.pw = { open: false, current: '', new1: '', new2: '' };
      this.d.security.has_password = true; this.d.security.password_at = 'دلوقتي';
      this.notify(r.msg);
      this.reloadSessions();
    },
    async reloadSessions() {
      var r = await SpreadAPI.get('settings', { action: 'home' });
      if (r.ok) this.d.security.sessions = r.security.sessions;
    },
    async startTwoFa() {
      this.busy = '2fa';
      var purpose = this.d.security.two_fa ? 'disable' : 'enable';
      var r = await SpreadAPI.post('settings', 'twofa_start', { purpose: purpose });
      this.busy = '';
      if (!r.ok) return this.notify(r.error, 'danger');
      this.tf.purpose = purpose; this.tf.code = '';
      this.notify('بعتنا الكود على إيميلك ✉️');
    },
    async confirmTwoFa() {
      if (this.tf.code.length !== 6) return;
      this.busy = '2fa';
      var r = await SpreadAPI.post('settings', 'twofa_confirm', { code: this.tf.code });
      this.busy = '';
      if (!r.ok) { this.tf.code = ''; return this.notify(r.error, 'danger'); }
      this.d.security.two_fa = r.two_fa; this.tf.purpose = ''; this.tf.code = '';
      this.notify(r.two_fa ? 'اتفعّل التحقق بخطوتين 🔐' : 'اتوقف التحقق بخطوتين');
    },
    async endSession(s) {
      this.busy = 'sess';
      var r = await SpreadAPI.post('settings', 'session_end', { id: s.id });
      this.busy = '';
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d.security.sessions = r.sessions; this.notify('اتنهت الجلسة ✓');
    },
    async endOthers() {
      if (!confirm('تخرج من كل الأجهزة التانية؟')) return;
      this.busy = 'sess';
      var r = await SpreadAPI.post('settings', 'sessions_end_others', {});
      this.busy = '';
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d.security.sessions = r.sessions; this.notify(r.ended ? 'خرّجنا ' + r.ended + ' جهاز ✓' : 'مفيش أجهزة تانية');
    },
    async requestDelete() {
      this.busy = 'del';
      var body = this.d.security.has_password ? { password: this.del.confirm } : { email: this.del.confirm };
      var r = await SpreadAPI.post('settings', 'delete_request', body);
      this.busy = '';
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d.security.deletion = r.deletion; this.del = { open: false, confirm: '' };
      this.notify('اتسجّل طلب الحذف — تقدر تلغيه خلال 14 يوم', 'warning');
    },
    async cancelDelete() {
      this.busy = 'del';
      var r = await SpreadAPI.post('settings', 'delete_cancel', {});
      this.busy = '';
      if (!r.ok) return this.notify(r.error, 'danger');
      this.d.security.deletion = null; this.notify('اتلغى طلب الحذف — حسابك زي ما هو ✓');
    },
  });
  SpreadApp.mount('#sx', app);
  app.load();
});
</script>
