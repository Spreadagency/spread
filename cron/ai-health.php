<?php
/**
 * فحص صحة مزودين الـ AI (المرحلة 8-أ) — للتشخيص بس (الـ Circuit Breaker مش معتمد عليه)
 *
 * cPanel Cron (كل 15 دقيقة):
 *   /usr/local/bin/php /home/USER/public_html/cron/ai-health.php cron_secret=YOUR_SECRET
 *
 * - بيطلب GET /models من كل مزود مفعّل (مجاني — مابيصرفش توكنز) علشان يتأكد إن المفتاح شغال
 * - OpenRouter: بيقرا الرصيد المتبقي من /key وينبّه لو قل عن الحد (openrouter_min_balance)
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ai-log.php';
require_once __DIR__ . '/../includes/ai-gateway.php';

$provided = '';
if (php_sapi_name() === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if (strpos($arg, 'cron_secret=') === 0) $provided = substr($arg, 12);
    }
} else {
    $provided = $_GET['cron_secret'] ?? '';
}
$secret = get_setting('cron_secret', '');
if (!$secret || !hash_equals($secret, $provided)) {
    http_response_code(403);
    die('Forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
if (!usage_ready()) die("migration 8a not applied\n");

$minBal = (float) get_setting('openrouter_min_balance', '5');
foreach (ai_gw_providers() as $p) {
    $h = function (string $url) use ($p): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $p['key']]]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$http, is_string($raw) ? $raw : '', $err];
    };
    [$http, $raw, $err] = $h($p['base'] . '/models');
    $ok = $http === 200;
    $status = $ok ? 'healthy' : (in_array($http, [401, 403], true) ? 'down' : 'degraded');
    db_run('UPDATE ai_providers SET health_status = ?, last_health_check = NOW() WHERE provider_name = ?', [$status, $p['name']]);
    echo $p['name'] . ': ' . ($ok ? 'OK' : ('HTTP ' . $http . ' ' . $err)) . "\n";
    if (in_array($http, [401, 403], true)) {
        admin_alert('critical', 'provider_auth', "المزود {$p['name']}: المفتاح مرفوض (فحص دوري)", 'راجع المفتاح في «مزودين الـ AI».', 'admin/ai-providers.php', 'prov_auth:' . $p['name']);
    }
    if ($p['kind'] === 'openrouter' && $ok) {
        [$kh, $kraw] = $h($p['base'] . '/key');
        $d = json_decode($kraw, true)['data'] ?? null;
        if ($kh === 200 && is_array($d) && isset($d['limit_remaining']) && $d['limit_remaining'] !== null) {
            $left = (float) $d['limit_remaining'];
            set_setting('openrouter_balance_' . $p['name'], (string) $left);
            echo "  balance: \$$left\n";
            if ($left < $minBal) {
                admin_alert('critical', 'provider_low_balance', "رصيد {$p['name']} قرّب يخلص: \$" . number_format($left, 2),
                    "الحد المضبوط \$" . number_format($minBal, 2) . " — اشحن الرصيد قبل ما الطلبات تفشل (البوابة هتحوّل للبديل لو خلص).", 'admin/ai-providers.php', 'lowbal:' . $p['name']);
            }
        }
    }
}
set_setting('cron_health_last_run', date('Y-m-d H:i:s'));
if (function_exists('ai_log_cleanup')) ai_log_cleanup();
echo "done\n";
