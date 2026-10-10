# Builds, signing and release

## 0. One-time setup (owner)

| Step | Where | Why |
|---|---|---|
| 1. Create an Expo account and run `npx eas-cli@latest init` inside `mobile-app/` | expo.dev | Writes `extra.eas.projectId` into `app.json`. EAS builds and push notifications need it. |
| 2. Create an access token, then add it as GitHub secret **`EXPO_TOKEN`** | expo.dev → Account settings → Access tokens · GitHub → Settings → Secrets → Actions | Lets the workflow start EAS builds. |
| 3. Apple Developer Program ($99/yr) | developer.apple.com | Required for iOS builds, TestFlight and the App Store. |
| 4. Google Play Console ($25 once) | play.google.com/console | Required for the Play Store. |
| 5. Confirm the app identifiers | `app.json` → `ios.bundleIdentifier` and `android.package` = `net.spreadagency.spreadai` | Uses reverse-DNS of the domain you own (spreadagency.net). Change it **before** the first store upload; after that it is permanent. |
| 6. Create `/privacy` and `/terms` pages | Site admin → Page Builder (or set `mobile_privacy_url` / `mobile_terms_url`) | Both stores require a privacy-policy URL. The app links to these pages. |
| 7. Decide in-app purchases | see `02-architecture.md` §8 | Store builds currently do **not** sell anything. |

**Signing keys are never committed.**
- On first build, EAS creates the Android upload keystore and the iOS certificates and provisioning profiles, and stores them on Expo's servers (`eas credentials`).
- `.gitignore` excludes `*.jks`, `*.p8`, `*.p12`, `*.key` and `*.mobileprovision`.

## 1. Builds

| Artifact | How | Signing | Use |
|---|---|---|---|
| **Test APK** (any phone) | GitHub → Releases → **android-test-latest** (direct download, refreshed on every push to `mobile-app/**`) · also the workflow artifact `spread-ai-android-test-apk` | Android debug key | Sideload for testing. Not for Play. |
| **Release APK** (direct distribution) | Actions → *Run workflow* → profile `preview`, platform `android`; or `eas build -p android --profile preview` | EAS-managed release keystore | Install link from expo.dev. |
| **Play AAB** | profile `production`, platform `android`; or `eas build -p android --profile production` | EAS-managed upload key | Upload to Play Console, or `eas submit -p android`. |
| **iOS simulator** | profile `simulator`, platform `ios` | none | Run on the Xcode simulator. |
| **iOS device / TestFlight** | profile `preview` (ad-hoc) or `production`; `eas build -p ios --profile production` | Apple certificates via EAS (needs the Apple account) | `eas submit -p ios` uploads to TestFlight / App Store Connect. |

Notes:
- **Versioning:**
  - `version` in `app.json` is the user-visible version.
  - Build numbers (`versionCode` / `buildNumber`) are managed remotely by EAS (`appVersionSource: remote`).
  - `production` auto-increments them.
- **API URL:** `EXPO_PUBLIC_API_URL` is set per profile in `eas.json`; the default is production. For a staging backend, add a profile with that URL or override it with an EAS environment variable. Release builds must use HTTPS.

### Local development
```bash
cd mobile-app
npm ci
cp .env.example .env.local            # point EXPO_PUBLIC_API_URL at your server
npx expo start                        # Expo Go / dev build
npx tsc --noEmit && npx expo lint && npx jest
```
- Android emulator against a local PHP server: use `EXPO_PUBLIC_API_URL=http://10.0.2.2:8080`.
- Web preview (`npx expo start --web`): add its origin to the `mobile_cors_origins` setting.

## 2. Server deployment (PHP)

1. Upload the new and changed files listed in `03-api.md`. No existing behaviour changes.
2. The two new tables are created on the first request from the app. To create them by hand instead, run `sql/2026_mobile_api.sql` from Admin → Migrations.
3. Make sure HTTPS is on, which the root `.htaccess` already forces.
4. Optional: if your host strips `Authorization`, nothing is needed, because the app also sends `X-Spread-Token`.
5. `mobile-app/` contains an `.htaccess` that denies all web access. Do not upload `mobile-app/node_modules`; the server doesn't need the app folder at all.

## 3. Store submission checklist

### App Store (iOS)
- [ ] Apple Developer account and the App Store Connect app record, with bundle ID `net.spreadagency.spreadai`.
- [ ] Screenshots: 6.9" and 6.5" iPhone, taken from a TestFlight build.
- [ ] Privacy policy URL (step 6 above) and the App Privacy questionnaire. Data collected:
  - name, e-mail and phone (account);
  - user content: posts, images and brand info (app functionality);
  - device push token;
  - no tracking, no ads SDK.
- [ ] Account deletion inside the app ✓ (Profile → Settings → حذف الحساب, with a 14-day grace period, server-side).
- [ ] Demo account for App Review: an active plan and a verified e-mail, so the reviewer can see design and publishing.
- [ ] Payments decision (§8): the reviewer may ask about 3.1.1 / 3.1.3.
- [ ] Export compliance: `usesNonExemptEncryption: false` (HTTPS only).

### Google Play
- [ ] Play Console app with package `net.spreadagency.spreadai` and Play App Signing enabled.
- [ ] Data safety form, with the same data as above. Data is encrypted in transit, and users can request deletion.
- [ ] Content rating questionnaire, target audience, ads declaration (no ads).
- [ ] Payments policy: the same decision as iOS.
- [ ] Upload the AAB to the internal testing track (`eas submit -p android`).

### Store listing (Arabic / English)
- **Name:** Spread AI
- **Subtitle / short description:** فريق تسويق كامل بالذكاء الاصطناعي — منشورات وتصميمات ونشر لبراندك
- **Full description:**
  - اكتب منشورات تسويقية بهوية براندك في ثواني.
  - اختار من أفكار جاهزة بزوايا مختلفة.
  - حوّل المنشور لتصميم احترافي.
  - انشر أو جدوِل على فيسبوك وإنستجرام.
  - تابع رصيدك وباقتك.
  - نفس حسابك على منصة Spread AI.
- **Keywords:** تسويق, محتوى, سوشيال ميديا, ذكاء اصطناعي, تصميم, فيسبوك, إنستجرام, منشورات
- **Category:** Business (primary) / Productivity
