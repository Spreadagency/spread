<?php
/**
 * Spread AI — محرك المحتوى v3 · حلقة التعلّم (المرحلة I)
 *
 * 1) نتايج كل قطعة بعد النشر (ce_performance) — العميل بيدخّلها دلوقتي، ومتصممة تتربط بـ Meta Insights بعدين (source = meta).
 * 2) التقرير الشهري (ce_learning_reports) — عام · لكل تخصص (Skill) · لكل براند:
 *    - العلاقة بين درجة الناقد ومعدل التفاعل الحقيقي (Spearman بالترتيب + Pearson)
 *    - أنهي معيار من العشرة بيتنبأ بالأداء فعلًا (عشان أوزان الناقد)
 *    - الأشكال والزوايا والأعمدة والدوافع اللي بتجيب تفاعل أعلى من المتوسط
 *    - أحسن 3 قطع
 * 3) لكل براند (لو الإعداد مفتوح): أحسن 3 قطع ← golden_ce_ids في ملف الهوية (أمثلة للكاتب) · الأنماط الناجحة ← winning_patterns
 * 4) لكل تخصص: Hooks أحسن القطع ← اقتراح نسخة جديدة للـ Skill (مسودة — محتاجة اختبار رجوع زي أي نسخة)
 */
require_once __DIR__ . '/ce-write.php';

const CE_PERF_FIELDS = ['reach', 'impressions', 'likes', 'comments', 'shares', 'saves', 'clicks', 'messages'];
const CE_PERF_PLATFORMS = ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'tiktok' => 'تيك توك', 'other' => 'تاني'];
/** وزن كل تفاعل في المعدل (الرسايل والحفظ والمشاركة أغلى من اللايك) */
const CE_PERF_WEIGHTS = ['likes' => 1, 'comments' => 2, 'shares' => 3, 'saves' => 3, 'clicks' => 2, 'messages' => 5];
const CE_PERF_MAX = 2000000000;

