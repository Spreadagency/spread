<?php
/**
 * Spread AI — الباقات (المرحلة 7): المساعدين — «🛟 الدعم» و«🤖 الوكيل»
 * للتطوير المحلي بس — بيعمل عملاء تجريبيين ويمسحهم في الآخر، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/pk7-assistants.php http://localhost:8080
 *
 * محتاج: ترحيلات المحرك + 2026_assistants · الـ AI الوهمي شغال (بيرد على ROLE: SPREAD_SUPPORT / SPREAD_AGENT).
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/assistants.php';
require_once __DIR__ . '/../includes/ce-design.php';
if (!as_ready()) exit("شغّل ترحيل 2026_assistants الأول\n");

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
    $jar = tempnam(sys_get_temp_dir(), 'pk7');
    [, $html] = http('GET', "$base/public/login.php", [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => $email, 'password' => 'Test12345!'], $jar);
    [, $page] = http('GET', "$base/public/dashboard.php", [], $jar);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $c);
    return [$jar, $c[1] ?? '', $page];
}
function aapi(string $base, array $s, string $action, array $data = [], bool $get = false): array
{
    if ($get) {
        [$code, $body] = http('GET', "$base/public/api/assistant.php?" . http_build_query(['action' => $action] + $data), [], $s[0], ['X-Requested-With: XMLHttpRequest']);
    } else {
        [$code, $body] = http('POST', "$base/public/api/assistant.php", base64_encode(json_encode(['action' => $action] + $data)), $s[0],
            ['X-CSRF-Token: ' . $s[1], 'X-Payload: b64', 'Content-Type: text/plain;charset=UTF-8', 'X-Requested-With: XMLHttpRequest']);
    }
    return [$code, json_decode($body, true) ?: []];
}
/** المهمة لحد ما تخلص أو تقف (الخطوات من غير cron زي الشات المفتوح) */
function drive(int $runId, int $max = 1500): array
{
    $run = as_run($runId);
    for ($i = 0; $i < $max && $run && $run['status'] === 'running'; $i++) $run = as_agent_tick($run, true);
    return $run;
}

$keys = ['assistant_support_enabled', 'assistant_agent_enabled', 'assistant_support_cost', 'assistant_agent_msg_cost', 'assistant_agent_fee', 'assistant_agent_daily', 'ce_worker_last_run'];
$saved = [];
foreach ($keys as $k) $saved[$k] = get_setting($k, null);
foreach (['assistant_support_enabled' => '1', 'assistant_agent_enabled' => '1', 'assistant_support_cost' => '0', 'assistant_agent_msg_cost' => '0', 'assistant_agent_fee' => '2', 'assistant_agent_daily' => '3', 'ce_worker_last_run' => ''] as $k => $v) set_setting($k, $v);
db_run('DELETE FROM rate_limits');
$uids = []; $pkgs = [];
$mk = function (bool $agent) use (&$uids, &$pkgs) {
    $id = (int) db_insert('INSERT INTO users (name, email, password, phone, status, email_verified_at, created_at) VALUES (?,?,?,?,"active",NOW(),NOW())',
        ['PK7 ' . ($agent ? 'وكيل' : 'دعم'), 'pk7-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT), '01012345678']);
    db_run('INSERT IGNORE INTO credit_wallets (user_id, balance) VALUES (?, 0)', [$id]);
    $p = (int) db_insert('INSERT INTO credit_packages (name, credits, price_egp, validity_days, is_active, assistant_agent, quotas_json) VALUES (?, 500, 0, 30, 0, ?, ?)',
        ['PK7 ' . ($agent ? 'agent' : 'basic'), $agent ? 1 : 0, json_encode(['designs' => 0, 'research' => 0])]);
    $pkgs[] = $p;
    plan_start($id, $p, 500, 30, 'paid', null, 'PK7');
    credits_add($id, 500, 'PK7 test');
    db_insert('INSERT INTO user_feature_access (user_id, feature_key, is_enabled) VALUES (?, "content_engine", 1)', [$id]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$id, 'عيادة الوكيل', 'عيادة أسنان تجريبية']);
    db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$id, 'تبييض الأسنان', 'high']);
    db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")', [$id, 'موظفة', json_encode(['الوقت']), json_encode(['ابتسامة']), json_encode(['السعر'])]);
    $uids[] = $id;
    return $id;
};

