<?php
/**
 * Spread AI — اختبارات محرك المحتوى · المرحلة D
 * للتطوير المحلي بس: بيعمل بيانات تجريبية ويمسحها في الآخر — ماتشغّلوش على السيرفر الحقيقي.
 *
 *   php tests/ce-d-engine.php http://localhost:8080
 *
 * محتاج: ترحيل 2026_ce_engine · حساب sub@test.local (الباسورد Test12345!) مفتوح له المحرك · free@test.local مقفول له.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/ce-campaign.php';
if (!ce_ready()) exit("شغّل ترحيل 2026_ce_engine الأول\n");

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function login(string $base, string $email): array
{
    $jar = tempnam(sys_get_temp_dir(), 'ced');
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
function tmp_user(string $tag, int $credits): int
{
    $uid = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())',
        ['CE-D test ' . $tag, 'ced-' . $tag . '-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('x', PASSWORD_DEFAULT)]);
    if ($credits > 0) credits_add($uid, $credits, 'CE-D test credits');
    return $uid;
}
db_run('DELETE FROM rate_limits');
$saved = [];
foreach (['ce_margin_pct', 'ce_egp_per_credit', 'ce_dna_max_tokens', 'ce_min_credits_piece', 'cron_secret'] as $k) $saved[$k] = get_setting($k, null);

/* ─── handler تجريبي ─── */
function ce_test_handler(array $job, array &$state): array
{
    $state['n'] = (int) ($state['n'] ?? 0) + 1;
    return match ($state['mode'] ?? 'ok') {
        'throw' => throw new RuntimeException('boom'),
        'fail' => ['fail' => 'مش هينفع'],
        default => $state['n'] >= 2 ? ['done' => true, 'result' => ['n' => $state['n']], 'used_credits' => 3] : ['done' => false, 'progress' => 50, 'label' => 'نص الطريق'],
    };
}
ce_job_register('ce_test', 'ce_test_handler', 'اختبار');

/* ─── 1) حجز الكريدت ─── */
echo "حجز الكريدت\n";
$u = tmp_user('hold', 20);
$h1 = ce_hold($u, 10, 'test', 'cedtest:A:' . $u);
t('الحجز بيخصم من الرصيد', $h1['ok'] && credits_balance($u) === 10, json_encode($h1));
$h1b = ce_hold($u, 10, 'test', 'cedtest:A:' . $u);
t('نفس المفتاح = نفس الحجز ومفيش خصم تاني', $h1b['ok'] && $h1b['existing'] && $h1b['hold_id'] === $h1['hold_id'] && credits_balance($u) === 10);
$s1 = ce_settle($h1['hold_id'], 4);
t('التسوية بترجع الفرق (10 محجوز − 4 اتصرف = 6)', $s1['ok'] && ($s1['refunded'] ?? 0) === 6 && credits_balance($u) === 16, json_encode($s1) . ' bal=' . credits_balance($u));
$s1b = ce_settle($h1['hold_id'], 0);
t('التسوية مرة واحدة بس', !empty($s1b['already']) && credits_balance($u) === 16);
$h2 = ce_hold($u, 1000, 'test', 'cedtest:B:' . $u);
t('رصيد مش كفاية → مفيش حجز', !$h2['ok'] && $h2['code'] === 'credits' && !db_one('SELECT id FROM ce_credit_holds WHERE idem_key = ?', ['cedtest:B:' . $u]));
$h3 = ce_hold($u, 5, 'test', 'cedtest:C:' . $u);
ce_settle($h3['hold_id'], 999);
t('مفيش خصم أكتر من المحجوز', credits_balance($u) === 11 && (int) db_one('SELECT used FROM ce_credit_holds WHERE id = ?', [$h3['hold_id']])['used'] === 5);
$h4 = ce_hold($u, 6, 'test', 'cedtest:D:' . $u);
ce_release($h4['hold_id']);
t('الإلغاء بيرجّع الحجز كله', credits_balance($u) === 11 && db_one('SELECT status FROM ce_credit_holds WHERE id = ?', [$h4['hold_id']])['status'] === 'released');

