<?php
/**
 * POST /api/wa/callback.php
 *   { "message_id": 123, "status": "sent"|"failed", "provider_id": "3EB0…", "error": "" }
 *   أو مجموعة: { "results": [ {…}, {…} ] }
 * → { "ok": true }
 */
require_once __DIR__ . '/_boot.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') wa_json(['ok' => false, 'error' => 'method'], 405);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$list = isset($in['results']) && is_array($in['results']) ? $in['results'] : [$in];

$done = 0;
foreach ($list as $r) {
    if (!is_array($r)) continue;
    $id = (int) ($r['message_id'] ?? $r['id'] ?? 0);
    $status = (string) ($r['status'] ?? '') === 'sent' ? 'sent' : 'failed';
    if ($id <= 0) continue;
    if (wa_callback($id, $status, (string) ($r['provider_id'] ?? ''), (string) ($r['error'] ?? ''))) $done++;
}
wa_json(['ok' => true, 'updated' => $done]);
