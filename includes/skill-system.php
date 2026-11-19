<?php
/**
 * Spread AI — نظام تشغيل الـ Skills (خريطة التطوير · المرحلة 4) — فوق includes/ce-skills.php
 *
 *   1) الأقسام الأساسية الـ 6 لازم تبقى مليانة قبل حفظ نسخة جديدة (skill_require_sections — شغال افتراضيًا)
 *   2) تغطية القطاعات: كام براند على كل Skill، والقطاعات اللي مالهاش Skill (بتشتغل على «العام»)
 *   3) ملاحظات العميل: طبقة صغيرة فوق Skill القطاع للحسابات المُدارة (managed_notes · kind = skill)
 *   4) اقتراح الشهر: أعلى وأقل منشورات القطاع أداءً ← مسودة نسخة جديدة (مابتتفعّلش لوحدها — لازم اختبار رجوع)
 * التفعيل لسه من ce_skill_activate() اللي بيرفض أي نسخة ماعدّتش اختبار الرجوع.
 */
require_once __DIR__ . '/ce-skills.php';

/** الأقسام الأساسية: المفتاح ← السؤال اللي القسم بيجاوب عليه */
const SKILL_REQUIRED = [
    'knowledge' => 'الجمهور ولغته (## المعرفة)',
    'angles' => 'زوايا بتنجح (## الزوايا)',
    'forbidden' => 'ممنوعات وادعاءات (## الممنوعات)',
    'rules' => 'أسلوب الكتابة (## القواعد)',
    'visual' => 'التصميم (## التصميم)',
    'hooks' => 'أمثلة ناجحة (## أمثلة Hooks)',
];
const SKILL_CLIENT_NOTES_MAX = 600;

/** الأقسام الأساسية الناقصة في ملف (أسماءها) — فاضي = تمام */
function skill_missing_sections(array $sections): array
{
    $miss = [];
    foreach (SKILL_REQUIRED as $k => $label) {
        if (trim((string) ($sections[$k]['text'] ?? '')) === '') $miss[] = $label;
    }
    return $miss;
}

function skill_require_on(): bool
{
    return !function_exists('get_setting') || get_setting('skill_require_sections', '1') === '1';
}

/* ═══════════════ ملاحظات العميل (طبقة فوق Skill القطاع) ═══════════════ */

function skill_client_notes(int $uid): string
{
    static $memo = [];
    if (isset($memo[$uid])) return $memo[$uid];
    if ($uid <= 0) return '';
    try {
        if (!function_exists('ma_ready')) require_once __DIR__ . '/managed-accounts.php';
        if (!ma_ready() || !ma_get($uid)) return $memo[$uid] = '';
        $r = db_one('SELECT body FROM managed_notes WHERE user_id = ? AND kind = "skill" ORDER BY id DESC LIMIT 1', [$uid]);
        return $memo[$uid] = trim((string) ($r['body'] ?? ''));
    } catch (\Throwable $e) {
        return $memo[$uid] = '';
    }
}

/** حفظ ملاحظات العميل (فاضي = مسح) — للحسابات المُدارة بس */
function skill_client_notes_set(int $uid, string $text, int $adminId): bool
{
    if (!function_exists('ma_ready')) require_once __DIR__ . '/managed-accounts.php';
    if (!ma_ready() || !ma_get($uid)) return false;
    $text = mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text)), 0, SKILL_CLIENT_NOTES_MAX);
    db_run('DELETE FROM managed_notes WHERE user_id = ? AND kind = "skill"', [$uid]);
    if ($text !== '') db_insert('INSERT INTO managed_notes (user_id, admin_id, kind, body) VALUES (?,?, "skill", ?)', [$uid, $adminId, $text]);
    return true;
}

/** الـ Skill + ملاحظات العميل كقسم «client» (بيتحقن بعد أقسام القطاع). من غير ملاحظات ← نفس الـ Skill بالحرف */
function skill_with_client_layer(?array $skill, int $uid): ?array
{
    if (!$skill || $uid <= 0) return $skill;
    $notes = skill_client_notes($uid);
    if ($notes === '') return $skill;
    $skill['sections']['client'] = ['title' => 'ملاحظات خاصة بالعميل ده', 'text' => $notes, 'items' => []];
    return $skill;
}

/* ═══════════════ تغطية القطاعات ═══════════════ */

/**
 * @return array{by_skill: array<string,int>, gaps: array<int,array{industry:string, brands:int}>, total:int}
 *   by_skill: مفتاح الـ Skill ← عدد البراندات (حسب «المجال») · gaps: مجالات مالهاش Skill متخصصة (الأكتر براندات الأول)
 */
function skill_coverage(int $limit = 400): array
{
    $out = ['by_skill' => [], 'gaps' => [], 'total' => 0];
    try {
        $rows = db_all('SELECT TRIM(industry) industry, COUNT(*) n FROM brand_profiles WHERE industry IS NOT NULL AND TRIM(industry) <> "" GROUP BY TRIM(industry) ORDER BY n DESC LIMIT ' . (int) $limit);
    } catch (\Throwable $e) {
        return $out;
    }
    $general = isset(ce_skill_active_list()[CE_SKILL_GENERAL]);
    foreach ($rows as $r) {
        $n = (int) $r['n'];
        $out['total'] += $n;
        $sk = ce_skill_detect((string) $r['industry']);
        if ($sk) {
            $out['by_skill'][$sk['skey']] = ($out['by_skill'][$sk['skey']] ?? 0) + $n;
        } else {
            if ($general) $out['by_skill'][CE_SKILL_GENERAL] = ($out['by_skill'][CE_SKILL_GENERAL] ?? 0) + $n;
            $out['gaps'][] = ['industry' => mb_substr((string) $r['industry'], 0, 80), 'brands' => $n];
        }
    }
    $out['gaps'] = array_slice($out['gaps'], 0, 30);
    return $out;
}

