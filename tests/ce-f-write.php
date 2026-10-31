<?php
/**
 * Spread AI — اختبارات محرك المحتوى · المرحلة F (الأفكار ← الاختيار ← الكتابة ← الجودة ← المراجعة النهائية)
 * للتطوير المحلي بس: بيعمل بيانات تجريبية ويمسحها في الآخر — ماتشغّلوش على السيرفر الحقيقي.
 *
 *   php tests/ce-f-write.php http://localhost:8080
 *
 * محتاج: ترحيلات 2026_ce_engine و 2026_ce_plan و 2026_ce_content · حساب sub@test.local (الباسورد Test12345!) · الـ AI الوهمي شغال.
 * علامات الـ AI الوهمي (في استثناءات الحملة): REVISE_ME (أول نسخة ضعيفة) · MANUAL_ME (دايمًا ضعيفة) · DUP_ME (نفس الأفكار) · BADFORMAT (أول رد ناقص)
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/ce-write.php';
// الاختبار ده بيتأكد من حساب التكلفة الفعلية (ce_billing_mode = actual) — الحساب الثابت ليه اختباره في tests/pk1-billing.php
$__billMode = get_setting('ce_billing_mode', null);
set_setting('ce_billing_mode', 'actual');
register_shutdown_function(function () use ($__billMode) { $__billMode === null ? db_run('DELETE FROM settings WHERE setting_key = "ce_billing_mode"') : set_setting('ce_billing_mode', (string) $__billMode); });
if (!ce_ready() || !ce_angles() || !ce_write_ready()) exit("شغّل ترحيلات 2026_ce_engine و 2026_ce_plan و 2026_ce_content الأول\n");

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
    $jar = tempnam(sys_get_temp_dir(), 'cef');
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
function run_job(int $id, int $max = 120): ?array
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
foreach (['ce_qa_weights', 'ce_qa_pass_total', 'ce_qa_max_rounds', 'ce_critic_other_provider'] as $k) $saved[$k] = get_setting($k, null);

/* ─── تجهيز عميل تجريبي بهوية: خدمتين · عميلين ─── */
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())',
    ['CE-F test', 'cef-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('x', PASSWORD_DEFAULT)]);
credits_add($uid, 200, 'CE-F test credits');
db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$uid, 'عيادة الاختبار', 'عيادة أسنان']);
foreach ([['تبييض', 'high'], ['تقويم', 'medium']] as [$n, $p]) db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$uid, $n, $p]);
$per = [];
foreach (['أم مشغولة', 'شاب'] as $n) $per[] = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")',
    [$uid, $n, json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
$jd = ce_dna_build($uid);
run_job((int) $jd['job']['id']);

/** حملة جاهزة للكتابة: بريف ← خطة ← اعتماد */
$planned = function (array $formats, array $excl = []) use ($uid, $per): int {
    $r = ce_campaign_save($uid, ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => $per, 'formats' => $formats, 'channel' => 'whatsapp', 'boldness' => 'medium', 'exclusions' => $excl]);
    if (!$r['ok']) { echo '  (حملة: ' . json_encode($r['errors'], JSON_UNESCAPED_UNICODE) . ")\n"; return 0; }
    $cid = (int) $r['campaign']['id'];
    $s = ce_plan_start($uid, $cid);
    run_job((int) $s['job']['id'], 20);
    // الحملات الصغيرة (خانة أو اتنين) درجة توازنها واطية بطبيعتها — الاختبار هنا للكتابة مش لبوابة الخطة
    if (!ce_plan_approve($uid, $cid)['ok']) db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$cid]);
    return $cid;
};
$content = fn(int $slotId) => db_one('SELECT * FROM ce_contents WHERE slot_id = ?', [$slotId]);

/* ─── 1) منع التكرار ─── */
echo "منع التكرار\n";
t('نفس الجملة بالتشكيل والهمزات = 1', ce_similarity('هو سِنانك بتوجعك بالليل؟', 'هو سنانك بتوجعك بالليل') == 1.0);
t('جملتين مختلفتين < 0.5', ce_similarity('هو التقويم للكبار كمان؟', 'إمتى الزراعة محتاجة جراحة؟') < 0.5);
t('حد الرفض الافتراضي 0.85', ce_qa_rules()['dedup'] == 0.85);