function ce_learning_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM ce_performance LIMIT 1');
        db_one('SELECT id FROM ce_learning_reports LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/* ═══════════════════ النتايج ═══════════════════ */

/** معدل التفاعل الموزون % = Σ(تفاعل × وزنه) ÷ الوصول (أو المشاهدات لو الوصول مش معروف) */
function ce_perf_rate(array $p): float
{
    $base = max((int) ($p['reach'] ?? 0), 0) ?: max((int) ($p['impressions'] ?? 0), 0);
    if ($base <= 0) return 0.0;
    $sum = 0;
    foreach (CE_PERF_WEIGHTS as $k => $w) $sum += $w * max(0, (int) ($p[$k] ?? 0));
    return round(min(9999, 100 * $sum / $base), 3);
}

/** حفظ نتايج قطعة (العميل لقطعته بس · الأدمن لأي قطعة) — بيرجع الصف بعد الحفظ */
function ce_perf_save(int $uid, int $contentId, array $in, string $source = 'manual', ?int $adminId = null): array
{
    if (!ce_learning_ready()) return ['ok' => false, 'error' => 'شغّل ترحيل 2026_ce_learning الأول'];
    $c = $adminId ? db_one('SELECT * FROM ce_contents WHERE id = ?', [$contentId]) : db_one('SELECT * FROM ce_contents WHERE id = ? AND user_id = ?', [$contentId, $uid]);
    if (!$c) return ['ok' => false, 'code' => 'not_found', 'error' => 'القطعة مش موجودة'];
    if (!in_array($c['status'], ['pass', 'manual'], true)) return ['ok' => false, 'code' => 'state', 'error' => 'القطعة لسه ماخلصتش'];
    $row = ['platform' => isset(CE_PERF_PLATFORMS[$in['platform'] ?? '']) ? $in['platform'] : 'facebook'];
    $d = (string) ($in['published_at'] ?? '');
    $row['published_at'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) && strtotime($d) <= time() + 86400 ? $d : null;
    $any = 0;
    foreach (CE_PERF_FIELDS as $f) {
        $v = $in[$f] ?? 0;
        if (!is_numeric($v) || $v < 0 || $v > CE_PERF_MAX) return ['ok' => false, 'code' => 'input', 'error' => 'الأرقام لازم تبقى صحيحة وموجبة'];
        $row[$f] = (int) $v;
        $any += $row[$f];
    }
    if ($any === 0) return ['ok' => false, 'code' => 'input', 'error' => 'دخّل رقم واحد على الأقل'];
    if ($row['reach'] === 0 && $row['impressions'] === 0) return ['ok' => false, 'code' => 'input', 'error' => 'دخّل الوصول (Reach) أو المشاهدات — من غيرهم مانقدرش نحسب المعدل'];
    if ($row['reach'] > 0 && $row['likes'] + $row['comments'] + $row['shares'] > $row['reach'] * 5) return ['ok' => false, 'code' => 'input', 'error' => 'التفاعل أكبر من الوصول بكتير — راجع الأرقام'];
    $row['eng_rate'] = ce_perf_rate($row);
    $cols = array_merge(['user_id', 'campaign_id', 'content_id', 'source', 'entered_by_admin'], array_keys($row));
    $vals = array_merge([(int) $c['user_id'], (int) $c['campaign_id'], $contentId, in_array($source, ['manual', 'meta', 'import'], true) ? $source : 'manual', $adminId], array_values($row));
    $upd = implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", array_merge(array_keys($row), ['source', 'entered_by_admin'])));
    db_run('INSERT INTO ce_performance (`' . implode('`, `', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . $upd, $vals);
    return ['ok' => true, 'perf' => ce_perf_public(db_one('SELECT * FROM ce_performance WHERE content_id = ?', [$contentId]))];
}

function ce_perf_public(?array $p): ?array
{
    if (!$p) return null;
    $out = ['platform' => $p['platform'], 'published_at' => $p['published_at'], 'eng_rate' => (float) $p['eng_rate'], 'source' => $p['source'], 'updated_at' => $p['updated_at'] ?: $p['created_at']];
    foreach (CE_PERF_FIELDS as $f) $out[$f] = (int) $p[$f];
    return $out;
}

/** نتايج حملة (content_id ← النتايج) */
function ce_perf_for_campaign(int $cid): array
{
    if (!ce_learning_ready()) return [];
    $out = [];
    foreach (db_all('SELECT * FROM ce_performance WHERE campaign_id = ?', [$cid]) as $p) $out[(int) $p['content_id']] = ce_perf_public($p);
    return $out;
}

/* ═══════════════════ الإحصاء ═══════════════════ */

function ce_pearson(array $x, array $y): ?float
{
    $n = count($x);
    if ($n < 3 || $n !== count($y)) return null;
    $mx = array_sum($x) / $n;
    $my = array_sum($y) / $n;
    $sxy = $sxx = $syy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $x[$i] - $mx;
        $dy = $y[$i] - $my;
        $sxy += $dx * $dy;
        $sxx += $dx * $dx;
        $syy += $dy * $dy;
    }
    if ($sxx <= 0 || $syy <= 0) return null;
    return round($sxy / sqrt($sxx * $syy), 3);
}

/** ترتيب بمتوسط الرتب للقيم المتساوية */
function ce_ranks(array $v): array
{
    $idx = array_keys($v);
    usort($idx, fn($a, $b) => $v[$a] <=> $v[$b]);
    $r = [];
    for ($i = 0, $n = count($idx); $i < $n;) {
        $j = $i;
        while ($j + 1 < $n && $v[$idx[$j + 1]] == $v[$idx[$i]]) $j++;
        $avg = ($i + $j) / 2 + 1;
        for ($k = $i; $k <= $j; $k++) $r[$idx[$k]] = $avg;
        $i = $j + 1;
    }
    ksort($r);
    return array_values($r);
}

function ce_spearman(array $x, array $y): ?float
{
    if (count($x) < 3) return null;
    return ce_pearson(ce_ranks(array_values($x)), ce_ranks(array_values($y)));
}

/** وصف العلاقة بالعربي */
function ce_corr_label(?float $r): string
{
    if ($r === null) return 'مش كفاية بيانات';
    $a = abs($r);
    $s = $a >= 0.6 ? 'قوية' : ($a >= 0.35 ? 'متوسطة' : ($a >= 0.15 ? 'ضعيفة' : 'مفيش علاقة تقريبًا'));
    if ($a < 0.15) return $s;
    return $s . ($r > 0 ? ' — الدرجة العالية بتجيب تفاعل أعلى' : ' — عكسية: الناقد بيكافئ حاجات الجمهور مش بيتفاعل معاها');
}

/* ═══════════════════ التقرير الشهري ═══════════════════ */

function ce_learning_period_ok(string $p): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p);
}

