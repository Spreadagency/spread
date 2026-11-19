<?php
/**
 * Spread AI — التقرير اليومي لوحده (MCP المرحلة 7)
 *
 * Claude بيعمل المراجعة اليومية (برومبت daily_review) — ولو اتجدولت عنده بتيجي لوحدها كل يوم الصبح.
 * الموقع بيضمن إن التقرير يوصلك في كل الأحوال:
 *   الساعة اللي بتحددها (افتراضي 9 الصبح بتوقيت القاهرة) الـ cron بيبص على تقرير امبارح:
 *     موجود من Claude ← تنبيه + إيميل «التقرير جاهز»
 *     مش موجود ← تقرير تلقائي من أرقام الموقع (القمع · الجودة · الـ AI والتكلفة · الإيراد · المشاكل · اللي مستنيك)
 *                 + خلاصة قصيرة بالـ AI (اختياري) ← تنبيه + إيميل «تقرير تلقائي — Claude ماشتغلش»
 *   Claude لو حفظ بعدها، تقريره بيحل محل التلقائي (نفس اليوم) — والتلقائي عمره ما بيكتب فوق تقرير Claude.
 */

require_once __DIR__ . '/mcp-config.php';

function dr_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_one('SELECT id FROM ' . mcp_t('ai_reports') . ' LIMIT 1'); return $ok = true; } catch (\Throwable $e) { return $ok = false; }
}

function dr_get(string $day, string $type = 'daily'): ?array
{
    return dr_ready() ? (db_one('SELECT * FROM ' . mcp_t('ai_reports') . ' WHERE type = ? AND report_date = ?', [$type, $day]) ?: null) : null;
}

/** المصدر: auto (من الموقع) · claude */
function dr_source(?array $r): string
{
    if (!$r) return '';
    $p = json_decode((string) ($r['payload'] ?? ''), true);
    return is_array($p) && ($p['source'] ?? '') === 'auto' ? 'auto' : 'claude';
}

/* ═══════════════════ الأرقام ═══════════════════ */

/** أرقام يوم واحد (من غير AI) */
function dr_day_numbers(string $day): array
{
    $a = $day . ' 00:00:00';
    $b = date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00';
    $v = function (string $sql, array $p = []) use ($a, $b): float {
        try { return (float) (db_one($sql, array_merge([$a, $b], $p))['v'] ?? 0); } catch (\Throwable $e) { return 0.0; }
    };
    $posts = $v('SELECT COUNT(*) v FROM contents WHERE created_at >= ? AND created_at < ?');
    $regen = $v('SELECT COUNT(*) v FROM content_versions WHERE version_type = "ai" AND version_number > 1 AND created_at >= ? AND created_at < ?');
    $runs = $v('SELECT COUNT(*) v FROM ai_runs WHERE created_at >= ? AND created_at < ? AND COALESCE(sandbox,0) = 0');
    return [
        'signups' => $v('SELECT COUNT(*) v FROM users WHERE created_at >= ? AND created_at < ?'),
        'posts' => $posts,
        'designs' => $v('SELECT COUNT(*) v FROM content_designs WHERE created_at >= ? AND created_at < ?'),
        'published' => $v('SELECT COUNT(*) v FROM contents WHERE published_at >= ? AND published_at < ?')
                     + $v('SELECT COUNT(*) v FROM ce_publish_queue WHERE status = "published" AND published_at >= ? AND published_at < ?'),
        'regen_pct' => $posts > 0 ? round($regen / $posts * 100, 1) : 0.0,
        'up' => $v('SELECT COUNT(*) v FROM contents WHERE feedback = 1 AND created_at >= ? AND created_at < ?'),
        'down' => $v('SELECT COUNT(*) v FROM contents WHERE feedback = -1 AND created_at >= ? AND created_at < ?'),
        'credits' => $v('SELECT COALESCE(SUM(ABS(amount)),0) v FROM credit_transactions WHERE action_type IN ("deduct","consume") AND created_at >= ? AND created_at < ?'),
        'ai_runs' => $runs,
        'ai_failed_pct' => $runs > 0 ? round($v('SELECT COUNT(*) v FROM ai_runs WHERE status = "failed" AND created_at >= ? AND created_at < ? AND COALESCE(sandbox,0) = 0') / $runs * 100, 1) : 0.0,
        'ai_cost' => round($v('SELECT COALESCE(SUM(cost_egp),0) v FROM ai_runs WHERE created_at >= ? AND created_at < ? AND COALESCE(sandbox,0) = 0'), 2),
        'revenue' => round($v('SELECT COALESCE(SUM(final_amount),0) v FROM payment_requests WHERE status = "approved" AND reviewed_at >= ? AND reviewed_at < ?')
                     + $v('SELECT COALESCE(SUM(amount_egp),0) v FROM payment_orders WHERE status = "paid" AND paid_at >= ? AND paid_at < ?'), 2),
        'issues' => $v('SELECT COUNT(*) v FROM user_issues WHERE created_at >= ? AND created_at < ?'),
        'publish_failed' => $v('SELECT COUNT(*) v FROM ce_publish_queue WHERE status = "failed" AND updated_at >= ? AND updated_at < ?'),
        'jobs_failed' => $v('SELECT COUNT(*) v FROM ce_jobs WHERE status = "failed" AND finished_at >= ? AND finished_at < ?'),
    ];
}

