// Captures live-system evidence for the OTP negative-path row.
//   node scripts/capture-otp-evidence.cjs challenge   # OTP enabled: employee flow
//   node scripts/capture-otp-evidence.cjs admin        # OTP disabled: view audit log
// Output: storage/app/otp-evidence/system/*.png

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.BASE || 'http://127.0.0.1:8000';
const OUT = path.resolve(__dirname, '../storage/app/otp-evidence/system');
const EMP = { id: 'rrivera@alicia.gov.ph', pw: 'Password!2026' };
const ADMIN = { id: 'superadmin@alicia.gov.ph', pw: 'Password!2026' };
const phase = process.argv[2] || 'challenge';

fs.mkdirSync(OUT, { recursive: true });
const shot = (page, name) => page.screenshot({ path: path.join(OUT, name + '.png'), fullPage: true });

async function submitLogin(page, who) {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  await page.fill('input[name="identifier"]', who.id);
  await page.fill('input[name="password"]', who.pw);
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')]);
}

async function submitCode(page, code) {
  await page.fill('#code', code);
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')]);
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  if (phase === 'challenge') {
    // Valid credentials → the system demands the OTP second factor.
    await submitLogin(page, EMP);
    await shot(page, '01-otp-challenge');          // the OTP screen (access not yet granted)

    // Incorrect code → rejected with an error.
    await submitCode(page, '999999');
    await shot(page, '02-otp-incorrect-rejected');

    // A couple more wrong codes, to populate the audit trail.
    await submitCode(page, '888888');
    await submitCode(page, '777777');

    // Omitted code → trying to reach the dashboard bounces back to /otp.
    await page.goto(BASE + '/dashboard', { waitUntil: 'networkidle' });
    await shot(page, '03-otp-omitted-denied');     // URL is back at /otp — access denied
    console.log('challenge phase done; current URL:', page.url());
  } else {
    // OTP has been disabled for this phase so the admin can open the audit log.
    await submitLogin(page, ADMIN);
    await page.goto(BASE + '/audit-logs', { waitUntil: 'networkidle' });
    await shot(page, '04-audit-otp-failed');       // otp_failed rows from the attempts above
    console.log('admin phase done; current URL:', page.url());
  }

  await browser.close();
  for (const f of fs.readdirSync(OUT).sort()) console.log('  ', f);
})().catch((e) => { console.error(e.message); process.exit(1); });
