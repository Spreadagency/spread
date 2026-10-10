<?php
/**
 * Spread AI v2 — فحص التحديث: التعديلات الجديدة اترفعت واشتغلت؟
 *
 * بيقارن كل ملف على السيرفر ببصمته في الحزمة (includes/build-manifest.php)،
 * وبيفحص الترحيلات والإعدادات والكاش — والإصلاح بضغطة.
 * مكتوب علشان يشتغل حتى لو ملفات ناقصة (كله بـ is_file / function_exists).
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
if (is_file(__DIR__ . '/../includes/ai-gateway.php')) require_once __DIR__ . '/../includes/ai-gateway.php';   // حالة البوابة (البحث العميق)

require_admin();
if (function_exists('admin_can') && !admin_can('site_settings')) {
    http_response_code(403);
    exit('للمدير العام بس');
}

$ROOT = realpath(__DIR__ . '/..');
$msg = null;
@include_once $ROOT . '/includes/version.php';
$manifest = is_file($ROOT . '/includes/build-manifest.php') ? (include $ROOT . '/includes/build-manifest.php') : null;
if (!is_array($manifest) || empty($manifest['files'])) $manifest = null;
$hasMig = is_file($ROOT . '/includes/migrations.php');
if ($hasMig) require_once $ROOT . '/includes/migrations.php';

/* ═══════════ الإصلاحات بضغطة ═══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'mode_all' || $a === 'mode_optin') {
        $v = $a === 'mode_all' ? 'all' : 'optin';
        set_setting('ui_v2_mode', $v);
        $msg = ['success', $v === 'all' ? 'الشكل الجديد بقى للكل ✓ — افتح المنصة بحساب عميل' : 'رجع تجريبي ✓'];
    }
    if ($a === 'migrate' && $hasMig) {
        @set_time_limit(180);
        $r = migrations_run_pending('ui-diagnose');
        unset($_SESSION['a2_mig_pending']);
        $msg = $r['ok']
            ? ['success', $r['ran'] ? 'اتنفّذ: ' . implode(' · ', $r['ran']) : 'مفيش حاجة معلّقة']
            : ['danger', 'وقف عند خطأ: ' . $r['error']];
        if (function_exists('admin_log')) admin_log('migrations_run', 'system', null, json_encode($r['ran'] ?? [], JSON_UNESCAPED_UNICODE));
    }
    if ($a === 'run_one' && $hasMig && !empty($_POST['file'])) {
        @set_time_limit(180);
        $r = migration_run_file((string) $_POST['file'], 'ui-diagnose');
        unset($_SESSION['a2_mig_pending']);
        $msg = $r['ok'] ? ['success', 'اتنفّذ تاني: ' . basename((string) $_POST['file']) . ' ✓'] : ['danger', 'وقف عند خطأ: ' . $r['error']];
        if (function_exists('admin_log')) admin_log('migration_rerun', 'system', null, basename((string) $_POST['file']));
    }
    if ($a === 'opcache' && function_exists('opcache_reset')) {
        $ok = @opcache_reset();
        $msg = [$ok ? 'success' : 'warning', $ok ? 'اتمسح كاش PHP ✓ — حدّث الصفحة' : 'الاستضافة مش سامحة بمسح الكاش من هنا — اعمل Restart لـ PHP من cPanel'];
    }
    if ($a === 'user_v2' && !empty($_POST['email'])) {
        try {
            $n = db()->prepare('UPDATE users SET ui_pref = "v2" WHERE email = ?');
            $n->execute([trim((string) $_POST['email'])]);
            $msg = $n->rowCount() ? ['success', 'اتفعّل الشكل الجديد للحساب ده ✓'] : ['warning', 'مفيش حساب بالإيميل ده'];
        } catch (\Throwable $e) {
            $msg = ['danger', 'عمود ui_pref مش موجود — شغّل الترحيلات الأول'];
        }
    }
}

/* ═══════════ ① الملفات مقابل بصمة الحزمة ═══════════
 * بنفرّق بين نوعين:
 *   - ملف من التحديث الأخير مختلف/ناقص  → التحديث نفسه ماكملش (أحمر)
 *   - ملف من مرحلة قديمة مختلف          → حزمة قديمة مااترفعتش، أو الملف اتعدّل على السيرفر (أصفر)
 */
