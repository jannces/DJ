# -*- coding: utf-8 -*-
"""Generates the consolidated system architecture diagram (SVG)."""

W, Hgt = 1900, 1040
NAVY, DEEP, ICE, SOFT, SOFT2 = "#1E2761", "#141B45", "#CADCFC", "#EEF3FC", "#F7F9FD"
MID, LINE = "#2B3B7A", "#C9D6EE"
ALERT, ALERTL, ALERTB = "#C43D2B", "#FBEDEA", "#E9BDB4"
TEXT, MUTE, WHITE = "#343B4F", "#78819A", "#FFFFFF"
F = "Liberation Sans, Arial, Helvetica, sans-serif"

o = []
def esc(t): return t.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
def rect(x, y, w, h, fill, stroke=None, rx=10, dash=None, sw=1.5):
    s = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" fill="{fill}"'
    if stroke: s += f' stroke="{stroke}" stroke-width="{sw}"'
    if dash: s += f' stroke-dasharray="{dash}"'
    o.append(s + '/>')
def txt(x, y, t, size=24, color=TEXT, bold=False, anchor="start", ls=0, op=1):
    weight = ' font-weight="700"' if bold else ''
    spacing = ' letter-spacing="%s"' % ls if ls else ''
    o.append('<text x="%s" y="%s" font-family="%s" font-size="%s" fill="%s"%s text-anchor="%s"%s>%s</text>'
             % (x, y, F, size, color, weight, anchor, spacing, esc(t)))
def arrow(x1, y1, x2, y2, color=NAVY, sw=3, marker="ah", dash=None):
    d = f' stroke-dasharray="{dash}"' if dash else ""
    o.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{color}" '
             f'stroke-width="{sw}"{d} marker-end="url(#{marker})"/>')

