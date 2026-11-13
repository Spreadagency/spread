<?php
/**
 * POST /api/wa/inbound.php   (n8n ← رد العميل على الواتساب)
 *   Header: X-Spread-Key = wa_shared_secret
 *   Body (JSON أو form): { "phone": "2010…", "text": "…", "id": "رقم الرسالة عند المزود (اختياري — بيمنع التكرار)" }
 *   الرد: { ok, id, matched, action }   action = none | approve | stop
 * الرسالة بتتخزن كبيانات وبتتعرض escaped — الكلمتين اللي بيتنفّذوا «موافق» و«إيقاف» بس.
 */
require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/../../../includes/rate-limit.php';
require_once __DIR__ . '/../../../includes/messaging.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') wa_json(['ok' => false, 'error' => 'method'], 405);
if (!msg_inbound_enabled()) wa_json(['ok' => false, 'error' => 'disabled'], 403);
if (!rate_limit('wa_inbound', client_ip(), max(10, (int) get_setting('wa_inbound_rate_per_min', '120')), 60)) wa_json(['ok' => false, 'error' => 'rate_limit'], 429);

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$phone = is_scalar($in['phone'] ?? null) ? (string) $in['phone'] : '';
$text = is_scalar($in['text'] ?? null) ? (string) $in['text'] : '';
$pid = is_scalar($in['id'] ?? null) ? (string) $in['id'] : '';

$r = msg_inbound_receive($phone, $text, $pid);
if (!$r['ok']) wa_json(['ok' => false, 'error' => $r['error'] ?? 'error'], 422);
wa_json(['ok' => true, 'id' => (int) $r['id'], 'matched' => !empty($r['user_id']), 'action' => $r['action'] ?? 'none', 'duplicate' => !empty($r['duplicate'])]);
