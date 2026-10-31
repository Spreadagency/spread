<?php
/**
 * Spread AI — محرك المحتوى: الـ worker (المرحلة D)
 * بيشغّل طابور الشغل (ce_jobs) خطوة خطوة لحد ~50 ثانية في كل مرة.
 *
 * cPanel → Cron Jobs → كل دقيقة (* * * * *):
 *   /usr/local/bin/php /home/USER/public_html/cron/ce-worker.php cron_secret=YOUR_SECRET
 * نفس cron_secret بتاع «النشر المجدول» (الأدمن ← التكاملات).
 * من غير الـ cron الشغل برضه بيكمّل طول ما العميل فاتح الصفحة (الواجهة بتطلب الخطوات بنفسها) — بس أبطأ.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/credits.php';

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

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ce-jobs.php';
if (!ce_ready()) die("Content engine tables missing — run migration 2026_ce_engine.sql\n");

// نسخة واحدة بس في نفس الوقت
$lock = fopen(sys_get_temp_dir() . '/spread_ce_worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) die("Another worker is running\n");

try { set_setting('ce_worker_last_run', date('Y-m-d H:i:s')); } catch (\Throwable $e) {}
$n = ce_worker_tick(50);
echo date('Y-m-d H:i:s') . " — ran $n step(s)\n";

// المرحلة 7: «الوكيل» — المهام الشغالة بتتحرك مرحلة (والواقفة بتكمّل لوحدها لو السبب اتحل)
try {
    require_once __DIR__ . '/../includes/assistants.php';
    $ag = as_agent_tick_all(10);
    if ($ag) echo "agent runs advanced: $ag\n";
} catch (\Throwable $e) {
    error_log('agent tick: ' . $e->getMessage());
}

// حلقة التعلّم: تقرير الشهر اللي فات (مرة واحدة أول ما الشهر يبدأ)
try {
    require_once __DIR__ . '/../includes/ce-learning.php';
    $lr = ce_learning_maybe_monthly();
    // النشر من المحرك: اللي معاده جه بيتنشر (كل دقيقة)
    require_once __DIR__ . '/../includes/ce-publish.php';
    $pb = ce_publish_tick(5);
    if (array_sum($pb)) echo 'publish: ' . json_encode($pb) . "\n";
    // Meta Insights: سحب الأرقام كل 10 دقايق + ربط تلقائي كل 6 ساعات
    $mt = ce_meta_tick();
    if ($mt) echo 'meta insights: ' . json_encode($mt) . "\n";
    if ($lr) echo 'learning report ' . $lr['period'] . ': global n=' . ($lr['global']['n'] ?? 0) . ' · skills ' . $lr['skills'] . ' · brands ' . $lr['users'] . "\n";
} catch (\Throwable $e) {
    error_log('ce learning: ' . $e->getMessage());
}
