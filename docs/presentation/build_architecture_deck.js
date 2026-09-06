const pptxgen = require("pptxgenjs");
const path = require("path");

const NAVY   = "1E2761";
const DEEP   = "141B45";
const ICE    = "CADCFC";
const SOFT   = "EEF3FC";
const SOFT2  = "F6F8FD";
const ALERT  = "C43D2B";
const ALERTL = "FBEDEA";
const TEXT   = "343B4F";
const MUTE   = "78819A";
const WHITE  = "FFFFFF";
const H = "Cambria";
const B = "Calibri";

const pres = new pptxgen();
pres.layout = "LAYOUT_WIDE";           // 13.33 x 7.5
pres.author = "Macarubbo, Mendoza A.J., Mendoza D.R.";
pres.title  = "System Architecture — Digital Leave Management System";
const W = 13.33;

// ---------- helpers ----------
function titleBar(s, kicker, title) {
  s.addText(kicker, { x:0.62, y:0.42, w:9, h:0.26, isTextBox:true, margin:0,
    fontFace:B, fontSize:11, bold:true, color:MUTE, charSpacing:1.6 });
  s.addText(title, { x:0.6, y:0.68, w:12.2, h:0.66, isTextBox:true, margin:0, valign:"top",
    fontFace:H, fontSize:32, bold:true, color:NAVY });
}
function card(s, o) {
  s.addShape(pres.ShapeType.roundRect, {
    x:o.x, y:o.y, w:o.w, h:o.h, rectRadius:0.08,
    fill:{ color:o.fill || SOFT }, line:{ color:o.line || (o.fill||SOFT), width:1 },
    shadow: o.shadow ? { type:"outer", angle:90, blur:8, offset:1, opacity:0.10, color:"8892AA" } : undefined
  });
}
function num(s, n, x, y, d, bg, fg) {
  d = d || 0.36;
  s.addShape(pres.ShapeType.ellipse, { x:x, y:y, w:d, h:d, fill:{ color:bg||NAVY }, line:{ color:bg||NAVY, width:1 } });
  s.addText(String(n), { x:x, y:y, w:d, h:d, isTextBox:true, margin:0, align:"center", valign:"middle",
    fontFace:B, fontSize:12, bold:true, color:fg||WHITE });
}
function arrowR(s, x, y, w, color) {
  s.addShape(pres.ShapeType.line, { x:x, y:y, w:w, h:0,
    line:{ color:color||NAVY, width:1.75, endArrowType:"triangle" } });
}
function arrowD(s, x, y, h, color) {
  s.addShape(pres.ShapeType.line, { x:x, y:y, w:0, h:h,
    line:{ color:color||NAVY, width:1.75, endArrowType:"triangle" } });
}
function footNote2(s, t) {
  s.addText(t, { x:0.62, y:5.96, w:12.1, h:0.26, isTextBox:true, margin:0,
    fontFace:B, fontSize:9.5, italic:true, color:MUTE });
}
function footNote(s, t) {
  s.addText(t, { x:0.62, y:6.86, w:12.1, h:0.3, isTextBox:true, margin:0,
    fontFace:B, fontSize:10.5, italic:true, color:MUTE });
}

// =========================================================
// 1 — TITLE
// =========================================================
let s = pres.addSlide();
s.background = { color: NAVY };
s.addShape(pres.ShapeType.rect, { x:0, y:0, w:W, h:7.5, fill:{ color:NAVY } });

s.addText("CHAPTER IV  ·  TECHNICAL BACKGROUND", { x:0.85, y:1.18, w:8, h:0.3, isTextBox:true, margin:0,
  fontFace:B, fontSize:12, bold:true, color:ICE, charSpacing:2.4 });
s.addText("System Architecture", { x:0.8, y:1.58, w:8.4, h:1.0, isTextBox:true, margin:0,
  fontFace:H, fontSize:52, bold:true, color:WHITE });
s.addText("Cybersecurity Integrated Digital Leave Management System\nwith Real-Time Intrusion Alerts", {
  x:0.85, y:2.72, w:7.6, h:0.85, isTextBox:true, margin:0,
  fontFace:B, fontSize:17, color:ICE, lineSpacing:26 });
s.addText("Local Government Unit of Alicia  ·  Isabela State University – Echague", {
  x:0.85, y:3.62, w:7.8, h:0.3, isTextBox:true, margin:0,
  fontFace:B, fontSize:12.5, color:"9FB4E0" });

const stats = [["135","Employees served"],["5","Operational roles"],["15","CSC leave types"]];
stats.forEach((st, i) => {
  const x = 0.85 + i*2.55;
  s.addText(st[0], { x:x, y:4.42, w:2.3, h:0.62, isTextBox:true, margin:0,
    fontFace:H, fontSize:40, bold:true, color:WHITE });
  s.addText(st[1], { x:x, y:5.06, w:2.3, h:0.3, isTextBox:true, margin:0,
    fontFace:B, fontSize:11.5, color:"9FB4E0" });
});
s.addText("Macarubbo, Noly J. Jr.  ·  Mendoza, Alexander John T.  ·  Mendoza, Dj Robin O.", {
  x:0.85, y:6.42, w:8.5, h:0.3, isTextBox:true, margin:0,
  fontFace:B, fontSize:11, color:"8095C9" });