/** الشهر اللي فات (YYYY-MM) */
function ce_learning_last_month(): string
{
    return date('Y-m', strtotime('first day of last month'));
}

/**
 * القطع اللي ليها نتايج في الشهر ده (بتاريخ النشر — ولو مش متسجّل بتاريخ إدخال النتايج)
 * scope: global · skill (scope_id = ce_skills.id) · user (scope_id = user_id)
 */
function ce_learning_rows(string $period, string $scope = 'global', int $scopeId = 0): array
{
    $from = $period . '-01';
    $to = date('Y-m-d', strtotime($from . ' +1 month'));
    $sql = 'SELECT p.*, c.qa_score, c.qa_json, c.body_json, c.format, c.idea_id, s.pillar, s.angle, s.funnel, s.cta_level,
                   i.trigger_type, i.hook, k.skill_version_id, sv.skill_id
            FROM ce_performance p
            JOIN ce_contents c ON c.id = p.content_id
            LEFT JOIN ce_slots s ON s.id = c.slot_id
            LEFT JOIN ce_ideas i ON i.id = c.idea_id
            JOIN ce_campaigns k ON k.id = p.campaign_id
            LEFT JOIN ce_skill_versions sv ON sv.id = k.skill_version_id
            WHERE COALESCE(p.published_at, DATE(p.created_at)) >= ? AND COALESCE(p.published_at, DATE(p.created_at)) < ? AND c.qa_score IS NOT NULL';
    $args = [$from, $to];
    if ($scope === 'user') { $sql .= ' AND p.user_id = ?'; $args[] = $scopeId; }
    if ($scope === 'skill') { $sql .= ' AND sv.skill_id = ?'; $args[] = $scopeId; }
    try {
        return db_all($sql . ' ORDER BY p.id', $args);
    } catch (\Throwable $e) {
        // ترحيل الـ Skills مش متشغّل
        if ($scope === 'skill') return [];
        return db_all(str_replace(['k.skill_version_id, sv.skill_id', 'LEFT JOIN ce_skill_versions sv ON sv.id = k.skill_version_id'], ['NULL skill_version_id, NULL skill_id', ''], $sql) . ' ORDER BY p.id', $args);
    }
}

/** نص قصير من القطعة (للعرض وللكاتب كمثال) */
function ce_learning_excerpt(array $row, int $max = 400): string
{
    $b = json_decode((string) $row['body_json'], true) ?: [];
    return mb_substr(trim(ce_content_text($b)), 0, $max);
}

