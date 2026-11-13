-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_managed_accounts.sql (بيشيل الإضافات دي بس — العملاء والمحتوى بيفضلوا)
--   علامة ⭐ القديمة (الإعداد managed_client_ids) مابتتمسحش، فبترجع تشتغل منها.
-- ═══════════════════════════════════════════════════════════════════
ALTER TABLE `contents` DROP KEY `idx_contents_approval`, DROP COLUMN `acted_by_admin_id`, DROP COLUMN `approval_status`, DROP COLUMN `approval_note`, DROP COLUMN `approval_at`;
DROP TABLE IF EXISTS `managed_notes`;
DROP TABLE IF EXISTS `managed_accounts`;
DELETE FROM `settings` WHERE `setting_key` = 'managed_accounts_imported';
