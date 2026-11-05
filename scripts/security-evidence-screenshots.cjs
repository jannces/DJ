// Renders the evidence report to PNG figures for the thesis appendix.
//
//   node scripts/security-evidence-screenshots.js
//
// Produces, under storage/app/security-evidence/images/:
//   report-full.png   the whole report (full-page)
//   report-summary.png the header + results table (above the fold)
//
// Uses the Chromium that ships with this environment; no install step needed.

const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

(async () => {
  // Which evidence folder to screenshot (default the security matrix). Pass a
  // folder name as the first argument, e.g. `node ... input-validation-evidence`.
  const ns = (process.argv[2] || 'security-evidence').replace(/[^a-z0-9-]/gi, '');
  const base = path.resolve(__dirname, '../storage/app/' + ns);
  const reportPath = path.join(base, 'report.html');
  if (!fs.existsSync(reportPath)) {
    console.error('report.html not found — run php scripts/security-evidence-report.php first');
    process.exit(1);
  }

  const outDir = path.join(base, 'images');
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1000, height: 900 }, deviceScaleFactor: 2 });
  await page.goto('file://' + reportPath, { waitUntil: 'networkidle' });

  await page.screenshot({ path: path.join(outDir, 'report-full.png'), fullPage: true });
  await page.screenshot({ path: path.join(outDir, 'report-summary.png'), clip: { x: 0, y: 0, width: 1000, height: 820 } });

  await browser.close();
  console.log('Wrote', path.join(outDir, 'report-full.png'));
  console.log('Wrote', path.join(outDir, 'report-summary.png'));
})();
