<?php
/**
 * Spread AI — الترجمة · المرحلة 1: محتوى إنجليزي (اختيار «English» في لغة الكتابة)
 * للتطوير المحلي بس — بيعمل حساب مؤقت ويمسحه.
 *
 *   php tests/i18n1-english-content.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/brand-kit.php';
require_once __DIR__ . '/../includes/prompt-builder.php';
require_once __DIR__ . '/../includes/plan-functions.php';
require_once __DIR__ . '/../includes/campaign-flow.php';
require_once __DIR__ . '/../includes/design-builder.php';
require_once __DIR__ . '/../includes/ce-write.php';
require_once __DIR__ . '/../includes/ce-overlay.php';

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function csrf_of(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : ''); }
$tag = bin2hex(random_bytes(3));
const EN_TAIL = 'OUTPUT LANGUAGE: ENGLISH';

/* ─── 1) مفتاح اللغة ─── */
echo "اختيار English\n";
t('english و en ← english', bk_dialect_key('english') === 'english' && bk_dialect_key('en') === 'english');
t('بيتخزن في الهوية english زي ما هو', bk_dialect_store('english') === 'english');
t('اللهجات القديمة زي ما هي', bk_dialect_key('khaleeji') === 'gulf' && bk_dialect_store('gulf') === 'khaleeji' && bk_dialect_key('') === 'egyptian');
t('وصف البرومبت بيقول English', str_contains(bk_dialect_label('english'), 'English'));
t('موجود في لهجات محرك الحملات', in_array('english', CE_DNA_DIALECTS, true));
require_once __DIR__ . '/../includes/brand-brain.php';
t('موجود في اختيارات الهوية (Brand Brain)', (brand_field_options()['dialect']['english'] ?? '') === 'English');
t('السطر الأخير فاضي للعربي', bk_lang_tail('egyptian') === '' && bk_lang_tail('khaleeji') === '' && bk_lang_tail(null) === '');

