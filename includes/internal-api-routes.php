<?php
/**
 * Spread AI — Internal API: المسارات (نفس أدوات الـ MCP في docs/MCP_TOOLS.md)
 *
 * route => [scope, handler]
 *   read      بيقرا من قاعدة المنصة + قاعدة الـ AI (مستخدم SELECT بس)
 *   ai_write  بيكتب في قاعدة الـ AI بس: تقييمات · تحليل بلاغات · تقارير · أمان وترابط
 *   draft     بيضيف مسودات في قاعدة الـ AI بس: اقتراحات · برومبتات · استايلات · ردود — كلها محتاجة موافقة الأدمن
 * مفيش ولا مسار بيحذف · ولا مسار بيلمس الكريدت أو الدفع أو الاشتراكات أو الحسابات أو الأفلييت.
 */

require_once __DIR__ . '/internal-api.php';

function iapi_routes(): array
{
    return [
        // ── A. قراءة ──
        'health'            => ['read', 'r_health'],
        'metrics'           => ['read', 'r_metrics'],
        'posts'             => ['read', 'r_posts'],
        'post'              => ['read', 'r_post'],
        'media'             => ['read', 'r_media'],
        'brand'             => ['read', 'r_brand'],
        'prompt_templates'  => ['read', 'r_prompt_templates'],
        'prompt_version'    => ['read', 'r_prompt_version'],
        'style_presets'     => ['read', 'r_style_presets'],
        'issues'            => ['read', 'r_issues'],
        'ai_errors'         => ['read', 'r_ai_errors'],
        'change_requests'   => ['read', 'r_change_requests'],
        'ab_results'        => ['read', 'r_ab_results'],
        'reports'           => ['read', 'r_reports'],
        // ── D. الأمان (قراءة) ──
        'security_scan'     => ['read', 'r_security_scan'],
        'security_logs'     => ['read', 'r_security_logs'],
        'e2e_results'       => ['read', 'r_e2e_results'],
        'dependency_audit'  => ['read', 'r_dependency_audit'],
        'integrity_check'   => ['read', 'r_integrity_check'],
        // ── B. تقييم وتسجيل ──
        'save_evaluation'   => ['ai_write', 'w_save_evaluation'],
        'analyze_issue'     => ['ai_write', 'w_analyze_issue'],
        'save_report'       => ['ai_write', 'w_save_report'],
        'save_security_finding' => ['ai_write', 'w_save_security_finding'],
        'save_integrity_issue'  => ['ai_write', 'w_save_integrity_issue'],
        // ── C. مسودات (موافقة الأدمن) ──
        'propose_change'    => ['draft', 'd_propose_change'],
        'draft_prompt'      => ['draft', 'd_draft_prompt'],
        'draft_style'       => ['draft', 'd_draft_style'],
        'draft_reply'       => ['draft', 'd_draft_reply'],
        'test_regenerate'   => ['draft', 'd_test_regenerate'],
    ];
}

/* ═══════════ مساعدات ═══════════ */

