# تشغيل خادم الـ MCP على VPS — من الصفر لحد ما Claude يشتغل

الدليل ده لحد أول مرة يتعامل مع VPS. كل خطوة فيها الأمر بالظبط واللي المفروض تشوفه.

| | |
|---|---|
| الوقت | حوالي **45 دقيقة** (أغلبها استنّى الـ DNS) |
| التكلفة | VPS صغير: حوالي **4 لـ 7 دولار في الشهر** |
| الافتراضات | Ubuntu **24.04** (أو 22.04) · دومين الخادم **`mcp.ai.spreadagency.net`** · الموقع **`ai.spreadagency.net`** — لو مختلفين غيّرهم في الأوامر |

---

## الفكرة في سطرين

الموقع على cPanel ممتاز للـ PHP، بس مايقدرش يشغّل برنامج Node شغال 24 ساعة. علشان كده خادم الـ MCP بيقعد على **VPS** (سيرفر صغير بتاعك لوحدك).

```
Claude ──HTTPS──▶ mcp.ai.spreadagency.net  (الـ VPS: nginx ← خادم الـ MCP)
                          │
                          └──HTTPS + توكن──▶ ai.spreadagency.net/internal-api/v1  (الموقع على cPanel)
                                                    │
                                                    └──▶ قاعدة البيانات (بمستخدمين محدودين)
```

- خادم الـ MCP **مابيكلّمش MySQL خالص**. بيكلّم الـ Internal API بس (المرحلة 3).
- الـ Internal API بيقبل من **IP الـ VPS بس** وبالتوكن.
- مفيش ولا أداة بتحذف، ولا بتلمس الكريدت أو الدفع أو الاشتراكات أو الحسابات أو الأفلييت.

---

## 0) قبل ما تبدأ (من الموقع)

- [ ] المرحلة 3 شغالة: الأدمن ← **AI CENTER ← إعداد الـ MCP** كله ✅.
- [ ] معاك **`INTERNAL_API_TOKEN`**. هتلاقيه في `includes/config.php` على الموقع (cPanel ← File Manager). ماتبعتوش لحد في شات أو إيميل.
- [ ] اختار **باسورد صاحب الموقع** للـ MCP (12 حرف على الأقل، مختلف عن باسورد الأدمن). ده اللي هتكتبه لما توافق إن Claude يتربط.
- [ ] الحزمة **`spread-mcp-server.zip`** على جهازك.

---

## 1) اختيار الـ VPS وإنشاؤه

### المواصفات
| | الأقل | المفضّل (لو هتشغّل الفحوصات الأسبوعية — خطوة 9) |
|---|---|---|
| المعالج | 1 vCPU | 2 vCPU |
| الرام | 1 GB | 2 GB |
| المساحة | 20 GB | 40 GB |
| النظام | **Ubuntu 24.04 LTS** | |
| المكان | أقرب داتا سنتر لسيرفر الموقع (غالبًا أوروبا) | |

أي مزوّد معروف ينفع، مثلًا: Hetzner · DigitalOcean · Vultr · Contabo · Hostinger VPS. الأسعار بتتغير، فبص على صفحتهم.

### مفتاح SSH (أأمن من الباسورد — اعمله مرة واحدة على جهازك)

**Windows:** افتح **PowerShell** واكتب:
```powershell
ssh-keygen -t ed25519 -C "spread-vps"
# Enter ← Enter ← Enter (أو حط باسورد للمفتاح لو عايز)
type $env:USERPROFILE\.ssh\id_ed25519.pub
```

**Mac / Linux:** افتح **Terminal**:
```bash
ssh-keygen -t ed25519 -C "spread-vps"
cat ~/.ssh/id_ed25519.pub
```

السطر اللي بيبدأ بـ `ssh-ed25519 …` ده **المفتاح العام**. انسخه.
- الملف التاني `id_ed25519` (من غير `.pub`) هو **المفتاح الخاص**. مايتبعتش لحد، ومايترفعش أي حتة.

### إنشاء السيرفر
في لوحة المزوّد:
1. **Create Server / Droplet / Instance**.
2. اختار **Ubuntu 24.04** والمواصفات اللي فوق.
3. في **SSH Keys** ← Add ← الصق المفتاح العام.
4. Create. بعد دقيقة هيظهر **IP السيرفر** (زي `203.0.113.25`). اكتبه عندك.

