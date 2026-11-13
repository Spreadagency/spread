-- ═══════════════════════════════════════════════════════════════════
-- Spread AI — الرجوع عن 2026_messages_inbound.sql (الرسائل الواردة بس — الطالع والقوالب بيفضلوا)
-- ═══════════════════════════════════════════════════════════════════
DROP TABLE IF EXISTS `message_inbound`;
DELETE FROM `settings` WHERE `setting_key` = 'wa_inbound_enabled';
