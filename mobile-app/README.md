# Spread AI — mobile app (Android & iOS)

This is the Spread AI app for Android and iOS. It is built with React Native, Expo SDK 57, TypeScript and Expo Router, and uses the **existing** Spread AI PHP backend and MySQL database.

The app uses the same accounts, credits, plans, posts, designs and publishing as the website.

| Doc | Contents |
|---|---|
| [docs/01-repository-audit.md](docs/01-repository-audit.md) | Phase 1: what exists in the platform, what can be reused, risks |
| [docs/02-architecture.md](docs/02-architecture.md) | Architecture, auth design, idempotency, payments and store compliance |
| [docs/03-api.md](docs/03-api.md) | `/api/v1` reference and every backend file that changed |
| [docs/04-build-and-release.md](docs/04-build-and-release.md) | Builds (APK / AAB / iOS), signing, store checklists, listing text |
| [docs/05-testing-report.md](docs/05-testing-report.md) | What was tested and how; what still needs devices or accounts |

## Quick start
```bash
npm ci
cp .env.example .env.local   # set EXPO_PUBLIC_API_URL
npx expo start
npm run typecheck && npm run lint && npm test
```

## Structure
```
src/app/            screens (Expo Router)
  (auth)/           welcome · login · 2FA · register · forgot password
  (tabs)/           home · projects · create · publishing · profile
  content/[id]      post: edit · AI edit · regenerate · versions · designs
  design/[id]       studio design: save/share · edit · turn into a post
  publish/[id]      publish now / schedule / cancel
  ideas · studio · credits · packages · notifications · settings · brand · help
src/lib/            API client, auth store, queries, AI-action hook, media, push, formatters
src/components/     UI kit (RTL, brand tokens), screen layout, AI progress / paywall states
src/theme/          design tokens from the live site
```

## Status checklist

**Completed**
- [x] Repository audit and architecture (Phase 1–2)
- [x] Backend `/api/v1`:
  - [x] token auth bridged to existing sessions
  - [x] 2FA, register, forgot password, logout
  - [x] JSON proxy to the existing endpoints
  - [x] Idempotency-Key (no double charges)
  - [x] web hand-off
  - [x] Expo push hooks
- [x] App foundation:
  - [x] TypeScript and Expo Router
  - [x] brand theme, Arabic fonts, RTL
  - [x] secure token storage
  - [x] API client with timeouts and network-only retries
- [x] Dashboard, quick create, ideas → post, post editing (manual / AI / regenerate / versions)
- [x] Design generation with the subscription gate, studio designs, save to gallery and share
- [x] Library: search, filters, pagination
- [x] Credits and quotas (respects the %-only display), packages (store-safe), notifications
- [x] Publishing: connected pages, publish now / schedule / cancel, failures
- [x] Settings: profile, password, sessions, account deletion; brand identity editing and logo upload
- [x] Unit tests, lint, typecheck, web e2e against a real backend, website regression crawl
- [x] CI workflow (checks + Android test APK), EAS profiles, store docs

**Pending: needs an owner decision or accounts**
- [ ] In-app purchases (StoreKit / Play Billing), or another store-approved payment model (see `docs/02-architecture.md` §8)
- [ ] `EXPO_TOKEN`, Apple Developer and Play Console accounts; `eas init` (project id for push)
- [ ] Privacy and terms pages on the site
- [ ] Final store identifiers (`net.spreadagency.spreadai` proposed)

**Unverified here:** native device behaviour, real AI providers, Facebook publishing, push delivery (see the testing report §5).

**Not supported, because the website has no such feature:**
- deleting a single post;
- Google sign-in in the app (web-only flow today; needs native client IDs);
- in-app connection of Facebook pages (done in the browser through the hand-off);
- content plans and deep research (still on the website).
