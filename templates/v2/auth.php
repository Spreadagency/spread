<?php
/**
 * Spread AI v2 — شاشة الدخول والتسجيل (زي التصميم)
 * يمين: الفورم بتبويبين · شمال: الروبوت في مدار + كروت طايرة + «من الفكرة… إلى النشر»
 *
 * المنطق (التحقق · حد المحاولات · التجربة · الإحالة) لسه في login.php / register.php زي ما هو —
 * القالب ده عرض بس، وبيستخدم نفس أسماء الحقول بالظبط.
 *
 * متغيرات: $authMode (login|register|verify) · $errors · $email · $old · $maskedEmail (verify)
 */
require_once __DIR__ . '/../../includes/ui-v2.php';

$authMode = in_array($authMode, ['register', 'verify'], true) ? $authMode : 'login';
$errors = $errors ?? [];
$old = $old ?? ['name' => '', 'email' => '', 'phone' => ''];
$body_class = trim(($body_class ?? '') . ' v2-auth-page');
$__no_tabbar = true;
$__robot = url('assets/img/robot.webp');
$__robotAnim = url('assets/img/robot-anim.webp');
include __DIR__ . '/../header.php';
?>
<div class="au">
    <header class="au-top">
        <a href="<?= url('/') ?>" class="v2-logo">
            <img src="<?= url('assets/img/spread-mark-128.png') ?>" alt="" width="40" height="33">
            <span><b>Spread <i>AI</i></b><small>CREATE · PLAN · PUBLISH</small></span>
        </a>
    </header>

    <main class="au-grid">
        <!-- ═══ الفورم ═══ -->
        <section class="au-card" aria-label="<?= ['login' => 'تسجيل الدخول', 'register' => 'إنشاء حساب', 'verify' => 'التحقق بخطوتين'][$authMode] ?>">
            <?php if ($authMode === 'verify'): ?>
                <span class="au-chip"><?= ui_icon('lock', 14) ?> خطوة أمان</span>
                <h1>اكتب الكود اللي وصلك 🔐</h1>
                <p class="au-sub">بعتنا كود من 6 أرقام على <b dir="ltr"><?= e($maskedEmail ?? '') ?></b> — صالح 10 دقايق.</p>
                <?= render_flash() ?>
                <?php foreach ($errors as $err): ?>
                    <div class="alert danger" role="alert"><?= e($err) ?></div>
                <?php endforeach; ?>
                <form method="POST" class="au-form" data-au-form autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify">
                    <div class="au-field">
                        <label for="au-code">كود التحقق</label>
                        <div class="au-input"><?= ui_icon('lock', 18) ?>
                            <input id="au-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus
                                   autocomplete="one-time-code" dir="ltr" class="au-code" placeholder="••••••">
                        </div>
                    </div>
                    <button type="submit" class="au-submit" data-busy-text="بنتأكد...">تأكيد ودخول <?= ui_icon('chevron', 18) ?></button>
                </form>
                <form method="POST" class="au-resend">
                    <?= csrf_field() ?><input type="hidden" name="action" value="resend">
                    <button type="submit">ماوصلكش؟ ابعت كود جديد</button> ·
                    <a href="<?= url('login-verify.php?cancel=1') ?>">ادخل بحساب تاني</a>
                </form>
            <?php else: ?>
            <nav class="au-tabs" aria-label="نوع الدخول">
                <a href="<?= url('login.php') ?>" class="<?= $authMode === 'login' ? 'on' : '' ?>" <?= $authMode === 'login' ? 'aria-current="page"' : '' ?>>تسجيل الدخول</a>
                <a href="<?= url('register.php') ?>" class="<?= $authMode === 'register' ? 'on' : '' ?>" <?= $authMode === 'register' ? 'aria-current="page"' : '' ?>>حساب جديد</a>
            </nav>

            <?php if ($authMode === 'login'): ?>
                <span class="au-chip"><?= ui_icon('sparkles', 14) ?> مرحبًا بيك</span>
                <h1>أهلاً بيك في Spread AI 👋</h1>
                <p class="au-sub">ابدأ رحلتك في صناعة وتسويق محتواك بالذكاء الاصطناعي</p>
            <?php else: ?>
                <span class="au-chip"><?= ui_icon('sparkles', 14) ?> دقيقة واحدة وتبدأ</span>
                <h1>ابدأ مع Spread AI 🚀</h1>
                <p class="au-sub">أنشئ حسابك وخلّي الذكاء الاصطناعي يساعدك في تسويق مشروعك</p>
            <?php endif; ?>

            <?= render_flash() ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert danger" role="alert"><?= e($err) ?></div>
            <?php endforeach; ?>

            <?php if ($authMode === 'login'): ?>
            <form method="POST" autocomplete="on" class="au-form" data-au-form>
                <?= csrf_field() ?>
                <div class="au-field">
                    <label for="au-email">البريد الإلكتروني</label>
                    <div class="au-input"><?= ui_icon('mail', 18) ?>
                        <input id="au-email" type="email" name="email" required autocomplete="email" dir="ltr"
                               value="<?= e($email ?? '') ?>" placeholder="you@example.com">
                    </div>
                </div>
                <div class="au-field">
                    <label for="au-pw">كلمة المرور</label>
                    <div class="au-input"><?= ui_icon('lock', 18) ?>
                        <input id="au-pw" type="password" name="password" required autocomplete="current-password" dir="ltr">
                        <button type="button" class="au-eye" aria-label="إظهار كلمة المرور" data-eye="au-pw"><?= ui_icon('eye', 18) ?></button>
                    </div>
                    <a href="<?= url('forgot-password.php') ?>" class="au-forgot">نسيت كلمة المرور؟</a>
                </div>
                <label class="au-remember">
                    <input type="checkbox" name="remember" value="1" <?= function_exists('remember_default') && remember_default() ? 'checked' : '' ?>>
                    <span>افتكرني على الجهاز ده</span>
                </label>
                <button type="submit" class="au-submit" data-busy-text="بنسجّل دخولك...">
                    تسجيل الدخول <?= ui_icon('chevron', 18) ?>
                </button>
            </form>
            <?php else: ?>
            <form method="POST" autocomplete="on" class="au-form" data-au-form>
                <?= csrf_field() ?>
                <div class="au-row">
                    <div class="au-field">
                        <label for="au-name">الاسم</label>
                        <div class="au-input"><?= ui_icon('user', 18) ?>
                            <input id="au-name" type="text" name="name" required minlength="2" autocomplete="name"
                                   value="<?= e($old['name'] ?? '') ?>" placeholder="اسمك بالكامل">
                        </div>
                    </div>
                    <div class="au-field">
                        <label for="au-biz">اسم النشاط التجاري</label>
                        <div class="au-input"><?= ui_icon('megaphone', 18) ?>
                            <input id="au-biz" type="text" name="business_name" maxlength="120" autocomplete="organization"
                                   value="<?= e($old['business_name'] ?? '') ?>" placeholder="مثلاً: مطعم البيت">
                        </div>
                    </div>
                </div>
                <div class="au-field">
                    <label for="au-email">البريد الإلكتروني</label>
                    <div class="au-input"><?= ui_icon('mail', 18) ?>
                        <input id="au-email" type="email" name="email" required autocomplete="email" dir="ltr"
                               value="<?= e($old['email'] ?? '') ?>" placeholder="you@example.com">
                    </div>
                </div>
                <div class="au-field">
                    <label for="au-phone">رقم الموبايل</label>
                    <div class="au-input"><?= ui_icon('phone', 18) ?>
                        <input id="au-phone" type="tel" name="phone" required pattern="[0-9+]{8,20}" autocomplete="tel" dir="ltr"
                               value="<?= e($old['phone'] ?? '') ?>" placeholder="01xxxxxxxxx">
                    </div>
                </div>
                <div class="au-row">
                    <div class="au-field">
                        <label for="au-pw">كلمة المرور</label>
                        <div class="au-input"><?= ui_icon('lock', 18) ?>
                            <input id="au-pw" type="password" name="password" required minlength="6" autocomplete="new-password" dir="ltr" data-strength>
                            <button type="button" class="au-eye" aria-label="إظهار كلمة المرور" data-eye="au-pw"><?= ui_icon('eye', 18) ?></button>
                        </div>
                        <div class="au-meter" aria-live="polite"><span></span><span></span><span></span><em></em></div>
                    </div>
                    <div class="au-field">
                        <label for="au-pw2">تأكيد كلمة المرور</label>
                        <div class="au-input"><?= ui_icon('lock', 18) ?>
                            <input id="au-pw2" type="password" name="password_confirm" required minlength="6" autocomplete="new-password" dir="ltr" data-match="au-pw">
                        </div>
                        <div class="au-match" aria-live="polite"></div>
                    </div>
                </div>
                <details class="au-ref" <?= function_exists('referral_active_code') && referral_active_code() !== '' ? 'open' : '' ?>>
                    <summary>عندك كود دعوة أو خصم؟</summary>
                    <div class="au-input"><?= ui_icon('gift', 18) ?>
                        <input type="text" name="ref_code" dir="ltr" data-no-encode="1" style="text-transform:uppercase"
                               value="<?= e(function_exists('referral_active_code') ? referral_active_code() : '') ?>" placeholder="SPREAD123">
                    </div>
                    <?php if (function_exists('referral_active_code') && referral_active_code() !== ''): ?>
                        <small class="au-ok">✓ كود الدعوة اتسجل — هتاخد رصيد هدية بعد التفعيل</small>
                    <?php endif; ?>
                </details>
                <p class="au-terms">بإنشاء الحساب بتوافق على <a href="<?= url('/') ?>" target="_blank">الشروط</a> و<a href="<?= url('/') ?>" target="_blank">سياسة الخصوصية</a>.</p>
                <button type="submit" class="au-submit" data-busy-text="بنجهّز حسابك...">
                    إنشاء الحساب <?= ui_icon('chevron', 18) ?>
                </button>
            </form>
            <?php endif; ?>

            <?php require_once __DIR__ . '/../../includes/google-auth.php'; if (google_enabled()): ?>
                <div class="au-or"><span>أو</span></div>
            <?php endif; ?>
            <?php $googleIntent = $authMode; include __DIR__ . '/../google-button.php'; ?>

            <p class="au-switch">
                <?php if ($authMode === 'login'): ?>
                    مش عندك حساب؟ <a href="<?= url('register.php') ?>">إنشاء حساب</a>
                <?php else: ?>
                    عندك حساب بالفعل؟ <a href="<?= url('login.php') ?>">تسجيل الدخول</a>
                <?php endif; ?>
            </p>
            <?php endif; /* verify */ ?>
        </section>

        <!-- ═══ المشهد: الروبوت في المدار ═══ -->
        <aside class="au-scene" aria-hidden="true">
            <ol class="au-steps">
                <li class="on">من الفكرة...</li><li>إلى المحتوى...</li><li>إلى التصميم...</li><li>إلى النشر.</li>
            </ol>
            <p class="au-scene-sub">Spread AI بيشتغل معاك كأنه فريق التسويق الخاص بيك.</p>

            <div class="au-orbit">
                <span class="au-ring au-ring-1"></span>
                <span class="au-ring au-ring-2"></span>
                <span class="au-glow"></span>
                <i class="au-spark s1"></i><i class="au-spark s2"></i><i class="au-spark s3"></i><i class="au-spark s4"></i>

                <button type="button" class="au-robot" tabindex="-1" data-robot>
                    <picture>
                        <source media="(min-width: 981px) and (prefers-reduced-motion: no-preference)" srcset="<?= e($__robotAnim) ?>" type="image/webp">
                        <img src="<?= e($__robot) ?>" alt="" width="330" height="357" decoding="async">
                    </picture>
                </button>
                <div class="au-bubble" data-bubble hidden></div>

                <div class="au-float f1"><span class="au-float-ic"><?= ui_icon('edit', 16) ?></span><span><b>كابشن جاهز</b><small>بصوت براندك</small></span></div>
                <div class="au-float f2"><span class="au-float-ic"><?= ui_icon('image', 16) ?></span><span><b>تصميم جديد</b><small>مقاس بوست وستوري</small></span></div>
                <div class="au-float f3"><span class="au-float-ic"><?= ui_icon('calendar', 16) ?></span><span><b>اتجدول للنشر</b><small>Instagram · Facebook</small></span></div>
                <span class="au-bub b1"><?= ui_icon('megaphone', 18) ?></span>
                <span class="au-bub b2"><?= ui_icon('chart', 18) ?></span>
                <span class="au-bub b3"><?= ui_icon('star', 16) ?></span>
            </div>
        </aside>
    </main>