const DR_LABELS = [
    'signups' => ['تسجيلات جديدة', false], 'posts' => ['منشورات', false], 'designs' => ['تصميمات', false], 'published' => ['اتنشر', false],
    'regen_pct' => ['إعادة التوليد %', true], 'up' => ['👍', false], 'down' => ['👎', true], 'credits' => ['كريدت اتصرف', false],
    'ai_runs' => ['عمليات AI', false], 'ai_failed_pct' => ['فشل الـ AI %', true], 'ai_cost' => ['تكلفة الـ AI (ج)', true], 'revenue' => ['اتدفع (ج)', false],
    'issues' => ['بلاغات جديدة', true], 'publish_failed' => ['نشر فشل', true], 'jobs_failed' => ['شغل محرك فشل', true],
];

/** اللي مستني الأدمن دلوقتي */
function dr_pending(): array
{
    $n = function (string $sql) { try { return (int) (db_one($sql)['n'] ?? 0); } catch (\Throwable $e) { return 0; } };
    $out = [
        ['payments', 'طلبات دفع مستنية مراجعة', $n('SELECT COUNT(*) n FROM payment_requests WHERE status IN ("pending","under_review")'), 'admin/payments.php'],
        ['issues', 'بلاغات جديدة', $n('SELECT COUNT(*) n FROM user_issues WHERE status = "new"'), 'admin/ai-issues.php?status=new'],
        ['videos', 'فيديوهات مستنية التنفيذ', $n('SELECT COUNT(*) n FROM contents WHERE format = "video" AND video_status IN ("sent","in_production")'), 'admin/video-requests.php'],
        ['agent', 'مهام وكيل واقفة', $n('SELECT COUNT(*) n FROM agent_runs WHERE status = "paused"'), 'admin/assistants.php'],
    ];
    if (function_exists('aic_pending_changes')) $out[] = ['changes', 'اقتراحات ومسودات Claude', aic_pending_changes(), 'admin/ai-changes.php'];
    $ab = $n('SELECT COUNT(*) n FROM prompts WHERE status = "testing" AND traffic_percent > 0');
    if ($ab) $out[] = ['ab', 'تجارب A/B شغالة', $ab, 'admin/ai-ab.php'];
    return array_values(array_filter($out, fn($x) => $x[2] > 0));
}

