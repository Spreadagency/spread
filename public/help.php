<?php
/**
 * Spread AI v2 — دليل المنصة الكامل
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';

require_login();
$user = current_user();

$genCost    = cost_for('content_generation_cost');
$designCost = cost_for('content_design_cost');
$ideasCost  = (int) get_setting('plan_ideas_cost', 2);
$sumCost    = (int) get_setting('source_summary_cost', 1);
$stIdeaCost = (int) get_setting('studio_idea_cost', 1);
$packages   = db_all('SELECT * FROM credit_packages WHERE is_active = 1 ORDER BY order_num ASC');
$waPay      = preg_replace('/[^0-9]/', '', (string) get_setting('pay_whatsapp', ''));

$sections = [
    ['id' => 'start',   'ico' => '🚀', 't' => 'البداية الصحيحة'],
    ['id' => 'brand',   'ico' => '◈',  't' => 'صناعة هويتك'],
    ['id' => 'plan',    'ico' => '🗓', 't' => 'الخطة الإعلانية'],
    ['id' => 'write',   'ico' => '✎',  't' => 'كتابة الإعلان'],
    ['id' => 'design',  'ico' => '🎨', 't' => 'صناعة التصميم'],
    ['id' => 'publish', 'ico' => '📤', 't' => 'الجدولة والنشر'],
    ['id' => 'credits', 'ico' => '◇',  't' => 'نظام الاشتراك والرصيد'],
];

$active = 'help';
$page_title = 'دليل المنصة';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1>دليل المنصة ❓</h1>
            <div class="sub">كل حاجة محتاج تعرفها — خطوة بخطوة من الهوية للنشر</div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
            <?php foreach ($sections as $sec): ?>
                <a href="#<?= $sec['id'] ?>" class="chip"><?= $sec['ico'] ?> <?= e($sec['t']) ?></a>
            <?php endforeach; ?>
        </div>

        <!-- البداية -->
        <div class="card" id="start" style="margin-bottom:16px">
            <div class="card-head"><h3>🚀 البداية الصحيحة — 4 خطوات</h3></div>
            <p>المنصة مبنية على فكرة بسيطة: <b>كل ما الـ AI يعرف بيزنسك أكتر، كل ما المحتوى والتصميمات تطلع «بتاعتك» فعلًا</b>. عشان كده الترتيب الصح:</p>
            <ol style="margin:12px 24px;line-height:2.1">
                <li><b>اصنع هويتك</b> — مرة واحدة، وكل حاجة بعدها بتتبني عليها</li>
                <li><b>اعمل خطة إعلانية</b> — أفكار شهر كامل في دقايق</li>
                <li><b>اكتب إعلاناتك</b> — من الخطة أو منشور منفرد</li>
                <li><b>اصنع تصميماتك</b> — بألوانك ولوجوك وستايلك</li>
            </ol>
        </div>

        <!-- الهوية -->
        <div class="card" id="brand" style="margin-bottom:16px">
            <div class="card-head"><h3>◈ صناعة هويتك (أهم خطوة)</h3></div>
            <p><b>3 طرق تبني بيها هويتك:</b></p>
            <p style="margin-top:10px">1️⃣ <b><a href="<?= url('brand-agent.php') ?>">🤖 مساعد الهوية الذكي</a></b> — أسهل طريقة: بيسألك سؤال سؤال (إيه بيزنسك؟ مين جمهورك؟ إيه ألوانك؟...) وفي الآخر بيحفظ الهوية كاملة لوحده. مناسب لو مش عارف تبدأ منين.</p>
            <p style="margin-top:8px">2️⃣ <b><a href="<?= url('brand-profile.php') ?>">صفحة هوية البراند</a></b> — تملأ البيانات بنفسك: الاسم، المجال، الوصف، الجمهور، النبرة، الألوان، الكلمات المفضلة/الممنوعة، <b>وشروط التصميم</b> (مثلًا: اللوجو دايمًا فوق يمين، ممنوع الأحمر). وارفع اللوجو وصورك.</p>
            <p style="margin-top:8px">3️⃣ <b><a href="<?= url('sources.php') ?>">مستندات الهوية</a></b> — ارفع بروفايل شركتك PDF أو Word أو لينك موقعك، والـ AI بيستخرج منها معلومات حقيقية (خدماتك، أسعارك، مميزاتك) وبيستخدمها في كل توليد (<?= $sumCost ?> ◇ للتلخيص).</p>
            <p style="margin-top:8px">✦ <b>ملخص الهوية الذكي:</b> في صفحة الهوية، زرار بيخلي الـ AI يحلل اللوجو وصورك ومستنداتك ويكتب ملخص شامل — الملخص ده بيدخل بعدها في كل محتوى وتصميم.</p>
            <p style="margin-top:8px">🎨 <b>معرض الإلهام:</b> اختار التصميمات اللي تعجبك بالـ ❤ — ذوقك بيدخل تلقائيًا في كل تصميم يتولد لك.</p>
        </div>

        <!-- الخطة -->
        <div class="card" id="plan" style="margin-bottom:16px">
            <div class="card-head"><h3>🗓 الخطة الإعلانية</h3></div>
            <p>من <a href="<?= url('content-plan.php') ?>">خطة المحتوى</a>: أنشئ خطة (حدد الهدف: وعي / مبيعات / تفاعل / مزيج) → الـ AI يقترح لحد 30 فكرة متنوعة (قصصي، إعلاني، توعوي، تعليمي، عروض، ترندات) مبنية على هويتك ومستنداتك (<?= $ideasCost ?> ◇ للأفكار كلها).</p>
            <p style="margin-top:8px">✓ اختار الأفكار اللي تعجبك → <b>🚀 إنتاج المختار</b> → كل فكرة بتتحول لبوست كامل: محتوى + هاشتاجات + CTA + <b>فكرة تصميم جاهزة</b> (<?= $genCost ?> ◇ للبوست).</p>
            <p style="margin-top:8px">🗓 تبويب التقويم: وزّع البوستات تلقائيًا (كل يوم/يومين/أسبوع) أو اسحب أي بوست ليوم تاني.</p>
        </div>

        <!-- الكتابة -->
        <div class="card" id="write" style="margin-bottom:16px">
            <div class="card-head"><h3>✎ كتابة الإعلان</h3></div>
            <p>من <a href="<?= url('create-content.php') ?>">إنشاء منشور</a>: اختار النوع (تعريفي / تسويقي / تعليمي / تفاعلي / عرض / ترند) والمنصة والطول والنبرة → توليد (<?= $genCost ?> ◇). المحتوى بيطلع بلهجة وأسلوب براندك ومبني على معلوماتك الحقيقية.</p>
            <p style="margin-top:8px">داخل أي بوست تقدر: تعدّل النص، تطلب إعادة توليد بملاحظات، تشوف النسخ السابقة، وتولّد تصميمات.</p>
        </div>

        <!-- التصميم -->
        <div class="card" id="design" style="margin-bottom:16px">
            <div class="card-head"><h3>🎨 صناعة التصميم</h3></div>
            <p><b>من داخل أي بوست:</b> صندوق «فكرة التصميم» بيكون فيه اقتراح الـ AI جاهز (عدّله أو اكتب فكرتك) + اختيارات:</p>
            <ul style="margin:8px 24px;line-height:2">
                <li>✔ <b>إضافة لوجو البراند</b> — اللوجو بيتبعت للموديل نفسه يدمجه في التصميم</li>
                <li>✔ <b>صورة العميل / المنتج</b> — بتظهر في التصميم</li>
                <li>📎 <b>صورة ستايل مرجعية</b> — ارفعها أو اختارها من معرض الإلهام أو صورك، والموديل يلتزم بستايلها بالظبط</li>
            </ul>
            <p style="margin-top:8px"><b><a href="<?= url('design-studio.php') ?>">✨ استوديو التصميم</a></b> — تصميمات مستقلة من غير بوست، بأربع أوضاع: من محتوى (مع اقتراح فكرة بـ <?= $stIdeaCost ?> ◇) / <b>قبل وبعد</b> بصورتين / برومبت حر / <b>تصور لصورتك الشخصية</b>. وفي كله تقدر تختار <b>قالب ستايل</b> جاهز. (كل تصميم <?= $designCost ?> ◇)</p>
        </div>

        <!-- النشر -->
        <div class="card" id="publish" style="margin-bottom:16px">
            <div class="card-head"><h3>📤 الجدولة والنشر</h3></div>
            <p>من داخل أي بوست: انشر فورًا على فيسبوك / انستجرام (بعد ربط حساباتك من الإعدادات) أو حدد ميعاد والنشر التلقائي هيشتغل لوحده. انستجرام محتاج تصميم مرفق بالبوست.</p>
        </div>

        <!-- الاشتراك -->
        <div class="card" id="credits" style="margin-bottom:16px">
            <div class="card-head"><h3>◇ نظام الاشتراك والرصيد</h3></div>
            <p>المنصة بتشتغل بنظام <b>الكريدت</b> — بتدفع على قد ما بتستخدم:</p>
            <div class="table-wrap" style="margin:10px 0">
            <table class="table">
                <thead><tr><th>العملية</th><th>التكلفة</th></tr></thead>
                <tbody>
                    <tr><td>توليد منشور / بوست من الخطة</td><td><?= $genCost ?> ◇</td></tr>
                    <tr><td>توليد تصميم (من البوست أو الاستوديو)</td><td><?= $designCost ?> ◇</td></tr>
                    <tr><td>أفكار خطة كاملة (لحد 30 فكرة)</td><td><?= $ideasCost ?> ◇</td></tr>
                    <tr><td>فكرة تصميم في الاستوديو</td><td><?= $stIdeaCost ?> ◇</td></tr>
                    <tr><td>تلخيص مستند هوية</td><td><?= $sumCost ?> ◇</td></tr>
                </tbody>
            </table>
            </div>
            <?php if ($packages): ?>
            <p><b>الباقات المتاحة:</b></p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
                <?php foreach ($packages as $p): ?>
                    <span class="chip chip-primary"><?= e($p['name']) ?>: <?= (int) $p['credits'] ?> ◇ بـ <?= number_format((float) $p['price_egp'], 0) ?> جنيه</span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p style="margin-top:8px"><b>⏳ صلاحية الرصيد شهر</b> من تاريخ الشحن. لو انتهت، عندك <b>3 أيام سماح</b>: جدّد خلالهم وأي رصيد متبقي بيرجعلك كامل مع الشحنة الجديدة — بعد الـ 3 أيام بيسقط.</p>
            <p style="margin-top:8px">💳 <b>الشحن:</b> من <a href="<?= url('credits.php') ?>">صفحة الرصيد</a> — تحويل إنستاباي أو فودافون كاش وبعدها تبعت إثبات الدفع واتساب والرصيد بيتشحن فورًا. ولو أي عملية AI فشلت، الكريدت بيرجع لك تلقائيًا.</p>
        </div>

        <div class="card" style="background:var(--primary-soft)">
            <b>💬 محتاج مساعدة؟</b>
            <p class="sub" style="margin-top:6px">
                <?php if ($waPay): ?>
                    كلمنا واتساب مباشرة: <a href="https://wa.me/<?= e($waPay) ?>" target="_blank" rel="noopener" dir="ltr">+<?= e($waPay) ?></a>
                <?php else: ?>
                    تواصل مع إدارة المنصة وهنساعدك في أي خطوة.
                <?php endif; ?>
            </p>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
