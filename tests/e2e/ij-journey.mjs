// Spread AI — E2E رحلة الهوية الجديدة (start.php): اللينك ← ده اللي لقيناه ← الأسئلة ← اللوجو ← الألوان ← الخدمات ← العملاء
// ← الأسلوب ← الاستايل ← التواصل ← الأشخاص ← اللينكات ← الملخص ← ميتا ← عرض البداية ← أول منشور
// للتطوير المحلي بس:  node tests/e2e/ij-journey.mjs http://localhost:8080 [مجلد الصور] [ar|en]
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';
import path from 'path';
const require = createRequire((process.env.PLAYWRIGHT_NODE_PATH || '/opt/node22/lib/node_modules') + '/');
const { chromium, devices } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const base = (process.argv[2] || 'http://localhost:8080').replace(/\/$/, '');
const shots = process.argv[3] || '';
const lang = process.argv[4] || 'ar';
const helper = (...a) => execFileSync('php', [path.join(root, 'tests/e2e/ij-helper.php'), ...a], { encoding: 'utf8' }).trim();
const tag = Math.random().toString(16).slice(2, 8);
const email = `ij-${tag}@test.local`;
const img = path.join(root, 'assets/img/spread-mark.png');
const AR = /[؀-ۿ]/;
let pass = 0, fail = 0, n = 0;
const t = (name, ok, why = '') => { ok ? pass++ : fail++; console.log((ok ? '  ✓ ' : '  ✗ ') + name + (ok || !why ? '' : ' — ' + why)); };

