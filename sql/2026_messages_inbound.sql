-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — خريطة التطوير · المرحلة 3: الرسائل الواردة من العملاء (واتساب عن طريق n8n)
--   message_inbound: رد العميل · العميل اللي اتربط بيه (من رقمه المتحقق) · الفعل اللي اتنفّذ · مين رد عليه
--   جدول messages (الطالع) مابيتغيّرش.
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_messages_inbound_rollback.sql
-- ═══════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `message_inbound` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL COMMENT 'NULL = الرقم مش مربوط بعميل',
  `wa_phone` VARCHAR(20) NOT NULL,
  `body` TEXT NOT NULL,
  `provider_id` VARCHAR(100) NULL COMMENT 'رقم الرسالة عند المزود — بيمنع التكرار',
  `action` VARCHAR(12) NOT NULL DEFAULT 'none' COMMENT 'none · approve · stop',
  `action_result` VARCHAR(200) NULL,
  `handled_at` DATETIME NULL,
  `handled_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mi_provider` (`provider_id`),
  KEY `idx_mi_user` (`user_id`, `created_at`),
  KEY `idx_mi_open` (`handled_at`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
