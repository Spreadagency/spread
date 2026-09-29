-- ═══════════════════════════════════════════════════════════
-- Spread AI v2 — المرحلة ⑤-ب: نسخ التصميم في الاستوديو (التعديل بالكلام)
--   parent_id = التصميم الأصلي (كل النسخ بتشاور عليه) — V1 · V2 · V3…
-- آمن للتشغيل المتكرر
-- ═══════════════════════════════════════════════════════════
SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'studio_designs' AND column_name = 'parent_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `studio_designs` ADD COLUMN `parent_id` INT NULL, ADD KEY `idx_studio_parent` (`parent_id`)', 'SELECT 1');
PREPARE p1 FROM @sql; EXECUTE p1; DEALLOCATE PREPARE p1;