function iapi_json($v): ?string
{
    return $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function iapi_dec(?string $s)
{
    if ($s === null || $s === '') return null;
    $d = json_decode($s, true);
    return $d === null ? $s : $d;
}

/** فترة: date_from/date_to (افتراضي: امبارح) + الفترة اللي قبلها بنفس الطول */
function iapi_period(): array
{
    $y = date('Y-m-d', strtotime('-1 day'));
    $from = iapi_date('date_from', $y);
    $to = iapi_date('date_to', $from);
    if ($to < $from) [$from, $to] = [$to, $from];
    $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $pTo = date('Y-m-d', strtotime($from . ' -1 day'));
    $pFrom = date('Y-m-d', strtotime($pTo . ' -' . ($days - 1) . ' day'));
    return [$from . ' 00:00:00', $to . ' 23:59:59', $pFrom . ' 00:00:00', $pTo . ' 23:59:59', $days];
}

function iapi_pct($now, $prev): ?float
{
    if (!is_numeric($now) || !is_numeric($prev) || (float) $prev == 0.0) return null;
    return round(((float) $now - (float) $prev) / (float) $prev * 100, 1);
}

/** المسار المسموح لملف (داخل storage/uploads بس) */
function iapi_file_path(?string $rel): ?string
{
    if (!$rel || str_contains($rel, '..') || !preg_match('#^uploads/[A-Za-z0-9/_\-.]+\.(png|jpe?g|webp)$#i', $rel)) return null;
    $abs = dirname(__DIR__) . '/storage/' . $rel;
    return is_file($abs) ? $abs : null;
}

/** لينك الصورة للـ MCP (بيتجاب بنفس التوكن من مسار media — مش لينك عام) */
function iapi_media_ref(?string $rel): ?string
{
    return iapi_file_path($rel) ? 'media?path=' . rawurlencode((string) $rel) : null;
}

function iapi_ai_has(string $table): bool
{
    static $c = [];
    if (isset($c[$table])) return $c[$table];
    try { iq_one('SELECT 1 FROM ' . mcp_t($table) . ' LIMIT 1'); return $c[$table] = true; } catch (\Throwable $e) { return $c[$table] = false; }
}

/* ═══════════ A. قراءة ═══════════ */

function r_health(): void
{
    $ai = [];
    foreach (['ai_evaluations', 'change_requests', 'mcp_audit_log', 'ai_reports'] as $t) $ai[$t] = iapi_ai_has($t);
    iapi_ok(['service' => 'spread-internal-api', 'version' => 1, 'secure_mode' => iapi_secure_mode(), 'ai_db' => mcp_ai_db() !== DB_NAME ? 'separate' : 'same_as_platform',
             'ai_tables' => $ai, 'time' => date('c')]);
}

/** قمع الاستخدام + الجودة + الـ AI للفترة دي مقارنة باللي قبلها */
function r_metrics(): void
{
    [$from, $to, $pFrom, $pTo, $days] = iapi_period();
    $gate = (int) (get_setting('brand_gate_pct', 75) ?: 75);
    $m = function (string $a, string $b) use ($gate): array {
        $v = fn(string $sql, array $p = []) => (float) (iq_val($sql, array_merge([$a, $b], $p)) ?? 0);
        $posts = $v('SELECT COUNT(*) FROM contents WHERE created_at BETWEEN ? AND ?');
        $regen = $v('SELECT COUNT(*) FROM content_versions WHERE version_type = "ai" AND version_number > 1 AND created_at BETWEEN ? AND ?');
        $edits = $v('SELECT COUNT(*) FROM content_versions WHERE version_type = "edited" AND created_at BETWEEN ? AND ?');
        $up = $v('SELECT COUNT(*) FROM contents WHERE feedback = 1 AND created_at BETWEEN ? AND ?');
        $down = $v('SELECT COUNT(*) FROM contents WHERE feedback = -1 AND created_at BETWEEN ? AND ?');
        $dUp = $dDown = 0;
        try {
            $dUp = $v('SELECT COUNT(*) FROM design_ratings WHERE rating = 1 AND created_at BETWEEN ? AND ?');
            $dDown = $v('SELECT COUNT(*) FROM design_ratings WHERE rating = -1 AND created_at BETWEEN ? AND ?');
        } catch (\Throwable $e) {}
        return [
            'funnel' => [
                'signups' => $v('SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?'),
                'identity_complete' => $v('SELECT COUNT(*) FROM users u JOIN brand_kits k ON k.user_id = u.id WHERE u.created_at BETWEEN ? AND ? AND k.completeness >= ?', [$gate]),
                'ideas' => $v('SELECT (SELECT COUNT(*) FROM campaign_ideas WHERE created_at BETWEEN ? AND ?)', [])
                    + (float) (iq_val('SELECT COUNT(*) FROM plan_ideas WHERE created_at BETWEEN ? AND ?', [$a, $b]) ?? 0),
                'ideas_selected' => $v('SELECT COUNT(*) FROM campaign_ideas WHERE selected = 1 AND created_at BETWEEN ? AND ?')
                    + (float) (iq_val('SELECT COUNT(*) FROM plan_ideas WHERE status IN ("selected","produced") AND created_at BETWEEN ? AND ?', [$a, $b]) ?? 0),
                'posts' => $posts,
                'designs' => $v('SELECT COUNT(*) FROM content_designs WHERE created_at BETWEEN ? AND ?'),
                'scheduled' => $v('SELECT COUNT(*) FROM contents WHERE created_at BETWEEN ? AND ? AND (scheduled_at IS NOT NULL OR publish_status IN ("pending","scheduled","published"))')
                    + (float) (iq_val('SELECT COUNT(*) FROM campaign_ideas WHERE plan_at IS NOT NULL AND content_id IS NULL AND created_at BETWEEN ? AND ?', [$a, $b]) ?? 0),
                'published' => $v('SELECT COUNT(*) FROM contents WHERE published_at BETWEEN ? AND ?'),
            ],
            'quality' => [
                'regeneration_rate' => $posts > 0 ? round($regen / $posts, 3) : null,
                'manual_edit_rate' => $posts > 0 ? round($edits / $posts, 3) : null,
                'post_ratings' => ['up' => $up, 'down' => $down, 'score' => ($up + $down) > 0 ? round(($up - $down) / ($up + $down), 3) : null],
                'design_ratings' => ['up' => $dUp, 'down' => $dDown, 'score' => ($dUp + $dDown) > 0 ? round(($dUp - $dDown) / ($dUp + $dDown), 3) : null],
            ],
            'credits_used' => $v('SELECT COALESCE(SUM(ABS(amount)), 0) FROM credit_transactions WHERE action_type IN ("deduct","consume") AND created_at BETWEEN ? AND ?'),
            'ai' => array_map(fn($r) => ['provider' => $r['provider'] ?: 'unknown', 'calls' => (int) $r['n'], 'failed' => (int) $r['f'],
                    'error_rate' => (int) $r['n'] > 0 ? round((int) $r['f'] / (int) $r['n'], 3) : 0, 'avg_ms' => (int) $r['ms']],
                iq_all('SELECT provider, COUNT(*) n, SUM(status = "failed") f, ROUND(AVG(CASE WHEN status = "ok" THEN duration_ms END)) ms
                        FROM ai_request_logs WHERE created_at BETWEEN ? AND ? GROUP BY provider ORDER BY n DESC LIMIT 20', [$a, $b])),
        ];
    };
    $now = $m($from, $to);
    $prev = $m($pFrom, $pTo);
    $chg = [];
    foreach ($now['funnel'] as $k => $val) $chg['funnel'][$k] = iapi_pct($val, $prev['funnel'][$k]);
    $chg['regeneration_rate'] = iapi_pct($now['quality']['regeneration_rate'], $prev['quality']['regeneration_rate']);
    $chg['credits_used'] = iapi_pct($now['credits_used'], $prev['credits_used']);
    $flags = [];
    foreach ($chg['funnel'] as $k => $p) if ($p !== null && abs($p) > 20) $flags[] = "{$k}: " . ($p > 0 ? '+' : '') . "{$p}%";
    iapi_ok(['period' => ['from' => substr($from, 0, 10), 'to' => substr($to, 0, 10), 'days' => $days],
             'previous' => ['from' => substr($pFrom, 0, 10), 'to' => substr($pTo, 0, 10)],
             'current' => $now, 'previous_values' => $prev, 'change_pct' => $chg, 'anomalies_over_20pct' => $flags]);
}

/** منشورات بفلاتر — للتقييم */
function r_posts(): void
{
    [$limit, $cursor] = iapi_page(20);
    $w = ['1=1']; $p = [];
    if (($df = iapi_date('date_from', '')) !== '') { $w[] = 'c.created_at >= ?'; $p[] = $df . ' 00:00:00'; }
    if (($dt = iapi_date('date_to', '')) !== '') { $w[] = 'c.created_at <= ?'; $p[] = $dt . ' 23:59:59'; }
    $rating = iapi_str('rating', 10);
    if ($rating === 'up') $w[] = 'c.feedback = 1';
    elseif ($rating === 'down') $w[] = 'c.feedback = -1';
    elseif ($rating === 'none') $w[] = 'c.feedback IS NULL';
    if (($pv = iapi_int('prompt_version_id')) > 0) { $w[] = 'c.prompt_version_id = ?'; $p[] = $pv; }
    if (($prov = iapi_str('provider', 60)) !== '') { $w[] = 'c.provider = ?'; $p[] = $prov; }
    if (($bid = iapi_int('brand_id')) > 0) { $w[] = 'c.brand_profile_id = ?'; $p[] = $bid; }
    if (($fmt = iapi_str('format', 20)) !== '') { $w[] = 'c.format = ?'; $p[] = $fmt; }
    $minRegen = iapi_int('min_regenerations', 0, 0, 100);
    if (iapi_int('unevaluated_only') === 1 && iapi_ai_has('ai_evaluations')) $w[] = 'c.id NOT IN (SELECT content_id FROM ' . mcp_t('ai_evaluations') . ')';
    $sql = 'SELECT c.id, c.created_at, c.brand_profile_id brand_id, c.format, c.content_type, c.feedback rating, c.prompt_version_id, c.provider, c.model,
                   c.campaign_id, LEFT(c.generated_text, 220) excerpt, c.publish_status,
                   (SELECT COUNT(*) FROM content_versions v WHERE v.content_id = c.id AND v.version_type = "ai") - 1 AS regenerations,
                   (SELECT COUNT(*) FROM content_versions v WHERE v.content_id = c.id AND v.version_type = "edited") AS manual_edits,
                   (SELECT COUNT(*) FROM content_designs d WHERE d.content_id = c.id) AS designs
            FROM contents c WHERE ' . implode(' AND ', $w)
        . ($minRegen > 0 ? ' HAVING regenerations >= ' . $minRegen : '')
        . ' ORDER BY c.id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $cursor;
    $rows = iq_all($sql, $p);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $ev = [];
    if ($ids && iapi_ai_has('ai_evaluations')) {
        foreach (iq_all('SELECT content_id, MAX(overall) o FROM ' . mcp_t('ai_evaluations') . ' WHERE content_id IN (' . implode(',', $ids) . ') GROUP BY content_id') as $e) $ev[(int) $e['content_id']] = (float) $e['o'];
    }
    foreach ($rows as &$r) {
        $r['regenerations'] = max(0, (int) $r['regenerations']);
        $r['evaluated'] = isset($ev[(int) $r['id']]);
        $r['overall'] = $ev[(int) $r['id']] ?? null;
    }
    iapi_ok(['items' => $rows, 'next_cursor' => $more ? $cursor + $limit : null, 'count' => count($rows)]);
}

/** منشور كامل: النص · التصميمات · الفكرة · الهوية وقتها · البرومبت · النسخ والتعديلات · التقييمات */
function r_post(): void
{
    $id = iapi_int('post_id') ?: iapi_int('id');
    $c = iq_one('SELECT * FROM contents WHERE id = ?', [$id]);
    if (!$c) iapi_fail('المنشور #' . $id . ' مش موجود — هات الأرقام من مسار posts', 'not_found', 404);
    $designs = iq_all('SELECT id, image_path, ratio, slide_no, model, prompt_version_id, provider, LEFT(prompt, 1500) prompt, created_at FROM content_designs WHERE content_id = ? ORDER BY id', [$id]);
    $dr = [];
    try { foreach (iq_all('SELECT design_id, rating, reason FROM design_ratings WHERE content_id = ?', [$id]) as $r) $dr[(int) $r['design_id']] = $r; } catch (\Throwable $e) {}
    foreach ($designs as &$d) {
        $d['image'] = iapi_media_ref($d['image_path']);
        $d['rating'] = isset($dr[(int) $d['id']]) ? (int) $dr[(int) $d['id']]['rating'] : null;
        $d['rating_reason'] = $dr[(int) $d['id']]['reason'] ?? null;
        unset($d['image_path']);
    }
    unset($d);
    $idea = iq_one('SELECT id, title, angle, description, audience, hook, brief_json, eval_json FROM campaign_ideas WHERE content_id = ? LIMIT 1', [$id])
        ?: iq_one('SELECT id, title, angle, description FROM plan_ideas WHERE content_id = ? LIMIT 1', [$id]);
    if ($idea) { $idea['brief'] = iapi_dec($idea['brief_json'] ?? null); $idea['internal_eval'] = iapi_dec($idea['eval_json'] ?? null); unset($idea['brief_json'], $idea['eval_json']); }
    $versions = iq_all('SELECT version_number, version_type, LEFT(content_text, 1500) text, notes, created_at FROM content_versions WHERE content_id = ? ORDER BY version_number', [$id]);
    // التعديلات اليدوية: النسخة قبل التعديل ← بعده
    $edits = [];
    foreach ($versions as $k => $v) if ($v['version_type'] === 'edited' && $k > 0) $edits[] = ['before' => $versions[$k - 1]['text'], 'after' => $v['text'], 'at' => $v['created_at']];
    $prompt = $c['prompt_version_id'] ? iq_one('SELECT id, prompt_type, version, title, status, traffic_percent, prompt_text body FROM prompts WHERE id = ?', [$c['prompt_version_id']]) : null;
    $reasons = iq_all('SELECT action, field, after_value reason, created_at FROM brand_feedback WHERE content_id = ? AND field IN ("post","post_reason") ORDER BY id', [$id]);
    foreach ($reasons as &$rr) if ($rr['field'] === 'post') $rr['reason'] = null;
    unset($rr);
    $evals = iapi_ai_has('ai_evaluations') ? iq_all('SELECT id, overall, content_total, design_total, created_at FROM ' . mcp_t('ai_evaluations') . ' WHERE content_id = ? ORDER BY id DESC LIMIT 5', [$id]) : [];
    $last20 = iq_all('SELECT id, LEFT(generated_text, 160) excerpt FROM contents WHERE user_id = ? AND id <> ? ORDER BY id DESC LIMIT 20', [$c['user_id'], $id]);
    iapi_ok(['post' => [
        'id' => (int) $c['id'], 'brand_id' => (int) $c['brand_profile_id'], 'created_at' => $c['created_at'], 'format' => $c['format'], 'content_type' => $c['content_type'],
        'platform' => $c['platform'], 'text' => $c['generated_text'], 'hashtags' => $c['hashtags'], 'cta' => $c['cta'],
        'slides' => iapi_dec($c['slides_json'] ?? null), 'design_direction' => $c['design_direction'], 'image_prompt' => $c['image_prompt'],
        'video_script' => iapi_dec($c['video_brief_json'] ?? null), 'model' => $c['model'], 'provider' => $c['provider'] ?? null,
        'publish_status' => $c['publish_status'], 'scheduled_at' => $c['scheduled_at'], 'published_at' => $c['published_at'],
        'style_chosen' => iapi_dec($c['brand_overrides'] ?? null), 'brief' => iapi_dec($c['brief_json'] ?? null), 'ai_reasoning' => iapi_dec($c['ai_reasoning'] ?? null),
        'brand_identity_snapshot' => iapi_dec($c['brand_snapshot'] ?? null),
        'rating' => $c['feedback'] !== null ? (int) $c['feedback'] : null, 'rating_reasons' => $reasons,
    ], 'designs' => $designs, 'idea' => $idea ?: null, 'prompt_version' => $prompt, 'versions' => $versions, 'manual_edits' => $edits,
       'evaluations' => $evals, 'brand_recent_posts' => $last20]);
}

/** صورة تصميم / بلاغ — بالتوكن بس (مش لينك عام) · بترجع الصورة نفسها */
function r_media(): void
{
    $abs = iapi_file_path(iapi_str('path', 300));
    if (!$abs) iapi_fail('الملف مش موجود أو مش مسموح (uploads/*.png|jpg|webp بس)', 'not_found', 404);
    iapi_audit('ok');
    while (ob_get_level() > 0) ob_end_clean();
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream';
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) iapi_fail('نوع الملف مش مسموح', 'invalid', 415);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}

/** هوية البراند: النبرة · الخدمات والمواضيع · الألوان · الشخصيات · الأشكال · العملاء المثاليين · المستندات (ملخص) */
function r_brand(): void
{
    $bid = iapi_int('brand_id');
    $bp = iq_one('SELECT id, user_id, business_name, industry, description, audience, tone, dialect, colors, services, keywords_use, keywords_avoid, design_rules,
                         ai_summary, visual_identity, address, phones, whatsapp, working_hours, website FROM brand_profiles WHERE id = ?', [$bid]);
    if (!$bp) iapi_fail('البراند #' . $bid . ' مش موجود (brand_id = brand_profiles.id — موجود في posts)', 'not_found', 404);
    $uid = (int) $bp['user_id'];
    $kit = iq_one('SELECT data, completeness, version, updated_at FROM brand_kits WHERE user_id = ?', [$uid]);
    $kd = $kit ? (json_decode((string) $kit['data'], true) ?: []) : [];
    foreach (['_learnings', '_feedback', '_visual', '_insights'] as $k) $meta[$k] = $kd[$k] ?? null;
    $kd = array_diff_key($kd, array_flip(['_learnings', '_feedback', '_profile']));
    $q = function (string $sql, array $p = []) { try { return iq_all($sql, $p); } catch (\Throwable $e) { return []; } };
    iapi_ok([
        'brand' => $bp,
        'kit' => ['completeness' => $kit ? (int) $kit['completeness'] : null, 'version' => $kit['version'] ?? null, 'updated_at' => $kit['updated_at'] ?? null, 'data' => $kd],
        'services' => $q('SELECT id, name, description, priority, is_focus, is_active FROM brand_services WHERE user_id = ? ORDER BY is_focus DESC, sort_order, id', [$uid]),
        'topics' => $q('SELECT id, service_id, title, times_used, is_active FROM brand_topics WHERE user_id = ? ORDER BY id DESC LIMIT 60', [$uid]),
        'personas' => $q('SELECT id, name, emoji, age, gender, job, location, pains, desires, objections, triggers, service_ids, voice FROM brand_personas WHERE user_id = ? AND is_active = 1', [$uid]),
        'characters' => $q('SELECT c.id, c.name, c.type, c.role, c.description, c.use_in, (SELECT COUNT(*) FROM brand_character_images i WHERE i.character_id = c.id) images FROM brand_characters c WHERE c.user_id = ? AND c.is_active = 1', [$uid]),
        'formats' => $q('SELECT * FROM brand_formats WHERE user_id = ?', [$uid]),
        'documents' => $q('SELECT id, title, type, LEFT(summary, 1200) summary, use_in_prompts FROM brand_sources WHERE brand_profile_id = ? ORDER BY id DESC LIMIT 15', [$bid]),
        'learnings' => $meta['_learnings'] ?? [], 'client_feedback' => $meta['_feedback'] ?? [],
    ]);
}

/** البرومبتات: المفعّل + نسخ التجربة لكل نوع ومعاها أرقامها */
function r_prompt_templates(): void
{
    $rows = iq_all('SELECT id, prompt_type, version, title, is_active, status, traffic_percent, created_by_kind, CHAR_LENGTH(prompt_text) chars, updated_at, created_at
                    FROM prompts WHERE is_active = 1 OR status IN ("testing","approved") ORDER BY prompt_type, version DESC');
    $stats = iapi_version_stats(array_map(fn($r) => (int) $r['id'], $rows));
    $out = [];
    foreach ($rows as $r) {
        $r['role'] = (int) $r['is_active'] === 1 ? 'live' : ($r['status'] ?: 'inactive');
        $r['stats'] = $stats[(int) $r['id']] ?? null;
        $out[$r['prompt_type']][] = $r;
    }
    iapi_ok(['templates' => $out]);
}

function r_prompt_version(): void
{
    $id = iapi_int('id');
    $r = iq_one('SELECT id, prompt_type, version, title, prompt_text body, is_active, status, traffic_percent, created_by_kind, reason, parent_id, created_at, approved_at FROM prompts WHERE id = ?', [$id]);
    if (!$r) iapi_fail('الإصدار #' . $id . ' مش موجود — هات الأرقام من prompt_templates', 'not_found', 404);
    $r['stats'] = iapi_version_stats([$id])[$id] ?? null;
    iapi_ok(['version' => $r]);
}

/** أرقام كل إصدار: عدد المنشورات · التقييم · إعادة التوليد · متوسط تقييم Claude */
function iapi_version_stats(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = [];
    foreach (iq_all("SELECT prompt_version_id v, COUNT(*) n, SUM(feedback = 1) up, SUM(feedback = -1) down,
                            AVG((SELECT COUNT(*) FROM content_versions x WHERE x.content_id = c.id AND x.version_type = 'ai') - 1) regen
                     FROM contents c WHERE prompt_version_id IN ($in) GROUP BY prompt_version_id") as $r) {
        $out[(int) $r['v']] = ['posts' => (int) $r['n'], 'up' => (int) $r['up'], 'down' => (int) $r['down'], 'avg_regenerations' => $r['regen'] !== null ? round((float) $r['regen'], 2) : null];
    }
    try {
        foreach (iq_all("SELECT prompt_version_id v, COUNT(*) n FROM content_designs WHERE prompt_version_id IN ($in) GROUP BY prompt_version_id") as $r) {
            $out[(int) $r['v']]['designs'] = (int) $r['n'];
        }
    } catch (\Throwable $e) {}
    if (iapi_ai_has('ai_evaluations')) {
        foreach (iq_all('SELECT prompt_version_id v, COUNT(*) n, AVG(overall) o FROM ' . mcp_t('ai_evaluations') . " WHERE prompt_version_id IN ($in) GROUP BY prompt_version_id") as $r) {
            $out[(int) $r['v']]['evaluated'] = (int) $r['n'];
            $out[(int) $r['v']]['avg_overall'] = round((float) $r['o'], 2);
        }
    }
    return $out;
}

function r_style_presets(): void
{
    $kind = iapi_str('kind', 30);
    $rows = iq_all('SELECT id, kind, name, data, is_active, sector_template_id FROM style_presets' . ($kind !== '' ? ' WHERE kind = ?' : '') . ' ORDER BY kind, sort_order, id', $kind !== '' ? [$kind] : []);
    foreach ($rows as &$r) $r['data'] = iapi_dec($r['data']);
    iapi_ok(['presets' => $rows]);
}

/** بلاغات العملاء ومعاها السياق والتحليل (لو اتعمل) */
function r_issues(): void
{
    [$limit, $cursor] = iapi_page(20);
    $st = iapi_str('status', 20);
    $w = $st !== '' ? 'WHERE i.status = ?' : '';
    $rows = iq_all("SELECT i.id, i.user_id, i.content_id, i.design_id, i.page_url, i.category_user, i.description, i.screenshot_path, i.context, i.status, i.created_at
                    FROM user_issues i $w ORDER BY i.id DESC LIMIT " . ($limit + 1) . ' OFFSET ' . $cursor, $st !== '' ? [$st] : []);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $an = [];
    if ($rows && iapi_ai_has('issue_analysis')) {
        foreach (iq_all('SELECT * FROM ' . mcp_t('issue_analysis') . ' WHERE issue_id IN (' . implode(',', array_map(fn($r) => (int) $r['id'], $rows)) . ')') as $a) $an[(int) $a['issue_id']] = $a;
    }
    foreach ($rows as &$r) {
        $r['context'] = iapi_dec($r['context']);
        $r['screenshot'] = iapi_media_ref($r['screenshot_path']);
        unset($r['screenshot_path']);
        $r['analysis'] = $an[(int) $r['id']] ?? null;
        if ($r['analysis']) $r['analysis']['related_issue_ids'] = iapi_dec($r['analysis']['related_issue_ids']);
    }
    iapi_ok(['items' => $rows, 'next_cursor' => $more ? $cursor + $limit : null]);
}

/** أخطاء التوليد متجمّعة بالمزود والنوع والرسالة */
function r_ai_errors(): void
{
    $d = iapi_date('date', date('Y-m-d', strtotime('-1 day')));
    $prov = iapi_str('provider', 60);
    $rows = iq_all('SELECT provider, kind, LEFT(COALESCE(error, CONCAT("HTTP ", http_code)), 140) err, COUNT(*) n, MIN(created_at) first_at, MAX(created_at) last_at
                    FROM ai_request_logs WHERE status = "failed" AND created_at BETWEEN ? AND ?' . ($prov !== '' ? ' AND provider = ?' : '') . '
                    GROUP BY provider, kind, err ORDER BY n DESC LIMIT 50', array_merge([$d . ' 00:00:00', $d . ' 23:59:59'], $prov !== '' ? [$prov] : []));
    $tot = iq_one('SELECT COUNT(*) n, SUM(status = "failed") f FROM ai_request_logs WHERE created_at BETWEEN ? AND ?', [$d . ' 00:00:00', $d . ' 23:59:59']);
    iapi_ok(['date' => $d, 'total_calls' => (int) ($tot['n'] ?? 0), 'failed' => (int) ($tot['f'] ?? 0), 'groups' => $rows]);
}

/** الاقتراحات (ومعاها المرفوض وملاحظات الأدمن) — علشان المرفوض مايتعادش */
function r_change_requests(): void
{
    if (!iapi_ai_has('change_requests')) iapi_ok(['items' => [], 'note' => 'جداول قاعدة الـ AI لسه ماتعملتش']);
    [$limit, $cursor] = iapi_page(30);
    $st = iapi_str('status', 20);
    $rows = iq_all('SELECT id, type, target, LEFT(diff, 2000) diff, reason, evidence, expected_impact, priority, status, fingerprint, created_at, reviewed_at, review_note
                    FROM ' . mcp_t('change_requests') . ($st !== '' ? ' WHERE status = ?' : '') . ' ORDER BY id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $cursor, $st !== '' ? [$st] : []);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    foreach ($rows as &$r) $r['evidence'] = iapi_dec($r['evidence']);
    iapi_ok(['items' => $rows, 'next_cursor' => $more ? $cursor + $limit : null]);
}

/** المفعّل مقابل التجربة لنوع برومبت */
function r_ab_results(): void
{
    $type = iapi_str('template_key', 100);
    if ($type === '') iapi_fail('template_key مطلوب (نوع البرومبت زي content أو campaign_content)', 'invalid', 422);
    $rows = iq_all('SELECT id, version, title, is_active, status, traffic_percent, created_at FROM prompts WHERE prompt_type = ? AND (is_active = 1 OR status = "testing") ORDER BY version DESC', [$type]);
    if (!$rows) iapi_fail('مفيش إصدار مفعّل أو تجربة للنوع «' . $type . '»', 'not_found', 404);
    $stats = iapi_version_stats(array_map(fn($r) => (int) $r['id'], $rows));
    $min = (int) (get_setting('ab_min_sample', 30) ?: 30);
    foreach ($rows as &$r) {
        $r['role'] = (int) $r['is_active'] === 1 ? 'live' : 'testing';
        $r['stats'] = $stats[(int) $r['id']] ?? ['posts' => 0];
        $s = $r['stats'];
        $r['stats']['rating_score'] = (($s['up'] ?? 0) + ($s['down'] ?? 0)) > 0 ? round((($s['up'] ?? 0) - ($s['down'] ?? 0)) / (($s['up'] ?? 0) + ($s['down'] ?? 0)), 3) : null;
        $r['sample_ok'] = ($s['posts'] ?? 0) >= $min;
    }
    iapi_ok(['template_key' => $type, 'min_sample' => $min, 'versions' => $rows]);
}

function r_reports(): void
{
    if (!iapi_ai_has('ai_reports')) iapi_ok(['items' => []]);
    [$limit, $cursor] = iapi_page(10);
    $type = iapi_str('type', 10);
    $rows = iq_all('SELECT id, type, report_date, summary, created_at FROM ' . mcp_t('ai_reports') . ($type !== '' ? ' WHERE type = ?' : '') . ' ORDER BY report_date DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $cursor, $type !== '' ? [$type] : []);
    iapi_ok(['items' => $rows]);
}

/* ═══════════ D. الأمان (قراءة) ═══════════ */

function r_security_scan(): void
{
    if (!iapi_ai_has('security_findings')) iapi_ok(['items' => []]);
    $src = iapi_str('source', 20) ?: 'zap';
    $rows = iq_all('SELECT id, source, title, severity, location, description, recommendation, status, first_seen, last_seen FROM ' . mcp_t('security_findings')
        . ' WHERE source = ? ORDER BY FIELD(severity,"critical","high","medium","low","info"), last_seen DESC LIMIT 50', [$src]);
    iapi_ok(['source' => $src, 'items' => $rows]);
}

function r_dependency_audit(): void
{
    $_GET['source'] = 'dependency';
    r_security_scan();
}

/** دخول فاشل بالـ IP · ضغط غير طبيعي على المسارات · استهلاك كريدت غير طبيعي · أخطاء PHP جديدة */
function r_security_logs(): void
{
    $d = iapi_date('date', date('Y-m-d', strtotime('-1 day')));
    [$a, $b] = [$d . ' 00:00:00', $d . ' 23:59:59'];
    $failed = iq_all('SELECT action, rate_key ip, COUNT(*) n FROM rate_limits WHERE action IN ("login","admin_login","iapi_auth") AND created_at BETWEEN ? AND ? GROUP BY action, rate_key ORDER BY n DESC LIMIT 30', [$a, $b]);
    $bursts = iq_all('SELECT action, COUNT(*) n, COUNT(DISTINCT rate_key) keys_n FROM rate_limits WHERE created_at BETWEEN ? AND ? GROUP BY action ORDER BY n DESC LIMIT 20', [$a, $b]);
    $avg = (float) (iq_val('SELECT AVG(t) FROM (SELECT SUM(ABS(amount)) t FROM credit_transactions WHERE action_type IN ("deduct","consume") AND created_at BETWEEN ? AND ? GROUP BY user_id) x', [$a, $b]) ?? 0);
    $credit = iq_all('SELECT user_id, SUM(ABS(amount)) used, COUNT(*) ops FROM credit_transactions WHERE action_type IN ("deduct","consume") AND created_at BETWEEN ? AND ? GROUP BY user_id HAVING used > ? ORDER BY used DESC LIMIT 15',
        [$a, $b, max(20, $avg * 3)]);
    // أخطاء PHP لليوم ده (آخر 200 سطر من الـ logs)
    $errors = [];
    foreach (array_filter([ini_get('error_log') ?: null, ...glob(dirname(__DIR__) . '/storage/logs/*.log') ?: []]) as $f) {
        if (!is_file($f) || !is_readable($f)) continue;
        $size = filesize($f);
        $fh = fopen($f, 'r');
        if (!$fh) continue;
        if ($size > 300000) fseek($fh, -300000, SEEK_END);
        $lines = array_slice(explode("\n", (string) stream_get_contents($fh)), -2000);
        fclose($fh);
        $needle = date('d-M-Y', strtotime($d));
        foreach ($lines as $l) {
            if ($l === '' || (!str_contains($l, $needle) && !str_contains($l, $d))) continue;
            if (!preg_match('/(Fatal|Warning|Error|Exception|Notice|\[iapi\]|\[api\])/i', $l)) continue;
            $key = preg_replace('/^\[[^\]]+\]\s*/', '', $l);
            $key = mb_substr(preg_replace('/\d+/', '#', $key), 0, 160);
            $errors[$key] = ($errors[$key] ?? 0) + 1;
        }
    }
    arsort($errors);
    iapi_ok(['date' => $d, 'failed_logins' => $failed, 'rate_limit_activity' => $bursts,
             'abnormal_credit_usage' => ['avg_per_user' => round($avg, 1), 'users' => $credit],
             'php_errors' => array_map(fn($k, $n) => ['message' => $k, 'count' => $n], array_slice(array_keys($errors), 0, 30), array_slice(array_values($errors), 0, 30))]);
}

function r_e2e_results(): void
{
    if (!iapi_ai_has('e2e_runs')) iapi_ok(['items' => []]);
    $d = iapi_date('date', '');
    $rows = iq_all('SELECT flow_key, status, duration_ms, error, screenshot_path, run_at FROM ' . mcp_t('e2e_runs') . ($d !== '' ? ' WHERE run_at BETWEEN ? AND ?' : '') . ' ORDER BY run_at DESC LIMIT 50',
        $d !== '' ? [$d . ' 00:00:00', $d . ' 23:59:59'] : []);
    iapi_ok(['items' => $rows]);
}

function r_integrity_check(): void
{
    iapi_fail('فحص الترابط بيتعمل في المرحلة 8 — لحد دلوقتي اقرا آخر نتايج متسجلة في integrity_issues', 'not_implemented', 501);
}

/* ═══════════ B. تقييم وتسجيل (ai_write) ═══════════ */

const IAPI_CONTENT_KEYS = ['hook', 'clarity', 'brand_voice_match', 'relevance_to_services', 'cta', 'dialect_correctness', 'originality', 'platform_fit'];
const IAPI_DESIGN_KEYS = ['brand_colors_match', 'text_legibility', 'arabic_text_correctness', 'composition', 'idea_match', 'watermark_placement'];

function iapi_scores(array $in, array $keys, string $label): ?array
{
    if (!$in) return null;
    $out = [];
    foreach ($keys as $k) {
        if (!isset($in[$k]) || !is_numeric($in[$k])) iapi_fail("{$label}.{$k} ناقص — كل المعايير لازم تتقيّم من 1 لـ 10: " . implode(', ', $keys), 'invalid', 422);
        $v = (float) $in[$k];
        if ($v < 1 || $v > 10) iapi_fail("{$label}.{$k} لازم يكون من 1 لـ 10", 'invalid', 422);
        $out[$k] = round($v, 1);
    }
    return $out;
}

function iapi_weights(): array
{
    $w = json_decode((string) get_setting('mcp_rubric_weights', ''), true);
    return is_array($w) && isset($w['content'], $w['design']) ? ['content' => (float) $w['content'], 'design' => (float) $w['design']] : ['content' => 0.6, 'design' => 0.4];
}

function w_save_evaluation(): void
{
    $id = iapi_int('post_id');
    $c = iq_one('SELECT id, prompt_version_id FROM contents WHERE id = ?', [$id]);
    if (!$c) iapi_fail('المنشور #' . $id . ' مش موجود', 'not_found', 404);
    $cs = iapi_scores(iapi_arr('content_scores'), IAPI_CONTENT_KEYS, 'content_scores');
    if (!$cs) iapi_fail('content_scores مطلوب', 'invalid', 422);
    $ds = iapi_scores(iapi_arr('design_scores'), IAPI_DESIGN_KEYS, 'design_scores');
    $designId = iapi_int('design_id') ?: null;
    if ($designId && !iq_one('SELECT id FROM content_designs WHERE id = ? AND content_id = ?', [$designId, $id])) iapi_fail('التصميم ده مش تبع المنشور', 'invalid', 422);
    $ct = round(array_sum($cs) / count($cs), 2);
    $dt = $ds ? round(array_sum($ds) / count($ds), 2) : null;
    $w = iapi_weights();
    $overall = $dt !== null ? round($w['content'] * $ct + $w['design'] * $dt, 2) : $ct;
    $newId = iq_exec('INSERT INTO ' . mcp_t('ai_evaluations') . ' (content_id, design_id, prompt_version_id, evaluator, content_scores, design_scores, content_total, design_total, overall, strengths, weaknesses, fix_suggestion)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
        $id, $designId, $c['prompt_version_id'], iapi_str('evaluator', 30) ?: 'claude', iapi_json($cs), iapi_json($ds), $ct, $dt, $overall,
        iapi_str('strengths', 3000), iapi_str('weaknesses', 3000), iapi_str('fix_suggestion', 3000),
    ], 'ai_write');
    iapi_ok(['id' => $newId, 'content_total' => $ct, 'design_total' => $dt, 'overall' => $overall, 'weights' => $w]);
}

function w_analyze_issue(): void
{
    $id = iapi_int('issue_id');
    if (!iq_one('SELECT id FROM user_issues WHERE id = ?', [$id])) iapi_fail('البلاغ #' . $id . ' مش موجود', 'not_found', 404);
    $cat = iapi_str('ai_category', 30);
    if (!in_array($cat, ['bug', 'content_quality', 'design_quality', 'ux', 'feature_request', 'security'], true)) iapi_fail('ai_category: bug · content_quality · design_quality · ux · feature_request · security', 'invalid', 422);
    $sev = iapi_str('ai_severity', 10);
    if (!in_array($sev, ['low', 'medium', 'high', 'critical'], true)) iapi_fail('ai_severity: low · medium · high · critical', 'invalid', 422);
    $rel = array_values(array_unique(array_filter(array_map('intval', iapi_arr('related_issue_ids')), fn($x) => $x > 0 && $x !== $id)));
    iq_exec('INSERT INTO ' . mcp_t('issue_analysis') . ' (issue_id, ai_category, ai_severity, root_cause, fix, related_issue_ids, analyzed_at) VALUES (?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE ai_category = VALUES(ai_category), ai_severity = VALUES(ai_severity), root_cause = VALUES(root_cause), fix = VALUES(fix),
             related_issue_ids = VALUES(related_issue_ids), analyzed_at = NOW()',
        [$id, $cat, $sev, iapi_str('root_cause', 4000), iapi_str('fix', 4000), iapi_json($rel)], 'ai_write');
    iapi_ok(['issue_id' => $id, 'saved' => true, 'note' => 'حالة البلاغ نفسها بيغيّرها الأدمن من لوحة البلاغات']);
}

function w_save_report(): void
{
    $type = iapi_str('type', 10) ?: 'daily';
    if (!in_array($type, ['daily', 'weekly', 'security'], true)) iapi_fail('type: daily · weekly · security', 'invalid', 422);
    $date = iapi_date('report_date', date('Y-m-d'));
    $summary = iapi_str('summary', 8000);
    if (mb_strlen($summary) < 20) iapi_fail('summary مطلوب (ملخص التقرير)', 'invalid', 422);
    $payload = iapi_in()['payload'] ?? null;
    iq_exec('INSERT INTO ' . mcp_t('ai_reports') . ' (type, report_date, summary, payload) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE summary = VALUES(summary), payload = VALUES(payload)', [$type, $date, $summary, iapi_json($payload)], 'ai_write');
    iapi_ok(['type' => $type, 'report_date' => $date, 'saved' => true]);
}

function w_save_security_finding(): void
{
    $src = iapi_str('source', 20);
    if (!in_array($src, ['code_review', 'zap', 'config', 'logs', 'dependency'], true)) iapi_fail('source: code_review · zap · config · logs · dependency', 'invalid', 422);
    $sev = iapi_str('severity', 10);
    if (!in_array($sev, ['info', 'low', 'medium', 'high', 'critical'], true)) iapi_fail('severity: info · low · medium · high · critical', 'invalid', 422);
    $title = iapi_str('title', 255);
    $loc = iapi_str('location', 500);
    if ($title === '' || $loc === '') iapi_fail('title و location مطلوبين', 'invalid', 422);
    // نفس المصدر + المكان = نفس المشكلة ← بنحدّث آخر ظهور (والمقفولة كـ fixed بترجع open لو ظهرت تاني)
    iq_exec('INSERT INTO ' . mcp_t('security_findings') . ' (source, title, severity, location, description, recommendation) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), severity = VALUES(severity), description = VALUES(description), recommendation = VALUES(recommendation),
             last_seen = NOW(), status = IF(status = "fixed", "open", status), fixed_at = IF(status = "fixed", NULL, fixed_at)',
        [$src, $title, $sev, $loc, iapi_str('description', 4000), iapi_str('recommendation', 4000)], 'ai_write');
    iapi_ok(['saved' => true]);
}

function w_save_integrity_issue(): void
{
    $key = iapi_str('check_key', 80);
    if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) iapi_fail('check_key مطلوب (حروف إنجليزي صغيرة وأرقام و _)', 'invalid', 422);
    $ids = array_slice(array_values(array_filter(array_map('intval', iapi_arr('entity_ids')))), 0, 200);
    iq_exec('INSERT INTO ' . mcp_t('integrity_issues') . ' (check_key, entity_type, entity_ids, count, description, suggested_fix, status, detected_at) VALUES (?,?,?,?,?,?,"open",NOW())
             ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type), entity_ids = VALUES(entity_ids), count = VALUES(count), description = VALUES(description),
             suggested_fix = VALUES(suggested_fix), detected_at = NOW(), status = IF(VALUES(count) = 0, "fixed", IF(status = "ignored", "ignored", "open"))',
        [$key, iapi_str('entity_type', 40) ?: 'unknown', iapi_json($ids), iapi_int('count', count($ids), 0), iapi_str('description', 2000), iapi_str('suggested_fix', 2000)], 'ai_write');
    iapi_ok(['saved' => true]);
}

/* ═══════════ C. مسودات (draft) — كلها مستنية موافقة الأدمن ═══════════ */

function d_propose_change(): void
{
    $type = iapi_str('type', 40);
    if (!in_array($type, ['prompt', 'style', 'ux', 'code', 'config', 'content_rule'], true)) iapi_fail('type: prompt · style · ux · code · config · content_rule', 'invalid', 422);
    $target = iapi_str('target', 255);
    $reason = iapi_str('reason', 4000);
    if ($target === '' || mb_strlen($reason) < 10) iapi_fail('target و reason مطلوبين (السبب بالأدلة)', 'invalid', 422);
    $prio = iapi_str('priority', 10) ?: 'medium';
    if (!in_array($prio, ['low', 'medium', 'high', 'critical'], true)) $prio = 'medium';
    $diff = (string) (iapi_in()['diff'] ?? '');
    $diff = mb_substr($diff, 0, 60000);
    $evidence = iapi_in()['evidence'] ?? null;
    $fp = sha1(mb_strtolower($type . '|' . $target . '|' . preg_replace('/\s+/u', ' ', trim($diff))));
    $eh = sha1((string) iapi_json($evidence));
    // نفس الاقتراح اترفض قبل كده ومن غير دليل جديد ← مابيتسجلش
    $rej = iq_one('SELECT id, evidence_hash, review_note FROM ' . mcp_t('change_requests') . ' WHERE fingerprint = ? AND status = "rejected" ORDER BY id DESC LIMIT 1', [$fp], 'draft');
    if ($rej && $rej['evidence_hash'] === $eh) {
        iapi_fail('الاقتراح ده اترفض قبل كده (#' . $rej['id'] . ($rej['review_note'] ? ' — ملاحظة الأدمن: ' . $rej['review_note'] : '') . ') — ماتقترحوش تاني من غير دليل جديد', 'already_rejected', 409);
    }
    $pend = iq_one('SELECT id FROM ' . mcp_t('change_requests') . ' WHERE fingerprint = ? AND status IN ("pending","postponed") LIMIT 1', [$fp], 'draft');
    if ($pend) iapi_ok(['id' => (int) $pend['id'], 'duplicate' => true, 'note' => 'نفس الاقتراح مستني مراجعة خلاص']);
    $id = iq_exec('INSERT INTO ' . mcp_t('change_requests') . ' (type, target, diff, reason, evidence, expected_impact, priority, fingerprint, evidence_hash) VALUES (?,?,?,?,?,?,?,?,?)',
        [$type, $target, $diff ?: null, $reason, iapi_json($evidence), iapi_str('expected_impact', 2000), $prio, $fp, $eh], 'draft');
    iapi_ok(['id' => $id, 'status' => 'pending', 'note' => 'مستني موافقة الأدمن (لوحة «طلبات التعديل»)']);
}

function d_draft_prompt(): void
{
    $type = iapi_str('template_key', 100);
    $body = (string) (iapi_in()['body'] ?? '');
    $reason = iapi_str('reason', 4000);
    $live = $type !== '' ? iq_one('SELECT id FROM prompts WHERE prompt_type = ? AND is_active = 1 ORDER BY version DESC LIMIT 1', [$type]) : null;
    if ($type === '' || !iq_one('SELECT 1 FROM prompts WHERE prompt_type = ? LIMIT 1', [$type])) iapi_fail('template_key مش موجود — هات الأنواع من prompt_templates', 'invalid', 422);
    if (mb_strlen($body) < 50) iapi_fail('body قصير جدًا (البرومبت كامل)', 'invalid', 422);
    if (mb_strlen($reason) < 10) iapi_fail('reason مطلوب (ليه التعديل ده هيحسّن الجودة)', 'invalid', 422);
    $id = iq_exec('INSERT INTO ' . mcp_t('prompt_drafts') . ' (prompt_type, body, reason, parent_prompt_id) VALUES (?,?,?,?)',
        [$type, mb_substr($body, 0, 60000), $reason, $live ? (int) $live['id'] : null], 'draft');
    iapi_ok(['id' => $id, 'status' => 'draft', 'parent_prompt_id' => $live ? (int) $live['id'] : null, 'note' => 'الأدمن يوافق ← يتعمل إصدار «تجربة» بنسبة أو «مفعّل»']);
}

function d_draft_style(): void
{
    $pid = iapi_int('preset_id') ?: null;
    if ($pid && !iq_one('SELECT id FROM style_presets WHERE id = ?', [$pid])) iapi_fail('الاستايل #' . $pid . ' مش موجود', 'not_found', 404);
    $payload = iapi_in()['payload'] ?? null;
    if (!is_array($payload) || !$payload) iapi_fail('payload مطلوب (JSON بنفس شكل data في style_presets)', 'invalid', 422);
    $reason = iapi_str('reason', 4000);
    if (mb_strlen($reason) < 10) iapi_fail('reason مطلوب', 'invalid', 422);
    $id = iq_exec('INSERT INTO ' . mcp_t('style_preset_drafts') . ' (preset_id, payload, reason) VALUES (?,?,?)', [$pid, iapi_json($payload), $reason], 'draft');
    iapi_ok(['id' => $id, 'status' => 'draft']);
}

function d_draft_reply(): void
{
    $iid = iapi_int('issue_id');
    if (!iq_one('SELECT id FROM user_issues WHERE id = ?', [$iid])) iapi_fail('البلاغ #' . $iid . ' مش موجود', 'not_found', 404);
    $body = iapi_str('body', 3000);
    if (mb_strlen($body) < 10) iapi_fail('body مطلوب (الرد بالمصري)', 'invalid', 422);
    $ch = iapi_str('channel', 10) ?: 'inbox';
    if (!in_array($ch, ['inbox', 'whatsapp'], true)) $ch = 'inbox';
    $id = iq_exec('INSERT INTO ' . mcp_t('reply_drafts') . ' (issue_id, body, channel) VALUES (?,?,?)', [$iid, $body, $ch], 'draft');
    iapi_ok(['id' => $id, 'status' => 'draft', 'note' => 'الرد مابيتبعتش غير لما الأدمن يوافق']);
}

function d_test_regenerate(): void
{
    iapi_fail('التوليد التجريبي (sandbox) بيتعمل في المرحلة 6', 'not_implemented', 501);
}
