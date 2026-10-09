<?php
declare(strict_types=1);

/**
 * POST /api/lead.php — step 1: save name + phone.
 * Body (JSON): name, phone, consent, csrf?, website (honeypot), cf_turnstile,
 *              utm_*, fbclid, fbp, fbc, event_id
 * The lead is saved before any upload so it is never lost.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

require_method('POST');
$in = input_json();
csrf_require($in);

// Honeypot: bots fill every field. Pretend success, store nothing.
if (!empty($in['website'])) {
    json_response(['ok' => true, 'lead' => ['first_name' => ''], 'result' => null]);
}

$ip = client_ip();
if (!RateLimiter::hit('lead', 'ip:' . $ip, Settings::int('limit_leads_per_ip_hour', 10), 3600)) {
    json_response(['error' => 'محاولات كتير من نفس الجهاز، جرّب بعد شوية.', 'code' => 'rate_limited'], 429);
}

if (!Turnstile::verify(str_in($in, 'cf_turnstile', 2048))) {
    json_response(['error' => 'مقدرناش نتأكد إنك مش روبوت، اعمل تحديث للصفحة وجرّب تاني.', 'code' => 'turnstile'], 403);
}

// ---- validation ----
$name = preg_replace('/\s+/u', ' ', (string) str_in($in, 'name', 100)) ?? '';
$name = trim(strip_tags($name));
$phone = normalize_phone(str_in($in, 'phone', 30));
$errors = [];
if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
    $errors['name'] = 'اكتب اسمك (حرفين على الأقل)';
}
if ($phone === null) {
    $errors['phone'] = 'اكتب رقم موبايل مصري صحيح';
}
if (empty($in['consent'])) {
    $errors['consent'] = 'لازم توافق على استخدام الصورة';
}
if ($errors) {
    json_response(['error' => reset($errors), 'fields' => $errors, 'code' => 'validation'], 422);
}

$utm = [];
foreach (['utm_source' => 100, 'utm_medium' => 100, 'utm_campaign' => 150, 'utm_content' => 150, 'utm_term' => 150, 'fbclid' => 255] as $k => $max) {
    $utm[$k] = str_in($in, $k, $max);
}
$ctx = PixelService::context(str_in($in, 'page_url', 500));
$fbp = $ctx['fbp'] ?? (preg_match('/^fb\.\d\.\d+\.\d+$/', (string) str_in($in, 'fbp', 100)) ? str_in($in, 'fbp', 100) : null);
$fbc = $ctx['fbc'] ?? (str_starts_with((string) str_in($in, 'fbc', 255), 'fb.') ? str_in($in, 'fbc', 255) : null);
if (!$fbc && $utm['fbclid']) {
    $fbc = 'fb.1.' . (int) (microtime(true) * 1000) . '.' . $utm['fbclid'];
}
$ctx['fbp'] = $fbp;
$ctx['fbc'] = $fbc;
$eventId = clean_event_id(str_in($in, 'event_id', 64));
$device = device_cookie();

// ---- upsert by phone ----
$existing = q_row('SELECT * FROM leads WHERE phone = ?', [$phone]);
$isNew = $existing === null;

if ($isNew) {
    try {
        q('INSERT INTO leads (name, phone, utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid, fbp, fbc, ip, user_agent, device_cookie, consent, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)', [
            $name, $phone, $utm['utm_source'], $utm['utm_medium'], $utm['utm_campaign'], $utm['utm_content'], $utm['utm_term'],
            $utm['fbclid'], $fbp, $fbc, $ip, user_agent(), $device, utc_now(), utc_now(),
        ]);
        $leadId = (int) db()->lastInsertId();
    } catch (PDOException $e) {
        // Same phone submitted twice at the same moment: fall back to the existing row.
        if ($e->getCode() !== '23000') {
            throw $e;
        }
        $existing = q_row('SELECT * FROM leads WHERE phone = ?', [$phone]);
        $isNew = false;
        $leadId = (int) $existing['id'];
    }
} else {
    $leadId = (int) $existing['id'];
    // Keep the first attribution; refresh name and click ids.
    q('UPDATE leads SET name = ?, fbp = COALESCE(?, fbp), fbc = COALESCE(?, fbc), consent = 1, updated_at = ? WHERE id = ?', [$name, $fbp, $fbc, utc_now(), $leadId]);
}

remember_lead($leadId);
$lead = q_row('SELECT * FROM leads WHERE id = ?', [$leadId]);

// Returning visitor with a finished result → show it, no new AI call.
$result = null;
$done = q_row("SELECT * FROM generations WHERE lead_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$leadId]);
if ($done && !$lead['regen_allowed']) {
    $result = generation_payload($done);
}

if ($isNew) {
    log_event('lead', $leadId, null, $eventId, array_filter(['campaign' => $utm['utm_campaign'], 'source' => $utm['utm_source']]));
    defer(static fn () => PixelService::send('Lead', $lead, $eventId, $ctx));
    defer(static fn () => WebhookService::dispatch('lead', $lead));
}

json_response([
    'ok' => true,
    'is_new' => $isNew,
    'event_id' => $isNew ? $eventId : null,
    'lead' => ['first_name' => first_name($lead['name'])],
    'result' => $result,
    'whatsapp_url' => whatsapp_link(first_name($lead['name'])),
]);
