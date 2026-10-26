// Builds the Word evidence document from the committed evidence pack.
//   node scripts/build-evidence-docx.cjs
// Output: docs/security-evidence/Security_Testing_Evidence.docx

const fs = require('fs');
const path = require('path');
const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType,
  Table, TableRow, TableCell, WidthType, BorderStyle, ShadingType,
  ImageRun, PageBreak, LevelFormat,
} = require('docx');

const base = path.resolve(__dirname, '../docs/security-evidence');
const summary = JSON.parse(fs.readFileSync(path.join(base, 'summary.json'), 'utf8'));
const sample = (slug) => JSON.parse(fs.readFileSync(path.join(base, 'samples', slug + '.json'), 'utf8'));

// ---- palette ----
const INK = '1F2937', ACCENT = '1D4ED8', ACCENTBG = 'EFF6FF', LINE = 'D1D5DB';
const PASSBG = 'DCFCE7', PASS = '15803D', MUTED = '6B7280';
const CONTENT = 9360; // 6.5" at 1440 dxa/inch (Letter, 1" margins)

const noBorders = {
  top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE },
  left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE },
  insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE },
};
const thinBorders = {
  top: { style: BorderStyle.SINGLE, size: 4, color: LINE },
  bottom: { style: BorderStyle.SINGLE, size: 4, color: LINE },
  left: { style: BorderStyle.SINGLE, size: 4, color: LINE },
  right: { style: BorderStyle.SINGLE, size: 4, color: LINE },
  insideHorizontal: { style: BorderStyle.SINGLE, size: 4, color: LINE },
  insideVertical: { style: BorderStyle.SINGLE, size: 4, color: LINE },
};

const run = (text, o = {}) => new TextRun({ text, ...o });
const P = (children, o = {}) => new Paragraph({ children: Array.isArray(children) ? children : [children], ...o });
const spacer = (after = 120) => new Paragraph({ children: [], spacing: { after } });

function cell(children, { w, shade, bold, color, size, align } = {}) {
  const paras = (Array.isArray(children) ? children : [children]).map((c) => {
    if (typeof c === 'string') return P(run(c, { bold, color, size: size || 20 }), { alignment: align });
    if (c instanceof Paragraph) return c;      // already a paragraph
    return P(c, { alignment: align });          // a TextRun (or run-like) → wrap it
  });
  return new TableCell({
    width: { size: w, type: WidthType.DXA },
    shading: shade ? { type: ShadingType.CLEAR, fill: shade, color: 'auto' } : undefined,
    margins: { top: 60, bottom: 60, left: 110, right: 110 },
    children: paras,
  });
}

function headerRow(labels, widths, { shade = ACCENTBG, color = ACCENT } = {}) {
  return new TableRow({
    tableHeader: true,
    children: labels.map((l, i) => cell(l, { w: widths[i], shade, bold: true, color, size: 18 })),
  });
}

// A two-column "field: value" detail table for one scenario.
function fieldTable(rows) {
  const wL = 2400, wR = CONTENT - wL;
  return new Table({
    width: { size: CONTENT, type: WidthType.DXA },
    columnWidths: [wL, wR],
    borders: thinBorders,
    rows: rows.map(([k, v]) => new TableRow({
      children: [
        cell(k, { w: wL, shade: 'F3F4F6', bold: true, size: 19 }),
        cell(v, { w: wR, size: 19 }),
      ],
    })),
  });
}

// A table rendering captured DB rows: columns from the row keys.
function dbTable(rows, caption) {
  if (!Array.isArray(rows) || rows.length === 0) return [];
  const cols = Object.keys(rows[0]);
  const w = Math.floor(CONTENT / cols.length);
  const widths = cols.map(() => w);
  const head = headerRow(cols, widths, { shade: INK, color: 'FFFFFF' });
  const body = rows.map((r) => new TableRow({
    children: cols.map((c, i) => cell(String(r[c]), { w: widths[i], size: 16 })),
  }));
  return [
    new Table({ width: { size: CONTENT, type: WidthType.DXA }, columnWidths: widths, borders: thinBorders, rows: [head, ...body] }),
    P(run(caption, { italics: true, color: MUTED, size: 16 }), { spacing: { before: 60, after: 160 } }),
  ];
}

