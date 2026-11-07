<?php
/**
 * Spread AI — خطة الحملات · المرحلة 5: الشغل في الخلفية (camp_write · camp_design) · الفكرة البديلة والذاكرة
 *            · الوكيل على مسار «حملاتي» · معالج المحرك برّه القايمة · الواجهة
 * للتطوير المحلي بس — بيعمل باقة وعميل مؤقتين ويمسحهم، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/cc5-ui-agent.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/campaign-write.php';
require_once __DIR__ . '/../includes/campaign-jobs.php';
require_once __DIR__ . '/../includes/assistants.php';
if (!ca_ready() || !ce_ready() || !as_ready()) exit("شغّل الترحيلات الأول (php tests/dev-setup.php)\n");

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
$logSize = function (): int { clearstatcache(); $f = STORAGE_PATH . '/logs/mock-ai.jsonl'; return is_file($f) ? filesize($f) : 0; };
function mock_has(int $offset, string $needle): bool
{
    $f = STORAGE_PATH . '/logs/mock-ai.jsonl';
    clearstatcache();
    $h = fopen($f, 'r'); fseek($h, $offset);
    while (($l = fgets($h)) !== false) {
        $b = json_decode((string) (json_decode($l, true)['body'] ?? ''), true);
        foreach ((array) ($b['messages'] ?? []) as $m) if (is_string($m['content'] ?? null) && str_contains($m['content'], $needle)) { fclose($h); return true; }
    }
    fclose($h);
    return false;
}

db_run('DELETE FROM rate_limits');
$keys = ['campaign_write_mode', 'campaign_skills_enabled', 'brand_gate_pct', 'campaign_pkg_enabled', 'campaign_max_drafts', 'campaign_draft_alts',
         'ce_engine_new_campaigns', 'ce_engine_sunset_at', 'assistant_agent_path', 'assistant_agent_fee', 'assistant_agent_enabled', 'ce_worker_last_run', 'campaign_bg_jobs'];
$saved = [];
foreach ($keys as $k) $saved[$k] = get_setting($k, null);
foreach (['campaign_skills_enabled' => '0', 'brand_gate_pct' => '0', 'campaign_write_mode' => 'single', 'campaign_max_drafts' => '5', 'campaign_draft_alts' => '2',
          'assistant_agent_fee' => '0', 'assistant_agent_enabled' => '1', 'ce_worker_last_run' => ''] as $k => $v) set_setting($k, $v);
foreach (['campaign_pkg_enabled', 'ce_engine_new_campaigns', 'ce_engine_sunset_at', 'assistant_agent_path', 'campaign_bg_jobs'] as $k) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);

$tag = bin2hex(random_bytes(3));
$pkgId = (int) db_insert('INSERT INTO credit_packages (name, credits, price_egp, is_active, campaigns_month, posts_per_campaign, videos_per_campaign, free_edits_per_campaign, assistant_agent)
                          VALUES (?, 300, 0, 0, 3, 4, 1, 2, 1)', ["CC5 $tag"]);
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, created_at) VALUES (?,?,?, "active", NOW(), "approved", NOW(), NOW())',
    ['CC5', "cc5-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, description, audience, tone, dialect, created_at) VALUES (?,?,?,?,?,?,?, NOW())',
    [$uid, 'عيادة cc5', 'عيادة أسنان', 'عيادة أسنان تجريبية', 'عائلات', 'ودود', 'egyptian']);
plan_start($uid, $pkgId, 300, 30, 'admin', null, 'cc5 test');
credits_add($uid, 200, 'cc5 test', null, 'admin_add', null);

$jar = tempnam(sys_get_temp_dir(), 'cc5');
[, $html] = http('GET', "$base/public/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => "cc5-$tag@test.local", 'password' => 'Test12345!'], $jar);
[, $dash] = http('GET', "$base/public/dashboard.php", [], $jar);
preg_match('/<meta name="csrf-token" content="([^"]+)"/', $dash, $cm);
$csrf = $cm[1] ?? '';
$api = function (string $ep, array $d) use ($base, $jar, $csrf): array {
    [$c, $b] = http('POST', "$base/public/api/$ep.php", json_encode($d, JSON_UNESCAPED_UNICODE), $jar, ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
    return [$c, json_decode($b, true) ?: ['raw' => mb_substr($b, 0, 300)]];
};
$C = fn(int $id) => db_one('SELECT * FROM campaigns WHERE id = ?', [$id]);
$camp = fn(string $title) => (int) db_insert('INSERT INTO campaigns (user_id, title, basis, topic, status, created_at) VALUES (?,?,?,?, "active", NOW())', [$uid, $title, 'awareness', 'كشف الأسنان']);
$idea = fn(int $cid, string $title, string $fmt = 'post') => (int) db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, angle, title, description, audience, hook, formats, selected, format) VALUES (?,?,?,?,?,?,?,?,1,?)',
    [$cid, $uid, 'توعية', $title, 'تفاصيل', 'عائلات', 'بتأجل الكشف؟', 'منشور', $fmt]);
t('دخول العميل المؤقت', $csrf !== '');

/* ─── 1) الشغل في الخلفية ─── */
echo "الكتابة والتصميم في الخلفية\n";
$A = $camp('حملة الخلفية');
[$c, $j] = $api('campaign-flow', ['action' => 'ideas_generate', 'id' => $A, 'total' => 3, 'batch' => 0]);
db_run('DELETE FROM campaign_ideas WHERE campaign_id = ?', [$A]);
$ids = [$idea($A, 'منشور خلفية 1'), $idea($A, 'منشور خلفية 2'), $idea($A, 'فيديو خلفية', 'video')];
[$c, $j] = $api('campaign-flow', ['action' => 'plan_approve', 'id' => $A]);
t('الحملة اتعتمدت', $c === 200 && $C($A)['pkg_state'] === 'approved', json_encode($j, JSON_UNESCAPED_UNICODE));
[$c, $j] = $api('campaign-flow', ['action' => 'bulk_write', 'id' => $A]);
$jobW = (int) ($j['job']['id'] ?? 0);
t('«اكتب الكل» = شغلانة واحدة في الطابور (camp_write) لـ 3 أفكار', $c === 200 && $jobW > 0 && ($j['job']['kind'] ?? '') === 'camp_write' && (int) $j['count'] === 3, json_encode($j, JSON_UNESCAPED_UNICODE));
[$c2, $j2] = $api('campaign-flow', ['action' => 'bulk_write', 'id' => $A]);
t('ضغطة تانية ← نفس الشغلانة (مش اتنين)', !empty($j2['existing']) && (int) ($j2['job']['id'] ?? 0) === $jobW);
// الصفحة اتقفلت: الـ cron هو اللي بيكمّل
$t0 = time();
while (($jr = ce_job_get($jobW)) && in_array($jr['status'], ['queued', 'running'], true) && time() - $t0 < 120) ce_worker_tick(10);
$jr = ce_job_get($jobW);
$res = json_decode((string) $jr['result_json'], true) ?: [];
t('الـ worker كمّل الشغلانة من غير الصفحة (قفل التاب مابيوقفش)', $jr['status'] === 'done' && (int) ($res['ok'] ?? 0) === 3, $jr['status'] . ' ' . json_encode($res, JSON_UNESCAPED_UNICODE));
t('المحتوى اتكتب بنفس المسار: حد الحملة + التقييم في نفس الطلب', (int) $C($A)['posts_used'] === 2 && (int) $C($A)['videos_used'] === 1
    && (int) db_one('SELECT COUNT(*) n FROM campaign_ideas WHERE campaign_id = ? AND content_id IS NOT NULL AND eval_json LIKE ?', [$A, '%"mode":"self"%'])['n'] === 3);
