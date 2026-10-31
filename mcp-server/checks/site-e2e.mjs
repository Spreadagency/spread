/**
 * Spread AI — اختبار الموقع كامل بـ Playwright (MCP المرحلة 8)
 * بيفتح الصفحات العامة + صفحات العميل (كمبيوتر وموبايل) + كل صفحات الأدمن من القايمة،
 * وبيتأكد: الصفحة فتحت (أقل من 400) · مفيش أخطاء JavaScript · مفيش «Fatal/Warning» · مفيش سكرول أفقي على الموبايل.
 * النتيجة: ملف JSON ← من الـ VPS: node scripts/push-checks.mjs e2e e2e-results.json (أو على السيرفر: php tests/e2e/record.php) ← تظهر في «الأمان ← اختبارات الموقع» ولـ Claude.
 *
 *   npm i -D playwright && npx playwright install chromium        (مرة واحدة)
 *   E2E_BASE=https://staging.ai.spreadagency.net \
 *   E2E_USER=test@… E2E_PASS=… E2E_ADMIN=… E2E_ADMIN_PASS=… node checks/site-e2e.mjs
 *
 * ⚠️ شغّله على نسخة Staging أو بحساب اختبار — بيفتح صفحات بس ومابيضغطش أزرار حفظ أو دفع.
 */
import { writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';

let pw;
try { pw = await import('playwright'); } catch {
  pw = createRequire((process.env.PLAYWRIGHT_NODE_PATH || '/usr/lib/node_modules') + '/')('playwright');
}
const { chromium } = pw;
const BASE = (process.env.E2E_BASE || 'http://localhost:8080').replace(/\/+$/, '');
const OUT = process.env.E2E_OUT || 'e2e-results.json';
const results = [];
const now = () => Date.now();

async function check(flow, page, url, opts = {}) {
  const t0 = now();
  const errs = [];
  const onErr = e => errs.push('JS: ' + e.message);
  page.on('pageerror', onErr);
  let error = null;
  try {
    const r = await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(opts.wait ?? 600);
    const code = r ? r.status() : 0;
    if (code >= 400 || code === 0) error = 'HTTP ' + code;
    const body = await page.content();
    if (!error && /(Fatal error|Warning:|Parse error|Uncaught )/.test(body)) error = 'خطأ PHP ظاهر في الصفحة';
    if (!error && opts.mobile) {
      const sw = await page.evaluate(() => document.documentElement.scrollWidth);
      if (sw > (page.viewportSize()?.width ?? 390) + 2) error = 'سكرول أفقي على الموبايل (' + sw + 'px)';
    }
    if (!error && opts.expect && !body.includes(opts.expect)) error = 'العنصر المتوقع مش موجود: ' + opts.expect;
    if (!error && errs.length) error = errs[0];
  } catch (e) {
    error = String(e.message || e).slice(0, 300);
  }
  page.off('pageerror', onErr);
  results.push({ flow, status: error ? 'failed' : 'passed', ms: now() - t0, error });
  console.log((error ? '  ✗ ' : '  ✓ ') + flow + (error ? ' — ' + error : ''));
}

async function login(page, path, email, pass) {
  await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', pass);
  await Promise.all([page.waitForNavigation({ timeout: 30000 }).catch(() => {}), page.click('button[type=submit]')]);
  return !/login/.test(page.url());
}

const b = await chromium.launch();
try {
  console.log('الصفحات العامة');
  const p0 = await b.newPage();
  for (const u of ['/', '/login.php', '/register.php', '/forgot-password.php']) await check('public:' + u, p0, u);
  await p0.close();

  if (process.env.E2E_USER && process.env.E2E_PASS) {
    console.log('العميل');
    for (const [w, h, tag] of [[1280, 900, 'desktop'], [390, 844, 'mobile']]) {
      const ctx = await b.newContext({ viewport: { width: w, height: h } });
      const p = await ctx.newPage();
      const ok = await login(p, '/login.php', process.env.E2E_USER, process.env.E2E_PASS);
      results.push({ flow: `client:${tag}:login`, status: ok ? 'passed' : 'failed', ms: 0, error: ok ? null : 'الدخول فشل' });
      if (ok) {
        for (const u of ['/dashboard.php', '/content-history.php', '/engine.php', '/my-plan.php', '/brand-brain.php', '/create-content.php', '/payments.php', '/research.php', '/design-studio.php', '/notifications.php', '/profile.php']) {
          await check(`client:${tag}:${u}`, p, u, { mobile: tag === 'mobile', expect: u === '/dashboard.php' ? 'sa-root' : undefined, wait: 900 });
        }
      }
      await ctx.close();
    }
  }

  if (process.env.E2E_ADMIN && process.env.E2E_ADMIN_PASS) {
    console.log('الأدمن');
    const p = await b.newPage();
    if (await login(p, '/admin/login.php', process.env.E2E_ADMIN, process.env.E2E_ADMIN_PASS)) {
      const links = await p.evaluate(() => [...new Set([...document.querySelectorAll('.sidebar a[href*="admin/"], nav a[href*="admin/"]')].map(a => a.getAttribute('href')))]);
      const extra = ['admin/ai-changes.php', 'admin/ai-ab.php', 'admin/ai-security.php', 'admin/profitability.php', 'admin/assistants.php'];
      for (const href of [...new Set([...links, ...extra])]) {
        const u = new URL(href, BASE + '/').pathname + new URL(href, BASE + '/').search;
        if (/logout/.test(u)) continue;
        await check('admin:' + u.replace(/^.*\/admin\//, ''), p, u);
      }
    } else results.push({ flow: 'admin:login', status: 'failed', ms: 0, error: 'دخول الأدمن فشل' });
    await p.close();
  }
} finally {
  await b.close();
}
writeFileSync(OUT, JSON.stringify({ base: BASE, run_at: new Date().toISOString(), results }, null, 2));
const failed = results.filter(r => r.status === 'failed').length;
console.log(`\n${results.length - failed} ✓ · ${failed} ✗ — النتيجة في ${OUT}`);
process.exit(failed ? 1 : 0);