// right-hand tier motif
const tiers = [["CLIENT","Browsers on authorized LAN devices"],
               ["NETWORK","Switch · Router/Firewall · HTTPS"],
               ["APPLICATION","Apache · PHP 8.3 · Laravel 12"],
               ["DATA","MySQL / MariaDB · Audit stores"]];
tiers.forEach((t, i) => {
  const y = 1.55 + i*1.18;
  s.addShape(pres.ShapeType.roundRect, { x:9.55, y:y, w:3.2, h:0.98, rectRadius:0.08,
    fill:{ color: i===3 ? ICE : "2B3B7A" }, line:{ color: i===3 ? ICE : "3D50A0", width:1 } });
  s.addText(t[0], { x:9.78, y:y+0.14, w:2.8, h:0.26, isTextBox:true, margin:0,
    fontFace:B, fontSize:11, bold:true, charSpacing:1.5, color: i===3 ? NAVY : ICE });
  s.addText(t[1], { x:9.78, y:y+0.42, w:2.82, h:0.44, isTextBox:true, margin:0,
    fontFace:B, fontSize:10.5, color: i===3 ? "3A4670" : "C6D2F2" });
  if (i < 3) arrowD(s, 11.15, y+1.0, 0.16, "6A7CC4");
});
s.addNotes("Chapter IV presents the architecture of the LAN-based leave management system: how the network, application, and data tiers are structured, and how the security controls are layered across them.");

// =========================================================
// 2 — ARCHITECTURAL OVERVIEW
// =========================================================
s = pres.addSlide();
titleBar(s, "4.1.1  SYSTEM OVERVIEW", "A centralized, LAN-only client–server system");
s.addText("Authorized client devices reach a single application server over the LGU's internal network. Processing, persistent data, logging, and administrative monitoring are centralized. LAN restriction reduces external exposure — but internal devices can still generate malicious requests, so application-level controls run alongside it.",
  { x:0.62, y:1.52, w:5.55, h:1.5, isTextBox:true, margin:0,
    fontFace:B, fontSize:13.5, color:TEXT, lineSpacing:21 });

const facts = [
  ["Architectural style","Layered monolith — MVC + service layer on Laravel 12. One server, one ops team, offline LAN."],
  ["Deployment","Single centralized server inside the LGU LAN. No public internet exposure for normal operations."],
  ["Roles enforced","Employee · Department Head · HR · Municipal Mayor · System Administrator, all through database-driven RBAC."],
  ["Business rules","15 CSC leave types stored as configuration, not code — policy changes need no redeployment."]
];
facts.forEach((f, i) => {
  const y = 1.52 + i*1.30;
  card(s, { x:6.5, y:y, w:6.25, h:1.14, fill:SOFT, line:"DCE5F6" });
  num(s, i+1, 6.74, y+0.20, 0.34);
  s.addText(f[0], { x:7.22, y:y+0.16, w:5.3, h:0.28, isTextBox:true, margin:0,
    fontFace:B, fontSize:13, bold:true, color:NAVY });
  s.addText(f[1], { x:7.22, y:y+0.46, w:5.28, h:0.6, isTextBox:true, margin:0,
    fontFace:B, fontSize:11.5, color:TEXT, lineSpacing:15 });
});

// left: approval chain mini-flow
card(s, { x:0.62, y:3.30, w:5.55, h:1.62, fill:NAVY, line:NAVY });
s.addText("APPROVAL SEQUENCE", { x:0.88, y:3.50, w:5, h:0.26, isTextBox:true, margin:0,
  fontFace:B, fontSize:11, bold:true, charSpacing:1.5, color:ICE });
const chain = ["Employee","Dept. Head","HR","Mayor"];
chain.forEach((c, i) => {
  const x = 0.88 + i*1.31;
  s.addShape(pres.ShapeType.roundRect, { x:x, y:3.86, w:1.12, h:0.5, rectRadius:0.06,
    fill:{ color:"2B3B7A" }, line:{ color:"3D50A0", width:1 } });
  s.addText(c, { x:x, y:3.86, w:1.12, h:0.5, isTextBox:true, margin:0, align:"center", valign:"middle",
    fontFace:B, fontSize:10.5, bold:true, color:WHITE });
  if (i < 3) arrowR(s, x+1.14, 4.11, 0.15, ICE);
});
s.addText("Balances update only after the final decision is recorded.",
  { x:0.88, y:4.46, w:5.05, h:0.3, isTextBox:true, margin:0,
    fontFace:B, fontSize:10.5, italic:true, color:ICE });