/** يوم + اليوم اللي قبله + متوسط 7 أيام قبله + الملاحظات */
function dr_snapshot(string $day): array
{
    $now = dr_day_numbers($day);
    $prev = dr_day_numbers(date('Y-m-d', strtotime($day . ' -1 day')));
    $avg = array_fill_keys(array_keys($now), 0.0);
    for ($i = 1; $i <= 7; $i++) {
        foreach (dr_day_numbers(date('Y-m-d', strtotime($day . " -{$i} day"))) as $k => $x) $avg[$k] += $x / 7;
    }
    $flags = [];
    foreach ($now as $k => $x) {
        $base = $avg[$k];
        if ($base < 3 && $x < 3) continue;   // أرقام صغيرة — مفيش معنى للنسبة
        $chg = $base > 0 ? round(($x - $base) / $base * 100) : 100;
        if (abs($chg) >= 20) {
            $bad = DR_LABELS[$k][1] ? $chg > 0 : $chg < 0;
            $flags[] = ['key' => $k, 'label' => DR_LABELS[$k][0], 'value' => $x, 'avg' => round($base, 1), 'chg' => $chg, 'bad' => $bad];
        }
    }
    usort($flags, fn($p, $q) => ($q['bad'] <=> $p['bad']) ?: abs($q['chg']) <=> abs($p['chg']));
    return ['day' => $day, 'now' => $now, 'prev' => $prev, 'avg7' => array_map(fn($x) => round($x, 1), $avg), 'flags' => $flags, 'pending' => dr_pending()];
}

/* ═══════════════════ التقرير ═══════════════════ */

function dr_num($x): string
{
    return fmod((float) $x, 1.0) == 0.0 ? number_format((float) $x) : number_format((float) $x, 1);
}

/** Markdown بالعربي (نفس اللي مركز الجودة بيعرضه) */
function dr_markdown(array $s, string $summary = ''): string
{
    $md = "# التقرير اليومي التلقائي — " . $s['day'] . "\n\n";
    $md .= "> اتعمل من أرقام الموقع لأن مراجعة Claude ماوصلتش لحد الميعاد. لو Claude عمل المراجعة بعدها، تقريره هيحل محل ده.\n\n";
    $md .= "## الخلاصة\n";
    if (trim($summary) !== '') $md .= trim($summary) . "\n\n";
    $n = $s['now'];
    $md .= '- ' . dr_num($n['posts']) . ' منشور · ' . dr_num($n['designs']) . ' تصميم · ' . dr_num($n['published']) . ' اتنشر · ' . dr_num($n['signups']) . " تسجيل جديد\n";
    $md .= '- الـ AI: ' . dr_num($n['ai_runs']) . ' عملية · فشل ' . dr_num($n['ai_failed_pct']) . '% · تكلفة ' . dr_num($n['ai_cost']) . ' ج — واتدفع ' . dr_num($n['revenue']) . " ج\n";
    foreach (array_slice($s['flags'], 0, 6) as $f) {
        $md .= '- ' . ($f['bad'] ? '⚠️ ' : '✅ ') . $f['label'] . ': ' . dr_num($f['value']) . ' (' . ($f['chg'] > 0 ? '+' : '') . $f['chg'] . '% عن متوسط الأسبوع ' . dr_num($f['avg']) . ")\n";
    }
    if (!$s['flags']) $md .= "- مفيش تغيير كبير عن متوسط الأسبوع.\n";
    $md .= "\n## الأرقام\n| البند | امبارح | اليوم اللي قبله | متوسط 7 أيام |\n|---|---|---|---|\n";
    foreach (DR_LABELS as $k => [$lbl]) $md .= '| ' . $lbl . ' | ' . dr_num($n[$k]) . ' | ' . dr_num($s['prev'][$k]) . ' | ' . dr_num($s['avg7'][$k]) . " |\n";
    $md .= "\n## مستنيك\n";
    if (!$s['pending']) $md .= "- مفيش حاجة مستنية ✓\n";
    foreach ($s['pending'] as [, $lbl, $cnt]) $md .= '- ' . $lbl . ': ' . $cnt . "\n";
    $md .= "\n## للمراجعة الكاملة\n- افتح Claude بموصّل Spread AI واختار برومبت **daily_review** (تقييم أسوأ المنشورات · البلاغات · الأمان · A/B).\n";
    return $md;
}