[$c, $j] = $api('campaign-flow', ['action' => 'bulk_design', 'id' => $A, 'ratio' => '1:1']);
$jobD = (int) ($j['job']['id'] ?? 0);
t('«صمّم الكل» = شغلانة camp_design للمنشورات بس (الفيديو لأ)', $c === 200 && ($j['job']['kind'] ?? '') === 'camp_design' && (int) $j['count'] === 2, json_encode($j, JSON_UNESCAPED_UNICODE));
// الصفحة مفتوحة من غير cron: الصفحة بتطلب الخطوات
for ($k = 0; $k < 10; $k++) {
    [, $s] = $api('campaign-flow', ['action' => 'job_step', 'id' => $A, 'job_id' => $jobD]);
    if (empty($s['job']['active'])) break;
}
t('الصفحة كمّلت الخطوات بنفسها (job_step)', ($s['job']['status'] ?? '') === 'done' && (int) ($s['job']['result']['ok'] ?? 0) === 2, json_encode($s['job'] ?? $s, JSON_UNESCAPED_UNICODE));
t('التصميمات من حصة الحملة (campaign_alloc)', (int) db_one('SELECT COUNT(*) n FROM content_designs WHERE user_id = ? AND campaign_alloc = 1', [$uid])['n'] === 2);
[$c, $j] = $api('campaign-flow', ['action' => 'board', 'id' => $A]);
t('اللوحة فيها الشغل اللي خلص وطريقة الكتابة والباقة', $c === 200 && isset($j['jobs']['camp_design']) && ($j['write_mode'] ?? '') === 'single' && ($j['package']['posts']['used'] ?? 0) === 2);
[$c, $j] = $api('campaign-flow', ['action' => 'job_step', 'id' => $A, 'job_id' => 999999]);
t('شغلانة مش بتاعة الحملة ← 404', $c === 404);

