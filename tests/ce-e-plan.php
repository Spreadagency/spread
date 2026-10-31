<?php
/**
 * Spread AI — اختبارات محرك المحتوى · المرحلة E (خطة المحتوى + بوابة الخطة)
 * للتطوير المحلي بس: بيعمل بيانات تجريبية ويمسحها في الآخر — ماتشغّلوش على السيرفر الحقيقي.
 *
 *   php tests/ce-e-plan.php http://localhost:8080
 *
 * محتاج: ترحيلات 2026_ce_engine و 2026_ce_plan · حساب sub@test.local (الباسورد Test12345!) مفتوح له المحرك · الـ AI الوهمي شغال.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/ce-plan.php';
if (!ce_ready() || !ce_angles()) exit("شغّل ترحيلات 2026_ce_engine و 2026_ce_plan الأول\n");

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
    $jar = tempnam(sys_get_temp_dir(), 'cee');
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
function run_job(int $id, int $max = 12): ?array
{
    $j = null;
    for ($i = 0; $i < $max; $i++) {
        $x = ce_job_step($id);
        if ($x) $j = $x;
        if ($j && !in_array($j['status'], ['queued', 'running'], true)) break;
    }
    return $j;
}
db_run('DELETE FROM rate_limits');
$saved = [];
foreach (['ce_funnel_mix', 'ce_plan_weights', 'ce_plan_threshold', 'ce_plan_max_rounds'] as $k) $saved[$k] = get_setting($k, null);

/* ─── تجهيز عميل تجريبي بهوية: 3 خدمات · 2 عملاء ─── */
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())',
    ['CE-E test', 'cee-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('x', PASSWORD_DEFAULT)]);
credits_add($uid, 60, 'CE-E test credits');
db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$uid, 'عيادة الاختبار', 'عيادة أسنان']);
$svc = [];
foreach ([['تبييض', 'high'], ['تقويم', 'medium'], ['زراعة', 'low']] as [$n, $p]) $svc[] = (int) db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$uid, $n, $p]);
$per = [];
foreach (['أم مشغولة', 'شاب'] as $n) $per[] = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")',
    [$uid, $n, json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
$jd = ce_dna_build($uid);
run_job((int) $jd['job']['id']);
$mkCampaign = function (array $brief) use ($uid): int {
    $r = ce_campaign_save($uid, $brief);
    if (!$r['ok']) { echo "  (حملة: " . json_encode($r['errors'], JSON_UNESCAPED_UNICODE) . ")\n"; return 0; }
    return (int) $r['campaign']['id'];
};
$brief = ['goal' => 'sales', 'all_services' => 1, 'persona_ids' => $per, 'formats' => ['post' => 5, 'carousel' => 3, 'reel' => 2], 'channel' => 'call', 'boldness' => 'medium'];

/* ─── 1) نسب المراحل ─── */
echo "نسب المراحل\n";
$fc = ce_funnel_counts(10, ['tofu' => 15, 'mofu' => 30, 'bofu' => 55]);
t('10 خانات بنسب العرض ← المجموع 10 وقريب من النسب', array_sum($fc) === 10 && $fc['mofu'] === 3 && in_array($fc['bofu'], [5, 6], true), json_encode($fc));
set_setting('ce_funnel_mix', json_encode(['sales' => [10, 20, 70]]));
t('الأدمن يغيّر نسب هدف', ce_funnel_mix('sales') === ['tofu' => 10, 'mofu' => 20, 'bofu' => 70]);
set_setting('ce_funnel_mix', json_encode(['sales' => [10, 20, 30]]));
t('نسب مجموعها مش 100 بتتجاهل', ce_funnel_mix('sales') === ['tofu' => 20, 'mofu' => 35, 'bofu' => 45]);
db_run('DELETE FROM settings WHERE setting_key = "ce_funnel_mix"');

/* ─── 2) البناء بالقواعد والتصليح ─── */
echo "القواعد في الكود\n";
$cid = $mkCampaign($brief);
db_run('UPDATE ce_campaigns SET start_date = ? WHERE id = ?', [date('Y-m-d', strtotime('+1 day')), $cid]);
$c = ce_campaign_get($cid, $uid);
$ctx = ce_plan_ctx($uid, $c);
t('سياق الخطة: 10 خانات · 3 خدمات · عميلين', $ctx['n'] === 10 && count($ctx['services']) === 3 && count($ctx['personas']) === 2);
$rules = ce_plan_build_rules($ctx);
$vr = ce_plan_validate($rules, $ctx);
t('الخطة بالقواعد بتعدّي كل القواعد الثابتة', $vr['hard'] === 0 && $vr['code']['coverage'] == 100, json_encode($vr['issues'], JSON_UNESCAPED_UNICODE));
$bad = array_map(fn($i) => ['funnel_stage' => $i < 5 ? 'bofu' : 'tofu', 'pillar' => 'education', 'angle' => 'faq', 'format' => 'post', 'cta_level' => $i === 0 ? 'hard' : 'soft', 'service_id' => $svc[0], 'persona_id' => 999999], range(0, 9));
$vb = ce_plan_validate(ce_plan_normalize($bad, $ctx), $ctx);
$rules0 = array_column($vb['issues'], 'rule');
t('التحقق بيمسك: عمود متكرر · زاوية متكررة · تغطية · مكان الـ CTA · الأشكال',
    count(array_intersect(['adjacent_pillar', 'adjacent_angle', 'service_coverage', 'persona_coverage', 'cta_position', 'formats'], $rules0)) === 6, implode(',', array_unique($rules0)));
$fixed = ce_plan_repair(ce_plan_normalize($bad, $ctx), $ctx);
$vf = ce_plan_validate($fixed, $ctx);
t('التصليح بيخلّي خطة الـ AI البايظة تعدّي القواعد', $vf['hard'] === 0, json_encode($vf['issues'], JSON_UNESCAPED_UNICODE));
$fmt = array_count_values(array_column($fixed, 'format'));
t('عدد الأشكال زي البريف بالظبط', ($fmt['post'] ?? 0) === 5 && ($fmt['carousel'] ?? 0) === 3 && ($fmt['reel'] ?? 0) === 2);
$tail = ce_plan_tail_start(10);
$hard = array_keys(array_filter($fixed, fn($s) => $s['cta_level'] === 'hard'));
t('الـ CTA القوي في آخر 30% بس — وموجود (هدف بيع)', $hard && min($hard) >= $tail, json_encode($hard));
$rank = ['tofu' => 0, 'mofu' => 1, 'bofu' => 2];
$ord = true;
for ($i = 1; $i < 10; $i++) if ($rank[$fixed[$i]['funnel']] < $rank[$fixed[$i - 1]['funnel']]) $ord = false;
t('الترتيب: وعي ← اهتمام ← قرار', $ord);
$lockedSlot = $fixed[4];
$lockedSlot['locked'] = true;
$lockedSlot['objective'] = 'خانة مقفولة من العميل';
$again = ce_plan_repair(ce_plan_normalize($bad, $ctx, [4 => $lockedSlot]), $ctx);
t('الخانة المقفولة بتفضل في مكانها زي ما هي', $again[4]['objective'] === 'خانة مقفولة من العميل' && $again[4]['angle'] === $lockedSlot['angle'] && !empty($again[4]['locked']));

/* ─── 3) الدرجة ─── */
echo "الدرجة\n";
$code = ['balance' => 90, 'non_repetition' => 100, 'coverage' => 100, 'sequence' => 100, 'cta' => 100];
$cmb = ce_plan_combine($code, ['balance' => 95, 'non_repetition' => 70, 'coverage' => 100, 'sequence' => 100, 'cta' => 100, 'goal_fit' => 80]);
t('لكل معيار: الأقل بين الكود والناقد', $cmb['dims']['balance'] == 90 && $cmb['dims']['non_repetition'] == 70 && $cmb['dims']['goal_fit'] == 80);
t('الدرجة بالأوزان (15·20·20·15·15·15)', abs($cmb['score'] - round((90 * 15 + 70 * 20 + 100 * 20 + 100 * 15 + 100 * 15 + 80 * 15) / 100, 1)) < 0.01, (string) $cmb['score']);
$noc = ce_plan_combine($code, null);
t('من غير ناقد: مناسبة الهدف = التوازن', $noc['dims']['goal_fit'] == 90);

/* ─── 4) الشغلانة + البوابة ─── */
echo "الشغلانة والبوابة\n";
$bal = credits_balance($uid);
$st = ce_plan_start($uid, $cid);
$st2 = ce_plan_start($uid, $cid);
t('ابدأ الحملة ← حجز + شغلانة · الضغط مرتين = نفس الشغلانة', $st['ok'] && !empty($st2['existing']) && (int) $st2['job']['id'] === (int) $st['job']['id'] && credits_balance($uid) < $bal);
t('الحملة بقت «بنعمل الخطة»', ce_campaign_get($cid, $uid)['status'] === 'planning');
$j = run_job((int) $st['job']['id']);
$c = ce_campaign_get($cid, $uid);
$view = ce_plan_view($uid, $c);
t('الخطة خلصت وعدّت البوابة من أول جولة (ناقد عالي)', $j['status'] === 'done' && $c['status'] === 'review' && (int) $c['plan_passed'] === 1 && $view['hard'] === 0 && count($view['slots']) === 10,
    json_encode(['st' => $j['status'] ?? null, 'c' => $c['status'], 'score' => $c['plan_score'], 'issues' => $view['issues']], JSON_UNESCAPED_UNICODE));
t('التقييم اتسجّل بدرجات الكود والناقد', $view['eval'] && $view['eval']['round'] === 1 && isset($view['eval']['scores']['coverage']['code'], $view['eval']['scores']['coverage']['critic']));
$h = db_one('SELECT * FROM ce_credit_holds WHERE id = ?', [(int) $j['hold_id']]);
t('حجز الخطة اتسوّى (اتصرف الفعلي والباقي رجع)', $h['status'] === 'settled' && (int) $h['used'] >= 1 && credits_balance($uid) === $bal - (int) $h['used'], json_encode($h));

// تعديل العميل يكسر قاعدة ← الاعتماد ممنوع
$slots = $view['slots'];
$payload = array_map(fn($s) => ['id' => $s['id'], 'pillar' => $s['pillar'], 'angle' => $s['angle'], 'locked' => $s['locked']], $slots);
$payload[1]['pillar'] = $payload[0]['pillar'];
$sv = ce_plan_save($uid, $cid, $payload);
t('تعديل بيكسر قاعدة ← بيتحفظ بس بيظهر المخالفة', $sv['ok'] && $sv['view']['hard'] > 0 && !$sv['view']['can_approve']);
t('الاعتماد ممنوع والقواعد متكسّرة', !ce_plan_approve($uid, $cid)['ok']);
$payload[1]['pillar'] = $slots[1]['pillar'];
$payload[1]['service_id'] = 999999;
$payload = array_reverse($payload);
$payload = array_reverse($payload);
$sv2 = ce_plan_save($uid, $cid, $payload);
t('رجّع التعديل ← القواعد سليمة · خدمة غريبة اتجاهلت', $sv2['ok'] && $sv2['view']['hard'] === 0 && !in_array(999999, array_column($sv2['view']['slots'], 'service_id'), true), json_encode([$sv2['ok'], $sv2['error'] ?? null, $sv2['view']['issues'] ?? null], JSON_UNESCAPED_UNICODE));
t('ids ناقصة أو غريبة ← مرفوض', !ce_plan_save($uid, $cid, array_slice($payload, 1))['ok']);
// ترتيب جديد بالسحب
$moved = $payload;
[$moved[2], $moved[3]] = [$moved[3], $moved[2]];
$sv3 = ce_plan_save($uid, $cid, $moved);
t('ترتيب جديد بيتحفظ والتواريخ بتتحسب من المكان', $sv3['ok'] && $sv3['view']['slots'][2]['id'] === $moved[2]['id'] && $sv3['view']['slots'][2]['date'] <= $sv3['view']['slots'][3]['date'], json_encode([$sv3['ok'], $sv3['error'] ?? null], JSON_UNESCAPED_UNICODE));
ce_plan_save($uid, $cid, $payload);

// قفل خانة + إعادة بناء
$lock = $payload;
$lock[6]['locked'] = 1;
ce_plan_save($uid, $cid, $lock);
$before = ce_plan_slots_load($cid)[6];
$rb = ce_plan_start($uid, $cid, 'full');
run_job((int) $rb['job']['id']);
$after = ce_plan_slots_load($cid)[6];
t('إعادة البناء بتسيب الخانة المقفولة زي ما هي', $after['locked'] && $after['angle'] === $before['angle'] && $after['objective'] === $before['objective'] && $after['service_id'] === $before['service_id']);

// تقييم تاني بس
$evBefore = (int) db_one('SELECT COUNT(*) n FROM ce_plan_evals WHERE campaign_id = ?', [$cid])['n'];
$slotsBefore = json_encode(ce_plan_slots_load($cid));
$rs = ce_plan_start($uid, $cid, 'gate_only');
run_job((int) $rs['job']['id']);
t('«قيّم تاني» ← تقييم جديد من غير ما الخطة تتغير', (int) db_one('SELECT COUNT(*) n FROM ce_plan_evals WHERE campaign_id = ?', [$cid])['n'] === $evBefore + 1
    && json_encode(ce_plan_slots_load($cid)) === $slotsBefore);
$ap = ce_plan_approve($uid, $cid);
t('الاعتماد ← «الخطة معتمدة»', $ap['ok'] && ce_campaign_get($cid, $uid)['status'] === 'plan_ok');
t('مينفعش تبدأ تاني بعد الاعتماد', !ce_plan_start($uid, $cid)['ok']);

/* ─── 5) الناقد بيدّي درجة واطية ← جولات تحسين ─── */
echo "جولات التحسين\n";
set_setting('ce_plan_max_rounds', '2');
$cid2 = $mkCampaign(['goal' => 'other', 'goal_other' => 'CRITIC_LOW اختبار'] + $brief);
$s2 = ce_plan_start($uid, $cid2);
$j2 = run_job((int) $s2['job']['id'], 20);
$n2 = (int) db_one('SELECT COUNT(*) n FROM ce_plan_evals WHERE campaign_id = ?', [$cid2])['n'];
$c2 = ce_campaign_get($cid2, $uid);
t('أقل من 80 ← اتبنت 3 مرات (أول مرة + جولتين)', $j2['status'] === 'done' && $n2 === 3, "evals=$n2 status=" . ($j2['status'] ?? ''));
t('آخر جولات اتبعت فيها ملاحظات الناقد للبناء', str_contains((string) ($j2['state_json'] ?? ''), 'استخدم قصة عميل'));
t('الخطة اتحفظت بس «مش عادية البوابة» والاعتماد ممنوع', $c2['status'] === 'review' && (int) $c2['plan_passed'] === 0 && !ce_plan_approve($uid, $cid2)['ok']);

/* ─── 6) الرصيد ─── */
echo "الرصيد\n";
$poor = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())', ['CE-E poor', 'cee-p-' . bin2hex(random_bytes(3)) . '@test.local', 'x']);
db_insert('INSERT INTO ce_brand_dna (user_id, dna_json, tokens_est) VALUES (?,?,1)', [$poor, json_encode(ce_dna_get($uid))]);
db_run('INSERT INTO ce_campaigns (user_id, title, status, goal, brief_json, estimate_json, est_credits) SELECT ?, title, "ready", goal, brief_json, estimate_json, est_credits FROM ce_campaigns WHERE id = ?', [$poor, $cid2]);
$pc = (int) db()->lastInsertId();
$sp = ce_plan_start($poor, $pc);
t('رصيد مش كفاية ← مفيش شغلانة والحملة ترجع «جاهزة»', !$sp['ok'] && ($sp['code'] ?? '') === 'credits' && ce_campaign_get($pc, $poor)['status'] === 'ready', json_encode($sp, JSON_UNESCAPED_UNICODE));