/** حساب التقرير (من غير حفظ) */
function ce_learning_compute(array $rows): array
{
    $n = count($rows);
    $rate = array_map(fn($r) => (float) $r['eng_rate'], $rows);
    $qa = array_map(fn($r) => (float) $r['qa_score'], $rows);
    $avg = $n ? array_sum($rate) / $n : 0.0;
    $out = ['n' => $n, 'avg_rate' => round($avg, 3), 'avg_qa' => $n ? round(array_sum($qa) / $n, 1) : null,
            'spearman' => ce_spearman($qa, $rate), 'pearson' => ce_pearson($qa, $rate), 'criteria' => [], 'dims' => [], 'top' => [], 'bottom' => []];
    // كل معيار من العشرة لوحده
    foreach (array_keys(CE_QA_LABELS) as $k) {
        $xs = $ys = [];
        foreach ($rows as $i => $r) {
            $q = json_decode((string) $r['qa_json'], true) ?: [];
            if (isset($q['criteria'][$k]['score'])) { $xs[] = (float) $q['criteria'][$k]['score']; $ys[] = $rate[$i]; }
        }
        $out['criteria'][$k] = ['label' => CE_QA_LABELS[$k], 'n' => count($xs), 'spearman' => ce_spearman($xs, $ys), 'weight' => ce_qa_weights()[$k] ?? null];
    }
    // الأشكال · الزوايا · الأعمدة · الدوافع · المراحل — المتوسط قدام المتوسط العام
    $angles = function_exists('ce_angles') ? ce_angles(false) : [];
    $dims = ['format' => CE_FORMATS, 'angle' => array_map(fn($a) => $a['label'], $angles), 'pillar' => CE_PILLARS, 'trigger_type' => CE_TRIGGERS, 'funnel' => CE_FUNNELS];
    foreach ($dims as $dim => $labels) {
        $g = [];
        foreach ($rows as $i => $r) {
            $v = (string) ($r[$dim] ?? '');
            if ($v === '') continue;
            $g[$v][] = $rate[$i];
        }
        $list = [];
        foreach ($g as $v => $xs) {
            $m = array_sum($xs) / count($xs);
            $list[] = ['key' => $v, 'label' => $labels[$v] ?? $v, 'n' => count($xs), 'avg_rate' => round($m, 3), 'lift' => $avg > 0 ? round(100 * ($m - $avg) / $avg) : 0];
        }
        usort($list, fn($a, $b) => $b['avg_rate'] <=> $a['avg_rate']);
        $out['dims'][$dim] = $list;
    }
    // أحسن وأضعف القطع
    $order = array_keys($rate);
    usort($order, fn($a, $b) => $rate[$b] <=> $rate[$a]);
    $pick = fn($i) => ['content_id' => (int) $rows[$i]['content_id'], 'campaign_id' => (int) $rows[$i]['campaign_id'], 'user_id' => (int) $rows[$i]['user_id'],
                       'eng_rate' => $rate[$i], 'qa_score' => $qa[$i], 'format' => $rows[$i]['format'], 'hook' => (string) ($rows[$i]['hook'] ?? ''), 'excerpt' => ce_learning_excerpt($rows[$i], 220)];
    foreach (array_slice($order, 0, 3) as $i) $out['top'][] = $pick($i);
    foreach (array_slice(array_reverse($order), 0, 3) as $i) if ($n > 3) $out['bottom'][] = $pick($i);
    $out['patterns'] = ce_learning_patterns($out);
    $out['weights_hint'] = ce_learning_weights_hint($out['criteria']);
    return $out;
}

/** الأنماط الناجحة (جمل قصيرة لملف الهوية): أي قيمة جابت ≥ 20% فوق المتوسط وفيها قطعتين على الأقل */
function ce_learning_patterns(array $rep): array
{
    $names = ['format' => 'شكل', 'angle' => 'زاوية', 'pillar' => 'محتوى', 'trigger_type' => 'دافع', 'funnel' => 'مرحلة'];
    $all = [];
    foreach ($rep['dims'] as $dim => $list) {
        foreach ($list as $x) {
            if ($x['n'] >= 2 && $x['lift'] >= 20) $all[] = ['lift' => $x['lift'], 'text' => mb_substr($names[$dim] . ' «' . $x['label'] . '» بيجيب تفاعل أعلى بـ ' . $x['lift'] . '%', 0, 100)];
        }
    }
    usort($all, fn($a, $b) => $b['lift'] <=> $a['lift']);
    return array_slice(array_column($all, 'text'), 0, 3);
}

/** اقتراح أوزان للناقد: المعايير اللي بتتنبأ بالأداء (علاقة موجبة) تاخد وزن أكبر — اقتراح بس، الأدمن يقرر */
function ce_learning_weights_hint(array $criteria): ?array
{
    $pos = [];
    foreach ($criteria as $k => $c) if ($c['spearman'] !== null && $c['n'] >= 5) $pos[$k] = max(0.05, $c['spearman'] + 0.2);
    if (count($pos) < 5) return null;
    $sum = array_sum($pos);
    $out = [];
    foreach ($pos as $k => $v) $out[$k] = max(3, (int) round(100 * $v / $sum));
    return $out;
}