card(s, { x:0.62, y:5.10, w:5.55, h:1.55, fill:ALERTL, line:"F0D3CD" });
s.addText("Why controls still matter inside the LAN", { x:0.88, y:5.28, w:5.05, h:0.28, isTextBox:true, margin:0,
  fontFace:B, fontSize:13, bold:true, color:ALERT });
s.addText([
  { text:"Unauthorized internal access and privilege misuse", options:{ bullet:true, breakLine:true } },
  { text:"Improper edits to leave records by shared workstations", options:{ bullet:true, breakLine:true } },
  { text:"Brute-force attempts from a trusted network segment", options:{ bullet:true } }
], { x:0.94, y:5.60, w:5.0, h:0.95, isTextBox:true, margin:0,
     fontFace:B, fontSize:11.5, color:TEXT, paraSpaceAfter:4 });
s.addNotes("The LAN boundary is a control, not the only control. Authentication, RBAC, validation, logging, IP monitoring, and intrusion alerts operate on top of it.");

// =========================================================
// 3 — NETWORK ARCHITECTURE
// =========================================================
s = pres.addSlide();
titleBar(s, "4.2  NETWORK ARCHITECTURE", "Client\u2013server topology inside the LGU LAN");

const DY = 1.55, DH = 4.00, DMID = DY + DH/2;   // diagram band

// client column
card(s, { x:0.62, y:DY, w:2.75, h:DH, fill:SOFT2, line:"DCE5F6" });
s.addText("AUTHORIZED CLIENTS", { x:0.82, y:DY+0.16, w:2.4, h:0.26, isTextBox:true, margin:0,
  fontFace:B, fontSize:10.5, bold:true, charSpacing:1.2, color:MUTE });
const clients = ["Employee workstations","Department Head PCs","HR Management PCs","Office of the Mayor","System Admin console","Mobile devices (Wi-Fi)"];
clients.forEach((c, i) => {
  const y = DY + 0.50 + i*0.56;
  s.addShape(pres.ShapeType.roundRect, { x:0.82, y:y, w:2.35, h:0.46, rectRadius:0.06,
    fill:{ color:WHITE }, line:{ color:"C9D6EE", width:1 } });
  s.addText(c, { x:0.96, y:y, w:2.1, h:0.46, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:10.5, color:TEXT });
});

// switch
card(s, { x:4.02, y:DMID-0.88, w:1.75, h:1.75, fill:NAVY, line:NAVY, shadow:true });
s.addText("Managed\nSwitch", { x:4.02, y:DMID-0.68, w:1.75, h:0.7, isTextBox:true, margin:0, align:"center",
  fontFace:B, fontSize:14, bold:true, color:WHITE, lineSpacing:18 });
s.addText("Wired LAN\ndistribution\n+ Wireless AP", { x:4.02, y:DMID-0.01, w:1.75, h:0.75, isTextBox:true, margin:0, align:"center",
  fontFace:B, fontSize:10, color:ICE, lineSpacing:13 });
arrowR(s, 3.42, DMID, 0.55);

// firewall
card(s, { x:6.42, y:DMID-0.88, w:1.75, h:1.75, fill:ICE, line:"A9C2EC", shadow:true });
s.addText("Router /\nFirewall", { x:6.42, y:DMID-0.68, w:1.75, h:0.7, isTextBox:true, margin:0, align:"center",
  fontFace:B, fontSize:14, bold:true, color:NAVY, lineSpacing:18 });
s.addText("Network boundary\n& access policy", { x:6.42, y:DMID+0.02, w:1.75, h:0.6, isTextBox:true, margin:0, align:"center",
  fontFace:B, fontSize:10, color:"3A4670", lineSpacing:13 });
arrowR(s, 5.82, DMID, 0.55);

// internet, blocked
s.addShape(pres.ShapeType.roundRect, { x:6.42, y:DY, w:1.75, h:0.7, rectRadius:0.10,
  fill:{ color:ALERTL }, line:{ color:ALERT, width:1.25, dashType:"dash" } });
s.addText("Internet", { x:6.42, y:DY, w:1.75, h:0.7, isTextBox:true, margin:0, align:"center", valign:"middle",
  fontFace:B, fontSize:12, bold:true, color:ALERT });
s.addShape(pres.ShapeType.line, { x:7.30, y:DY+0.72, w:0, h:DMID-0.90-DY-0.72,
  line:{ color:ALERT, width:1.5, dashType:"dash" } });
s.addText("no public exposure", { x:7.42, y:DY+0.80, w:1.6, h:0.28, isTextBox:true, margin:0,
  fontFace:B, fontSize:9.5, italic:true, color:ALERT });

