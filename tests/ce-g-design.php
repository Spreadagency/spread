<?php
/**
 * Spread AI — اختبارات محرك المحتوى · المرحلة G (برومبت التصميم ← الصورة ← مراجعة الصورة ← النص العربي فوقها)
 * للتطوير المحلي بس: بيعمل بيانات تجريبية ويمسحها في الآخر — ماتشغّلوش على السيرفر الحقيقي.
 *
 *   php tests/ce-g-design.php http://localhost:8080
 *
 * محتاج: ترحيلات المحرك كلها لحد 2026_ce_design · حساب sub@test.local (الباسورد Test12345!) · الـ AI الوهمي شغال.
 * علامات الـ AI الوهمي (في استثناءات الحملة): REGEN_ME (أول صورة ماتعدّيش) · ALWAYS_REGEN (ولا صورة بتعدّي)
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/ce-design.php';
if (!ce_ready() || !ce_write_ready() || !ce_design_ready()) exit("شغّل ترحيلات المحرك لحد 2026_ce_design الأول\n");

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
    $jar = tempnam(sys_get_temp_dir(), 'ceg');
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
function run_job(int $id, int $max = 150): ?array
{
    $j = null;
    for ($i = 0; $i < $max; $i++) {
        $x = ce_job_step($id);
        if ($x) $j = $x;
        if ($j && !in_array($j['status'], ['queued', 'running'], true)) break;
    }
    return $j;
}
/** لون البكسل [r, g, b] */
function px($im, int $x, int $y): array
{
    $c = imagecolorat($im, $x, $y);
    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
}
db_run('DELETE FROM rate_limits');
$saved = [];
foreach (['ce_carousel_ai_images', 'ce_reel_cover', 'ce_vision_max_regen'] as $k) $saved[$k] = get_setting($k, null);
set_setting('ce_carousel_ai_images', '0');   // الافتراضي: كل شرايح الكاروسيل بصورة
set_setting('ce_reel_cover', '0');           // غلاف الريلز اختيار العميل
set_setting('ce_vision_max_regen', '2');

/* ─── 1) تشكيل العربي ─── */
echo "تشكيل العربي\n";
$cps = fn(string $s) => array_map(fn($c) => mb_ord($c), mb_str_split($s));
t('«باب»: أول ← آخر الألف ← الباء لوحدها (الحرف الأصلي)', $cps(ce_ar_shape('باب')) === [0xFE91, 0xFE8E, 0x0628], json_encode(array_map('dechex', $cps(ce_ar_shape('باب')))));
t('«سنانك»: أول · وسط · وسط(بعد ا: أول) · آخر', $cps(ce_ar_shape('سنانك')) === [0xFEB3, 0xFEE8, 0xFE8E, 0xFEE7, 0xFEDA], json_encode(array_map('dechex', $cps(ce_ar_shape('سنانك')))));
t('لام ألف: «لا» لوحدها ← ﻻ · «بلا» ← ﻼ', $cps(ce_ar_shape('لا')) === [0xFEFB] && $cps(ce_ar_shape('بلا')) === [0xFE91, 0xFEFC]);
t('التشكيل (الحركات) بيتشال', ce_ar_shape('سِنّ') === ce_ar_shape('سن'));
$v = $cps(ce_ar_visual('سنانك (3 خطوات)'));
t('ترتيب العرض: من اليمين للشمال · الرقم في مكانه · الأقواس معكوسة', $v[0] === ord('(') && in_array(ord('3'), $v, true) && array_search(ord('3'), $v) > 0 && end($v) === 0xFEB3, json_encode(array_map('dechex', $v)));
t('الإنجليزي والأرقام بيفضلوا بترتيبهم', str_contains(ce_ar_visual('جرب Spread AI بـ 50%'), 'Spread AI') && str_contains(ce_ar_visual('رقم 01012345678'), '01012345678'));
t('سطر إنجليزي بس مابيتعكسش', ce_ar_visual('Hello 2026') === 'Hello 2026');

