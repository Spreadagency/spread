<?php
declare(strict_types=1);

/** GET /api/status.php?id= — polled every 2 s while generating. */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

require_method('GET');
$lead = require_lead();
// Release the session lock so parallel polls don't queue behind each other.
session_write_close();

$genId = (int) ($_GET['id'] ?? 0);
GenerationService::reapStuck($genId);
$gen = q_row('SELECT * FROM generations WHERE id = ? AND lead_id = ?', [$genId, $lead['id']]);
if (!$gen) {
    json_response(['error' => 'Not found'], 404);
}
json_response(generation_payload($gen));
