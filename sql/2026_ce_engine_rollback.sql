-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_ce_engine.sql
-- ⚠️ بيمسح حملات المحرك الجديد والـ Brand DNA وطابور الشغل وسجل حجز الكريدت.
--    الكريدت نفسه مش بيتأثر (القيود في credit_transactions بتفضل). خد نسخة احتياطية الأول.
--    لو فيه حجوزات لسه held: رجّعها الأول من «محرك المحتوى ← الشغل» قبل الرجوع.
-- ═══════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS `ce_campaigns`;
DROP TABLE IF EXISTS `ce_brand_dna`;
DROP TABLE IF EXISTS `ce_credit_holds`;
DROP TABLE IF EXISTS `ce_jobs`;

DELETE FROM `user_feature_access` WHERE `feature_key` = 'content_engine';
DELETE FROM `feature_flags` WHERE `feature_key` = 'content_engine';
DELETE FROM `settings` WHERE `setting_key` IN ('ce_margin_pct', 'ce_egp_per_credit', 'ce_min_credits_piece', 'ce_revision_factor',
    'ce_dna_cost', 'ce_dna_max_tokens', 'ce_max_pieces', 'ce_token_defaults', 'ce_worker_last_run');