/* ─── 2) الخطوط والقوالب ─── */
echo "الخطوط والقوالب\n";
$fonts = ce_fonts();
t('خطوط المنصة (Cairo · Tajawal) موجودة وفيها الحروف الموصولة', isset($fonts['Cairo-800'], $fonts['Tajawal-700']) && ce_font_has_arabic($fonts['Cairo-800']['path']));
$latin = (glob('/usr/share/fonts/truetype/*/Carlito-Regular.ttf') ?: [null])[0];
t('خط من غير حروف عربي (أو ملف مش خط) بيترفض', !ce_font_has_arabic(__FILE__) && (!$latin || !ce_font_has_arabic($latin)));
t('خط البراند بيكسب لو متاح (Tajawal)', str_contains(ce_font_path('Cairo-800', ['Tajawal']), 'Tajawal-800'));
$cl = ce_tpl_clean(['layout' => 'evil', 'scrim_color' => 'javascript:x', 'h_size' => 999, 'font_h' => '../../etc/passwd', 'logo' => 'none']);
t('تنضيف القالب: قيم غريبة بترجع للافتراضي والأرقام بتتحدد', $cl['layout'] === 'auto' && $cl['scrim_color'] === 'dark' && $cl['h_size'] == 16 && $cl['font_h'] === 'Cairo-800' && $cl['logo'] === 'none');
t('4 قوالب جاهزة', count(ce_templates(true)) >= 4);
t('المساحة الفاضية من وصف الـ Brief', ce_zone_from_text('الثلث الشمال') === 'left' && ce_zone_from_text('top third') === 'top' && ce_zone_from_text('') === 'bottom');

/* ─── 3) الرسم ─── */
echo "رسم النص فوق الصورة\n";
$tmp = sys_get_temp_dir() . '/ceg-' . bin2hex(random_bytes(4));
$im = imagecreatetruecolor(1024, 1024);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 210, 220));
imagepng($im, "$tmp-b.png");
$tpl = ce_template_get(null, 'post');
$r = ce_overlay_render(['base' => "$tmp-b.png", 'aspect' => '4:5', 'headline' => 'ابتسامتك تستاهل', 'subline' => 'احجز النهارده', 'zone' => 'bottom',
    'template' => ['scrim' => 'solid', 'scrim_color' => '#102030', 'scrim_opacity' => 100, 'layout' => 'auto', 'logo' => 'none'] + $tpl['style'], 'colors' => ['#0C87EF'], 'out' => "$tmp.png"]);
$out = @imagecreatefrompng("$tmp.png");
t('المقاس النهائي 1080×1350 (4:5)', $r['ok'] && $out && imagesx($out) === 1080 && imagesy($out) === 1350, json_encode($r));
// فوق: الصورة زي ما هي · تحت: الشريط + فيه بكسلات فاتحة (النص الأبيض)
$top = px($out, 540, 200);
$white = 0;
for ($y = (int) (1350 * 0.7); $y < 1350; $y += 3) for ($x = 100; $x < 980; $x += 3) { $p = px($out, $x, $y); if ($p[0] > 230 && $p[1] > 230 && $p[2] > 230) $white++; }
t('الجزء الفاضي فوق مالمسهوش حاجة', abs($top[0] - 200) < 6 && abs($top[2] - 220) < 6, json_encode($top));
t('النص اترسم أبيض على الشريط الغامق (لون تلقائي حسب التباين)', $white > 200, (string) $white);
$r2 = ce_overlay_render(['base' => null, 'aspect' => '1:1', 'headline' => str_repeat('كلام طويل جدا ', 30), 'template' => $tpl['style'], 'colors' => ['#FFFFFF', '#EEEEEE'], 'out' => "$tmp-2.png"]);
t('عنوان طويل جدًا ← بيصغر ويتقص لـ 3 سطور (مابيطلعش برّه)', $r2['ok'] && $r2['lines'] <= 3);
$o2 = imagecreatefrompng("$tmp-2.png");
$dark = 0;
for ($y = 300; $y < 780; $y += 4) for ($x = 60; $x < 1020; $x += 4) { $p = px($o2, $x, $y); if ($p[0] < 60 && $p[1] < 60) $dark++; }
t('خلفية فاتحة ← النص غامق', $dark > 100, (string) $dark);
foreach (glob("$tmp*") as $f) @unlink($f);