/* ─── 2) الشغلانات ─── */
echo "طابور الشغل\n";
$bal = credits_balance($u);
$j = ce_job_create($u, 'ce_test', ['mode' => 'ok'], ['credits' => 8, 'idem_key' => 'cedtest:J1:' . $u, 'note' => 'test']);
$j2 = ce_job_create($u, 'ce_test', ['mode' => 'ok'], ['credits' => 8, 'idem_key' => 'cedtest:J1:' . $u]);
t('الشغلانة بتحجز الكريدت · والضغط مرتين مابيعملش شغلانتين', $j['ok'] && $j2['existing'] && $j2['job']['id'] === $j['job']['id'] && credits_balance($u) === $bal - 8);
$jid = (int) $j['job']['id'];
$tok = ce_job_claim($jid);
t('قفل الشغلانة: واحد بس يقدر يمسكها', $tok !== null && ce_job_claim($jid) === null);
db_run('UPDATE ce_jobs SET lock_token = NULL, locked_at = NULL WHERE id = ?', [$jid]);
$a = ce_job_step($jid);
t('خطوة أولى ← لسه شغالة 50%', $a['status'] === 'running' && (int) $a['progress'] === 50 && $a['stage_label'] === 'نص الطريق');
$b = ce_job_step($jid);
t('خطوة تانية ← خلصت والحالة اتحفظت بين الخطوات', $b['status'] === 'done' && (int) $b['progress'] === 100 && (json_decode($b['result_json'], true)['n'] ?? 0) === 2);
t('التسوية: 8 محجوز − 3 اتصرف = 5 رجعوا', credits_balance($u) === $bal - 3, 'bal=' . credits_balance($u));

$bal = credits_balance($u);
$jt = ce_job_create($u, 'ce_test', ['mode' => 'throw'], ['credits' => 4, 'max_attempts' => 2]);
$jtid = (int) $jt['job']['id'];
$r1 = ce_job_step($jtid);
t('خطأ مؤقت ← بيتعاد بعد شوية (مش فشل)', $r1['status'] === 'running' && (int) $r1['attempts'] === 1 && $r1['run_after'] !== null);
t('قبل ميعاد الإعادة مابتتمسكش', ce_job_step($jtid) === null);
db_run('UPDATE ce_jobs SET run_after = NULL WHERE id = ?', [$jtid]);
$r2 = ce_job_step($jtid);
t('آخر محاولة فشلت ← فشل نهائي والكريدت رجع كله', $r2['status'] === 'failed' && credits_balance($u) === $bal, 'bal=' . credits_balance($u) . ' expected ' . $bal);

$jf = ce_job_create($u, 'ce_test', ['mode' => 'fail'], ['credits' => 2]);
$rf = ce_job_step((int) $jf['job']['id']);
t('فشل نهائي من الـ handler ← بيقف على طول ويرجّع الحجز', $rf['status'] === 'failed' && $rf['error'] === 'مش هينفع' && credits_balance($u) === $bal);

$jc = ce_job_create($u, 'ce_test', ['mode' => 'ok'], ['credits' => 3]);
t('العميل يلغي ← الكريدت يرجع', ce_job_cancel((int) $jc['job']['id'], $u) && credits_balance($u) === $bal && !ce_job_cancel((int) $jc['job']['id'], $u));
$other = tmp_user('other', 0);
$jo = ce_job_create($u, 'ce_test', ['mode' => 'ok']);
t('عميل تاني مايقدرش يلغي شغلانة مش بتاعته', !ce_job_cancel((int) $jo['job']['id'], $other));

$js = ce_job_create($u, 'ce_test', ['mode' => 'ok']);
db_run('UPDATE ce_jobs SET status = "running", lock_token = ?, locked_at = NOW() - INTERVAL 10 MINUTE WHERE id = ?', [str_repeat('a', 32), (int) $js['job']['id']]);
$rs = ce_job_step((int) $js['job']['id']);
t('قفل عدّى عليه 5 دقايق (العملية وقعت) ← بيتاخد تاني ويتحسب محاولة', $rs !== null && (int) $rs['attempts'] === 1);