/* ═══════════════ اقتراح الشهر من الأداء ═══════════════ */

/**
 * أعلى وأقل منشورات القطاع أداءً في آخر N يوم (من ce_perf_posts) للبراندات اللي على الـ Skill دي.
 * @return array{top: array, low: array, posts:int}
 */
function skill_month_suggestion(array $skill, int $days = 30, int $n = 3): array
{
    $out = ['top' => [], 'low' => [], 'posts' => 0];
    try {
        $rows = db_all('SELECT p.content_id, p.user_id, p.platform, p.reach, p.likes, p.comments, p.shares, p.saves, c.body_json, c.format, b.industry
                        FROM ce_perf_posts p JOIN ce_contents c ON c.id = p.content_id
                        LEFT JOIN brand_profiles b ON b.user_id = p.user_id
                        WHERE p.published_at >= (NOW() - INTERVAL ? DAY) AND p.reach > 0 ORDER BY p.id DESC LIMIT 1500', [$days]);
    } catch (\Throwable $e) {
        return $out;
    }
    $items = [];
    foreach ($rows as $r) {
        $sk = ce_skill_detect((string) ($r['industry'] ?? ''));
        $key = $sk ? $sk['skey'] : CE_SKILL_GENERAL;
        if ($key !== $skill['skey']) continue;
        $body = json_decode((string) $r['body_json'], true) ?: [];
        $text = trim((string) ($body['hook'] ?? '') . "\n" . (string) ($body['body'] ?? $body['caption'] ?? $body['text'] ?? ''));
        if ($text === '') continue;
        $eng = (int) $r['likes'] + (int) $r['comments'] * 2 + (int) $r['shares'] * 3 + (int) $r['saves'] * 3;
        $cid = (int) $r['content_id'];
        // نفس القطعة على منصتين ← بنجمعهم
        if (isset($items[$cid])) { $items[$cid]['reach'] += (int) $r['reach']; $items[$cid]['eng'] += $eng; continue; }
        $items[$cid] = ['content_id' => $cid, 'text' => mb_substr($text, 0, 500), 'format' => (string) $r['format'], 'reach' => (int) $r['reach'], 'eng' => $eng];
    }
    foreach ($items as &$i) $i['rate'] = $i['reach'] > 0 ? round($i['eng'] * 100 / $i['reach'], 2) : 0.0;
    unset($i);
    $out['posts'] = count($items);
    if (count($items) < $n * 2) return $out;   // بيانات قليلة ← مفيش اقتراح
    usort($items, fn($a, $b) => $b['rate'] <=> $a['rate']);
    $out['top'] = array_slice($items, 0, $n);
    $out['low'] = array_slice(array_reverse($items), 0, $n);
    return $out;
}

/**
 * مسودة نسخة جديدة فيها أعلى منشورات الشهر كأمثلة (والأقل كملاحظة للناقد).
 * مسودة بس: التفعيل لسه محتاج اختبار رجوع ناجح.
 */
function skill_suggestion_draft(int $skillId, ?int $adminId): array
{
    $s = db_one('SELECT * FROM ce_skills WHERE id = ?', [$skillId]);
    $v = $s ? (db_one('SELECT * FROM ce_skill_versions WHERE skill_id = ? AND is_active = 1', [$skillId]) ?: db_one('SELECT * FROM ce_skill_versions WHERE skill_id = ? ORDER BY version DESC LIMIT 1', [$skillId])) : null;
    if (!$s || !$v) return ['ok' => false, 'error' => 'الـ Skill مالهاش نسخة'];
    $sug = skill_month_suggestion(['skey' => $s['skey']]);
    if (!$sug['top']) return ['ok' => false, 'error' => 'بيانات الأداء قليلة الشهر ده — مفيش اقتراح'];
    $clean = fn(string $t) => trim(preg_replace('/^#+\s*/m', '', str_replace(["\r", '---'], ['', '—'], $t)));
    $month = date('Y-m');
    $add = "\n\n## أمثلة بوستات\n<!-- اقتراح $month: أعلى منشورات القطاع تفاعلًا -->\n";
    foreach ($sug['top'] as $i => $p) $add .= "\n### مثال ناجح " . ($i + 1) . ' (تفاعل ' . $p['rate'] . "%)\n" . $clean($p['text']) . "\n";
    $add .= "\n## الناقد\n- ابعد عن الأسلوب ده (أقل منشورات $month تفاعلًا):\n";
    foreach ($sug['low'] as $p) $add .= '  - «' . mb_substr(str_replace("\n", ' ', $clean($p['text'])), 0, 140) . "»\n";
    $r = ce_skill_save_md(rtrim((string) $v['body_md']) . $add, $skillId, 'اقتراح ' . $month . ' من أداء المنشورات (مسودة)', 'edit', $adminId);
    return $r;
}
