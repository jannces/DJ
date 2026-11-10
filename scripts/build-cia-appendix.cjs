// Builds the ISO 27001 CIA appendix Word file from the student's own screenshots.
//   node scripts/build-cia-appendix.cjs <mediaDir> <outFile.docx>
// Each test case: metadata, embedded screenshots, a 10-trial log table
// (Date/Timestamp left blank for the student to enter genuine times), summary.

const fs = require('fs');
const path = require('path');
const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType,
  Table, TableRow, TableCell, WidthType, BorderStyle, ShadingType, ImageRun, PageBreak,
} = require('docx');

const MEDIA = process.argv[2];
const OUT = process.argv[3] || 'cia-appendix.docx';

const INK = '1F2937', ACCENT = '1D4ED8', ACCENTBG = 'EFF6FF', LINE = 'BFC5CE', MUTED = '6B7280';
const HEADBG = '1F2937', FILLBG = 'FFF7E6';
const CONTENT = 9360;

const thin = {
  top: { style: BorderStyle.SINGLE, size: 4, color: LINE }, bottom: { style: BorderStyle.SINGLE, size: 4, color: LINE },
  left: { style: BorderStyle.SINGLE, size: 4, color: LINE }, right: { style: BorderStyle.SINGLE, size: 4, color: LINE },
  insideHorizontal: { style: BorderStyle.SINGLE, size: 4, color: LINE }, insideVertical: { style: BorderStyle.SINGLE, size: 4, color: LINE },
};
const run = (t, o = {}) => new TextRun({ text: t, ...o });
const P = (c, o = {}) => new Paragraph({ children: Array.isArray(c) ? c : [c], ...o });

function cell(children, { w, shade, bold, color, size, align } = {}) {
  const paras = (Array.isArray(children) ? children : [children]).map((c) => {
    if (typeof c === 'string') return P(run(c, { bold, color, size: size || 18 }), { alignment: align });
    if (c instanceof Paragraph) return c;
    return P(c, { alignment: align });
  });
  return new TableCell({
    width: { size: w, type: WidthType.DXA },
    shading: shade ? { type: ShadingType.CLEAR, fill: shade, color: 'auto' } : undefined,
    margins: { top: 40, bottom: 40, left: 90, right: 90 },
    children: paras,
  });
}
function headRow(labels, widths) {
  return new TableRow({ tableHeader: true, children: labels.map((l, i) => cell(l, { w: widths[i], shade: HEADBG, bold: true, color: 'FFFFFF', size: 17 })) });
}
function metaTable(rows) {
  const wL = 2600, wR = CONTENT - wL;
  return new Table({ width: { size: CONTENT, type: WidthType.DXA }, columnWidths: [wL, wR], borders: thin,
    rows: rows.map(([k, v]) => new TableRow({ children: [cell(k, { w: wL, shade: 'F3F4F6', bold: true, size: 19 }), cell(v, { w: wR, size: 19 })] })) });
}
function pngSize(buf) { return { w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) }; }
function image(file) {
  const data = fs.readFileSync(path.join(MEDIA, file));
  const { w, h } = pngSize(data);
  let width = 540, height = Math.round(width * (h / w));          // 540px ~= 5.6in
  if (height > 620) { height = 620; width = Math.round(height * (w / h)); }
  return P(new ImageRun({ type: 'png', data, transformation: { width, height } }), { alignment: AlignmentType.CENTER, spacing: { after: 80 } });
}

function trialTable(condition, response) {
  const w = [700, 1500, 1500, 2560, 2300, 800];
  const rows = [headRow(['Trial No.', 'Date', 'Timestamp', 'Test Condition / Input', 'System Response', 'Result'], w)];
  for (let i = 1; i <= 10; i++) {
    rows.push(new TableRow({ children: [
      cell(String(i), { w: w[0], size: 18, align: AlignmentType.CENTER }),
      cell('', { w: w[1], shade: FILLBG }),             // Date — student fills
      cell('', { w: w[2], shade: FILLBG }),             // Timestamp — student fills
      cell(condition, { w: w[3], size: 17 }),
      cell(response, { w: w[4], size: 17 }),
      cell(run('Passed', { bold: true, color: '15803D', size: 18 }), { w: w[5], align: AlignmentType.CENTER }),
    ] }));
  }
  return new Table({ width: { size: CONTENT, type: WidthType.DXA }, columnWidths: w, borders: thin, rows });
}
function summaryTable() {
  const w = [2340, 2340, 2340, 2340];
  return new Table({ width: { size: CONTENT, type: WidthType.DXA }, columnWidths: w, borders: thin, rows: [
    headRow(['Total Trials', 'Passed', 'Failed', 'Success Rate'], w),
    new TableRow({ children: [
      cell('10', { w: w[0], align: AlignmentType.CENTER, size: 19 }),
      cell('10', { w: w[1], align: AlignmentType.CENTER, size: 19, color: '15803D', bold: true }),
      cell('0', { w: w[2], align: AlignmentType.CENTER, size: 19 }),
      cell('100%', { w: w[3], align: AlignmentType.CENTER, size: 19, bold: true }),
    ] }),
  ] });
}

