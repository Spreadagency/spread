<?php
/**
 * Spread AI — خطة الحملات · المرحلة 4: الحملة وحدة في الباقة
 * للتطوير المحلي بس — بيعمل باقة وعميل مؤقتين وحملات تجريبية ويمسحهم، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/cc4-package.php http://localhost:8080
 *
 * محتاج: tests/dev-setup.php اتشغّل (فيه الترحيل) · الموقع شغال · الـ AI الوهمي شغال.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/packages.php';
require_once __DIR__ . '/../includes/campaign-flow.php';
require_once __DIR__ . '/../includes/campaign-allowance.php';
if (!ca_ready()) exit("شغّل ترحيل 2026_campaign_package الأول (php tests/dev-setup.php)\n");

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => is_array($post) ? http_build_query($post) : $post]);
    $b = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b];
}

db_run('DELETE FROM rate_limits');
$keys = ['campaign_write_mode', 'campaign_skills_enabled', 'brand_gate_pct', 'campaign_pkg_enabled', 'campaign_max_drafts', 'campaign_draft_regens',
         'content_regeneration_cost', 'content_design_cost', 'designs_charge_credits', 'quotas_enforced'];
$saved = [];
foreach ($keys as $k) $saved[$k] = get_setting($k, null);
set_setting('campaign_skills_enabled', '0');
set_setting('brand_gate_pct', '0');
set_setting('campaign_write_mode', 'single');
set_setting('campaign_max_drafts', '2');
set_setting('campaign_draft_regens', '2');
set_setting('content_regeneration_cost', '3');
set_setting('content_design_cost', '4');
db_run('DELETE FROM settings WHERE setting_key = "campaign_pkg_enabled"');

/* ─── 1) الدوال ─── */
echo "الدوال\n";
t('الترحيل: الحقول الجديدة موجودة', ca_ready());
t('كل الباقات الموجودة 0 حملات افتراضيًا (مفيش حاجة اتغيّرت)', (int) db_one('SELECT COUNT(*) n FROM credit_packages WHERE campaigns_month > 0 AND name NOT LIKE "CC4 %"')['n'] === 0);
[$n1] = ca_window(date('Y-m-d H:i:s', time() - 5 * 86400));
[$n3, $next3] = ca_window(date('Y-m-d H:i:s', time() - 65 * 86400));
t('الشهر تراكمي كل 30 يوم من بداية الاشتراك (اليوم 5 = 1 · اليوم 65 = 3)', $n1 === 1 && $n3 === 3 && strtotime($next3) > time());
t('الدورة الوهمية (من غير اشتراك) ← بالكريدت', ca_limits_from_plan(['synthetic' => true, 'id' => null, 'campaigns_month' => 2]) === null);

/* ─── باقة وعميل مؤقتين ─── */
$tag = bin2hex(random_bytes(3));
$pkgId = (int) db_insert('INSERT INTO credit_packages (name, credits, price_egp, is_active, campaigns_month, posts_per_campaign, videos_per_campaign, free_edits_per_campaign, carousel_max_slides)
                          VALUES (?, 300, 0, 0, 2, 2, 1, 2, 3)', ["CC4 $tag"]);
$mkUser = function (string $sfx) use ($tag): int {
    $u = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, created_at) VALUES (?,?,?, "active", NOW(), "approved", NOW(), NOW())',
        ["CC4 $sfx", "cc4-$sfx-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, description, audience, tone, dialect, created_at) VALUES (?,?,?,?,?,?,?, NOW())',
        [$u, 'عيادة cc4', 'عيادة أسنان', 'عيادة أسنان تجريبية', 'عائلات', 'ودود', 'egyptian']);
    return $u;
};
$uid = $mkUser('pkg');
$planId = (int) plan_start($uid, $pkgId, 300, 30, 'admin', null, 'cc4 test');
credits_add($uid, 200, 'cc4 test', null, 'admin_add', null);
$pl = plan_active($uid, true);
t('الاشتراك بياخد لقطة من حدود الباقة', (int) $pl['campaigns_month'] === 2 && (int) $pl['posts_per_campaign'] === 2 && (int) $pl['free_edits_per_campaign'] === 2 && (int) $pl['carousel_max_slides'] === 3);
$st = ca_status($uid);
t('حالة الحملات: 2 متاحين في الشهر الأول', $st && $st['allow'] === 2 && $st['used'] === 0 && $st['left'] === 2);
db_run('UPDATE credit_packages SET posts_per_campaign = 9 WHERE id = ?', [$pkgId]);
t('تغيير الباقة بعد الاشتراك مابيأثرش على المشترك', (int) plan_active($uid, true)['posts_per_campaign'] === 2);

