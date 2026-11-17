<?php
/**
 * Spread AI — رحلة الهوية + «عرض البداية»: مرة واحدة لكل حساب ورقم موبايل · مش للمشترك · العلامة المائية والأصل
 * · رجوع التصميمات نضيفة بعد الاشتراك · بعد انتهاء التجربة · إعدادات الأدمن · «رحلتك الأولى» القديمة اتلغت.
 * للتطوير المحلي بس — بيعمل حسابات مؤقتة ويمسحها، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/ij2-trial.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/onboarding.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/trial.php';
require_once __DIR__ . '/../includes/watermark.php';
require_once __DIR__ . '/../includes/admin-ui.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array $post = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function loc(string $h): string { return preg_match('/^Location:\s*(\S+)/mi', $h, $m) ? $m[1] : ''; }
function csrf_of(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : ''); }

$tag = bin2hex(random_bytes(3));
$keys = ['trial_on', 'trial_days', 'trial_contents', 'trial_designs', 'onb_questions_on', 'watermark_enabled', 'quotas_enforced'];
$saved = [];
foreach ($keys as $k) $saved[$k] = db_one('SELECT setting_value FROM settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1', [$k])['setting_value'] ?? null;
$swap = function (string $k, ?string $v) { db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); if ($v !== null) set_setting($k, $v); };
foreach (['trial_on', 'trial_days', 'trial_contents', 'trial_designs', 'onb_questions_on'] as $k) $swap($k, null);   // الافتراضي
$swap('watermark_enabled', '0');   // العلامة مقفولة للمنصة — التجربة لازم تتعلّم برضه
db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct")');

$uids = []; $files = []; $pkgId = null;
$phone = '011' . random_int(10000000, 99999999);
$mk = function (string $email, string $phone) use (&$uids): int {
    $id = (int) db_insert('INSERT INTO users (name, email, phone, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?,?, "active", NOW(), "approved", NOW())',
        ['Trial ' . $email, $email, $phone, password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?,?)', [$id, 'Trial biz']);
    $uids[] = $id;
    return $id;
};

try {
    echo "الإعدادات الافتراضية\n";
    $cfg = trial_cfg();
    t('14 يوم · 10 محتوى · 4 تصميمات', $cfg === ['days' => 14, 'contents' => 10, 'designs' => 4], json_encode($cfg));
    t('الكريدت = 10 × الكتابة + 4 × التصميم', trial_credits() === 10 * cost_for('content_generation_cost') + 4 * cost_for('content_design_cost'));

    echo "التفعيل\n";
    $a = $mk("tr-a-$tag@test.local", $phone);
    t('حساب جديد يستاهل العرض', trial_eligible($a) && trial_status($a)['state'] === 'none');
    $bal0 = credits_balance($a);
    t('اتفعّل', trial_grant($a));
    $st = trial_status($a);
    t('الحالة: شغال 14 يوم · 0 من 10 محتوى · 0 من 4 تصميم', $st['state'] === 'active' && $st['days_left'] === 14 && $st['contents'] === ['used' => 0, 'total' => 10] && $st['designs']['total'] === 4, json_encode($st));
    t('الرصيد زاد بكريدت العرض', credits_balance($a) === $bal0 + trial_credits());
    t('دورة «عرض البداية» = مشترك (التصميم مسموح)', plan_subscribed($a) && plan_active($a, true)['source'] === 'trial' && plan_active($a)['name'] === 'عرض البداية');
    t('مرة تانية لنفس الحساب ← لأ', !trial_grant($a) && (int) db_one('SELECT COUNT(*) n FROM user_plans WHERE user_id = ? AND source = "trial"', [$a])['n'] === 1);
    $b = $mk("tr-b-$tag@test.local", '+2' . $phone);
    t('حساب تاني بنفس الموبايل (بصيغة تانية) ← لأ', !trial_eligible($b) && !trial_grant($b));
    $c = $mk("tr-c-$tag@test.local", '012' . random_int(10000000, 99999999));
    plan_start($c, null, 50, 30, 'paid', null, 'باقة تجريبية');
    t('عنده باقة مدفوعة ← لأ', !trial_eligible($c));
    $swap('trial_on', '0');
    $d = $mk("tr-d-$tag@test.local", '015' . random_int(10000000, 99999999));
    t('العرض مقفول من الأدمن ← لأ', !trial_eligible($d));
    $swap('trial_on', null);

    echo "العلامة المائية\n";
    t('العلامة لازم تتحط على تصميم التجربة (حتى لو مقفولة للمنصة)', watermark_for_user($a) && wm_trial_mode());
    $logo = wm_trial_logo();
    t('لوجو العلامة موجود (لوجو العلامة · المنصة · أو نص)', $logo !== '' && is_file(STORAGE_PATH . '/' . $logo), $logo);
    @mkdir(STORAGE_PATH . '/uploads/designs', 0755, true);
    $rel = 'uploads/designs/trial-test-' . $tag . '.png';
    $im = imagecreatetruecolor(600, 600);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
    imagepng($im, STORAGE_PATH . '/' . $rel);
    imagedestroy($im);
    $files[] = $rel;
    $before = md5_file(STORAGE_PATH . '/' . $rel);
    $r = watermark_apply($rel);
    $orig = STORAGE_PATH . '/uploads/designs/originals/' . basename($rel);
    $files[] = 'uploads/designs/originals/' . basename($rel);
    t('العلامة اتحطت والأصل اتحفظ', !empty($r['ok']) && md5_file(STORAGE_PATH . '/' . $rel) !== $before && is_file($orig) && md5_file($orig) === $before, json_encode($r, JSON_UNESCAPED_UNICODE));
    $bid = (int) db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$a])['id'];
    $co = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status) VALUES (?,?,?,?,?,?)', [$a, $bid, 'introductory', 'both', 'منشور تجربة', 'generated']);
    db_insert('INSERT INTO content_designs (content_id, user_id, image_path, prompt, model, credits_used) VALUES (?,?,?,?,?,1)', [$co, $a, $rel, 'test', 'mock']);
    t('التصميم والمنشور اتحسبوا من التجربة', trial_status($a)['designs']['used'] === 1 && trial_status($a)['contents']['used'] === 1, json_encode(trial_status($a)));
    wm_trial_mode(false);
    t('عميل مشترك (من غير تجربة) والعلامة مقفولة ← مفيش علامة', !watermark_for_user($c));

    echo "بعد الاشتراك\n";
    package_flags_ensure();
    $pkgId = (int) db_insert('INSERT INTO credit_packages (name, credits, price_egp, validity_days, is_active, watermark) VALUES (?,?,?,?,0,0)', ["اختبار $tag", 100, 1, 30]);
    plan_start($a, $pkgId, 100, 30, 'paid', null, 'اشتراك');
    t('اشترك في باقة من غير علامة ← التصميم رجع نضيف', md5_file(STORAGE_PATH . '/' . $rel) === $before && !is_file($orig));
    t('الحالة: اشترك بعد التجربة', trial_status($a)['state'] === 'subscribed');

    echo "التجربة خلصت من غير اشتراك\n";
    $e = $mk("tr-e-$tag@test.local", '010' . random_int(10000000, 99999999));
    trial_grant($e);
    db_run('UPDATE user_plans SET ends_at = DATE_SUB(NOW(), INTERVAL 1 DAY), starts_at = DATE_SUB(NOW(), INTERVAL 15 DAY) WHERE user_id = ? AND source = "trial"', [$e]);
    t('الحالة: خلصت', trial_status($e)['state'] === 'ended' && !trial_active_plan($e));
    t('مش مشترك بعدها (مفيش تصميم)', !plan_subscribed($e));

    echo "الصفحات\n";
    $j = tempnam(sys_get_temp_dir(), 'ij');
    [, $lp] = http('GET', "$base/public/login.php", [], $j);
    [, , $h] = http('POST', "$base/public/login.php", ['csrf' => csrf_of($lp), 'email' => "tr-e-$tag@test.local", 'password' => 'Test12345!'], $j);
    t('عميل حالي لسه ماعداش الرحلة ← الدخول بيودّيه «ابدأ» على طول', str_contains(loc($h), 'start.php'), loc($h));
    [$code, $dp] = http('GET', "$base/public/dashboard.php", [], $j);
    t('مرة واحدة في الجلسة — بعدها الرئيسية', $code === 200);
    t('الرئيسية: «التجربة خلصت» ومحتواه متاح', str_contains($dp, 'data-trial="ended"'));
    [, , $h] = http('GET', "$base/public/journey.php", [], $j);
    t('«رحلتك الأولى» القديمة ← رحلة الهوية', str_contains(loc($h), 'start.php'), loc($h));
    [$code, $sp] = http('GET', "$base/public/start.php", [], $j);
    t('صفحة «ابدأ» بالـ 16 شاشة', $code === 200 && substr_count($sp, 'data-step') >= 16 && str_contains($sp, 'id="s-offer"'));
    // عميل عنده لوجو قبل كده (كان بيدّي 500: upload_url مش متحمّلة)
    db_run('UPDATE brand_profiles SET logo_path = ? WHERE user_id = ?', ['uploads/brand/logo-test.png', $e]);
    [$code, $sp] = http('GET', "$base/public/start.php", [], $j);
    t('«ابدأ» لعميل عنده لوجو ← بتفتح (مش 500) واللوجو ظاهر', $code === 200 && str_contains($sp, 'logo-test.png'), (string) $code);
    $cs = csrf_of($sp);
    [, $fr] = http('POST', "$base/public/start.php", ['csrf' => $cs, 'act' => 'fields', 'f' => ['address' => "شارع $tag", 'phones' => '0100', 'password' => 'x']], $j);
    $bp = db_one('SELECT address, phones FROM brand_profiles WHERE user_id = ?', [$e]);
    t('حفظ التواصل (والحقول الغريبة بتتجاهل)', (json_decode($fr, true)['ok'] ?? false) && $bp['address'] === "شارع $tag" && $bp['phones'] === '0100', $fr);
    [, $fr] = http('POST', "$base/public/start.php", ['csrf' => $cs, 'act' => 'finish'], $j);
    $fj = json_decode($fr, true) ?: [];
    t('اعتماد الهوية ← «خلصت» ومن غير تجربة تانية', !empty($fj['ok']) && empty($fj['trial_granted']) && ij_done($e) && ($fj['trial']['state'] ?? '') === 'ended', $fr);
    [, , $h] = http('GET', "$base/public/start.php", [], $j);
    t('بعد الاعتماد «ابدأ» ← الرئيسية', str_contains(loc($h), 'dashboard.php'), loc($h));
    @unlink($j);

    echo "أول دخول\n";
    $f = $mk("tr-f-$tag@test.local", '010' . random_int(10000000, 99999999));
    $jf = tempnam(sys_get_temp_dir(), 'ij');
    [, $lp] = http('GET', "$base/public/login.php", [], $jf);
    [, , $h] = http('POST', "$base/public/login.php", ['csrf' => csrf_of($lp), 'email' => "tr-f-$tag@test.local", 'password' => 'Test12345!'], $jf);
    t('الدخول ← رحلة الهوية على طول (من غير ما يعدّي على الرئيسية)', str_contains(loc($h), 'start.php'), loc($h));
    [, , $h] = http('GET', "$base/public/dashboard.php", [], $jf);
    t('ولو فتح الرئيسية بعدها ← مابترجعهوش (مرة في الجلسة)', loc($h) === '');
    db_run('UPDATE users SET phone = phone WHERE id = ?', [$f]);
    @unlink($jf);

    echo "الأدمن\n";
    $aj = tempnam(sys_get_temp_dir(), 'ia');
    [, $alp] = http('GET', "$base/admin/login.php", [], $aj);
    http('POST', "$base/admin/login.php", ['csrf' => csrf_of($alp), 'email' => 'owner@app.test', 'password' => 'Test12345!'], $aj);
    [$code, $ap] = http('GET', "$base/admin/onboarding-questions.php", [], $aj);
    t('قسم «عرض البداية» في صفحة أسئلة الهوية', $code === 200 && str_contains($ap, 'id="trial"') && str_contains($ap, 'name="trial_days"'));
    http('POST', "$base/admin/onboarding-questions.php", ['csrf' => csrf_of($ap), 'act' => 'trial', 'trial_on' => '1', 'trial_days' => '21', 'trial_contents' => '6', 'trial_designs' => '999'], $aj);
    t('الحفظ: 21 يوم · 6 محتوى · التصميمات بحد أقصى 50', trial_cfg() === ['days' => 21, 'contents' => 6, 'designs' => 50], json_encode(trial_cfg()));
    $all = [];
    foreach (admin_nav() as $items) foreach ($items as $it) $all[$it[4]] = 1;
    t('قايمة الأدمن مازالت 55 صفحة (العرض جوه صفحة أسئلة الهوية)', count($all) === 55, (string) count($all));
    @unlink($aj);
} finally {
    foreach ($uids as $u) {
        foreach (['content_designs', 'contents', 'user_plans', 'brand_kits', 'brand_profiles', 'credit_lots', 'credit_transactions', 'credit_wallets', 'user_sessions', 'remember_tokens'] as $tb) {
            try { db_run("DELETE FROM $tb WHERE user_id = ?", [$u]); } catch (\Throwable $e) {}
        }
        db_run('DELETE FROM users WHERE id = ?', [$u]);
    }
    if ($pkgId) db_run('DELETE FROM credit_packages WHERE id = ?', [$pkgId]);
    foreach ($files as $f) @unlink(STORAGE_PATH . '/' . $f);
    foreach ($saved as $k => $v) $swap($k, $v);
}
echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
