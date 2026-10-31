<?php
/**
 * Spread AI — الباقات (المرحلة 2): قطع محرك المحتوى في مكتبة المحتوى و«حملاتي» (التقويم)
 * للتطوير المحلي بس — بيعمل عميل تجريبي ويمسحه في الآخر.
 *
 *   php tests/pk2-library.php http://localhost:8080
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
require_once __DIR__ . '/../includes/ce-library.php';
require_once __DIR__ . '/../includes/ce-write.php';
if (!ce_lib_ready()) exit("شغّل ترحيل 2026_ce_library الأول\n");

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
db_run('DELETE FROM rate_limits');
$lib = fn(int $cid) => db_one('SELECT lib_content_id FROM ce_contents WHERE id = ?', [$cid])['lib_content_id'] ?? null;

try {
/* ─── تجهيز ─── */
echo "تجهيز\n";
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, created_at) VALUES (?,?,?,"active",NOW(),NOW())',
    ['PK2 test', 'pk2-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT)]);
$email = db_one('SELECT email FROM users WHERE id = ?', [$uid])['email'];
credits_add($uid, 300, 'PK2 test credits');
foreach (["content_engine", "social_publishing"] as $f) db_insert("INSERT INTO user_feature_access (user_id, feature_key, is_enabled) VALUES (?, ?, 1)", [$uid, $f]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$uid, 'براند المكتبة', 'براند تجريبي']);
db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$uid, 'خدمة', 'high']);
$per = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")', [$uid, 'عميل', json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
run_job((int) ce_dna_build($uid)['job']['id']);
$r = ce_campaign_save($uid, ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => [$per], 'formats' => ['post' => 2, 'carousel' => 1, 'reel' => 1], 'channel' => 'whatsapp', 'boldness' => 'medium']);
$camp = (int) ($r['campaign']['id'] ?? 0);
run_job((int) ce_plan_start($uid, $camp)['job']['id'], 30);
if (!ce_plan_approve($uid, $camp)['ok']) db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$camp]);
run_job((int) ce_write_start($uid, $camp)['job']['id'], 400);
$xs = db_all('SELECT x.*, s.slot_date, s.position FROM ce_contents x JOIN ce_slots s ON s.id = x.slot_id WHERE x.campaign_id = ? ORDER BY s.position', [$camp]);
$by = [];
foreach ($xs as $x) $by[$x['format']][] = $x;
t('حملة المحرك اتكتبت (بوستين + كاروسيل + ريلز)', count($xs) === 4 && count($by['carousel'] ?? []) === 1 && count($by['reel'] ?? []) === 1);

/* ─── 1) بعد الكتابة ─── */
echo "بعد الكتابة\n";
$cc = db_one('SELECT * FROM ce_campaigns WHERE id = ?', [$camp]);
$lc = $cc['lib_campaign_id'] ? db_one('SELECT * FROM campaigns WHERE id = ?', [(int) $cc['lib_campaign_id']]) : null;
t('اتعملت حملة في «حملاتي» واقفة على الجدولة', $lc && (int) $lc['user_id'] === $uid && $lc['basis'] === 'engine' && (int) $lc['stage'] === 5 && $lc['title'] === $cc['title'], json_encode($lc, JSON_UNESCAPED_UNICODE));
$cs = db_all('SELECT * FROM contents WHERE user_id = ? AND campaign_id = ? ORDER BY id', [$uid, (int) $lc['id']]);
t('كل قطعة بقت منشور في المكتبة (من غير كريدت)', count($cs) === 4 && !array_filter($cs, fn($c) => (int) $c['credits_used'] !== 0) && !array_filter($xs, fn($x) => !$lib((int) $x['id'])));
$post = db_one('SELECT * FROM contents WHERE id = ?', [(int) $lib((int) $by['post'][0]['id'])]);
$pb = json_decode((string) $by['post'][0]['body_json'], true);
t('نص البوست = الـ Hook + النص · الـ CTA · الهاشتاجات', str_contains((string) $post['generated_text'], mb_substr((string) ($pb['body'] ?? ''), 0, 20)) && $post['cta'] === ($pb['cta'] ?? null) && $post['format'] === 'post', mb_substr((string) $post['generated_text'], 0, 80));
$car = db_one('SELECT * FROM contents WHERE id = ?', [(int) $lib((int) $by['carousel'][0]['id'])]);
$cb = json_decode((string) $by['carousel'][0]['body_json'], true);
t('الكاروسيل بشرايحه', $car['format'] === 'carousel' && (int) $car['slides_count'] === count($cb['slides'] ?? []) && count(json_decode((string) $car['slides_json'], true)) === count($cb['slides'] ?? []));
$reel = db_one('SELECT * FROM contents WHERE id = ?', [(int) $lib((int) $by['reel'][0]['id'])]);
t('الريلز = سكريبت فيديو (بالمشاهد)', $reel['format'] === 'video' && $reel['video_status'] === 'script' && str_contains((string) $reel['video_brief_json'], 'shoot'));
$ideas = db_all('SELECT * FROM campaign_ideas WHERE campaign_id = ? ORDER BY sort_order', [(int) $lc['id']]);
$ip = array_values(array_filter($ideas, fn($i) => (int) $i['content_id'] === (int) $post['id']))[0] ?? null;
t('في التقويم: كل قطعة في تاريخ خانتها (والريلز من غير ميعاد)', count($ideas) === 4 && $ip && substr((string) $ip['plan_at'], 0, 10) === $by['post'][0]['slot_date']
  && !array_values(array_filter($ideas, fn($i) => (int) $i['content_id'] === (int) $reel['id']))[0]['plan_at'], json_encode([count($ideas), $ip['plan_at'] ?? null, $by['post'][0]['slot_date']]));
