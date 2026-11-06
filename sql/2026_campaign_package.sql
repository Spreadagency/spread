-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — خطة الحملات · المرحلة 4: الحملة وحدة في الباقة (docs/PLAN_CAMPAIGNS_V2.md §2 · §9)
--   credit_packages: عدد الحملات في الشهر وحدود الحملة الواحدة (0 = الحملات بالكريدت زي دلوقتي — مفيش باقة بتتغيّر)
--   user_plans: لقطة من نفس الحقول وقت الاشتراك
--   campaigns: حالة الباقة (pkg_state) + وقت الخصم + العدّادات + الـ Skill + حدود المسودة
--   campaign_ideas.rejected · content_designs.campaign_alloc
-- آمن للتشغيل أكتر من مرة · الرجوع: 2026_campaign_package_rollback.sql
-- ═══════════════════════════════════════════════════════════════════

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_packages' AND COLUMN_NAME = 'campaigns_month');
SET @q := IF(@c = 0, 'ALTER TABLE `credit_packages`
  ADD COLUMN `campaigns_month` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''حملات في الشهر · 0 = بالكريدت زي دلوقتي'',
  ADD COLUMN `posts_per_campaign` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `videos_per_campaign` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `free_edits_per_campaign` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `carousel_max_slides` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''0 = حد الكاروسيل العام''', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_plans' AND COLUMN_NAME = 'campaigns_month');
SET @q := IF(@c = 0, 'ALTER TABLE `user_plans`
  ADD COLUMN `campaigns_month` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `posts_per_campaign` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `videos_per_campaign` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `free_edits_per_campaign` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `carousel_max_slides` TINYINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'pkg_state');
SET @q := IF(@c = 0, 'ALTER TABLE `campaigns`
  ADD COLUMN `user_plan_id` INT NULL COMMENT ''الدورة اللي الحملة اتحسبت منها'',
  ADD COLUMN `pkg_state` VARCHAR(10) NULL COMMENT ''NULL = بالكريدت (القديم) · draft · approved · done · cancelled'',
  ADD COLUMN `counted_at` DATETIME NULL COMMENT ''إمتى اتخصمت من الباقة («اعتمد الخطة»)'',
  ADD COLUMN `posts_target` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''اللي العميل اختاره (لحد حد الباقة)'',
  ADD COLUMN `videos_target` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `posts_used` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `videos_used` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `edits_used` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `skill_version_id` INT NULL COMMENT ''نسخة الـ Skill اللي الحملة بدأت بيها (0 = من غير)'',
  ADD COLUMN `draft_regens` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `draft_alts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN `draft_plans` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''عدد مرات توليد الخطة في المسودة'',
  ADD KEY `idx_camp_pkg` (`user_id`, `pkg_state`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaign_ideas' AND COLUMN_NAME = 'rejected');
SET @q := IF(@c = 0, 'ALTER TABLE `campaign_ideas` ADD COLUMN `rejected` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_designs' AND COLUMN_NAME = 'campaign_alloc');
SET @q := IF(@c = 0, 'ALTER TABLE `content_designs` ADD COLUMN `campaign_alloc` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''من حصة الحملة — عدّاد التصميمات العادي بيستثنيه''', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- الحملات اللي اتعملت قبل كده بتكمّل بالكريدت: بس الحملات اللي بتبدأ بعد اللحظة دي بتدخل الباقة
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('campaign_pkg_since', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')),
('campaign_pkg_enabled', '1'),          -- مفتاح طوارئ: 0 = كل الحملات بالكريدت زي الأول
('campaign_draft_regens', '2'),         -- إعادات الخطة ببلاش في المسودة
('campaign_draft_alts', '5'),           -- أفكار بديلة ببلاش في المسودة
('campaign_max_drafts', '2');           -- أقصى حملات مسودة في نفس الوقت
