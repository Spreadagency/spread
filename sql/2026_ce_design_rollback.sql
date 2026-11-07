-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_ce_design.sql
-- ⚠️ بيمسح التصميمات وقوالب النص. ملفات الصور نفسها بتفضل في storage/uploads/ce-designs (امسحها بإيدك لو عايز).
-- ═══════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS `ce_designs`;
DROP TABLE IF EXISTS `ce_text_templates`;

DELETE FROM `ai_task_routes` WHERE `task` = 'ce_image';

DELETE FROM `settings` WHERE `setting_key` IN ('ce_vision_max_regen', 'ce_vision_min_idea', 'ce_vision_min_palette',
    'ce_carousel_ai_images', 'ce_reel_cover', 'ce_min_credits_image');