/**
 * التقرير لشهر ونطاق — بيتحفظ (ولو اتعمل قبل كده بيتحدّث). لتقرير البراند: بيحدّث ملف الهوية لو الإعداد مفتوح.
 * @return array{ok:bool, report?:array, status?:string}
 */
function ce_learning_run(string $period, string $scope = 'global', int $scopeId = 0, ?bool $apply = null): array
{
    if (!ce_learning_ready()) return ['ok' => false, 'error' => 'شغّل ترحيل 2026_ce_learning الأول'];
    if (!ce_learning_period_ok($period)) return ['ok' => false, 'error' => 'الشهر لازم يبقى YYYY-MM'];
    if (!in_array($scope, ['global', 'skill', 'user'], true)) return ['ok' => false, 'error' => 'نطاق مش معروف'];
    $rows = ce_learning_rows($period, $scope, $scopeId);
    $rep = ce_learning_compute($rows);
    $min = max(3, ce_setting_int('ce_learning_min_n', 5));
    $rep['enough'] = $rep['n'] >= $min;
    $rep['min_n'] = $min;
    $rep['label'] = $rep['enough'] ? ce_corr_label($rep['spearman']) : 'مش كفاية بيانات (' . $rep['n'] . ' من ' . $min . ')';
    $applied = null;
    $apply = $apply ?? (ce_setting_int('ce_learning_apply', 1) === 1);
    if ($scope === 'user' && $apply && $rep['n'] >= 3) $applied = ce_learning_apply_brand($scopeId, $rep);
    db_run('INSERT INTO ce_learning_reports (period, scope, scope_id, n, spearman, pearson, report_json, applied_json) VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE n = VALUES(n), spearman = VALUES(spearman), pearson = VALUES(pearson), report_json = VALUES(report_json), applied_json = COALESCE(VALUES(applied_json), applied_json)',
        [$period, $scope, $scopeId, $rep['n'], $rep['enough'] ? $rep['spearman'] : null, $rep['enough'] ? $rep['pearson'] : null,
         json_encode($rep, JSON_UNESCAPED_UNICODE), $applied ? json_encode($applied, JSON_UNESCAPED_UNICODE) : null]);
    return ['ok' => true, 'report' => $rep, 'applied' => $applied];
}

/** الأنماط اللي التعلّم ضافها قبل كده للبراند (عشان نبدّلها من غير ما نلمس اللي العميل كتبه) */
function ce_learning_learned_patterns(int $uid): array
{
    if (!ce_learning_ready()) return [];
    $r = db_one('SELECT applied_json FROM ce_learning_reports WHERE scope = "user" AND scope_id = ? AND applied_json IS NOT NULL ORDER BY period DESC LIMIT 1', [$uid]);
    return $r ? array_values((array) ((json_decode((string) $r['applied_json'], true) ?: [])['patterns'] ?? [])) : [];
}

/** أحسن 3 قطع ← أمثلة للكاتب (golden_ce_ids) · الأنماط ← winning_patterns (بنبدّل اللي اتعلّمناه قبل كده بس) */
function ce_learning_apply_brand(int $uid, array $rep): ?array
{
    $dna = ce_dna_get($uid);
    if (!$dna) return null;
    $top = array_map(fn($t) => (int) $t['content_id'], array_filter($rep['top'], fn($t) => $t['eng_rate'] > 0));
    $golden = array_slice(array_values(array_unique(array_merge($top, array_map('intval', (array) ($dna['golden_ce_ids'] ?? []))))), 0, 5);
    $old = ce_learning_learned_patterns($uid);
    $mine = array_values(array_filter((array) ($dna['winning_patterns'] ?? []), fn($p) => !in_array($p, $old, true)));
    $patterns = array_slice(array_values(array_unique(array_merge($rep['patterns'], $mine))), 0, 5);
    if ($golden === array_map('intval', (array) ($dna['golden_ce_ids'] ?? [])) && $patterns === array_values((array) ($dna['winning_patterns'] ?? []))) {
        return ['golden_ce_ids' => $golden, 'patterns' => $rep['patterns'], 'changed' => false];
    }
    $dna['golden_ce_ids'] = $golden;
    $dna['winning_patterns'] = $patterns;
    ce_dna_save($uid, $dna, 'learning', null, null, ce_dna_edited($uid));
    return ['golden_ce_ids' => $golden, 'patterns' => $rep['patterns'], 'changed' => true];
}