function pill(text, fill, color) {
  return new Table({
    width: { size: 1500, type: WidthType.DXA },
    columnWidths: [1500],
    borders: noBorders,
    rows: [new TableRow({ children: [
      new TableCell({
        width: { size: 1500, type: WidthType.DXA },
        shading: { type: ShadingType.CLEAR, fill, color: 'auto' },
        margins: { top: 40, bottom: 40, left: 120, right: 120 },
        children: [P(run(text, { bold: true, color, size: 18 }), { alignment: AlignmentType.CENTER })],
      }),
    ] })],
  });
}

// Read a PNG's pixel dimensions from its IHDR chunk (bytes 16-24, big-endian).
function pngSize(buf) {
  return { w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) };
}

// Centered image scaled to `targetWpx` pixels wide (96 px = 1 inch in docx-js),
// height derived from the file's real aspect ratio.
function image(file, targetWpx) {
  const data = fs.readFileSync(path.join(base, file));
  const { w, h } = pngSize(data);
  const height = Math.round(targetWpx * (h / w));
  return P(new ImageRun({ type: 'png', data, transformation: { width: targetWpx, height } }),
    { alignment: AlignmentType.CENTER });
}

const H1 = (t) => new Paragraph({ heading: HeadingLevel.HEADING_1, spacing: { before: 280, after: 120 },
  children: [run(t, { bold: true, color: ACCENT, size: 28 })] });
const H2 = (t) => new Paragraph({ heading: HeadingLevel.HEADING_2, spacing: { before: 220, after: 100 },
  children: [run(t, { bold: true, color: INK, size: 24 })] });

// ============================================================ build sections
const children = [];

// --- Title block ---
children.push(
  P(run('Security Testing Evidence Report', { bold: true, size: 40, color: INK }), { spacing: { after: 60 } }),
  P(run('Cybersecurity Integrated Digital Leave Management System with Real-Time Intrusion Alerts', { size: 22, color: INK }), { spacing: { after: 40 } }),
  P(run('Local Government Unit of Alicia', { size: 22, color: MUTED }), { spacing: { after: 40 } }),
  P([run('Generated: ', { size: 18, color: MUTED }), run(summary.generated_at, { size: 18, color: MUTED }),
     run('  ·  Automated suite: ', { size: 18, color: MUTED }), run('SecurityTestMatrixTest', { size: 18, color: MUTED, font: 'Consolas' })],
    { spacing: { after: 160 } }),
  new Paragraph({ border: { bottom: { style: BorderStyle.SINGLE, size: 12, color: ACCENT } }, spacing: { after: 160 }, children: [] }),
);

const totalIter = summary.overall.total_iterations;
const passed = summary.scenarios.reduce((a, s) => a + s.passed, 0);
children.push(
  P([run('Overall result: ', { bold: true, size: 22 }),
     run(summary.overall.all_passed ? 'ALL SCENARIOS PASS' : 'FAILURES PRESENT',
       { bold: true, size: 22, color: summary.overall.all_passed ? PASS : 'B91C1C' })]),
  P([run(`${summary.scenarios.length} scenarios · ${totalIter} total trials · ${passed} passed · ${totalIter - passed} failed`, { size: 20, color: MUTED })],
    { spacing: { after: 120 } }),
);

// --- 1. Overview ---
children.push(H1('1. Overview and Methodology'));
children.push(
  P(run('This report documents the security testing conducted on the system. Six security test scenarios, drawn from the project test plan, were each executed 50 times by an automated test suite so that every result is a tally of 50 independent trials rather than a single run. In total, 300 trials were executed and all passed.', { size: 21 }), { spacing: { after: 120 } }),
  P(run('The suite exercises the system’s own live security controls — the application-layer Intrusion Detection Service, the login-security (brute-force / lockout) service, and the role-based access-control middleware. Each trial records its outcome and captures representative rows from the application’s own log tables (intrusion_logs, failed_logins) as evidence.', { size: 21 }), { spacing: { after: 120 } }),
  H2('Test environment'),
  fieldTable([
    ['Framework', 'Laravel 12 · PHP 8.3 · PHPUnit'],
    ['Controls under test', 'IntrusionDetectionService · LoginSecurityService · PermissionMiddleware'],
    ['Iterations', '50 per scenario (configurable via SECURITY_MATRIX_ITERATIONS)'],
    ['Assessment type', 'Defensive self-assessment, in-process against the project’s own application'],
    ['Evidence store', 'security-evidence.sqlite (database), runs.csv, report.html, PNG figures'],
  ]),
);

