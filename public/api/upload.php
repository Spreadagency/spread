<?php
declare(strict_types=1);

/**
 * POST /api/upload.php (multipart: photo, csrf) — step 2.
 * Validates by content, re-encodes to a clean JPEG outside the web root
 * and creates a generation row in "uploaded" state.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

require_method('POST');
csrf_require($_POST);
$lead = require_lead();

// Already has a finished result and no regeneration allowed → return it instead.
$done = q_row("SELECT * FROM generations WHERE lead_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$lead['id']]);
$doneCount = (int) q_value("SELECT COUNT(*) FROM generations WHERE lead_id = ? AND status = 'done'", [$lead['id']]);
if ($done && !$lead['regen_allowed'] && $doneCount >= max(1, Settings::int('limit_per_phone', 1))) {
    json_response(generation_payload($done) + ['returning' => true]);
}

// Abuse guard: at most 10 uploads per lead per day.
if (!RateLimiter::hit('upload', 'lead:' . $lead['id'], 10, 86400)) {
    json_response(['error' => 'رفعت صور كتير النهارده، جرّب بكرة.', 'code' => 'rate_limited'], 429);
}

try {
    $rel = ImageService::storeOriginal($_FILES['photo'] ?? []);
} catch (ImageException $e) {
    json_response(['error' => $e->getMessage(), 'code' => 'bad_image'], 422);
}

// Replace previous uploads that never started generating (changed photo).
foreach (q("SELECT id, original_path FROM generations WHERE lead_id = ? AND status = 'uploaded'", [$lead['id']])->fetchAll() as $old) {
    ImageService::deleteFile(ORIGINALS_PATH, $old['original_path']);
    q("DELETE FROM generations WHERE id = ? AND status = 'uploaded'", [$old['id']]);
}

q("INSERT INTO generations (lead_id, original_path, status, ip, device_cookie, created_at) VALUES (?, ?, 'uploaded', ?, ?, ?)", [
    $lead['id'], $rel, client_ip(), device_cookie(), utc_now(),
]);
$genId = (int) db()->lastInsertId();
$_SESSION['gen_id'] = $genId;
log_event('upload', (int) $lead['id'], $genId);

json_response(['ok' => true, 'status' => 'uploaded', 'id' => $genId]);