// server
card(s, { x:8.82, y:DY, w:3.9, h:DH, fill:NAVY, line:NAVY, shadow:true });
s.addText("CENTRALIZED SERVER", { x:9.06, y:DY+0.18, w:3.4, h:0.26, isTextBox:true, margin:0,
  fontFace:B, fontSize:10.5, bold:true, charSpacing:1.2, color:ICE });
const stack = [
  ["Apache HTTP Server","TLS termination \u00b7 HTTPS only"],
  ["Laravel 12 application","Middleware kernel, controllers, services"],
  ["MySQL / MariaDB","Employees, leave, balances, config"],
  ["Audit & security stores","Audit, activity, intrusion, failed logins"],
  ["Mail relay + scheduler","OTP delivery, alerts, accrual jobs"]
];
stack.forEach((t, i) => {
  const y = DY + 0.52 + i*0.68;
  s.addShape(pres.ShapeType.roundRect, { x:9.06, y:y, w:3.42, h:0.60, rectRadius:0.06,
    fill:{ color: i===2 ? ICE : "2B3B7A" }, line:{ color: i===2 ? ICE : "3D50A0", width:1 } });
  s.addText(t[0], { x:9.24, y:y+0.05, w:3.1, h:0.24, isTextBox:true, margin:0,
    fontFace:B, fontSize:11.5, bold:true, color: i===2 ? NAVY : WHITE });
  s.addText(t[1], { x:9.24, y:y+0.29, w:3.1, h:0.24, isTextBox:true, margin:0,
    fontFace:B, fontSize:9.5, color: i===2 ? "3A4670" : "AFC0EA" });
});
arrowR(s, 8.22, DMID, 0.55);
s.addText("HTTPS", { x:8.17, y:DMID-0.32, w:0.70, h:0.24, isTextBox:true, margin:0, align:"center",
  fontFace:B, fontSize:9, bold:true, color:NAVY });
s.addText("TLS", { x:8.17, y:DMID+0.10, w:0.70, h:0.24, isTextBox:true, margin:0, align:"center",
  fontFace:B, fontSize:9, bold:true, color:NAVY });

// supporting notes
const netnotes = [
  ["Device allow-list","Only registered devices clear the check"],
  ["Boundary policy","Firewall keeps the app off public routes"],
  ["Local-only database","MySQL binds to the application host only"]
];
netnotes.forEach((n, i) => {
  const x = 0.62 + i*4.05;
  card(s, { x:x, y:5.86, w:3.85, h:0.76, fill:SOFT, line:"DCE5F6" });
  s.addText(n[0], { x:x+0.24, y:5.94, w:3.4, h:0.24, isTextBox:true, margin:0,
    fontFace:B, fontSize:11, bold:true, color:NAVY });
  s.addText(n[1], { x:x+0.24, y:6.20, w:3.4, h:0.24, isTextBox:true, margin:0,
    fontFace:B, fontSize:10, color:TEXT });
});

footNote(s, "Figure 3 \u2014 Requests travel from authorized clients through the switch and router/firewall to the centralized server; responses return over the same HTTPS path.");
s.addNotes("Centralization simplifies administration but makes server hardening, backup, access restriction, and monitoring essential to availability and security.");

// =========================================================
// 4 — CONSOLIDATED SYSTEM ARCHITECTURE DIAGRAM
// =========================================================
s = pres.addSlide();
s.addText("SYSTEM ARCHITECTURE  \u2014  END TO END", { x:0.72, y:0.24, w:9, h:0.26, isTextBox:true, margin:0,
  fontFace:B, fontSize:11, bold:true, color:MUTE, charSpacing:1.6 });
s.addImage({ path: path.join(__dirname, "architecture.png"), x:0.72, y:0.60, w:11.88, h:6.50 });
s.addNotes("One picture of the whole system: authorized clients on the LAN, the switch and firewall, "
  + "the centralized server with its five layers, the data tier on the same host, and the intrusion "
  + "alert path that runs back to the System Administrator. Walk it left to right along the navy "
  + "request path, then bottom right to left along the red alert path.");

// =========================================================
// 5 — APPLICATION ARCHITECTURE (LAYERS)
// =========================================================
s = pres.addSlide();
titleBar(s, "4.3  APPLICATION SYSTEM ARCHITECTURE", "Five layers, separated responsibilities");