$fMissing = $fOld = [];
$fOk = 0;
$inUpd = array_flip((array) ($manifest['update'] ?? []));
if ($manifest) {
    foreach ($manifest['files'] as $rel => $meta) {
        $sum = is_array($meta) ? (string) ($meta['h'] ?? '') : (string) $meta;
        $psize = is_array($meta) ? (int) ($meta['s'] ?? 0) : 0;
        $p = $ROOT . '/' . $rel;
        $row = ['f' => $rel, 'upd' => isset($inUpd[$rel]), 'psize' => $psize, 'size' => 0, 'at' => null];
        if (!is_file($p)) { $fMissing[] = $row; continue; }
        $body = str_replace("\r\n", "\n", (string) @file_get_contents($p));
        if (md5($body) === $sum) { $fOk++; continue; }
        $row['size'] = strlen($body);
        $row['at'] = @filemtime($p) ?: null;
        $fOld[] = $row;
    }
}
$fTotal = $manifest ? count($manifest['files']) : 0;
$updBad = array_merge(array_filter($fMissing, fn($r) => $r['upd']), array_filter($fOld, fn($r) => $r['upd']));
$oldBad = array_merge(array_filter($fMissing, fn($r) => !$r['upd']), array_filter($fOld, fn($r) => !$r['upd']));

// اتفكت في مجلد غلط؟ (أشهر غلطة: الـ zip اتفك جوه مجلد باسمه أو جوه public/)
$stray = [];
foreach (['includes/plans.php', 'includes/ai-gateway.php', 'includes/version.php', 'includes/brand-brain.php', 'admin/ai-providers.php'] as $probe) {
    foreach (array_merge(glob($ROOT . '/*/' . $probe) ?: [], glob($ROOT . '/*/*/' . $probe) ?: []) as $p) {
        $stray[] = explode('/', ltrim(substr($p, strlen($ROOT)), '/'))[0] . '/';
    }
}
$stray = array_values(array_unique($stray));

