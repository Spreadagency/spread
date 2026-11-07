<?php
/**
 * Spread AI — الباقات (المرحلة 5): مستويات الجودة (الموديلات · معامل السعر · جولات التحسين · الأولوية)
 * للتطوير المحلي بس — بيعمل عملاء تجريبيين ويمسحهم في الآخر، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/pk5-tiers.php http://localhost:8080
 *
 * محتاج: ترحيلات المحرك · الـ AI الوهمي شغال.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ce-campaign.php';
require_once __DIR__ . '/../includes/ce-design.php';
require_once __DIR__ . '/../includes/ce-publish.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/ce-write.php';
require_once __DIR__ . '/../includes/ai-gateway.php';
if (!qt_ready()) exit("شغّل ترحيل 2026_quality_tiers الأول\n");

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array|string $post = [], ?string $jar = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function login(string $base, string $email): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cek');
    [, $html] = http('GET', "$base/public/login.php", [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => $email, 'password' => 'Test12345!'], $jar);
    [, $page] = http('GET', "$base/public/dashboard.php", [], $jar);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $c);
    return [$jar, $c[1] ?? ''];
}
function api_post(string $base, array $s, string $action, array $data = []): array
{
    [$code, $body] = http('POST', "$base/public/api/engine.php", base64_encode(json_encode(['action' => $action] + $data)), $s[0],
        ['X-CSRF-Token: ' . $s[1], 'X-Payload: b64', 'Content-Type: text/plain;charset=UTF-8', 'X-Requested-With: XMLHttpRequest']);
    return [$code, json_decode($body, true) ?: []];
}
function run_job(int $id, int $max = 250): ?array
{
    $j = null;
    for ($i = 0; $i < $max; $i++) {
        $x = ce_job_step($id);
        if ($x) $j = $x;
        if ($j && !in_array($j['status'], ['queued', 'running'], true)) break;
    }
    return $j;
}
$saved = [];
foreach (['content_generation_cost', 'carousel_generation_cost', 'publish_cost', 'ce_billing_mode', 'ce_qa_max_rounds', 'ce_qa_pass_total', 'ce_vision_max_regen'] as $k) $saved[$k] = get_setting($k, null);
foreach (['content_generation_cost' => '2', 'carousel_generation_cost' => '3', 'publish_cost' => '2', 'ce_billing_mode' => 'fixed', 'ce_qa_max_rounds' => '1', 'ce_qa_pass_total' => '85', 'ce_vision_max_regen' => '1'] as $k => $v) set_setting($k, $v);
$tierBak = db_all('SELECT * FROM quality_tiers');
$uids = [];
$pkgs = [];
$jobIds = [];
$mk = function (string $tier) use (&$uids, &$pkgs) {
    $id = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, created_at) VALUES (?,?,?,"active",NOW(),NOW())',
        ['PK5 ' . $tier, 'pk5-' . $tier . '-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_run('INSERT IGNORE INTO credit_wallets (user_id, balance) VALUES (?, 0)', [$id]);
    $p = (int) db_insert('INSERT INTO credit_packages (name, credits, price_egp, validity_days, is_active, quality_tier) VALUES (?, 100, 0, 30, 0, ?)', ['PK5 ' . $tier, $tier]);
    $pkgs[] = $p;
    plan_start($id, $p, 100, 30, 'paid', null, 'PK5');
    credits_add($id, 100, 'PK5 test');
    $uids[] = $id;
    return $id;
};

try {
/* ─── 1) المستويات ─── */
echo "المستويات\n";
t('3 مستويات افتراضية بأسمائها', array_keys(qt_all()) === ['standard', 'pro', 'premium'] && pkg_tiers()['premium'] === 'بريميوم');
$std = $mk('standard');
$pro = $mk('pro');
$pre = $mk('premium');
t('مستوى العميل من باقته', qt_for_user($std) === 'standard' && qt_for_user($pro) === 'pro' && qt_for_user($pre) === 'premium');

/* ─── 2) السعر ─── */
echo "معامل السعر\n";
qt_price_user($std, true);
t('عادي ← الأسعار زي ما هي', cost_for('content_generation_cost') === 2 && cost_for_format('carousel') === 3);
qt_price_user($pro, true);
t('احترافي × 1.5 (لفوق): منشور 3 · كاروسيل 5', cost_for('content_generation_cost') === 3 && cost_for_format('carousel') === 5 && cost_for_format('story') === 3);
t('النشر والتصميم مابيتأثروش بالمستوى', publish_credit_cost() === 2 && design_credit_cost() === 0);
qt_price_user($pre, true);
t('بريميوم × 2.5: منشور 5 · خطة المحرك ×2.5', cost_for('content_generation_cost') === 5 && ce_price_plan() === (int) ceil(max(0, (int) (get_setting('ce_plan_cost', '') !== '' ? get_setting('ce_plan_cost') : get_setting('plan_ideas_cost', 2))) * 2.5));
db_run('UPDATE quality_tiers SET is_active = 0 WHERE tkey = "premium"');
db_run('UPDATE quality_tiers SET is_active = 1 WHERE tkey = "premium"');