/* ─── 3) قاعدة الكريدت ─── */
echo "قاعدة الكريدت\n";
require_once __DIR__ . '/../includes/usage.php';
set_setting('ce_margin_pct', '100'); set_setting('ce_egp_per_credit', '1.5');
$fx = usage_fx_rate();
t('الكريدت = $ × سعر الدولار × (1+الهامش) ÷ قيمة الكريدت', ce_usd_to_credits(0.03) === (int) ceil(0.03 * $fx * 2 / 1.5), ce_usd_to_credits(0.03) . ' fx=' . $fx);
t('صفر تكلفة = صفر كريدت', ce_usd_to_credits(0) === 0);

/* ─── 4) Brand DNA ─── */
echo "Brand DNA\n";
$d = tmp_user('dna', 0);
db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$d, 'عيادة اختبار', 'عيادة أسنان للعيلة في طنطا']);
$s1id = (int) db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$d, 'تبييض', 'high']);
db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$d, 'تقويم', 'medium']);
$foreign = (int) (db_one('SELECT id FROM brand_services WHERE user_id <> ? ORDER BY id LIMIT 1', [$d])['id'] ?? 999999);
$rules = ce_dna_from_kit($d);
t('البناء بالقواعد بياخد الخدمات من الهوية', count($rules['services']) === 2 && $rules['services'][0]['name'] !== '');
$c = ce_dna_clean($d, ['services' => [['id' => $foreign, 'name' => 'خدمة حد تاني'], ['id' => $s1id, 'name' => 'تبييض', 'priority' => 'xx']]] + $rules);
t('التنضيف بيشيل خدمات عميل تاني وأي قيمة مش من القايمة', count($c['services']) === 1 && $c['services'][0]['priority'] === 'medium');
set_setting('ce_dna_max_tokens', '400');
$big = $c;
$big['winning_patterns'] = array_map(fn($i) => "نمط رقم $i " . str_repeat('طويل جدًا ', 8), range(1, 5));
$big['proof_assets'] = array_map(fn($i) => "إثبات رقم $i " . str_repeat('طويل ', 14), range(1, 5));
$big['positioning'] = str_repeat('تموضع ', 40);
$trim = ce_dna_trim(ce_dna_clean($d, $big));
t('التقصير بيقرّب الملف من الميزانية', ce_tokens_est(json_encode($trim, JSON_UNESCAPED_UNICODE)) < ce_tokens_est(json_encode(ce_dna_clean($d, $big), JSON_UNESCAPED_UNICODE)) && count($trim['winning_patterns']) <= 3);
set_setting('ce_dna_max_tokens', '1200');