/* ─── 2) التحقق من الشكل ─── */
echo "التحقق من شكل المحتوى\n";
$c0 = $planned(['post' => 1]);
$ctx0 = ce_plan_ctx($uid, ce_campaign_get($c0, $uid));
$br = ['headline' => 'واحد اتنين تلاتة أربعة خمسة ستة سبعة تمانية تسعة'];
[$b, $err] = ce_content_check(['hooks' => ['أ', 'ب'], 'body' => 'نص', 'cta' => 'احجز', 'design_brief' => $br], 'post', $ctx0, $ctx0['brief']);
t('بوست بـ 2 Hooks ← خطأ', (bool) array_filter($err, fn($e) => str_contains($e, '3 Hooks')), json_encode($err, JSON_UNESCAPED_UNICODE));
t('العنوان بيتقصّ لـ 7 كلمات · مقاس البوست 1:1 · كل حقول الـ brief موجودة',
    count(explode(' ', $b['design_brief']['headline'])) === 7 && $b['design_brief']['aspect_ratio'] === '1:1' && !array_diff(CE_BRIEF_FIELDS, array_keys($b['design_brief'])));
[, $err] = ce_content_check(['slides' => [['text' => 'أ'], ['text' => 'ب'], ['text' => 'ج']], 'cta' => 'x', 'design_brief' => []], 'carousel', $ctx0, $ctx0['brief']);
t('كاروسيل 3 شرائح ← خطأ', (bool) array_filter($err, fn($e) => str_contains($e, '5 لـ 8')));
[$b, $err] = ce_content_check(['scenes' => [['time' => '0-2s', 'visual' => 'أ'], ['time' => '2-60s', 'voiceover' => 'ب']], 'cta' => 'x', 'design_brief' => []], 'reel', $ctx0, $ctx0['brief']);
t('ريلز 60 ثانية ← خطأ · المقاس 9:16', (bool) array_filter($err, fn($e) => str_contains($e, '45')) && $b['design_brief']['aspect_ratio'] === '9:16');
$fl = ce_content_flags(['body' => 'النتيجة مضمون 100% معانا'], $ctx0, ['exclusions' => ['الأسعار']]);
$fl2 = ce_content_flags(['body' => 'شوف الأسعار عندنا'], $ctx0, ['exclusions' => ['الأسعار']]);
t('الكود بيمسك الوعد المضمون واستثناءات الحملة', $fl && $fl2 && str_contains($fl2[0], 'الأسعار'), json_encode([$fl, $fl2], JSON_UNESCAPED_UNICODE));

/* ─── 3) الدرجة والحكم ─── */
echo "الدرجة والحكم\n";
$crit = fn(int $all, array $over = []) => ['criteria' => array_map(fn($k) => ['score' => $over[$k] ?? $all, 'evidence' => 'ابتسامتك تستاهل', 'fix' => 'صلّح'], array_combine(array_keys(CE_QA_WEIGHTS), array_keys(CE_QA_WEIGHTS))), 'verdict' => 'PASS'];
$body = ['hooks' => ['ابتسامتك تستاهل']];
$q = ce_qa_score($crit(90), $body, []);
t('كله 90 ← PASS بإجمالي 90', $q['verdict'] === 'PASS' && $q['total'] == 90 && $q['criteria']['hook']['evidence_ok']);
$q = ce_qa_score($crit(95, ['trust' => 65]), $body, []);
t('إجمالي عالي بس معيار < 70 ← REVISE', $q['verdict'] === 'REVISE' && $q['total'] >= 85, (string) $q['total']);
$q = ce_qa_score($crit(95), $body, ['فيه وعد ممنوع']);
t('مخالفة من الكود ← REVISE حتى لو الدرجات عالية', $q['verdict'] === 'REVISE');
$q = ce_qa_score(['criteria' => ['hook' => ['score' => 90, 'evidence' => 'كلام مش موجود خالص']]] + $crit(90), $body, []);
t('اقتباس مش موجود في المحتوى بيتعلّم', !$q['criteria']['hook']['evidence_ok']);
t('الإصلاحات للمعايير < 80 بس', count(ce_qa_fixes(ce_qa_score($crit(90, ['hook' => 60, 'value' => 75]), $body, []))) === 2);
set_setting('ce_qa_weights', json_encode(['hook' => 50] + array_fill_keys(array_keys(CE_QA_WEIGHTS), 1)));
t('أوزان مجموعها مش 100 بتتجاهل', ce_qa_weights() === CE_QA_WEIGHTS);
db_run('DELETE FROM settings WHERE setting_key = "ce_qa_weights"');