/* ═══════════ ② قاعدة البيانات ═══════════ */
$tbl = function (string $t): bool {
    try { return (bool) db_one('SELECT 1 x FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]); } catch (\Throwable $e) { return false; }
};
$migs = [];
if ($hasMig) {
    try { $migs = migrations_status(); } catch (\Throwable $e) { $migs = []; }
}
$pending = array_values(array_filter($migs, fn($m) => $m['state'] === 'pending'));
$missingSql = array_values(array_filter($migs, fn($m) => $m['state'] === 'missing'));

/* ═══════════ ③ حالة المراحل الأخيرة (اللي العميل بيشوفها) ═══════════ */
$gs = fn($k, $d = '') => (string) (function_exists('get_setting') ? get_setting($k, $d) : $d);
$plansReady = function_exists('plans_ready') && plans_ready();
$mode = $gs('ui_v2_mode', '');
$feat = [];
$feat[] = ['8-أ', 'بوابة الـ AI والبدائل', $tbl('ai_runs') ? ($gs('ai_gateway_enabled', '0') === '1' ? true : null) : false,
    $tbl('ai_runs') ? ($gs('ai_gateway_enabled', '0') === '1' ? 'شغالة' : 'متركّبة بس مقفولة — شغّلها من «الموديلات والـ Router»') : 'جداولها مش موجودة — شغّل ترحيل 8-أ'];
$feat[] = ['8-ب', 'الحصص الشهرية', $plansReady ? ($gs('quotas_enforced', '1') === '1' ? true : null) : false,
    $plansReady ? ($gs('quotas_enforced', '1') === '1' ? 'شغالة — الخدمة بتقف لما الحصة تخلص' : 'للعرض بس (مش بتوقف الخدمة)') : 'مش شغالة لحد ما ترحيل 8-ب يتشغّل — العميل بيشوف الشكل القديم (أرقام كريدت)'];
$feat[] = ['8-ب', 'العميل بيشوف الاستهلاك بالنسبة %', $plansReady ? ($gs('credits_display', 'percent') !== 'visible' ? true : null) : false,
    $plansReady ? ($gs('credits_display', 'percent') !== 'visible' ? 'النسبة % بس — من غير أرقام كريدت' : 'أرقام الكريدت ظاهرة (اتغيرت من «الباقات والأسعار»)') : 'مستني ترحيل 8-ب'];
if ($plansReady) {
    $ap = 0; $pq = 0;
    try { $ap = (int) (db_one('SELECT COUNT(*) n FROM user_plans WHERE status = "active" AND ends_at > NOW()')['n'] ?? 0); } catch (\Throwable $e) {}
    try { $pq = (int) (db_one('SELECT COUNT(*) n FROM credit_packages WHERE is_active = 1 AND quotas_json IS NOT NULL AND quotas_json <> ""')['n'] ?? 0); } catch (\Throwable $e) {}
    $feat[] = ['8-ب', 'عملاء عندهم دورة باقة', $ap > 0 ? true : null, $ap . ' عميل — الباقي حصصهم مفتوحة لحد ما يشتروا أو تفعّل لهم باقة من ملفهم', ''];
    $feat[] = ['8-ب', 'باقات ليها حصص', $pq > 0 ? true : null, $pq . ' باقة مفعّلة'];
}
$feat[] = ['8-ج', 'مركز الإعدادات وسجل التغييرات', $tbl('settings_audit') ? true : false, $tbl('settings_audit') ? 'شغال' : 'مستني ترحيل 8-ج'];
$feat[] = ['9', 'مشاريع الاستوديو اللي لسه مكملتش · تصميمات بتعجبك', $tbl('studio_drafts') && $tbl('brand_inspirations') ? true : null,
    $tbl('studio_drafts') && $tbl('brand_inspirations') ? 'شغال' : 'بيتعمل تلقائيًا أول استخدام — أو شغّل ترحيل 9'];
$feat[] = ['9', 'البحث العميق على الـ Smart Router', function_exists('ai_gw_enabled') && ai_gw_enabled() ? true : null,
    function_exists('ai_gw_enabled') && ai_gw_enabled() ? 'شغال' : 'البحث متوقف لحد ما البوابة تشتغل من «الموديلات والـ Router» (المسار القديم اتشال من البحث)'];
$payReady = $tbl('payment_requests') && $tbl('payment_methods');
$pmActive = 0;
if ($payReady) { try { $pmActive = (int) (db_one('SELECT COUNT(*) n FROM payment_methods WHERE is_active = 1')['n'] ?? 0); } catch (\Throwable $e) {} }
$feat[] = ['10', 'الباقات والدفع اليدوي (إنستاباي · فودافون كاش) + مراجعة الأدمن', $payReady ? ($pmActive ? true : null) : false,
    !$payReady ? 'مستني ترحيل 10 (أو بيتعمل تلقائيًا أول فتح لصفحة الباقات)' : ($pmActive ? $pmActive . ' طريقة دفع مفعّلة' : 'مفيش طريقة دفع مفعّلة — ضيفها من «المدفوعات ← طرق الدفع»')];
$feat[] = ['10', 'أكواد الخصم على الباقات (Promo)', $tbl('promo_usages') ? true : false, $tbl('promo_usages') ? 'شغال' : 'مستني ترحيل 10'];
$feat[] = ['10', '«افتكرني» · رقم الموبايل لحسابات جوجل', $tbl('user_remember_tokens') ? true : false,
    $tbl('user_remember_tokens') ? 'شغال · مدة ' . (int) get_setting('remember_days', 30) . ' يوم' : 'مستني ترحيل 10'];
$optedIn = 0;
try { $optedIn = (int) (db_one('SELECT COUNT(*) n FROM users WHERE ui_pref = "v2"')['n'] ?? 0); } catch (\Throwable $e) {}
$modeLbl = ['off' => '🔒 مقفولة', 'optin' => '🧪 تجريبية', 'all' => '🚀 للكل'][$mode] ?? 'مش متحددة';
$feat[] = ['واجهة', 'الواجهة الجديدة للعملاء: ' . $modeLbl, $mode === 'all' ? true : null,
    $mode === 'optin' ? "بتظهر بس للي فعّلها من ملفه ({$optedIn} حساب)" : ($mode === 'all' ? 'ظاهرة لكل العملاء' : 'مقفولة للكل')];

/* ═══════════ ④ الكاش والتوقيت ═══════════ */
$opc = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$opcOn = is_array($opc) && !empty($opc['opcache_enabled']);
$validate = (int) ini_get('opcache.validate_timestamps');
$freq = (int) ini_get('opcache.revalidate_freq');
$tzRow = db_one('SELECT NOW() n');
$drift = (int) round((strtotime((string) $tzRow['n']) - time()) / 60);

/* ═══════════ الخلاصة ═══════════ */
$problems = [];   // بتوقف التحديث
$notes = [];      // محتاجة مراجعة بس مش بتوقف حاجة
if (!$manifest) $problems[] = 'ملف بصمة الحزمة (includes/build-manifest.php) مش موجود — يعني الحزمة الأخيرة لسه مااترفعتش';
if ($updBad) $problems[] = count($updBad) . ' ملف من التحديث ده مش مترفّع صح';
if ($stray) $problems[] = 'فيه ملفات اتفكت في مجلد فرعي: ' . implode(' · ', $stray);
if ($pending) $problems[] = count($pending) . ' ترحيل مستني يتشغّل';
if ($opcOn && !$validate) $problems[] = 'كاش PHP مابيتجددش لوحده';
if (abs($drift) > 1) $problems[] = 'توقيت قاعدة البيانات مختلف ' . $drift . ' دقيقة';
if ($oldBad) $notes[] = count($oldBad) . ' ملف من مراحل قديمة مختلف عن الحزمة — مش جزء من التحديث ده';
$serverBuild = defined('SPREAD_BUILD') ? SPREAD_BUILD : 'قديمة (قبل 8-ج)';

$active = 'ui-diagnose';
$page_title = 'فحص التحديث';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <div class="page-head">
            <div>
                <h1>فحص التحديث</h1>
                <div class="sub">Update Check · الملفات اترفعت صح؟ الترحيلات اتشغّلت؟ الكاش؟ — والإصلاح بضغطة</div>
            </div>
        </div>
        <?php if ($msg): ?><div class="alert <?= e($msg[0]) ?>"><?= e($msg[1]) ?></div><?php endif; ?>
        <?= render_flash() ?>

        <div class="card dg-sum <?= $problems ? 'bad' : ($notes ? 'warn' : 'ok') ?>">
            <div>
                <b><?= $problems ? '🔴 التحديث مش مكتمل' : ($notes ? '🟡 التحديث تمام — وفيه ملفات قديمة محتاجة نظرة' : '✅ التحديث مترفّع كامل وشغّال') ?></b>
                <div class="a2-muted" style="margin-top:4px">النسخة على السيرفر: <b><?= e($serverBuild) ?></b><?= $manifest ? ' · بصمة الحزمة: <b>' . e($manifest['build']) . '</b> (' . e($manifest['generated'] ?? '') . ')' : '' ?> · مسار المنصة: <code dir="ltr"><?= e($ROOT) ?></code></div>
                <?php if ($problems): ?><ul class="dg-list"><?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php if ($notes): ?><ul class="dg-list warn"><?php foreach ($notes as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul><?php endif; ?>
            </div>
            <div class="a2-row" style="gap:8px">
                <?php if ($pending || ($hasMig && !$tbl('schema_migrations'))): ?>
                    <form method="POST" style="margin:0" onsubmit="return confirm('خدت باك أب لقاعدة البيانات؟ هيتشغّل <?= count($pending) ?> ترحيل')"><?= csrf_field() ?><input type="hidden" name="action" value="migrate"><button class="btn">▶ شغّل الترحيلات (<?= count($pending) ?: '؟' ?>)</button></form>
                <?php endif; ?>
                <?php if ($opcOn && function_exists('opcache_reset')): ?>
                    <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="opcache"><button class="btn ghost">🧹 امسح كاش PHP</button></form>
                <?php endif; ?>
                <a class="btn ghost" href="<?= url('admin/ui-diagnose.php') ?>">↻ افحص تاني</a>
            </div>
        </div>

        <div class="a2-grid a2-g2" style="margin-top:16px">
            <div class="card" style="padding:0">
                <div class="a2-h" style="padding:16px 18px 0"><h3>① الملفات</h3>
                    <?php if ($manifest): ?><span class="chip <?= $updBad ? 'chip-coral' : (($fMissing || $fOld) ? 'chip-amber' : 'chip-mint') ?>"><?= $fOk ?> / <?= $fTotal ?> مطابق</span><?php endif; ?></div>
                <div style="padding:6px 18px 16px">
                    <?php if (!$manifest): ?>
                        <div class="a2-note">مفيش بصمة للحزمة على السيرفر، فمش هقدر أقارن الملفات. ارفع الحزمة الأخيرة (فيها <code>includes/build-manifest.php</code>) في <b>المجلد الرئيسي للمنصة</b> وافتح الصفحة دي تاني.</div>
                    <?php elseif (!$fMissing && !$fOld): ?>
                        <div class="a2-muted">كل ملفات الحزمة موجودة بالنسخة الجديدة ✓</div>
                    <?php else:
                        $pkgAt = !empty($manifest['generated']) ? strtotime((string) $manifest['generated']) : 0;
                        $rows = function (array $list) use ($pkgAt) {
                            echo '<div class="dg-rows">';
                            foreach (array_slice($list, 0, 60) as $r) {
                                $miss = $r['size'] === 0 && $r['at'] === null;
                                $why = $miss ? 'مش موجود' : ($r['at'] && $pkgAt && $r['at'] > $pkgAt ? 'اتعدّل على السيرفر' : 'نسخة أقدم');
                                echo '<div class="dg-row"><code dir="ltr">' . e($r['f']) . '</code><span>'
                                    . ($miss ? '<b class="bad">ناقص</b>' : '<b>' . $why . '</b> · على السيرفر ' . a2n($r['size']) . ' بايت مقابل ' . a2n($r['psize']) . ' في الحزمة'
                                        . ($r['at'] ? ' · آخر تعديل ' . e(date('Y-m-d H:i', $r['at'])) : ''))
                                    . '</span></div>';
                            }
                            if (count($list) > 60) echo '<div class="a2-muted">+' . (count($list) - 60) . ' كمان</div>';
                            echo '</div>';
                        };
                    ?>
                        <?php if ($updBad): ?>
                            <div style="margin-top:8px"><b style="color:#C2362E">❌ ملفات التحديث ده (<?= count($updBad) ?>)</b> <span class="a2-muted">— دي اللي بتوقف التحديث</span></div>
                            <?php $rows($updBad); ?>
                            <div class="a2-note" style="margin-top:10px"><b>الحل:</b> ارفع حزمة التحديث تاني وفكّها في <code dir="ltr"><?= e($ROOT) ?></code> نفسه (مش جوه مجلد جديد)، ووافق على «استبدال الملفات».</div>
                        <?php endif; ?>
                        <?php if ($oldBad): ?>
                            <div style="margin-top:14px"><b style="color:#8A5A10">⚠️ ملفات من مراحل قديمة (<?= count($oldBad) ?>)</b> <span class="a2-muted">— مش جزء من التحديث ده، فالتحديث نفسه تمام</span></div>
                            <?php $rows($oldBad); ?>
                            <div class="a2-note" style="margin-top:10px">
                                <b>يعني إيه؟</b> الملفات دي ماتغيّرتش في التحديث ده، فمش موجودة في حزمته — ونسختها على السيرفر مختلفة عن الأصل. سببين بس:
                                <br>① <b>«نسخة أقدم»</b> = فيه حزمة قديمة مااترفعتش. اطلب حزمة إصلاح بالملفات دي وارفعها.
                                <br>② <b>«اتعدّل على السيرفر»</b> = الملف اتظبط بإيدك. سيبه زي ما هو لو التعديل مقصود.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($stray): ?><div class="a2-note" style="margin-top:10px;color:#C2362E">لقيت ملفات المنصة جوه مجلد فرعي: <b dir="ltr"><?= e(implode(' · ', $stray)) ?></b> — الـ zip اتفك في المكان الغلط. انقل محتواه للمجلد الرئيسي.</div><?php endif; ?>
                </div>
            </div>

            <div class="card" style="padding:0">
                <div class="a2-h" style="padding:16px 18px 0"><h3>② الترحيلات</h3>
                    <span class="chip <?= $pending ? 'chip-amber' : 'chip-mint' ?>"><?= $pending ? count($pending) . ' مستني' : 'كله اتشغّل' ?></span></div>
                <?php if (!$hasMig): ?><div class="a2-empty">ملف الترحيلات مش موجود</div><?php else: ?>
                <div class="a2-tw" style="border:0"><table class="a2-tbl"><tbody>
                    <?php foreach (array_reverse($migs) as $m): if (!in_array($m['state'], ['pending', 'missing', 'changed'], true) && !str_contains($m['file'], 'phase8')) continue; ?>
                        <tr>
                            <td><?= ['applied' => '✅', 'pending' => '⏳', 'missing' => '❌', 'changed' => '⚠️'][$m['state']] ?? '•' ?></td>
                            <td><b dir="ltr" style="font-size:12.5px"><?= e($m['file']) ?></b><div class="a2-muted" style="font-size:12px"><?= e($m['label']) ?></div></td>
                            <td class="a2-muted" style="white-space:nowrap"><?= $m['state'] === 'applied' ? e(substr((string) $m['applied_at'], 0, 16)) : ['pending' => 'مستني', 'missing' => 'الملف ناقص', 'changed' => 'الملف اتغير بعد التشغيل'][$m['state']] ?>
                                <?php if ($m['state'] === 'changed'): ?>
                                    <form method="post" style="margin:4px 0 0" onsubmit="return confirm('تشغّل الترحيل ده تاني؟ الترحيلات آمنة تتشغّل أكتر من مرة.')"><?= csrf_field() ?><input type="hidden" name="action" value="run_one"><input type="hidden" name="file" value="<?= e($m['file']) ?>"><button class="btn sm ghost">شغّله تاني</button></form>
                                <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <div class="a2-muted" style="padding:0 18px 14px;font-size:12px">بيظهر ترحيلات المرحلة 8 + أي ترحيل مستني. القائمة الكاملة في <a href="<?= url('admin/migrations.php') ?>">الترحيلات</a>.
                    <?php if (array_filter($migs, fn($m) => $m['state'] === 'changed')): ?><br>⚠️ «اتغير بعد التشغيل» = الملف اتحدّث في حزمة أحدث بعد ما اشتغل عندك. دوس «شغّله تاني» علشان الإضافات الجديدة تتطبّق — الترحيلات كلها آمنة تتشغّل أكتر من مرة.<?php endif; ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="padding:0;margin-top:16px">
            <div class="a2-h" style="padding:16px 18px 0"><h3>③ اللي العميل المفروض يشوفه</h3></div>
            <div class="a2-tw" style="border:0"><table class="a2-tbl"><tbody>
                <?php foreach ($feat as $f): ?>
                    <tr>
                        <td style="width:34px"><?= $f[2] === true ? '✅' : ($f[2] === null ? '🟡' : '❌') ?></td>
                        <td style="width:70px"><span class="chip chip-line"><?= e($f[0]) ?></span></td>
                        <td><b><?= e($f[1]) ?></b><div class="a2-muted" style="font-size:12.5px"><?= e($f[3]) ?></div></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><?= !$opcOn || $validate ? '✅' : '🟡' ?></td><td><span class="chip chip-line">سيرفر</span></td>
                    <td><b>كاش PHP (OPcache)</b><div class="a2-muted" style="font-size:12.5px"><?= !$opcOn ? 'مقفول — الملفات الجديدة بتشتغل فورًا' : ($validate ? "شغّال وبيتجدد كل {$freq} ثانية" : 'شغّال ومابيتجددش لوحده — امسحه بعد كل رفع') ?></div></td>
                </tr>
                <tr>
                    <td><?= abs($drift) <= 1 ? '✅' : '❌' ?></td><td><span class="chip chip-line">سيرفر</span></td>
                    <td><b>توقيت قاعدة البيانات = توقيت المنصة</b><div class="a2-muted" style="font-size:12.5px">المنصة <?= date('H:i') ?> (<?= e(date_default_timezone_get()) ?>) · قاعدة البيانات <?= e(date('H:i', strtotime((string) $tzRow['n']))) ?> · PHP <?= e(PHP_VERSION) ?></div></td>
                </tr>
            </tbody></table></div>
            <div style="padding:12px 18px 16px" class="a2-row">
                <?php if ($mode !== 'all'): ?>
                    <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="mode_all"><button class="btn soft">🚀 اعرض الواجهة الجديدة للكل</button></form>
                <?php endif; ?>
                <form method="POST" style="margin:0;display:flex;gap:8px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="user_v2">
                    <input class="input" type="email" name="email" placeholder="إيميل حساب تجربه عليه" style="max-width:260px" dir="ltr" required>
                    <button class="btn ghost">✨ فعّل الواجهة الجديدة للحساب ده</button></form>
            </div>
        </div>

        <div class="a2-note" style="margin-top:16px">
            <b>ترتيب الرفع الصح:</b> ① باك أب ② فك الحزمة في المجلد الرئيسي واستبدل الملفات ③ افتح الصفحة دي ← «شغّل الترحيلات» ④ «امسح كاش PHP» لو ظاهر ⑤ ادخل بحساب عميل وجرّب.
            التعديلات بتاعة العميل (النسبة % والحصص) مش هتظهر غير بعد الترحيل.
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
