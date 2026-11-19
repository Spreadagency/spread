// Spread AI — E2E: التنقّل بين مراحل الحملة (وضع «الكتابة والتقييم في طلب واحد»):
// المحتوى ← «قيّم الخطة» ← «التالي» للتصميم ← «السابق» للمحتوى ← «التالي» تاني · ورسالة سبب المرحلة المقفولة
// للتطوير المحلي بس:  node tests/e2e/cf-nav.mjs http://localhost:8080 [مجلد الصور]
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';
import path from 'path';
const require = createRequire((process.env.PLAYWRIGHT_NODE_PATH || '/opt/node22/lib/node_modules') + '/');
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const base = (process.argv[2] || 'http://localhost:8080').replace(/\/$/, '');
const shots = process.argv[3] || '';
const helper = (...a) => execFileSync('php', [path.join(root, 'tests/e2e/cf-helper.php'), ...a], { encoding: 'utf8' }).trim();
const email = `cf-${Math.random().toString(16).slice(2, 8)}@test.local`;
let pass = 0, fail = 0;
const t = (n, ok, why = '') => { ok ? pass++ : fail++; console.log((ok ? '  ✓ ' : '  ✗ ') + n + (ok || !why ? '' : ' — ' + why)); };

const cid = helper('make', email);
const b = await chromium.launch();
const errors = [];
try {
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errors.push(String(e)));
  await p.goto(base + '/public/login.php?ui=v2');
  await p.fill('input[name="email"]', email);
  await p.fill('input[name="password"]', 'Test12345!');
  await Promise.all([p.waitForNavigation(), p.click('form button[type="submit"]')]);
  await p.goto(base + '/public/campaign.php?id=' + cid, { waitUntil: 'networkidle' });
  const steps = async () => p.$$eval('.cf-steps li', (ls) => ls.map((l) => (l.classList.contains('on') ? '◐' : l.classList.contains('done') ? '✓' : '○') + (l.classList.contains('locked') ? '🔒' : '')).join(' '));
  t('واقف على «المحتوى» (5 مراحل · التصميم مفتوح كخطوة جاية)', (await steps()) === '✓ ◐ ○ ○🔒 ○🔒', await steps());
  t('«تقييم الخطة كاملة» ظاهر في «المحتوى» (من غير مرحلة تقييم منفصلة)', await p.isVisible('.cf-plan-eval'));
  await p.click('.cf-plan-eval button.cf-btn');
  await p.waitForSelector('.cf-plan-eval .cf-ring', { timeout: 60000 });
  t('التقييم اشتغل وطلع درجة', !!(await p.textContent('.cf-plan-eval .cf-ring b')).trim());
  if (shots) await p.screenshot({ path: path.join(shots, 'cf-plan-eval.png'), fullPage: true });
  // «التالي» ← التصميم (كان بيترفض: 4 > 2 + 1)
  await p.click('.cf-foot .cf-btn.primary, footer .cf-btn.primary', { timeout: 5000 }).catch(async () => { await p.click('button:has-text("اعتماد المحتوى والتصميم")'); });
  await p.waitForFunction(() => document.querySelector('.cf-steps li.on b')?.textContent.includes('التصميم'), null, { timeout: 30000 }).catch(() => {});
  t('«التالي» من المحتوى ← التصميم', (await p.textContent('.cf-steps li.on b')).includes('التصميم'), await p.textContent('.cf-steps li.on b'));
  // «السابق» ← المحتوى، وبعدين «التالي» تاني
  await p.click('button:has-text("السابق")');
  await p.waitForFunction(() => document.querySelector('.cf-steps li.on b')?.textContent.includes('المحتوى'), null, { timeout: 30000 }).catch(() => {});
  t('«السابق» من التصميم ← المحتوى', (await p.textContent('.cf-steps li.on b')).includes('المحتوى'), await p.textContent('.cf-steps li.on b'));
  t('تقييم الخطة لسه محفوظ بعد الرجوع', await p.isVisible('.cf-plan-eval .cf-ring'));
  await p.click('.cf-steps li:nth-child(3) button');   // يدوس على «التصميم» من السايدبار
  await p.waitForFunction(() => document.querySelector('.cf-steps li.on b')?.textContent.includes('التصميم'), null, { timeout: 30000 }).catch(() => {});
  t('الرجوع للتصميم من السايدبار', (await p.textContent('.cf-steps li.on b')).includes('التصميم'));
  await p.click('.cf-steps li:nth-child(5) button', { force: true });   // «النشر» لسه مقفولة
  await p.waitForTimeout(600);
  const toast = await p.evaluate(() => [...document.querySelectorAll('[class*=toast]')].map((x) => x.textContent.trim()).filter(Boolean).pop() || '');
  t('مرحلة بعيدة مقفولة ← رسالة بالسبب', toast.includes('الأول'), toast);
  const r = JSON.parse(helper('check', email));
  t('السيرفر: المرحلة والتقييم اتحفظوا', +r.stage === 4 && +r.max_stage === 4 && +r.evaluated === 1, JSON.stringify(r));
  t('من غير أخطاء JavaScript', errors.length === 0, errors.join(' | '));
  if (shots) await p.screenshot({ path: path.join(shots, 'cf-design.png') });
  await ctx.close();
} catch (e) {
  t('الاختبار كمل', false, String(e).slice(0, 300));
} finally {
  await b.close();
  helper('clean', email);
}
console.log('\n' + (fail ? `✗ ${fail} فشل · ` : '✓ ') + `${pass} نجح`);
process.exit(fail ? 1 : 0);
