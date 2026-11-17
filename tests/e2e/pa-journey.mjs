// Spread AI — E2E: رحلة «ابدأ» بتقترح اللوجو والتصميمات من الصفحة المربوطة بميتا (Graph وهمي على 8093)
// للتطوير المحلي بس:  node tests/e2e/pa-journey.mjs http://localhost:8080 [مجلد الصور]
import { createRequire } from 'module';
import { execFileSync, spawn } from 'child_process';
import { fileURLToPath } from 'url';
import path from 'path';
const require = createRequire((process.env.PLAYWRIGHT_NODE_PATH || '/opt/node22/lib/node_modules') + '/');
const { chromium, devices } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const base = (process.argv[2] || 'http://localhost:8080').replace(/\/$/, '');
const shots = process.argv[3] || '';
const helper = (...a) => execFileSync('php', [path.join(root, 'tests/e2e/pa-helper.php'), ...a], { encoding: 'utf8' }).trim();
const email = `pa-${Math.random().toString(16).slice(2, 8)}@test.local`;
let pass = 0, fail = 0;
const t = (n, ok, why = '') => { ok ? pass++ : fail++; console.log((ok ? '  ✓ ' : '  ✗ ') + n + (ok || !why ? '' : ' — ' + why)); };

const graph = spawn('php', ['-S', '127.0.0.1:8093', path.join(root, 'tests/mock-graph.php')], { stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 500));
helper('make', email, base);
const b = await chromium.launch();
const errors = [];
try {
  const ctx = await b.newContext({ ...devices['iPhone 13'] });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errors.push(String(e)));
  const step = async (id) => { await p.waitForSelector(`#${id}:not([hidden])`, { timeout: 90000 }); await p.waitForTimeout(300); };
  await p.goto(base + '/public/login.php');
  await p.fill('input[name="email"]', email);
  await p.fill('input[name="password"]', 'Test12345!');
  await Promise.all([p.waitForNavigation(), p.click('form button[type="submit"]')]);
  await step('s-link');
  t('أول شاشة فيها «اربط صفحتك» (ونجيب منها اللوجو والتصميمات)', await p.isVisible('#meta-early'));
  await p.fill('#f-name', 'عيادة الصفحة');
  await p.click('#b-link');
  await step('s-found');
  await p.click('[data-next="found"]');
  await p.waitForSelector('#s-qs:not([hidden]), #s-logo:not([hidden])', { timeout: 90000 });
  for (let k = 0; k < 12 && await p.isVisible('#s-qs'); k++) {
    if (await p.isVisible('#q-text')) await p.fill('#q-text', 'تبييض'); else await p.click('#q-opts .onb-opt');
    await p.click('#q-next'); await p.waitForTimeout(250);
  }
  await step('s-logo');
  await p.waitForSelector('#pa-logos button', { timeout: 30000 });
  const logos = await p.$$('#pa-logos button');
  t('شاشة اللوجو: «لقينا دول في صفحتك» + اقتراحين (صورة الصفحة وصورة إنستجرام)', logos.length === 2 && await p.isVisible('#pa-logos-wrap'), String(logos.length));
  t('أول اقتراح متعلّم وظاهر في المعاينة', (await p.$$('#pa-logos button.on')).length === 1 && !!(await p.$('#logo-box img')));
  if (shots) await p.screenshot({ path: path.join(shots, 'pa-logo.png'), fullPage: true });
  await p.click('[data-next="logo"]');
  await step('s-logo');
  t('الصورة ماتحمّلتش من السيرفر ← رسالة «ارفع اللوجو من جهازك» والعميل لسه في نفس الشاشة', (await p.textContent('#msg')).includes('ارفع اللوجو من جهازك'), await p.textContent('#msg'));
  await p.click('#pa-logos button.on');   // يشيل الاختيار ويكمّل من غير لوجو
  t('يقدر يشيل الاختيار', (await p.$$('#pa-logos button.on')).length === 0);
  await p.click('[data-next="logo-skip"]');
  for (const [id, next] of [['s-colors', 'colors'], ['s-services', 'services'], ['s-personas', 'personas'], ['s-voice', 'voice']]) { await step(id); await p.click(`[data-next="${next}"]`); }
  await step('s-style');
  await p.waitForSelector('#pa-imgs button', { timeout: 30000 });
  const imgs = await p.$$('#pa-imgs button');
  t('شاشة الاستايل: صور من صفحته (بوستات فيسبوك + إنستجرام)', imgs.length === 3 && await p.isVisible('#pa-imgs-wrap'), String(imgs.length));
  t('مفيش «اربط صفحتك» تاني (الصفحة مربوطة)', !(await p.isVisible('#meta-style')));
  await imgs[0].click(); await imgs[2].click();
  t('اختار اتنين', (await p.$$('#pa-imgs button.on')).length === 2);
  if (shots) await p.screenshot({ path: path.join(shots, 'pa-style.png'), fullPage: true });
  await p.click('#styles .onb-cardopt:nth-child(1)');
  await p.click('[data-next="style"]');
  await step('s-contact');
  t('بعد الاستايل كمّل للتواصل (والصور اللي مااتحمّلتش رسالتها ظاهرة)', (await p.textContent('#msg')).length > 0, await p.textContent('#msg'));
  t('من غير أخطاء JavaScript', errors.length === 0, errors.join(' | '));
  await ctx.close();
} catch (e) {
  t('الرحلة كملت', false, String(e).slice(0, 300));
} finally {
  await b.close();
  helper('clean', email);
  graph.kill();
}
console.log('\n' + (fail ? `✗ ${fail} فشل · ` : '✓ ') + `${pass} نجح`);
process.exit(fail ? 1 : 0);
