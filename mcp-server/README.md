# spread-mcp-server

خادم الـ MCP بتاع Spread AI (الجودة والمراقبة والأمان) — Node.js + TypeScript، الـ SDK الرسمي v2، Streamable HTTP.

- بيكلم **الـ Internal API بس** (`/internal-api/v1`) — مابيلمسش MySQL خالص.
- الدخول: **OAuth 2.1** لموصّل Claude (بباسورد صاحب الموقع) + **Bearer ثابت** لـ Claude Code والاختبار.
- مفيش ولا أداة بتحذف، أو بتلمس الكريدت أو الدفع أو الاشتراكات أو الحسابات أو الأفلييت.

| الملف | |
|---|---|
| `../docs/MCP_TOOLS.md` | الأدوات والموارد والبرومبتات والصلاحيات |
| `../docs/DEPLOY.md` | التركيب على الـ VPS (Ubuntu + nginx + HTTPS + systemd) والربط بـ Claude |
| `.env.example` | الإعدادات (انسخه لـ `.env`) |
| `scripts/smoke.ts` | اختبار كامل من بره زي Claude (`npm run smoke`) |

```bash
npm ci && npm run build
cp .env.example .env && nano .env     # chmod 600 .env
npm start                              # http://127.0.0.1:3000/mcp
```
