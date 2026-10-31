<?php
/**
 * Spread AI — مركز الجودة (المرحلة 5): مساعدات مشتركة لصفحات الأدمن
 *   التقرير اليومي · تقييمات المحتوى · البلاغات · سجل الـ MCP · إعدادات الجودة
 *
 * الأدمن بيقرا ويكتب في قاعدة الـ AI بمستخدم المنصة (ليه ALL عليها) — الـ MCP نفسه مالوش غير SELECT/INSERT/UPDATE.
 */

require_once __DIR__ . '/mcp-config.php';

/* ═══════════ الجداول ═══════════ */

/** جدول في قاعدة الـ AI موجود؟ (قبل «إنشاء الجداول» من إعداد الـ MCP = لأ) */
function aic_has(string $table): bool
{
    static $c = [];
    if (isset($c[$table])) return $c[$table];
    try { db_one('SELECT 1 FROM ' . mcp_t($table) . ' LIMIT 1'); return $c[$table] = true; } catch (\Throwable $e) { return $c[$table] = false; }
}

/** جدول في قاعدة المنصة موجود؟ */
function aic_platform_has(string $table): bool
{
    static $c = [];
    if (isset($c[$table])) return $c[$table];
    try { db_one('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1'); return $c[$table] = true; } catch (\Throwable $e) { return $c[$table] = false; }
}

function aic_setup_notice(): string
{
    return '<div class="alert warning">جداول قاعدة الـ AI لسه ماتعملتش — افتح <a href="' . e(url('admin/mcp-setup.php')) . '">إعداد الـ MCP</a> ودوس «إنشاء جداول قاعدة الـ AI».</div>';
}

/* ═══════════ التنقّل بين صفحات المركز ═══════════ */

function aic_pages(): array
{
    return [
        'ai-report'      => ['admin/ai-report.php', 'التقرير اليومي'],
        'ai-evaluations' => ['admin/ai-evaluations.php', 'تقييمات المحتوى'],
        'ai-issues'      => ['admin/ai-issues.php', 'البلاغات'],
        'ai-mcp-log'     => ['admin/ai-mcp-log.php', 'سجل الـ MCP'],
        'ai-quality-settings' => ['admin/ai-quality-settings.php', 'إعدادات الجودة'],
    ];
}

function aic_tabs(string $active): string
{
    $out = '<div class="a2-seg" style="flex-wrap:wrap">';
    foreach (aic_pages() as $k => [$u, $l]) {
        $badge = '';
        if ($k === 'ai-issues' && ($n = aic_new_issues()) > 0) $badge = ' <span class="chip chip-coral" style="padding:0 7px">' . $n . '</span>';
        $out .= '<a class="' . ($k === $active ? 'on' : '') . '" href="' . e(url($u)) . '">' . e($l) . $badge . '</a>';
    }
    return $out . '</div>';
}

function aic_new_issues(): int
{
    static $n = null;
    if ($n !== null) return $n;
    try { return $n = (int) (db_one('SELECT COUNT(*) n FROM user_issues WHERE status = "new"')['n'] ?? 0); } catch (\Throwable $e) { return $n = 0; }
}

/* ═══════════ الأوزان والمعايير ═══════════ */

const AIC_CONTENT = [
    'hook' => 'الخطّاف', 'clarity' => 'الوضوح', 'brand_voice_match' => 'نبرة البراند', 'relevance_to_services' => 'الصلة بالخدمات',
    'cta' => 'الدعوة لإجراء', 'dialect_correctness' => 'صحة اللهجة', 'originality' => 'الأصالة', 'platform_fit' => 'مناسبة المنصة',
];
const AIC_DESIGN = [
    'brand_colors_match' => 'ألوان البراند', 'text_legibility' => 'وضوح الكلام', 'arabic_text_correctness' => 'صحة العربي',
    'composition' => 'التكوين', 'idea_match' => 'مطابقة الفكرة', 'watermark_placement' => 'اللوجو والعلامة',
];

function aic_weights(): array
{
    $w = json_decode((string) get_setting('mcp_rubric_weights', ''), true);
    return is_array($w) && isset($w['content'], $w['design']) ? ['content' => (float) $w['content'], 'design' => (float) $w['design']] : ['content' => 0.6, 'design' => 0.4];
}

function aic_low(): float
{
    $v = (float) get_setting('eval_low_threshold', '6');
    return $v >= 1 && $v <= 10 ? $v : 6.0;
}

