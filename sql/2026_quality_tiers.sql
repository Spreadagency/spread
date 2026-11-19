-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — مستويات الجودة (المرحلة 5)
--   quality_tiers   كل مستوى: الاسم · الوصف · معامل الكريدت · جولات التحسين وحد الجودة · إعادات الصورة · الأولوية
--   ai_tier_routes  موديل لكل مهمة في كل مستوى (فاضي = زي الأساسي) — والسلسلة الأساسية بتفضل بديل لو فشل
--   ce_jobs.priority المستوى الأعلى بيتنفذ الأول في طابور المحرك
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_quality_tiers_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `quality_tiers` (
  `tkey` VARCHAR(20) NOT NULL,
  `label` VARCHAR(60) NOT NULL,
  `description` VARCHAR(255) NULL COMMENT 'اللي العميل بيشوفه: أسرع · أدق · تفاصيل أكتر',
  `credit_mult` DECIMAL(4,2) NOT NULL DEFAULT 1.00 COMMENT 'معامل سعر الكتابة',
  `qa_rounds` TINYINT NULL COMMENT 'جولات تحسين الكتابة (فاضي = إعداد المحرك)',
  `qa_pass` TINYINT NULL COMMENT 'حد الجودة للقطعة (فاضي = إعداد المحرك)',
  `vision_regen` TINYINT NULL COMMENT 'إعادات الصورة لو ماعدّتش المراجعة (فاضي = إعداد المحرك)',
  `priority` TINYINT NOT NULL DEFAULT 0 COMMENT 'الأعلى بيتنفذ الأول',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `quality_tiers` (`tkey`, `label`, `description`, `credit_mult`, `qa_rounds`, `qa_pass`, `vision_regen`, `priority`, `sort_order`) VALUES
('standard', 'عادي', 'موديلات سريعة وجودة كويسة', 1.00, NULL, NULL, NULL, 0, 1),
('pro', 'احترافي', 'موديلات أقوى وتحسين أكتر', 1.50, 2, 88, 2, 5, 2),
('premium', 'بريميوم', 'أقوى الموديلات وأدق مراجعة وأولوية في التنفيذ', 2.50, 3, 90, 3, 10, 3);

CREATE TABLE IF NOT EXISTS `ai_tier_routes` (
  `tier` VARCHAR(20) NOT NULL,
  `task` VARCHAR(40) NOT NULL,
  `model_id` INT NULL COMMENT 'ai_model_registry.id — فاضي = زي الأساسي',
  `temperature` DECIMAL(3,2) NULL,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tier`, `task`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ce_jobs' AND COLUMN_NAME = 'priority');
SET @q := IF(@c = 0, 'ALTER TABLE `ce_jobs` ADD COLUMN `priority` TINYINT NOT NULL DEFAULT 0, ADD KEY `idx_ce_jobs_prio` (`status`, `priority`, `id`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