helper('make', email);
const b = await chromium.launch();
const errors = [];
let pg = null;
try {
  const ctx = await b.newContext({ ...devices['iPhone 13'] });
  await ctx.addCookies([{ name: 'spread_lang', value: lang, url: base }]);
  const p = await ctx.newPage();
  pg = p;
  p.on('pageerror', (e) => errors.push(String(e)));
  const shot = async (name) => { if (shots) await p.screenshot({ path: path.join(shots, `ij-${lang}-${String(++n).padStart(2, '0')}-${name}.png`), fullPage: true }); };
  const step = async (id) => { await p.waitForSelector(`#${id}:not([hidden])`, { timeout: 90000 }); await p.waitForTimeout(250); };
  const noScroll = async () => !(await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1));

  await p.goto(base + '/public/login.php', { waitUntil: 'networkidle' });
  await p.fill('input[name="email"]', email);
  await p.fill('input[name="password"]', 'Test12345!');
  await Promise.all([p.waitForNavigation(), p.click('form button[type="submit"]')]);
  if (!/start\.php/.test(p.url())) await p.goto(base + '/public/start.php', { waitUntil: 'networkidle' });
  t('بعد الدخول ← رحلة الهوية', /start\.php/.test(p.url()), p.url());
  await step('s-link');
  t('من غير سكرول أفقي', await noScroll());
  if (lang === 'en') t('الشاشة الأولى بالإنجليزي', !AR.test((await p.textContent('#s-link')).replace('Mona', '')), await p.textContent('#s-link h2'));
  await shot('link');

  await p.fill('#f-name', 'عيادة الابتسامة ' + tag);
  await p.fill('#f-url', 'https://www.facebook.com/ij-test-' + tag);
  await p.click('#b-link');
  await step('s-found');
  t('«ده اللي لقيناه»: الاسم متعبي', (await p.inputValue('#g-name')).includes(tag));
  await p.fill('#g-desc', 'عيادة أسنان في مدينة نصر لكل العيلة');
  const opts = await p.$$eval('#g-sector option', (o) => o.map((x) => x.value).filter(Boolean));
  if (opts.length) await p.selectOption('#g-sector', opts[0]);
  await shot('found');
  await p.click('[data-next="found"]');

  // أسئلة الأدمن (من غير المجال والأسلوب)
  await p.waitForSelector('#s-qs:not([hidden]), #s-logo:not([hidden])', { timeout: 90000 });
  let qs = 0;
  while (await p.isVisible('#s-qs')) {
    const title = await p.textContent('#q-title');
    t(`سؤال الأدمن «${title}» مش المجال ولا الأسلوب`, !/نشاطك إيه|بأنهي أسلوب|What is your business|Which style/i.test(title));
    if (await p.isVisible('#q-text')) await p.fill('#q-text', 'تبييض الأسنان');
    else await p.click('#q-opts .onb-opt');
    if (qs === 0) await shot('question');
    await p.click('#q-next'); qs++;
    await p.waitForTimeout(300);
    if (qs > 12) break;
  }
  t('الأسئلة اتعرضت وخلصت', qs >= 1, String(qs));

  await step('s-logo');
  await p.setInputFiles('#logo-file', img);
  await shot('logo');
  await p.click('[data-next="logo"]');

  await step('s-colors');
  const pals = await p.$$('#pals .onb-pal');
  t('الألوان: من 2 لـ 4 اختيارات', pals.length >= 2 && pals.length <= 4, String(pals.length));
  if (pals.length > 1) await pals[1].click();
  await shot('colors');
  await p.click('[data-next="colors"]');

  await step('s-services');
  const svc = await p.$$('#svc .onb-opt');
  t('الخدمات متخمّنة', svc.length >= 1, String(svc.length));
  await p.fill('#svc-new', 'زراعة أسنان ' + tag);
  await p.click('#svc-add');
  await shot('services');
  await p.click('[data-next="services"]');

  await step('s-personas');
  const pers = await p.$$('#pers .onb-per');
  t('العملاء المثاليين: كروت ✓/✗', pers.length >= 1, String(pers.length));
  if (pers.length > 1) await p.click('#pers .onb-per:last-child [data-no]');
  await shot('personas');
  await p.click('[data-next="personas"]');

  await step('s-voice');
  const voices = await p.$$('#voices .onb-opt');
  t('الأسلوب: 3 اختيارات على الأقل', voices.length >= 3, String(voices.length));
  await voices[voices.length - 2].click();   // «ودود وبسيط»
  await shot('voice');
  await p.click('[data-next="voice"]');

  await step('s-style');
  t('الاستايل: 6 كروت', (await p.$$('#styles .onb-cardopt')).length === 6);
  await p.click('#styles .onb-cardopt:nth-child(4)');
  await p.setInputFiles('#insp-file', img);
  await shot('style');
  await p.click('[data-next="style"]');

  await step('s-contact');
  await p.fill('#c-address', 'مدينة نصر، القاهرة');
  await p.fill('#c-phones', '01000000000');
  await p.fill('#c-whatsapp', '01000000001');
  await p.fill('#c-working_hours', 'كل يوم 10 ص – 10 م');
  await shot('contact');
  await p.click('[data-next="contact"]');

  await step('s-people');
  await p.click('#p-add');
  t('صورة شخص من غير اسم ← رسالة', await p.isVisible('#msg'));
  await p.fill('#p-name', 'د. أحمد');
  await p.fill('#p-role', 'صاحب العيادة');
  await p.setInputFiles('#p-file', img);
  await p.click('#p-add');
  t('من غير موافقة ← رسالة', (await p.textContent('#msg')).length > 3);
  await p.check('#p-consent');
  await p.click('#p-add');
  await p.waitForFunction(() => document.querySelector('#people-list').textContent.includes('أحمد'), null, { timeout: 30000 });
  t('الشخص اتضاف', true);
  await shot('people');
  await p.click('[data-next="people"]');

  await step('s-links');
  await p.fill('#l-social_instagram', 'instagram.com/ij' + tag);
  await p.click('[data-next="links"]');

  await step('s-summary');
  const sum = await p.textContent('#id-card');
  t('الملخص فيه التواصل والمواعيد والشخص', sum.includes('مدينة نصر') && sum.includes('10 ص') && sum.includes('أحمد'), sum.slice(0, 200));
  await shot('summary');
  await p.click('[data-next="summary"]');

  await p.waitForSelector('#s-meta:not([hidden]), #s-offer:not([hidden])', { timeout: 30000 });
  if (await p.isVisible('#s-meta')) { await shot('meta'); await p.click('[data-next="meta"]'); }
  await step('s-offer');
  const offer = await p.textContent('#s-offer');
  t('عرض البداية: 10 محتوى + 4 تصميم + 14 يوم', /10/.test(offer) && /4/.test(offer) && /14/.test(offer), offer.replace(/\s+/g, ' ').slice(0, 160));
  await shot('offer');
  await p.click('[data-next="offer"]');
  await p.waitForSelector('#post-card:not([hidden]), #post-fail:not([hidden])', { timeout: 120000 });
  t('أول منشور اتكتب', await p.isVisible('#post-card'), await p.textContent('#post-fail-t').catch(() => ''));
  await shot('post');

  const r = JSON.parse(helper('check', email));
  t('الهوية اتعلّمت «خلصت» واتعتمدت', r.done && r.approved, JSON.stringify(r).slice(0, 200));
  t('الوصف والتواصل والمواعيد اتحفظوا', r.description.includes('مدينة نصر') && r.address === 'مدينة نصر، القاهرة' && r.whatsapp === '01000000001' && r.hours.includes('10'));
  t('اللوجو واللينكات اتحفظوا', r.logo && /instagram\.com\/ij/.test(r.instagram || ''));
  t('الخدمة المضافة اتحفظت', r.services.some((s) => s.includes(tag)), r.services.join(', '));
  t('العملاء المثاليين اتحفظوا (والمرفوض اتشال)', r.personas >= 1 && (pers.length < 2 || r.personas === pers.length - 1), `${r.personas} / ${pers.length}`);
  t('الشخص بموافقة وصورته', r.characters.includes('د. أحمد') && r.char_images === 1);
  t('التصميم اللي عجبه اتحفظ', r.insp === 1);
  t('الاستايل والأسلوب اتحفظوا', /trustworthy/.test(r.style) && /نساعدك|help you/.test(r.voice), r.style + ' | ' + r.voice);
  t('عرض البداية شغال', r.trial.state === 'active' && r.trial.contents.total === 10 && r.trial.designs.total === 4 && r.trial.days_left === 14, JSON.stringify(r.trial));

  await p.goto(base + '/public/dashboard.php', { waitUntil: 'networkidle' });
  t('الرئيسية: مابترجعش للرحلة', /dashboard\.php/.test(p.url()));
  t('الرئيسية: شريط عرض البداية', await p.isVisible('.dh-trial[data-trial="active"]'));
  await shot('dashboard');
  await p.goto(base + '/public/journey.php', { waitUntil: 'networkidle' });
  t('«رحلتك الأولى» القديمة ← الرئيسية', /dashboard\.php/.test(p.url()), p.url());
  t('من غير أخطاء JavaScript', errors.length === 0, errors.join(' | '));
  await ctx.close();
} catch (e) {
  t('الرحلة كملت', false, String(e).slice(0, 400));
  if (pg) { console.log('  · الشاشة الظاهرة:', await pg.evaluate(() => [...document.querySelectorAll('[data-step]')].filter((x) => !x.hidden).map((x) => x.id).join(',') + ' · ' + (document.getElementById('msg').hidden ? '' : document.getElementById('msg').textContent))); if (shots) await pg.screenshot({ path: path.join(shots, `ij-${lang}-fail.png`), fullPage: true }); }
} finally {
  await b.close();
  if (!process.env.KEEP) helper('clean', email);
}
console.log('\n' + (fail ? `✗ ${fail} فشل · ` : '✓ ') + `${pass} نجح`);
process.exit(fail ? 1 : 0);