/* ─── 2) الفكرة البديلة والذاكرة ─── */
echo "الفكرة البديلة والأفكار المرفوضة\n";
$B = $camp('حملة البدائل');
$api('campaign-flow', ['action' => 'ideas_generate', 'id' => $B, 'total' => 3, 'batch' => 0]);
$b1 = $idea($B, 'فكرة هتترفض ' . $tag);
$bal = credits_balance($uid);
[$c, $j] = $api('campaign-flow', ['action' => 'idea_alt', 'id' => $B, 'idea_id' => $b1]);
t('فكرة بديلة في المسودة ← ببلاش (1 من 2)', $c === 200 && !empty($j['free']) && credits_balance($uid) === $bal && (int) $C($B)['draft_alts'] === 1, json_encode($j, JSON_UNESCAPED_UNICODE));
$r1 = db_one('SELECT rejected, selected FROM campaign_ideas WHERE id = ?', [$b1]);
t('الفكرة القديمة اتعلّمت «مرفوضة» (مش اتمسحت) والجديدة مكانها', (int) $r1['rejected'] === 1 && (int) $r1['selected'] === 0 && !empty($j['idea']['id']) && (int) $j['replaced'] === $b1);
[$c, $j] = $api('campaign-flow', ['action' => 'board', 'id' => $B]);
t('المرفوضة مش بتظهر في اللوحة', !in_array($b1, array_column($j['ideas'] ?? [], 'id'), true));
$b2 = $idea($B, 'فكرة تانية'); $api('campaign-flow', ['action' => 'idea_alt', 'id' => $B, 'idea_id' => $b2]);
$b3 = $idea($B, 'فكرة تالتة');
$bal = credits_balance($uid);
[$c, $j] = $api('campaign-flow', ['action' => 'idea_alt', 'id' => $B, 'idea_id' => $b3]);
t('بعد حد البدائل ببلاش ← بسعر فكرة', $c === 200 && empty($j['free']) && $bal - credits_balance($uid) === idea_credits_for(1), (string) ($bal - credits_balance($uid)));
$b4 = $idea($B, 'فكرة هتتمسح');
$api('campaign-flow', ['action' => 'idea_delete', 'id' => $B, 'idea_id' => $b4]);
t('«حذف» فكرة لسه ماتكتبتش ← مرفوضة (للذاكرة)', (int) (db_one('SELECT rejected FROM campaign_ideas WHERE id = ?', [$b4])['rejected'] ?? 0) === 1);
db_run('DELETE FROM rate_limits');
$off = $logSize();
[$cg, $jg] = $api('campaign-flow', ['action' => 'ideas_generate', 'id' => $B, 'total' => 3, 'batch' => 0]);
t('إعادة الخطة مابتمسحش المرفوضة', (bool) db_one('SELECT id FROM campaign_ideas WHERE id = ?', [$b1]));
t('والمرفوضة داخلة قايمة «ماتكررهاش» في البرومبت', $cg === 200 && mock_has($off, 'فكرة هتترفض ' . $tag), $cg . ' ' . json_encode($jg, JSON_UNESCAPED_UNICODE));

