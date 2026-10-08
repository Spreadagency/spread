<?php
declare(strict_types=1);

/**
 * POST /api/generate.php {id, csrf} — step 3.
 * Checks the limits, claims the row and runs Gemini.
 * On PHP-FPM / LiteSpeed the response ("processing") is flushed first and
 * the work continues in the same process; the page polls status.php.
 * Elsewhere it runs synchronously and returns the final result.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

require_method('POST');
$in = input_json();
csrf_require($in);
$lead = require_lead();

if (Settings::get('gemini_api_key', '') === '') {
    log_error('generate.php called but gemini_api_key is empty');
    json_response(['status' => 'failed', 'message' => 'الخدمة مش متاحة دلوقتي، كلّمنا على واتساب.'], 503);
}

$genId = (int) ($in['id'] ?? ($_SESSION['gen_id'] ?? 0));
$gen = q_row('SELECT * FROM generations WHERE id = ? AND lead_id = ?', [$genId, $lead['id']]);
if (!$gen) {
    json_response(['error' => 'ارفع صورتك الأول.', 'code' => 'no_upload'], 404);
}

GenerationService::reapStuck($genId);
$gen = q_row('SELECT * FROM generations WHERE id = ?', [$genId]);

if ($gen['status'] === 'done') {
    json_response(generation_payload($gen));
}
if ($gen['status'] === 'processing') {
    json_response(['status' => 'processing', 'id' => $genId]);
}
if ($gen['status'] === 'rejected') {
    json_response(generation_payload($gen));
}

$ip = client_ip();
$cookie = device_cookie();
if ($blocked = GenerationService::checkLimits($lead, $ip, $cookie)) {
    if ($blocked['status'] === 'cap') {
        app_log('warning', 'Daily generation cap reached');
    }
    json_response($blocked);
}

if (!GenerationService::claim($genId, $ip, $cookie)) {
    json_response(['status' => 'processing', 'id' => $genId]);
}
log_event('generate', (int) $lead['id'], $genId, clean_event_id(str_in($in, 'event_id', 64)));

if (can_finish_request()) {
    // Answer now, keep working after the connection is closed.
    http_response_code(202);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => 'processing', 'id' => $genId]);
    finish_request();
    GenerationService::run($genId);
    exit;
}

// Synchronous fallback: release the session lock so tracking beacons aren't blocked.
session_write_close();
json_response(GenerationService::run($genId));