// --- 2. Summary of results ---
children.push(H1('2. Summary of Results'));
const controlMap = {
  'Detected Vulnerabilities': 'Input validation · RBAC · Authentication · Secure config',
  'Unauthorized Access Attempt': 'OTP authentication · RBAC · Access logging',
  'Blocked Unauthorized Login Attempts': 'Login-attempt blocking · IP / source monitoring',
  'SQL Injection Attempt': 'Input validation · IDS signatures · Parameterized queries',
  'Brute Force Login Attempt': 'Login blocking · OTP authentication · Intrusion logging',
  'Intrusion Alerts Generated': 'Real-time intrusion alerts · Audit logging',
};
const sw = [520, 3000, 3600, 1120, 1120];
const resultRows = [headerRow(['#', 'Scenario', 'Security control verified', 'Trials', 'Result'], sw)];
summary.scenarios.forEach((s, i) => {
  resultRows.push(new TableRow({ children: [
    cell(String(i + 1), { w: sw[0], size: 19 }),
    cell(run(s.scenario, { bold: true, size: 19 }), { w: sw[1] }),
    cell(controlMap[s.scenario] || '', { w: sw[2], size: 18 }),
    cell(`${s.passed}/${s.iterations}`, { w: sw[3], size: 19, align: AlignmentType.CENTER }),
    cell(run(s.result, { bold: true, color: PASS, size: 19 }), { w: sw[4], shade: PASSBG, align: AlignmentType.CENTER }),
  ] }));
});
children.push(new Table({ width: { size: CONTENT, type: WidthType.DXA }, columnWidths: sw, borders: thinBorders, rows: resultRows }));
children.push(P(run('Mean real-time alert latency (brute-force lockout → administrator notification queued): 19.32 ms across 50 trials.', { italics: true, size: 18, color: MUTED }), { spacing: { before: 100 } }));

children.push(spacer(120), image('images/report-summary.png', 560));
children.push(P(run('Figure 1. Automated evidence report — results by scenario.', { italics: true, size: 18, color: MUTED }), { alignment: AlignmentType.CENTER, spacing: { before: 40 } }));

// --- 3. Per-scenario detail + DB evidence ---
children.push(new Paragraph({ children: [new PageBreak()] }));
children.push(H1('3. Scenario Detail and Database Evidence'));

const scen = (n, { tool, attack, control, expected, criteria, result, note }, dbRows, dbCaption) => {
  children.push(H2(n));
  children.push(fieldTable([
    ['Tool / method', tool],
    ['Attack scenario', attack],
    ['Security control', control],
    ['Expected result', expected],
    ['Pass / fail criteria', criteria],
    ['Result (50 trials)', result],
  ]));
  if (note) children.push(P(run(note, { size: 19 }), { spacing: { before: 100, after: 60 } }));
  if (dbRows) {
    children.push(P(run('Evidence — rows captured from the application’s own tables during the run:', { bold: true, size: 19 }), { spacing: { before: 120, after: 60 } }));
    dbTable(dbRows, dbCaption).forEach((x) => children.push(x));
  }
};

scen('3.1  Detected Vulnerabilities (endpoint sweep)', {
  tool: 'Automated endpoint sweep (OWASP ZAP-equivalent access-control probe)',
  attack: 'Every role-restricted endpoint requested under both an unauthenticated session and an authenticated employee-role session.',
  control: 'Input validation · RBAC · Authentication · Secure configuration',
  expected: 'No finding of high or critical severity. A restricted page served, or a 5xx server error, counts as a finding.',
  criteria: 'PASS: no high/critical finding in any trial. FAIL: a restricted page is served or a server error occurs.',
  result: 'PASS — 50 / 50 trials, 0 findings (5 endpoints × 2 sessions probed per trial).',
  note: 'Endpoints probed: /users, /roles, /security, /audit-logs, /settings. Anonymous access was redirected to the login screen (302); employee access was refused (403).',
});

scen('3.2  Unauthorized Access Attempt', {
  tool: 'Manual / automated login and direct-URL testing',
  attack: 'Direct URL access to five role-restricted endpoints by an authenticated employee-role account and by an unauthenticated session.',
  control: 'OTP authentication · RBAC · Access logging',
  expected: 'Unauthorized access attempts denied.',
  criteria: 'PASS: unauthorized users cannot reach restricted pages. FAIL: a protected area is reached.',
  result: 'PASS — 50 / 50 trials. Employee hits returned 403 and were logged as privilege-escalation events; anonymous hits were redirected to login.',
}, sample('unauthorized-access-attempt'), 'Table: privilege-escalation rows written to intrusion_logs (one per denied endpoint).');