/** نص الأمثلة الذهبية للكاتب (من المحرك الجديد) */
function ce_learning_golden_texts(int $uid, array $ids, int $limit = 2): array
{
    $out = [];
    foreach (array_slice(array_map('intval', $ids), 0, $limit) as $id) {
        $r = db_one('SELECT body_json FROM ce_contents WHERE id = ? AND user_id = ?', [$id, $uid]);
        if ($r && ($t = ce_learning_excerpt($r, 500)) !== '') $out[] = $t;
    }
    return $out;
}

/**
 * الدورة الشهرية: عام + كل تخصص فيه بيانات + كل براند عنده نتايج — بتتنادي من الـ worker مرة في الشهر (أو من الأدمن)
 * @return array ملخص
 */
function ce_learning_month(string $period, ?bool $apply = null): array
{
    $sum = ['period' => $period, 'global' => null, 'skills' => 0, 'users' => 0];
    $g = ce_learning_run($period, 'global', 0, $apply);
    if (!$g['ok']) return $sum + ['error' => $g['error']];
    $sum['global'] = ['n' => $g['report']['n'], 'spearman' => $g['report']['spearman']];
    $rows = ce_learning_rows($period);
    $skills = array_unique(array_filter(array_map(fn($r) => (int) ($r['skill_id'] ?? 0), $rows)));
    foreach ($skills as $sid) { ce_learning_run($period, 'skill', $sid, $apply); $sum['skills']++; }
    $users = array_unique(array_map(fn($r) => (int) $r['user_id'], $rows));
    foreach ($users as $uid) { ce_learning_run($period, 'user', $uid, $apply); $sum['users']++; }
    return $sum;
}

/** من الـ worker: لو تقرير الشهر اللي فات ماتعملش ← اعمله (مرة واحدة) */
function ce_learning_maybe_monthly(): ?array
{
    if (!ce_learning_ready()) return null;
    $p = ce_learning_last_month();
    if ((string) get_setting('ce_learning_last_period', '') === $p) return null;
    set_setting('ce_learning_last_period', $p);   // قبل التشغيل — عشان لو وقع مايتكررش كل دقيقة
    return ce_learning_month($p);
}

