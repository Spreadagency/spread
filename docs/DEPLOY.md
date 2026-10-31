# تركيب خادم الـ MCP على الـ VPS والربط بـ Claude (المرحلة 4)

الافتراضات: **Ubuntu 22.04 أو 24.04** على الـ VPS، والدومين **`mcp.ai.spreadagency.net`**. لو حاجة منهم مختلفة، غيّرها في الخطوات.

الوقت المتوقع: حوالي 30 دقيقة.

---

## 0) قبل ما تبدأ
- [ ] المرحلة 3 شغالة على الموقع: الأدمن ← AI CENTER ← «إعداد الـ MCP» كله ✅.
- [ ] عندك SSH على الـ VPS بمستخدم عنده `sudo`.
- [ ] تعرف **IP الـ VPS**.

## 1) الدومين
من مكان إدارة الـ DNS (cPanel ← **Zone Editor**، أو عند مزوّد الدومين): ضيف سجل
`A` ← `mcp.ai` ← IP الـ VPS.

اتأكد إنه اشتغل (ممكن ياخد من دقايق لساعة):
```bash
dig +short mcp.ai.spreadagency.net     # لازم يطلع IP الـ VPS
```

## 2) تجهيز الـ VPS
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx certbot python3-certbot-nginx ufw unzip
# Node.js 22 LTS
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v    # v22.x

# الجدار الناري: SSH و HTTP/HTTPS بس (بورت 3000 مش مفتوح لبره)
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable

# مستخدم مخصوص للخادم (من غير دخول)
sudo adduser --system --group --home /opt/spread-mcp spreadmcp
```

## 3) الكود
ارفع `spread-mcp-server.zip` على الـ VPS (مثلًا بـ `scp`)، وبعدين:
```bash
sudo mkdir -p /opt/spread-mcp/app
sudo unzip -o spread-mcp-server.zip -d /opt/spread-mcp/app
cd /opt/spread-mcp/app
sudo mkdir -p data
sudo chown -R spreadmcp:spreadmcp /opt/spread-mcp
sudo -u spreadmcp npm ci
sudo -u spreadmcp npm run build
```

## 4) الإعدادات (`.env`)
```bash
sudo -u spreadmcp cp .env.example .env
sudo chmod 600 .env

# باسورد صاحب الموقع (بتكتبه وقت الموافقة على ربط Claude) ← بيطلّع سطر MCP_OWNER_PASSWORD_HASH=…
sudo -u spreadmcp npm run hash-password --silent

# سر الـ JWT + توكن ثابت لـ Claude Code والاختبار
openssl rand -hex 32     # ← MCP_JWT_SECRET
openssl rand -hex 32     # ← MCP_STATIC_TOKENS

sudo -u spreadmcp nano .env
```

| المتغير | القيمة |
|---|---|
| `MCP_PUBLIC_URL` | `https://mcp.ai.spreadagency.net` |
| `INTERNAL_API_URL` | `https://ai.spreadagency.net/internal-api/v1` |
| `INTERNAL_API_TOKEN` | **نفس** `INTERNAL_API_TOKEN` اللي في `includes/config.php` على الموقع |
| `MCP_OWNER_PASSWORD_HASH` | السطر اللي طلع من `hash-password` |
| `MCP_JWT_SECRET` | أول `openssl rand` |
| `MCP_STATIC_TOKENS` | تاني `openssl rand` (احفظه في مكان آمن) |
| `MCP_ENABLE_DRAFT_TOOLS` | `false` لحد المرحلة 6 |

⚠️ الملف ده فيه أسرار:
- ماتبعتوش لحد.
- ماترفعوش على GitHub. `mcp-server/.gitignore` بيمنعه أصلًا.

## 5) الموقع يسمح للـ VPS
على الموقع (cPanel ← File Manager ← `includes/config.php`):
```php
define('INTERNAL_API_IPS', 'IP_الـVPS');
```

اختبار من الـ VPS (لازم يرجع `"ok":true`):
```bash
TOKEN=$(sudo grep ^INTERNAL_API_TOKEN= /opt/spread-mcp/app/.env | cut -d= -f2)
curl -s -H "Authorization: Bearer $TOKEN" -H "X-Scope: read" -H "X-Client: setup" \
  "https://ai.spreadagency.net/internal-api/v1/index.php?r=health"
```

| الرد | الحل |
|---|---|
| `ip_denied` | الـ IP في `INTERNAL_API_IPS` غلط. شوف IP الـ VPS الحقيقي بـ `curl -s ifconfig.me` |
| `auth` | التوكنين مش زي بعض |