/* ─── 3) الوكيل على مسار «حملاتي» ─── */
echo "الوكيل على نفس مسار الحملة\n";
t('المسار الافتراضي للوكيل = «حملاتي»', as_agent_path() === 'campaign');
t('الوكيل مش محتاج ميزة المحرك (content_engine) على المسار ده', as_blocked($uid, 'agent') === null, (string) as_blocked($uid, 'agent'));
[$brief, $err] = as_agent_brief($uid, ['goal' => 'awareness', 'formats' => ['post' => 2, 'carousel' => 0, 'reel' => 0], 'days' => 30, 'services' => 'all']);
t('بريف الوكيل سليم', !$err, json_encode($err, JSON_UNESCAPED_UNICODE));
$runId = (int) db_insert('INSERT INTO agent_runs (user_id, title, stage, status, brief_json, opts_json, estimate_json, fee_credits) VALUES (?,?,"confirm","pending",?,?,?,0)',
    [$uid, 'حملة الوكيل ' . $tag, json_encode($brief, JSON_UNESCAPED_UNICODE), json_encode(['publish' => 'none']), '{}']);
$before = (int) db_one('SELECT COUNT(*) n FROM usage_events WHERE user_id = ? AND unit = "campaigns"', [$uid])['n'];
$r = as_agent_confirm($uid, $runId);
$run = as_run($runId);
t('الموافقة ← حملة في «حملاتي» (مش المحرك)', $r['ok'] && (as_run_opts($run)['path'] ?? '') === 'campaign' && $C((int) $run['campaign_id'])['title'] === 'حملة الوكيل ' . $tag, json_encode($r, JSON_UNESCAPED_UNICODE));
for ($k = 0; $k < 40 && $run && in_array($run['status'], ['running'], true); $k++) $run = as_agent_tick($run, true);
$vc = (int) $run['campaign_id'];
t('الوكيل خلّص: الخطة ← الاعتماد ← الكتابة ← التصميم', $run['status'] === 'done', $run['status'] . ' ' . $run['stage'] . ' ' . (string) $run['note']);
t('الحملة اتحسبت من الباقة مرة واحدة (نفس «اعتمد الخطة»)', (int) db_one('SELECT COUNT(*) n FROM usage_events WHERE user_id = ? AND unit = "campaigns"', [$uid])['n'] === $before + 1
    && $C($vc)['pkg_state'] === 'approved');
t('المنشورات اتكتبت واتصممت', (int) $C($vc)['posts_used'] === 2 && (int) db_one('SELECT COUNT(*) n FROM content_designs d JOIN contents c ON c.id = d.content_id WHERE c.campaign_id = ?', [$vc])['n'] >= 2);
t('لينك الحملة للعميل = صفحة «حملاتي»', str_contains((string) (as_run_public($run)['campaign_url'] ?? ''), 'campaign.php?id=' . $vc));