// عميل على باقة 0 حملات (زي دلوقتي)
$uid0 = $mkUser('zero');
$pkg0 = db_one('SELECT * FROM credit_packages WHERE is_active = 1 AND campaigns_month = 0 ORDER BY credits DESC LIMIT 1');
if ($pkg0) plan_start($uid0, (int) $pkg0['id'], (int) $pkg0['credits'], 30, 'admin', null, 'cc4 test');
credits_add($uid0, 200, 'cc4 test', null, 'admin_add', null);
t('باقة 0 حملات ← ca_status = null (بالكريدت زي دلوقتي)', ca_status($uid0) === null);

$login = function (int $u) use ($base): array {
    $email = db_one('SELECT email FROM users WHERE id = ?', [$u])['email'];
    $jar = tempnam(sys_get_temp_dir(), 'cc4');
    [, $html] = http('GET', "$base/public/login.php", [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => $email, 'password' => 'Test12345!'], $jar);
    [, $dash] = http('GET', "$base/public/dashboard.php", [], $jar);
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $dash, $cm);
    return [$jar, $cm[1] ?? ''];
};
[$jar, $csrf] = $login($uid);
[$jar0, $csrf0] = $login($uid0);
t('دخول العملاء المؤقتين', $csrf !== '' && $csrf0 !== '');
$api = function (array $d, ?array $who = null) use ($base, &$jar, &$csrf): array {
    [$j, $cs] = $who ?? [$jar, $csrf];
    [$c, $b] = http('POST', "$base/public/api/campaign-flow.php", json_encode($d, JSON_UNESCAPED_UNICODE), $j, ['Content-Type: application/json', 'X-CSRF-Token: ' . $cs]);
    return [$c, json_decode($b, true) ?: ['raw' => mb_substr($b, 0, 300)]];
};
$camp = function (int $u, string $title): int {
    return (int) db_insert('INSERT INTO campaigns (user_id, title, basis, topic, status, created_at) VALUES (?,?,?,?, "active", NOW())', [$u, $title, 'awareness', 'كشف الأسنان']);
};
$idea = function (int $cid, int $u, string $title, string $fmt = 'post', ?int $slides = null): int {
    return (int) db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, angle, title, description, audience, hook, formats, selected, format, slides_count) VALUES (?,?,?,?,?,?,?,?,1,?,?)',
        [$cid, $u, 'توعية', $title, 'تفاصيل', 'عائلات', 'بتأجل الكشف؟', 'منشور', $fmt, $slides]);
};
$C = fn(int $id) => db_one('SELECT * FROM campaigns WHERE id = ?', [$id]);
$events = fn(int $u, string $unit) => (int) db_one('SELECT COUNT(*) n FROM usage_events WHERE user_id = ? AND unit = ?', [$u, $unit])['n'];

/* ─── 2) زي دلوقتي: باقة 0 حملات ─── */
echo "باقة من غير حملات (زي دلوقتي)\n";
$z = $camp($uid0, 'حملة صفر');
$zi = $idea($z, $uid0, 'فكرة صفر');
$bal = credits_balance($uid0);
[$c, $j] = $api(['action' => 'content_generate', 'id' => $z, 'idea_id' => $zi], [$jar0, $csrf0]);
t('الكتابة شغالة بالكريدت زي الأول', $c === 200 && $bal - credits_balance($uid0) === cost_for_format('post') && $C($z)['pkg_state'] === null, $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));

