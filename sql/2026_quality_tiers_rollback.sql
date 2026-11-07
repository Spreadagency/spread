-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_quality_tiers.sql (الكل بيرجع للموديلات الأساسية وسعر واحد)
-- ═══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS `ai_tier_routes`;
DROP TABLE IF EXISTS `quality_tiers`;
ALTER TABLE `ce_jobs` DROP KEY `idx_ce_jobs_prio`, DROP COLUMN `priority`;