/* ─── 4) المحرك: برّه القايمة · مفيش حملات جديدة · القفل بعد 60 يوم ─── */
echo "معالج المحرك\n";
db_run('INSERT IGNORE INTO user_feature_access (user_id, feature_key, is_enabled) VALUES (?, "content_engine", 1)', [$uid]);
[$c, $h] = http('GET', "$base/public/dashboard.php", [], $jar);
t('معالج المحرك مش في قايمة العميل (من غير حملات محرك قديمة)', $c === 200 && !str_contains($h, 'href="' . url('engine.php') . '"') && !str_contains($h, "engine.php'"), (string) $c);
[$c] = http('GET', "$base/public/engine.php", [], $jar);
t('صفحة المحرك ← «حملاتي»', $c === 302);
[$c, $j] = $api('engine', ['action' => 'campaign_save', 'brief' => ['goal' => 'awareness'], 'partial' => 1]);
t('حملة محرك جديدة من الـ API ← مقفولة', $c === 409 && ($j['code'] ?? '') === 'closed', $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
set_setting('ce_engine_new_campaigns', '1');
[$c, $j] = $api('engine', ['action' => 'campaign_save', 'brief' => ['goal' => 'awareness'], 'partial' => 1]);
t('ce_engine_new_campaigns = 1 ← بترجع', ($j['code'] ?? '') !== 'closed', $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
db_run('DELETE FROM settings WHERE setting_key = "ce_engine_new_campaigns"');
t('القفل بعد 60 يوم من الإطلاق (من campaign_pkg_since)', ce_engine_sunset_at() !== null && abs(strtotime(ce_engine_sunset_at()) - strtotime((string) get_setting('campaign_pkg_since', '')) - 60 * 86400) < 5);
set_setting('ce_engine_sunset_at', date('Y-m-d H:i:s', time() - 60));
$r = ce_job_create($uid, 'campaign_plan', [], ['ref_type' => 'ce_campaign', 'ref_id' => 0]);
$rw = ce_job_create($uid, 'camp_write', ['cid' => 0, 'ideas' => [], 'n' => 0], ['ref_type' => 'campaign', 'ref_id' => 0]);
t('بعد القفل: شغل حملات المحرك اتقفل والطابور نفسه شغال للحملات', !$r['ok'] && ($r['code'] ?? '') === 'sunset' && $rw['ok'], json_encode([$r, $rw['ok'] ?? null], JSON_UNESCAPED_UNICODE));
if (!empty($rw['job']['id'])) db_run('DELETE FROM ce_jobs WHERE id = ?', [(int) $rw['job']['id']]);
db_run('DELETE FROM settings WHERE setting_key = "ce_engine_sunset_at"');

/* ─── 5) الواجهة ─── */
echo "الواجهة\n";
[$c, $h] = http('GET', "$base/public/campaign.php?id=$A&ui=v2", [], $jar);
t('صفحة الحملة فيها شريط الباقة والخطوات والشغل في الخلفية', $c === 200 && str_contains($h, 'cf-pkg') && str_contains($h, 'stepList()') && str_contains($h, 'bgFollow') && str_contains($h, '"write_mode":"single"'), (string) $c);

/* ─── تنضيف ─── */
$cids = array_column(db_all('SELECT id FROM contents WHERE user_id = ?', [$uid]), 'id');
if ($cids) foreach (['content_versions', 'content_designs'] as $tb) { try { db_run("DELETE FROM $tb WHERE content_id IN (" . implode(',', array_map('intval', $cids)) . ')'); } catch (\Throwable $e) {} }
foreach (['campaign_ideas', 'campaigns', 'contents', 'brand_profiles', 'credit_transactions', 'credit_lots', 'credit_wallets', 'ai_usage_logs', 'usage_events', 'user_plans', 'notifications',
          'user_sessions', 'ai_runs', 'content_designs', 'ce_jobs', 'agent_runs', 'assistant_threads', 'user_feature_access', 'ce_campaigns'] as $tb) {
    try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
}
try { db_run('DELETE FROM users WHERE id = ?', [$uid]); } catch (\Throwable $e) { echo '  (المستخدم ماتمسحش: ' . $e->getMessage() . ")\n"; }
db_run('DELETE FROM credit_packages WHERE id = ?', [$pkgId]);
foreach ($keys as $k) {
    db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    if ($saved[$k] !== null) set_setting($k, $saved[$k]);
}
db_run('DELETE FROM rate_limits');
@unlink($jar);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
