-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الباقات الجديدة (المرحلة 3)
--   package_prices   مدد وأسعار لكل باقة (شهري · 3 شهور · سنة · أي عدد أيام) — لكل مدة كريدت وتصميمات
--   addon_packs      إضافات: تصميمات زيادة · كريدت مساعد (صالح شهر، بيقف لو الباقة خلصت ويكمل مع الجديدة)
--   credit_packages  + الحد الأسبوعي (كريدت/تصميمات) · مستوى الجودة · البحث مسموح ولا لأ
--   user_plans       + نفس الحاجات (لقطة وقت الاشتراك — تعديل الباقة بعدين مايأثرش على المشترك)
--   payment_requests + المدة أو الإضافة اللي اتطلبت
--   credit_lots      + addon_until (كريدت الإضافة)
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_packages_v2_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `package_prices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id` INT NOT NULL,
  `label` VARCHAR(60) NOT NULL COMMENT 'شهري · 3 شهور · سنة …',
  `days` INT NOT NULL DEFAULT 30,
  `price_egp` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `compare_egp` DECIMAL(10,2) NULL COMMENT 'السعر قبل الخصم (للعرض)',
  `credits` INT NOT NULL DEFAULT 0 COMMENT 'كريدت المدة كلها (بينزل مرة واحدة)',
  `designs` INT NOT NULL DEFAULT 0 COMMENT 'تصميمات المدة كلها (0 = مفتوح)',
  `research` INT NOT NULL DEFAULT 0 COMMENT 'أبحاث المدة كلها (0 = مفتوح لو البحث مسموح)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pp_package` (`package_id`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `addon_packs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` ENUM('designs','credits') NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `amount` INT NOT NULL,
  `price_egp` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `validity_days` INT NOT NULL DEFAULT 30 COMMENT 'الكريدت المساعد: أيام الاستخدام (بتقف لو الباقة خلصت)',
  `description` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- credit_packages
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_packages' AND COLUMN_NAME = 'weekly_credits');
SET @q := IF(@c = 0, 'ALTER TABLE `credit_packages`
  ADD COLUMN `weekly_credits` INT NOT NULL DEFAULT 0 COMMENT ''أقصى كريدت في الأسبوع (تراكمي) · 0 = من غير حد'',
  ADD COLUMN `weekly_designs` INT NOT NULL DEFAULT 0 COMMENT ''أقصى تصميمات في الأسبوع (تراكمي) · 0 = من غير حد'',
  ADD COLUMN `quality_tier` VARCHAR(20) NOT NULL DEFAULT ''standard'',
  ADD COLUMN `allow_research` TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- user_plans
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_plans' AND COLUMN_NAME = 'weekly_credits');
SET @q := IF(@c = 0, 'ALTER TABLE `user_plans`
  ADD COLUMN `weekly_credits` INT NOT NULL DEFAULT 0,
  ADD COLUMN `weekly_designs` INT NOT NULL DEFAULT 0,
  ADD COLUMN `quality_tier` VARCHAR(20) NOT NULL DEFAULT ''standard'',
  ADD COLUMN `allow_research` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN `price_id` INT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- payment_requests
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_requests' AND COLUMN_NAME = 'price_id');
SET @q := IF(@c = 0, 'ALTER TABLE `payment_requests`
  ADD COLUMN `price_id` INT NULL COMMENT ''المدة (package_prices)'',
  ADD COLUMN `addon_id` INT NULL COMMENT ''إضافة (addon_packs) بدل الباقة'',
  ADD COLUMN `quotas_json` TEXT NULL COMMENT ''حصص المدة وقت الطلب''', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- credit_lots
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_lots' AND COLUMN_NAME = 'addon_until');
SET @q := IF(@c = 0, 'ALTER TABLE `credit_lots` ADD COLUMN `addon_until` DATETIME NULL COMMENT ''كريدت إضافة: صالح لحد كده (بيتمد بالأيام اللي كان واقف فيها)''', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- كل باقة موجودة ← مدة «شهري» بنفس سعرها وكريدتها وحصة تصميماتها
INSERT INTO `package_prices` (`package_id`, `label`, `days`, `price_egp`, `credits`, `designs`, `research`, `sort_order`)
SELECT p.id, 'شهري', p.validity_days, p.price_egp, p.credits,
       CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.quotas_json, '$.designs')), '0') AS UNSIGNED),
       CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.quotas_json, '$.research')), '0') AS UNSIGNED), 0
FROM `credit_packages` p WHERE NOT EXISTS (SELECT 1 FROM `package_prices` x WHERE x.package_id = p.id);

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('signup_package_id', ''),        -- الباقة المجانية عند التسجيل (فاضي = الكريدت الترحيبي القديم)
('signup_package_price_id', '');