> لو المزوّد بيدّيك جدار ناري في اللوحة (Firewall): افتح **22 و 80 و 443** بس.

---

## 2) الدومين (DNS)

في cPanel ← **Zone Editor** ← الدومين `spreadagency.net` ← **+ A Record**:

| الحقل | القيمة |
|---|---|
| Name | `mcp.ai` |
| Address / Record | IP الـ VPS |
| TTL | `300` |

اتأكد إنه اشتغل (من دقايق لساعة):
```bash
# Mac / Linux
dig +short mcp.ai.spreadagency.net
# Windows (PowerShell)
nslookup mcp.ai.spreadagency.net
```
لازم يطلع IP الـ VPS. لو لسه مش طالع، كمّل عادي، والسكريبت هيقولك تعمل الشهادة بعدين.

---

## 3) الدخول على السيرفر

من PowerShell أو Terminal:
```bash
ssh root@IP_الـVPS
```
- أول مرة هيسألك `Are you sure you want to continue connecting?` ← اكتب `yes`.
- لو المزوّد عمل مستخدم اسمه `ubuntu` بدل `root`، ادخل بـ `ssh ubuntu@IP` وحط `sudo` قبل الأوامر.

**تحديث السيرفر أول مرة:**
```bash
apt update && apt upgrade -y && apt install -y unzip
reboot        # لو طلب. استنى دقيقة وادخل تاني بـ ssh
```

**(مستحسن) قفل الدخول بالباسورد** — بعد ما تتأكد إن الدخول بالمفتاح شغال:
```bash
echo "PasswordAuthentication no" > /etc/ssh/sshd_config.d/90-spread.conf
systemctl restart ssh
apt install -y fail2ban        # بيحظر أي حد بيجرّب باسوردات
```

---

## 4) رفع الحزمة

**من جهازك** (PowerShell أو Terminal، من الفولدر اللي فيه الملف):
```bash
scp spread-mcp-server.zip root@IP_الـVPS:/root/
```
> لو بتحب الواجهات: برنامج **WinSCP** (Windows) أو **Cyberduck** (Mac). اتصل بـ SFTP بنفس الـ IP والمفتاح، واسحب الملف لـ `/root`.

---

## 5) التركيب بأمر واحد

على السيرفر:
```bash
cd /root
unzip -o spread-mcp-server.zip deploy/install.sh
sudo bash deploy/install.sh install mcp.ai.spreadagency.net إيميلك@gmail.com ai.spreadagency.net
```

السكريبت بيعمل كل ده لوحده:
1. بيركّب nginx و certbot و Node 22.
2. بيقفل الجدار الناري (ufw) إلا SSH و HTTP و HTTPS. بورت 3000 مش مفتوح لبره.
3. بيعمل مستخدم مخصوص `spreadmcp` من غير دخول.
4. بيفك الكود في `/opt/spread-mcp/app` وبيبنيه.
5. بيعمل `.env` (`chmod 600`) وبيسألك على حاجتين بس:
   - **`INTERNAL_API_TOKEN`**: الصقه (مش هيظهر وانت بتكتب، وده طبيعي).
   - **باسورد صاحب الموقع**: اكتبه (برضه مش هيظهر).
   - الباقي (سر الـ JWT، والتوكن الثابت) بيتولّد عشوائي.
6. بيشغّل الخادم كخدمة (systemd)، فبيقوم لوحده لو السيرفر عمل restart.
7. بيظبط nginx، وبيطلّع شهادة HTTPS من Let's Encrypt (بتتجدد لوحدها).

في الآخر هيطبعلك مربع فيه **4 حاجات**: سطر `INTERNAL_API_IPS`، أمر الاختبار، عنوان الموصّل، و**التوكن الثابت**. احفظ التوكن في مكان آمن (مدير باسوردات مثلًا).

> لو الـ DNS ماكانش اشتغل لسه، السكريبت هيقولك. لما يشتغل نفّذ السطر اللي كتبهولك:
> `sudo certbot --nginx -d mcp.ai.spreadagency.net --redirect -m إيميلك --agree-tos -n`

---

## 6) الموقع يسمح للـ VPS

