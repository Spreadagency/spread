# Mobile API reference (`/api/v1`)

Base URL is `https://<platform-domain>/api/v1/`, for example `https://ai.spreadagency.net/api/v1/`.
Every response is JSON in one of two shapes:
- `{ "ok": true, … }`
- `{ "ok": false, "error": "<Arabic message for the user>", "code": "<stable code>" }`, sent with a real HTTP status.

### Request conventions

| Header | Purpose |
|---|---|
| `Authorization: Bearer spm_…` **and** `X-Spread-Token: spm_…` | Device token. The second header is a fallback for hosts that strip `Authorization`. |
| `Content-Type: text/plain` + `X-Payload: b64` | The JSON body is base64-encoded, so mod_security doesn't block long Arabic text. Plain JSON also works. |
| `Idempotency-Key: <uuid>` | **Required** on AI and other expensive POSTs (see the table below). Reuse the same key when retrying the same user action. |
| `X-App-Platform`, `X-App-Version`, `X-Device-Name` | Device label shown in **Settings → Sessions**. |

- No cookies are read or set.
- CSRF does not apply to token requests.
- CORS is off by default. To allow origins for development, set `mobile_cors_origins` (a comma-separated list) in the `settings` table.

### Common error codes

| Code | HTTP | Meaning |
|---|---|---|
| `auth` | 401 | Token is missing, expired or revoked, or the user logged out on another device. |
| `email_unverified` · `pending_approval` · `rejected` · `suspended` | 403 | Account state; same rules as the website. |
| `needs_phone` | 403 | Google account without a phone number. The user completes it in Settings. |
| `subscription` | 200 | Design or publishing needs an active plan. The response also contains a `paywall` object. |
| `quota` / `credits` | 402 | Monthly quota used up, or not enough credits. |
| `idempotency_required` | 428 | An expensive POST was sent without an `Idempotency-Key`. |
| `in_progress` | 409 | The same key is still running. |
| `rate_limit` | 429 | Too many requests. |
| `validation` · `invalid` | 422 | Bad input. |
| `not_found` | 404 | Missing, or owned by another user. |

---

## `auth.php`

| Action | Method | Input | Output |
|---|---|---|---|
| `login` | POST | `email, password` | Either `{token, expires_in, user}` or `{two_factor:true, challenge, expires_in, email_hint}` |
| `verify_code` | POST + `Bearer <challenge>` | `code` (6 digits) | `{token, expires_in, user}` |
| `resend_code` | POST + `Bearer <challenge>` | — | `{sent:true}` |
| `register` | POST | `name, email, phone, password, password_confirm, business_name?, ref_code?` | `{registered, needs_verification, pending_approval, message}` (201) |
| `forgot_password` | POST | `email` | `{message}` (same reply whether or not the e-mail exists) |
| `resend_verification` | POST | `email` | `{message}` |
| `me` | GET | — | `{user, block, brand{id,name,logo}, balance}` |
| `logout` | POST | — | `{logged_out}`. Revokes the token and its session row. |
| `push_register` | POST | `push_token` (`ExponentPushToken[…]`, or empty to unregister) | `{registered}` |

**Login** follows the same rules as `public/login.php`:
- rate limit of 6 attempts per 10 minutes per IP;
- the e-mail must be verified;
- approval must not be pending or rejected;
- the account must be active;
- e-mail 2FA applies (`account_2fa_send` / `account_2fa_verify`).

**Register** mirrors `public/register.php`: brand name, phone, referral, manual approval, verification e-mail and CRM notification.

## `app.php`

| Action | Method | Output |
|---|---|---|
| `bootstrap` | GET (no auth needed) | `api_version`, `min_app_version` (setting `mobile_min_version`), `purchases` (setting `mobile_purchases`: `off`\|`web`), `links{privacy, terms, help, support, reset_password}`, `options{content_types, platforms, lengths, tones, dialects, templates, ratios, formats}`, `costs{…}` |
| `dashboard` | GET | Greeting, brand health, action items (each with an app `route`), journey, month counters, `usage` (respects `credits_display`), `subscribed`, recent posts and designs, announcement slides, notification badge |
| `notifications` | GET | `badge`, `items` (computed bell feed), `history` (`user_notifications`) |
| `notif_read` | POST | `id?`; omit it to mark everything as read |
| `credits` | GET | `usage{…units}`, `costs`, `history` (amounts are `null` when the admin hides numbers), `payments` (manual payment requests), `subscribed` |
| `packages` | GET | `packages[{id, name, description, badge, featured, validity_days, features, quotas, price_egp}]`. `price_egp` is `null` unless purchases mode is `web`. |
| `web_handoff` | POST | `target` (a website page from the allow-list). Returns `{url, expires_in:120}`, a one-time sign-in link for the browser. |

## `call.php?ep=<name>` — the existing website endpoints

The request runs **the website's own PHP file**. That file still enforces:
- charging and refunds;
- quotas;
- the subscription gate;
- ownership;
- rate limits.