/* ─── 3) المسودة ─── */
echo "المسودة\n";
$A = $camp($uid, 'حملة A');
$bal = credits_balance($uid);
[$c, $j] = $api(['action' => 'ideas_generate', 'id' => $A, 'total' => 3, 'batch' => 0]);
$cA = $C($A);
t('أول توليد للخطة ← الحملة بقت مسودة في الباقة ومن غير خصم', $c === 200 && $cA['pkg_state'] === 'draft' && (int) $cA['user_plan_id'] === $planId && credits_balance($uid) === $bal, $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
t('المسودة مابتتحسبش من الحملات', $events($uid, 'campaigns') === 0 && (int) $cA['posts_target'] === 2 && (int) $cA['videos_target'] === 1);
$api(['action' => 'ideas_generate', 'id' => $A, 'total' => 3, 'batch' => 0]);
$api(['action' => 'ideas_generate', 'id' => $A, 'total' => 3, 'batch' => 0]);
t('إعادتين للخطة ببلاش', credits_balance($uid) === $bal && (int) $C($A)['draft_plans'] === 3 && (int) $C($A)['draft_regens'] === 2);
[$c] = $api(['action' => 'ideas_generate', 'id' => $A, 'total' => 3, 'batch' => 0]);
t('الإعادة التالتة بسعر الأفكار العادي', $c === 200 && $bal - credits_balance($uid) === idea_credits_for(3), (string) ($bal - credits_balance($uid)));

$B = $camp($uid, 'حملة B');
$api(['action' => 'ideas_generate', 'id' => $B, 'total' => 3, 'batch' => 0]);
$D = $camp($uid, 'حملة D');
[$c, $j] = $api(['action' => 'ideas_generate', 'id' => $D, 'total' => 3, 'batch' => 0]);
t('أقصى حملتين مسودة في نفس الوقت', $c === 409 && ($j['code'] ?? '') === 'draft_limit', $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));

$ia1 = $idea($A, $uid, 'منشور A1');
[$c, $j] = $api(['action' => 'content_generate', 'id' => $A, 'idea_id' => $ia1]);
t('الكتابة قبل «اعتمد الخطة» ← ممنوعة (not_approved)', $c === 402 && ($j['code'] ?? '') === 'not_approved' && !$C($A)['posts_used']);

/* ─── 4) «اعتمد الخطة» ─── */
echo "«اعتمد الخطة»\n";
[$c, $j] = $api(['action' => 'plan_approve', 'id' => $A]);
t('«اعتمد الخطة» = الحملة اتحسبت', $c === 200 && $C($A)['pkg_state'] === 'approved' && $C($A)['counted_at'] !== null && $events($uid, 'campaigns') === 1, json_encode($j, JSON_UNESCAPED_UNICODE));
[$c, $j] = $api(['action' => 'plan_approve', 'id' => $A]);
t('ضغطة تانية ← مابتتعدّش مرتين', $c === 200 && !empty($j['already']) && $events($uid, 'campaigns') === 1);
// موافقتين في نفس اللحظة (العميل والوكيل) على B
$mh = curl_multi_init(); $hs = [];
foreach ([1, 2] as $_) {
    $h = curl_init("$base/public/api/campaign-flow.php");
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
        CURLOPT_POSTFIELDS => json_encode(['action' => 'plan_approve', 'id' => $B])]);
    curl_multi_add_handle($mh, $h); $hs[] = $h;
}
do { curl_multi_exec($mh, $run); curl_multi_select($mh); } while ($run);
foreach ($hs as $h) curl_multi_remove_handle($mh, $h);
t('موافقتين في نفس اللحظة ← اتحسبت مرة واحدة', $events($uid, 'campaigns') === 2 && $C($B)['pkg_state'] === 'approved');
db_run('DELETE FROM rate_limits');
[$c, $j] = $api(['action' => 'ideas_generate', 'id' => $D, 'total' => 3, 'batch' => 0]);
t('بعد الاعتماد المسودات قلّت ← حملة جديدة تبدأ', $c === 200 && $C($D)['pkg_state'] === 'draft');
$api(['action' => 'idea_toggle', 'id' => $D, 'idea_id' => $idea($D, $uid, 'فكرة D'), 'selected' => 1]);
[$c, $j] = $api(['action' => 'plan_approve', 'id' => $D]);
t('الحملة التالتة في نفس الشهر ← خلّصت حملات الفترة', $c === 409 && ($j['code'] ?? '') === 'quota' && $C($D)['pkg_state'] === 'draft', json_encode($j, JSON_UNESCAPED_UNICODE));