const layers = [
  ["PRESENTATION", "Blade views · Bootstrap 5 · Chart.js · SweetAlert2 · dark/light theme · offline-vendored assets", ICE, NAVY],
  ["HTTP  &  SECURITY KERNEL", "Routes (web / api v1) · Controllers · Form Requests · 9-stage middleware pipeline", "2B3B7A", WHITE],
  ["DOMAIN  /  APPLICATION SERVICES", "LeaveApplication · LeaveCredit · ApprovalWorkflow · LeavePolicyEngine · Otp · LoginSecurity · IntrusionDetection · AuditLogger · Report", NAVY, WHITE],
  ["DATA ACCESS", "Eloquent models & repositories — parameterized queries, transactional balance updates", "2B3B7A", WHITE],
  ["INFRASTRUCTURE", "MySQL / MariaDB · file storage · SMTP mail relay · queue worker · task scheduler", ICE, NAVY]
];
layers.forEach((l, i) => {
  const y = 1.55 + i*0.90;
  s.addShape(pres.ShapeType.roundRect, { x:0.62, y:y, w:12.1, h:0.78, rectRadius:0.07,
    fill:{ color:l[2] }, line:{ color:l[2], width:1 } });
  s.addText(l[0], { x:0.88, y:y+0.06, w:3.5, h:0.66, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:12, bold:true, charSpacing:0.8, color:l[3] });
  s.addText(l[1], { x:4.45, y:y+0.06, w:8.05, h:0.66, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:11, color: l[3]===WHITE ? "C6D2F2" : "3A4670" });
  if (i < 4) arrowD(s, 3.1, y+0.79, 0.11, "9AAAD4");
});

card(s, { x:0.62, y:6.12, w:12.1, h:0.62, fill:SOFT, line:"DCE5F6" });
s.addText([
  { text:"Trust boundary 1  ", options:{ bold:true, color:NAVY } },
  { text:"browser ⇄ Apache (TLS) — all input untrusted, validated and IDS-scanned.     " },
  { text:"Trust boundary 2  ", options:{ bold:true, color:NAVY } },
  { text:"app ⇄ MySQL, bound to localhost only." }
], { x:0.88, y:6.12, w:11.6, h:0.62, isTextBox:true, margin:0, valign:"middle",
     fontFace:B, fontSize:11.5, color:TEXT });
footNote(s, "");
s.addNotes("Figure 4: each layer can be configured, tested, and maintained independently. Business rules live in the service layer, not in controllers or views.");

// =========================================================
// 6 — SECURITY KERNEL
// =========================================================
s = pres.addSlide();
titleBar(s, "4.3.3  INTEGRATED SECURITY FRAMEWORK", "The security kernel — every request, in order");

const steps = [
  ["Blocked IP check","Rejects known-bad sources before any processing"],
  ["Authorized device","LAN device allow-list, toggleable per deployment"],
  ["Intrusion detection","SQLi / XSS / traversal signatures + rate anomaly"],
  ["Session & CSRF","Token failures are recorded as intrusion events"],
  ["Authentication","Username + password (bcrypt) then email OTP"],
  ["RBAC permission gate","Database-driven roles, inheritance, per-user overrides"],
  ["Activity logging","Page-level trail attributed to the acting user"],
  ["Audit trail","Append-only record of every state change"],
  ["Security headers","CSP · X-Frame-Options · nosniff · Referrer-Policy · HSTS"]
];
steps.forEach((st, i) => {
  const col = i % 3, row = Math.floor(i / 3);
  const x = 0.62 + col*4.05, y = 1.58 + row*1.42;
  const hot = (i === 2);
  card(s, { x:x, y:y, w:3.85, h:1.24, fill: hot ? ALERTL : SOFT, line: hot ? "F0D3CD" : "DCE5F6" });
  num(s, i+1, x+0.22, y+0.20, 0.34, hot ? ALERT : NAVY);
  s.addText(st[0], { x:x+0.68, y:y+0.18, w:3.0, h:0.28, isTextBox:true, margin:0,
    fontFace:B, fontSize:12.5, bold:true, color: hot ? ALERT : NAVY });
  s.addText(st[1], { x:x+0.68, y:y+0.50, w:3.0, h:0.62, isTextBox:true, margin:0,
    fontFace:B, fontSize:11, color:TEXT, lineSpacing:14.5 });
  if (col < 2) arrowR(s, x+3.89, y+0.62, 0.12, "9AAAD4");
});

card(s, { x:0.62, y:5.90, w:12.1, h:0.78, fill:NAVY, line:NAVY });
s.addText("Defense in depth — no single mechanism is relied on. A request must clear network restriction, transport security, request filtering, identity, and authorization before it can change a leave record.",
  { x:0.9, y:5.90, w:11.55, h:0.78, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:12, color:ICE });
footNote(s, "");
s.addNotes("Presence of a control in the architecture is not proof of effectiveness — each is assessed independently during the technical testing described in Chapter III.");

// =========================================================
// 7 — REAL-TIME INTRUSION ALERT WORKFLOW
// =========================================================
s = pres.addSlide();
titleBar(s, "4.4.3  REAL-TIME INTRUSION ALERT WORKFLOW", "Detect, record, block, notify");

