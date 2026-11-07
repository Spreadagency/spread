# Phase 1 — Repository audit (Spread AI platform)

Everything below was checked in the source code of this repository (not assumed).
Paths are relative to the repository root.

## 1. Technology stack

| Layer | What is used |
|---|---|
| Backend | Plain PHP 8 (no framework), procedural `includes/*.php` modules, PDO/MySQL (MariaDB compatible) |
| Web UI (platform) | Server-rendered PHP templates (`templates/`, `templates/v2/`), vanilla JS + a petite-vue style client (`assets/js/spread-app.js`, `assets/js/main.js`) |
| Marketing site | `index.php`, `site/`, `site-admin/` (separate database, see `site/config.php`) |
| Platform admin | `admin/` |
| AI | `includes/ai.php`, `includes/ai-gateway.php` (multi-provider gateway with fail-over), `includes/smart-ai.php`, provider adapters in `services/Adapters/` (OpenAI, OpenRouter, …). Keys live in `includes/config.php` / encrypted DB settings — never sent to clients |
| Hosting | Apache + `.htaccess` (cPanel). Web root = repo root; anything that is not a real file is rewritten to `public/` (`/api/x.php` → `public/api/x.php`) |
| Cron | `cron/publish-scheduled.php` (every 5 min), `cron/ai-health.php` |
| Region / currency | Egypt, EGP, `Africa/Cairo`, Arabic (Egyptian) UI, RTL |

## 2. Backend architecture and existing endpoints

Two request stacks exist side by side:

### 2.1 `public/api/*.php` — JSON API (v2)
Bootstrapped by `api_boot()` in `includes/api.php`:
* GET/POST only, operation selected with `action`.
* Body: JSON, or base64 JSON with header `X-Payload: b64` (bypasses mod_security on long Arabic text).
* CSRF on POST: header `X-CSRF-Token` (or body `csrf`) compared with `$_SESSION['csrf']` → 419 `csrf`.
* Errors: `{ok:false, error, code}` with real HTTP status (401 auth, 402 credits/quota, 403, 404, 409 conflict, 419, 422, 429, 500, 502 ai_failed).
* Per-user rate limit `api_rate_per_minute` (default 90).

| File | Actions (verified) |
|---|---|
| `api/contents.php` | `list`, `versions`, `library` (filters + 24/page), `get`, `save` (optimistic `rev`), `ai_edit`, `restore`, `flag`, `attach`, `use_design`, `upload_design`, `slides_save`, `video_*` |
| `api/studio.php` | `home`, `design`, `brief`, `save_brief`, `to_post`, `size_hint`, `trends`, `trend_ideas`, `drafts`, `draft`, `draft_save`, `draft_file`, `draft_delete` |
| `api/settings.php` | `home` (profile, plan, referral, social accounts, security), `profile_save`, `avatar`, `password`, `session_end`, `sessions_end_others`, `twofa_start/confirm`, `delete_request/cancel`, `disconnect` |
| `api/billing.php` | `quote`, `submit` (manual transfer + receipt), `cancel`, `respond`, `notif_read` |
| `api/brand.php` | `get`, `overview`, `analyze_url`, `decide`, `apply_all`, `logo_colors`, `save_field`, `upload_logo`, `insp_*`, `approve` |
| `api/campaigns.php`, `api/campaign-flow.php` | campaigns CRUD; ideas → selection → content → evaluation → plan |
| `api/journey.php`, `api/research.php` | onboarding journey; deep research |

### 2.2 `public/ajax/*.php` — legacy form endpoints
* `require_login()` → **302 HTML redirect** on auth failure (not JSON).
* `require_csrf()` → reads **only** `$_POST['csrf']`; failure = 403 plain text.
* Form-encoded / multipart only (no JSON body). Always HTTP 200, `{ok:false,error}` (sometimes `code`).
* Key endpoints: `generate-content.php`, `generate-stream.php` (SSE), `regenerate-content.php`, `save-content.php`, `generate-design.php`, `studio-design.php`, `generate-logo.php`, `upload-image.php`, `delete-image.php`, `publish-direct.php`, `schedule-content.php`, `produce-idea.php`, `generate-plan-ideas.php`, `plan-idea-action.php`, `trial.php`, `buy-package.php`.

