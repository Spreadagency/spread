// Spread AI — E2E «ابدأ» (خريطة التطوير · المرحلة 1): من التسجيل لأول بوست محفوظ على مقاس موبايل
// للتطوير المحلي بس:
//   PLAYWRIGHT_NODE_PATH=/opt/node22/lib/node_modules node tests/e2e/ob1-start.mjs http://localhost:8080 [مجلد الصور]
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';
import path from 'path';
const require = createRequire((process.env.PLAYWRIGHT_NODE_PATH || '/opt/node22/lib/node_modules') + '/');
const { chromium, devices } = require('playwright');

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const base = (process.argv[2] || 'http://localhost:8080').replace(/\/$/, '');
const shots = process.argv[3] || '';
// كل تعامل مع قاعدة البيانات من tests/e2e/ob1-helper.php (بيرفض يشتغل لو APP_DEBUG مقفول)
const helper = (...args) => execFileSync('php', [path.join(root, 'tests/e2e/ob1-helper.php'), ...args], { encoding: 'utf8' }).trim();
const tag = Math.random().toString(16).slice(2, 8);
const email = `ob1e-${tag}@test.local`;
let pass = 0, fail = 0;
const t = (name, ok, why = '') => { ok ? pass++ : fail++; console.log((ok ? '  ✓ ' : '  ✗ ') + name + (ok || !why ? '' : ' — ' + why)); };

const saved = helper('on');

const browser = await chromium.launch();
const ctx = await browser.newContext({ ...devices['iPhone 13'] });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
const shot = async (n) => { if (shots) await page.screenshot({ path: path.join(shots, n + '.png'), fullPage: true }); };
const t0 = Date.now();
try {
    await page.goto(base + '/public/register.php');
    await page.fill('input[name="name"]', 'منى تجربة');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="phone"]', '010' + String(Math.floor(1e7 + Math.random() * 9e7)));
    await page.fill('input[name="password"]', 'Test12345!');
    await page.fill('input[name="password_confirm"]', 'Test12345!');
    for (const cb of await page.$$('form input[type="checkbox"][required]')) await cb.check();
    await Promise.all([page.waitForURL(/start\.php/, { timeout: 20000 }), page.click('form button[type="submit"]')]);
    t('التسجيل ← صفحة «ابدأ» مباشرة', /start\.php/.test(page.url()), page.url());

    // 1) لينكاتك
    await page.waitForSelector('#s1:not([hidden])');
    const overflow1 = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    t('الشاشة 1 من غير سكرول أفقي على الموبايل', !overflow1);
    await page.click('#b1');
    t('الاسم الفاضي بيتمنع برسالة', await page.isVisible('#msg'));
    await page.fill('#f-name', 'عيادة منى للأسنان');
    await shot('1-links');
    await page.click('#b1');

    // 2) الأسئلة
    await page.waitForSelector('#s2:not([hidden])', { timeout: 60000 });
    t('الشاشة 2: سؤال 1 من 5', (await page.textContent('#q-count')).includes('1 من 5'));
    const firstSector = await page.$('#q-opts .onb-opt');
    if (firstSector) await firstSector.click();
    await shot('2-questions');
    await page.click('#q-next');
    await page.click('#q-opts .onb-opt >> nth=3');
    await page.click('#q-next');
    await page.fill('#q-text', 'تبييض الأسنان');
    await page.click('#q-next');
    await page.click('#q-opts .onb-opt >> nth=1');
    await page.click('#q-next');
    await page.click('#q-skip');   // آخر سؤال: تخطّي ← البناء

    // 3) الهوية + أول بوست
    await page.waitForSelector('#s3:not([hidden])', { timeout: 120000 });
    t('الشاشة 3: كارت الهوية مليان', (await page.$$('#id-card dt')).length >= 2);
    await page.waitForSelector('#post-card:not([hidden]), #post-fail:not([hidden])', { timeout: 120000 });
    const postOk = await page.isVisible('#post-card');
    t('أول بوست ظاهر', postOk, postOk ? '' : await page.textContent('#post-fail-t'));
    const secs = Math.round((Date.now() - t0) / 1000);
    t(`من التسجيل لأول بوست في 3 شاشات (${secs} ثانية في الاختبار)`, postOk);
    const overflow3 = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    t('الشاشة 3 من غير سكرول أفقي', !overflow3);
    await shot('3-identity-post');
    const n = helper('count', email);
    t('البوست محفوظ في الحساب', n === '1', n);
    if (postOk) {
        await Promise.all([page.waitForLoadState('load'), page.click('#post-open')]);
        t('«افتح البوست» بيفتح صفحة المنشور', /content-view\.php\?id=\d+/.test(page.url()), page.url());
    }
    t('من غير أخطاء JavaScript', errors.length === 0, errors.join(' | '));
} catch (e) {
    t('الرحلة كملت', false, String(e).slice(0, 300));
    await shot('error');
} finally {
    await browser.close();
    helper('off', saved, email);
}
console.log('\n' + (fail ? `✗ ${fail} فشل · ` : '✓ ') + `${pass} نجح`);
process.exit(fail ? 1 : 0);