const flow = [
  ["Login attempt","Credentials and OTP submitted from a LAN client"],
  ["Verify & record","Failed attempt logged with username, IP, device, timestamp"],
  ["Threshold check","Repeated failures from one account or IP are correlated"],
  ["Automatic block","IP or account restricted; event written to intrusion log"],
  ["Administrator alerted","Dashboard surfaces the event; high severity also emails"]
];
flow.forEach((f, i) => {
  const x = 0.62 + i*2.50;
  const hot = i >= 3;
  card(s, { x:x, y:1.62, w:2.28, h:2.30, fill: hot ? ALERTL : SOFT, line: hot ? "F0D3CD" : "DCE5F6" });
  num(s, i+1, x+0.20, 1.82, 0.36, hot ? ALERT : NAVY);
  s.addText(f[0], { x:x+0.20, y:2.34, w:1.92, h:0.5, isTextBox:true, margin:0,
    fontFace:B, fontSize:13, bold:true, color: hot ? ALERT : NAVY, lineSpacing:17 });
  s.addText(f[1], { x:x+0.20, y:2.88, w:1.92, h:0.9, isTextBox:true, margin:0,
    fontFace:B, fontSize:11, color:TEXT, lineSpacing:14.5 });
  if (i < 4) arrowR(s, x+2.31, 2.77, 0.16, hot ? ALERT : NAVY);
});

const kpis = [
  ["3 strikes","Failed logins before the account is locked"],
  ["24 hours","Lockout duration; expired blocks lifted automatically"],
  ["≤ 15 seconds","Dashboard poll interval — the bounded sense of “real-time”"],
  ["Append-only","Intrusion and audit records retained as evidence"]
];
kpis.forEach((k, i) => {
  const x = 0.62 + i*3.09;
  card(s, { x:x, y:4.22, w:2.88, h:1.42, fill:NAVY, line:NAVY });
  s.addText(k[0], { x:x+0.24, y:4.40, w:2.45, h:0.44, isTextBox:true, margin:0,
    fontFace:H, fontSize:21, bold:true, color:WHITE });
  s.addText(k[1], { x:x+0.24, y:4.88, w:2.45, h:0.66, isTextBox:true, margin:0,
    fontFace:B, fontSize:10.5, color:ICE, lineSpacing:14 });
});

card(s, { x:0.62, y:5.86, w:12.1, h:0.82, fill:SOFT, line:"DCE5F6" });
s.addText([
  { text:"Measured, not assumed.  ", options:{ bold:true, color:NAVY } },
  { text:"Detection-to-notification latency and alert precision (the proportion of alerts matching genuine intrusion attempts) are recorded during controlled testing and reported under Section 3.4." }
], { x:0.9, y:5.86, w:11.55, h:0.82, isTextBox:true, margin:0, valign:"middle",
     fontFace:B, fontSize:12, color:TEXT });
footNote(s, "");
s.addNotes("Detection sources include failed logins, signature matches on request input, unauthorized device access, and 403 permission denials.");

// =========================================================
// 8 — LEAVE REQUEST DATA FLOW
// =========================================================
s = pres.addSlide();
titleBar(s, "4.9.2  DATA FLOW", "Path of a leave application");

const lane = [
  ["Employee","Files CSC Form 6 online; system validates inputs and attaches required documents", NAVY],
  ["Leave Policy Engine","Classifies the leave type and applies its rules — day limits, deductibility, credit source", "2B3B7A"],
  ["Department Head","Reviews requests from personnel in their own office", NAVY],
  ["HR Management","Certifies classification and available credits against CSC Omnibus Rules", "2B3B7A"],
  ["Municipal Mayor","Records the final approval decision", NAVY]
];
lane.forEach((l, i) => {
  const y = 1.58 + i*0.80;
  s.addShape(pres.ShapeType.roundRect, { x:0.62, y:y, w:8.4, h:0.68, rectRadius:0.06,
    fill:{ color:l[2] }, line:{ color:l[2], width:1 } });
  num(s, i+1, 0.86, y+0.16, 0.36, ICE, NAVY);
  s.addText(l[0], { x:1.36, y:y, w:2.5, h:0.68, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:12.5, bold:true, color:WHITE });
  s.addText(l[1], { x:3.92, y:y, w:4.9, h:0.68, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:10.5, color:"C6D2F2" });
  if (i < 4) arrowD(s, 1.04, y+0.69, 0.10, "9AAAD4");
});

card(s, { x:0.62, y:5.62, w:8.4, h:1.06, fill:ICE, line:"A9C2EC" });
s.addText("6", { x:0.86, y:5.78, w:0.36, h:0.36, isTextBox:true, margin:0, align:"center", valign:"middle",
  fontFace:B, fontSize:12, bold:true, color:WHITE });
s.addShape(pres.ShapeType.ellipse, { x:0.86, y:5.78, w:0.36, h:0.36, fill:{ color:NAVY }, line:{ color:NAVY, width:1 } });
s.addText("6", { x:0.86, y:5.78, w:0.36, h:0.36, isTextBox:true, margin:0, align:"center", valign:"middle",
  fontFace:B, fontSize:12, bold:true, color:WHITE });