/* ─── 5) الإلغاء ─── */
echo "الإلغاء\n";
[$c, $j] = $api(['action' => 'plan_cancel', 'id' => $B]);
t('إلغاء حملة معتمدة قبل أي كتابة ← الحملة بترجع', $c === 200 && !empty($j['released']) && $events($uid, 'campaigns') === 1 && $C($B)['pkg_state'] === 'cancelled');
[$c] = $api(['action' => 'plan_approve', 'id' => $D]);
t('ودلوقتي الحملة التالتة تتعتمد', $c === 200 && $C($D)['pkg_state'] === 'approved' && $events($uid, 'campaigns') === 2);

/* ─── 6) جوه الحملة ─── */
echo "المنشورات والفيديو والتعديلات\n";
$bal = credits_balance($uid);
[$c, $j] = $api(['action' => 'content_generate', 'id' => $A, 'idea_id' => $ia1]);
t('أول منشور ← من حد الحملة ومن غير كريدت', $c === 200 && (int) $C($A)['posts_used'] === 1 && credits_balance($uid) === $bal, $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
t('الضغطة المزدوجة على نفس الفكرة مابتعدّش تاني', ca_write_allow($C($A), $ia1, 'post', null)['mode'] === 'counted' && (int) $C($A)['posts_used'] === 1);
$ia2 = $idea($A, $uid, 'كاروسيل A2', 'carousel', 5);
[$c, $j] = $api(['action' => 'content_generate', 'id' => $A, 'idea_id' => $ia2]);
$co2 = db_one('SELECT * FROM contents WHERE id = (SELECT content_id FROM campaign_ideas WHERE id = ?)', [$ia2]);
t('تاني منشور (كاروسيل) ← الشرايح لحد carousel_max_slides (3)', $c === 200 && (int) $C($A)['posts_used'] === 2 && (int) ($co2['slides_count'] ?? 0) === 3, json_encode([$c, $co2['slides_count'] ?? null]));
$ia3 = $idea($A, $uid, 'منشور A3');
[$c, $j] = $api(['action' => 'content_generate', 'id' => $A, 'idea_id' => $ia3]);
t('التالت ← وصلت لحد المنشورات في الحملة', $c === 402 && ($j['code'] ?? '') === 'campaign_limit' && (int) $C($A)['posts_used'] === 2 && !db_one('SELECT content_id FROM campaign_ideas WHERE id = ?', [$ia3])['content_id']);
$iv = $idea($A, $uid, 'فيديو A4', 'video');
[$c, $j] = $api(['action' => 'content_generate', 'id' => $A, 'idea_id' => $iv]);
t('سكريبت الفيديو ← من حد الفيديو', $c === 200 && (int) $C($A)['videos_used'] === 1 && credits_balance($uid) === $bal, $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
[$c, $j] = $api(['action' => 'content_generate', 'id' => $A, 'idea_id' => $ia1, 'regen' => 1]);
t('إعادة الكتابة ← من رصيد التعديلات (1 من 2)', $c === 200 && (int) $C($A)['edits_used'] === 1 && credits_balance($uid) === $bal);

/* ─── 7) التصميم ─── */
echo "التصميم\n";
$dPage = http('GET', "$base/public/content-view.php?id=" . (int) db_one('SELECT content_id FROM campaign_ideas WHERE id = ?', [$ia1])['content_id'], [], $jar);
preg_match('/<meta name="csrf-token" content="([^"]+)"/', $dPage[1], $dm);
$design = function (int $cid, array $extra = []) use ($base, $jar, $dm): array {
    [$c, $b] = http('POST', "$base/public/ajax/generate-design.php", ['csrf' => $dm[1] ?? '', 'content_id' => $cid, 'include_logo' => '0'] + $extra, $jar);
    return [$c, json_decode($b, true) ?: ['raw' => mb_substr($b, 0, 300)]];
};
$co1 = (int) db_one('SELECT content_id FROM campaign_ideas WHERE id = ?', [$ia1])['content_id'];
$pl = plan_active($uid, true);
$dUsed = plan_used($uid, 'designs', $pl['starts'], date('Y-m-d H:i:s', time() + 3600));
$bal = credits_balance($uid);
[$c, $j] = $design($co1);
$d1 = db_one('SELECT * FROM content_designs WHERE content_id = ? ORDER BY id DESC LIMIT 1', [$co1]);
t('أول تصميم للمنشور ← من حصة الحملة (campaign_alloc) ومن غير كريدت', !empty($j['ok']) && $d1 && (int) $d1['campaign_alloc'] === 1 && credits_balance($uid) === $bal, json_encode($j, JSON_UNESCAPED_UNICODE));
t('عدّاد التصميمات العادي بيستثنيه', plan_used($uid, 'designs', $pl['starts'], date('Y-m-d H:i:s', time() + 3600)) === $dUsed);
[$c, $j] = $design($co1, ['design_edit' => '1', 'custom_prompt' => 'غيّر اللون']);
t('إعادة التصميم ← من رصيد التعديلات (2 من 2)', !empty($j['ok']) && (int) $C($A)['edits_used'] === 2 && credits_balance($uid) === $bal, json_encode($j, JSON_UNESCAPED_UNICODE));
[$c, $j] = $design($co1, ['design_edit' => '1', 'custom_prompt' => 'تاني']);
t('رصيد التعديلات خلص ← كريدت بس (content_design_cost) ومن غير حصة التصميمات', !empty($j['ok']) && $bal - credits_balance($uid) === 4
    && plan_used($uid, 'designs', $pl['starts'], date('Y-m-d H:i:s', time() + 3600)) === $dUsed, ($bal - credits_balance($uid)) . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
$bal = credits_balance($uid);
[$c, $j] = $api(['action' => 'content_generate', 'id' => $A, 'idea_id' => $ia1, 'regen' => 1]);
t('إعادة كتابة بعد ما التعديلات خلصت ← بالكريدت', $c === 200 && $bal - credits_balance($uid) === 3);
$bal = credits_balance($uid);
[$c, $j] = $api(['action' => 'improve', 'id' => $A, 'idea_id' => $ia1]);
t('«حسّن» بعد ما التعديلات خلصت ← بالكريدت', $c === 200 && $bal - credits_balance($uid) === 3, $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
t('الكاروسيل: شريحة 4 برّه carousel_max_slides ← مش من حصة الحملة', ca_design_allow($co2 ? db_one('SELECT * FROM contents WHERE id = ?', [(int) $co2['id']]) : [], 4, false)['mode'] !== 'alloc');

/* ─── 8) الإلغاء بعد الكتابة ─── */
echo "الإلغاء بعد الكتابة\n";
[$c, $j] = $api(['action' => 'plan_cancel', 'id' => $A]);
t('إلغاء بعد ما اتكتب جزء ← الحملة مابترجعش', $c === 200 && empty($j['released']) && $events($uid, 'campaigns') === 2);
[$c, $j] = $api(['action' => 'content_generate', 'id' => $A, 'idea_id' => $ia3]);
t('حملة ملغية ← مفيش كتابة', $c === 402 && ($j['code'] ?? '') === 'closed');

/* ─── 9) مفتاح الطوارئ والقديم ─── */
echo "مفتاح الطوارئ والحملات القديمة\n";
$old = (int) db_insert('INSERT INTO campaigns (user_id, title, basis, topic, status, created_at) VALUES (?,?,?,?, "active", DATE_SUB(NOW(), INTERVAL 1 YEAR))', [$uid, 'حملة قديمة', 'awareness', 'x']);
t('حملة اتعملت قبل الترحيل ← بتكمّل بالكريدت', ca_adopt($C($old))['pkg_state'] === null);
$eng = (int) db_insert('INSERT INTO campaigns (user_id, title, basis, topic, status, created_at) VALUES (?,?,?,?, "active", NOW())', [$uid, 'من المحرك', 'engine', 'x']);
t('حملات المحرك في المكتبة (basis = engine) مابتدخلش', ca_adopt($C($eng))['pkg_state'] === null);
set_setting('campaign_pkg_enabled', '0');
t('campaign_pkg_enabled = 0 ← كل الحملات بالكريدت', !ca_is_package($C($D)) && ca_status($uid) === null);
db_run('DELETE FROM settings WHERE setting_key = "campaign_pkg_enabled"');

/* ─── 10) الصفحات ─── */
echo "الصفحات\n";
[$c, $h] = http('GET', "$base/public/my-plan.php", [], $jar);
t('«باقتي» فيها الحملات المتاحة', $c === 200 && str_contains($h, 'الحملات (الشهر') && str_contains($h, 'حملة D'), (string) $c);
[$c, $h] = http('GET', "$base/public/campaign.php?id=$D&ui=v2", [], $jar);
t('صفحة الحملة بتحمّل سكريبت «اعتمد الخطة»', $c === 200 && str_contains($h, 'campaign-approve.js'), (string) $c);
[$c, $j] = $api(['action' => 'board', 'id' => $D]);
t('اللوحة فيها حالة الحملة في الباقة', $c === 200 && ($j['package']['state'] ?? '') === 'approved' && isset($j['allowance']['left']), json_encode(array_intersect_key($j, ['package' => 1, 'allowance' => 1]), JSON_UNESCAPED_UNICODE));

/* ─── تنضيف ─── */
foreach ([$uid, $uid0] as $u) {
    $cids = array_column(db_all('SELECT id FROM contents WHERE user_id = ?', [$u]), 'id');
    if ($cids) foreach (['content_versions', 'content_designs'] as $tb) { try { db_run("DELETE FROM $tb WHERE content_id IN (" . implode(',', array_map('intval', $cids)) . ')'); } catch (\Throwable $e) {} }
    foreach (['campaign_ideas', 'campaigns', 'contents', 'brand_profiles', 'credit_transactions', 'credit_lots', 'credit_wallets', 'ai_usage_logs', 'usage_events', 'user_plans', 'notifications', 'user_sessions', 'ai_runs', 'content_designs'] as $tb) {
        try { db_run("DELETE FROM $tb WHERE user_id = ?", [$u]); } catch (\Throwable $e) {}
    }
    try { db_run('DELETE FROM users WHERE id = ?', [$u]); } catch (\Throwable $e) { echo "  (المستخدم #$u ماتمسحش: " . $e->getMessage() . ")\n"; }
}
db_run('DELETE FROM credit_packages WHERE id = ?', [$pkgId]);
foreach ($keys as $k) {
    db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    if ($saved[$k] !== null) set_setting($k, $saved[$k]);
}
db_run('DELETE FROM rate_limits');

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