t('مختارة ومعتمدة ودرجة الجودة من المحرك', !array_filter($ideas, fn($i) => !$i['selected'] || !$i['approved']) && (int) $ip['eval_score'] === (int) round((float) $by['post'][0]['qa_score']));

/* ─── 2) التصميم ─── */
echo "التصميم\n";
$d = ce_design_start($uid, $camp, false);
if ($d['ok']) run_job((int) $d['job']['id'], 400);
$eng = (int) db_one('SELECT COUNT(*) n FROM ce_designs WHERE campaign_id = ? AND status IN ("pass", "manual") AND final_path IS NOT NULL', [$camp])['n'];
$mir = db_all('SELECT d.* FROM content_designs d JOIN contents c ON c.id = d.content_id WHERE c.campaign_id = ?', [(int) $lc['id']]);
t('التصميمات النهائية اتنسخت للمكتبة', $d['ok'] && $eng > 0 && count($mir) === $eng, json_encode([$eng, count($mir)]));
$post = db_one('SELECT * FROM contents WHERE id = ?', [(int) $post['id']]);
t('غلاف المنشور = تصميمه · من غير كريدت', (int) $post['selected_image_id'] > 0 && !array_filter($mir, fn($m) => (int) $m['credits_used'] !== 0 || $m['provider'] !== null));
$du = array_values(array_filter(plan_usage($uid, true)['units'], fn($u) => $u['key'] === 'designs'))[0];
t('النسخة مابتتحسبش على حصة التصميمات مرتين', $du['used'] === $eng, json_encode([$du['used'], $eng]));
$carSlides = db_all('SELECT slide_no FROM content_designs WHERE content_id = ? ORDER BY slide_no', [(int) $car['id']]);
t('شرايح الكاروسيل بأرقامها', count($carSlides) > 1 && (int) $carSlides[0]['slide_no'] === 1);

/* ─── 3) التحديث والتعديل ─── */
echo "التحديث والتعديل\n";
$slot = (int) $by['post'][0]['slot_id'];
$idea = db_one('SELECT id FROM ce_ideas WHERE slot_id = ? LIMIT 1', [$slot]);
db_run('UPDATE contents SET generated_text = "نص قديم" WHERE id = ?', [(int) $post['id']]);
$w = ce_write_slot($uid, $camp, $slot, (int) $idea['id']);
if ($w['ok']) run_job((int) $w['job']['id'], 100);
t('المحرك أعاد كتابة القطعة ← المكتبة اتحدّثت', $w['ok'] && db_one('SELECT generated_text FROM contents WHERE id = ?', [(int) $post['id']])['generated_text'] !== 'نص قديم');
db_run('UPDATE contents SET generated_text = "نص العميل في المكتبة", status = "edited" WHERE id = ?', [(int) $post['id']]);
$w = ce_write_slot($uid, $camp, $slot, (int) $idea['id']);
if ($w['ok']) run_job((int) $w['job']['id'], 100);
t('العميل عدّل في المكتبة ← المحرك مابيكتبش فوق تعديله', db_one('SELECT generated_text FROM contents WHERE id = ?', [(int) $post['id']])['generated_text'] === 'نص العميل في المكتبة');
$x0 = db_one('SELECT * FROM ce_contents WHERE id = ?', [(int) $by['post'][0]['id']]);
t('النشر من المحرك بياخد نص المكتبة المعدّل', str_starts_with((string) ce_lib_edited_message($x0), 'نص العميل في المكتبة'));