s.addText("Credit computation & posting", { x:1.36, y:5.76, w:3.4, h:0.28, isTextBox:true, margin:0,
  fontFace:B, fontSize:12.5, bold:true, color:NAVY });
s.addText("Working days computed against the holiday calendar; balances deducted inside a locking transaction and written to leave history — never negative.",
  { x:1.36, y:6.06, w:7.4, h:0.5, isTextBox:true, margin:0,
    fontFace:B, fontSize:10.5, color:"3A4670", lineSpacing:14 });

// data stores on the right
s.addText("DATA STORES WRITTEN", { x:9.42, y:1.58, w:3.4, h:0.26, isTextBox:true, margin:0,
  fontFace:B, fontSize:10.5, bold:true, charSpacing:1.2, color:MUTE });
const stores = ["Leave requests & documents","Approvals & workflow history","Leave balances & credit ledger","Leave types & policy configuration","Audit log (append-only)","Activity log","Intrusion & failed-login logs"];
stores.forEach((st, i) => {
  const y = 1.94 + i*0.68;
  const sec = i >= 4;
  card(s, { x:9.42, y:y, w:3.3, h:0.56, fill: sec ? ALERTL : SOFT, line: sec ? "F0D3CD" : "DCE5F6" });
  s.addText(st, { x:9.64, y:y, w:2.95, h:0.56, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:10.5, color: sec ? ALERT : TEXT });
});
footNote(s, "");
s.addNotes("Approval routing is enforced by the workflow service — a request cannot skip a stage, and balances change only at final approval.");

// =========================================================
// 9 — SECURITY CONTROL MAPPING
// =========================================================
s = pres.addSlide();
titleBar(s, "4.3.4  SECURITY CONTROL MAPPING", "Objectives traced to implemented controls");

const ctrl = [
  ["Confidentiality","HTTPS/TLS transport · OTP authentication · bcrypt password hashing"],
  ["Integrity","Server-side input validation · parameterized queries · automated computation"],
  ["Availability","LAN-based deployment · centralized server · scheduled backups"],
  ["Authentication","Username and password with email one-time password (OTP)"],
  ["Authorization","Role-Based Access Control with inheritance and per-user overrides"],
  ["Accountability","Append-only audit trail and page-level activity logs"],
  ["Threat detection","IP monitoring · login-attempt thresholds · real-time intrusion alerts"]
];
ctrl.forEach((c, i) => {
  const col = i % 2, row = Math.floor(i / 2);
  const x = 0.62 + col*6.18, y = 1.58 + row*1.16;
  card(s, { x:x, y:y, w:5.92, h:1.0, fill:SOFT, line:"DCE5F6" });
  s.addText(c[0], { x:x+0.26, y:y+0.14, w:5.4, h:0.28, isTextBox:true, margin:0,
    fontFace:B, fontSize:13, bold:true, color:NAVY });
  s.addText(c[1], { x:x+0.26, y:y+0.44, w:5.4, h:0.46, isTextBox:true, margin:0,
    fontFace:B, fontSize:11, color:TEXT, lineSpacing:14.5 });
});

card(s, { x:6.80, y:5.06, w:5.92, h:1.0, fill:ALERTL, line:"F0D3CD" });
s.addText("Documented limitation", { x:7.06, y:5.20, w:5.4, h:0.28, isTextBox:true, margin:0,
  fontFace:B, fontSize:13, bold:true, color:ALERT });
s.addText("Data at rest is not encrypted. Stored data is protected by host access control, localhost-only database binding, and physical security.",
  { x:7.06, y:5.50, w:5.4, h:0.46, isTextBox:true, margin:0,
    fontFace:B, fontSize:11, color:TEXT, lineSpacing:14.5 });

card(s, { x:0.62, y:6.20, w:12.1, h:0.62, fill:NAVY, line:NAVY });
s.addText("Threats addressed: SQL injection · brute-force login · unauthorized access · session hijacking · data tampering · credential theft · insider misuse",
  { x:0.9, y:6.20, w:11.55, h:0.62, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:11.5, color:ICE });
s.addNotes("Table 5 and Table 6 of the manuscript: the mapping provides traceability between objectives, threats, and controls. It does not by itself demonstrate effectiveness.");

// =========================================================
// 10 — TECHNICAL FRAMEWORK / STACK
// =========================================================
s = pres.addSlide();
titleBar(s, "4.9.5  TECHNICAL FRAMEWORK", "Four tiers, and the technology in each");