## 6) التشغيل (systemd)
```bash
sudo cp deploy/spread-mcp.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now spread-mcp
sudo systemctl status spread-mcp      # لازم: active (running)
curl -s http://127.0.0.1:3000/healthz # {"ok":true,...}
```

## 7) nginx + HTTPS
```bash
sudo cp deploy/nginx-mcp.conf /etc/nginx/sites-available/spread-mcp
sudo ln -s /etc/nginx/sites-available/spread-mcp /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d mcp.ai.spreadagency.net --redirect -m <إيميلك> --agree-tos -n
curl -s https://mcp.ai.spreadagency.net/healthz
```
`certbot` بيجدد الشهادة لوحده.

## 8) الاختبار الكامل (من الـ VPS)
```bash
cd /opt/spread-mcp/app
sudo -u spreadmcp env MCP_URL=https://mcp.ai.spreadagency.net \
  MCP_TOKEN=<التوكن الثابت> OWNER_PASSWORD='<الباسورد>' npm run smoke
```
- لازم يطلع **0 فشل**.
- ضيف `SMOKE_WRITE=1` لو عايز تجرّب أدوات الكتابة كمان. هتكتب تقييم وتقرير تجريبي بتاريخ 2000-01-01 في قاعدة الـ AI.

## 9) الربط بـ Claude

### Claude (الموقع أو التطبيق)
1. **Settings ← Connectors ← Add custom connector**.
   - الاسم: `Spread AI`
   - العنوان: `https://mcp.ai.spreadagency.net/mcp`
2. **Connect**. هتفتح صفحة «ربط Claude بـ Spread AI»:
   1. اختار الصلاحيات.
   2. اكتب باسورد صاحب الموقع.
   3. دوس **موافق**.
3. في أي شات: فعّل الموصّل من زرار الأدوات، واكتب «اعمل المراجعة اليومية». أو اختار برومبت **daily_review** من قايمة الموصّل.

> لو Claude طلب **OAuth Client ID / Secret** (Advanced settings):
> 1. حط قيم في `MCP_OAUTH_CLIENT_ID` و `MCP_OAUTH_CLIENT_SECRET` (32 حرف على الأقل) في `.env`.
> 2. اعمل `sudo systemctl restart spread-mcp`.
> 3. اكتب نفس القيم في Claude.
>
> غير كده سيبهم فاضيين، وClaude هيسجّل نفسه تلقائي.

### Claude Code (اختياري)
```bash
claude mcp add --transport http spread https://mcp.ai.spreadagency.net/mcp \
  --header "Authorization: Bearer <التوكن الثابت>"
```

## 10) التشغيل اليومي
| عايز | الأمر |
|---|---|
| السجل لحظة بلحظة | `journalctl -u spread-mcp -f` |
| إعادة تشغيل | `sudo systemctl restart spread-mcp` |
| تحديث الكود | فك الحزمة الجديدة فوق `/opt/spread-mcp/app` ← `sudo chown -R spreadmcp:spreadmcp /opt/spread-mcp` ← `sudo -u spreadmcp npm ci && sudo -u spreadmcp npm run build` ← `sudo systemctl restart spread-mcp` |
| **إلغاء كل دخول Claude** (لو شاكك في حاجة) | `sudo systemctl stop spread-mcp && cd /opt/spread-mcp/app && sudo -u spreadmcp npm run revoke-all && sudo systemctl start spread-mcp` |
| تغيير الباسورد | `npm run hash-password` ← حدّث `.env` ← restart |
| تغيير التوكن الثابت | `openssl rand -hex 32` ← `MCP_STATIC_TOKENS` ← restart |
| طلبات الـ MCP على الموقع | الأدمن ← AI CENTER ← إعداد الـ MCP ← «آخر طلبات الـ MCP» (العميل `mcp:…`) |

## مشاكل معروفة
| العَرَض | السبب والحل |
|---|---|
| Claude بيقول «couldn't connect» | جرّب `curl https://mcp.ai.spreadagency.net/.well-known/oauth-protected-resource/mcp` من جهازك. لو مارجعش JSON، المشكلة في nginx أو الشهادة أو الـ DNS |
| `403 Invalid Host` | nginx مش باعت `Host`. اتأكد من `proxy_set_header Host $host;` |
| الأدوات بترجع «الـ Internal API رفض الطلب» | IP الـ VPS مش في `INTERNAL_API_IPS` |
| الأدوات بترجع «رفض التوكن» | `INTERNAL_API_TOKEN` مختلف بين الاتنين |
| «محاولات غلط كتير» | استنى ربع ساعة، أو اعمل `sudo systemctl restart spread-mcp` |
| الخادم مابيقومش | شوف `journalctl -u spread-mcp -n 50`. أشهر سبب متغير ناقص في `.env`، والرسالة بتقول اسمه |