try {
/* ─── 1) مين يشوف إيه ─── */
echo "الصلاحيات\n";
$ua = $mk(true);
$ub = $mk(false);
t('الدعم متاح للاتنين', as_blocked($ua, 'support') === null && as_blocked($ub, 'support') === null);
t('الوكيل: للباقة اللي فيها الوكيل بس', as_blocked($ua, 'agent') === null && str_contains((string) as_blocked($ub, 'agent'), 'مش في باقتك'));
set_setting('assistant_agent_enabled', '0');
t('الأدمن قفل الوكيل ← مقفول للكل', as_blocked($ua, 'agent') !== null);
set_setting('assistant_agent_enabled', '1');
$sa = login($base, db_one('SELECT email FROM users WHERE id = ?', [$ua])['email']);
$sb = login($base, db_one('SELECT email FROM users WHERE id = ?', [$ub])['email']);
t('زرار «مساعدك» في صفحات العميل', str_contains($sa[2], 'assets/js/assistant.js') && str_contains($sa[2], 'assets/css/assistant.css'));

/* ─── 2) الدعم ─── */
echo "الدعم\n";
db_insert('INSERT INTO payment_requests (user_id, plan_name, credits, validity_days, original_amount, final_amount, method, method_label, contact_name, contact_email, contact_phone, status) VALUES (?, "باقة النمو", 100, 30, 500, 500, "instapay", "إنستاباي", "x", "x@x", "010", "pending")', [$ua]);
$ctx = as_context($ua);
t('السياق: الباقة والكريدت وطلب الدفع وهوية البراند', str_contains($ctx, 'الباقة: PK7 agent') && str_contains($ctx, 'الكريدت:') && str_contains($ctx, 'طلب دفع #') && str_contains($ctx, 'عيادة الوكيل'), $ctx);
[$c1, $l1] = aapi($base, $sa, 'load', ['kind' => 'support'], true);
t('أول مرة: ترحيب باسم العميل', $c1 === 200 && array_key_exists('blocked', $l1) && $l1['blocked'] === null && str_contains((string) ($l1['messages'][0]['content'] ?? ''), 'مساعد الدعم') && ($l1['agent'] ?? false) === true);
[$c2, $r2] = aapi($base, $sa, 'send', ['kind' => 'support', 'message' => 'ليه رصيدي قل؟']);
$lk = array_column($r2['links'] ?? [], 'url');
t('الرد + زراير الصفحات المسموحة بس (اللينك الغريب اتشال)', $c2 === 200 && str_contains((string) ($r2['reply'] ?? ''), 'رصيدك') && count($lk) === 2
    && str_contains($lk[0], 'my-plan.php?tab=balance') && str_contains($lk[1], 'payments.php') && !str_contains((string) $r2['reply'], '[LINK'), json_encode($r2, JSON_UNESCAPED_UNICODE));
$last = json_decode((string) @file_get_contents('/tmp/claude-0/-home-user-spread/c847aa0b-e606-5d77-9835-79b34f925adf/scratchpad/mockai/last_req.json'), true);
$sys = (string) ($last['messages'][0]['content'] ?? '');
t('البرومبت فيه دليل المنصة وبيانات الحساب', str_contains($sys, 'ROLE: SPREAD_SUPPORT') && str_contains($sys, 'دليل المنصة') && str_contains($sys, 'طلب دفع #'));
[$c3, $r3] = aapi($base, $sa, 'send', ['kind' => 'support', 'message' => 'النشر فشل تاني عايز اكلم الفريق', 'page' => '/content-history.php']);
$iss = db_one('SELECT * FROM user_issues WHERE user_id = ? AND category_user = "support_chat" ORDER BY id DESC LIMIT 1', [$ua]);
t('مقدرش يحل ← تذكرة للفريق بالمحادثة وبيانات الحساب', $c3 === 200 && $iss && (int) ($r3['ticket'] ?? 0) === (int) $iss['id'] && str_contains((string) $r3['reply'], 'بلاغ #' . $iss['id'])
    && str_contains((string) $iss['context'], 'النشر فشل') && str_contains((string) $iss['context'], 'account') && $iss['page_url'] === '/content-history.php', json_encode([$c3, $r3], JSON_UNESCAPED_UNICODE));
t('الأدمن اتنبّه', (bool) db_one('SELECT id FROM admin_alerts WHERE dedupe_key = ?', ['st:' . $iss['id']]));
[, $r4] = aapi($base, $sa, 'send', ['kind' => 'support', 'message' => 'عايز اكلم الفريق بسرعة']);
t('تذكرة واحدة كل 10 دقايق (مش تكرار)', (int) ($r4['ticket'] ?? 0) === (int) $iss['id'] && (int) db_one('SELECT COUNT(*) n FROM user_issues WHERE user_id = ? AND category_user = "support_chat"', [$ua])['n'] === 1);
set_setting('assistant_support_cost', '1');
$b0 = credits_balance($ua);
aapi($base, $sa, 'send', ['kind' => 'support', 'message' => 'سؤال']);
t('رسالة الدعم بكريدت لو الأدمن حدد', credits_balance($ua) === $b0 - 1);
set_setting('assistant_support_cost', '0');
[, $l5] = aapi($base, $sa, 'load', ['kind' => 'support'], true);
t('المحادثة محفوظة', count($l5['messages'] ?? []) === 9, (string) count($l5['messages'] ?? []));
[, $r6] = aapi($base, $sa, 'reset', ['kind' => 'support']);
t('محادثة جديدة', count($r6['messages'] ?? []) === 1);

/* ─── 3) الوكيل: المحادثة ← المهمة ─── */
echo "الوكيل\n";
[$k1, $x1] = aapi($base, $sb, 'send', ['kind' => 'agent', 'message' => 'اعمل حملة']);
[, $xl] = aapi($base, $sb, 'load', ['kind' => 'agent'], true);
t('باقة من غير الوكيل ← مقفول + لينك الترقية', $k1 === 403 && str_contains((string) ($xl['blocked'] ?? ''), 'رقّي') && str_contains((string) ($xl['upgrade_url'] ?? ''), 'my-plan.php'));
[$k2, $x2] = aapi($base, $sa, 'send', ['kind' => 'agent', 'message' => 'عايز حملة']);
t('بيسأل الأول (من غير مهمة)', $k2 === 200 && $x2['run'] === null && str_contains((string) $x2['reply'], 'هدفها'), json_encode([$k2, $x2], JSON_UNESCAPED_UNICODE));
[, $x3] = aapi($base, $sa, 'send', ['kind' => 'agent', 'message' => 'ناقص']);
t('بريف ناقص ← بيقول محتاج إيه ومفيش مهمة', $x3['run'] === null && str_contains((string) $x3['reply'], 'محتاج أعرف'), (string) ($x3['reply'] ?? ''));
$lastA = json_decode((string) @file_get_contents('/tmp/claude-0/-home-user-spread/c847aa0b-e606-5d77-9835-79b34f925adf/scratchpad/mockai/last_req.json'), true);
t('برومبت الوكيل: خدمات العميل والرصيد والاختيارات', str_contains((string) ($lastA['messages'][0]['content'] ?? ''), 'تبييض الأسنان') && str_contains((string) ($lastA['messages'][0]['content'] ?? ''), 'ROLE: SPREAD_AGENT'));
[$k4, $x4] = aapi($base, $sa, 'send', ['kind' => 'agent', 'message' => 'تمام نفذ']);
$run = $x4['run'] ?? null;
t('المهمة مستنية موافقتك: 3 قطع + التقدير + رسوم 2', $k4 === 200 && $run && $run['status'] === 'pending' && $run['pieces'] === 3 && $run['credits'] > 0 && $run['fee'] === 2 && !$run['campaign_id'], json_encode($run, JSON_UNESCAPED_UNICODE));
$b1 = credits_balance($ua);
[$k5, $x5] = aapi($base, $sa, 'agent_confirm', ['run_id' => $run['id'], 'publish' => 'schedule', 'connection_id' => 0]);
t('جدولة من غير صفحة مربوطة ← مرفوض', $k5 === 409 && str_contains((string) $x5['error'], 'الصفحة'));
[$k6, $x6] = aapi($base, $sa, 'agent_confirm', ['run_id' => $run['id'], 'publish' => 'none']);
$r6 = $x6['run'] ?? [];
t('الموافقة ← الرسوم اتخصمت والحملة اتعملت في المحرك والوكيل بدأ', $k6 === 200 && in_array($r6['status'] ?? '', ['running', 'paused'], true) && (int) ($r6['campaign_id'] ?? 0) > 0
    && credits_balance($ua) <= $b1 - 2 && (bool) db_one('SELECT id FROM ce_campaigns WHERE id = ? AND user_id = ?', [(int) $r6['campaign_id'], $ua]), json_encode([$k6, $x6], JSON_UNESCAPED_UNICODE));
t('مفيش ملف هوية ← أول مرحلة «ملف الهوية»', ($r6['stages'][0]['key'] ?? '') === 'dna');
[$k7] = aapi($base, $sa, 'agent_confirm', ['run_id' => $run['id'], 'publish' => 'none']);
t('الموافقة مرة واحدة بس', $k7 === 409);

/* ─── 4) التنفيذ ─── */
echo "التنفيذ\n";
$cid = (int) $r6['campaign_id'];
$rr = drive((int) $run['id']);
if ($rr['status'] === 'paused' && $rr['stage'] === 'plan') {
    t('الخطة ماعدّتش البوابة ← وقف وقال للعميل يراجعها', str_contains((string) $rr['note'], 'الخطة') && (bool) db_one('SELECT id FROM user_notifications WHERE user_id = ? AND kind = "agent_run"', [$ua]));
    db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$cid]);   // العميل اعتمدها بنفسه
    $rr = as_agent_tick($rr, true);
    t('العميل اعتمد ← الوكيل كمّل لوحده', $rr['status'] === 'running' && $rr['stage'] === 'write', $rr['stage'] . '/' . $rr['status']);
    $rr = drive((int) $run['id']);
} else {
    t('الخطة عدّت البوابة واتعتمدت لوحدها', in_array($rr['stage'], ['done', 'design', 'write'], true), $rr['stage'] . '/' . $rr['status'] . ' ' . $rr['note']);
}
$c = ce_campaign_get($cid, $ua);
$pieces = (int) db_one('SELECT COUNT(*) n FROM ce_contents WHERE campaign_id = ? AND status IN ("pass","manual")', [$cid])['n'];
$designs = (int) db_one('SELECT COUNT(*) n FROM ce_designs WHERE campaign_id = ? AND status IN ("pass","manual")', [$cid])['n'];
t('خلصت: الكتابة والتصميم كاملين', $rr['status'] === 'done' && $rr['stage'] === 'done' && $c['status'] === 'done' && $pieces === 3 && $designs >= 3, json_encode([$rr['status'], $rr['stage'], $rr['note'], $c['status'], $pieces, $designs], JSON_UNESCAPED_UNICODE));
$log = json_decode((string) $rr['log_json'], true) ?: [];
t('السجل: كل مرحلة اتسجلت', count($log) >= 5 && str_contains(implode(' ', array_column($log, 'msg')), 'الكتابة خلصت') && str_contains(implode(' ', array_column($log, 'msg')), 'التصميم خلص'), json_encode(array_column($log, 'msg'), JSON_UNESCAPED_UNICODE));
t('إشعار + رسالة في محادثة الوكيل', (bool) db_one('SELECT id FROM user_notifications WHERE user_id = ? AND title LIKE "%الوكيل خلّص%"', [$ua])
    && str_contains(json_encode(as_thread($ua, 'agent')['messages'], JSON_UNESCAPED_UNICODE), 'الوكيل خلّص'));