o.append(f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{Hgt}" viewBox="0 0 {W} {Hgt}">')
o.append(f'''<defs>
<marker id="ah" markerWidth="9" markerHeight="9" refX="7.5" refY="4.5" orient="auto">
  <path d="M0,0 L9,4.5 L0,9 z" fill="{NAVY}"/></marker>
<marker id="ahr" markerWidth="9" markerHeight="9" refX="7.5" refY="4.5" orient="auto">
  <path d="M0,0 L9,4.5 L0,9 z" fill="{ALERT}"/></marker>
<marker id="ahl" markerWidth="9" markerHeight="9" refX="7.5" refY="4.5" orient="auto">
  <path d="M0,0 L9,4.5 L0,9 z" fill="{ICE}"/></marker>
</defs>''')
rect(0, 0, W, Hgt, WHITE, rx=0)

# ---------------- LAN boundary ----------------
rect(20, 60, 1860, 940, "none", "#A9B7D8", rx=18, dash="10 8", sw=2)
txt(44, 44, "LGU OF ALICIA  —  INTERNAL LAN", 23, NAVY, True, ls=1.6)

# legend (top right, above the boundary line)
lx = 1300
o.append(f'<line x1="{lx}" y1="37" x2="{lx+44}" y2="37" stroke="{NAVY}" stroke-width="3" marker-end="url(#ah)"/>')
txt(lx + 54, 43, "request path", 21, MUTE)
o.append(f'<line x1="{lx+200}" y1="37" x2="{lx+244}" y2="37" stroke="{ALERT}" stroke-width="3" stroke-dasharray="7 6"/>')
txt(lx + 254, 43, "blocked", 21, MUTE)
o.append(f'<line x1="{lx+370}" y1="37" x2="{lx+414}" y2="37" stroke="{ALERT}" stroke-width="3" marker-end="url(#ahr)"/>')
txt(lx + 424, 43, "alert path", 21, MUTE)

# ---------------- clients ----------------
CX, CW, CY, CH = 44, 292, 112, 720
rect(CX, CY, CW, CH, SOFT2, LINE, rx=14)
txt(CX + 24, CY + 42, "AUTHORIZED CLIENTS", 22, MUTE, True, ls=1.4)
txt(CX + 24, CY + 72, "web browser only — no installed client", 20, MUTE)
roles = [("Employee", "files leave, tracks status"),
         ("Department Head", "reviews own office"),
         ("HR Management", "certifies credits"),
         ("Municipal Mayor", "final approval"),
         ("System Admin", "accounts, logs, monitoring"),
         ("Mobile (Wi-Fi)", "same app, authorized devices")]
for i, (r, sub) in enumerate(roles):
    y = CY + 96 + i * 88
    admin = (i == 4)
    rect(CX + 20, y, CW - 40, 74, WHITE, ALERT if admin else LINE, rx=9, sw=2.5 if admin else 1.5)
    txt(CX + 38, y + 32, r, 26, ALERT if admin else NAVY, True)
    txt(CX + 38, y + 58, sub, 20, MUTE)
txt(CX + 24, CY + CH - 26, "RBAC decides what each role sees", 20, NAVY, True)

# ---------------- network ----------------
def netbox(x, title, sub1, sub2):
    rect(x, 400, 176, 120, NAVY, NAVY, rx=12)
    txt(x + 88, 448, title, 26, WHITE, True, anchor="middle")
    txt(x + 88, 476, sub1, 20, ICE, anchor="middle")
    txt(x + 88, 500, sub2, 20, ICE, anchor="middle")
netbox(380, "Switch", "wired LAN +", "wireless AP")
netbox(600, "Firewall", "boundary and", "access policy")

# internet, blocked
rect(600, 4, 176, 52, ALERTL, ALERT, rx=10, dash="8 6", sw=2)
txt(688, 38, "Internet", 26, ALERT, True, anchor="middle")
o.append(f'<line x1="688" y1="58" x2="688" y2="398" stroke="{ALERT}" stroke-width="2.5" stroke-dasharray="9 8"/>')
rect(636, 196, 106, 40, WHITE, "none", rx=8)
txt(688, 224, "no route", 21, ALERT, True, anchor="middle")

arrow(340, 460, 376, 460)
arrow(560, 460, 596, 460)
arrow(784, 460, 896, 460)
txt(838, 438, "HTTPS / TLS", 20, NAVY, True, anchor="middle")

# deployment note (fills the gap under the network column)
rect(380, 600, 496, 186, SOFT, LINE, rx=12)
txt(404, 636, "DEPLOYMENT", 22, MUTE, True, ls=1.4)
for i, ln in enumerate(["One server hosts the app and the database",
                        "No public internet route to the system",
                        "Data at rest not encrypted \u2014 noted limitation"]):
    txt(404, 674 + i * 34, "\u2022   " + ln, 21, TEXT)

# ---------------- server ----------------
SX, SW_, SY, SH = 900, 956, 112, 720
IX, IW = SX + 22, SW_ - 44          # inner content band
rect(SX, SY, SW_, SH, NAVY, NAVY, rx=16)
txt(IX, SY + 42, "CENTRALIZED APPLICATION SERVER", 22, ICE, True, ls=1.4)

def band(y, h, label, fill, stroke):
    rect(IX, y, IW, h, fill, stroke, rx=10)

# A — web server
band(SY + 60, 56, None, MID, "#3D50A0")
txt(IX + 22, SY + 96, "Apache HTTP Server", 25, WHITE, True)
txt(IX + 320, SY + 96, "TLS termination · HTTPS only", 22, "#AFC0EA")

# B — middleware kernel
BY = SY + 130
rect(IX, BY, IW, 116, "#22307F", "#3D50A0", rx=10)
txt(IX + 18, BY + 30, "SECURITY MIDDLEWARE KERNEL — every request, in order", 21, ICE, True, ls=1.1)
kern = [("Blocked IP", "known bad"), ("Device check", "allow-list"), ("IDS scan", "SQLi / XSS"),
        ("Auth + OTP", "bcrypt, MFA"), ("RBAC gate", "permissions")]
kw = (IW - 4 * 10) / 5
for i, (t, sub) in enumerate(kern):
    x = IX + i * (kw + 10)
    hot = (i == 2)
    rect(x, BY + 42, kw, 62, ALERTL if hot else ICE, ALERT if hot else ICE, rx=8)
    txt(x + kw / 2, BY + 70, t, 23, ALERT if hot else NAVY, True, anchor="middle")
    txt(x + kw / 2, BY + 93, sub, 19, ALERT if hot else "#4A5680", anchor="middle")

# C — http layer
CY2 = BY + 130
band(CY2, 56, None, MID, "#3D50A0")
txt(IX + 22, CY2 + 36, "Routes · Controllers", 25, WHITE, True)
txt(IX + 330, CY2 + 36, "Form Requests — server-side validation", 22, "#AFC0EA")

# D — services
DY = CY2 + 70
rect(IX, DY, IW, 132, "#22307F", "#3D50A0", rx=10)
txt(IX + 18, DY + 30, "APPLICATION SERVICE LAYER — business rules live here", 21, ICE, True, ls=1.1)
svcs = [("Leave", "application"), ("Policy &", "credits"), ("Approval", "workflow"),
        ("Login", "security"), ("Audit, IDS", "& reports")]
for i, (l1, l2) in enumerate(svcs):
    x = IX + i * (kw + 10)
    rect(x, DY + 42, kw, 76, MID, "#4E62B8", rx=8)
    txt(x + kw / 2, DY + 70, l1, 23, WHITE, True, anchor="middle")
    txt(x + kw / 2, DY + 96, l2, 23, WHITE, True, anchor="middle")

# E — data access
EY = DY + 146
band(EY, 56, None, MID, "#3D50A0")
txt(IX + 22, EY + 36, "Eloquent models", 25, WHITE, True)
txt(IX + 260, EY + 36, "parameterized queries · locking transactions on balances", 22, "#AFC0EA")

# F — data tier
FY = EY + 70
rect(IX, FY, IW, 140, "#22307F", "#3D50A0", rx=10)
txt(IX + 18, FY + 30, "DATA TIER  —  same host, database bound to localhost", 21, ICE, True, ls=1.1)
stores = [("MySQL · leave data", ["employees, leave,", "balances, approvals"], ICE, NAVY),
          ("MySQL · security", ["audit, activity,", "intrusion, blocks"], ALERTL, ALERT),
          ("File storage", ["leave documents,", "exports, backups"], ICE, NAVY),
          ("Mail + scheduler", ["OTP & alert email,", "accrual jobs"], ICE, NAVY)]
sw_ = (IW - 3 * 12) / 4
for i, (t, lines, fill, fg) in enumerate(stores):
    x = IX + i * (sw_ + 12)
    rect(x, FY + 42, sw_, 84, fill, fill, rx=8)
    txt(x + sw_ / 2, FY + 69, t, 21, fg, True, anchor="middle")
    for j, ln in enumerate(lines):
        txt(x + sw_ / 2, FY + 94 + j * 22, ln, 19, fg if fill == ALERTL else "#4A5680", anchor="middle")


# ---------------- alert path ----------------
AY = 884
txt(1834, AY - 12, "REAL-TIME INTRUSION ALERT PATH", 21, ALERT, True, anchor="end", ls=1.2)
steps = [("Email on high severity", None), ("Admin dashboard refresh", "≤ 15 seconds"),
         ("Logged, source blocked", "intrusion + blocked IP"), ("Failed login / signature match", "detected in the kernel")]
aw = (1770 - 3 * 30) / 4
for i, (t, sub) in enumerate(steps):
    x = 64 + i * (aw + 30)
    rect(x, AY, aw, 84, ALERTL, ALERTB, rx=10)
    txt(x + aw / 2, AY + (34 if sub else 50), t, 24, ALERT, True, anchor="middle")
    if sub: txt(x + aw / 2, AY + 62, sub, 21, ALERT, anchor="middle")
for i in range(3):
    x = 64 + (i + 1) * (aw + 30)
    arrow(x - 4, AY + 42, x - 26, AY + 42, ALERT, 3, "ahr")
arrow(64 + aw / 2, AY - 6, 64 + aw / 2, CY + CH + 8, ALERT, 3, "ahr")
txt(64 + aw / 2 + 26, AY - 26, "alerts reach the System Administrator", 20, ALERT)

o.append('</svg>')
open("/tmp/claude-0/-home-user-DJ/20ed61d7-b78d-5bb4-abb2-863fea3f0b22/scratchpad/deck/architecture.svg", "w").write("\n".join(o))
print("svg written")
