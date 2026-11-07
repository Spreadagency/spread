-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_video_pricing.sql
-- ⚠️ طلبات الدفع اللي اتعملت للفيديو بتفضل في payment_requests (من غير ربط بالطلب)
-- ═══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS `video_orders`;
DROP TABLE IF EXISTS `video_types`;
ALTER TABLE `credit_packages` DROP COLUMN `video_discount_pct`;
ALTER TABLE `user_plans` DROP COLUMN `video_discount_pct`;
ALTER TABLE `payment_requests` DROP INDEX `idx_pr_video`, DROP COLUMN `video_order_id`;
DELETE FROM `settings` WHERE `setting_key` = 'video_orders_enabled';