t('قطع الحملة في مكتبة المحتوى', (int) db_one('SELECT COUNT(*) n FROM contents WHERE user_id = ? AND campaign_id IS NOT NULL', [$ua])['n'] >= 3);
t('رسوم التنفيذ في سجل الكريدت', (bool) db_one('SELECT id FROM credit_transactions WHERE user_id = ? AND notes LIKE "%الوكيل: رسوم تنفيذ%"', [$ua]));
[, $st] = aapi($base, $sa, 'agent_status', ['run_id' => $run['id']]);
t('الحالة من الـ API', ($st['run']['status'] ?? '') === 'done' && str_contains((string) ($st['run']['campaign_url'] ?? ''), 'engine.php?campaign=' . $cid));

/* ─── 5) الحدود والإلغاء ─── */
echo "الحدود والإلغاء\n";
[, $y1] = aapi($base, $sa, 'send', ['kind' => 'agent', 'message' => 'نفذ تاني']);
set_setting('assistant_agent_daily', '1');
[$k8, $y8] = aapi($base, $sa, 'agent_confirm', ['run_id' => $y1['run']['id'] ?? 0, 'publish' => 'none']);
t('الحد اليومي', $k8 === 409 && str_contains((string) ($y8['error'] ?? ''), 'في اليوم'));
set_setting('assistant_agent_daily', '5');
$b2 = credits_balance($ua);
[$k9, $y9] = aapi($base, $sa, 'agent_confirm', ['run_id' => $y1['run']['id'] ?? 0, 'publish' => 'none']);
t('مهمة تانية: ملف الهوية موجود ← تبدأ من الخطة', $k9 === 200 && ($y9['run']['stages'][0]['key'] ?? '') === 'plan' && credits_balance($ua) < $b2, json_encode($y9['run'] ?? null, JSON_UNESCAPED_UNICODE));
[, $y2] = aapi($base, $sa, 'send', ['kind' => 'agent', 'message' => 'نفذ كمان']);
[$k10, $y10] = aapi($base, $sa, 'agent_confirm', ['run_id' => $y2['run']['id'] ?? 0, 'publish' => 'none']);
t('مهمة واحدة شغالة في نفس الوقت', $k10 === 409 && str_contains((string) ($y10['error'] ?? ''), 'مهمة تانية'));
$bal = credits_balance($ua);
[$k11, $y11] = aapi($base, $sa, 'agent_cancel', ['run_id' => $y1['run']['id']]);
$cj = db_one('SELECT status FROM ce_jobs WHERE user_id = ? AND kind = "campaign_plan" ORDER BY id DESC LIMIT 1', [$ua]);
t('إلغاء في الخطة ← رسوم التنفيذ رجعت وشغلانة الخطة اتلغت (وحجزها رجع)', $k11 === 200 && ($y11['run']['status'] ?? '') === 'cancelled' && credits_balance($ua) >= $bal + 2 && in_array($cj['status'] ?? '', ['cancelled', 'done'], true), json_encode([$k11, $y11['run']['status'] ?? null, $bal, credits_balance($ua), $cj], JSON_UNESCAPED_UNICODE));
db_run('UPDATE credit_wallets SET balance = 0 WHERE user_id = ?', [$ua]);
db_run('UPDATE credit_lots SET remaining = 0 WHERE user_id = ?', [$ua]);
[$k12, $y12] = aapi($base, $sa, 'agent_confirm', ['run_id' => $y2['run']['id'] ?? 0, 'publish' => 'none']);
t('رصيد مش كفاية للرسوم ← مرفوض', $k12 === 402, json_encode([$k12, $y12], JSON_UNESCAPED_UNICODE));
credits_add($ua, 2, 'PK7 الرسوم بس');
[$k13, $y13] = aapi($base, $sa, 'agent_confirm', ['run_id' => $y2['run']['id'] ?? 0, 'publish' => 'none']);
$p13 = as_run((int) ($y2['run']['id'] ?? 0));
t('الرسوم اتدفعت بس الخطة محتاجة كريدت ← الوكيل وقف وقال يشحن', $k13 === 200 && $p13['status'] === 'paused' && $p13['stage'] === 'plan' && str_contains((string) $p13['note'], 'رصيدك مش كفاية'), json_encode([$k13, $p13['status'] ?? null, $p13['note'] ?? null], JSON_UNESCAPED_UNICODE));
credits_add($ua, 300, 'PK7 شحن');
$p13 = as_agent_tick($p13, false);
t('العميل شحن ← الوكيل كمّل لوحده', $p13['status'] === 'running' && $p13['stage'] === 'plan' && (bool) db_one('SELECT id FROM ce_jobs WHERE user_id = ? AND kind = "campaign_plan" AND status IN ("queued","running")', [$ua]), json_encode([$p13['status'], $p13['note']], JSON_UNESCAPED_UNICODE));
as_agent_cancel($ua, (int) $p13['id']);
t('الـ cron: as_agent_tick_all شغال', is_int(as_agent_tick_all(5)));