/* ─── 7) الـ API ─── */
echo "الـ API\n";
$s = login($base, 'sub@test.local');
[$a1] = http('GET', "$base/public/api/engine.php?action=plan&id=$cid", [], $s[0]);
t('خطة حملة عميل تاني ← 404', $a1 === 404, (string) $a1);
[$a2] = http('GET', "$base/public/api/engine.php?action=campaign_start&id=$cid", [], $s[0]);
t('ابدأ الحملة بـ GET ← 405', $a2 === 405, (string) $a2);
[$a3, $r3] = api_post($base, $s, 'plan_approve', ['id' => $cid]);
t('اعتماد حملة عميل تاني ← 404', $a3 === 404, (string) $a3);

/* ─── 8) المسح ─── */
echo "مسح الحساب\n";
account_purge($uid);
t('مسح الحساب بيمسح الخطط والتقييمات', !db_one('SELECT id FROM ce_slots WHERE campaign_id IN (?, ?)', [$cid, $cid2]) && !db_one('SELECT id FROM ce_plan_evals WHERE campaign_id IN (?, ?)', [$cid, $cid2]));

/* ─── التنضيف ─── */
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM ce_jobs WHERE user_id IN (?, ?)', [$uid, $poor]);
try { account_purge($poor); } catch (\Throwable $e) {}
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CE-E%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
db_run('DELETE FROM rate_limits');
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