/** خلاصة بالـ AI (3-4 سطور) — لو فشلت التقرير بيطلع من غيرها */
function dr_ai_summary(array $s): string
{
    if (get_setting('daily_report_ai_summary', '1') !== '1') return '';
    if (!function_exists('ai_generate')) require_once __DIR__ . '/ai.php';
    $prompt = "إنت محلل منصة محتوى بالذكاء الاصطناعي (Spread AI). اكتب خلاصة لصاحب المنصة بالمصري في 3-4 سطور قصيرة (من غير عناوين ومن غير أرقام مش موجودة تحت): "
        . "إيه اللي ماشي كويس، إيه اللي محتاج انتباه، وأهم حاجة يعملها النهارده.\n\nأرقام " . $s['day'] . " (JSON):\n"
        . json_encode(['امبارح' => $s['now'], 'متوسط_7_أيام' => $s['avg7'], 'تغيرات_كبيرة' => $s['flags'], 'مستني' => array_map(fn($p) => [$p[1], $p[2]], $s['pending'])], JSON_UNESCAPED_UNICODE);
    try {
        $r = ai_generate($prompt, [], ['task' => 'daily_report_auto', 'max_tokens' => 400, 'temperature' => 0.3]);
    } catch (\Throwable $e) {
        return '';
    }
    $raw = !empty($r['ok']) ? (string) $r['response'] : '';
    if (preg_match('/\[CONTENT\](.*?)\[\/CONTENT\]/su', $raw, $m)) $raw = $m[1];   // لو الموديل رد بشكل المنشورات
    $t = trim(preg_replace(['/\[\/?[A-Z_]+\].*$/su', '/^#+\s*/mu'], ['', ''], $raw));
    return mb_substr($t, 0, 1200);
}

/**
 * التقرير التلقائي ليوم (مابيكتبش فوق تقرير Claude)
 * @return array{ok:bool, id?:int, skipped?:string}
 */
