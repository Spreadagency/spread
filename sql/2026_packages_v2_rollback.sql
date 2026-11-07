-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_packages_v2.sql
-- ⚠️ المدد والإضافات بتتمسح. الاشتراكات والكريدت اللي اتضاف بيفضلوا زي ما هما.
-- ═══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS `package_prices`;
DROP TABLE IF EXISTS `addon_packs`;
ALTER TABLE `credit_packages` DROP COLUMN `weekly_credits`, DROP COLUMN `weekly_designs`, DROP COLUMN `quality_tier`, DROP COLUMN `allow_research`;
ALTER TABLE `user_plans` DROP COLUMN `weekly_credits`, DROP COLUMN `weekly_designs`, DROP COLUMN `quality_tier`, DROP COLUMN `allow_research`, DROP COLUMN `price_id`;
ALTER TABLE `payment_requests` DROP COLUMN `price_id`, DROP COLUMN `addon_id`, DROP COLUMN `quotas_json`;
ALTER TABLE `credit_lots` DROP COLUMN `addon_until`;
DELETE FROM `settings` WHERE `setting_key` IN ('signup_package_id', 'signup_package_price_id');
