-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_campaign_package.sql (بيشيل الإضافات دي بس — الحملات والمحتوى بيفضلوا)
-- ═══════════════════════════════════════════════════════════════════
ALTER TABLE `credit_packages` DROP COLUMN `campaigns_month`, DROP COLUMN `posts_per_campaign`, DROP COLUMN `videos_per_campaign`, DROP COLUMN `free_edits_per_campaign`, DROP COLUMN `carousel_max_slides`;
ALTER TABLE `user_plans` DROP COLUMN `campaigns_month`, DROP COLUMN `posts_per_campaign`, DROP COLUMN `videos_per_campaign`, DROP COLUMN `free_edits_per_campaign`, DROP COLUMN `carousel_max_slides`;
ALTER TABLE `campaigns` DROP KEY `idx_camp_pkg`, DROP COLUMN `user_plan_id`, DROP COLUMN `pkg_state`, DROP COLUMN `counted_at`, DROP COLUMN `posts_target`, DROP COLUMN `videos_target`,
  DROP COLUMN `posts_used`, DROP COLUMN `videos_used`, DROP COLUMN `edits_used`, DROP COLUMN `skill_version_id`, DROP COLUMN `draft_regens`, DROP COLUMN `draft_alts`, DROP COLUMN `draft_plans`;
ALTER TABLE `campaign_ideas` DROP COLUMN `rejected`;
ALTER TABLE `content_designs` DROP COLUMN `campaign_alloc`;
DELETE FROM `usage_events` WHERE `unit` IN ('campaigns', 'camp_post', 'camp_video');
DELETE FROM `settings` WHERE `setting_key` IN ('campaign_pkg_since', 'campaign_pkg_enabled', 'campaign_draft_regens', 'campaign_draft_alts', 'campaign_max_drafts');
