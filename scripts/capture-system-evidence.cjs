// Drives the RUNNING application with a real browser and screenshots the system
// actually performing input validation and recording the events — authentic
// "happening in the system" evidence, not a test report.
//
// Prereq: the app is served at BASE (default http://127.0.0.1:8000) with OTP
// disabled and the demo accounts seeded (see the session that generated this).
//
//   node scripts/capture-system-evidence.cjs
//
// Output: storage/app/system-evidence/images/*.png

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.BASE || 'http://127.0.0.1:8000';
const OUT = path.resolve(__dirname, '../storage/app/system-evidence/images');
const ADMIN = { id: 'superadmin@alicia.gov.ph', pw: 'Password!2026' };
const EMP = { id: 'rrivera@alicia.gov.ph', pw: 'Password!2026' };

fs.mkdirSync(OUT, { recursive: true });
const shot = (page, name) => page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: true });

async function login(page, who) {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="identifier"]', who.id);
  await page.fill('input[name="password"]', who.pw);
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click('button[type="submit"]'),
  ]);
}

(async () => {
  const browser = await chromium.launch();
  const ctxOpts = { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 };
  let ctx = await browser.newContext(ctxOpts);
  let page = await ctx.newPage();

  // ---- 1. Login: empty submit → server-side required-field errors ----------
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.$eval('form', (f) => (f.noValidate = true)); // let the server validate, not the browser
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')]);
  await shot(page, '01-login-empty-rejected');

  // ---- 2. Login: <script> payload → IDS "Access blocked" page --------------
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="identifier"]', '<script>alert(1)</script>');
  await page.fill('input[name="password"]', 'x');
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')]);
  await shot(page, '02-login-xss-blocked');

  // ---- 3. Login: SQL-injection payload → IDS blocked -----------------------
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="identifier"]', "admin' OR 1=1 --");
  await page.fill('input[name="password"]', 'x');
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')]);
  await shot(page, '03-login-sqli-blocked');

  // ---- 4. Employee logs in for real → dashboard ----------------------------
  await login(page, EMP);
  await shot(page, '04-employee-dashboard');

  // ---- 5. Leave application form (real CSC Form 6) --------------------------
  await page.goto(BASE + '/leave/apply', { waitUntil: 'networkidle' });
  await shot(page, '05-leave-form');

  // ---- 5b. Leave form empty submit → validation errors (best effort) -------
  try {
    await page.$eval('form', (f) => (f.noValidate = true));
    const btn = page.locator('button[type="submit"]:visible').first();
    await btn.click({ timeout: 4000 });
    await page.waitForLoadState('networkidle');
    await shot(page, '06-leave-empty-rejected');
  } catch (e) {
    console.log('leave empty-submit skipped (stepped form):', e.message.split('\n')[0]);
  }

  // ---- 6. Admin signs in (fresh context, no logout needed) → dashboard -----
  await ctx.close();
  ctx = await browser.newContext(ctxOpts);
  page = await ctx.newPage();
  await login(page, ADMIN);
  await shot(page, '07-admin-dashboard');

  await page.goto(BASE + '/security', { waitUntil: 'networkidle' });
  await shot(page, '08-security-dashboard');

  // ---- 7. Intrusion log — the xss / sqli events we just triggered ----------
  await page.goto(BASE + '/security/intrusions', { waitUntil: 'networkidle' });
  await shot(page, '09-intrusion-log');

  // ---- 8. Audit log --------------------------------------------------------
  await page.goto(BASE + '/audit-logs', { waitUntil: 'networkidle' });
  await shot(page, '10-audit-log');

  await browser.close();
  console.log('Screenshots written to', OUT);
  for (const f of fs.readdirSync(OUT).sort()) console.log('  ', f);
})().catch((e) => { console.error(e); process.exit(1); });