/* ─── تجهيز: عميل + حملة اتكتبت ─── */
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())',
    ['CE-G test', 'ceg-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('x', PASSWORD_DEFAULT)]);
credits_add($uid, 300, 'CE-G test credits');
db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$uid, 'عيادة الاختبار', 'عيادة أسنان']);
foreach ([['تبييض', 'high'], ['تقويم', 'medium']] as [$n, $p]) db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$uid, $n, $p]);
$per = [];
foreach (['أم مشغولة', 'شاب'] as $n) $per[] = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")',
    [$uid, $n, json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
run_job((int) ce_dna_build($uid)['job']['id']);
$written = function (array $formats, array $excl = [], array $extra = []) use ($uid, $per): int {
    $r = ce_campaign_save($uid, ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => $per, 'formats' => $formats, 'channel' => 'whatsapp', 'boldness' => 'medium', 'exclusions' => $excl] + $extra);
    $cid = (int) $r['campaign']['id'];
    run_job((int) ce_plan_start($uid, $cid)['job']['id'], 20);
    if (!ce_plan_approve($uid, $cid)['ok']) db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$cid]);
    run_job((int) ce_write_start($uid, $cid)['job']['id']);
    return $cid;
};

/* ─── 4) الحملة كاملة ─── */
echo "تصميم الحملة\n";
$cid = $written(['post' => 1, 'carousel' => 1, 'reel' => 1], [], ['reel_cover' => 1]);
$c = ce_campaign_get($cid, $uid);
$v = ce_design_view($uid, $c);
t('قبل التصميم: الإطارات المستنية (بوست + شرايح الكاروسيل) + غلاف الريلز لوحده (اختيار العميل) والحجز المتوقع', $c['status'] === 'done' && $v['pending'] >= 6 && $v['reels'] === 1 && $v['reel_cover'] && $v['credits_reels'] > $v['credits'],
    json_encode(['st' => $c['status'], 'p' => $v['pending'], 'r' => $v['reels'], 'cr' => $v['credits'], 'crr' => $v['credits_reels']]));
$bal = credits_balance($uid);
$ds = ce_design_start($uid, $cid);
$ds2 = ce_design_start($uid, $cid);
t('صمّم ← حجز (بغلاف الريلز اللي اختاره) + شغلانة · الضغط مرتين = نفس الشغلانة', $ds['ok'] && !empty($ds2['existing']) && (int) $ds2['job']['id'] === (int) $ds['job']['id'] && credits_balance($uid) === $bal - $v['credits_reels'],
    json_encode([$ds['error'] ?? null, credits_balance($uid), $bal, $v['credits_reels']], JSON_UNESCAPED_UNICODE));
$j = run_job((int) $ds['job']['id']);
$rows = db_all('SELECT d.*, s.format FROM ce_designs d JOIN ce_slots s ON s.id = d.slot_id WHERE d.campaign_id = ? ORDER BY d.slot_id, d.frame', [$cid]);
$byF = [];
foreach ($rows as $d) $byF[$d['format']][] = $d;
t('الشغلانة خلصت وكل التصميمات جاهزة', $j['status'] === 'done' && $rows && !array_filter($rows, fn($d) => $d['status'] !== 'pass'), json_encode(['st' => $j['status'], 'err' => $j['error'], 'rows' => array_column($rows, 'status')], JSON_UNESCAPED_UNICODE));
t('المقاسات: بوست 1:1 · كاروسيل 4:5 · ريلز 9:16', ($byF['post'][0]['aspect'] ?? '') === '1:1' && ($byF['carousel'][0]['aspect'] ?? '') === '4:5' && ($byF['reel'][0]['aspect'] ?? '') === '9:16');
$sz = fn($d) => @getimagesize(STORAGE_PATH . '/' . $d['final_path']);
t('الصور النهائية بالمقاس الصح (1080×1080 · 1080×1350 · 1080×1920)', ($sz($byF['post'][0])[1] ?? 0) === 1080 && ($sz($byF['carousel'][0])[1] ?? 0) === 1350 && ($sz($byF['reel'][0])[1] ?? 0) === 1920);
$car = $byF['carousel'] ?? [];
t('الكاروسيل: كل الشرايح بصورة AI', $car && count($car) >= 5 && !array_filter($car, fn($d) => $d['kind'] !== 'ai' || !$d['image_path']));
set_setting('ce_carousel_ai_images', '1');
$fr = ce_design_frames('carousel', ['slides' => [['text' => 'أ'], ['text' => 'ب'], ['text' => 'ج']], 'design_brief' => []]);
t('الأدمن يقدر يخلّي أول شريحة بس بصورة والباقي نص', array_column($fr, 'kind') === ['ai', 'text', 'text']);
set_setting('ce_carousel_ai_images', '0');
$p0 = $byF['post'][0];
t('البرومبت فيه المساحة الفاضية ومنع أي نص · والممنوعات دايمًا موجودة', str_contains($p0['prompt'], 'clean, calm and empty') && str_contains($p0['prompt'], 'no text') && str_contains($p0['negative'], 'logos') && str_contains($p0['negative'], 'distorted teeth'));
$qa = json_decode((string) $p0['qa_json'], true);
t('مراجعة الصورة: الألوان + الفكرة + المكان الفاضي + الحكم', $qa && $qa['verdict'] === 'PASS' && $qa['zone_clear'] && $qa['idea'] == 90 && $p0['qa_score'] > 80, json_encode($qa, JSON_UNESCAPED_UNICODE));
t('الصورة النضيفة اتحفظت منفصلة عن النهائية', $p0['image_path'] && $p0['final_path'] && $p0['image_path'] !== $p0['final_path'] && is_file(STORAGE_PATH . '/' . $p0['image_path']));
$h = db_one('SELECT * FROM ce_credit_holds WHERE id = ?', [(int) $j['hold_id']]);
$res = json_decode((string) $j['result_json'], true);
t('الحجز اتسوّى: الفعلي ≥ الصور × الحد الأدنى والباقي رجع', $h['status'] === 'settled' && (int) $h['used'] >= (int) $res['images'] && credits_balance($uid) === $bal - (int) $h['used'], json_encode([$h, $res]));
t('مفيش حاجة تتصمم تاني', ce_design_view($uid, ce_campaign_get($cid, $uid))['pending'] === 0 && !ce_design_start($uid, $cid)['ok']);