/* ─── 3) إعدادات المحرك ─── */
echo "المحرك\n";
qt_price_user($std, true);
$r0 = ce_qa_rules();
t('عادي ← إعدادات المحرك العامة', $r0['max_rounds'] === 1 && (float) $r0['pass_total'] === 85.0 && ce_design_rules()['max_regen'] === 1);
qt_price_user($pro, true);
t('احترافي ← جولتين · حد 88 · إعادتين للصورة', ce_qa_rules()['max_rounds'] === 2 && (float) ce_qa_rules()['pass_total'] === 88.0 && ce_design_rules()['max_regen'] === 2);
qt_price_user($pre, true);
t('بريميوم ← 3 جولات · حد 90 · 3 إعادات', ce_qa_rules()['max_rounds'] === 3 && (float) ce_qa_rules()['pass_total'] === 90.0 && ce_design_rules()['max_regen'] === 3);

/* ─── 4) الأولوية ─── */
echo "الأولوية\n";
db_run('UPDATE ce_jobs SET run_after = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE status IN ("queued","running") AND (run_after IS NULL OR run_after <= NOW())');   // شغل قديم محلي مايدخلش في الاختبار
$j1 = ce_job_create($std, 'brand_dna', [], ['idem_key' => 'pk5a:' . bin2hex(random_bytes(4))]);
$j2 = ce_job_create($pre, 'brand_dna', [], ['idem_key' => 'pk5b:' . bin2hex(random_bytes(4))]);
$jobIds = [(int) $j1['job']['id'], (int) $j2['job']['id']];
t('شغلانة بريميوم أولويتها 10', (int) db_one('SELECT priority FROM ce_jobs WHERE id = ?', [$jobIds[1]])['priority'] === 10 && (int) db_one('SELECT priority FROM ce_jobs WHERE id = ?', [$jobIds[0]])['priority'] === 0);
t('الطابور بيشغّل بريميوم الأول حتى لو اتعملت بعد', ce_job_pick() === $jobIds[1]);

/* ─── 5) الموديلات ─── */
echo "الموديلات\n";
$mid = (int) (db_one('SELECT id FROM ai_model_registry WHERE enabled = 1 AND type IN ("text","vision") AND model <> ? ORDER BY id DESC LIMIT 1', [(string) (json_decode((string) (db_one('SELECT chain_json FROM ai_task_routes WHERE task = "ce_writer"')['chain_json'] ?? ''), true)[0]['m'] ?? '')])['id'] ?? 0);
$mm = db_one('SELECT * FROM ai_model_registry WHERE id = ?', [$mid]);
qt_route_save('pro', 'ce_writer', $mid, 0.4);
$rt = qt_route('pro', 'ce_writer');
t('موديل المهمة في المستوى', $rt && $rt['m'] === $mm['model'] && $rt['p'] === $mm['provider_name'] && (float) $rt['temp'] === 0.4);
t('«عادي» ومهمة مالهاش موديل ← زي الأساسي', qt_route('standard', 'ce_writer') === null && qt_route('pro', 'ce_critic') === null);
$ch = ai_gw_chain('ce_writer', ['tier_route' => $rt]);
$baseChain = ai_gw_chain("ce_writer", []);
t('السلسلة: موديل المستوى الأول والأساسي بعده كبديل', ($ch[0]['m'] ?? '') === $mm['model'] && count($ch) >= count($baseChain), json_encode(array_map(fn($c) => $c['p']['name'] . '/' . $c['m'], $ch)));
qt_price_user(null, true);
$a = ce_ai_json('ce_writer', 'Return JSON {"ok":true}', 'test', ['user_id' => $pro, 'ref_type' => 'pk5', 'ref_id' => 1], 100);
$run = db_one('SELECT model FROM ai_runs WHERE user_id = ? AND task = "ce_writer" ORDER BY id DESC LIMIT 1', [$pro]);
t('طلب حقيقي لعميل «احترافي» ← اتنفذ على موديل المستوى', $run && $run['model'] === $mm['model'], json_encode([$a['ok'], $a['error'], $run]));
ce_ai_json('ce_writer', 'Return JSON {"ok":true}', 'test', ['user_id' => $std, 'ref_type' => 'pk5', 'ref_id' => 1], 100);
$run0 = db_one('SELECT model FROM ai_runs WHERE user_id = ? AND task = "ce_writer" ORDER BY id DESC LIMIT 1', [$std]);
t('عميل «عادي» ← الموديل الأساسي', $run0 && $run0['model'] === ($baseChain[0]["m"] ?? ""), json_encode([$run0, $baseChain[0]["m"] ?? null]));

