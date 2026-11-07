<?php
/**
 * Spread AI — المسودات والموافقات (MCP المرحلة 6)
 *
 * Claude بيقترح (change_requests) · يكتب مسودة برومبت (prompt_drafts) أو استايل (style_preset_drafts) ← مفيش حاجة بتتطبّق
 * لحد ما الأدمن يوافق من «طلبات التعديل»:
 *   برومبت ← إصدار «تجربة A/B» لنسبة من العملاء (العميل بيفضل في نفس المجموعة) أو «مفعّل» على طول
 *   «تجارب A/B» ← تغيير النسبة · ترقية التجربة · إيقافها · رجوع للإصدار اللي قبله بضغطة
 * التوليد التجريبي (sandbox): نفس منشور حقيقي بالمسودة ← قبل/بعد — مابيلمسش المنشور ولا كريدت العميل، ومتسجّل sandbox
 * في تكاليف الـ AI (برّه تقرير الربحية)، بحد يومي (إعدادات الجودة ← sandbox_daily_cap).
 */

require_once __DIR__ . '/mcp-config.php';

function pr_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM ' . mcp_t('prompt_drafts') . ' LIMIT 1');
        db_one('SELECT id FROM ' . mcp_t('change_requests') . ' LIMIT 1');
        db_one('SELECT status, traffic_percent FROM prompts LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

function pr_setting(string $k, int $def): int
{
    return (int) (get_setting($k, (string) $def) ?: $def);
}

/* ═══════════════════ الإصدارات ═══════════════════ */

function pr_live(string $type): ?array
{
    return db_one('SELECT * FROM prompts WHERE prompt_type = ? AND is_active = 1 ORDER BY version DESC LIMIT 1', [$type]) ?: null;
}

function pr_testing(string $type): ?array
{
    return db_one('SELECT * FROM prompts WHERE prompt_type = ? AND status = "testing" AND is_active = 0 ORDER BY version DESC LIMIT 1', [$type]) ?: null;
}

/** النص الحالي للنوع (المفعّل · وإلا الافتراضي من الكود) */
function pr_live_text(string $type): string
{
    $l = pr_live($type);
    if ($l) return (string) $l['prompt_text'];
    if (function_exists('prompt_library') && isset(prompt_library()[$type])) return (string) (prompt_library()[$type][3] ?? '');
    return $type === 'content' && function_exists('default_base_prompt') ? default_base_prompt() : '';
}

function pr_insert_version(string $type, string $text, array $f): int
{
    $v = (int) (db_one('SELECT MAX(version) v FROM prompts WHERE prompt_type = ?', [$type])['v'] ?? 0) + 1;
    $title = (string) (db_one('SELECT title FROM prompts WHERE prompt_type = ? ORDER BY version DESC LIMIT 1', [$type])['title'] ?? $type);
    return (int) db_insert('INSERT INTO prompts (prompt_type, title, prompt_text, version, is_active, status, traffic_percent, created_by_kind, reason, parent_id, approved_at, approved_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?)', [
        $type, mb_substr($title, 0, 150), $text, $v, $f['is_active'] ?? 0, $f['status'], (int) ($f['traffic'] ?? 0), $f['kind'] ?? 'ai',
        $f['reason'] ?? null, $f['parent_id'] ?? null, $f['by'] ?? null,
    ]);
}

/* ═══════════════════ مسودات البرومبت ═══════════════════ */

function pr_prompt_draft(int $id): ?array
{
    return db_one('SELECT * FROM ' . mcp_t('prompt_drafts') . ' WHERE id = ?', [$id]) ?: null;
}

/**
 * موافقة: «testing» بنسبة (التجربة القديمة لنفس النوع بتتقفل) أو «live» (المفعّل القديم بيتأرشف ويفضل متاح للرجوع)
 * @return array{ok:bool, prompt_id?:int, error?:string}
 */
function pr_approve_prompt_draft(int $draftId, string $mode, int $pct, int $adminId, string $note = ''): array
{
    $d = pr_prompt_draft($draftId);
    if (!$d || $d['status'] !== 'draft') return ['ok' => false, 'error' => 'المسودة دي اتراجعت خلاص'];
    $type = (string) $d['prompt_type'];
    $live = pr_live($type);
    $fields = ['reason' => mb_substr((string) $d['reason'], 0, 4000), 'parent_id' => $live ? (int) $live['id'] : ((int) $d['parent_prompt_id'] ?: null), 'by' => $adminId, 'kind' => 'ai'];
    if ($mode === 'live') {
        db_run('UPDATE prompts SET is_active = 0, status = IF(status IS NULL OR status IN ("live","approved"), "archived", status) WHERE prompt_type = ? AND is_active = 1', [$type]);
        db_run('UPDATE prompts SET status = "archived", traffic_percent = 0 WHERE prompt_type = ? AND status = "testing"', [$type]);
        $pid = pr_insert_version($type, (string) $d['body'], $fields + ['status' => 'live', 'is_active' => 1]);
    } else {
        $pct = max(1, min(90, $pct));
        db_run('UPDATE prompts SET status = "archived", traffic_percent = 0 WHERE prompt_type = ? AND status = "testing"', [$type]);
        $pid = pr_insert_version($type, (string) $d['body'], $fields + ['status' => 'testing', 'traffic' => $pct]);
    }
    db_run('UPDATE ' . mcp_t('prompt_drafts') . ' SET status = "approved", applied_prompt_id = ?, reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE id = ?',
        [$pid, $adminId, $note !== '' ? mb_substr($note, 0, 2000) : null, $draftId]);
    pr_log($adminId, 'prompt_draft_approve', $pid, $type . ' ← ' . ($mode === 'live' ? 'مفعّل' : 'تجربة ' . $pct . '%') . ' (مسودة #' . $draftId . ')');
    return ['ok' => true, 'prompt_id' => $pid];
}

/* ═══════════════════ تجارب A/B ═══════════════════ */

/** الأنواع اللي فيها تجربة شغالة أو اتغيّرت قريب (للرجوع) */
function pr_ab_list(): array
{
    $types = array_column(db_all('SELECT DISTINCT prompt_type FROM prompts WHERE status = "testing" OR (approved_at IS NOT NULL AND approved_at > DATE_SUB(NOW(), INTERVAL 60 DAY))'), 'prompt_type');
    $out = [];
    foreach ($types as $t) {
        $live = pr_live($t);
        $test = pr_testing($t);
        $prev = pr_previous_live($t);
        $out[$t] = ['type' => $t, 'live' => $live, 'testing' => $test, 'previous' => $prev];
    }
    return $out;
}

/** الإصدار اللي كان مفعّل قبل الحالي (للرجوع بضغطة) */
function pr_previous_live(string $type): ?array
{
    $live = pr_live($type);
    if ($live && !empty($live['parent_id'])) {
        $p = db_one('SELECT * FROM prompts WHERE id = ? AND prompt_type = ?', [(int) $live['parent_id'], $type]);
        if ($p) return $p;
    }
    return db_one('SELECT * FROM prompts WHERE prompt_type = ? AND is_active = 0 AND (status IS NULL OR status IN ("archived","live","approved")) AND id <> ? ORDER BY version DESC LIMIT 1',
        [$type, (int) ($live['id'] ?? 0)]) ?: null;
}

function pr_set_traffic(int $promptId, int $pct, int $adminId): bool
{
    $p = db_one('SELECT * FROM prompts WHERE id = ? AND status = "testing"', [$promptId]);
    if (!$p) return false;
    $pct = max(0, min(90, $pct));
    db_run('UPDATE prompts SET traffic_percent = ? WHERE id = ?', [$pct, $promptId]);
    pr_log($adminId, 'ab_traffic', $promptId, $p['prompt_type'] . ' → ' . $pct . '%');
    return true;
}

/** ترقية التجربة ← المفعّل (القديم بيتأرشف وأب للجديد علشان الرجوع) */
function pr_promote(int $promptId, int $adminId): bool
{
    $p = db_one('SELECT * FROM prompts WHERE id = ? AND status = "testing"', [$promptId]);
    if (!$p) return false;
    $live = pr_live((string) $p['prompt_type']);
    db_run('UPDATE prompts SET is_active = 0, status = "archived" WHERE prompt_type = ? AND is_active = 1', [$p['prompt_type']]);
    db_run('UPDATE prompts SET is_active = 1, status = "live", traffic_percent = 0, parent_id = COALESCE(?, parent_id), approved_at = NOW(), approved_by = ? WHERE id = ?',
        [$live ? (int) $live['id'] : null, $adminId, $promptId]);
    pr_log($adminId, 'ab_promote', $promptId, $p['prompt_type'] . ' v' . $p['version'] . ' بقى المفعّل');
    return true;
}

function pr_stop_test(int $promptId, int $adminId): bool
{
    $p = db_one('SELECT * FROM prompts WHERE id = ? AND status = "testing"', [$promptId]);
    if (!$p) return false;
    db_run('UPDATE prompts SET status = "archived", traffic_percent = 0 WHERE id = ?', [$promptId]);
    pr_log($adminId, 'ab_stop', $promptId, $p['prompt_type'] . ' v' . $p['version'] . ' اتوقفت');
    return true;
}

/** رجوع للإصدار اللي قبله (والحالي بيتأرشف) */
function pr_rollback(string $type, int $adminId): ?int
{
    $live = pr_live($type);
    $prev = pr_previous_live($type);
    if (!$live || !$prev) return null;
    db_run('UPDATE prompts SET is_active = 0, status = "archived" WHERE id = ?', [(int) $live['id']]);
    db_run('UPDATE prompts SET is_active = 1, status = "live", approved_at = NOW(), approved_by = ? WHERE id = ?', [$adminId, (int) $prev['id']]);
    pr_log($adminId, 'prompt_rollback', (int) $prev['id'], $type . ': رجوع من v' . $live['version'] . ' لـ v' . $prev['version']);
    return (int) $prev['id'];
}

/** أرقام إصدارات (منشورات · 👍👎 · إعادة توليد · تقييم Claude) */
function pr_stats(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = [];
    foreach (db_all("SELECT prompt_version_id v, COUNT(*) n, SUM(feedback = 1) up, SUM(feedback = -1) down, MIN(created_at) first_at,
                            AVG((SELECT COUNT(*) FROM content_versions x WHERE x.content_id = c.id AND x.version_type = 'ai') - 1) regen
                     FROM contents c WHERE prompt_version_id IN ($in) GROUP BY prompt_version_id") as $r) {
        $up = (int) $r['up']; $dn = (int) $r['down'];
        $out[(int) $r['v']] = ['posts' => (int) $r['n'], 'up' => $up, 'down' => $dn, 'score' => $up + $dn ? round(($up - $dn) / ($up + $dn), 2) : null,
                               'regen' => $r['regen'] !== null ? round((float) $r['regen'], 2) : null, 'first_at' => $r['first_at']];
    }
    try {
        foreach (db_all('SELECT prompt_version_id v, COUNT(*) n, AVG(overall) o FROM ' . mcp_t('ai_evaluations') . " WHERE prompt_version_id IN ($in) GROUP BY prompt_version_id") as $r) {
            $out[(int) $r['v']]['evaluated'] = (int) $r['n'];
            $out[(int) $r['v']]['overall'] = round((float) $r['o'], 2);
        }
    } catch (\Throwable $e) {}
    return $out;
}

/* ═══════════════════ مسودات الاستايل ═══════════════════ */

/** موافقة: استايل جديد · أو تعديل الموجود (النسخة القديمة بتتحفظ «نسخة قبل الاقتراح» مقفولة للرجوع) */
function pr_approve_style_draft(int $id, int $adminId, string $note = ''): array
{
    $d = db_one('SELECT * FROM ' . mcp_t('style_preset_drafts') . ' WHERE id = ?', [$id]);
    if (!$d || $d['status'] !== 'draft') return ['ok' => false, 'error' => 'المسودة دي اتراجعت خلاص'];
    $payload = json_decode((string) $d['payload'], true);
    if (!is_array($payload)) return ['ok' => false, 'error' => 'المسودة فيها JSON بايظ'];
    if ($d['preset_id']) {
        $old = db_one('SELECT * FROM style_presets WHERE id = ?', [(int) $d['preset_id']]);
        if (!$old) return ['ok' => false, 'error' => 'الاستايل اتمسح'];
        db_insert('INSERT INTO style_presets (kind, name, preview_path, data, sector_template_id, is_active, sort_order) VALUES (?,?,?,?,?,0,?)',
            [$old['kind'], mb_substr($old['name'] . ' — نسخة قبل اقتراح #' . $id, 0, 100), $old['preview_path'], $old['data'], $old['sector_template_id'], (int) $old['sort_order'] + 1]);
        $data = array_merge(json_decode((string) $old['data'], true) ?: [], $payload);
        db_run('UPDATE style_presets SET data = ? WHERE id = ?', [json_encode($data, JSON_UNESCAPED_UNICODE), (int) $old['id']]);
        $pid = (int) $old['id'];
    } else {
        $kind = in_array($payload['kind'] ?? '', ['design_style', 'palette', 'tone', 'visual', 'character_style'], true) ? $payload['kind'] : 'design_style';
        $pid = (int) db_insert('INSERT INTO style_presets (kind, name, data, is_active, sort_order) VALUES (?,?,?,0,99)',
            [$kind, mb_substr((string) ($payload['name'] ?? 'استايل مقترح #' . $id), 0, 100), json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
    db_run('UPDATE ' . mcp_t('style_preset_drafts') . ' SET status = "approved", reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE id = ?', [$adminId, $note ?: null, $id]);
    pr_log($adminId, 'style_draft_approve', $pid, 'مسودة #' . $id);
    return ['ok' => true, 'preset_id' => $pid, 'new' => !$d['preset_id']];
}

/* ═══════════════════ الرفض والاقتراحات ═══════════════════ */

const PR_CR_STATUSES = ['pending' => ['مستني', 'chip-amber'], 'approved' => ['اتوافق — للتنفيذ', 'chip-primary'], 'applied' => ['اتنفّذ', 'chip-mint'],
                        'rejected' => ['اترفض', 'chip-line'], 'postponed' => ['متأجّل', 'chip-line'], 'reverted' => ['رجعناه', 'chip-coral']];

/** مراجعة اقتراح: الرفض لازم ملاحظة (Claude بيشوفها ومايعيدوش) */
function pr_review_change(int $id, string $to, int $adminId, string $note): array
{
    if (!isset(PR_CR_STATUSES[$to]) || $to === 'pending') return ['ok' => false, 'error' => 'حالة غلط'];
    if ($to === 'rejected' && mb_strlen(trim($note)) < 3) return ['ok' => false, 'error' => 'اكتب سبب الرفض — Claude بيقراه علشان مايقترحوش تاني'];
    $c = db_one('SELECT * FROM ' . mcp_t('change_requests') . ' WHERE id = ?', [$id]);
    if (!$c) return ['ok' => false, 'error' => 'الاقتراح مش موجود'];
    db_run('UPDATE ' . mcp_t('change_requests') . ' SET status = ?, reviewed_at = NOW(), reviewed_by = ?, review_note = COALESCE(?, review_note) WHERE id = ?',
        [$to, $adminId, trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null, $id]);
    pr_log($adminId, 'change_request_' . $to, $id, (string) $c['target']);
    return ['ok' => true];
}

function pr_reject_draft(string $kind, int $id, int $adminId, string $note): array
{
    $tbl = ['prompt' => 'prompt_drafts', 'style' => 'style_preset_drafts'][$kind] ?? null;
    if (!$tbl) return ['ok' => false, 'error' => 'نوع غلط'];
    if (mb_strlen(trim($note)) < 3) return ['ok' => false, 'error' => 'اكتب سبب الرفض'];
    db_run('UPDATE ' . mcp_t($tbl) . ' SET status = "rejected", reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE id = ? AND status = "draft"', [$adminId, mb_substr(trim($note), 0, 2000), $id]);
    pr_log($adminId, $kind . '_draft_reject', $id, $note);
    return ['ok' => true];
}

function pr_log(int $adminId, string $action, int $id, string $note): void
{
    if (function_exists('admin_log')) { try { admin_log($action, 'prompt_review', $id, mb_substr($note, 0, 500)); } catch (\Throwable $e) {} }
}

/* ═══════════════════ التوليد التجريبي (sandbox) ═══════════════════ */

function pr_sandbox_left(): int
{
    $cap = pr_setting('sandbox_daily_cap', 20);
    if ($cap <= 0) return 0;
    try { $used = (int) (db_one('SELECT COUNT(*) n FROM ' . mcp_t('sandbox_runs') . ' WHERE created_at >= CURDATE()')['n'] ?? 0); } catch (\Throwable $e) { $used = 0; }
    return max(0, $cap - $used);
}

/** آخر منشور «content» يصلح للتجربة (لو مااتحددش) */
function pr_sample_post(string $type): ?int
{
    $r = $type === 'content'
        ? db_one('SELECT c.id FROM contents c LEFT JOIN prompts p ON p.id = c.prompt_version_id WHERE c.generated_text <> "" AND (p.prompt_type = "content" OR (c.prompt_version_id IS NULL AND c.campaign_id IS NULL)) ORDER BY c.id DESC LIMIT 1')
        : db_one('SELECT c.id FROM contents c JOIN prompts p ON p.id = c.prompt_version_id WHERE p.prompt_type = ? AND c.generated_text <> "" ORDER BY c.id DESC LIMIT 1', [$type]);
    return $r ? (int) $r['id'] : null;
}

/**
 * نفس المنشور بنص برومبت تاني — من غير ما يلمس المنشور أو كريدت العميل
 * @param string $by admin | mcp (للسجل)
 * @return array{ok:bool, before?:string, after?:string, run_id?:int, approx?:bool, error?:string, code?:string}
 */
function pr_sandbox(int $contentId, string $type, string $body, ?int $draftId = null, ?int $versionId = null, ?callable $store = null): array
{
    if (pr_sandbox_left() <= 0) return ['ok' => false, 'code' => 'cap', 'error' => 'وصلت لحد التوليد التجريبي النهارده (إعدادات الجودة ← توليد تجريبي في اليوم)'];
    $c = db_one('SELECT * FROM contents WHERE id = ?', [$contentId]);
    if (!$c || trim((string) $c['generated_text']) === '') return ['ok' => false, 'code' => 'not_found', 'error' => 'المنشور #' . $contentId . ' مش موجود أو فاضي'];
    if (trim($body) === '') return ['ok' => false, 'code' => 'invalid', 'error' => 'نص البرومبت فاضي'];
    require_once __DIR__ . '/prompt-builder.php';
    require_once __DIR__ . '/brand-brain.php';
    $uid = (int) $c['user_id'];
    $brand = db_one('SELECT * FROM brand_profiles WHERE id = ?', [(int) $c['brand_profile_id']]) ?: (brand_for_user($uid) ?: []);
    $approx = false;
    $GLOBALS['__prompt_override'][$type] = $body;   // prompt_pick بيرجّع المسودة للنوع ده في الطلب ده بس
    try {
        if ($type === 'content') {
            $prompt = build_final_prompt($brand, [
                'content_type' => (string) $c['content_type'], 'platform' => (string) $c['platform'], 'length' => (string) $c['length'],
                'tone' => (string) $c['tone'], 'dialect' => (string) ($c['dialect'] ?: 'egyptian'), 'template_id' => $c['template_id'] ? (int) $c['template_id'] : null,
            ], (string) ($c['extra_notes'] ?? ''));
        } else {
            // الأنواع التانية: نفس المتغيرات الأساسية (تقريبي — مش نفس مسار الكود بالظبط)
            $approx = true;
            $prompt = prompt_apply($body, ['content_type' => (string) $c['content_type'], 'platform' => (string) $c['platform'], 'length' => (string) $c['length'],
                'topic' => mb_substr((string) ($c['extra_notes'] ?: $c['generated_text']), 0, 300), 'post_text' => (string) $c['generated_text'], 'extra_notes' => (string) ($c['extra_notes'] ?? '')]
                + ($uid ? prompt_brand_vars($uid, $brand ?: null, 'copy', ['identity' => true]) : []), ['strip_output' => false]);
        }
    } finally {
        unset($GLOBALS['__prompt_override'][$type]);
    }
    $r = ai_generate($prompt, [], ['task' => $type === 'content' ? 'content' : null, 'sandbox' => 1, 'prompt_version_id' => $versionId, 'max_tokens' => 1200, 'temperature' => 0.8]);
    if (empty($r['ok'])) return ['ok' => false, 'code' => 'ai', 'error' => 'الموديل مارجعش رد: ' . ($r['error'] ?? '')];
    $p = parse_ai_response((string) $r['response']);
    $after = trim($p['content'] ?? '') !== '' ? trim($p['content'] . ($p['hashtags'] ? "\n\n" . $p['hashtags'] : '') . ($p['cta'] ? "\n\nCTA: " . $p['cta'] : '')) : trim((string) $r['response']);
    $before = trim((string) $c['generated_text'] . ($c['hashtags'] ? "\n\n" . $c['hashtags'] : '') . ($c['cta'] ? "\n\nCTA: " . $c['cta'] : ''));
    $sql = 'INSERT INTO ' . mcp_t('sandbox_runs') . ' (content_id, prompt_draft_id, prompt_version_id, before_text, after_text) VALUES (?,?,?,?,?)';
    $args = [$contentId, $draftId, $versionId, $before, $after];
    $runId = $store ? (int) $store($sql, $args) : (int) db_insert($sql, $args);
    return ['ok' => true, 'before' => $before, 'after' => $after, 'run_id' => $runId, 'approx' => $approx, 'left_today' => pr_sandbox_left()];
}

/** فرق سطر بسطر (للعرض) — [['=',$l] | ['-',$l] | ['+',$l]] */
function pr_diff(string $a, string $b): array
{
    $x = preg_split('/\R/u', $a);
    $y = preg_split('/\R/u', $b);
    $n = count($x); $m = count($y);
    if ($n * $m > 250000) {   // كبير ← مقارنة بسيطة
        $out = [];
        foreach ($x as $l) $out[] = ['-', $l];
        foreach ($y as $l) $out[] = ['+', $l];
        return $out;
    }
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) for ($j = $m - 1; $j >= 0; $j--) $L[$i][$j] = $x[$i] === $y[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
    $out = []; $i = 0; $j = 0;
    while ($i < $n && $j < $m) {
        if ($x[$i] === $y[$j]) { $out[] = ['=', $x[$i]]; $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $out[] = ['-', $x[$i++]]; }
        else { $out[] = ['+', $y[$j++]]; }
    }
    while ($i < $n) $out[] = ['-', $x[$i++]];
    while ($j < $m) $out[] = ['+', $y[$j++]];
    return $out;
}

/** HTML للفرق (السطور اللي ماتغيّرتش بتتلم) */
function pr_diff_html(string $a, string $b): string
{
    $d = pr_diff($a, $b);
    $h = '<div class="pr-diff">';
    $same = 0;
    foreach ($d as $k => [$op, $l]) {
        if ($op === '=') {
            $near = false;
            for ($q = max(0, $k - 2); $q <= min(count($d) - 1, $k + 2); $q++) if ($d[$q][0] !== '=') { $near = true; break; }
            if (!$near) { $same++; continue; }
        }
        if ($same) { $h .= '<div class="pr-skip">… ' . $same . ' سطر زي ما هم …</div>'; $same = 0; }
        $h .= '<div class="pr-' . ($op === '+' ? 'add' : ($op === '-' ? 'del' : 'eq')) . '"><i>' . $op . '</i>' . e($l === '' ? ' ' : $l) . '</div>';
    }
    if ($same) $h .= '<div class="pr-skip">… ' . $same . ' سطر زي ما هم …</div>';
    return $h . '</div>';
}