على الموقع: cPanel ← File Manager ← `includes/config.php`. ضيف (أو عدّل) السطر:
```php
define('INTERNAL_API_IPS', 'IP_الـVPS');
```

**اختبار من الـ VPS** (لازم يرجع `"ok":true`):
```bash
TOKEN=$(sudo grep ^INTERNAL_API_TOKEN= /opt/spread-mcp/app/.env | cut -d= -f2)
curl -s -H "Authorization: Bearer $TOKEN" -H "X-Scope: read" -H "X-Client: setup" \
  "https://ai.spreadagency.net/internal-api/v1/index.php?r=health"
```

| الرد | الحل |
|---|---|
| `ip_denied` | الـ IP غلط. شوف الـ IP الحقيقي بـ `curl -s ifconfig.me` |
| `auth` | التوكن في `.env` مش زي اللي في `config.php` |
| مفيش رد / timeout | الموقع واقف، أو الاستضافة حاجبة الـ IP. كلّم الدعم بتاعهم |

---

## 7) الاختبار الكامل

```bash
cd /opt/spread-mcp/app
sudo -u spreadmcp env MCP_URL=https://mcp.ai.spreadagency.net MCP_TOKEN=التوكن_الثابت npm run smoke
```
- لازم يطلع **0 فشل**.
- `SMOKE_WRITE=1` قبل الأمر بيجرّب أدوات الكتابة كمان. بيكتب تقييم وتقرير تجريبي بتاريخ 2000-01-01 في قاعدة الـ AI بس.

ومن جهازك، افتح في المتصفح: `https://mcp.ai.spreadagency.net/healthz`. لازم تشوف `{"ok":true,…}`.

---

## 8) الربط بـ Claude

### Claude (الموقع claude.ai أو التطبيق)
1. **Settings ← Connectors ← Add custom connector**:
   - الاسم: `Spread AI`
   - العنوان: `https://mcp.ai.spreadagency.net/mcp`
2. دوس **Connect**. هتفتح صفحة «ربط Claude بـ Spread AI»:
   1. اختار الصلاحيات (قراءة · تقييم وتسجيل · مسودات).
   2. اكتب باسورد صاحب الموقع.
   3. دوس **موافق**.
3. في أي شات: فعّل الموصّل من زرار الأدوات، واكتب «اعمل المراجعة اليومية». أو اختار برومبت **daily_review** من قايمة الموصّل.

> لو Claude طلب **OAuth Client ID / Secret** (Advanced settings):
> 1. حط قيم في `MCP_OAUTH_CLIENT_ID` و `MCP_OAUTH_CLIENT_SECRET` (الـ secret 32 حرف على الأقل) في `/opt/spread-mcp/app/.env`.
> 2. `sudo systemctl restart spread-mcp`.
> 3. اكتب نفس القيم في Claude.
>
> غير كده سيبهم فاضيين، وClaude هيسجّل نفسه تلقائي.

### المراجعة اليومية لوحدها (المرحلة 7)
الأدمن ← **AI CENTER ← التقرير اليومي** ← مربع «المهمة المجدولة». فيه البرومبت جاهز تنسخه في مهمة مجدولة على Claude. ولو Claude ماعملهاش، الموقع بيعمل تقرير تلقائي في ميعاده.

### Claude Code (اختياري)
```bash
claude mcp add --transport http spread https://mcp.ai.spreadagency.net/mcp \
  --header "Authorization: Bearer التوكن_الثابت"
```

---

## 9) الفحوصات الأسبوعية (اختياري — المرحلة 8)

مرة في الأسبوع، الـ VPS بيعمل 3 فحوصات ويبعت نتيجتها للموقع. بتلاقيها في الأدمن ← **AI CENTER ← الأمان**، وClaude بيقراها في المراجعة.

| الفحص | بيعمل إيه |
|---|---|
| **npm audit** | مكتبات خادم الـ MCP فيها ثغرات معروفة؟ |
| **OWASP ZAP baseline** | فحص سلبي للموقع من بره: headers ناقصة، كوكيز مش آمنة، ملفات ظاهرة. **بيقرا بس ومابيهاجمش** |
| **اختبار الموقع (Playwright)** | بيفتح الصفحات العامة، وصفحات العميل (كمبيوتر وموبايل)، وكل صفحات الأدمن. بيتأكد مفيش أخطاء PHP أو JavaScript أو سكرول أفقي. **بيفتح صفحات بس ومابيدوسش حفظ أو دفع** |