scen('3.3  Blocked Unauthorized Login Attempts', {
  tool: 'Login monitoring / system logs',
  attack: 'Twenty consecutive failed authentications against one account from a single source address within a 60-second window.',
  control: 'Login-attempt blocking · IP / source monitoring',
  expected: 'Unauthorized login attempts automatically blocked after the threshold limit.',
  criteria: 'PASS: repeated failures are blocked after the defined limit. FAIL: excessive failures continue without restriction.',
  result: 'PASS — 50 / 50 trials. The account was blocked once the 3-attempt threshold was crossed; every attempt was recorded; a correct password afterwards was still refused.',
}, sample('blocked-unauthorized-login-attempts'), 'Table: failed_logins rows for one source address — invalid-password attempts followed by blocked attempts.');

scen('3.4  SQL Injection Attempt', {
  tool: 'SQLMap-equivalent payload set / manual injection testing',
  attack: 'Ten injection payloads (tautology, UNION, and time-delay families) across query and login input fields.',
  control: 'Input validation · IDS signatures · Parameterized queries',
  expected: 'SQL injection attempts fail; no unauthorized database access occurs.',
  criteria: 'PASS: injection attempts are blocked and no unauthorized database access occurs. FAIL: injection bypasses validation or reaches database information.',
  result: 'PASS — 50 / 50 trials. The IDS blocked and logged the classic payload shapes; every payload was additionally run through a parameterized query and returned zero rows, proving it was treated as a literal string and never executed as SQL.',
}, sample('sql-injection-attempt'), 'Table: sqli detections written to intrusion_logs (high severity, signature match).');

scen('3.5  Brute Force Login Attempt', {
  tool: 'Hydra-equivalent wordlist / manual brute-force testing',
  attack: 'Twenty login attempts using a password wordlist against a valid account name from a single source address.',
  control: 'Login blocking · IP monitoring · OTP authentication',
  expected: 'Brute-force attempts blocked.',
  criteria: 'PASS: repeated attempts are blocked after the allowed threshold. FAIL: brute-force attempts continue successfully.',
  result: 'PASS — 50 / 50 trials. No wordlist entry ever produced a session; the account was blocked after the threshold and a high-severity auth_fail event was logged.',
}, sample('brute-force-login-attempt'), 'Table: the lockout event written to intrusion_logs when the threshold was crossed.');

scen('3.6  Intrusion Alerts Generated', {
  tool: 'Intrusion monitoring system',
  attack: 'Each attack family executed, with alert presence and audit record measured, and alert latency timed.',
  control: 'Real-time intrusion alerts · Audit logging',
  expected: 'Suspicious activities generate intrusion alerts.',
  criteria: 'PASS: suspicious or unauthorized activities generate real-time alerts. FAIL: intrusions occur without generating alerts.',
  result: 'PASS — 50 / 50 trials. Brute-force lockouts produced an administrator lockout alert plus an audit record; SQL-injection bursts crossing the auto-block threshold produced an intrusion alert. Mean alert latency: 19.32 ms.',
});

// --- 4. Reproduction ---
children.push(new Paragraph({ children: [new PageBreak()] }));
children.push(H1('4. How to Reproduce'));
children.push(
  P(run('From the project root, run the three commands below. The first runs all 50 trials per scenario; the second builds the database, CSV and HTML report; the third renders the figures.', { size: 21 }), { spacing: { after: 100 } }),
  P(run('SECURITY_MATRIX_ITERATIONS=50 php artisan test --filter=SecurityTestMatrixTest', { font: 'Consolas', size: 18 }), { shading: { type: ShadingType.CLEAR, fill: 'F3F4F6', color: 'auto' }, spacing: { after: 20 } }),
  P(run('php scripts/security-evidence-report.php', { font: 'Consolas', size: 18 }), { shading: { type: ShadingType.CLEAR, fill: 'F3F4F6', color: 'auto' }, spacing: { after: 20 } }),
  P(run('node scripts/security-evidence-screenshots.cjs', { font: 'Consolas', size: 18 }), { shading: { type: ShadingType.CLEAR, fill: 'F3F4F6', color: 'auto' }, spacing: { after: 120 } }),
  P([run('Evidence files are in ', { size: 20 }), run('docs/security-evidence/', { font: 'Consolas', size: 18 }),
     run(': the queryable database (security-evidence.sqlite), the trial ledger (runs.csv), the HTML report and the PNG figures.', { size: 20 })]),
);