/* ─── 5) تعديل النص والقالب ─── */
echo "تعديل النص\n";
$bal = credits_balance($uid);
$tpls = ce_templates(true, 'post');
$oldFinal = $p0['final_path'];
$tx = ce_design_text($uid, $cid, (int) $p0['slot_id'], 1, ['headline' => 'عنوان جديد خالص', 'subline' => 'سطر تاني', 'template_id' => $tpls[1]['id']]);
$p1 = ce_design_row((int) $p0['slot_id'], 1);
t('النص الجديد ← رسم تاني على نفس الصورة من غير كريدت', $tx['ok'] && $p1['headline'] === 'عنوان جديد خالص' && (int) $p1['template_id'] === $tpls[1]['id'] && $p1['final_path'] !== $oldFinal && $p1['image_path'] === $p0['image_path'] && credits_balance($uid) === $bal);
t('الصورة النهائية القديمة اتمسحت', !is_file(STORAGE_PATH . '/' . $oldFinal));
t('عنوان فاضي أو طويل ← مرفوض', !ce_design_text($uid, $cid, (int) $p0['slot_id'], 1, ['headline' => ''])['ok'] && !ce_design_text($uid, $cid, (int) $p0['slot_id'], 1, ['headline' => str_repeat('ا', 120)])['ok']);
t('قالب مش موجود ← مرفوض', !ce_design_text($uid, $cid, (int) $p0['slot_id'], 1, ['headline' => 'x', 'template_id' => 999999])['ok']);
t('تصميم عميل تاني ← مرفوض', !ce_design_text($uid + 99999, $cid, (int) $p0['slot_id'], 1, ['headline' => 'x'])['ok']);

/* ─── 6) صورة جديدة ─── */
echo "صورة جديدة لتصميم واحد\n";
$bal = credits_balance($uid);
$rd = ce_design_redo($uid, $cid, (int) $p0['slot_id'], 1);
t('صورة جديدة ← شغلانة لتصميم واحد بحجز صورة', $rd['ok'] && credits_balance($uid) === $bal - ce_design_hold_credits(ce_campaign_get($cid, $uid), 1));
t('مفيش طلب تاني والتصميم شغال', !ce_design_redo($uid, $cid, (int) $p0['slot_id'], 1)['ok']);
run_job((int) $rd['job']['id'], 20);
$p2 = ce_design_row((int) $p0['slot_id'], 1);
t('الصورة اتغيرت والنص اللي العميل كتبه فضل', $p2['status'] === 'pass' && $p2['image_path'] !== $p1['image_path'] && $p2['headline'] === 'عنوان جديد خالص' && (int) $p2['attempts'] === 2);
t('الصورة القديمة اتمسحت من السيرفر', !is_file(STORAGE_PATH . '/' . $p1['image_path']));