$jb = ce_dna_build($d);
for ($i = 0; $i < 5; $i++) { $x = ce_job_step((int) $jb['job']['id']); if ($x && !in_array($x['status'], ['queued', 'running'], true)) break; }
$st = ce_dna_state($d);
t('شغلانة البناء خلصت والملف اتحفظ', ($x['status'] ?? '') === 'done' && $st['exists'] && !$st['stale'] && $st['tokens'] > 0, json_encode($st));
$again = ce_dna_build($d);
t('بناء تاني لنفس نسخة الهوية = نفس الشغلانة', !empty($again['existing']) && (int) $again['job']['id'] === (int) $jb['job']['id']);
ce_dna_user_save($d, ['positioning' => 'تموضع كتبه العميل بإيده']);
t('تعديل العميل بيتعلّم «يدوي»', in_array('positioning', ce_dna_state($d)['edited'], true));
$jb2 = ce_dna_build($d, true);
for ($i = 0; $i < 5; $i++) { $x = ce_job_step((int) $jb2['job']['id']); if ($x && !in_array($x['status'], ['queued', 'running'], true)) break; }
t('إعادة البناء مابتغيّرش الحقول اللي العميل عدّلها', (ce_dna_get($d)['positioning'] ?? '') === 'تموضع كتبه العميل بإيده');
ce_dna_user_save($d, ['usp' => ['ميزة للتجربة']]);
ce_dna_undo($d);
t('الرجوع للنسخة اللي قبلها', !in_array('ميزة للتجربة', ce_dna_get($d)['usp'] ?? [], true));
t('الـ prefix ثابت حرفيًا بين نداءين (عشان الـ caching)', ce_dna_prefix($d) === ce_dna_prefix($d) && str_starts_with(ce_dna_prefix($d), '## BRAND_PROFILE'));
db_run('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$d, 'خدمة جديدة', 'low']);
t('الهوية اتغيرت ← الملف «محتاج تحديث»', ce_dna_state($d)['stale'] === true);
$jb3 = ce_dna_build($d);
for ($i = 0; $i < 5; $i++) { $x = ce_job_step((int) $jb3['job']['id']); if ($x && !in_array($x['status'], ['queued', 'running'], true)) break; }
t('التحديث بيضيف الخدمة الجديدة', count(ce_dna_get($d)['services']) === 3);

/* ─── 5) البريف والتقدير ─── */
echo "البريف والتقدير\n";
[, $e0] = ce_brief_clean($d, []);
t('بريف فاضي ← أخطاء للخطوات المطلوبة', isset($e0['goal'], $e0['services'], $e0['formats'], $e0['channel'], $e0['boldness']));
$svc = array_map(fn($s) => (int) $s['id'], ce_wizard_options($d)['services']);
[, $e1] = ce_brief_clean($d, ['goal' => 'sales', 'service_ids' => $svc, 'formats' => ['post' => 3], 'channel' => 'call', 'boldness' => 'safe']);
t('أكتر من خدمتين ← مرفوض', isset($e1['services']));
[, $e2] = ce_brief_clean($d, ['goal' => 'offer', 'service_ids' => [$svc[0]], 'formats' => ['post' => 3], 'channel' => 'call', 'boldness' => 'safe', 'offer' => ['type' => 'percent', 'value' => 0]]);
t('هدف العرض من غير قيمة ← مرفوض', isset($e2['offer']));
[$b3, $e3] = ce_brief_clean($d, ['goal' => 'sales', 'service_ids' => [$svc[0], $foreign], 'formats' => ['post' => 4, 'carousel' => 2, 'reel' => 1], 'channel' => 'call', 'boldness' => 'safe', 'days' => 999]);
t('بريف سليم · الخدمة الغريبة اتشالت · مدة غلط ← 30', !$e3 && $b3['service_ids'] === [$svc[0]] && $b3['days'] === 30, json_encode($e3, JSON_UNESCAPED_UNICODE));
$est = ce_estimate($b3);
$w = array_values(array_filter($est['stages'], fn($s) => $s['key'] === 'ce_writer'))[0];
// الصور (المرحلة G): بوست = صورة · كاروسيل = كل الشرايح (6 في المتوسط) · ريلز = غلاف لو العميل اختاره ← 4 + 12 (+1)
$saveCar = get_setting('ce_carousel_ai_images', null);
set_setting('ce_carousel_ai_images', '0');
$est = ce_estimate($b3);
$estR = ce_estimate(['reel_cover' => 1] + $b3);
if ($saveCar !== null) set_setting('ce_carousel_ai_images', (string) $saveCar);
t('التقدير: 7 قطع · الكاتب × 1.4 · 16 صورة (+ غلاف الريلز لو اختاره = 17)', $est['pieces'] === 7 && abs($w['count'] - 9.8) < 0.01
    && array_values(array_filter($est['stages'], fn($s) => $s['key'] === 'ce_design_prompt'))[0]['count'] == 16 && $est['images']['count'] === 16 && $estR['images']['count'] === 17,
    json_encode([$est['images']['count'], $estR['images']['count']]));
t('التقدير من الافتراضي لو مفيش سجل', in_array($w['source'], ['default', 'history'], true) && $est['credits'] >= 7);
$hist = [];
for ($i = 0; $i < 5; $i++) $hist[] = (int) db_insert('INSERT INTO ai_runs (run_key, task, status, tokens_in, tokens_out, sandbox) VALUES (?, "ce_vision_qa", "ok", 1000, 100, 0)', [bin2hex(random_bytes(8))]);
$hv = ce_stage_history('ce_vision_qa');
$est2 = ce_estimate($b3);
$vq = array_values(array_filter($est2['stages'], fn($s) => $s['key'] === 'ce_vision_qa'))[0];
t('5 تشغيلات ناجحة ← التقدير من السجل', $vq['source'] === 'history' && $hv !== null, json_encode($vq));
db_run('DELETE FROM ai_runs WHERE id IN (' . implode(',', $hist) . ')');
$pub = ce_estimate_public($est);
t('تقدير العميل مافيهوش دولار ولا أسماء موديلات', !isset($pub['usd']) && !str_contains(json_encode($pub), 'model'));
$sv = ce_campaign_save($d, ['goal' => 'sales', 'service_ids' => [$svc[0]], 'formats' => ['post' => 2], 'channel' => 'call', 'boldness' => 'safe']);
t('حفظ البريف ← حملة جاهزة بالتقدير', $sv['ok'] && $sv['campaign']['status'] === 'ready' && (int) $sv['campaign']['est_credits'] > 0);
$sp = ce_campaign_save($d, ['goal' => 'sales', 'partial' => 1]);
t('حفظ في النص (ناقص) ← مسودة', $sp['ok'] && $sp['campaign']['status'] === 'draft');

/* ─── 6) الـ API ─── */
echo "الـ API\n";
$s = login($base, 'sub@test.local');
[$c1, $b1] = http('GET', "$base/public/api/engine.php?action=home", [], $s[0]);
t('العميل المفتوح له ← home شغال', $c1 === 200 && (json_decode($b1, true)['ok'] ?? false), (string) $c1);
[$c2] = http('GET', "$base/public/api/engine.php?action=dna_build", [], $s[0]);
t('dna_build بـ GET ← 405', $c2 === 405, (string) $c2);
$jx = ce_job_create($d, 'brand_dna', [], []);
[$c3, $r3] = api_post($base, $s, 'job_step', ['id' => (int) $jx['job']['id']]);
t('شغلانة عميل تاني ← 404', $c3 === 404, (string) $c3);
[, $wz] = http('GET', "$base/public/api/engine.php?action=wizard", [], $s[0]);
$per = array_map(fn($p) => $p['id'], json_decode($wz, true)['options']['personas'] ?? []);
[$c4, $r4] = api_post($base, $s, 'estimate', ['brief' => ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => array_slice($per, 0, 1), 'formats' => ['post' => 3], 'channel' => 'whatsapp', 'boldness' => 'medium']]);
t('التقدير من الـ API', $c4 === 200 && ($r4['estimate']['pieces'] ?? 0) === 3 && !isset($r4['estimate']['usd']), json_encode($r4, JSON_UNESCAPED_UNICODE));
$f = login($base, 'free@test.local');
[$c5, $b5] = http('GET', "$base/public/api/engine.php?action=home", [], $f[0]);
t('عميل مش مفتوح له المحرك ← 403', $c5 === 403 && (json_decode($b5, true)['code'] ?? '') === 'feature', (string) $c5);
[$c6] = http('GET', "$base/public/engine.php", [], $f[0]);
t('صفحة المحرك لعميل مقفول له ← تحويل', $c6 === 302, (string) $c6);