function dr_make_auto(string $day): array
{
    if (!dr_ready()) return ['ok' => false, 'skipped' => 'not_ready'];
    $cur = dr_get($day);
    if ($cur && dr_source($cur) === 'claude') return ['ok' => false, 'skipped' => 'claude'];
    $s = dr_snapshot($day);
    $md = dr_markdown($s, dr_ai_summary($s));
    $payload = json_encode(['source' => 'auto', 'generated_at' => date('Y-m-d H:i:s')] + $s, JSON_UNESCAPED_UNICODE);
    db_run('INSERT INTO ' . mcp_t('ai_reports') . ' (type, report_date, summary, payload) VALUES ("daily",?,?,?)
            ON DUPLICATE KEY UPDATE summary = IF(payload LIKE \'%"source":"auto"%\', VALUES(summary), summary), payload = IF(payload LIKE \'%"source":"auto"%\', VALUES(payload), payload)',
        [$day, $md, $payload]);
    $row = dr_get($day);
    return ['ok' => dr_source($row) === 'auto', 'id' => (int) ($row['id'] ?? 0), 'skipped' => dr_source($row) === 'claude' ? 'claude' : null];
}

/* ═══════════════════ التوصيل ═══════════════════ */

/** إيميلات التقرير: من الإعدادات · وإلا أصحاب الحسابات super/owner في الأدمن */
function dr_recipients(): array
{
    $list = array_filter(array_map('trim', explode(',', (string) get_setting('daily_report_emails', ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
    if ($list) return array_values(array_unique($list));
    try {
        return array_values(array_filter(array_column(db_all('SELECT email FROM admin_users WHERE status = "active" AND role IN ("super","owner") ORDER BY id LIMIT 3'), 'email'),
            fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    } catch (\Throwable $e) {
        return [];
    }
}

/** تنبيه في الأدمن + إيميل (مرة واحدة لكل يوم ومصدر) */
function dr_notify(string $day, string $source): int
{
    $key = 'daily_report_notified_' . $day . '_' . $source;
    if (get_setting($key, '') !== '') return 0;
    set_setting($key, date('Y-m-d H:i:s'));
    $title = $source === 'auto' ? 'التقرير اليومي التلقائي جاهز (Claude ماشتغلش)' : 'تقرير Claude اليومي جاهز ✓';
    $link = 'admin/ai-report.php?type=daily';
    $r = dr_get($day);
    $teaser = $r ? mb_substr(trim(preg_replace('/[#>*|\-]+/u', ' ', (string) $r['summary'])), 0, 280) : '';
    if (function_exists('admin_alert')) { try { admin_alert($source === 'auto' ? 'warn' : 'info', 'daily_report', $title . ' — ' . $day, $teaser, $link, 'dr:' . $day . ':' . $source); } catch (\Throwable $e) {} }
    $sent = 0;
    if (get_setting('daily_report_email_on', '1') === '1') {
        if (!function_exists('send_mail')) require_once __DIR__ . '/mailer.php';
        foreach (dr_recipients() as $to) {
            try {
                $html = function_exists('build_email_template') ? build_email_template($title, $day . "\n\n" . $teaser, 'افتح التقرير', rtrim((string) url(''), '/') . '/' . $link) : nl2br(e($teaser));
                if (send_mail($to, $title . ' — ' . $day, $html, $teaser)) $sent++;
            } catch (\Throwable $e) {}
        }
    }
    return $sent;
}

/**
 * من الـ cron كل دقيقة — بيشتغل مرة واحدة في اليوم بعد الساعة المحددة
 * @return array|null نتيجة اليوم (لو اشتغل)
 */
function dr_tick(?int $hourNow = null): ?array
{
    if (!dr_ready() || get_setting('daily_report_on', '1') !== '1') return null;
    $today = date('Y-m-d');
    $hour = max(0, min(23, (int) get_setting('daily_report_hour', '9')));
    if (($hourNow ?? (int) date('G')) < $hour) return null;
    if (get_setting('daily_report_last_run', '') === $today) return null;
    set_setting('daily_report_last_run', $today);   // قفل قبل الشغل (عشان مايتكررش لو اتأخر)
    $day = date('Y-m-d', strtotime('-1 day'));
    $cur = dr_get($day);
    if ($cur && dr_source($cur) === 'claude') return ['day' => $day, 'source' => 'claude', 'emails' => dr_notify($day, 'claude')];
    if (get_setting('daily_report_auto', '1') !== '1') {
        if (function_exists('admin_alert')) { try { admin_alert('warn', 'daily_report', 'التقرير اليومي ماوصلش — ' . $day, 'Claude ماعملش المراجعة والتقرير التلقائي مقفول', 'admin/ai-report.php', 'dr:' . $day . ':missing'); } catch (\Throwable $e) {} }
        return ['day' => $day, 'source' => 'missing'];
    }
    $r = dr_make_auto($day);
    return ['day' => $day, 'source' => $r['ok'] ? 'auto' : ($r['skipped'] ?? 'error'), 'emails' => $r['ok'] ? dr_notify($day, 'auto') : 0];
}

/** Claude حفظ تقرير يومي (من الـ Internal API) ← تنبيه «جاهز» لو الميعاد عدّى خلاص */
function dr_on_claude_saved(string $type, string $day): void
{
    if ($type !== 'daily' || !dr_ready()) return;
    if (get_setting('daily_report_last_run', '') === date('Y-m-d') && $day === date('Y-m-d', strtotime('-1 day'))) dr_notify($day, 'claude');
}

/** البرومبت اللي يتحط في مهمة Claude المجدولة */
function dr_schedule_prompt(): string
{
    return "استخدم موصّل Spread AI وشغّل المراجعة اليومية (برومبت daily_review) ليوم امبارح: الأرقام · أسوأ المنشورات وتقييمها · البلاغات · أخطاء الـ AI · الأمان · A/B — واحفظ التقرير بـ spread_save_report واعرضه هنا.";
}