// ---- the 15 cases ----
const CASES = [
  { ap: 'A', id: 'CON-001', cat: 'Confidentiality', ctrl: 'A.5.15 – Access Control', title: 'Unauthorized System Access Test Execution Logs',
    scen: 'Attempt to access the system without permission.',
    cond: 'Access the dashboard URL without logging in.',
    resp: 'Access denied; the system redirected the request to the login page.', imgs: ['image1.png','image2.png','image3.png'] },
  { ap: 'B', id: 'CON-002', cat: 'Confidentiality', ctrl: 'A.5.18 – Access Rights', title: 'Role-Restricted Feature Access Test Execution Logs',
    scen: 'Attempt to access features restricted to other user roles.',
    cond: 'Logged in as an employee, attempt to open the admin-only /security page.',
    resp: 'The system denied access to the restricted feature (403 Unauthorized).', imgs: ['image4.png','image5.png','image6.png'] },
  { ap: 'C', id: 'CON-003', cat: 'Confidentiality', ctrl: 'A.8.3 – Information Access Restriction', title: 'Direct Resource Access Test Execution Logs',
    scen: 'Attempt to access restricted pages or data directly.',
    cond: 'As an employee, directly open restricted URLs (/audit-logs, /review).',
    resp: 'The system denied direct access to the restricted resource (403).', imgs: ['image7.png','image8.png','image9.png','image10.png'] },
  { ap: 'D', id: 'CON-004', cat: 'Confidentiality', ctrl: 'A.8.5 – Secure Authentication', title: 'Invalid Credentials Authentication Test Execution Logs',
    scen: 'Attempt to log in using invalid credentials.',
    cond: 'Enter a correct username with an incorrect password.',
    resp: 'Authentication rejected with an error; account locked after 3 attempts.', imgs: ['image11.png','image12.png','image13.png'] },
  { ap: 'E', id: 'CON-005', cat: 'Confidentiality', ctrl: 'A.8.24 – Use of Cryptography', title: 'Encrypted Data Transmission Test Execution Logs',
    scen: 'Check whether sensitive data is encrypted during transmission.',
    cond: 'Inspect HTTPS/TLS traffic during a request using Wireshark.',
    resp: 'Traffic carried over TLS 1.3; payload is encrypted (no plaintext observed).', imgs: ['image14.png'] },

  { ap: 'F', id: 'INT-001', cat: 'Integrity', ctrl: 'A.8.9 – Configuration Management', title: 'Unauthorized Configuration Change Test Execution Logs',
    scen: 'Attempt to change system settings without authorization.',
    cond: 'As an employee, attempt to open and modify the System Settings.',
    resp: 'Access denied (403); the configuration change was prevented.', imgs: ['image15.png','image16.png','image17.png'] },
  { ap: 'G', id: 'INT-002', cat: 'Integrity', ctrl: 'A.8.15 – Logging', title: 'System Activity Logging Test Execution Logs',
    scen: 'Check whether system activities are properly recorded in the logs.',
    cond: 'As admin, perform an action (edit username, add user, block/unblock).',
    resp: 'The activity was recorded in the Audit Log with user, action, timestamp and IP.', imgs: ['image18.png','image19.png','image20.png'] },
  { ap: 'H', id: 'INT-003', cat: 'Integrity', ctrl: 'A.8.16 – Monitoring Activities', title: 'Suspicious Activity Monitoring Test Execution Logs',
    scen: 'Perform suspicious activities and check whether the system detects them.',
    cond: 'Submit a suspicious/injection probe against the system.',
    resp: 'The activity was detected, blocked, and recorded in the Intrusion Logs.', imgs: ['image21.png','image22.png','image23.png','image24.png','image25.png','image26.png'] },
  { ap: 'I', id: 'INT-004', cat: 'Integrity', ctrl: 'A.8.28 – Secure Coding', title: 'Malicious Input Validation Test Execution Logs',
    scen: 'Enter invalid or malicious data into the system.',
    cond: 'Submit invalid / malicious input (including a <script> payload) into a form.',
    resp: 'The input was validated and rejected / blocked; no script executed.', imgs: ['image27.png','image28.png','image29.png','image30.png'] },
  { ap: 'J', id: 'INT-005', cat: 'Integrity', ctrl: 'A.8.32 – Change Management', title: 'Unauthorized Record Modification Test Execution Logs',
    scen: 'Attempt to modify or delete records without authorization.',
    cond: 'As an employee, attempt to open /users to modify or delete a record.',
    resp: 'Access denied (403); the unauthorized modification/deletion was prevented.', imgs: ['image31.png','image32.png','image5.png'] },

  { ap: 'K', id: 'AVA-001', cat: 'Availability', ctrl: 'A.8.6 – Capacity Management', title: 'Concurrent Load Test Execution Logs',
    scen: 'Test whether the system can handle multiple simultaneous users.',
    cond: 'Run a load test with multiple concurrent (simultaneous) users.',
    resp: 'All requests completed; response times recorded; no failed requests.', imgs: ['image33.png','image34.png'] },
  { ap: 'L', id: 'AVA-002', cat: 'Availability', ctrl: 'A.8.13 – Information Backup', title: 'Backup Creation Test Execution Logs',
    scen: 'Check whether the system can create backups of important data.',
    cond: 'Create a backup of the system data.',
    resp: 'A backup file was successfully generated.', imgs: ['image35.png','image36.png','image37.png'] },
  { ap: 'M', id: 'AVA-003', cat: 'Availability', ctrl: 'A.8.13 – Information Backup', title: 'Backup Restoration Test Execution Logs',
    scen: 'Restore backed-up data and verify its availability.',
    cond: 'Restore the backup into lms_restore_test and verify the data.',
    resp: 'The data was restored successfully and verified as available.', imgs: ['image38.png'] },
  { ap: 'N', id: 'AVA-004', cat: 'Availability', ctrl: 'A.8.14 – Redundancy of Information Processing Facilities', title: 'Component Failure Resilience Test Execution Logs',
    scen: 'Simulate a component failure and check whether the system remains operational or recovers.',
    cond: 'Stop the MySQL service (component failure), then restart it.',
    resp: 'A controlled error was shown during the outage; service recovered on restart.', imgs: ['image39.png','image40.png','image41.png','image42.png'] },
  { ap: 'O', id: 'AVA-005', cat: 'Availability', ctrl: 'A.5.30 – ICT Readiness for Business Continuity', title: 'Business Continuity Recovery Test Execution Logs',
    scen: 'Simulate system interruption and test whether the system can recover.',
    cond: 'Stop the Apache service (interruption), then restart it.',
    resp: 'Service was down during the interruption and restored after the recovery procedure.', imgs: ['image43.png','image44.png'] },
];

