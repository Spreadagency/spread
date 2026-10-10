# Testing report

**Environment:** local MariaDB copy of the platform schema with test accounts only, the PHP 8.3 dev server, and a mock OpenAI-compatible AI server. No production data was touched.

**App build tested:** the Expo web export of the same code base, driven with Playwright at 390×844 and 360×740 against that backend. Native-only APIs (secure store, media library, push, share sheet) need device testing (§4).

## 1. Automated checks (`mobile-app/`)

| Check | Result |
|---|---|
| `tsc --noEmit` | ✅ pass |
| `expo lint` (eslint-config-expo, react-hooks rules) | ✅ 0 problems |
| `jest`: 15 unit tests (UTF-8/base64 against Node `Buffer`, Idempotency-Key format, API client headers / base64 JSON / `_b64` forms / error mapping / 401 handling / network errors, formatters) | ✅ 15/15 |
| `expo export --platform web` (whole app bundles) | ✅ |
| `expo config --type public` (plugins and permissions valid) | ✅ |

## 2. Backend API (curl against `/api/v1`)

| Scenario | Result |
|---|---|
| Login with a bad password | ✅ 401 `invalid_credentials`, rate limit counted |
| Login | ✅ token returned, **no `Set-Cookie`**, device listed in `user_sessions` |
| Token via `Authorization` and via `X-Spread-Token` | ✅ |
| No token | ✅ 401 JSON (not a redirect) |
| Unverified e-mail | ✅ 403 `email_unverified` |
| 2FA | ✅ challenge returned; wrong code gives "باقي 4 محاولات"; right code gives a token; reusing the challenge is rejected; a challenge can't be used as an access token |
| Register | ✅ user, brand name, phone, 10 starter credits, verification mail queued |
| Forgot password | ✅ same message for unknown e-mails |
| Logout | ✅ token revoked, then 401 |
| "Log out other devices" from settings | ✅ the other app token is revoked on its next check (same 60 s window as the website) |
| Generate a post without `Idempotency-Key` | ✅ 428 |
| Generate a post | ✅ charged once (50 → 49) |
| Same key replayed | ✅ `X-Idempotent-Replay: 1`, balance unchanged, no duplicate row |
| AI provider down | ✅ the site's own logic refunds; the failed key is retryable |
| Design for a non-subscriber | ✅ `code: subscription` + paywall, **no design created, no charge** |
| Design for a subscriber, with an uploaded reference image | ✅ 2 credits, image URL returned |
| Library, item details, save (with `rev`), AI-edit proposal, studio home, settings home | ✅ |
| Another user's content | ✅ 404 `not_found` |
| Unknown proxy endpoint | ✅ 404 `unknown_endpoint` |
| Web hand-off | ✅ opens a website session once; the second use is rejected; foreign targets are rejected |
| Push hook in `notify_user()` | ✅ notification still saved when the push service is unreachable; adds ≈0.3 s |

## 3. App end to end (web build → real PHP backend)

| Flow | Result |
|---|---|
| Welcome → login → dashboard (usage card, quick actions, action items, brand health, recent posts and designs, slides) | ✅ |
| Create → generate post → post screen with "saved" banner | ✅ |
| Post → designs tab → generate design (subscriber) → design shown as cover, save/share buttons | ✅ |
| Non-subscriber: design → paywall «اشترك لصناعة التصميم والنشر» + «شوف الباقات» + «العودة للمنشور» | ✅ nothing generated, nothing charged |
| Ideas: goal + topic → 5 ideas (charged once) → write post | ✅ brand gate (< 75 %) shown and buttons disabled, as on the website |
| Projects (search, status filters, pagination) | ✅ |
| Publishing (pages, ready, scheduled, failed) | ✅ |
| Profile, credits (quotas, history, %-only display respected), packages (no prices in store mode), settings, brand identity, notifications | ✅ |
| JS errors during the full run | ✅ none, after the two fixes below |
| Horizontal overflow at 390 / 360 px | ✅ 0 |

**Bugs found and fixed during testing:**
- `expo-media-library` has no web module, which crashed the post screen on web. Fixed with a web shim (`media.web.ts`).
- The web keep-awake release threw an error. Fixed by guarding it.
- Tab labels were clipped.
- Success banners showed the info icon.
- The welcome-screen outline button had low contrast.

## 4. Website regression

After the backend changes, the following load with **no PHP errors and no JS errors**:
- 35 platform user pages;
- 48 platform admin pages;
- the home page;
- the site-admin login.

## 5. Not verified here (needs devices or accounts)

| Item | Why | How to verify |
|---|---|---|
| Native Android / iOS runtime (secure store, gallery save, share sheet, camera, push, RTL forced natively) | No Android SDK or Expo servers are reachable from this container (network policy) | Install the CI test APK; TestFlight build |
| Android APK / AAB build | Runs on GitHub Actions / EAS, outside this container | Check the "Mobile app" workflow run and its artifact |
| iOS build and TestFlight | Needs an Apple Developer account and `EXPO_TOKEN` | §1 of `04-build-and-release.md` |
| Real AI providers (OpenAI / OpenRouter) | Local tests used a mock with the same API shape | Staging account with real keys |
| Facebook / Instagram publishing | Needs a Meta app and a test page | Connect a test page through the hand-off, then publish |
| Push delivery | Needs an EAS project id and a device | Approve a payment request in admin and watch the phone |