### 2.3 HTML-only features (no JSON endpoint)
Dashboard (`dashboard.php` → `dashboard_v2_data()`), content plans (`content-plan.php`), brand sources (`sources.php`), help, packages page, login/register/forgot/reset pages, Facebook OAuth page picker.

## 3. Database (MySQL) — tables relevant to the app
Schema files: `sql/schema.sql` + ordered migrations listed in `includes/migrations.php`.

* **Users / auth**: `users` (status, approval_status, email_verified_at, two_fa_enabled, auth_provider, phone, deletion_requested_at), `email_verifications`, `password_resets`, `user_sessions` (active-session list, revocable), `user_remember_tokens`.
* **Brand**: `brand_profiles` (effectively **one brand per user**: `user_brand()` = `LIMIT 1`), `brand_images`, `brand_sources`, `brand_facts`, `brand_inspirations`.
* **Content**: `contents` (text, hashtags, cta, format post/carousel/video/story, publish state), `content_versions`, `content_notes`, `content_designs` (images per post, `contents.selected_image_id` = cover), `campaigns`, `campaign_ideas`, `content_plans`, `plan_ideas`.
* **Design studio**: `studio_designs` (+ `parent_id` versions), `studio_drafts`, `studio_methods`, `design_templates`, `media_library`, `trends`.
* **Credits**: `credit_wallets` (balance, expiry), `credit_transactions` (ledger, `idem_key` UNIQUE), `credit_lots`.
* **Plans / billing**: `credit_packages`, `user_plans` (active plan cycles), `quota_overrides`, `usage_events`, `payment_orders` (Paymob), `payment_methods`, `payment_requests` (+ events), `promo_usages`, `offers`, `referrals`.
* **Publishing**: `social_connections` (Facebook page + linked Instagram, encrypted tokens), `feature_flags`, `user_feature_access`, `publish_logs`.
* **Notifications**: `user_notifications`, `announcements`.

## 4. Authentication, sessions, permissions
* PHP file sessions, cookie `PHPSESSID` (HttpOnly, SameSite=Lax, 7 days), started in `includes/config.php`.
* "Remember me" cookie `spread_rm` (`includes/remember.php`, rotating selector/validator).
* Login: rate-limited (6/10 min/IP), requires verified e-mail, `approval_status=approved`, `status=active`; optional **2FA by e-mail code** (`account_login_or_challenge`).
* Active sessions are tracked in `user_sessions`; "log out other devices" revokes them (`account_session_check`).
* Google sign-in: web redirect flow only; ID-token `aud` must equal the web client id.
* Roles: platform users have `role=user`; admins are a separate table (`admin_users`) and panel — **not part of the mobile app**.
* **There was no token / Bearer / mobile auth of any kind, and no CORS.** The CSRF token is only exposed in HTML.

## 5. Credits, plans and payments
* Costs are DB settings (`cost_for()`): content 1, regenerate 1, design 2, logo 3, ideas tiered, studio idea 1, AI edit, research 2/4/8 …
* Charging pattern: `credits_consume()` (row lock) → AI call → `credits_add(...,'refund')` on failure. Monthly quotas via `plan_gate()`; design requires an active plan (`design_subscription_gate()`).
* **No idempotency key on generation** — a client retry after a timeout can charge twice. (Mitigated in the mobile API, see architecture doc.)
* Plans are fixed-length, **non-renewing** periods that grant credits + quotas (`user_plans`).
* Payment providers: **Paymob card iframe** (EGP), **manual transfer with receipt** (InstaPay / Vodafone Cash, admin approval), promo codes, offers and referrals.
* Everything sold is a **digital good consumed inside the app** → App Store / Google Play billing rules apply (see `02-architecture.md` §8).

## 6. AI content generation
* Quick create: `ajax/generate-content.php` (type, platform, length, tone, dialect, template, notes, logo/personal image as vision input) → one post (text, hashtags, CTA) saved in `contents`.
* Ideas → choose → post: campaign flow (`api/campaign-flow.php`: `ideas_generate` → `idea_toggle` → `content_generate`) and content plans (HTML).
* Edit: `api/contents.php save` (versions kept), AI edit by instruction (`ai_edit` proposal → `save source=ai_edit`), `ajax/regenerate-content.php`.
* Formats: post, carousel, story, video (script only).

