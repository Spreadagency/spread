-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — خريطة التطوير · المرحلة 2: أساس إدارة الحسابات
--   managed_accounts: الحساب المُدار — مسؤول واحد · مستوى الخدمة · المطلوب في الشهر
--   managed_notes: ملاحظات ومهام الفريق على الحساب
--   contents: مين من الفريق عمل المنشور (acted_by_admin_id) + حالة موافقة العميل (approval_status)
--     approval_status: NULL = بره مسار الموافقة (كل المحتوى القديم) · draft · pending · approved · changes
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_managed_accounts_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `managed_accounts` (
  `user_id` INT NOT NULL,
  `manager_admin_id` INT NULL COMMENT 'مسؤول الحساب (admin_users.id)',
  `service_level` VARCHAR(10) NOT NULL DEFAULT 'approval' COMMENT 'approval = العميل يوافق قبل النشر · auto = الفريق ينشر مباشرة',
  `monthly_posts` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'عدد البوستات المتفق عليه في الشهر (0 = مش محدد)',
  `started_at` DATE NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_ma_manager` (`manager_admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `managed_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `admin_id` INT NULL,
  `kind` VARCHAR(8) NOT NULL DEFAULT 'note' COMMENT 'note · task',
  `body` VARCHAR(1000) NOT NULL,
  `due_date` DATE NULL,
  `done_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mn_user` (`user_id`, `kind`, `done_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contents' AND COLUMN_NAME = 'approval_status');
SET @q := IF(@c = 0, 'ALTER TABLE `contents`
  ADD COLUMN `acted_by_admin_id` INT NULL COMMENT ''الفريق عمله باسم العميل (admin_users.id)'',
  ADD COLUMN `approval_status` VARCHAR(10) NULL COMMENT ''NULL = بره مسار الموافقة · draft · pending · approved · changes'',
  ADD COLUMN `approval_note` VARCHAR(500) NULL COMMENT ''تعديل طلبه العميل'',
  ADD COLUMN `approval_at` DATETIME NULL,
  ADD KEY `idx_contents_approval` (`user_id`, `approval_status`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