const tf = [
  ["CLIENT TIER", ["Web browser (desktop / mobile)","HTML5 · CSS3 · Bootstrap 5","JavaScript · Chart.js","SweetAlert2 · offline assets"], ICE, NAVY, "3A4670"],
  ["NETWORK TIER", ["Ethernet switch","Router / firewall policy","Wireless access point","HTTPS / TLS configuration"], "2B3B7A", WHITE, "C6D2F2"],
  ["APPLICATION TIER", ["Apache HTTP Server","PHP 8.3 · Laravel 12","Middleware security kernel","Service layer · queue · scheduler"], NAVY, WHITE, "C6D2F2"],
  ["DATA TIER", ["MySQL / MariaDB","Leave, employee & workflow records","Documents in file storage","Audit, activity & intrusion logs"], ICE, NAVY, "3A4670"]
];
tf.forEach((t, i) => {
  const x = 0.62 + i*3.09;
  card(s, { x:x, y:1.58, w:2.88, h:3.55, fill:t[2], line:t[2], shadow:true });
  s.addText(t[0], { x:x+0.24, y:1.80, w:2.45, h:0.3, isTextBox:true, margin:0,
    fontFace:B, fontSize:11.5, bold:true, charSpacing:1.2, color:t[3] });
  t[1].forEach((item, j) => {
    s.addText(item, { x:x+0.24, y:2.24 + j*0.68, w:2.45, h:0.6, isTextBox:true, margin:0,
      fontFace:B, fontSize:11, color:t[4], lineSpacing:14.5 });
  });
  if (i < 3) arrowR(s, x+2.92, 3.32, 0.14, "9AAAD4");
});

const val = [
  ["Verification tooling","Wireshark for traffic capture · OWASP ZAP for vulnerability scanning · SQLMap for injection testing · Hydra for controlled brute-force simulation"],
  ["Quality reference","ISO/IEC 25010 — functional suitability, usability, performance efficiency, security — evaluated by end users and IT experts"]
];
val.forEach((v, i) => {
  const y = 5.36 + i*0.74;
  card(s, { x:0.62, y:y, w:12.1, h:0.64, fill:SOFT, line:"DCE5F6" });
  s.addText(v[0], { x:0.88, y:y, w:2.4, h:0.64, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:11.5, bold:true, color:NAVY });
  s.addText(v[1], { x:3.36, y:y, w:9.1, h:0.64, isTextBox:true, margin:0, valign:"middle",
    fontFace:B, fontSize:11, color:TEXT });
});
s.addNotes("The four tiers separate user access, network transport, application processing, and persistent storage so each can be configured, tested, and maintained independently.");

// =========================================================
// 11 — CLOSING
// =========================================================
s = pres.addSlide();
s.background = { color: NAVY };
s.addText("WHAT THE ARCHITECTURE DELIVERS", { x:0.85, y:0.95, w:9, h:0.3, isTextBox:true, margin:0,
  fontFace:B, fontSize:12, bold:true, charSpacing:2.2, color:ICE });
s.addText("Centralized, controlled, traceable", {
  x:0.8, y:1.34, w:11.6, h:0.78, isTextBox:true, margin:0, valign:"top",
  fontFace:H, fontSize:40, bold:true, color:WHITE });
s.addText("What the architecture is built to deliver — and where the evidence for each claim comes from.", {
  x:0.85, y:2.18, w:11.5, h:0.34, isTextBox:true, margin:0,
  fontFace:B, fontSize:14, color:"9FB4E0" });

const close = [
  ["Efficiency","Submission, routing, classification, and credit computation are automated, removing repeated manual steps from the paper process."],
  ["Accuracy","Leave rules are stored as configuration and applied uniformly, with balance updates committed transactionally."],
  ["Security","Layered controls span the network boundary, transport, request handling, identity, authorization, and monitoring."],
  ["Accountability","Append-only audit and intrusion records give administrators evidence for review and for controlled security testing."]
];
close.forEach((c, i) => {
  const col = i % 2, row = Math.floor(i / 2);
  const x = 0.8 + col*6.0, y = 3.00 + row*1.62;
  s.addShape(pres.ShapeType.roundRect, { x:x, y:y, w:5.72, h:1.42, rectRadius:0.08,
    fill:{ color:"2B3B7A" }, line:{ color:"3D50A0", width:1 } });
  s.addText(c[0], { x:x+0.28, y:y+0.16, w:5.1, h:0.3, isTextBox:true, margin:0,
    fontFace:B, fontSize:14, bold:true, color:ICE });
  s.addText(c[1], { x:x+0.28, y:y+0.50, w:5.16, h:0.8, isTextBox:true, margin:0,
    fontFace:B, fontSize:11.5, color:"C6D2F2", lineSpacing:15.5 });
});
s.addText("Efficiency, accuracy, and security claims are evaluated using the procedures defined in Chapter III — the architecture states intent, the testing provides the evidence.",
  { x:0.8, y:6.42, w:11.6, h:0.4, isTextBox:true, margin:0,
    fontFace:B, fontSize:11.5, italic:true, color:"8095C9" });
s.addNotes("Close by linking the architecture back to the research questions: processing time, computation accuracy, control effectiveness, ISO 25010 quality, and alert latency/precision.");

pres.writeFile({ fileName: process.argv[2] || "System_Architecture.pptx" }).then(f => console.log("wrote", f));