## 7. AI design generation
* `ajax/generate-design.php` (design for a post; subscription gate, quota, 2 credits, optional reference image, ratio).
* `ajax/studio-design.php` (free studio: modes free / from_content / from_image / before_after / personal / custom / trend).
* **Fully synchronous** (no jobs/polling): a request can take up to ~4 minutes. Images saved to `storage/uploads/designs/*.png` and served publicly by unguessable file name.

## 8. Publishing
* Facebook Pages + linked Instagram Business account only (Graph API v21).
* OAuth connect is a web, session-bound flow with an HTML page picker (`public/social/connect.php`, `callback.php`).
* `ajax/publish-direct.php` (now / Facebook-scheduled / queue) and `ajax/schedule-content.php` (schedule / cancel); cron publishes with retries/back-off; errors stored in `contents.publish_error`.

## 9. Notifications
* In-app only: `user_notifications` (payment events) + computed bell feed `ui_notifications()` (failed publishes, posts needing review/design, usage, announcements).
* E-mail via `includes/mailer.php`. **No push notifications existed.**

## 10. Files, uploads, media
* `upload_image()` (`includes/uploader.php`): JPEG/PNG/WebP, max 5 MB (`MAX_UPLOAD_SIZE`), finfo MIME check, random names under `storage/uploads/<folder>/`.
* Payment receipts are private (`storage/uploads/payments` denied, served by owner check).

## 11. Security and configuration
* Secrets: `includes/config.php` and `site/config.php` are **git-ignored** (only `*.sample.php` committed). AI/Meta/Paymob keys are in config or encrypted DB settings.
* CSRF on all writes, rate limits (`rate_limit()`), ownership checks (`api_own()`, `get_user_content()`), AES-GCM for social tokens, HMAC on Meta webhooks and Paymob callbacks.

## 12. Reuse assessment

| Feature | Reuse directly | Needs API work | Mobile-specific |
|---|---|---|---|
| Login / 2FA / register / forgot | business logic (`authenticate_user`, `account_2fa_*`, `register_user`, mailer) | **token auth endpoints** (`/api/v1/auth.php`) | secure token storage |
| Session revocation | `user_sessions` | bridge ties each device token to a `user_sessions` row | — |
| Dashboard | `dashboard_v2_data()` | JSON wrapper | native layout |
| Content generation / edit | `ajax/generate-content`, `api/contents` | auth bridge + idempotency | — |
| Ideas → post | `api/campaign-flow` | auth bridge | — |
| Design generation | `ajax/generate-design`, `ajax/studio-design` | auth bridge, long timeout | image picker, save to gallery |
| Library | `api/contents library/get/save` | — | search, copy, share |
| Delete project | **not supported by the website** (no endpoint) | not added (business rule) | — |
| Credits / plan | `plan_usage`, `credits_history`, `billing_packages` | JSON wrapper | store-compliant display |
| Purchases | Paymob / manual transfer (web) | — | **store billing decision required** |
| Publishing | `publish-direct`, `schedule-content`, `api/settings` accounts | auth bridge | FB connect via web hand-off |
| Notifications | `ui_notifications`, `user_notifications` | JSON wrapper + **push tokens** | Expo push |
| Settings / deletion | `api/settings` | auth bridge | — |

## 13. Technical risks found
1. No idempotency on AI charges (double charge on retry) → mobile API adds `Idempotency-Key`.
2. Long synchronous AI requests (≤ 4 min) → mobile client uses long timeouts and never auto-retries AI POSTs.
3. `lifecycle.php` does not map `publish_status='scheduled'` (Facebook-scheduled posts show as "design ready"). Pre-existing; not changed.
4. Cancelling a Facebook-scheduled post does not delete it on Facebook. Pre-existing; documented.
5. `uploads/designs/originals/` (un-watermarked originals) is public. Pre-existing; documented.
6. `studio-design.php` does not pass the requested ratio on the non-smart path. Pre-existing; documented.
7. Apache may strip the `Authorization` header under CGI/FastCGI → the mobile API also accepts `X-Spread-Token`.
8. The `mobile-app/` folder sits inside the web root → it ships with an `.htaccess` that denies all web access.