/* ─── 6) الأدمن ─── */
echo "الأدمن\n";
$jar = tempnam(sys_get_temp_dir(), 'pk5a');
[, $lp] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $m1);
http('POST', "$base/admin/login.php", ['csrf' => $m1[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c1, $h1] = http('GET', "$base/admin/quality-tiers.php?tier=pro", [], $jar);
t('صفحة «مستويات الجودة»: المستويات + موديلات كل مهمة', $c1 === 200 && str_contains($h1, 'name="credit_mult"') && str_contains($h1, 'name="m[ce_writer]"') && !str_contains($h1, 'Warning:'), (string) $c1);
preg_match('/name="csrf" value="([^"]+)"/', $h1, $m2);
http('POST', "$base/admin/quality-tiers.php", http_build_query(['csrf' => $m2[1] ?? '', 'action' => 'save_tier', 'tkey' => 'pro', 'label' => 'احترافي', 'credit_mult' => '1.75', 'qa_rounds' => '2', 'qa_pass' => '87', 'vision_regen' => '', 'priority' => '6', 'is_active' => '1']), $jar, ['Content-Type: application/x-www-form-urlencoded']);
$pr = db_one('SELECT * FROM quality_tiers WHERE tkey = "pro"');
t('حفظ المستوى من الأدمن (والفاضي = إعداد المحرك)', (float) $pr['credit_mult'] === 1.75 && (int) $pr['qa_pass'] === 87 && $pr['vision_regen'] === null && (int) $pr['priority'] === 6, json_encode($pr, JSON_UNESCAPED_UNICODE));
http('POST', "$base/admin/quality-tiers.php", http_build_query(['csrf' => $m2[1] ?? '', 'action' => 'save_routes', 'tier' => 'pro', 'm' => ['ce_writer' => 0, 'ce_critic' => $mid], 't' => ['ce_critic' => '0.2']]), $jar, ['Content-Type: application/x-www-form-urlencoded']);
t('حفظ الموديلات: الكاتب رجع للأساسي والناقد اتغير', !db_one('SELECT tier FROM ai_tier_routes WHERE tier = "pro" AND task = "ce_writer"') && (int) db_one('SELECT model_id FROM ai_tier_routes WHERE tier = "pro" AND task = "ce_critic"')['model_id'] === $mid);
[$c3, $h3] = http('GET', "$base/admin/packages.php?edit=" . $pkgs[1], [], $jar);
t('«الباقات»: المستوى من المستويات اللي في الأدمن', $c3 === 200 && str_contains($h3, 'value="premium"') && str_contains($h3, 'بريميوم'));

} finally {
db_run('DELETE FROM ai_tier_routes WHERE tier IN ("pro", "premium")');
db_run('DELETE FROM quality_tiers');
foreach ($tierBak as $r) db_run('INSERT INTO quality_tiers (' . implode(',', array_keys($r)) . ') VALUES (' . implode(',', array_fill(0, count($r), '?')) . ')', array_values($r));
db_run('UPDATE ce_jobs SET run_after = NULL WHERE run_after > NOW() AND run_after <= DATE_ADD(NOW(), INTERVAL 2 HOUR) AND user_id NOT IN (' . implode(',', array_map('intval', $uids ?: [0])) . ')');
foreach ($uids as $id) {
    db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$id]);
    db_run('DELETE FROM user_plans WHERE user_id = ?', [$id]);
    db_run('DELETE FROM ai_runs WHERE user_id = ?', [$id]);
    account_purge($id);
}
foreach ($pkgs as $p) db_run('DELETE FROM credit_packages WHERE id = ?', [$p]);
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND notes LIKE "%PK5%"');
}
t('مسح الحسابات التجريبية والمستويات رجعت', !db_one('SELECT id FROM users WHERE email LIKE "pk5-%@test.local"') && (int) db_one('SELECT COUNT(*) n FROM quality_tiers')['n'] === count($tierBak));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
