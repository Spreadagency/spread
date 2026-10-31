<?php
/**
 * Spread AI — اختبار الـ Internal API + إثبات حدود صلاحيات الـ MCP
 *
 *   php tests/internal-api-smoke.php https://ai.spreadagency.net   (أو http://localhost:8080)
 *
 * بيقرا التوكن ومستخدمين MySQL من includes/config.php — ولازم يتشغّل من جهاز مسموح له (INTERNAL_API_IPS).
 * الجزء التاني بيثبت إن مستخدمين الـ MCP مايقدروش يحذفوا ولا يلمسوا الكريدت والدفع والحسابات —
 * كل محاولة لازم ترجع «denied» من MySQL نفسه (ومفيش أي حاجة بتتغيّر فعلًا).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SPREAD_INTERNAL_API', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/internal-api-routes.php';

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/') . '/internal-api/v1/index.php';
$tok = defined('INTERNAL_API_TOKEN') ? INTERNAL_API_TOKEN : '';
$pass = 0; $fail = 0;

function call(string $route, string $scope, array $body = null, array $query = [], ?string $token = null): array
{
    global $base, $tok;
    $ch = curl_init($base . '?' . http_build_query(['r' => $route] + $query));
    $h = ['Authorization: Bearer ' . ($token ?? $tok), 'X-Scope: ' . $scope, 'X-Client: test'];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $j = str_contains($type, 'json') ? json_decode($raw, true) : null;
    return ['code' => $code, 'json' => $j, 'raw' => $raw, 'type' => $type];
}

function check(string $name, bool $ok, string $info = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($info !== '' ? '  — ' . $info : '') . "\n";
}

echo "Internal API: $base\n\n— البوابة\n";
$r = call('health', 'read'); check('health', ($r['json']['ok'] ?? false) === true, 'secure_mode=' . json_encode($r['json']['secure_mode'] ?? null));
$r = call('health', 'read', null, [], 'wrong-token'); check('توكن غلط ← 401', $r['code'] === 401);
$r = call('save_report', 'read', ['x' => 1]); check('صلاحية مش مطابقة ← 403', $r['code'] === 403);
$r = call('posts', 'ai_write'); check('مسار قراءة بصلاحية كتابة ← 403', $r['code'] === 403);
$r = call('delete_everything', 'read'); check('مسار مش موجود ← 404', $r['code'] === 404);

echo "\n— القراءة\n";
foreach (['metrics', 'posts', 'prompt_templates', 'style_presets', 'issues', 'ai_errors', 'change_requests', 'reports', 'security_logs', 'e2e_results', 'security_scan'] as $rt) {
    $r = call($rt, 'read', null, ['limit' => 3]);
    check($rt, ($r['json']['ok'] ?? false) === true, $r['json']['error'] ?? '');
}
$posts = call('posts', 'read', null, ['limit' => 1])['json']['items'] ?? [];
$pid = (int) ($posts[0]['id'] ?? 0);
if ($pid) {
    $r = call('post', 'read', null, ['post_id' => $pid]);
    check('post #' . $pid, ($r['json']['ok'] ?? false) === true);
    $img = $r['json']['designs'][0]['image'] ?? null;
    if ($img) { parse_str((string) parse_url('x?' . explode('?', $img)[1], PHP_URL_QUERY), $q); $m = call('media', 'read', null, $q); check('media (صورة بالتوكن)', $m['code'] === 200 && str_starts_with($m['type'], 'image/'), $m['type']); }
    $bid = (int) ($posts[0]['brand_id'] ?? 0);
    $b = call('brand', 'read', null, ['brand_id' => $bid]);
    $flat = json_encode($b['json'] ?? [], JSON_UNESCAPED_UNICODE);
    check('brand #' . $bid . ' من غير أرقام أو إيميلات كاملة', ($b['json']['ok'] ?? false) && !preg_match('/(?<!\d)01[0125]\d{8}(?!\d)/', $flat) && !preg_match('/[A-Za-z0-9._%+\-]{2,}@[A-Za-z0-9.\-]+\.[a-z]{2,}/', $flat));
}
$r = call('media', 'read', null, ['path' => '../includes/config.php']); check('media خارج uploads ← مرفوض', $r['code'] === 404);

echo "\n— الكتابة (قاعدة الـ AI بس)\n";
if ($pid) {
    $sc = array_fill_keys(IAPI_CONTENT_KEYS, 8);
    $r = call('save_evaluation', 'ai_write', ['post_id' => $pid, 'content_scores' => $sc, 'strengths' => 'اختبار', 'weaknesses' => 'اختبار', 'fix_suggestion' => 'اختبار', 'evaluator' => 'smoke_test']);
    check('save_evaluation', ($r['json']['ok'] ?? false) === true, 'overall=' . json_encode($r['json']['overall'] ?? null));
    $r = call('save_evaluation', 'ai_write', ['post_id' => $pid, 'content_scores' => ['hook' => 15]]);
    check('تقييم ناقص/برّه 1–10 ← مرفوض', $r['code'] === 422);
}
$r = call('save_report', 'ai_write', ['type' => 'daily', 'report_date' => '2000-01-01', 'summary' => 'تقرير اختبار من smoke test — يتجاهل']);
check('save_report', ($r['json']['ok'] ?? false) === true);
$r = call('save_security_finding', 'ai_write', ['source' => 'config', 'title' => 'smoke test', 'severity' => 'info', 'location' => 'smoke://test']);
check('save_security_finding (upsert)', ($r['json']['ok'] ?? false) === true);

echo "\n— المسودات\n";
$diff = 'smoke-' . date('YmdHis');
$r = call('propose_change', 'draft', ['type' => 'config', 'target' => 'smoke_test', 'diff' => $diff, 'reason' => 'اختبار تلقائي — يتجاهل', 'evidence' => ['n' => 1]]);
check('propose_change', ($r['json']['ok'] ?? false) === true);
$r2 = call('propose_change', 'draft', ['type' => 'config', 'target' => 'smoke_test', 'diff' => $diff, 'reason' => 'اختبار تلقائي — يتجاهل', 'evidence' => ['n' => 1]]);
check('نفس الاقتراح مايتكررش', ($r2['json']['duplicate'] ?? false) === true);

echo "\n— إثبات حدود الصلاحيات (MySQL نفسه لازم يرفض)\n";
if (!iapi_secure_mode()) {
    check('مستخدمين MySQL المحدودين متظبطين في config.php', false, 'الاختبار ده محتاج MCP_DB_* — من غيرهم الـ API شغال بمستخدم المنصة (تطوير محلي بس)');
} else {
    foreach (mcp_permission_probes() as $pr) check($pr['label'] . ' ← مرفوض', $pr['denied'], $pr['denied'] ? '' : 'اتنفذ! راجع صلاحيات ' . $pr['scope']);
}

echo "\n— السجل\n";
try {
    $n = (int) db_one('SELECT COUNT(*) n FROM ' . mcp_t('mcp_audit_log') . ' WHERE client = "test" AND created_at >= NOW() - INTERVAL 5 MINUTE')['n'];
    check('كل طلب متسجّل في mcp_audit_log', $n >= 10, $n . ' طلب');
} catch (\Throwable $e) {
    check('mcp_audit_log', false, $e->getMessage());
}

// تنضيف بيانات الاختبار (بمستخدم المنصة — مستخدمين الـ MCP مالهمش صلاحية حذف أصلًا)
try {
    db_run('DELETE FROM ' . mcp_t('ai_evaluations') . ' WHERE evaluator = "smoke_test"');
    db_run('DELETE FROM ' . mcp_t('ai_reports') . ' WHERE report_date = "2000-01-01"');
    db_run('DELETE FROM ' . mcp_t('security_findings') . ' WHERE location = "smoke://test"');
    db_run('DELETE FROM ' . mcp_t('change_requests') . ' WHERE target = "smoke_test"');
    echo "\n(بيانات الاختبار اتمسحت)\n";
} catch (\Throwable $e) {
    echo "\n⚠️ تنضيف بيانات الاختبار: " . $e->getMessage() . "\n";
}

echo "\nالنتيجة: {$pass} نجح · {$fail} فشل\n";
exit($fail ? 1 : 0);
