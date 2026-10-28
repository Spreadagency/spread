<?php
/**
 * Graph API وهمي لاختبارات Meta Insights (للتطوير المحلي بس):
 *   php -S 127.0.0.1:8093 tests/mock-graph.php
 * الحالة في ملف JSON (المسار في MOCK_GRAPH_STATE أو temp/spread-mock-graph.json):
 *   {"pages": {"<page>": [{"id","message","created_time"}]}, "ig": {"<ig>": [{"id","caption","timestamp"}]},
 *    "metrics": {"<post>": {"name": value…}}, "deprecated": false}
 * التوكنز: tok_expired ← 190 · tok_noins ← insights مرفوضة (code 10) · غير كده عادي
 */
header('Content-Type: application/json');
$state = json_decode((string) @file_get_contents(getenv('MOCK_GRAPH_STATE') ?: sys_get_temp_dir() . '/spread-mock-graph.json'), true) ?: [];
$path = trim(preg_replace('#^/v[0-9.]+/#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
$tok = (string) ($_GET['access_token'] ?? '');
$err = function (int $code, string $msg, int $http = 400) {
    http_response_code($http);
    echo json_encode(['error' => ['message' => $msg, 'type' => 'OAuthException', 'code' => $code]]);
    exit;
};
if ($tok === 'tok_expired') $err(190, 'Error validating access token: Session has expired');
$parts = explode('/', $path);
$id = $parts[0] ?? '';
$edge = $parts[1] ?? '';
$m = $state['metrics'][$id] ?? [];

if ($edge === 'posts') {
    echo json_encode(['data' => array_map(fn($p) => $p + ['permalink_url' => 'https://facebook.com/' . $p['id']], $state['pages'][$id] ?? [])]);
    exit;
}
if ($edge === 'media') {
    echo json_encode(['data' => array_map(fn($p) => $p + ['permalink' => 'https://instagram.com/p/' . $p['id']], $state['ig'][$id] ?? [])]);
    exit;
}
if ($edge === 'insights') {
    if ($tok === 'tok_noins') $err(10, '(#10) Requires read_insights permission', 403);
    $want = explode(',', (string) ($_GET['metric'] ?? ''));
    $isFb = str_contains($id, '_');
    if ($isFb && !empty($state['deprecated']) && array_intersect($want, ['post_impressions', 'post_impressions_unique'])) $err(100, '(#100) The value must be a valid insights metric');
    if (!$isFb && array_diff($want, ['reach', 'saved', 'shares', 'views', 'impressions'])) $err(100, '(#100) metric invalid');
    $data = [];
    foreach ($want as $k) if (array_key_exists($k, $m)) $data[] = $isFb ? ['name' => $k, 'period' => 'lifetime', 'values' => [['value' => $m[$k]]]] : ['name' => $k, 'total_value' => ['value' => $m[$k]]];
    echo json_encode(['data' => $data]);
    exit;
}
// البوست نفسه
foreach ($state['pages'] ?? [] as $page => $posts) foreach ($posts as $p) if ($p['id'] === $id) {
    echo json_encode(['id' => $id, 'from' => ['id' => (string) $page], 'created_time' => $p['created_time'], 'permalink_url' => 'https://facebook.com/' . $id,
        'reactions' => ['data' => [], 'summary' => ['total_count' => $m['reactions'] ?? 0]], 'comments' => ['data' => [], 'summary' => ['total_count' => $m['comments'] ?? 0]],
        'shares' => ['count' => $m['shares'] ?? 0]]);
    exit;
}
foreach ($state['ig'] ?? [] as $acc => $media) foreach ($media as $p) if ($p['id'] === $id) {
    echo json_encode(['id' => $id, 'owner' => ['id' => (string) $acc], 'timestamp' => $p['timestamp'], 'permalink' => 'https://instagram.com/p/' . $id,
        'like_count' => $m['like_count'] ?? 0, 'comments_count' => $m['comments_count'] ?? 0]);
    exit;
}
$err(100, 'Unsupported get request. Object with ID \'' . $id . '\' does not exist', 400);