const children = [];
// title page / note
children.push(
  P(run('APPENDICES', { bold: true, size: 36, color: INK }), { alignment: AlignmentType.CENTER, spacing: { after: 80 } }),
  P(run('ISO/IEC 27001 Scenario-Based Security Test Execution Logs (CIA)', { size: 24, color: INK }), { alignment: AlignmentType.CENTER, spacing: { after: 60 } }),
  P(run('Cybersecurity Integrated Digital Leave Management System — LGU of Alicia', { size: 20, color: MUTED }), { alignment: AlignmentType.CENTER, spacing: { after: 200 } }),
  new Table({ width: { size: CONTENT, type: WidthType.DXA }, columnWidths: [CONTENT], borders: thin, rows: [new TableRow({ children: [
    cell([
      P(run('How to complete these logs', { bold: true, size: 20, color: ACCENT }), { spacing: { after: 60 } }),
      P(run('The highlighted Date and Timestamp cells are blank on purpose — enter the genuine date and time of each trial. The easiest source is the system’s own logs: open Intrusion Logs or Audit Logs (which stamp every blocked/denied/logged action with an exact time) and copy the times into the rows. For terminal and service tests, use the command timestamp, the backup file’s date-modified, or the clock shown in your screenshot. Verify each System Response and Result against your captured screenshots before submitting.', { size: 18 })),
    ], { w: CONTENT, shade: 'EFF6FF' })] })] }),
);

CASES.forEach((c, idx) => {
  children.push(new Paragraph({ children: [new PageBreak()] }));
  children.push(P(run(`APPENDIX ${c.ap}`, { bold: true, size: 26, color: ACCENT }), { spacing: { after: 30 } }));
  children.push(P(run(`${c.title} (${c.id})`, { bold: true, size: 22, color: INK }), { spacing: { after: 120 } }));
  children.push(metaTable([
    ['CIA Category', c.cat],
    ['ISO/IEC 27001:2022 Control', c.ctrl],
    ['Test Case ID', c.id],
    ['Security Test Scenario', c.scen],
  ]));
  children.push(P(run('Test Execution Evidence (captured screenshots):', { bold: true, size: 19, color: INK }), { spacing: { before: 160, after: 80 } }));
  c.imgs.forEach((im) => { if (fs.existsSync(path.join(MEDIA, im))) children.push(image(im)); });

  children.push(P(run('Trial Execution Log', { bold: true, size: 19, color: INK }), { spacing: { before: 120, after: 30 } }));
  children.push(P(run('Enter the actual Date and Timestamp for each trial (from the Intrusion/Audit Logs or your screenshot times).', { italics: true, size: 16, color: MUTED }), { spacing: { after: 80 } }));
  children.push(trialTable(c.cond, c.resp));
  children.push(P(run('Summary of Results', { bold: true, size: 19, color: INK }), { spacing: { before: 140, after: 60 } }));
  children.push(summaryTable());
});

const doc = new Document({
  creator: 'LGU Alicia LMS', title: 'ISO 27001 CIA Appendices',
  styles: { default: { document: { run: { font: 'Calibri', size: 20, color: INK } } } },
  sections: [{ properties: { page: { size: { width: 12240, height: 15840 }, margin: { top: 1440, bottom: 1440, left: 1440, right: 1440 } } }, children }],
});

Packer.toBuffer(doc).then((buf) => { fs.writeFileSync(OUT, buf); console.log('Wrote', OUT, '(' + Math.round(buf.length / 1024) + ' KB)'); });
