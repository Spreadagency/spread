<?php
declare(strict_types=1);

/**
 * POST /api/event.php — browser tracking beacons.
 * Body: {type, event_id, csrf, page_url}. Mirrors Contact / ViewContent /
 * ShareResult to the Conversions API with the same event_id, and fires the
 * WhatsApp webhook.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

require_method('POST');
$in = input_json();
csrf_require($in);

const ALLOWED = ['whatsapp_click', 'website_click', 'share', 'download', 'call_click', 'directions_click', 'view_result'];
$type = (string) ($in['type'] ?? '');
if (!in_array($type, ALLOWED, true)) {
    json_response(['error' => 'Unknown event'], 422);
}
if (!RateLimiter::hit('event', 'ip:' . client_ip(), 120, 3600)) {
    json_response(['ok' => false], 429);
}

$lead = current_lead();
$leadId = $lead ? (int) $lead['id'] : null;
$gen = $lead ? q_row("SELECT * FROM generations WHERE lead_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$leadId]) : null;
$eventId = clean_event_id(str_in($in, 'event_id', 64));

// One view_result per generation is enough for reporting.
if ($type === 'view_result' && $gen && q_value("SELECT 1 FROM events WHERE type = 'view_result' AND generation_id = ? LIMIT 1", [$gen['id']])) {
    json_response(['ok' => true, 'dup' => true]);
}

log_event($type, $leadId, $gen ? (int) $gen['id'] : null, $eventId, array_filter(['place' => str_in($in, 'place', 40)]));

$ctx = PixelService::context(str_in($in, 'page_url', 500));
$capi = ['whatsapp_click' => 'Contact', 'view_result' => 'ViewContent', 'share' => 'ShareResult'][$type] ?? null;
if ($capi) {
    defer(static fn () => PixelService::send($capi, $lead, $eventId, $ctx));
}
if ($type === 'whatsapp_click' && $lead) {
    defer(static fn () => WebhookService::dispatch('whatsapp_click', $lead, $gen));
}

json_response(['ok' => true]);
