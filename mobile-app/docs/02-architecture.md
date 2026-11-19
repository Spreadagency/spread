# Phase 2 — Mobile architecture and plan

## 1. Principles
* **The PHP backend stays the single source of truth.** The app never talks to MySQL or to AI providers. All business rules (credits, quotas, subscription gate, ownership, rate limits) keep running in the existing PHP code.
* **Reuse before rewriting.** The app calls the existing endpoints (`public/api/*`, `public/ajax/*`) through a thin, versioned mobile layer, `/api/v1/`. That layer adds token authentication, JSON errors and idempotency. It does not duplicate generation or charging logic.
* **Additive backend changes only.** There are new files under `public/api/v1/` and `includes/mobile*.php`, plus two new tables created on first use (`mobile_tokens`, `mobile_idempotency`). No existing column or behaviour changes.
* The `mobile-app/` folder is inside the web root and carries an `.htaccess` that denies all web access.

## 2. Authentication: device tokens bridged to the existing session model
```
App ──Bearer spm_xxx──▶ /api/v1/* ──▶ includes/mobile-boot.php
                                       │ token → sha256 → mobile_tokens (active, not expired)
                                       │ session_id = HMAC(token)   (no cookies sent)
                                       │ $_SESSION['user_id'] = owner
                                       │ account checks (verified / approved / active / not revoked)
                                       ▼
                         existing endpoint code runs unchanged
```
* Tokens are 256-bit random values. Only `sha256(token)` is stored. They have a 90-day sliding expiry and are revoked on logout.
* Each token gets a deterministic PHP session ID, so the device appears in **Settings → Active sessions** (`user_sessions`). "Log out other devices" on the website therefore also logs out the phone.
* CSRF does not apply to header-token requests, because the browser never sends the token automatically. The bridge fills the session CSRF value so that existing `require_csrf()` and `api_boot()` checks pass.
* 2FA: login returns a short-lived **challenge token**. `verify_2fa` exchanges it for an access token. This reuses `account_2fa_send/verify`.
* The bridge accepts `Authorization: Bearer …` and `X-Spread-Token: …`, because Apache CGI may strip `Authorization`.
* No cookies are ever issued to the app (`session.use_cookies=0`).

## 3. `/api/v1` endpoints
| Endpoint | Purpose |
|---|---|
| `auth.php` | `login`, `verify_2fa`, `resend_2fa`, `register`, `forgot_password`, `resend_verification`, `logout`, `me`, `devices`, `push_register` |
| `app.php` | `bootstrap` (config, costs, ratios, options), `dashboard`, `notifications`, `notif_read`, `credits` (balance, plan usage, history), `packages`, `web_handoff` |
| `call.php?ep=<name>` | **allow-listed proxy** to existing endpoints (content, design, studio, library, campaign flow, publishing, settings, brand). Adds token auth, JSON auth errors and `Idempotency-Key` replay protection |
| `handoff.php?code=` | one-time code (2 min) that opens a normal web session in the browser, for flows that only exist on the web (Facebook page connection, payment pages where allowed) |

The full reference, with request and response shapes, is in `03-api.md`.

## 4. Duplicate-charge prevention
Every AI POST sent from the app carries `Idempotency-Key: <uuid>` (one per user action, kept across retries).
* The proxy stores `(user, key, endpoint)` in `mobile_idempotency`.
* If the key is still running, the proxy returns **409 `in_progress`**.
* If it has finished, the proxy replays the stored response with `X-Idempotent-Replay: 1`. The endpoint is not re-run and nothing is charged again.
* The client never auto-retries AI POSTs. It only retries safe GETs.

## 5. Mobile stack
* Expo SDK 57, React Native 0.86, TypeScript, Expo Router (file-based, typed routes).
* TanStack Query for server state (caching, pagination, retry for GETs only). Zustand for session state.
* `expo-secure-store` (Keychain / Keystore) for the token.
* `expo-image-picker`, `expo-file-system`, `expo-media-library` (save to gallery), `expo-sharing`, `expo-clipboard`, `expo-notifications` (Expo push), `expo-haptics`.
* Brand: IBM Plex Sans Arabic + Readex Pro, navy `#0B1526`, blue `#0C87EF`, teal `#2EE3CC`, off-white `#F4F7FC`, and the gradient `135deg #2EE3CC → #0C87EF`. All of these were taken from `site-assets/css/home-v2.css`.
* Layout is RTL Arabic (`I18nManager.forceRTL`), with light theme only, matching the product.

### Navigation
```
(auth)   welcome · login · verify-2fa · register · forgot
(tabs)   Home · Create · Projects · Publishing · Profile
stack    content/[id] · design/[id] · ideas flow · studio · credits · packages · notifications · settings · legal
```

## 6. Long AI requests
Generation is synchronous on the server, taking up to about 240 s. The API client therefore uses:
* a 300 s timeout for AI calls and 30 s for everything else;
* a progress UI;
* a keep-awake screen;
* idempotency, so that a dropped connection can be retried safely with the same key.

## 7. Push notifications
* `mobile_tokens.push_token` holds the Expo push token, registered after login.
* `includes/mobile-push.php` (`mobile_push_user()`) sends a push through the Expo push service. It is called from `notify_user()` for payment and plan events, and from the publishing cron when a post succeeds or fails. Each call is guarded by `function_exists`, so the website keeps working if the file is missing.
* "AI generation finished" needs no push, because generation is synchronous while the app is open.

## 8. Payments and store compliance (owner decision required)
* Everything Spread AI sells (credits and plans) is a **digital good used inside the app**.
* Apple guideline 3.1.1 and Google Play's Payments policy require store billing for such goods in Egypt and other non-US storefronts. Steering users to the website's Paymob or manual-transfer checkout is only allowed on the US storefront.
* What the app does now: it shows balance, plan, quotas, usage history and costs. It does **not** sell. Store builds have no prices, buy buttons, or links to web checkout. This is controlled by `EXPO_PUBLIC_PURCHASES=off|web`. `web` is for internal or direct-APK builds only.
* Recommended next step, which needs approval: add store products (credit packs and plan periods) through StoreKit / Play Billing, for example with `react-native-iap` or RevenueCat. Verify receipts on the server with a new `/api/v1/iap.php` that grants credits through `credits_add()` with `idem_key = store transaction id`. This needs App Store Connect / Play Console products and server credentials from the owner. Prices would also change because of store fees. That is a business-model decision, so it is **not implemented**.
* Risk: Apple may reject an app that hides purchasing entirely, citing 3.1.3(b), until IAP is added.

## 9. Builds
* No Android SDK, Expo, or Google download hosts are reachable from this development container. Real builds therefore run in the cloud:
  * **GitHub Actions** (`.github/workflows/mobile.yml`) runs typecheck, lint and tests, then `expo prebuild`, then a Gradle build that produces a **debug APK** artifact. No secrets are needed for this.
  * Release APK, AAB and iOS builds run on **EAS Build** through `eas.json` profiles. They need `EXPO_TOKEN` (GitHub secret), and for iOS an Apple Developer account.