/** شارة التقييم بلونها */
function aic_score(?float $v, bool $big = false): string
{
    if ($v === null) return '<span class="a2-muted">—</span>';
    $cls = $v >= 8 ? 'chip-mint' : ($v >= aic_low() ? 'chip-amber' : 'chip-coral');
    return '<span class="chip ' . $cls . '"' . ($big ? ' style="font-size:15px;padding:4px 12px"' : '') . '>' . number_format($v, 1) . '</span>';
}

/** شريط أفقي لمعيار (1–10) */
function aic_bar(string $label, ?float $v): string
{
    $pct = $v === null ? 0 : max(0, min(100, $v * 10));
    $col = $v === null ? '#E6EDF5' : ($v >= 8 ? '#2a9d76' : ($v >= aic_low() ? '#e0a21a' : '#d9534f'));
    return '<div style="display:grid;grid-template-columns:120px 1fr 38px;gap:8px;align-items:center;font-size:12.5px;margin:5px 0">'
        . '<span>' . e($label) . '</span><span style="height:8px;background:#EEF2F7;border-radius:99px;overflow:hidden"><i style="display:block;height:100%;width:' . $pct . '%;background:' . $col . '"></i></span>'
        . '<b style="text-align:end;direction:ltr">' . ($v === null ? '—' : number_format($v, 1)) . '</b></div>';
}

/* ═══════════ البلاغات ═══════════ */

const AIC_ISSUE_STATUS = [
    'new' => ['chip-coral', 'جديد'], 'analyzing' => ['chip-amber', 'بيتحلّل'], 'analyzed' => ['chip-primary', 'اتحلّل'],
    'in_progress' => ['chip-amber', 'شغالين عليه'], 'resolved' => ['chip-mint', 'اتحل'], 'wont_fix' => ['chip-line', 'مش هيتعمل'],
];
const AIC_USER_CAT = ['tech' => 'مشكلة تقنية', 'content' => 'المحتوى', 'design' => 'التصميم', 'ux' => 'صعوبة في الاستخدام', 'suggestion' => 'اقتراح', 'support_chat' => '🛟 من مساعد الدعم'];
const AIC_AI_CAT = ['bug' => 'Bug', 'content_quality' => 'جودة المحتوى', 'design_quality' => 'جودة التصميم', 'ux' => 'تجربة الاستخدام', 'feature_request' => 'طلب ميزة', 'security' => 'أمان'];
const AIC_SEVERITY = ['low' => ['chip-line', 'بسيطة'], 'medium' => ['chip-amber', 'متوسطة'], 'high' => ['chip-coral', 'عالية'], 'critical' => ['chip-coral', 'حرجة']];

function aic_chip(array $map, ?string $k): string
{
    if ($k === null || $k === '') return '<span class="a2-muted">—</span>';
    [$cls, $lbl] = $map[$k] ?? ['chip-line', $k];
    return '<span class="chip ' . $cls . '">' . e($lbl) . '</span>';
}

/* ═══════════ الأدوات (تفعيل/إيقاف من الإعدادات) ═══════════ */

