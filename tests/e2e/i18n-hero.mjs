// Spread AI — E2E الترجمة: الموقع بالإنجليزي ← الهيرو «Start your campaign» ← التسجيل بالإنجليزي ← الهوية بالإنجليزي
// للتطوير المحلي بس:  node tests/e2e/i18n-hero.mjs http://localhost:8080 [مجلد الصور]
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';
import path from 'path';
const require = createRequire((process.env.PLAYWRIGHT_NODE_PATH || '/opt/node22/lib/node_modules') + '/');
const { chromium, devices } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const base = (process.argv[2] || 'http://localhost:8080').replace(/\/$/, '');
const shots = process.argv[3] || '';
const helper = (...a) => execFileSync('php', [path.join(root, 'tests/e2e/hx-helper.php'), ...a], { encoding: 'utf8' }).trim();
const tag = Math.random().toString(16).slice(2, 8);
const email = `hx-${tag}@test.local`, biz = `عيادة الابتسامة EN ${tag}`;
const AR = /[؀-ۿ]/;
let pass = 0, fail = 0;
const t = (n, ok, why = '') => { ok ? pass++ : fail++; console.log((ok ? '  ✓ ' : '  ✗ ') + n + (ok || !why ? '' : ' — ' + why)); };

helper('prep');
const b = await chromium.launch();
const errors = [];
try {
  const ctx = await b.newContext({ ...devices['iPhone 13'] });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errors.push(String(e)));
  // الزائر بيدوس «EN» من الموقع العربي
  await p.goto(base + '/?lang=ar', { waitUntil: 'networkidle' });
  await Promise.all([p.waitForNavigation(), p.click('.h-lang a[hreflang="en"]')]);
  t('زرار EN ← نفس الصفحة بالإنجليزي', (await p.getAttribute('html', 'dir')) === 'ltr' && (await p.textContent('#hx-go')).includes('Start your campaign now'), p.url());
  t('من غير سكرول أفقي', !(await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
  await p.click('#hx-go');
  t('رسالة «من غير لينك» بالإنجليزي', (await p.textContent('#hx-msg')).includes('Paste your page or website link first'));
  await p.fill('#hx-url', 'https://www.facebook.com/i18n-test-' + tag);
  await p.click('#hx-go');
  await p.waitForSelector('#hx-manual:not([hidden]), #hx-found:not([hidden])', { timeout: 60000 });
  if (await p.isVisible('#hx-manual')) {
    t('اللينك ماتقراش ← الطلب بالإنجليزي', !AR.test(await p.textContent('#hx-manual-note')), await p.textContent('#hx-manual-note'));
    await p.fill('#hx-biz', biz);
    await p.fill('#hx-ind', 'Dental clinic');
    await p.click('#hx-go');
  }
  await p.waitForSelector('#hx-found:not([hidden])', { timeout: 60000 });
  await p.waitForTimeout(1400);
  const facts = await p.textContent('#hx-facts');
  t('اللي اكتشفناه: العناوين بالإنجليزي', !AR.test(facts.replace(biz, '')), facts);
  t('فقاعة الروبوت بالإنجليزي', (await p.textContent('#hx-bubble')).includes('I found'));
  if (shots) await p.screenshot({ path: path.join(shots, 'en-hero-found.png') });
  await Promise.all([p.waitForURL(/register\.php/), p.click('#hx-reg')]);
  t('صفحة التسجيل بالإنجليزي', (await p.getAttribute('html', 'lang')) === 'en');
  await p.fill('input[name="name"]', 'Mona Test');
  await p.fill('input[name="email"]', email);
  await p.fill('input[name="phone"]', '010' + String(Math.floor(1e7 + Math.random() * 9e7)));
  await p.fill('input[name="password"]', 'Test12345!');
  await p.fill('input[name="password_confirm"]', 'Test12345!');
  for (const cb of await p.$$('form input[type="checkbox"][required]')) await cb.check();
  await Promise.all([p.waitForNavigation(), p.click('form button[type="submit"]')]);
  if (/login\.php/.test(p.url())) {
    t('بعد التسجيل: رسالة النجاح بالإنجليزي', !AR.test(await p.textContent('.alert')), await p.textContent('.alert'));
    helper('verify', email);
    await p.fill('input[name="email"]', email);
    await p.fill('input[name="password"]', 'Test12345!');
    await Promise.all([p.waitForNavigation(), p.click('form button[type="submit"]')]);
  }
  const r = JSON.parse(helper('check', email));
  t('الهوية اتحفظت واتربطت بالتجربة', r.business_name === biz && r.claimed === true, JSON.stringify(r));
  t('ولغة المحتوى English', r.dialect === 'english', JSON.stringify(r));
  t('من غير أخطاء JavaScript', errors.length === 0, errors.join(' | '));
  await ctx.close();
} catch (e) {
  t('الرحلة كملت', false, String(e).slice(0, 300));
} finally {
  await b.close();
  helper('clean', email);
}
console.log('\n' + (fail ? `✗ ${fail} فشل · ` : '✓ ') + `${pass} نجح`);
process.exit(fail ? 1 : 0);
