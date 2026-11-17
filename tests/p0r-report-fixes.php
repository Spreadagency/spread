<?php
/**
 * Spread AI — المرحلة 0 من تقرير اختبار تجربة العميل:
 *   اقتراحات الملاحظات (post_chips GET) · اعتماد الهوية بيفتح الحملة + سبب المرحلة المقفولة · العملاء المثاليين
 *   · «حملة جديدة» مابتعملش مسودات فاضية كتير · ✓ المراحل = خلصت فعلًا وعدد مراحل واحد · رقم استهلاك واحد
 *   · السايدبار من غير تكرار ولا تناقض · محدش يشوف محتوى عميل تاني بتغيير الرقم.
 * للتطوير المحلي بس — بيعمل حسابات مؤقتة ويمسحها، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/p0r-report-fixes.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/onboarding.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/brand-brain.php';
require_once __DIR__ . '/../includes/brand-kit.php';
require_once __DIR__ . '/../includes/lifecycle.php';
require_once __DIR__ . '/../includes/campaign-flow.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, $post = [], ?string $jar = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => is_string($post) ? $post : http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function loc(string $h): string { return preg_match('/^Location:\s*(\S+)/mi', $h, $m) ? $m[1] : ''; }
function csrf_of(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : ''); }

$tag = bin2hex(random_bytes(3));
$keys = ['brand_gate_pct', 'onb_questions_on', 'ui_v2_mode', 'campaigns_enabled'];
$saved = [];
foreach ($keys as $k) $saved[$k] = db_one('SELECT setting_value FROM settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1', [$k])['setting_value'] ?? null;
$swap = function (string $k, ?string $v) { db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); if ($v !== null) set_setting($k, $v); };
$swap('brand_gate_pct', '90');   // حد عالي علشان العميل الجديد يبقى تحته
$swap('ui_v2_mode', 'all');
$swap('campaigns_enabled', '1');
db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct", "bk_personas")');

$uids = [];
$mk = function (string $email) use (&$uids): int {
    $id = (int) db_insert('INSERT INTO users (name, email, phone, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?,?, "active", NOW(), "approved", NOW())',
        ['P0 ' . $email, $email, '012' . random_int(10000000, 99999999), password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name, industry) VALUES (?,?,?)', [$id, 'عيادة P0', 'عيادة أسنان']);
    credits_add($id, 50, 'اختبار P0', null, 'test', null, 30);
    $uids[] = $id;
    return $id;
};
$login = function (string $email) use ($base): string {
    $j = tempnam(sys_get_temp_dir(), 'p0');
    [, $lp] = http('GET', "$base/public/login.php", [], $j);
    http('POST', "$base/public/login.php", ['csrf' => csrf_of($lp), 'email' => $email, 'password' => 'Test12345!'], $j);
    return $j;
};
$csrfFor = function (string $jar) use ($base): string { [, $h] = http('GET', "$base/public/dashboard.php?onb=skip", [], $jar); return csrf_of($h); };

try {
    $a = $mk("p0a-$tag@test.local");
    $b = $mk("p0b-$tag@test.local");
    $ja = $login("p0a-$tag@test.local");
    $jb = $login("p0b-$tag@test.local");
    $csA = $csrfFor($ja);
    $bidA = (int) db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$a])['id'];
    $bidB = (int) db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$b])['id'];
    $coA = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status) VALUES (?,?,?,?,?,?)', [$a, $bidA, 'introductory', 'both', "منشور A $tag", 'generated']);
    $coB = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status) VALUES (?,?,?,?,?,?)', [$b, $bidB, 'introductory', 'both', "منشور سري B $tag", 'generated']);

    echo "1) اقتراحات الملاحظات في صفحة المنشور\n";
    [$c, $r] = http('GET', "$base/public/api/brand-kit.php?action=post_chips&content_id=$coA", [], $ja);
    $j = json_decode($r, true);
    t('post_chips بـ GET ← 200 (كانت 405)', $c === 200 && !empty($j['ok']) && isset($j['options']), "$c " . substr($r, 0, 120));
    [$c] = http('GET', "$base/public/api/brand-kit.php?action=service_delete&id=1", [], $ja);
    t('العمليات اللي بتغيّر بيانات لسه POST بس', $c === 405, (string) $c);

    echo "2) الهوية والحملة\n";
    $h = brand_health(brand_for_user($a));
    t('عميل جديد هويته تحت الحد ← الحملة مقفولة', !$h['unlocked'] && $h['gate'] === 90, json_encode($h['pct']));
    db_run('UPDATE brand_profiles SET brand_approved_at = NOW() WHERE user_id = ?', [$a]);
    $h = brand_health(brand_for_user($a));
    t('اعتمد هويته (من «ابدأ») ← الحملة مفتوحة حتى لو النسبة أقل', $h['unlocked'] && $h['approved'] && $h['pct'] < 90, json_encode([$h['pct'], $h['unlocked']]));
    $cB = (int) db_insert('INSERT INTO campaigns (user_id, title, ideas_count, period_month, basis) VALUES (?,?,?,?,?)', [$b, "حملة B $tag", 7, date('Y-m'), 'services']);
    [$c, $page] = http('GET', "$base/public/campaign.php?id=$cB", [], $jb);
    t('صفحة الحملة لعميل هويته ناقصة: كارت «كمّل واعتمد هويتك» بيظهر من أول مرحلة', $c === 200 && str_contains($page, 'كمّل واعتمد هويتك') && str_contains($page, 'v-if="!health.unlocked"'), (string) $c);
    t('أزرار المراحل المقفولة بتقول السبب (مش disabled ساكت)', str_contains($page, 'lockReason(n)') && !str_contains($page, ':disabled="!canGo(n)"'));
    t('التصميم محتاج اشتراك ← كارت فيه «شوف الباقات» (مش وقفة ساكتة)', str_contains($page, 'v-if="paywall"'));
    $init = campaign_board_payload(db_one('SELECT * FROM campaigns WHERE id = ?', [$cB]), $b);
    t('لينك إكمال الهوية بيودّي على رحلة «ابدأ»', str_starts_with((string) ($init['health']['url'] ?? ''), 'start.php'), json_encode($init['health'] ?? null));

    echo "3) العملاء المثاليين\n";
    $cases = [
        'object' => '{"personas":[{"name":"أ"},{"name":"ب"}]}', 'array' => '[{"name":"أ"},{"name":"ب"}]',
        'cut' => '{"personas":[{"name":"أ","pains":["x"]},{"name":"ب"},{"name":"ج","pa', 'other key' => '{"customers":[{"name":"أ"}]}',
    ];
    foreach ($cases as $k => $raw) t("الرد ($k) بيتقرا", count(bk_personas_parse($raw)) >= 1, json_encode(bk_personas_parse($raw), JSON_UNESCAPED_UNICODE));
    t('رد مقطوع ← بياخد الكاملين بس (2)', count(bk_personas_parse($cases['cut'])) === 2);
    [$c, $r] = http('POST', "$base/public/api/brand-kit.php?action=personas_generate", json_encode(['n' => 3]), $ja, ['X-CSRF-Token: ' . $csA, 'Content-Type: application/json']);
    $j = json_decode($r, true);
    t('التوليد بيرجع 3 عملاء ويتحفظوا', $c === 200 && ($j['saved'] ?? 0) === 3 && count($j['state']['personas'] ?? []) === 3, "$c " . substr($r, 0, 200));

    echo "4) «حملة جديدة»\n";
    db_run('INSERT INTO campaigns (user_id, title, ideas_count, period_month, created_at) VALUES (?,?,?,?, NOW() - INTERVAL 5 DAY)', [$a, 'حملة قديمة فاضية', 7, date('Y-m', strtotime('-1 month'))]);
    $blank = (int) db_one('SELECT id FROM campaigns WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$a])['id'];
    [, , $hd] = http('GET', "$base/public/campaign-new.php", [], $ja);
    t('فتح «حملة جديدة» ← بيكمّل على المسودة الفاضية القديمة (مهما كان عمرها)', str_contains(loc($hd), 'campaign.php?id=' . $blank), loc($hd));
    http('GET', "$base/public/campaign-new.php", [], $ja);
    http('GET', "$base/public/campaign-new.php", [], $ja);
    t('فتحها 3 مرات ← مفيش حملات جديدة', (int) db_one('SELECT COUNT(*) n FROM campaigns WHERE user_id = ?', [$a])['n'] === 1);
    $months = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    t('واسمها وشهرها اتحدّثوا للشهر الحالي', db_one('SELECT title, period_month FROM campaigns WHERE id = ?', [$blank]) == ['title' => 'حملة ' . $months[(int) date('n')], 'period_month' => date('Y-m')]);
    db_run('UPDATE campaigns SET basis = "services" WHERE id = ?', [$blank]);   // اتلمست
    [, , $hd] = http('GET', "$base/public/campaign-new.php", [], $ja);
    $new = (int) (preg_match('/id=(\d+)/', loc($hd), $m) ? $m[1] : 0);
    t('بعد ما اتلمست ← حملة جديدة باسم مش مكرر', $new && $new !== $blank && db_one('SELECT title FROM campaigns WHERE id = ?', [$new])['title'] === 'حملة ' . $months[(int) date('n')] . ' 2', (string) (db_one('SELECT title FROM campaigns WHERE id = ?', [$new])['title'] ?? ''));
    [, $r] = http('GET', "$base/public/api/campaigns.php?action=list", [], $ja);
    $ids = array_column(json_decode($r, true)['campaigns'] ?? [], 'id');
    t('«حملاتي» مابتعرضش المسودة الفاضية', in_array($blank, $ids, true) && !in_array($new, $ids, true), json_encode($ids));

    echo "5) مراحل الحملة\n";
    $cS = (int) db_insert('INSERT INTO campaigns (user_id, title, ideas_count, period_month, basis, stage, max_stage) VALUES (?,?,?,?,?,6,6)', [$a, "ستارت $tag", 3, date('Y-m'), 'services']);
    foreach ([[$coA, 1], [null, 1], [null, 0]] as $k => [$co, $sel]) {
        db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, title, selected, content_id) VALUES (?,?,?,?,?)', [$cS, $a, "فكرة $k", $sel, $co]);
    }
    $api = campaign_to_api(db_one('SELECT * FROM campaigns WHERE id = ?', [$cS]));
    $done = array_column($api['steps']['steps'], 'done', 'n');
    t('حملة وصلت لـ«النشر» بس محتواها ناقص ← مفيش ✓ على المحتوى ولا التصميم ولا النشر', $done[1] && !$done[2] && !$done[4] && !$done[6], json_encode($done));
    t('النسبة = المراحل اللي خلصت فعلًا', $api['progress'] === (int) round(1 / $api['steps']['total'] * 100), (string) $api['progress']);
    t('عدد المراحل واحد (5 لما التقييم جوه المحتوى)', $api['steps']['total'] === (campaign_write_mode() === 'single' ? 5 : 6) && count($api['steps']['steps']) === $api['steps']['total']);
    t('«المرحلة الجاية» = أول مرحلة ماخلصتش', $api['steps']['next'] === 'المحتوى', (string) $api['steps']['next']);
    db_run('UPDATE campaigns SET status = "completed" WHERE id = ?', [$cS]);
    t('حملة مكتملة ← كله ✓ و100%', campaign_to_api(db_one('SELECT * FROM campaigns WHERE id = ?', [$cS]))['progress'] === 100);

    echo "6) رقم استهلاك واحد\n";
    plan_start($a, null, 100, 30, 'manual', null, 'اختبار P0', ['quotas_json' => json_encode(['designs' => 12])]);
    db_insert('INSERT INTO content_designs (content_id, user_id, image_path, prompt, model, credits_used) VALUES (?,?,?,?,?,1)', [$coA, $a, 'uploads/designs/p0.png', 'x', 'mock']);
    plan_usage($a, true);   // الكاش بتاع العملية دي اتحسب قبل التصميم
    $hl = usage_headline($a);
    t('الجملة الواحدة: «فاضلك 11 تصميم لحد …»', str_contains($hl['text'], 'فاضلك 11 تصميم لحد') && $hl['designs']['used'] === 1, $hl['text']);
    [, $dash] = http('GET', "$base/public/dashboard.php?onb=skip", [], $ja);
    t('الهيدر بيقول نفس الرقم', str_contains($dash, 'فاضل 11 تصميم'));
    [, $st] = http('GET', "$base/public/api/studio.php?action=home", [], $ja);
    $sj = json_decode($st, true);
    t('Design Studio: نفس عدد التصميمات ونفس الجملة', ($sj['month'] ?? -1) === 1 && ($sj['headline'] ?? '') === $hl['text'], json_encode(['month' => $sj['month'] ?? null, 'headline' => $sj['headline'] ?? null], JSON_UNESCAPED_UNICODE));
    [, $an] = http('GET', "$base/public/analytics.php", [], $ja);
    t('التحليلات: نفس الجملة', str_contains($an, e($hl['text'])), preg_match('/استخدام الباقة.{0,300}/su', $an, $mm) ? strip_tags($mm[0]) : substr(strip_tags($an), 0, 200));

    echo "7) السايدبار\n";
    t('هويته معتمدة ← «كمّل هويتك» وكارت Brand Brain المكرر مش ظاهرين', !str_contains($dash, 'كمّل هويتك') && !str_contains($dash, '<b>Brand Brain</b>'),
        json_encode(['finish' => str_contains($dash, 'كمّل هويتك'), 'bb' => str_contains($dash, '<b>Brand Brain</b>'), 'card' => str_contains($dash, 'sb-brand v2-brand')]));
    [, $dashB] = http('GET', "$base/public/dashboard.php?onb=skip", [], $jb);
    t('هوية ناقصة ← كارت «هوية البراند» بيودّي على الرحلة', str_contains($dashB, 'sb-brand v2-brand') && str_contains($dashB, 'كمّل هويتك'));

    echo "8) محدش يشوف محتوى عميل تاني\n";
    [$c, $body, $hd] = http('GET', "$base/public/content-view.php?id=$coB", [], $ja);
    t('صفحة المنشور برقم عميل تاني ← ممنوع', $c === 302 && !str_contains($body, "منشور سري B $tag"), (string) $c);
    [$c, $r] = http('GET', "$base/public/api/contents.php?action=get&id=$coB", [], $ja);
    t('API المحتوى برقم عميل تاني ← 404', $c === 404 && !str_contains($r, "منشور سري B $tag"), (string) $c);
    [$c, $r] = http('GET', "$base/public/api/brand-kit.php?action=post_chips&content_id=$coB", [], $ja);
    t('اقتراحات الملاحظات لمنشور عميل تاني ← 404', $c === 404, (string) $c);
    [$c, , $hd] = http('GET', "$base/public/campaign.php?id=$cB", [], $ja);
    t('حملة عميل تاني ← ممنوع', $c === 302 || $c === 404, (string) $c);
    [$c, $r] = http('GET', "$base/public/api/campaign-flow.php?action=board&id=$cB", [], $ja);
    t('بيانات حملة عميل تاني ← 404', $c === 404, (string) $c);
    [$c, $r] = http('POST', "$base/public/ajax/add-note.php", ['csrf' => $csA, 'content_id' => $coB, 'note' => 'x'], $ja);
    t('ملاحظة على منشور عميل تاني ← مرفوض', !(json_decode($r, true)['ok'] ?? false) && !db_one('SELECT id FROM content_notes WHERE content_id = ?', [$coB]), substr($r, 0, 100));
    @unlink($ja); @unlink($jb);
} finally {
    foreach ($uids as $u) {
        try { db_run('DELETE FROM campaign_ideas WHERE user_id = ?', [$u]); } catch (\Throwable $e) {}
        try { db_run('DELETE n FROM content_notes n JOIN contents c ON c.id = n.content_id WHERE c.user_id = ?', [$u]); } catch (\Throwable $e) {}
        foreach (['campaigns', 'content_designs', 'contents', 'brand_personas', 'brand_services', 'user_plans', 'brand_kits', 'brand_profiles', 'credit_lots', 'credit_transactions', 'credit_wallets', 'user_sessions', 'remember_tokens'] as $tb) {
            try { db_run("DELETE FROM $tb WHERE user_id = ?", [$u]); } catch (\Throwable $e) {}
        }
        db_run('DELETE FROM users WHERE id = ?', [$u]);
    }
    foreach ($saved as $k => $v) $swap($k, $v);
}
echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