echo "غلاف الريلز اختياري\n";
$cv = $written(['post' => 1, 'reel' => 1]);
$vv = ce_design_view($uid, ce_campaign_get($cid2 = $cv, $uid));
t('من غير اختيار ← الغلاف مش في الحجز', $vv['reels'] === 1 && !$vv['reel_cover'] && $vv['pending'] === 1);
run_job((int) ce_design_start($uid, $cv)['job']['id']);
$reelSlot = (int) db_one('SELECT id FROM ce_slots WHERE campaign_id = ? AND format = "reel"', [$cv])['id'];
t('اتصمم البوست بس — الريلز من غير غلاف', !ce_design_row($reelSlot, 1) && (int) db_one('SELECT COUNT(*) n FROM ce_designs WHERE campaign_id = ?', [$cv])['n'] === 1);
$rc = ce_design_start($uid, $cv, true);
t('العميل اختار الغلاف بعدين ← شغلانة للغلاف بس', $rc['ok'] && count(json_decode((string) db_one('SELECT state_json FROM ce_jobs WHERE id = ?', [(int) $rc['job']['id']])['state_json'], true)['queue']) === 1);
run_job((int) $rc['job']['id']);
$rr = ce_design_row($reelSlot, 1);
t('غلاف الريلز 9:16 اتعمل', $rr && $rr['status'] === 'pass' && $rr['aspect'] === '9:16' && (getimagesize(STORAGE_PATH . '/' . $rr['final_path'])[1] ?? 0) === 1920);

echo "خطوط البراند\n";
bk_save_section($uid, 'typography', ['ar' => 'Cairo', 'en' => 'Inter', 'design_fonts' => ['Tajawal', 'Cairo', 'NotAFont', 'Tajawal']], 'user', 'test');
t('الهوية بتحفظ لحد 3 من خطوط المنصة بس (الغريب والمكرر بيتشالوا)', ce_brand_fonts($uid) === ['Tajawal', 'Cairo'], json_encode(ce_brand_fonts($uid)));
t('ملف الهوية المختصر بياخد خطوط التصميمات', ce_dna_trim(ce_dna_clean($uid, ce_dna_from_kit($uid)))['visual']['fonts'] === ['Tajawal', 'Cairo']);
t('خطوط المنصة للاختيار فيها Cairo و Tajawal برابط معاينة', count(array_filter(ce_font_families(), fn($f) => in_array($f['family'], ['Cairo', 'Tajawal'], true) && $f['url'])) === 2);
$okF = ce_design_text($uid, $cid, (int) $p0['slot_id'], 1, ['headline' => 'عنوان بخط تاني', 'font' => 'Cairo']);
t('خط العنوان لتصميم واحد من خطوط البراند ← بيترسم', $okF['ok'] && ce_design_row((int) $p0['slot_id'], 1)['font_family'] === 'Cairo');
t('خط مش من خطوط البراند ← مرفوض', !ce_design_text($uid, $cid, (int) $p0['slot_id'], 1, ['headline' => 'x', 'font' => 'Arial'])['ok']);

/* ─── 7) الصورة ماعدّتش المراجعة ─── */
echo "إعادة الصورة\n";
$cr = $written(['post' => 1], ['REGEN_ME']);
$j = run_job((int) ce_design_start($uid, $cr)['job']['id']);
$dr = db_one('SELECT * FROM ce_designs WHERE campaign_id = ?', [$cr]);
$qr = json_decode((string) $dr['qa_json'], true);
t('أول صورة ماعدّتش ← اتعادت بالملاحظات وعدّت', $dr['status'] === 'pass' && (int) $dr['attempts'] === 2 && $qr['verdict'] === 'PASS', json_encode(['st' => $dr['status'], 'att' => $dr['attempts'], 'qa' => $qr], JSON_UNESCAPED_UNICODE));
t('الإعادة اتبعت فيها ملاحظات المراجعة', !str_contains((string) $dr['prompt'], 'REGEN_ME'));
$cm = $written(['post' => 1], ['ALWAYS_REGEN']);
$jm = run_job((int) ce_design_start($uid, $cm)['job']['id']);
$dm = db_one('SELECT * FROM ce_designs WHERE campaign_id = ?', [$cm]);
$qm = json_decode((string) $dm['qa_json'], true);
t('ولا صورة عدّت ← «محتاجة مراجعتك» بعد إعادتين (3 صور) بأحسن صورة', $dm['status'] === 'manual' && (int) $dm['attempts'] === 3 && $qm['verdict'] === 'REGENERATE' && $dm['final_path'], json_encode(['st' => $dm['status'], 'att' => $dm['attempts']]));
t('الصور اللي ماتختارتش اتمسحت (بتفضل صورة واحدة)', count(glob(STORAGE_PATH . '/uploads/ce-designs/' . $uid . '/*.png')) === count(db_all('SELECT id FROM ce_designs WHERE user_id = ? AND image_path IS NOT NULL', [$uid])) + count(db_all('SELECT id FROM ce_designs WHERE user_id = ? AND final_path IS NOT NULL', [$uid])),
    count(glob(STORAGE_PATH . '/uploads/ce-designs/' . $uid . '/*.png')) . ' ملف');