/* ─── 4) الحملة كاملة ─── */
echo "كتابة الحملة كاملة\n";
$cid = $planned(['post' => 2, 'carousel' => 1, 'reel' => 1]);
$c = ce_campaign_get($cid, $uid);
t('الخطة معتمدة وفيها 4 خانات', $c['status'] === 'plan_ok' && count(ce_plan_slots_load($cid)) === 4, $c['status']);
$bal = credits_balance($uid);
$need = ce_write_hold_credits($c);
$ws = ce_write_start($uid, $cid);
$ws2 = ce_write_start($uid, $cid);
t('ابدأ الكتابة ← حجز + شغلانة · الضغط مرتين = نفس الشغلانة', $ws['ok'] && !empty($ws2['existing']) && (int) $ws2['job']['id'] === (int) $ws['job']['id'] && credits_balance($uid) === $bal - $need,
    json_encode([$ws['error'] ?? null, $need, $bal, credits_balance($uid)], JSON_UNESCAPED_UNICODE));
t('الحجز ≥ عدد القطع × الحد الأدنى', $need >= 4 * ce_setting_int('ce_min_credits_piece', 1));
$j = run_job((int) $ws['job']['id']);
$c = ce_campaign_get($cid, $uid);
$view = ce_write_view($uid, $c);
$st = array_count_values(array_map(fn($s) => $s['content']['status'] ?? '-', $view['slots']));
t('الشغلانة خلصت وكل القطع عدّت الجودة', $j['status'] === 'done' && ($st['pass'] ?? 0) === 4, json_encode(['job' => $j['status'] ?? null, 'err' => $j['error'] ?? null, 'st' => $st], JSON_UNESCAPED_UNICODE));
$ok3 = true; $sel = true;
foreach ($view['slots'] as $s) {
    $fresh = array_filter($s['ideas'], fn($i) => $i['round'] === 1);
    if (count($fresh) !== 3 || count(array_unique(array_column($fresh, 'trigger'))) !== 3) $ok3 = false;
    $picked = array_values(array_filter($s['ideas'], fn($i) => $i['selected']));
    if (count($picked) !== 1 || $picked[0]['score'] < max(array_column($s['ideas'], 'score'))) $sel = false;
}
t('3 أفكار لكل خانة من 3 دوافع مختلفة', $ok3);
t('فكرة واحدة مختارة — وهي الأعلى درجة', $sel);
$byFmt = [];
foreach ($view['slots'] as $s) $byFmt[$s['format']] = $s['content']['body'];
t('البوست: 3 Hooks + نص + CTA', count($byFmt['post']['hooks'] ?? []) === 3 && !empty($byFmt['post']['body']) && !empty($byFmt['post']['cta']));
t('الكاروسيل 5-8 شرائح · الريلز مشاهد بالوقت والصوت', count($byFmt['carousel']['slides'] ?? []) >= 5 && !empty($byFmt['reel']['scenes'][0]['time']) && !empty($byFmt['reel']['scenes'][0]['voiceover']));
t('Design Brief: عنوان ≤ 7 كلمات + ألوان + مقاس', count(preg_split('/\s+/u', $byFmt['post']['design_brief']['headline'])) <= 7 && $byFmt['post']['design_brief']['colors_hex'] && $byFmt['reel']['design_brief']['aspect_ratio'] === '9:16');
$qa = $view['slots'][0]['content']['qa'];
t('تقييم الجودة: 10 معايير بالاقتباس والدرجة + الإجمالي في الكود', count($qa['criteria']) === 10 && $qa['total'] == 92 && $qa['criteria']['hook']['evidence_ok'], json_encode($qa['total']));
t('نسخة واحدة لكل قطعة (من أول مرة)', (int) db_one('SELECT COUNT(*) n FROM ce_content_versions v JOIN ce_contents x ON x.id = v.content_id WHERE x.campaign_id = ?', [$cid])['n'] === 4);
$fq = $view['final'];
t('المراجعة النهائية: درجة + 6 معايير + 3 ملاحظات + العدّاد', $fq && $fq['score'] > 0 && count($fq['dims']) === 6 && count($fq['notes']) === 3 && $fq['pass'] === 4, json_encode($fq, JSON_UNESCAPED_UNICODE));
t('الحملة «خلصت»', $c['status'] === 'done');
$h = db_one('SELECT * FROM ce_credit_holds WHERE id = ?', [(int) $j['hold_id']]);
t('الحجز اتسوّى: الفعلي ≥ القطع × الحد الأدنى والباقي رجع', $h['status'] === 'settled' && (int) $h['used'] >= 4 && credits_balance($uid) === $bal - (int) $h['used'], json_encode($h));
t('مينفعش تبدأ الكتابة تاني', !ce_write_start($uid, $cid)['ok']);