/* ─── 7) الـ worker ─── */
echo "الـ worker (cron)\n";
$php = PHP_BINARY;
$out = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/../cron/ce-worker.php') . ' cron_secret=wrong 2>&1');
t('من غير السر الصح ← مرفوض', str_contains($out, 'Forbidden'));
set_setting('cron_secret', 'ced-test-secret');
db_run('UPDATE ce_jobs SET status = "cancelled" WHERE kind = "ce_test" AND status IN ("queued","running")'); // الـ handler التجريبي مش متسجّل في عملية الـ cron
$jw = ce_job_create($d, 'brand_dna', [], ['idem_key' => 'cedtest:W:' . $d]);
$out = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/../cron/ce-worker.php') . ' cron_secret=ced-test-secret 2>&1');
$jw2 = ce_job_get((int) $jw['job']['id']);
t('الـ cron شغّل الشغلانة لحد ما خلصت', $jw2['status'] === 'done' && str_contains($out, 'step'), trim($out) . ' status=' . $jw2['status']);
t('الـ cron سجّل آخر دورة', ce_worker_alive());

/* ─── التنضيف ─── */
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM settings WHERE setting_key = "ce_worker_last_run"');
foreach ([$u, $other, $d] as $x) {
    db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$x]);
    try { account_purge($x); } catch (\Throwable $e) { echo '  (تنضيف: ' . $e->getMessage() . ")\n"; }
}
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CE-D%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
db_run('DELETE FROM rate_limits');
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
