-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_assistants.sql (الحملات اللي الوكيل عملها بتفضل في المحرك)
-- ═══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS `agent_runs`;
DROP TABLE IF EXISTS `assistant_threads`;
ALTER TABLE `credit_packages` DROP COLUMN `assistant_support`, DROP COLUMN `assistant_agent`;
ALTER TABLE `user_plans` DROP COLUMN `assistant_support`, DROP COLUMN `assistant_agent`;
DELETE FROM `ai_task_routes` WHERE `task` IN ('assistant_support', 'assistant_agent');
DELETE FROM `settings` WHERE `setting_key` IN ('assistant_support_enabled', 'assistant_agent_enabled', 'assistant_support_cost', 'assistant_agent_msg_cost', 'assistant_agent_fee', 'assistant_agent_daily', 'assistant_support_kb');