/* ─── 5) تبديل الفكرة ─── */
echo "تبديل الفكرة\n";
$s0 = $view['slots'][0];
$alt = array_values(array_filter($s0['ideas'], fn($i) => !$i['selected'] && !$i['dup']))[0];
$bal = credits_balance($uid);
$sw = ce_write_slot($uid, $cid, $s0['id'], $alt['id']);
t('تبديل الفكرة ← شغلانة لخانة واحدة بحجز قطعة', $sw['ok'] && credits_balance($uid) === $bal - ce_write_hold_credits(ce_campaign_get($cid, $uid), 1), json_encode([$sw['error'] ?? null]));
t('مفيش تبديل تاني والكتابة شغالة', !ce_write_slot($uid, $cid, $s0['id'], $alt['id'])['ok']);
run_job((int) $sw['job']['id'], 20);
$x = $content($s0['id']);
t('الخانة اتكتبت بالفكرة الجديدة (نسخة 2) والمختارة اتنقلت', (int) $x['idea_id'] === $alt['id'] && (int) $x['version'] === 2 && $x['status'] === 'pass'
    && (int) db_one('SELECT selected FROM ce_ideas WHERE id = ?', [$alt['id']])['selected'] === 1 && (int) db_one('SELECT COUNT(*) n FROM ce_ideas WHERE slot_id = ? AND selected = 1', [$s0['id']])['n'] === 1);
t('الخانات التانية ماتغيرتش', (int) $content($view['slots'][1]['id'])['version'] === 1);
t('فكرة من خانة تانية ← مرفوض', !ce_write_slot($uid, $cid, $view['slots'][1]['id'], $alt['id'])['ok']);

/* ─── 6) التحسين ─── */
echo "جولات التحسين\n";
$cr = $planned(['post' => 1], ['REVISE_ME']);
$w = ce_write_start($uid, $cr);
$jr = run_job((int) $w['job']['id']);
$sr = ce_plan_slots_load($cr)[0];
$xr = $content($sr['id']);
$qr = json_decode((string) $xr['qa_json'], true);
$v1 = json_decode((string) db_one('SELECT qa_json FROM ce_content_versions WHERE content_id = ? AND version = 1', [(int) $xr['id']])['qa_json'], true);
t('أول نسخة ضعيفة + فيها استثناء الحملة ← اتحسّنت مرة وعدّت', $xr['status'] === 'pass' && (int) $xr['rounds'] === 1 && (int) $xr['version'] === 2 && $qr['verdict'] === 'PASS',
    json_encode(['st' => $xr['status'], 'r' => $xr['rounds'], 'v' => $xr['version'], 'err' => $jr['error'] ?? null], JSON_UNESCAPED_UNICODE));
