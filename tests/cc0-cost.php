<?php
/**
 * Spread AI — خطة الحملات · المرحلة 0: تقرير تكلفة الحملة (قراية بس)
 * للتطوير المحلي بس — بيعمل عمليات تجريبية في ai_runs ويمسحها، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/cc0-cost.php http://localhost:8080
 *
 * محتاج: tests/dev-setup.php اتشغّل (أدمن owner@app.test والمزوّد الوهمي).
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campaign-cost.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array $post = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b];
}

$t0 = date('Y-m-d H:i:s');
$savedSteps = get_setting('cc_steps_json', null);
$runIds = [];
try {

echo "شكل الحملة\n";
$sh = cc_shape(['posts' => 999, 'slides' => 0, 'improve' => 5, 'campaigns' => 'x']);
t('الأرقام بتتقص لحدود معقولة والغلط بيرجع للافتراضي', $sh['posts'] === 60 && $sh['slides'] === 1 && $sh['improve'] == 1.0 && $sh['campaigns'] === 2, json_encode($sh));

echo "المعادلتين\n";
$steps = [
    'plan' => ['usd' => 0.01, 'source' => 'estimate'], 'write' => ['usd' => 0.02, 'source' => 'estimate'], 'dwrite' => ['usd' => 0.005, 'source' => 'estimate'],
    'image' => ['usd' => 0.04, 'source' => 'estimate'], 'video' => ['usd' => 0.03, 'source' => 'estimate'],
];
$shape = cc_shape(['posts' => 10, 'carousel_pct' => 20, 'slides' => 5, 'videos' => 2, 'edits' => 4, 'improve' => 0.5, 'plan_regens' => 2, 'retry' => 1.0]);
$cc = cc_campaign_cost($steps, $shape);
// 12 قطعة ← 3 دفعات · 2 كاروسيل × 5 + 8 = 18 صورة · شريحة = 0.045
$avg = 3 * 0.01 + 10 * 1.5 * 0.02 + 18 * 0.045 + 2 * 0.03;
t('المتوسط = الدفعات + الكتابة بالتحسين + الصور × (كاتب + صورة) + الفيديو', abs($cc['avg_usd'] - round($avg, 4)) < 0.0001 && $cc['frames'] === 18 && $cc['batches'] === 3, json_encode([$cc['avg_usd'], round($avg, 4)]));
$worst = 3 * 3 * 0.01 + 10 * 2 * 0.02 + 18 * 0.045 + 4 * max(0.02, 5 * 0.045) + 2 * 0.03;
t('أقصى حالة = إعادات الخطة + تحسين لكل منشور + رصيد التعديلات على أغلى تعديل', abs($cc['worst_usd'] - round($worst, 4)) < 0.0001, json_encode([$cc['worst_usd'], round($worst, 4)]));
$cc2 = cc_campaign_cost($steps, cc_shape(['posts' => 10, 'carousel_pct' => 20, 'slides' => 5, 'videos' => 2, 'edits' => 4, 'improve' => 0.5, 'plan_regens' => 2, 'retry' => 1.5]));
t('معامل المحاولات التانية بيضرب أقصى حالة بس', abs($cc2['worst_usd'] - round($worst * 1.5, 4)) < 0.0001 && $cc2['avg_usd'] === $cc['avg_usd']);
$steps['image'] = ['usd' => null, 'source' => 'unknown'];
$cc3 = cc_campaign_cost($steps, $shape);
t('خطوة تكلفتها مجهولة ← بتظهر ومابتدخلش الحساب', $cc3['unknown'] === ['image'] && $cc3['avg_usd'] < $cc['avg_usd']);
$steps['image'] = ['usd' => 0.04, 'source' => 'default'];
t('سعر افتراضي ← بيتحسب وبيتعلّم', cc_campaign_cost($steps, $shape)['defaulted'] === ['image']);

echo "مصدر الأرقام\n";
$st = cc_step_costs('standard');
t('الخطوات الخمسة بموديل من البوابة', count($st) === 5 && !array_filter($st, fn($s) => $s['model'] === null), json_encode(array_column($st, 'model')));
t('من غير عمليات كفاية ← تقدير (مش «حقيقي»)', $st['write']['source'] !== 'measured' && $st['write']['usd'] > 0, $st['write']['source']);
// 6 عمليات كتابة بتكلفة معروفة على نفس الموديل ← المتوسط الحقيقي
$model = explode(' · ', (string) $st['write']['model'])[1] ?? 'mock-text';
for ($i = 0; $i < 6; $i++) {
    $runIds[] = (int) db_insert('INSERT INTO ai_runs (run_key, task, feature, status, provider, model, tokens_in, tokens_out, cost_usd, created_at) VALUES (?,?,?,?,?,?,?,?,?, NOW())',
        ['cc0-' . bin2hex(random_bytes(6)), 'content', 'cc0_test', 'ok', 'openai', $model, 5200, 800, 0.012]);
}
$runIds[] = (int) db_insert('INSERT INTO ai_runs (run_key, task, feature, status, provider, model, tokens_in, tokens_out, cost_usd, created_at) VALUES (?,?,?,?,?,?,?,?,?, NOW())',
    ['cc0-' . bin2hex(random_bytes(6)), 'content', 'cc0_test', 'ok', 'openai', $model, 5200, 800, 0]);
$m = cc_measured('content', $model);
t('6 عمليات بتكلفة + واحدة من غير ← «حقيقي» والمجهولة مابتدخلش المتوسط', $m && $m['priced'] >= 6 && $m['unknown'] >= 1 && abs($m['usd'] - 0.012) < 0.002, json_encode($m));
$st2 = cc_step_costs('standard');
t('خطوة الكتابة بقت بالمتوسط الحقيقي', $st2['write']['source'] === 'measured' && $st2['write']['runs'] >= 6);
set_setting('cc_steps_json', json_encode(['dwrite' => ['in' => 9000, 'out' => 9000], 'bogus' => ['in' => 1]]));
$st3 = cc_steps();
t('التوكنز المتوقعة بتتعدل من الإعداد (والمفاتيح الغريبة بتتجاهل)', $st3['dwrite']['in'] === 9000 && !isset($st3['bogus']));

echo "التقرير\n";
$rep = cc_report(['campaigns' => 2]);
$row = $rep['rows'][0] ?? null;
t('صف لكل باقة شغالة بالسعر والتكلفة الشهرية والهامش', $row && count($rep['rows']) === (int) db_one('SELECT COUNT(*) n FROM credit_packages WHERE is_active = 1')['n']
    && $row['month_avg_egp'] > 0 && $row['month_worst_egp'] >= $row['month_avg_egp'] && ($row['price'] === null || $row['margin_avg'] !== null), json_encode($row, JSON_UNESCAPED_UNICODE));
t('الشهر = حملتين × الحملة', $row && abs($row['month_avg_egp'] - 2 * $row['campaign_avg_egp']) < 0.05);
t('التقرير ماكتبش أي حاجة في سجل الطلبات', (int) db_one('SELECT COUNT(*) n FROM ai_runs WHERE created_at >= ? AND feature <> "cc0_test"', [$t0])['n'] === 0);

echo "الصفحة\n";
$jar = tempnam(sys_get_temp_dir(), 'cc0');
[, $h] = http('GET', $base . '/admin/login.php', [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $h, $mm);
http('POST', $base . '/admin/login.php', ['csrf' => $mm[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c, $h] = http('GET', $base . '/admin/campaign-cost.php?posts=8&campaigns=3', [], $jar);
t('صفحة الأدمن بتفتح بالأرقام من الفورم', $c === 200 && str_contains($h, 'تكلفة الحملة') && str_contains($h, 'value="8"') && str_contains($h, '3 حملة في الشهر'), (string) $c);
t('فيها جدول الباقات وتكلفة كل خطوة', str_contains($h, 'لكل باقة') && str_contains($h, 'تكلفة كل خطوة') && str_contains($h, 'content'));
[$c2] = http('GET', $base . '/admin/campaign-cost.php');
t('من غير دخول ← مقفولة (تحويل لصفحة الدخول)', $c2 === 302, (string) $c2);
@unlink($jar);

} finally {
    if ($runIds) db_run('DELETE FROM ai_runs WHERE id IN (' . implode(',', array_map('intval', $runIds)) . ')');
    if ($savedSteps === null) db_run('DELETE FROM settings WHERE setting_key = ?', ['cc_steps_json']); else set_setting('cc_steps_json', (string) $savedSteps);
}
t('رجّعنا البيانات', !db_one('SELECT id FROM ai_runs WHERE feature = "cc0_test"') && get_setting('cc_steps_json', null) === $savedSteps);
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