t('الأسباب ظاهرة للعميل', in_array('مكان النص مش فاضي', $qm['issues'], true));
t('«اعتمده» ← جاهز · ومرة تانية ← مرفوض', ce_design_accept($uid, $cm, (int) $dm['slot_id'], 1) && ce_design_row((int) $dm['slot_id'], 1)['status'] === 'pass' && !ce_design_accept($uid, $cm, (int) $dm['slot_id'], 1));

/* ─── 8) الإلغاء ─── */
echo "الإلغاء في النص\n";
$cc = $written(['post' => 3]);
$bal = credits_balance($uid);
$w = ce_design_start($uid, $cc);
for ($i = 0; $i < 4; $i++) ce_job_step((int) $w['job']['id']);   // تصميم واحد
$st = json_decode((string) db_one('SELECT state_json FROM ce_jobs WHERE id = ?', [(int) $w['job']['id']])['state_json'], true);
ce_job_cancel((int) $w['job']['id'], $uid);
$hc = db_one('SELECT * FROM ce_credit_holds WHERE id = ?', [(int) $w['job']['hold_id']]);
$exp = ce_design_cancel_cost($st);
t('الإلغاء بيحسب الصور اللي اتولّدت بس', $hc['status'] === 'settled' && (int) $hc['used'] === $exp && $exp >= 1 && credits_balance($uid) === $bal - $exp && $exp < (int) $hc['reserved'],
    json_encode(['used' => $hc['used'], 'exp' => $exp, 'res' => $hc['reserved'], 'img' => $st['images'] ?? null]));
$again = ce_design_start($uid, $cc);
t('«صمّم» تاني بيكمّل الباقي بس', $again['ok'] && count(json_decode((string) db_one('SELECT state_json FROM ce_jobs WHERE id = ?', [(int) $again['job']['id']])['state_json'], true)['queue']) === 2);
ce_job_cancel((int) $again['job']['id'], $uid);

/* ─── 9) الـ API ─── */
echo "الـ API\n";
$s = login($base, 'sub@test.local');
[$a1] = http('GET', "$base/public/api/engine.php?action=design&id=$cid", [], $s[0]);
t('تصميمات حملة عميل تاني ← 404', $a1 === 404, (string) $a1);
[$a2] = http('GET', "$base/public/api/engine.php?action=design_start&id=$cid", [], $s[0]);
t('ابدأ التصميم بـ GET ← 405', $a2 === 405, (string) $a2);
[$a3] = api_post($base, $s, 'design_text', ['id' => $cid, 'slot_id' => (int) $p0['slot_id'], 'frame' => 1, 'headline' => 'x']);
t('تعديل نص تصميم عميل تاني ← 404', $a3 === 404, (string) $a3);
[$a4] = api_post($base, $s, 'design_redo', ['id' => $cid, 'slot_id' => (int) $p0['slot_id'], 'frame' => 1]);
t('صورة جديدة لعميل تاني ← 404', $a4 === 404, (string) $a4);

/* ─── 10) المسح ─── */
echo "مسح الحساب\n";
$dir = STORAGE_PATH . '/uploads/ce-designs/' . $uid;
account_purge($uid);
t('مسح الحساب بيمسح التصميمات وفولدر الصور', !db_one('SELECT id FROM ce_designs WHERE user_id = ?', [$uid]) && !is_dir($dir));

/* ─── التنضيف ─── */
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$uid]);
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CE-G%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
db_run('DELETE FROM rate_limits');
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