t('النسخة الأولى اتسجّلت REVISE بمخالفة الكود', ($v1['verdict'] ?? '') === 'REVISE' && $v1['code_flags'], json_encode($v1['code_flags'] ?? null, JSON_UNESCAPED_UNICODE));

echo "مراجعة يدوية\n";
$cm = $planned(['post' => 1], ['MANUAL_ME']);
$w = ce_write_start($uid, $cm);
run_job((int) $w['job']['id']);
$sm = ce_plan_slots_load($cm)[0];
$xm = $content($sm['id']);
$best = db_one('SELECT MAX(qa_score) m FROM ce_content_versions WHERE content_id = ?', [(int) $xm['id']])['m'];
t('ضعيفة دايمًا ← «محتاجة مراجعة» بعد جولتين (3 نسخ)', $xm['status'] === 'manual' && (int) db_one('SELECT COUNT(*) n FROM ce_content_versions WHERE content_id = ?', [(int) $xm['id']])['n'] === 3, (string) $xm['status']);
t('بتتحفظ أحسن نسخة', (float) $xm['qa_score'] === (float) $best);
t('«اعتمدها زي ما هي» ← عدّت · ومرة تانية ← مرفوض', ce_content_accept($uid, $cm, $sm['id']) && $content($sm['id'])['status'] === 'pass' && !ce_content_accept($uid, $cm, $sm['id']));
t('خانة عميل تاني ← مرفوض', !ce_content_accept($uid + 99999, $cm, $sm['id']));

echo "إعادة الكتابة لما الشكل ناقص\n";
$cb = $planned(['post' => 1], ['BADFORMAT']);
$w = ce_write_start($uid, $cb);
run_job((int) $w['job']['id']);
$xb = $content(ce_plan_slots_load($cb)[0]['id']);
$bb = json_decode((string) $xb['body_json'], true);
t('أول رد بـ 2 Hooks ← اتعاد مرة بالخطأ واتقبل بـ 3 (نسخة 1)', count($bb['hooks'] ?? []) === 3 && (int) $xb['version'] === 1 && $xb['status'] === 'pass', json_encode($bb['hooks'] ?? null, JSON_UNESCAPED_UNICODE));

echo "الأفكار المكررة\n";
$cd = $planned(['post' => 2], ['DUP_ME']);
$w = ce_write_start($uid, $cd);
run_job((int) $w['job']['id']);
$sd = ce_plan_slots_load($cd);
$d2 = db_one('SELECT COUNT(*) n, SUM(is_dup) d, MAX(round) r FROM ce_ideas WHERE slot_id = ?', [$sd[1]['id']]);
t('الخانة التانية: الأفكار شبه الأولى اترفضت وطلبنا أفكار جديدة', (int) $d2['d'] >= 3 && (int) $d2['r'] === 2, json_encode($d2));
t('ولسه اتكتبت (بأحسن المتاح)', in_array($content($sd[1]['id'])['status'], ['pass', 'manual'], true));

/* ─── 7) الإلغاء ─── */
echo "الإلغاء في النص\n";
$cc = $planned(['post' => 3]);
$bal = credits_balance($uid);
$w = ce_write_start($uid, $cc);
for ($i = 0; $i < 6; $i++) ce_job_step((int) $w['job']['id']);   // خانة واحدة تقريبًا
$jst = json_decode((string) db_one('SELECT state_json FROM ce_jobs WHERE id = ?', [(int) $w['job']['id']])['state_json'], true);
ce_job_cancel((int) $w['job']['id'], $uid);
$hc = db_one('SELECT * FROM ce_credit_holds WHERE id = ?', [(int) $w['job']['hold_id']]);
$exp = ce_write_cancel_cost($jst);
t('الإلغاء بيحسب القطع اللي خلصت بس والباقي بيرجع', $hc['status'] === 'settled' && (int) $hc['used'] === $exp && credits_balance($uid) === $bal - $exp && $exp < (int) $hc['reserved'],
    json_encode(['used' => $hc['used'], 'exp' => $exp, 'res' => $hc['reserved'], 'done' => $jst['done_pieces'] ?? null]));