/** المسارات اللي الأدمن يقدر يقفلها — [مسار => [المجموعة، الوصف، أداة الـ MCP]] (health مش هنا: لازم يفضل شغال) */
function aic_route_catalog(): array
{
    return [
        'metrics' => ['read', 'الأرقام اليومية والقمع', 'spread_get_metrics'],
        'posts' => ['read', 'قايمة المنشورات', 'spread_list_posts'],
        'post' => ['read', 'تفاصيل المنشور', 'spread_get_post'],
        'media' => ['read', 'صور التصميمات والبلاغات', 'spread_get_image'],
        'brand' => ['read', 'هوية البراند', 'spread_get_brand'],
        'prompt_templates' => ['read', 'البرومبتات', 'spread_list_prompt_templates'],
        'prompt_version' => ['read', 'نص إصدار البرومبت', 'spread_get_prompt_version'],
        'ab_results' => ['read', 'نتايج A/B', 'spread_get_ab_results'],
        'style_presets' => ['read', 'الاستايلات', 'spread_list_style_presets'],
        'issues' => ['read', 'البلاغات', 'spread_list_issues'],
        'ai_errors' => ['read', 'أخطاء الـ AI', 'spread_get_ai_errors'],
        'change_requests' => ['read', 'الاقتراحات', 'spread_list_change_requests'],
        'reports' => ['read', 'التقارير المحفوظة', 'spread_list_reports'],
        'security_logs' => ['read', 'سجلات الأمان', 'spread_get_security_logs'],
        'security_scan' => ['read', 'ملاحظات الأمان', 'spread_list_security_findings'],
        'dependency_audit' => ['read', 'فحص المكتبات', 'spread_list_security_findings'],
        'e2e_results' => ['read', 'نتايج الاختبارات', 'spread_get_e2e_results'],
        'save_evaluation' => ['ai_write', 'حفظ التقييمات', 'spread_save_evaluation'],
        'analyze_issue' => ['ai_write', 'تحليل البلاغات', 'spread_analyze_issue'],
        'save_report' => ['ai_write', 'حفظ التقارير', 'spread_save_report'],
        'save_security_finding' => ['ai_write', 'تسجيل ملاحظات الأمان', 'spread_save_security_finding'],
        'save_integrity_issue' => ['ai_write', 'تسجيل مشاكل الترابط', 'spread_save_integrity_issue'],
        'propose_change' => ['draft', 'اقتراح تعديل', 'spread_propose_change'],
        'draft_prompt' => ['draft', 'مسودة برومبت', 'spread_draft_prompt'],
        'draft_style' => ['draft', 'مسودة استايل', 'spread_draft_style'],
        'draft_reply' => ['draft', 'مسودة رد على بلاغ', 'spread_draft_reply'],
    ];
}

/** المسارات المقفولة (الـ Internal API بيرفضها، وخادم الـ MCP بيخفي أدواتها) */
function aic_disabled_routes(): array
{
    $v = json_decode((string) get_setting('mcp_disabled_routes', '[]'), true);
    $cat = aic_route_catalog();
    return is_array($v) ? array_values(array_filter($v, fn($r) => is_string($r) && isset($cat[$r]))) : [];
}

/* ═══════════ Markdown آمن (تقارير Claude) ═══════════ */

/** بيهرّب كل حاجة الأول، وبعدين يحوّل: عناوين · قوايم · جداول · **عريض** · `كود` — مفيش HTML من النص أبدًا */
function aic_md(string $md): string
{
    $inline = function (string $s): string {
        $s = e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s);
        $s = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $s);
        return $s;
    };
    $lines = preg_split('/\r?\n/', $md);
    $out = '';
    $list = null;
    $table = [];
    $flushList = function () use (&$out, &$list) { if ($list) { $out .= '</' . $list . '>'; $list = null; } };
    $flushTable = function () use (&$out, &$table, $inline) {
        if (!$table) return;
        $rows = array_values(array_filter($table, fn($r) => !preg_match('/^\|?\s*:?-{2,}/', trim($r))));
        $out .= '<div class="a2-tw" style="margin:8px 0"><table class="a2-tbl">';
        foreach ($rows as $i => $r) {
            $cells = array_map('trim', explode('|', trim(trim($r), '|')));
            $tag = $i === 0 ? 'th' : 'td';
            $out .= ($i === 0 ? '<thead>' : '') . '<tr>' . implode('', array_map(fn($c) => "<$tag>" . $inline($c) . "</$tag>", $cells)) . '</tr>' . ($i === 0 ? '</thead><tbody>' : '');
        }
        $out .= '</tbody></table></div>';
        $table = [];
    };
    foreach ($lines as $l) {
        $t = trim($l);
        if ($t !== '' && $t[0] === '|') { $flushList(); $table[] = $t; continue; }
        $flushTable();
        if ($t === '') { $flushList(); continue; }
        if (preg_match('/^(#{1,4})\s+(.*)$/u', $t, $m)) {
            $flushList();
            $lvl = min(4, strlen($m[1]) + 2);
            $out .= "<h$lvl style=\"margin:14px 0 6px\">" . $inline($m[2]) . "</h$lvl>";
            continue;
        }
        if (preg_match('/^[-*•]\s+(.*)$/u', $t, $m) || preg_match('/^\d+[.)]\s+(.*)$/u', $t, $m)) {
            $want = preg_match('/^\d/', $t) ? 'ol' : 'ul';
            if ($list !== $want) { $flushList(); $out .= '<' . $want . ' style="margin:4px 0;padding-inline-start:22px;line-height:1.9">'; $list = $want; }
            $out .= '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        $flushList();
        $out .= '<p style="margin:6px 0;line-height:1.9">' . $inline($t) . '</p>';
    }
    $flushList();
    $flushTable();
    return $out;
}