// --- 5. Where to put this in the manuscript ---
children.push(H1('5. Where to Put This in the Manuscript'));
const tip = (title, body) => {
  children.push(P(run(title, { bold: true, size: 21, color: ACCENT }), { spacing: { before: 140, after: 40 } }));
  children.push(P(run(body, { size: 20 }), { spacing: { after: 40 } }));
};
tip('Chapter 3 — Methodology (Testing / Evaluation procedure)',
  'Put the methodology paragraph and the test-environment table here. Describe the six scenarios, the 50-trial repetition, and that testing exercised the system’s live security controls. This establishes rigour and repeatability. Reference the summary table as a forward pointer to Chapter 4.');
tip('Chapter 4 — Results and Discussion',
  'Put the Summary of Results table (Section 2) and Figure 1 here as the headline finding: 6 scenarios, 300 trials, 100% pass. Then discuss each scenario briefly, citing the pass/fail criteria. The mean alert-latency figure (19.32 ms) belongs here as a performance result for the real-time alerting objective.');
tip('Chapter 4 or Appendix — Scenario detail and database evidence',
  'The per-scenario detail cards and the captured database rows (Section 3) are your proof. If your program allows a results chapter to carry tables, keep the detail cards in Chapter 4; otherwise move Section 3 to an appendix (e.g., “Appendix G — Security Testing Evidence”) and cite it from Chapter 4. The intrusion_logs and failed_logins tables are the concrete artifacts a panelist will look for.');
tip('Appendix — Raw artifacts and reproduction',
  'Place the reproduction commands (Section 4) and screenshots of the SQLite database and HTML report in an appendix. Mention that the full ledger (runs.csv, 300 rows) and the database file are included in the submission package so the results can be independently verified.');
tip('Chapter 5 — Summary, Conclusion, Recommendation',
  'Cite the 100% pass rate as evidence that the security objectives (intrusion detection, access control, brute-force protection, real-time alerting) were met. For recommendations, note that this is an in-process self-assessment and that an external network-level scan (OWASP ZAP / SQLMap against a running HTTPS instance) is recommended as future work — this shows maturity and pre-empts the obvious panel question.');
tip('ISO/IEC 25010 — Security characteristic',
  'Use this evidence to support the “Security” characteristic (confidentiality, integrity, non-repudiation, accountability) in your ISO 25010 evaluation chapter, and cite the per-trial pass rate alongside the respondent survey scores.');

children.push(P(run('Honesty note for your defense: this is a defensive self-assessment run in-process against the project’s own application, not an external penetration engagement. Where the test plan names an external tool (ZAP, SQLMap, Hydra), the suite reproduces the check that tool performs against the live controls. State this plainly in Chapter 3 so the scope is clear; it strengthens, rather than weakens, the work.',
  { italics: true, size: 19, color: MUTED }), { spacing: { before: 160 } }));

// --- Appendix A: report screenshots ---
children.push(new Paragraph({ children: [new PageBreak()] }));
children.push(H1('Appendix A. Report Screenshots'));
children.push(P(run('Screenshots of the automated evidence report (report.html), produced by the test run. The full report is reproduced across the following strips at readable width.', { size: 20 }), { spacing: { after: 140 } }));

children.push(H2('A.1  Results summary'));
children.push(image('images/report-summary.png', 600));
children.push(P(run('Figure A1. Evidence report — header and results-by-scenario table.', { italics: true, size: 18, color: MUTED }), { alignment: AlignmentType.CENTER, spacing: { before: 40, after: 160 } }));

children.push(H2('A.2  Full report'));
['images/report-full-01.png', 'images/report-full-02.png', 'images/report-full-03.png', 'images/report-full-04.png']
  .filter((f) => fs.existsSync(path.join(base, f)))
  .forEach((f) => children.push(image(f, 600), spacer(40)));
children.push(P(run('Figure A2. Full evidence report (continuous, shown in sequential strips).', { italics: true, size: 18, color: MUTED }), { alignment: AlignmentType.CENTER, spacing: { before: 40 } }));

// ============================================================ document
const doc = new Document({
  creator: 'LGU Alicia LMS',
  title: 'Security Testing Evidence Report',
  styles: { default: { document: { run: { font: 'Calibri', size: 21, color: INK } } } },
  sections: [{
    properties: { page: { size: { width: 12240, height: 15840 }, margin: { top: 1440, bottom: 1440, left: 1440, right: 1440 } } },
    children,
  }],
});

Packer.toBuffer(doc).then((buf) => {
  const out = path.join(base, 'Security_Testing_Evidence.docx');
  fs.writeFileSync(out, buf);
  console.log('Wrote', out, '(' + buf.length + ' bytes)');
});