/* ─── 4) النشر من مكان واحد ─── */
echo "النشر من مكان واحد\n";
t('مفيش نشر من أي مكان ← الاتنين مسموحين', ce_lib_engine_publishing((int) $post['id']) === null && ce_lib_library_publishing($x0) === null);
db_run('INSERT INTO ce_publish_queue (user_id, campaign_id, content_id, connection_id, platform, scheduled_at, status) VALUES (?,?,?,0,"facebook",DATE_ADD(NOW(), INTERVAL 2 DAY),"pending")', [$uid, $camp, (int) $x0['id']]);
t('متجدولة من المحرك ← المكتبة بترفض تنشرها', str_contains((string) ce_lib_engine_publishing((int) $post['id']), 'محرك المحتوى'));
$jar = login($base, $email);
[$c1, $b1] = http('POST', "$base/public/ajax/publish-direct.php", ['csrf' => $jar[1], 'content_id' => $post['id'], 'connection_id' => 1, 'mode' => 'now'], $jar[0], ['X-CSRF-Token: ' . $jar[1], 'X-Requested-With: XMLHttpRequest']);
t('«انشر» من المكتبة ← رسالة «من محرك المحتوى»', str_contains($b1, 'محرك المحتوى'), $c1 . ' ' . mb_substr($b1, 0, 200));
db_run('DELETE FROM ce_publish_queue WHERE content_id = ?', [(int) $x0['id']]);
db_run('UPDATE contents SET publish_status = "scheduled" WHERE id = ?', [(int) $car['id']]);
$xc = db_one('SELECT * FROM ce_contents WHERE id = ?', [(int) $by['carousel'][0]['id']]);
t('متجدولة من المكتبة ← المحرك بيسيبها بسبب واضح', str_contains((string) ce_lib_library_publishing($xc), 'مكتبة المحتوى'));
db_run('UPDATE contents SET publish_status = "draft" WHERE id = ?', [(int) $car['id']]);

/* ─── 5) التكملة والمسح ─── */
echo "التكملة والمسح\n";
db_run('DELETE FROM contents WHERE id = ?', [(int) $reel['id']]);
ce_lib_sync_campaign($uid, $camp);
t('العميل مسح منشور من المكتبة ← مابيرجعش', (int) $lib((int) $by['reel'][0]['id']) === -1 && !db_one('SELECT id FROM contents WHERE id = ?', [(int) $reel['id']]));
$p2 = (int) $lib((int) $by['post'][1]['id']);
db_run('DELETE FROM campaign_ideas WHERE content_id = ?', [$p2]);
db_run('DELETE FROM contents WHERE id = ?', [$p2]);
db_run('UPDATE ce_contents SET lib_content_id = NULL, lib_synced_at = NULL WHERE id = ?', [(int) $by['post'][1]['id']]);
t('قطعة فاتها النسخ ← التكملة بترجّعها', ce_lib_backfill($uid) >= 1 && (int) $lib((int) $by['post'][1]['id']) > 0);
t('التكملة تاني ← مفيش حاجة تتعمل', ce_lib_backfill($uid) === 0);

/* ─── 6) الصفحات ─── */
echo "الصفحات\n";
[$c2, $h2] = http('GET', "$base/public/campaigns.php?ui=v2", [], $jar[0]);
t('«حملاتي» فيها حملة المحرك', $c2 === 200 && str_contains($h2, htmlspecialchars((string) $cc['title'], ENT_QUOTES)) && !str_contains($h2, 'Warning:'), (string) $c2);
[$c3, $b3] = http('GET', "$base/public/api/contents.php?action=library&page=1&campaign=" . (int) $lc['id'], [], $jar[0], ['X-Requested-With: XMLHttpRequest']);
$j3 = json_decode($b3, true) ?: [];
t('المكتبة بتعرض قطع المحرك (بالحملة والغلاف)', $c3 === 200 && count($j3['items'] ?? []) === 3 && (bool) array_filter($j3['items'] ?? [], fn($i) => !empty($i['cover'])), $c3 . ' ' . mb_substr($b3, 0, 200));
[$c4, $h4] = http('GET', "$base/public/campaign.php?ui=v2&id=" . (int) $lc['id'], [], $jar[0]);
t('صفحة الحملة القديمة بتفتح (الجدولة)', $c4 === 200 && !str_contains($h4, 'Warning:') && !str_contains($h4, 'Fatal'), (string) $c4);

} finally {
if (!empty($uid)) {
    db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$uid]);
    db_run('DELETE FROM usage_events WHERE user_id = ?', [$uid]);
    db_run('DELETE FROM ce_publish_queue WHERE user_id = ?', [$uid]);
    db_run('DELETE ci FROM campaign_ideas ci WHERE ci.user_id = ?', [$uid]);
    db_run('DELETE FROM campaigns WHERE user_id = ?', [$uid]);
    account_purge($uid);
}
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "PK2%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
db_run('DELETE FROM rate_limits');
}
t('مسح الحساب بيمسح المكتبة والحملة', !db_one('SELECT id FROM contents WHERE user_id = ?', [$uid]) && !db_one('SELECT id FROM campaigns WHERE user_id = ?', [$uid]));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