**التركيب** (محتاج 2 GB رام علشان ZAP و Chromium):
```bash
sudo bash /opt/spread-mcp/app/deploy/install.sh checks staging.ai.spreadagency.net
```
- يفضّل نسخة **staging** (شوف `docs/STAGING.md`). ولو مفيش، حط دومين الموقع نفسه.
- بيركّب Docker و Playwright، وبيعمل `/opt/spread-mcp/checks.env` (`chmod 600`).

**حسابات الاختبار** (علشان يختبر صفحات العميل والأدمن):
```bash
sudo nano /opt/spread-mcp/checks.env
```
حط حساب **عميل اختبار** (مش عميل حقيقي)، وحساب **أدمن اختبار** بصلاحية مشاهدة. لو سبتهم فاضيين، بيختبر الصفحات العامة بس.

**تشغيل دلوقتي** (من غير ما تستنى الجمعة):
```bash
sudo systemctl start spread-mcp-checks
journalctl -u spread-mcp-checks -n 50 --no-pager
```

---

## 10) التشغيل اليومي

| عايز | الأمر |
|---|---|
| حالة الخادم كلها | `sudo bash /opt/spread-mcp/app/deploy/install.sh status` |
| السجل لحظة بلحظة | `journalctl -u spread-mcp -f` (اخرج بـ Ctrl+C) |
| إعادة تشغيل | `sudo systemctl restart spread-mcp` |
| **تحديث الكود** | ارفع الحزمة الجديدة لـ `/root` (خطوة 4) ← `cd /root && unzip -o spread-mcp-server.zip deploy/install.sh && sudo bash deploy/install.sh update`. مابيلمسش `.env` ولا تسجيلات Claude |
| **إلغاء كل دخول Claude** (لو شاكك في حاجة) | `sudo systemctl stop spread-mcp && cd /opt/spread-mcp/app && sudo -u spreadmcp npm run revoke-all && sudo systemctl start spread-mcp` |
| تغيير باسورد صاحب الموقع | `cd /opt/spread-mcp/app && sudo -u spreadmcp npm run hash-password --silent` ← انسخ السطر لـ `.env` ← restart |
| تغيير التوكن الثابت | `openssl rand -hex 32` ← `MCP_STATIC_TOKENS` في `.env` ← restart ← حدّثه في Claude Code والمهام المجدولة |
| قفل أداة معيّنة | الأدمن ← AI CENTER ← إعدادات الجودة ← «أدوات Claude» (من غير ما تلمس السيرفر) |
| طلبات الـ MCP على الموقع | الأدمن ← AI CENTER ← سجل الـ MCP |
| تحديثات أمان Ubuntu | بتنزل لوحدها (unattended-upgrades). مرة في الشهر: `sudo apt update && sudo apt upgrade -y` |

### النسخة الاحتياطية
الحاجتين المهمين بس:
- `.env`: الأسرار.
- `data/`: تسجيلات Claude.

```bash
sudo tar czf /root/mcp-backup-$(date +%F).tgz -C /opt/spread-mcp/app .env data
# ونزّله على جهازك:
scp root@IP_الـVPS:/root/mcp-backup-*.tgz .
```
احفظ النسخة في مكان آمن ومشفّر، لأن فيها أسرار. ولو المزوّد عنده **Snapshots / Backups** فعّلها كمان.

---

## 11) التركيب اليدوي (لو السكريبت وقف في خطوة)

السكريبت بيعمل الخطوات دي بالترتيب. تقدر تكمّل من مكان ما وقف:

