<?php
/**
 * Spread AI — مفاتيح التشفير (Content Engine · المرحلة B)
 * المفتاح اللي بيشفّر مفاتيح الـ AI وتوكنز فيسبوك والأسرار — حالته وتغييره من غير ما حاجة تقف:
 *   1) المفتاح الجديد في ENCRYPTION_KEY والقديم في ENCRYPTION_KEY_PREV (config.php)
 *   2) «إعادة تشفير» هنا ← كل القيم بتتشفّر بالجديد
 *   3) شيل ENCRYPTION_KEY_PREV
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/crypto.php';

require_admin();
require_admin_can('ai_settings');

/** أماكن القيم المشفّرة: [label, select sql, update sql] — الصفوف: id, v */
function sk_targets(): array
{
    return [
        ['مفاتيح مزودين الـ AI', 'SELECT id, api_key_encrypted v FROM ai_providers WHERE api_key_encrypted IS NOT NULL AND api_key_encrypted <> ""', 'UPDATE ai_providers SET api_key_encrypted = ? WHERE id = ?'],
        ['توكنز صفحات العملاء (فيسبوك/إنستجرام)', 'SELECT id, access_token_enc v FROM social_connections WHERE access_token_enc IS NOT NULL AND access_token_enc <> ""', 'UPDATE social_connections SET access_token_enc = ? WHERE id = ?'],
        ['أسرار الإعدادات (AI · جوجل · ميتا)', 'SELECT id, setting_value v FROM settings WHERE setting_key IN ("ai_api_key_enc","google_client_secret_enc","meta_app_secret_enc") AND setting_value <> ""', 'UPDATE settings SET setting_value = ? WHERE id = ?'],
    ];
}

function sk_scan(): array
{
    $out = [];
    foreach (sk_targets() as [$label, $sel]) {
        $c = ['current' => 0, 'prev' => 0, 'none' => 0];
        try {
            foreach (db_all($sel) as $r) $c[Crypto::which((string) $r['v'])]++;
        } catch (\Throwable $e) {
            $c['error'] = $e->getMessage();
        }
        $out[] = [$label, $c];
    }
    return $out;
}

$keyOk = true;
try { Crypto::encrypt('test'); } catch (\Throwable $e) { $keyOk = false; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reencrypt') {
    require_csrf();
    if (!$keyOk) { flash_set('danger', 'ENCRYPTION_KEY مش مظبوط في config.php'); redirect('admin/security-keys.php'); }
    $done = 0; $failed = 0;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        foreach (sk_targets() as [$label, $sel, $upd]) {
            foreach (db_all($sel) as $r) {
                $w = Crypto::which((string) $r['v']);
                if ($w === 'current') continue;
                if ($w === 'none') { $failed++; continue; }
                db_run($upd, [Crypto::encrypt(Crypto::decrypt((string) $r['v'])), (int) $r['id']]);
                $done++;
            }
        }
        $pdo->commit();
        admin_log('encryption_reencrypt', 'settings', null, json_encode(['done' => $done, 'failed' => $failed]));
        flash_set($failed ? 'warning' : 'success', "اتعاد تشفير $done قيمة بالمفتاح الجديد ✓" . ($failed ? " · $failed قيمة مش بتتفك بأي مفتاح (لازم تتكتب تاني من صفحتها)" : ''));
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[security-keys] ' . $e->getMessage());
        flash_set('danger', 'إعادة التشفير اتلغت ومحصلش أي تغيير: ' . $e->getMessage());
    }
    redirect('admin/security-keys.php');
}

$scan = $keyOk ? sk_scan() : [];
$public = $keyOk && Crypto::usingPublicKey();
$hasPrev = $keyOk && Crypto::hasPrevKey();
$needPrev = array_sum(array_map(fn($x) => $x[1]['prev'] ?? 0, $scan));
$broken = array_sum(array_map(fn($x) => $x[1]['none'] ?? 0, $scan));
$suggest = bin2hex(random_bytes(32));

