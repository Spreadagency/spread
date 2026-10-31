-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الفيديو: الأنواع والأسعار (بالفلوس) · أمثلة · خصم لكل باقة · طلبات الفيديو المدفوعة
--   video_types: نوع الفيديو + المدد بأسعارها (durations_json) + لينكات الأمثلة (samples_json) — من الأدمن
--   video_orders: العميل اختار النوع والمدة ← السعر بعد خصم باقته ← طلب دفع ← بعد الاعتماد بيروح لفريق التنفيذ
--   credit_packages.video_discount_pct + لقطته في user_plans (الخصم وقت الاشتراك)
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_video_pricing_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `video_types` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NULL,
  `durations_json` TEXT NULL COMMENT '[{label, seconds, price_egp, days}] — المدد وأسعارها ومدة التنفيذ',
  `samples_json` TEXT NULL COMMENT '[{title, url}] — «شوف فيديوهات زيه»',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vt_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `video_orders` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `content_id` INT NOT NULL,
  `type_id` INT NULL,
  `type_name` VARCHAR(120) NOT NULL,
  `duration_label` VARCHAR(60) NOT NULL,
  `seconds` INT NOT NULL DEFAULT 0,
  `delivery_days` INT NOT NULL DEFAULT 0,
  `base_egp` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `final_egp` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending_payment' COMMENT 'pending_payment · paid · cancelled',
  `payment_request_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vo_user` (`user_id`, `status`),
  KEY `idx_vo_content` (`content_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_packages' AND COLUMN_NAME = 'video_discount_pct');
SET @q := IF(@c = 0, 'ALTER TABLE `credit_packages` ADD COLUMN `video_discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_plans' AND COLUMN_NAME = 'video_discount_pct');
SET @q := IF(@c = 0, 'ALTER TABLE `user_plans` ADD COLUMN `video_discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_requests' AND COLUMN_NAME = 'video_order_id');
SET @q := IF(@c = 0, 'ALTER TABLE `payment_requests` ADD COLUMN `video_order_id` INT NULL, ADD KEY `idx_pr_video` (`video_order_id`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- الاشتراكات الشغالة دلوقتي بتاخد خصم باقتها الحالي (لو الأدمن حطه بعدين بيتطبق على الاشتراكات الجديدة بس)
UPDATE `user_plans` up JOIN `credit_packages` p ON p.id = up.package_id
   SET up.video_discount_pct = p.video_discount_pct
 WHERE up.status = 'active' AND up.video_discount_pct = 0 AND p.video_discount_pct > 0;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('video_orders_enabled', '1');   -- طلب الفيديو بالدفع (لو مفيش أنواع متفعّلة بيرجع لواتساب زي الأول)