```bash
# الحزم
sudo apt update && sudo apt install -y nginx certbot python3-certbot-nginx ufw unzip rsync
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable

# المستخدم والكود
sudo adduser --system --group --home /opt/spread-mcp spreadmcp
sudo mkdir -p /opt/spread-mcp/app && sudo unzip -o spread-mcp-server.zip -d /opt/spread-mcp/app
cd /opt/spread-mcp/app && sudo mkdir -p data && sudo chown -R spreadmcp:spreadmcp /opt/spread-mcp
sudo -u spreadmcp npm ci && sudo -u spreadmcp npm run build

# الإعدادات
sudo -u spreadmcp cp .env.example .env && sudo chmod 600 .env
sudo -u spreadmcp npm run hash-password --silent     # ← MCP_OWNER_PASSWORD_HASH=…
openssl rand -hex 32                                 # ← MCP_JWT_SECRET
openssl rand -hex 32                                 # ← MCP_STATIC_TOKENS
sudo -u spreadmcp nano .env

# التشغيل + nginx + HTTPS
sudo cp deploy/spread-mcp.service /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now spread-mcp
curl -s http://127.0.0.1:3000/healthz
sudo cp deploy/nginx-mcp.conf /etc/nginx/sites-available/spread-mcp
sudo ln -sf /etc/nginx/sites-available/spread-mcp /etc/nginx/sites-enabled/ && sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d mcp.ai.spreadagency.net --redirect -m إيميلك --agree-tos -n
```

| المتغير في `.env` | القيمة |
|---|---|
| `MCP_PUBLIC_URL` | `https://mcp.ai.spreadagency.net` |
| `INTERNAL_API_URL` | `https://ai.spreadagency.net/internal-api/v1` |
| `INTERNAL_API_TOKEN` | **نفس** اللي في `includes/config.php` |
| `MCP_OWNER_PASSWORD_HASH` | السطر اللي طلع من `hash-password` |
| `MCP_JWT_SECRET` | أول `openssl rand` |
| `MCP_STATIC_TOKENS` | تاني `openssl rand` |
| `MCP_ENABLE_DRAFT_TOOLS` | `true` (أدوات المسودات من المرحلة 6 — كلها محتاجة موافقة الأدمن) |

⚠️ `.env` فيه أسرار. مايتبعتش لحد، ومايترفعش على GitHub (`.gitignore` بيمنعه أصلًا).

---

## مشاكل معروفة

| العَرَض | السبب والحل |
|---|---|
| `ssh: Connection timed out` | الـ IP غلط، أو جدار المزوّد قافل 22 |
| `Permission denied (publickey)` | المفتاح ماتضافش للسيرفر. ضيفه من لوحة المزوّد، أو ادخل من Console اللوحة وحطه في `~/.ssh/authorized_keys` |
| Claude بيقول «couldn't connect» | جرّب من جهازك `https://mcp.ai.spreadagency.net/.well-known/oauth-protected-resource/mcp`. لو مارجعش JSON، المشكلة في nginx أو الشهادة أو الـ DNS |
| الشهادة مااتعملتش | الـ DNS لسه مش شاور على الـ VPS (`dig +short …`). استنى وشغّل أمر certbot |
| `403 Invalid Host` | nginx مش باعت `Host`. اتأكد من `proxy_set_header Host $host;` |
| الأدوات بترجع «الـ Internal API رفض الطلب» | IP الـ VPS مش في `INTERNAL_API_IPS` |
| الأدوات بترجع «رفض التوكن» | `INTERNAL_API_TOKEN` مختلف بين الاتنين |
| «محاولات غلط كتير» | استنى ربع ساعة، أو `sudo systemctl restart spread-mcp` |
| الخادم مابيقومش | `journalctl -u spread-mcp -n 50`. أشهر سبب متغير ناقص في `.env`، والرسالة بتقول اسمه |
| `npm ci` بيقع بـ «Killed» | الرام قليلة. ضيف swap: `sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile && echo '/swapfile none swap sw 0 0' \| sudo tee -a /etc/fstab` |
| الفحوصات الأسبوعية مابتظهرش في «الأمان» | `journalctl -u spread-mcp-checks -n 80`. لو فيه `ip_denied` أو `auth` يبقى نفس حلول خطوة 6 |

---

## ملاحظة أمان معروفة (مقبولة)
`npm audit` بيطلّع تحذير **متوسط** في `@hono/node-server`. التحذير في `serveStatic`، يعني تقديم ملفات ثابتة من الديسك.

- خادمنا **مابيستخدمش** الجزء ده خالص.
- المكتبة جاية من جوه `@modelcontextprotocol/node`، والتحديث محتاج إصدار رئيسي جديد منها.

علّمها **«مقبولة»** في صفحة «الأمان»، وهتتقفل لوحدها لما المكتبة تتحدث وتعمل `install.sh update`.