$active = 'security-keys';
$page_title = 'مفاتيح التشفير';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head">
            <h1>مفاتيح التشفير 🔐</h1>
            <div class="sub">المفتاح اللي بيحمي مفاتيح الـ AI وتوكنز صفحات العملاء والأسرار في قاعدة البيانات</div>
        </div>

        <div class="card" style="margin-bottom:14px;border:2px solid <?= !$keyOk || $public ? '#d9534f' : ($hasPrev || $needPrev ? '#e0a21a' : '#2a9d76') ?>">
            <?php if (!$keyOk): ?>
                <b>🔴 ENCRYPTION_KEY مش مظبوط في includes/config.php</b>
            <?php elseif ($public): ?>
                <b>🔴 الموقع شغال بمفتاح كان منشور في ملف config.sample.php</b>
                <p style="line-height:1.9;margin:6px 0 0">أي حد معاه نسخة من قاعدة البيانات يقدر يفك مفاتيح الـ AI وتوكنز صفحات العملاء. غيّره بالخطوات تحت.</p>
            <?php elseif ($hasPrev || $needPrev): ?>
                <b>🟠 في نص تغيير المفتاح</b>
                <p style="line-height:1.9;margin:6px 0 0"><?= $needPrev ? "فيه $needPrev قيمة لسه بالمفتاح القديم — دوس «إعادة التشفير» تحت." : 'كل القيم بقت بالمفتاح الجديد — شيل سطر ENCRYPTION_KEY_PREV من config.php.' ?></p>
            <?php else: ?>
                <b>✅ المفتاح خاص بالموقع ده، وكل القيم متشفّرة بيه</b>
            <?php endif; ?>
        </div>

        <?php if ($scan): ?>
        <div class="card" style="margin-bottom:14px">
            <div class="a2-h"><h3>القيم المشفّرة</h3></div>
            <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المكان</th><th>بالمفتاح الحالي</th><th>بالمفتاح القديم</th><th>مش بتتفك</th></tr></thead><tbody>
                <?php foreach ($scan as [$label, $c]): ?>
                    <tr><td><?= e($label) ?></td><td class="num"><?= (int) $c['current'] ?></td><td class="num"><?= (int) $c['prev'] ?></td>
                        <td class="num"><?= (int) $c['none'] ? '<b style="color:#d9534f">' . (int) $c['none'] . '</b>' : '0' ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php if ($broken): ?><p class="a2-muted" style="font-size:12.5px;margin:8px 0 0">«مش بتتفك» = اتشفّرت بمفتاح مش موجود دلوقتي. لازم تتكتب تاني من صفحتها (مزودين الـ AI · الإعدادات · العميل يعيد ربط صفحته).</p><?php endif; ?>
            <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="reencrypt">
                <button class="btn" <?= $needPrev ? '' : 'disabled' ?> data-confirm="كل القيم اللي بالمفتاح القديم هتتشفّر بالجديد — متأكد إن المفتاح الجديد محفوظ في config.php؟">إعادة التشفير بالمفتاح الحالي</button></form>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="a2-h"><h3>تغيير المفتاح خطوة بخطوة</h3></div>
            <ol style="line-height:2.1;padding-inline-start:20px;margin:0">
                <li>خد نسخة احتياطية من قاعدة البيانات.</li>
                <li>مفتاح جديد اتولّد لك دلوقتي (بيتغيّر كل مرة تفتح الصفحة، ومابيتحفظش في أي حتة):
                    <div style="margin:6px 0"><code dir="ltr" style="font-size:13px;user-select:all;word-break:break-all"><?= e($suggest) ?></code></div></li>
                <li>افتح <code>includes/config.php</code> من cPanel ← File Manager:
                    <ul style="padding-inline-start:18px;margin:4px 0">
                        <li>غيّر اسم السطر الحالي من <code dir="ltr">ENCRYPTION_KEY</code> لـ <code dir="ltr">ENCRYPTION_KEY_PREV</code> (سيب قيمته زي ما هي).</li>
                        <li>ضيف سطر جديد: <code dir="ltr">define('ENCRYPTION_KEY', 'المفتاح الجديد');</code> واحفظ.</li>
                    </ul></li>
                <li>ارجع هنا ودوس «إعادة التشفير» — المفروض عمود «بالمفتاح القديم» يبقى صفر.</li>
                <li>امسح سطر <code dir="ltr">ENCRYPTION_KEY_PREV</code> من config.php.</li>
                <li>لو المفتاح القديم كان المنشور: الأحوط تعمل مفاتيح جديدة عند مزودين الـ AI (وتحطها من «مزودين الـ AI») و App Secret جديد لتطبيق ميتا.</li>
            </ol>
            <p class="a2-muted" style="font-size:12.5px;margin:10px 0 0">طول ما ENCRYPTION_KEY_PREV موجود، أي قيمة قديمة بتتفك بيه تلقائي — فمفيش حاجة بتقف في النص.</p>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
