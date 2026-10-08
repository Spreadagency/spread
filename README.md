# شوف نفسك بعد التخسيس — AI Weight-Loss Simulator

Lead-generation landing page for **د. محمد حسام الدين المرسي**. A visitor enters a name and phone number, uploads a photo, gets an AI "after weight loss" simulation (Google Gemini), and is pushed to WhatsApp. PHP 8.1+ (no framework) · MySQL/MariaDB · vanilla JS · shared hosting with cPanel.

> صنعت بواسطة [Spread](https://spreadagency.net) — Spreadagency.net

---

## العربي — خطوات التشغيل على cPanel

1. **الساب دومين:** من cPanel ← Domains اعمل `slim.doctor-domain.com` وخلّي الـ **Document Root** يشاور على فولدر `public` جوه المشروع (مثلًا `/home/USER/slim/public`). فعّل SSL من AutoSSL.
2. **ارفع الملفات:** ارفع المشروع كله على `/home/USER/slim` (مش جوه `public_html`).
3. **قاعدة البيانات:** من MySQL Databases اعمل داتابيز ويوزر وادّي اليوزر كل الصلاحيات. بعد كده من phpMyAdmin ← Import ارفع `install/schema.sql`.
4. **الإعدادات:** انسخ `app/config.sample.php` باسم `app/config.php` واكتب فيه بيانات الداتابيز و `app_url`. ولّد `app_key` بالأمر:
   `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
   ⚠️ لو غيّرت `app_key` بعد كده، المفاتيح السرية المتخزنة مش هتتقري.
5. **الصلاحيات:** فولدر `storage/` وكل اللي جواه لازم يكون قابل للكتابة (755 للفولدرات). مش محتاج 777.
6. **PHP:** من MultiPHP Manager اختار PHP 8.1 أو أحدث، ومعاه الإضافات: `pdo_mysql`، `gd` (أو `imagick`)، `curl`، `fileinfo`، `openssl`، `mbstring`. لو عندك Imagick، صور HEIC من الآيفون هتتقري على السيرفر كمان.
7. **المفاتيح:** لحد ما لوحة التحكم تخلص، ممكن تحط المفاتيح من Terminal:
   ```
   php install/set-setting.php gemini_api_key "AIza..."
   php install/set-setting.php whatsapp_number 2010XXXXXXXX
   php install/set-setting.php website_url https://doctor-domain.com
   php install/set-setting.php meta_pixel_id 1234567890
   php install/set-setting.php meta_capi_token "EAAG..."
   php install/set-setting.php --list
   ```
   المفاتيح السرية (Gemini، CAPI، Turnstile، Webhook secret) بتتشفّر تلقائيًا. ولو حطيتها من phpMyAdmin كنص عادي هتشتغل برضه.
8. **Cron:** من Cron Jobs ضيف مهمة تشتغل مرة في اليوم:
   `0 3 * * * /usr/local/bin/php /home/USER/slim/cron/cleanup.php >/dev/null 2>&1`
9. **الأرقام والمحتوى:** الإحصائيات (سنين الخبرة، عدد العمليات…) متسجلة **مش مفعّلة** في جدول `content_items`. اكتب الأرقام الحقيقية بعد ما الدكتور يأكدها وخلّي `is_active = 1`. نفس الكلام لمواعيد الفروع ورقم التليفون في جدول `branches`.

### اختبار سريع قبل الإعلانات
- [ ] الصفحة بتفتح على `https://` والقفل ظاهر.
- [ ] تسجيل اسم ورقم جديد ← بيظهر في جدول `leads`.
- [ ] نفس الرقم مرة تانية ← مفيش ليد مكرر، ولو عنده نتيجة بتظهر له على طول.
- [ ] رفع صورة وتوليد ← النتيجة بتظهر في أقل من 30 ثانية تقريبًا.
- [ ] زرار واتساب بيفتح الرقم الصح والرسالة فيها اسم الزائر.
- [ ] لينك المشاركة `/r/...` بيعرض صورة "بعد" بس، ومن غير رقم الموبايل، ومعاينة واتساب فيها الكارت.
- [ ] Meta Events Manager ← Test Events: أحداث `Lead` و `Contact` و `ViewContent` جاية من Browser و Server بنفس الـ event_id (Deduplicated).
- [ ] فتح `https://slim.../storage/` أو `/app/` بيرجّع 403.
- [ ] امسح `meta_test_event_code` قبل ما الإعلانات تبدأ.

---

## English — Setup

1. Create the subdomain and point its **document root at `/public`**, then enable SSL. If your host can't set a document root, the root `.htaccess` rewrites everything into `/public` and blocks the private folders.
2. Create the database, then import `install/schema.sql`. The seed includes the owner admin `admin@example.com` / `ChangeMe!2026` for the upcoming admin panel, which forces a password change on first login.
3. Copy `app/config.sample.php` to `app/config.php` and set the DB credentials, `app_url` and a 64-hex `app_key`. Set `behind_cloudflare => true` if the site sits behind Cloudflare.
4. Make `storage/` writable by PHP.
5. Set keys with `php install/set-setting.php KEY VALUE`, or later from the admin panel.
6. Add the daily cron for `cron/cleanup.php`.
7. Local dev:
   ```
   php -S localhost:8000 -t public install/dev-router.php
   ```

### Getting the keys
- **Gemini:** https://aistudio.google.com/apikey. Enable billing for image generation. The default model is `gemini-2.5-flash-image`; the safety check uses `gemini-2.5-flash`. Both can be changed in `settings`.
- **Meta Pixel + Conversions API:** Events Manager → your dataset → Settings → *Generate access token*. Put the Pixel ID in `meta_pixel_id` and the token in `meta_capi_token`. Use `meta_test_event_code` while testing.
- **Cloudflare Turnstile (optional):** Dashboard → Turnstile → add a widget (mode: *Invisible* or *Managed*). Set `turnstile_site_key` and `turnstile_secret`. If either is empty, the check is skipped.
- **Webhooks:** set `webhook_n8n_url` / `webhook_crm_url` (HTTPS only) and optionally `webhook_secret`. Each request is then signed with `X-Spread-Signature: sha256=<HMAC of body>`.

---

## How it works

### Folder layout
```
public/            document root (index.php, r.php, img.php, api/, assets/)
app/               bootstrap, db, helpers, settings, csrf, services/, views/
storage/           uploads (originals, results) + logs — never web-accessible
cron/cleanup.php   retention + housekeeping
install/           schema.sql, set-setting.php, dev-router.php
public/admin/      admin panel (PHP) — /admin
public/uploads/    logo / doctor photo / OG image uploaded from the admin (PHP execution blocked)
docs/              design boards, admin prototype, screenshots (docs/screens/live = real admin)
```

### Visitor flow
| Step | Endpoint | What happens |
| --- | --- | --- |
| Page load | `index.php` | Renders everything from `settings`, `content_items` and `branches`. Logs `page_view`. Sets the CSRF token and the device cookie. |
| 1. Lead | `POST api/lead.php` | CSRF check, honeypot, Turnstile, 10 leads/hour per IP, then validation and Egyptian phone normalization. Upserts the lead by phone, so the same phone never creates a duplicate. A new lead is logged as `lead` and sent to Meta CAPI `Lead` with the browser's `event_id`, plus the webhooks. A returning lead with a result gets that result back. |
| 2. Upload | `POST api/upload.php` | The real MIME type is read from the bytes. The image is re-encoded to JPEG (EXIF stripped), max 1536px, saved under `storage/uploads/originals/xx/` with a random name. |
| 3. Generate | `POST api/generate.php` | Checks the limits, claims the row atomically, runs the Gemini safety check, then the edit (one automatic retry). Saves the result as WebP and builds a 1200×630 share card. On PHP-FPM/LiteSpeed it answers `processing` at once and keeps working; the page then polls `api/status.php` every 2 s. |
| Result | `img.php` | Images are served only through HMAC-signed URLs that expire in 24 h, or through the share token for `/r/{token}`. |
| Tracking | `POST api/event.php` | Logs `whatsapp_click`, `view_result`, `share`, `download`, `call_click`, `directions_click` and `website_click`, and mirrors `Contact`, `ViewContent` and `ShareResult` to CAPI with the same `event_id`. A WhatsApp click also fires the webhooks. |

### Limits (all in `settings`)
| Limit | Key | Default |
| --- | --- | --- |
| Completed generations per phone (admin can allow one more with `leads.regen_allowed`) | `limit_per_phone` | 1 |
| Generations per IP per 24 h | `limit_per_ip` | 3 |
| Generations per device cookie per 24 h | `limit_per_cookie` | 2 |
| Global generations per day (site time zone) | `limit_daily_global` | 300 |
| Lead submissions per IP per hour | `limit_leads_per_ip_hour` | 10 |

Failed and rejected attempts don't use up the quota.

### Security
- PDO prepared statements everywhere. All output is escaped.
- CSRF on every POST. Cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS.
- Secrets are stored as `enc:` with AES-256-GCM keyed from `app_key`.
- Uploads are checked by content, re-encoded, given random names and stored outside the web root.
- The CSP is sent from PHP. Extra hosts for custom tracking code go in `csp_extra_hosts`. HSTS, nosniff and frame options come from `.htaccess`.
- `app/`, `storage/`, `install/` and `cron/` all deny web access.
- Errors are written to `storage/logs/`; visitors never see them.

### Admin panel (`/admin`)
Log in at `https://slim.doctor-domain.com/admin/` with the seeded owner `admin@example.com` / `ChangeMe!2026`. The first login forces a password change. After that, add your own owner from **الإعدادات** and delete or rename the seed account.

| Page | What it does | Role |
| --- | --- | --- |
| لوحة التحكم | KPIs with period-over-period deltas and sparklines, leads per day, leads by `utm_campaign`, funnel (visit → lead → upload → generated → WhatsApp), API usage vs daily cap, latest 10 leads | all |
| المسجلين | Search, filters (status, campaign, period, has image, clicked WhatsApp), bulk status, bulk delete, Excel/CSV export (UTF-8 BOM). Clicking a row opens a drawer with details, the before/after images, events timeline, status, notes, "allow regenerate" and delete. | view / edit |
| الصور | Before/after pairs with filters, lightbox, download and delete, plus the auto-delete banner with the exact cron command and the last run | view / edit |
| SEO | Title, description, keywords, canonical, OG title/description/image, favicon, schema toggles, robots.txt, live Google and Facebook/WhatsApp previews | view / edit |
| التتبع (Pixel) | Pixel ID, masked CAPI token, Graph version, test event code, per-event toggles, GA4, GTM, custom head/body code, extra CSP hosts, **Send test event** | owner |
| الربط والـ API | Masked Gemini key, models, prompt and safety prompt, timeout/retry, all limits, Turnstile, n8n / Spread CRM webhooks + secret, **Test connection** / **Test webhook** | owner |
| محتوى الصفحة | Doctor info, photo and logo; hero texts, WhatsApp number and message, website, socials; stats, experience, steps, FAQ and loading facts (add/edit/hide/drag to reorder); branches with map; live mobile preview | view / edit |
| الإعدادات | Admin users (add with a one-time temp password, change role, reset password, delete), retention days, share-page privacy, maintenance mode, time zone, IP allowlist, brand colors, privacy text, activity log | owner |

Roles: **owner** can do everything. **editor** can do everything except Tracking, Integrations and Settings. **viewer** is read-only, and every form is disabled for them.

Security:
- Logins use bcrypt and are limited to 5 attempts per 15 min, per IP and per email.
- The session is regenerated on login, expires after 8 h idle, and is bound to the browser.
- CSRF protection covers every POST, including fetch requests.
- The admin pages send a strict CSP with no inline scripts.
- An optional IP allowlist refuses to save a list that would lock you out.
- Every change is written to `admin_logs`, shown in the activity log.
