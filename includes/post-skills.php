<?php
/**
 * Spread AI — الـ Skills في المنشور السريع والاستوديو (خطة الحملات §7 — «بعد الإصدار الأول»)
 *
 * نفس اختيار الحملات: اختيار العميل في ملف البراند ← التخصص من المجال ← «تسويق عام» (general) · «none» = من غير Skill
 * الأقسام لكل مرحلة من CE_SKILL_STAGE_MAP (نفس أقسام مراحل المحرك المقابلة — اختبار الرجوع بيغطيها):
 *   quick_post   = الكتابة (زي ce_writer)          ← build_final_prompt (إنشاء منشور · البث · إعادة التوليد)
 *   studio_ideas = الأفكار (زي ce_ideation)        ← فكرة التصميم · أفكار الترند · البريف في الاستوديو
 *   التصميم     = «التصميم» و«ممنوع في الصورة»    ← تصميم المنشور (design_generate) وتصميم الاستوديو
 * مفتاح الطوارئ: post_skills_enabled = 0 (الحملات ليها مفتاحها campaign_skills_enabled)
 * لو مفيش Skill شغالة البرومبتات بتطلع زي ما كانت بالحرف.
 */
require_once __DIR__ . '/ce-skills.php';

function post_skills_on(): bool
{
    return get_setting('post_skills_enabled', '1') === '1' && ce_skills_ready();
}

/** الـ Skill بتاعة البراند (null = من غير) */
function post_skill(array $brand): ?array
{
    static $memo = [];
    if (!post_skills_on()) return null;
    $uid = (int) ($brand['user_id'] ?? 0);
    $key = $uid . '|' . ($brand['industry'] ?? '');
    if (array_key_exists($key, $memo)) return $memo[$key];
    $dna = [];
    if ($uid > 0) {
        try {
            if (!function_exists('ce_dna_get')) require_once __DIR__ . '/ce-dna.php';
            $dna = ce_dna_get($uid) ?? [];
        } catch (\Throwable $e) { $dna = []; }
    }
    if (trim((string) ($dna['brand']['sector'] ?? '')) === '') $dna['brand']['sector'] = (string) ($brand['industry'] ?? '');
    return $memo[$key] = ce_skill_for_dna($dna);
}

/** بلوك الـ Skill في أول البرومبت (فاضي لو مفيش) */
function post_skill_prefix(string $stage, array $brand): string
{
    $sk = post_skill($brand);
    if (!$sk) return '';
    $b = ce_skill_block($stage, $sk);
    return $b === '' ? '' : ltrim($b) . "\n\n";
}

/** قواعد الصورة من الـ Skill لبرومبت الصورة (بالإنجليزي غالبًا) — فاضي لو مفيش */
function post_skill_visual(array $brand): string
{
    $sk = post_skill($brand);
    if (!$sk) return '';
    $vis = trim((string) ($sk['sections']['visual']['text'] ?? ''));
    $avoid = ce_skill_items($sk, 'visual_avoid');
    $out = '';
    if ($vis !== '') $out .= 'SECTOR DESIGN RULES (' . ($sk['name'] ?? '') . "):\n" . mb_substr($vis, 0, 1200) . "\n";
    if ($avoid) $out .= 'Avoid: ' . implode('; ', array_slice($avoid, 0, 12)) . "\n";
    return $out;
}