</div>

<script>
(function () {
  // «من الفكرة… إلى النشر» — خطوة كل 2.2 ثانية
  var steps = document.querySelectorAll('.au-steps li'), i = 0;
  if (steps.length && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    setInterval(function () {
      steps[i].classList.remove('on'); i = (i + 1) % steps.length; steps[i].classList.add('on');
    }, 2200);
  }

  // الروبوت بيسلّم لما تضغط عليه (زي التصميم)
  var lines = ['أهلاً! أنا مساعدك في Spread AI', 'بكتب، بصمم، وبنشر معاك'], n = -1, t = null;
  var bot = document.querySelector('[data-robot]'), bub = document.querySelector('[data-bubble]');
  function say() {
    n = (n + 1) % lines.length; bub.textContent = lines[n]; bub.hidden = false;
    clearTimeout(t); t = setTimeout(function () { bub.hidden = true; }, 2600);
  }
  if (bot && bub) { bot.addEventListener('click', say); setTimeout(say, 900); }

  // إظهار كلمة المرور
  document.querySelectorAll('[data-eye]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = document.getElementById(b.dataset.eye), show = f.type === 'password';
      f.type = show ? 'text' : 'password';
      b.setAttribute('aria-label', show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
      b.classList.toggle('on', show);
    });
  });

  // قوة كلمة المرور + التطابق
  var pw = document.querySelector('[data-strength]'), meter = document.querySelector('.au-meter');
  if (pw && meter) {
    var labels = ['', 'ضعيفة — زوّد أرقام وحروف', 'متوسطة — قربت', 'قوية'];
    pw.addEventListener('input', function () {
      var v = pw.value, sc = 0;
      if (v.length >= 6) sc++;
      if (v.length >= 8 && /\d/.test(v) && /[a-z\u0600-\u06FF]/i.test(v)) sc++;
      if (v.length >= 10 && /[^a-z0-9\u0600-\u06FF]/i.test(v)) sc++;
      meter.dataset.s = v ? Math.max(1, sc) : 0;
      meter.querySelector('em').textContent = v ? labels[Math.max(1, sc)] : '';
      check();
    });
  }
  var pw2 = document.querySelector('[data-match]'), mt = document.querySelector('.au-match');
  function check() {
    if (!pw2 || !mt) return;
    var a = document.getElementById(pw2.dataset.match).value, b = pw2.value;
    mt.textContent = b ? (a === b ? '✓ متطابقة' : 'مش متطابقة') : '';
    mt.className = 'au-match ' + (b ? (a === b ? 'ok' : 'no') : '');
  }
  if (pw2) pw2.addEventListener('input', check);
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
