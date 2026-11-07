<?php
/**
 * Spread AI — إدخال نتايج فحوصات الأمان في قاعدة الـ AI (security_findings) — MCP المرحلة 8
 * بتظهر في «الأمان ← ملاحظات الأمان»، وClaude بيقراها في مراجعة الأمان.
 *
 *   php tests/security/import-findings.php zap  zap-report.json     (OWASP ZAP baseline: -J zap-report.json)
 *   php tests/security/import-findings.php npm  npm-audit.json      (npm audit --json داخل mcp-server)
 *
 * اللي كان مفتوح من نفس المصدر ومش موجود في التقرير الجديد بيتعلّم «اتصلّح».
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mcp-config.php';
[$mode, $file] = [$argv[1] ?? '', $argv[2] ?? ''];
$json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!in_array($mode, ['zap', 'npm'], true) || !is_array($json)) exit("الاستخدام: php tests/security/import-findings.php zap|npm report.json\n");

$items = [];
if ($mode === 'zap') {
    $risk = ['0' => 'info', '1' => 'low', '2' => 'medium', '3' => 'high'];
    foreach ((array) ($json['site'] ?? []) as $site) {
        foreach ((array) ($site['alerts'] ?? []) as $a) {
            $uri = (string) ($a['instances'][0]['uri'] ?? ($site['@name'] ?? ''));
            $items[] = ['title' => (string) ($a['name'] ?? $a['alert'] ?? 'ZAP'), 'severity' => $risk[(string) ($a['riskcode'] ?? '0')] ?? 'info',
                        'location' => 'zap:' . ($a['pluginid'] ?? '') . ':' . parse_url($uri, PHP_URL_PATH),
                        'description' => strip_tags((string) ($a['desc'] ?? '')) . "\nأماكن: " . count((array) ($a['instances'] ?? [])) . ' · مثال: ' . $uri,
                        'recommendation' => strip_tags((string) ($a['solution'] ?? ''))];
        }
    }
} else {
    $map = ['critical' => 'critical', 'high' => 'high', 'moderate' => 'medium', 'low' => 'low', 'info' => 'info'];
    foreach ((array) ($json['vulnerabilities'] ?? []) as $name => $v) {
        $via = array_values(array_filter((array) ($v['via'] ?? []), 'is_array'));
        $items[] = ['title' => 'مكتبة فيها ثغرة: ' . $name, 'severity' => $map[$v['severity'] ?? 'low'] ?? 'low', 'location' => 'npm:' . $name,
                    'description' => ($via[0]['title'] ?? '') . ' ' . ($via[0]['url'] ?? '') . ' · الإصدارات: ' . ($v['range'] ?? ''),
                    'recommendation' => !empty($v['fixAvailable']) ? 'npm audit fix (أو حدّث المكتبة) ← npm run build ← restart' : 'مفيش تحديث لسه — راقبها أو استبدل المكتبة'];
    }
}
$src = $mode === 'zap' ? 'zap' : 'dependency';
$seen = [];
foreach ($items as $i) {
    $loc = mb_substr($i['location'], 0, 500);
    $seen[] = $loc;
    db_run('INSERT INTO ' . mcp_t('security_findings') . ' (source, title, severity, location, description, recommendation) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), severity = VALUES(severity), description = VALUES(description), recommendation = VALUES(recommendation), last_seen = NOW(),
              status = IF(status IN ("fixed"), "open", status), fixed_at = IF(status = "fixed", NULL, fixed_at)',
        [$src, mb_substr($i['title'], 0, 255), $i['severity'], $loc, mb_substr($i['description'], 0, 4000), mb_substr($i['recommendation'], 0, 4000)]);
}
$fixed = 0;
foreach (db_all('SELECT id, location FROM ' . mcp_t('security_findings') . ' WHERE source = ? AND status IN ("open","in_progress")', [$src]) as $r) {
    if (!in_array($r['location'], $seen, true)) { db_run('UPDATE ' . mcp_t('security_findings') . ' SET status = "fixed", fixed_at = NOW() WHERE id = ?', [(int) $r['id']]); $fixed++; }
}
echo count($items) . " ملاحظة اتسجلت · $fixed اتعلّمت «اتصلّحت»\n";