The proxy adds only token authentication, JSON errors for auth failures, and idempotency.

| `ep` | Website file | Notes |
|---|---|---|
| `contents` | `public/api/contents.php` | `library`, `get`, `save` (`rev` conflict check), `ai_edit`*, `restore`, `use_design`, `upload_design`, `flag`, `versions`, … |
| `studio` | `public/api/studio.php` | `home`, `design`, `to_post`, `brief`*, `trend_ideas`*, `drafts` … |
| `settings` | `public/api/settings.php` | `home`, `profile_save`, `password`, `session_end`, `sessions_end_others`, `delete_request`, `delete_cancel`, `disconnect` … |
| `brand` | `public/api/brand.php` | `get`, `save_field`, `upload_logo` (multipart `logo`), `analyze_url`*, … |
| `campaigns` | `public/api/campaigns.php` | `list`, `create`, `get`, … |
| `campaign_flow` | `public/api/campaign-flow.php` | `board`, `ideas_generate`* (`batch`, `total`), `idea_toggle`, `content_generate`*, `evaluate`*, `improve`* … |
| `journey` | `public/api/journey.php` | `state` |
| `generate_content`* | `public/ajax/generate-content.php` | form: `content_type, platform, length, tone, dialect, template_id?, extra_notes, use_logo, use_personal_image` |
| `regenerate_content`* | `public/ajax/regenerate-content.php` | form: `content_id` |
| `save_content` | `public/ajax/save-content.php` | form: `content_id, generated_text, hashtags, cta` |
| `generate_design`* | `public/ajax/generate-design.php` | multipart: `content_id, ratio, include_logo, custom_prompt?, source_image?` (subscription gate, 2 credits) |
| `studio_design`* | `public/ajax/studio-design.php` | multipart: `mode (free\|from_image\|…), free_prompt, ratio, include_logo, source_image?, design_edit?, edit_of?` |
| `generate_logo`* | `public/ajax/generate-logo.php` | form: `description, style` |
| `upload_image` / `delete_image` | `public/ajax/upload-image.php` / `delete-image.php` | brand images |
| `add_note` | `public/ajax/add-note.php` | |
| `publish_direct`* | `public/ajax/publish-direct.php` | form: `content_id, connection_id, platform, mode (now\|schedule\|queue), scheduled_at` (Cairo time `Y-m-d H:i`) |
| `schedule_content` | `public/ajax/schedule-content.php` | form: `content_id, scheduled_at, publish_platform, connection_id` \| `cancel=1` |

\* = expensive operation: an `Idempotency-Key` header is required.

**How a key is handled:**
- If the same key has already finished successfully, the stored response is replayed with `X-Idempotent-Replay: 1`, and the endpoint is not run again.
- If a run with that key failed, the key can be retried.
- Stored results are kept for 7 days.

Form fields listed in `_b64=field1,field2` are base64-decoded by the endpoints that call `decode_b64_fields()`.

## `handoff.php?code=…`

This is opened in the system browser, not called by the app's API client.
- It consumes the one-time code and starts a normal website session (cookie).
- It then redirects to the allow-listed page, for example `social-accounts.php` for the Facebook OAuth flow.

## Database objects (additive)

Both tables are created automatically on the first request (`mobile_ensure_schema()`). They are also in `sql/2026_mobile_api.sql` and listed in the migrations screen.

- `mobile_tokens`: `id`, `user_id`, `token_hash` (sha256), `kind` (`access`\|`challenge`\|`handoff`), device fields, `push_token`, `target`, `created_at`, `last_used_at`, `expires_at`, `revoked_at`.
- `mobile_idempotency`: `user_id` + `idem_key` (unique), `endpoint`, `status` (`running`\|`done`\|`failed`), `http_code`, `response`, timestamps.

## Settings (all optional)

| Key | Default | Purpose |
|---|---|---|
| `mobile_purchases` | `off` | `web` lets internal or direct builds show prices and open web checkout. |
| `mobile_min_version` | `1.0.0` | Returned by `bootstrap`, for forcing updates later. |
| `mobile_privacy_url` / `mobile_terms_url` | `<APP_URL>/privacy`, `/terms` | Legal pages linked from the app. |
| `mobile_push_enabled` | `1` | Turns Expo push on or off. |
| `mobile_cors_origins` | (empty) | Comma-separated origins allowed for the web preview. |

## Changes to existing files

Every change is guarded by `function_exists`, so the website behaves exactly as before.

| File | Change |
|---|---|
| `includes/api.php` → `api_send()` | Hook that stores the response for an idempotency key. |
| `includes/helpers.php` → `json_response()` | Same hook. |
| `includes/billing.php` → `notify_user()` | Also sends an Expo push when the user has the app. |
| `cron/publish-scheduled.php` | Push on a published post or a permanent failure. |
| `includes/migrations.php` | Lists `2026_mobile_api.sql`. |
