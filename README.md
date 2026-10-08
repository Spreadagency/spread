# شوف نفسك بعد التخسيس — Landing + Admin design

Lead-generation page for د. محمد حسام الدين المرسي (AI weight-loss simulator) and its admin dashboard. Everything is RTL Egyptian Arabic, IBM Plex Sans Arabic, vanilla HTML/CSS/JS with no build step, so it drops straight into the PHP structure in the build spec (`public/`).

> صنعت بواسطة [Spread](https://spreadagency.net) — Spreadagency.net

## What's here

| Path | What it is |
| --- | --- |
| `public/index.html` | Public landing page, built from the approved design board (hero + form, upload, generating, error, result slider, doctor, steps, branches, FAQ, CTA, footer, sticky WhatsApp bar). |
| `public/assets/css/site.css`, `public/assets/js/site.js` | Landing styles (all colors are CSS variables) and logic: phone normalization, UTM capture, Pixel/GA4/dataLayer events, upload + client resize, generation polling, before/after slider, share modal, FAQ + FAQPage schema. |
| `public/admin/index.html` | Admin dashboard prototype: dashboard, المسجلين, الصور, SEO, التتبع (Pixel), الربط والـ API, محتوى الصفحة, الإعدادات, plus a component sheet. |
| `public/admin/assets/admin.css`, `admin.js` | Admin design system and interactive screens (mock data). |
| `public/assets/img/` | Logo (color + white) and doctor portrait, taken from the design file. |
| `docs/screens/` | High-fidelity exports: admin at 1440px and 768px, states, and the landing at desktop and mobile. |

## Previewing

Open `public/index.html` or `public/admin/index.html` in a browser, or serve `public/` (`php -S localhost:8000 -t public`).

Landing review shortcuts: `?view=upload|preview|loading|result|error`, `&kind=rejected|cap`, `?demo=error`. When `/api/*.php` is unreachable, the flow runs in demo mode.

Admin review shortcuts: the floating switcher (بيانات / تحميل / فاضي / خطأ) on the dashboard, المسجلين and الصور screens, or `?state=loading|empty|error`, `?drawer=0` (lead drawer), `?modal=1` (delete confirm), `?toast=1`, `?collapsed=1`.

## Design tokens

Primary `#1F73B7` · Primary 700 `#134E80` · Sky 100 `#E8F3FB` · Navy/ink `#0E2B45` (admin sidebar) · Gold accent `#C9A24B` · WhatsApp/success `#137A4B` · Danger `#C23B3B`. Admin uses 12px cards and an 8px grid; the landing keeps the design board's 16–24px radii.

## Before launch

- Fill the placeholders that are marked with dashed chips: WhatsApp number (`SITE.whatsapp` in `site.js`), stats, experience years, branch hours/phone, website URL, social links.
- The stats and certificates must be confirmed by the doctor before publishing.
- The PHP backend, API endpoints, SQL schema and cron from the build spec are not part of this change. The front end already calls `api/lead.php`, `upload.php`, `generate.php`, `status.php` and `event.php` with the payloads described in the spec.