ce_write_view($uid, ce_campaign_get($cc, $uid));
t('الحملة بعد الإلغاء ← «خلصت» جزئيًا وتقدر تكمّل خانة خانة', ce_campaign_get($cc, $uid)['status'] === 'done');

/* ─── 8) الناقد من مزود تاني ─── */
echo "الناقد من مزود تاني\n";
t('من غير كاتب معروف ← مفيش تحديد', ce_critic_target(null) === null);
set_setting('ce_critic_other_provider', '0');
t('الإعداد مقفول ← مفيش تحديد', ce_critic_target('openai') === null);
set_setting('ce_critic_other_provider', '1');
$tg = ce_critic_target('__none__');
t('مزود تاني متاح ← الناقد بيتحدد منه', $tg === null || ($tg['p'] !== '__none__' && $tg['m'] !== ''));

/* ─── 9) الرصيد ─── */
echo "الرصيد\n";
$poor = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())', ['CE-F poor', 'cef-p-' . bin2hex(random_bytes(3)) . '@test.local', 'x']);
db_run('INSERT INTO ce_campaigns (user_id, title, status, goal, brief_json, estimate_json, est_credits) SELECT ?, title, "plan_ok", goal, brief_json, estimate_json, est_credits FROM ce_campaigns WHERE id = ?', [$poor, $cid]);
$pc = (int) db()->lastInsertId();
$sp = ce_write_start($poor, $pc);
t('رصيد مش كفاية ← مفيش شغلانة والحملة ترجع «الخطة معتمدة»', !$sp['ok'] && ($sp['code'] ?? '') === 'credits' && ce_campaign_get($pc, $poor)['status'] === 'plan_ok', json_encode($sp, JSON_UNESCAPED_UNICODE));

/* ─── 10) الـ API ─── */
echo "الـ API\n";
$s = login($base, 'sub@test.local');
[$a1] = http('GET', "$base/public/api/engine.php?action=write&id=$cid", [], $s[0]);
t('محتوى حملة عميل تاني ← 404', $a1 === 404, (string) $a1);
[$a2] = http('GET', "$base/public/api/engine.php?action=write_start&id=$cid", [], $s[0]);
t('ابدأ الكتابة بـ GET ← 405', $a2 === 405, (string) $a2);
[$a3] = api_post($base, $s, 'idea_swap', ['id' => $cid, 'slot_id' => $s0['id'], 'idea_id' => $alt['id']]);
t('تبديل فكرة في حملة عميل تاني ← 404', $a3 === 404, (string) $a3);
[$a4] = api_post($base, $s, 'content_accept', ['id' => $cid, 'slot_id' => $s0['id']]);
t('اعتماد خانة عميل تاني ← 404', $a4 === 404, (string) $a4);

/* ─── 11) المسح ─── */
echo "مسح الحساب\n";
$cids = [$c0, $cid, $cr, $cm, $cb, $cd, $cc];
$in = implode(',', array_map('intval', $cids));
$cntIds = array_column(db_all("SELECT id FROM ce_contents WHERE campaign_id IN ($in)"), 'id');
account_purge($uid);
t('مسح الحساب بيمسح الأفكار والمحتوى والنسخ', !db_one("SELECT id FROM ce_ideas WHERE campaign_id IN ($in)") && !db_one("SELECT id FROM ce_contents WHERE campaign_id IN ($in)")
    && (!$cntIds || !db_one('SELECT id FROM ce_content_versions WHERE content_id IN (' . implode(',', array_map('intval', $cntIds)) . ')')));

/* ─── التنضيف ─── */
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM ce_jobs WHERE user_id IN (?, ?)', [$uid, $poor]);
try { account_purge($poor); } catch (\Throwable $e) {}
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CE-F%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
db_run('DELETE FROM rate_limits');
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
