// Spread AI — E2E الهيرو «ابدأ حملتك»: لينك ← اللي اكتشفناه ← سجّل ← المعلومات في الهوية
// للتطوير المحلي بس:  PLAYWRIGHT_NODE_PATH=/opt/node22/lib/node_modules node tests/e2e/hx-hero.mjs http://localhost:8080 [مجلد الصور]
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
const email = `hx-${tag}@test.local`, biz = `عيادة الابتسامة ${tag}`;
let pass = 0, fail = 0;
const t = (n, ok, why = '') => { ok ? pass++ : fail++; console.log((ok ? '  ✓ ' : '  ✗ ') + n + (ok || !why ? '' : ' — ' + why)); };

helper('prep');
const b = await chromium.launch();
const errors = [];
try {
  for (const [label, opts] of [['ديسكتوب', { viewport: { width: 1440, height: 900 } }], ['موبايل', { ...devices['iPhone 13'] }]]) {
    const ctx = await b.newContext(opts);
    const p = await ctx.newPage();
    p.on('pageerror', (e) => errors.push(String(e)));
    await p.goto(base + '/', { waitUntil: 'networkidle' });
    t(`${label}: الهيرو فيه مربع اللينك وزرار «ابدأ حملتك الآن»`, await p.isVisible('#hx-url') && (await p.textContent('#hx-go')).includes('ابدأ حملتك الآن'));
    t(`${label}: من غير سكرول أفقي`, !(await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
    if (shots) await p.screenshot({ path: path.join(shots, `hx-${label === 'موبايل' ? 'm' : 'd'}-1.png`) });
    await ctx.close();
  }

  const ctx = await b.newContext({ ...devices['iPhone 13'] });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errors.push(String(e)));
  await p.goto(base + '/', { waitUntil: 'networkidle' });
  await p.click('#hx-go');
  t('من غير لينك ← رسالة', await p.isVisible('#hx-msg'));
  // لينك مش هيتقري (مفيش نت في البيئة المحلية) ← خانة اسم النشاط
  await p.fill('#hx-url', 'https://www.facebook.com/hx-test-page-' + tag);
  await p.click('#hx-go');
  await p.waitForSelector('#hx-manual:not([hidden]), #hx-found:not([hidden]), #hx-msg:not([hidden])', { timeout: 60000 });
  if (await p.isVisible('#hx-manual')) {
    t('اللينك ماتقراش ← بيطلب اسم النشاط', true);
    await p.fill('#hx-biz', biz);
    await p.fill('#hx-ind', 'عيادة أسنان');
    await p.click('#hx-go');
  }
  await p.waitForSelector('#hx-found:not([hidden])', { timeout: 60000 });
  await p.waitForTimeout(1400);
  const name = await p.textContent('#hx-name');
  t('بيعرض اللي اكتشفه (الاسم والمجال)', name.includes('عيادة') && (await p.textContent('#hx-indus')).length > 0, name);
  t('والمعلومات بتظهر واحدة ورا التانية', (await p.$$('#hx-facts li.in')).length >= 1);
  const reg = await p.getAttribute('#hx-reg', 'href'), login = await p.getAttribute('#hx-login', 'href');
  t('«سجّل وكمّل» و«تسجيل الدخول» شايلين توكن التجربة', /[?&]trial=[a-f0-9]{40}/.test(reg) && /[?&]trial=[a-f0-9]{40}/.test(login), reg);
  t('الروبوت بقى «قاعد» والفقاعة اتغيّرت', (await p.getAttribute('#h-bot img.on', 'data-b')) === 'sit' && (await p.textContent('#hx-bubble')).includes('لقيت'));
  if (shots) await p.screenshot({ path: path.join(shots, 'hx-m-2.png') });
  await Promise.all([p.waitForURL(/register\.php/), p.click('#hx-reg')]);
  await p.fill('input[name="name"]', 'منى تجربة');
  await p.fill('input[name="email"]', email);
  await p.fill('input[name="phone"]', '010' + String(Math.floor(1e7 + Math.random() * 9e7)));
  await p.fill('input[name="password"]', 'Test12345!');
  await p.fill('input[name="password_confirm"]', 'Test12345!');
  for (const cb of await p.$$('form input[type="checkbox"][required]')) await cb.check();
  await Promise.all([p.waitForNavigation(), p.click('form button[type="submit"]')]);
  if (/login\.php/.test(p.url())) {
    // البداية السريعة مقفولة: التسجيل ← صفحة الدخول (التوكن معاه) ← بعد تفعيل الإيميل الدخول بيحفظ المعلومات في الهوية
    t('بعد التسجيل ← صفحة الدخول والتوكن لسه معاه', /[?&]trial=[a-f0-9]{40}/.test(p.url()), p.url());
    helper('verify', email);
    await p.fill('input[name="email"]', email);
    await p.fill('input[name="password"]', 'Test12345!');
    await Promise.all([p.waitForNavigation(), p.click('form button[type="submit"]')]);
  }
  const r = JSON.parse(helper('check', email));
  t('بعد التسجيل: اسم النشاط والمجال اتحفظوا في الهوية', r.business_name === biz && r.industry === 'عيادة أسنان', JSON.stringify(r));
  t('والتجربة اتربطت بالحساب', r.claimed === true, JSON.stringify(r));
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