/* ─── 6) الأدمن ─── */
echo "الأدمن\n";
$ja = tempnam(sys_get_temp_dir(), 'pk7a');
[, $lp] = http('GET', "$base/admin/login.php", [], $ja);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $m1);
http('POST', "$base/admin/login.php", ['csrf' => $m1[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $ja);
[$ca, $ha] = http('GET', "$base/admin/assistants.php?f=all", [], $ja);
t('صفحة «المساعدين»: الإعدادات والدليل والمهام', $ca === 200 && str_contains($ha, 'name="assistant_agent_fee"') && str_contains($ha, 'name="assistant_support_kb"') && str_contains($ha, 'PK7 وكيل') && !str_contains($ha, 'Warning:'), (string) $ca);
preg_match('/name="csrf" value="([^"]+)"/', $ha, $m2);
http('POST', "$base/admin/assistants.php", http_build_query(['csrf' => $m2[1] ?? '', 'action' => 'settings', 'assistant_support_enabled' => 1, 'assistant_agent_enabled' => 1, 'assistant_support_cost' => 0, 'assistant_agent_msg_cost' => 0, 'assistant_agent_fee' => 7, 'assistant_agent_daily' => 4]), $ja, ['Content-Type: application/x-www-form-urlencoded']);
t('حفظ الإعدادات', get_setting('assistant_agent_fee') === '7' && get_setting('assistant_agent_daily') === '4');
http('POST', "$base/admin/assistants.php", http_build_query(['csrf' => $m2[1] ?? '', 'action' => 'kb', 'assistant_support_kb' => 'دليل مخصص PK7']), $ja, ['Content-Type: application/x-www-form-urlencoded']);
t('دليل المنصة من الأدمن بيروح للدعم', str_contains(as_support_system($ua), 'دليل مخصص PK7'));
set_setting('assistant_support_kb', '');
[$cp, $hp] = http('GET', "$base/admin/packages.php?edit=" . $pkgs[0], [], $ja);
t('«الباقات»: خانات الدعم والوكيل', $cp === 200 && str_contains($hp, 'name="assistant_agent"') && str_contains($hp, 'name="assistant_support"'));
[$ci, $hi] = http('GET', "$base/admin/ai-issues.php?cat=support_chat", [], $ja);
t('التذكرة في «البلاغات» بتصنيف «من مساعد الدعم»', $ci === 200 && str_contains($hi, 'من مساعد الدعم'));

} finally {
if (function_exists('qt_price_user')) qt_price_user(null, true);
foreach ($uids as $id) {
    foreach (db_all('SELECT id FROM ce_campaigns WHERE user_id = ?', [$id]) as $cc) {
        foreach (['ce_publish_queue', 'ce_designs', 'ce_contents', 'ce_ideas', 'ce_slots'] as $tb) { try { db_run("DELETE FROM {$tb} WHERE campaign_id = ?", [(int) $cc['id']]); } catch (\Throwable $e) {} }
    }
    foreach (['agent_runs', 'assistant_threads', 'ce_jobs', 'ce_campaigns', 'user_plans', 'ai_runs', 'payment_requests', 'user_notifications'] as $tb) { try { db_run("DELETE FROM {$tb} WHERE user_id = ?", [$id]); } catch (\Throwable $e) {} }
    foreach (db_all('SELECT id FROM user_issues WHERE user_id = ?', [$id]) as $is) db_run('DELETE FROM admin_alerts WHERE dedupe_key = ?', ['st:' . $is['id']]);
    try { db_run('DELETE FROM user_issues WHERE user_id = ?', [$id]); } catch (\Throwable $e) {}
    account_purge($id);
}
foreach ($pkgs as $p) db_run('DELETE FROM credit_packages WHERE id = ?', [$p]);
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
}
t('مسح الحسابات التجريبية', !db_one('SELECT id FROM users WHERE email LIKE "pk7-%@test.local"') && !db_one('SELECT id FROM agent_runs WHERE user_id IN (' . implode(',', $uids ?: [0]) . ')'));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
