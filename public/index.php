<?php
declare(strict_types=1);

/** Public landing page. Every text, link and ID comes from the admin settings. */
require dirname(__DIR__) . '/app/bootstrap.php';

send_page_headers();
header('Cache-Control: no-store'); // the page carries a per-session CSRF token

if (Settings::bool('maintenance_mode')) {
    http_response_code(503);
    header('Retry-After: 3600');
    require APP_PATH . '/views/maintenance.php';
    exit;
}

$S = Settings::all();
$csrf = csrf_token();
device_cookie();

// Server-side page view (dashboard "visitors"); same id as the browser PageView.
$pageViewEventId = new_event_id();
$leadId = current_lead_id();
$isPreview = !empty($_GET['preview']) || !empty($_SESSION['admin_id']); // admin preview / admins don't count as visitors
if (!$isPreview) log_event('page_view', $leadId, null, $pageViewEventId, array_filter([
    'utm_source' => str_in($_GET, 'utm_source', 100),
    'utm_campaign' => str_in($_GET, 'utm_campaign', 150),
]));

$stats = array_values(array_filter(content_items('stat'), static fn ($s) => trim((string) $s['value']) !== ''));
$experience = array_merge(content_items('experience'), content_items('certificate'));
$steps = content_items('step');
$faqs = content_items('faq');
$facts = array_column(content_items('fact'), 'title');
$branches = branches();
$canonical = Settings::get('canonical_url', '') ?: url();

// Client config for site.js (no secrets here).
$siteConfig = [
    'api' => url('api') . '/',
    'csrf' => $csrf,
    'whatsapp' => preg_replace('/\D/', '', (string) Settings::get('whatsapp_number', '')),
    'waTemplate' => Settings::get('whatsapp_message'),
    'website' => Settings::get('website_url', ''),
    'showLogoChip' => Settings::bool('show_logo_on_result'),
    'facts' => $facts ?: ['ثواني ونوريك النتيجة…'],
    'faqs' => [],
    'pixel' => Settings::get('meta_pixel_id', '') !== '',
    'pixelEvents' => [
        'Lead' => Settings::bool('pixel_event_lead'),
        'ViewContent' => Settings::bool('pixel_event_viewcontent'),
        'Contact' => Settings::bool('pixel_event_contact'),
        'ShareResult' => Settings::bool('pixel_event_share'),
    ],
    'turnstile' => Turnstile::enabled() ? Settings::get('turnstile_site_key') : '',
];

// JSON-LD
$schemas = [];
$doctorName = Settings::get('doctor_name');
if (Settings::bool('schema_physician')) {
    $schemas[] = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Physician',
        'name' => $doctorName,
        'description' => trim(Settings::get('doctor_title') . ' — ' . Settings::get('doctor_bio'), ' —'),
        'image' => url((string) Settings::get('doctor_photo')),
        'url' => $canonical,
        'medicalSpecialty' => 'Surgical',
        'telephone' => Settings::get('whatsapp_number', '') ? '+' . preg_replace('/\D/', '', (string) Settings::get('whatsapp_number')) : null,
    ]);
}
if (Settings::bool('schema_clinic') && $branches) {
    foreach ($branches as $b) {
        $schemas[] = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'MedicalClinic',
            'name' => $b['name'],
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $b['address'], 'addressCountry' => 'EG'],
            'telephone' => $b['phone'] ?: null,
            'openingHours' => $b['working_hours'] ?: null,
            'hasMap' => $b['map_url'] ?: null,
            'employee' => ['@type' => 'Physician', 'name' => $doctorName],
        ]);
    }
}
if (Settings::bool('schema_faq') && $faqs) {
    $schemas[] = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(static fn ($f) => [
            '@type' => 'Question',
            'name' => $f['title'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) $f['body']],
        ], $faqs),
    ];
}

require APP_PATH . '/views/landing.php';
