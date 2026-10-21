-- ═══════════════════════════════════════════════════════════════════
-- مستخدمين MySQL التلاتة للـ MCP — لسيرفر عندك فيه صلاحية root (VPS)
-- على cPanel: استخدم «MySQL Databases» بدل الملف ده (الخطوات في docs/INTERNAL_API.md)
-- غيّر: spread_app (قاعدة المنصة) · spread_ai (قاعدة الـ AI) · الباسوردات
-- ═══════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `spread_ai` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- read: قراءة بس (المنصة + الـ AI)
CREATE USER IF NOT EXISTS 'mcp_read'@'localhost' IDENTIFIED BY 'CHANGE_ME_READ';
GRANT SELECT ON `spread_app`.* TO 'mcp_read'@'localhost';
GRANT SELECT ON `spread_ai`.* TO 'mcp_read'@'localhost';

-- ai_write: كتابة وتعديل في قاعدة الـ AI بس — من غير DELETE · ومفيش أي صلاحية على المنصة
CREATE USER IF NOT EXISTS 'mcp_ai'@'localhost' IDENTIFIED BY 'CHANGE_ME_AI';
GRANT SELECT, INSERT, UPDATE ON `spread_ai`.* TO 'mcp_ai'@'localhost';

-- draft: إضافة بس في قاعدة الـ AI (مسودات واقتراحات) — من غير تعديل ولا حذف
CREATE USER IF NOT EXISTS 'mcp_draft'@'localhost' IDENTIFIED BY 'CHANGE_ME_DRAFT';
GRANT SELECT, INSERT ON `spread_ai`.* TO 'mcp_draft'@'localhost';

-- مستخدم المنصة الحالي: كامل على قاعدة الـ AI (الأدمن بيعرض ويوافق ويطبّق)
-- GRANT ALL PRIVILEGES ON `spread_ai`.* TO 'APP_DB_USER'@'localhost';

FLUSH PRIVILEGES;