/* ─── 2) البرومبتات ─── */
echo "البرومبتات\n";
$brandAr = ['id' => 0, 'user_id' => 0, 'business_name' => 'Smile Clinic', 'industry' => 'dental', 'dialect' => 'egyptian'];
$brandEn = ['dialect' => 'english'] + $brandAr;
$pAr = build_final_prompt($brandAr, ['content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'tone' => 'simple', 'dialect' => 'egyptian']);
$pEn = build_final_prompt($brandAr, ['content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'tone' => 'simple', 'dialect' => 'english']);
t('المنشور: اختيار English في الطلب ← سطر لغة الناتج', str_contains($pEn, EN_TAIL) && str_contains($pEn, 'English'));
t('المنشور: العربي من غير السطر', !str_contains($pAr, EN_TAIL));
$pBrandEn = build_final_prompt($brandEn, ['content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'tone' => 'simple']);
t('المنشور: الهوية English والطلب مافيهوش لهجة ← إنجليزي', str_contains($pBrandEn, EN_TAIL));
$idea = ['title' => 'Whitening offer', 'angle' => 'offer', 'description' => ''];
t('الخطة: إنجليزي', str_contains(build_produce_prompt($brandEn, $idea), EN_TAIL) && !str_contains(build_produce_prompt($brandAr, $idea), EN_TAIL));
$camp = ['id' => 0, 'title' => 'Summer', 'basis' => 'awareness', 'topic' => '', 'notes' => ''];
t('أفكار الحملة: إنجليزي', str_contains(campaign_ideas_prompt_base($brandEn, $camp, 3, []), EN_TAIL) && !str_contains(campaign_ideas_prompt_base($brandAr, $camp, 3, []), EN_TAIL));
t('لهجة الحملة English', str_contains(campaign_dialect($brandEn), 'English'));
t('محرك الحملات: الكاتب والأفكار إنجليزي', str_contains(ce_writer_system(['dna' => ['tone' => ['dialect' => 'english']]]), EN_TAIL)
    && str_contains(ce_ideation_system(['dna' => ['tone' => ['dialect' => 'english']]]), EN_TAIL));
t('محرك الحملات: العربي زي ما هو', ce_writer_system(['dna' => ['tone' => ['dialect' => 'egyptian']]]) === ce_prompt_sys('ce_writer', ce_prompt_vars('ce_writer', ['dna' => ['tone' => ['dialect' => 'egyptian']]]))
    && ce_ideation_system() === ce_prompt_sys('ce_ideation'));
t('محرك الحملات: الناقد مايعاقبش الإنجليزي', str_contains(ce_critic_system(['dna' => ['tone' => ['dialect' => 'english']]]), 'بالإنجليزي') && ce_critic_system() === ce_prompt_sys('ce_critic'));

/* ─── 3) النص على التصميم ─── */
echo "التصميم\n";
t('عنوان إنجليزي ← English من الشمال لليمين', str_contains(design_text_note('Bright smile in 1 hour', false, 'headline'), 'left-to-right'));
t('عنوان عربي ← Arabic من اليمين للشمال', str_contains(design_text_note('ابتسامة في ساعة', true, 'headline'), 'right-to-left'));
t('أرقام بس ← حسب اختيار اللغة', design_text_is_en('50%', true) && !design_text_is_en('50%', false));
$bt = design_brief_text(['headline' => 'Bright smile', 'subline' => 'Book today']);
t('بريف التصميم الإنجليزي مايقولش Arabic', str_contains($bt, 'English') && !str_contains($bt, 'Arabic'));
$bta = design_brief_text(['headline' => 'ابتسامة', 'subline' => 'احجز']);
t('بريف التصميم العربي زي ما كان', str_contains($bta, 'Arabic — render exactly, correct Arabic letters, right-to-left') && str_contains($bta, 'Supporting line (Arabic, smaller)'));

// الكتابة فوق الصورة (GD): الإنجليزي بيبدأ من الشمال
if (function_exists('imagecreatetruecolor') && ce_fonts()) {
    $dir = sys_get_temp_dir() . '/i18n1-' . $tag;
    @mkdir($dir);
    $bg = imagecreatetruecolor(1080, 1080);
    imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
    imagepng($bg, "$dir/bg.png");
    $leftInk = function (string $path): ?int {
        $im = @imagecreatefrompng($path);
        if (!$im) return null;
        $w = imagesx($im); $h = imagesy($im); $min = $w;
        for ($y = 0; $y < $h; $y += 3) for ($x = 0; $x < $w; $x += 3) {
            $c = imagecolorat($im, $x, $y);
            if ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255) < 200 && $x < $min) { $min = $x; break; }
        }
        return $min;
    };
    $opt = ['base' => "$dir/bg.png", 'zone' => 'bottom', 'colors' => ['#111111'], 'template' => ['align' => 'right', 'scrim' => 'none']];
    $rEn = ce_overlay_render($opt + ['headline' => 'Bright smile today', 'subline' => '', 'out' => "$dir/en.png"]);
    $rAr = ce_overlay_render($opt + ['headline' => 'ابتسامة النهارده', 'subline' => '', 'out' => "$dir/ar.png"]);
    $le = !empty($rEn['ok']) ? $leftInk("$dir/en.png") : null;
    $la = !empty($rAr['ok']) ? $leftInk("$dir/ar.png") : null;
    if ($le === null || $la === null) {
        echo '  · رسم النص فوق الصورة اتخطّى (' . json_encode([$rEn['error'] ?? null, $rAr['error'] ?? null], JSON_UNESCAPED_UNICODE) . ")\n";
    } else {
        t('الكتابة فوق الصورة: الإنجليزي بيبدأ من الشمال والعربي من اليمين', $le < 300 && $la > $le, "en=$le ar=$la");
    }
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);
}

/* ─── 4) من الموقع: اختيار English بيتحفظ وبيوصل للـ AI ─── */
echo "الموقع\n";
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?, "active", NOW(), "approved", NOW())',
    ["EN $tag", "i18n1-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, industry, dialect) VALUES (?,?,?,?)', [$uid, 'Smile Clinic ' . $tag, 'Dental clinic', 'english']);
credits_add($uid, 200, 'اختبار', null, 'admin');
db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct")');
$jar = tempnam(sys_get_temp_dir(), 'i18n');
try {
    [, $h] = http('GET', "$base/public/login.php", [], $jar);
    http('POST', "$base/public/login.php", ['csrf' => csrf_of($h), 'email' => "i18n1-$tag@test.local", 'password' => 'Test12345!'], $jar);
    [$c, $page] = http('GET', "$base/public/create-content.php", [], $jar);
    t('صفحة إنشاء المحتوى فيها English ومختار تلقائي من الهوية', $c === 200 && str_contains($page, 'data-val="english" class="on"') && str_contains($page, 'name="dialect" value="english"'));
    $log = __DIR__ . '/../storage/logs/mock-ai.jsonl';
    $before = is_file($log) ? filesize($log) : 0;
    [$c, $b] = http('POST', "$base/public/ajax/generate-content.php", ['csrf' => csrf_of($page), 'content_type' => 'introductory', 'platform' => 'both',
        'length' => 'short', 'tone' => 'simple', 'dialect' => 'english', 'extra_notes' => 'i18n1 ' . $tag], $jar, ['X-Requested-With: XMLHttpRequest']);
    $j = json_decode($b, true) ?: [];
    t('التوليد اشتغل', !empty($j['ok']), mb_substr($b, 0, 200));
    $row = db_one('SELECT dialect FROM contents WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
    t('المنشور اتحفظ بلغة english', ($row['dialect'] ?? '') === 'english', json_encode($row));
    $sent = is_file($log) ? (string) file_get_contents($log, false, null, $before) : '';
    t('البرومبت اللي وصل للـ AI فيه لغة الناتج', str_contains($sent, EN_TAIL), mb_substr($sent, 0, 120));
    $v = bk_clean_section('voice', ['dialect' => 'english']);
    t('الهوية (طريقة الكلام): English بيتحفظ', ($v['dialect'] ?? '') === 'english', json_encode($v, JSON_UNESCAPED_UNICODE));
    t('الـ JS بتاع الهوية فيه English', str_contains((string) file_get_contents(__DIR__ . '/../assets/js/brand-kit.js'), "english: 'English'"));
} finally {
    @unlink($jar);
    foreach (['contents', 'credit_transactions', 'credit_wallets', 'brand_profiles', 'user_sessions'] as $tb) {
        try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
    }
    try { db_run('DELETE FROM users WHERE id = ?', [$uid]); } catch (\Throwable $e) { echo '  · ' . $e->getMessage() . "\n"; }
}

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