/** اقتراح نسخة جديدة للـ Skill: Hooks أحسن القطع في التخصص ده بتتضاف لقسم «أمثلة Hooks» (مسودة) */
function ce_learning_skill_draft(int $reportId, ?int $adminId): array
{
    $r = db_one('SELECT * FROM ce_learning_reports WHERE id = ? AND scope = "skill"', [$reportId]);
    if (!$r) return ['ok' => false, 'error' => 'التقرير مش موجود'];
    $rep = json_decode((string) $r['report_json'], true) ?: [];
    $hooks = array_values(array_unique(array_filter(array_map(fn($t) => trim((string) $t['hook']), (array) ($rep['top'] ?? [])))));
    if (!$hooks) return ['ok' => false, 'error' => 'مفيش Hooks في أحسن القطع'];
    $act = db_one('SELECT * FROM ce_skill_versions WHERE skill_id = ? ORDER BY is_active DESC, version DESC LIMIT 1', [(int) $r['scope_id']]);
    if (!$act) return ['ok' => false, 'error' => 'الـ Skill مالهاش نسخ'];
    $md = (string) $act['body_md'];
    $add = implode("\n", array_map(fn($h) => '- ' . $h, array_filter($hooks, fn($h) => !str_contains($md, $h))));
    if ($add === '') return ['ok' => false, 'error' => 'الـ Hooks دي موجودة في الـ Skill قبل كده'];
    $add = "<!-- من تقرير التعلّم " . $r['period'] . " -->\n" . $add;
    if (preg_match('/^##\s+(أمثلة hooks|أمثلة الـ hooks|hooks|أمثلة البدايات)\s*$/miu', $md, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $next = preg_match('/^##\s+/mu', $md, $n2, PREG_OFFSET_CAPTURE, $pos) ? $n2[0][1] : strlen($md);
        $md = rtrim(substr($md, 0, $next)) . "\n" . $add . "\n\n" . ltrim(substr($md, $next));
    } else {
        $md = rtrim($md) . "\n\n## أمثلة Hooks\n" . $add . "\n";
    }
    return ce_skill_save_md($md, (int) $r['scope_id'], 'Hooks من أحسن القطع (' . $r['period'] . ')', 'edit', $adminId);
}

/* ═══════════════════ لوحة الاستخدام (الأدمن) ═══════════════════ */

/** @return array{from:string, to:string} */
function ce_usage_range(int $days): array
{
    $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
    return ['from' => date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')), 'to' => date('Y-m-d 23:59:59'), 'days' => $days];
}

/**
 * الاستهلاك: الإجمالي · يومي · شهري (12 شهر) · لكل مرحلة · لكل موديل · لكل عميل · أغلى 10 حملات · الـ Playground لوحده
 * $engineOnly = مراحل المحرك الجديد بس (task LIKE ce_%)
 */
function ce_usage(int $days = 30, bool $engineOnly = true): array
{
    $R = ce_usage_range($days);
    $w = 'created_at BETWEEN ? AND ? AND sandbox = 0' . ($engineOnly ? ' AND task LIKE "ce\\_%"' : '');
    $a = [$R['from'], $R['to']];
    $sel = 'COUNT(*) calls, COALESCE(SUM(tokens_in),0) tin, COALESCE(SUM(tokens_out),0) tout, COALESCE(SUM(tokens_cached),0) tcache, COALESCE(SUM(cost_usd),0) cost,
            COALESCE(SUM(credits_charged),0) credits, SUM(status <> "ok") fails, AVG(duration_ms) ms, SUM(status = "ok" AND cost_usd = 0 AND (tokens_in + tokens_out) > 0) unknown_cost';
    $out = ['range' => $R, 'engine_only' => $engineOnly];
    $out['total'] = db_one("SELECT $sel FROM ai_runs WHERE $w", $a);
    $out['daily'] = db_all("SELECT DATE(created_at) d, COUNT(*) calls, COALESCE(SUM(cost_usd),0) cost, COALESCE(SUM(tokens_in + tokens_out),0) tokens FROM ai_runs WHERE $w GROUP BY DATE(created_at) ORDER BY d", $a);
    $mFrom = date('Y-m-01 00:00:00', strtotime('first day of -11 months'));
    $out['monthly'] = db_all('SELECT DATE_FORMAT(created_at, "%Y-%m") m, COUNT(*) calls, COALESCE(SUM(cost_usd),0) cost, COALESCE(SUM(tokens_in + tokens_out),0) tokens, COALESCE(SUM(credits_charged),0) credits
                              FROM ai_runs WHERE created_at >= ? AND sandbox = 0' . ($engineOnly ? ' AND task LIKE "ce\\_%"' : '') . ' GROUP BY m ORDER BY m', [$mFrom]);
    $out['by_stage'] = db_all("SELECT task, $sel FROM ai_runs WHERE $w GROUP BY task ORDER BY cost DESC", $a);
    $out['by_model'] = db_all("SELECT CONCAT(COALESCE(provider,'?'), ' · ', COALESCE(model,'?')) model, $sel FROM ai_runs WHERE $w GROUP BY provider, model ORDER BY cost DESC LIMIT 20", $a);
    $out['by_user'] = db_all("SELECT r.user_id, u.name, u.email, COUNT(*) calls, COALESCE(SUM(r.cost_usd),0) cost, COALESCE(SUM(r.tokens_in + r.tokens_out),0) tokens, COALESCE(SUM(r.credits_charged),0) credits
                              FROM ai_runs r LEFT JOIN users u ON u.id = r.user_id WHERE r.created_at BETWEEN ? AND ? AND r.sandbox = 0" . ($engineOnly ? ' AND r.task LIKE "ce\\_%"' : '') . '
                              AND r.user_id IS NOT NULL GROUP BY r.user_id ORDER BY cost DESC LIMIT 20', $a);
    $out['top_campaigns'] = db_all("SELECT r.campaign_id, c.title, c.status, u.name, u.email, COUNT(*) calls, COALESCE(SUM(r.cost_usd),0) cost, COALESCE(SUM(r.tokens_in + r.tokens_out),0) tokens
                                    FROM ai_runs r JOIN ce_campaigns c ON c.id = r.campaign_id LEFT JOIN users u ON u.id = c.user_id
                                    WHERE r.created_at BETWEEN ? AND ? AND r.sandbox = 0 AND r.campaign_id IS NOT NULL GROUP BY r.campaign_id ORDER BY cost DESC LIMIT 10", $a);
    $out['sandbox'] = db_one('SELECT COUNT(*) calls, COALESCE(SUM(cost_usd),0) cost FROM ai_runs WHERE created_at BETWEEN ? AND ? AND sandbox = 1', $a);
    $out['revise'] = ce_revise_rates($R['from'], $R['to']);
    return $out;
}

/**
 * نسبة «إعادة» لكل مرحلة (مؤشر على برومبت ضعيف):
 * بوابة الخطة (جولات < الحد) · ناقد الجودة (REVISE من كل التقييمات) · الاختيار (خانات احتاجت أفكار تاني) · مراجعة الصورة (تصميمات اتعادت)
 */
function ce_revise_rates(string $from, string $to): array
{
    $pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b, 1) : null;
    $out = [];
    $p = db_one('SELECT COUNT(*) n, SUM(passed = 0) bad FROM ce_plan_evals WHERE created_at BETWEEN ? AND ?', [$from, $to]);
    $out['ce_plan_critic'] = ['label' => 'بوابة الخطة', 'n' => (int) $p['n'], 'revise' => (int) $p['bad'], 'rate' => $pct((int) $p['bad'], (int) $p['n'])];
    $q = db_one('SELECT COUNT(*) n, SUM(verdict <> "PASS") bad FROM ce_content_versions WHERE created_at BETWEEN ? AND ?', [$from, $to]);
    $out['ce_critic'] = ['label' => 'ناقد الجودة (الكتابة)', 'n' => (int) $q['n'], 'revise' => (int) $q['bad'], 'rate' => $pct((int) $q['bad'], (int) $q['n'])];
    $i = db_one('SELECT COUNT(*) n, SUM(r > 1) bad FROM (SELECT slot_id, MAX(round) r FROM ce_ideas WHERE created_at BETWEEN ? AND ? GROUP BY slot_id) x', [$from, $to]);
    $out['ce_selection'] = ['label' => 'الأفكار والاختيار', 'n' => (int) $i['n'], 'revise' => (int) $i['bad'], 'rate' => $pct((int) $i['bad'], (int) $i['n'])];
    $d = db_one('SELECT COUNT(*) n, SUM(attempts > 1) bad FROM ce_designs WHERE kind = "ai" AND created_at BETWEEN ? AND ?', [$from, $to]);
    $out['ce_vision_qa'] = ['label' => 'مراجعة الصورة', 'n' => (int) $d['n'], 'revise' => (int) $d['bad'], 'rate' => $pct((int) $d['bad'], (int) $d['n'])];
    $m = db_one('SELECT COUNT(*) n, SUM(status = "manual") bad FROM ce_contents WHERE created_at BETWEEN ? AND ? AND status IN ("pass", "manual")', [$from, $to]);
    $out['manual'] = ['label' => 'قطع وصلت «محتاجة مراجعتك»', 'n' => (int) $m['n'], 'revise' => (int) $m['bad'], 'rate' => $pct((int) $m['bad'], (int) $m['n'])];
    return $out;
}
