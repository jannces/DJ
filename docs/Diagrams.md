# System Diagrams

All diagrams are Mermaid (render on GitHub / VS Code). The ERD is in `Database.md`.

## 1. Context Diagram (Level -0)

```mermaid
flowchart LR
  EMP([Employee])
  DH([Department Head])
  HR([HR Officer])
  MAYOR([Municipal Mayor])
  ADMIN([System Admin / Super Admin])
  SMTP[[LAN SMTP Server]]
  S{{"Cybersecurity Integrated Digital\nLeave Management System"}}

  EMP -- leave applications, documents --> S
  S -- status, balances, notifications, Form 6 PDF --> EMP
  DH -- recommendations --> S
  HR -- certification, employee & leave admin, reports --> S
  MAYOR -- final decisions --> S
  ADMIN -- users, devices, settings, security ops --> S
  S -- OTPs, alerts, notifications --> SMTP
  S -- intrusion alerts, dashboards, audit trails --> ADMIN
```

## 2. DFD Level 0

```mermaid
flowchart TB
  subgraph External
    E1([Employee]); E2([Approvers: DH/HR/Mayor]); E3([Admin]); E4[[SMTP]]
  end
  P0((0\nLeave Management\n& Security System))
  D1[(D1 users/RBAC)]; D2[(D2 leave data)]; D3[(D3 security logs)]; D4[(D4 settings/devices)]
  E1 --> P0; E2 --> P0; E3 --> P0
  P0 <--> D1; P0 <--> D2; P0 <--> D3; P0 <--> D4
  P0 --> E4
```

## 3. DFD Level 1

```mermaid
flowchart TB
  E1([Employee]); E2([Approvers]); E3([Admin]); E4[[SMTP]]
  P1((1 Authenticate\n+ OTP + Device)); P2((2 Manage\nLeave)); P3((3 Compute\nCredits))
  P4((4 Approve\nWorkflow)); P5((5 Detect\nIntrusions)); P6((6 Audit\n& Report)); P7((7 Administer\nSystem))
  D1[(users/roles)]; D2[(leave_requests)]; D3[(leave_balances/history)]
  D4[(intrusion/failed/blocked)]; D5[(audit/activity)]; D6[(devices/settings)]

  E1 --> P1 --> D1; P1 --> E4; P1 --> D4
  E1 --> P2 --> D2; P2 --> P3; P3 --> D3
  E2 --> P4; P4 --> D2; P4 --> P3; P4 --> E4
  P5 --> D4; P5 --> E4; P5 -.-> E3
  P2 --> D5; P4 --> D5; P7 --> D5
  E3 --> P7 --> D6; E3 --> P6; P6 --> D5; D2 --> P6; D3 --> P6; D4 --> P6
```

## 4. Use Case Diagram

```mermaid
flowchart LR
  subgraph System
    UC1(Login + OTP); UC2(Apply Leave); UC3(Upload Documents); UC4(Cancel Request)
    UC5(View Balances/History); UC6(Recommend Leave); UC7(Certify Credits)
    UC8(Final Approve); UC9(Manage Employees/Departments); UC10(Generate Reports)
    UC11(Manage Users/Roles); UC12(Manage Devices); UC13(Monitor Security)
    UC14(Unblock Account/IP); UC15(Configure Settings/Leave Types)
  end
  Employee --- UC1 & UC2 & UC3 & UC4 & UC5
  DeptHead --- UC1 & UC6
  HR --- UC1 & UC7 & UC9 & UC10 & UC15
  Mayor --- UC1 & UC8
  Admin --- UC1 & UC11 & UC12 & UC13 & UC14 & UC15
```

## 5. Class Diagram (domain core)

```mermaid
classDiagram
  class User { +status +blockedUntil +roles() +permissions() +hasPermission(slug) }
  class Role { +slug +parent +permissions() }
  class Permission { +slug +module }
  class EmployeeProfile { +employeeNo +salary +department +position }
  class LeaveType { +code +maxDays +deductible +creditSource +detailSchema +requiredDocuments +approvalFlow }
  class LeaveRequest { +referenceNo +status +workingDays +details +submit() +cancel() }
  class Approval { +stepNo +roleSlug +action +comments +sign() }
  class LeaveBalance { +earned +used +balance }
  class LeaveHistory { +entryType +days +balanceAfter }
  class LeaveApplicationService { +submit(dto) +cancel(req) }
  class LeaveCreditService { +accrueMonthly() +deduct(req) +assertSufficient(req) }
  class ApprovalWorkflowService { +act(req, actor, decision) }
  class LeavePolicyEngine { +validate(dto) +requiredDocs(type, days) }
  class IntrusionDetectionService { +inspect(request) +recordEvent() +autoBlock(ip) }
  class AuditLogger { +log(action, model, old, new) }
  User "1" -- "0..1" EmployeeProfile
  User "*" -- "*" Role
  Role "*" -- "*" Permission
  Role --> Role : parent
  User "1" -- "*" LeaveRequest
  LeaveRequest "*" -- "1" LeaveType
  LeaveRequest "1" -- "*" Approval
  User "1" -- "*" LeaveBalance
  LeaveApplicationService ..> LeavePolicyEngine
  LeaveApplicationService ..> LeaveCreditService
  ApprovalWorkflowService ..> LeaveCreditService
  ApprovalWorkflowService ..> AuditLogger
```

## 6. Activity Diagram — leave approval workflow

```mermaid
flowchart TD
  A([Employee fills CSC Form 6]) --> B{Policy checks pass?}
  B -- "missing docs / no credits" --> A
  B -- "warnings only" --> C[Submit with warning flags]
  B -- ok --> C
  C --> D[Department Head review]
  D -->|Return for revision| A
  D -->|Recommend disapproval| H[HR validation]
  D -->|Recommend approval| H
  H -->|Return| A
  H --> I[HR certifies VL/SL credits & balance]
  I --> J[Mayor final action]
  J -->|Disapprove + reason| K[Status: Disapproved]
  J -->|Approve + days with/without pay| L[Deduct credits in DB transaction]
  L --> M[Write leave_history ledger]
  M --> N[Notify employee - bell + email]
  K --> N
  N --> O([End])
```

## 7. Sequence Diagram — login with OTP, lockout & IDS

```mermaid
sequenceDiagram
  actor U as User
  participant MW as Security Middleware
  participant AC as AuthController
  participant LS as LoginSecurityService
  participant OTP as OtpService
  participant DB as MySQL
  participant MAIL as SMTP (queued)

  U->>MW: POST /login
  MW->>MW: blocked IP? authorized device? IDS scan
  MW->>AC: pass
  AC->>LS: checkCredentials()
  alt wrong password
    LS->>DB: failed_logins++ (IP, UA, time)
    LS-->>U: error (attempt n/3)
    opt 3rd failure
      LS->>DB: users.status=blocked, blocked_until=+24h
      LS->>DB: intrusion_logs(auth_fail, high)
    end
  else correct
    AC->>OTP: issue(user)
    OTP->>DB: store hash, expires +5min
    OTP->>MAIL: queue OTP email
    U->>AC: POST /otp/verify code
    AC->>OTP: verify (≤5 attempts)
    OTP-->>AC: ok, mark consumed
    AC->>AC: session regenerate + otp_verified
    AC->>DB: audit_logs(login)
    AC-->>U: redirect role dashboard
  end
```

## 8. Deployment Diagram

```mermaid
flowchart TB
  subgraph Client["LGU Workstations (authorized devices)"]
    B[Browser - Chrome/Edge]
  end
  subgraph Server["LAN Server (XAMPP, Windows/Linux)"]
    AP[Apache 2.4 + TLS :443] --> PHP[PHP 8.3 - Laravel 12 app]
    PHP --> MY[(MySQL/MariaDB :3306 localhost)]
    PHP --> RD[(Redis / DB queue+cache)]
    W1[queue:work service] --> RD
    CR[schedule:run - cron/Task Scheduler] --> PHP
    FS[/storage: documents, backups/]
    PHP --> FS
  end
  MAILS[[LAN SMTP relay]]
  B == HTTPS ==> AP
  W1 --> MAILS
```

## 9. Network Diagram

```mermaid
flowchart LR
  subgraph LAN[LGU Alicia LAN 192.168.1.0/24]
    SW{{Managed Switch}}
    SRV[LMS Server .10\nApache 443]
    HRPC[HR PC .21]; ADPC[Admin PC .22]; DEPT[Dept PCs .31-.60]; MAYORPC[Mayor PC .23]
    MAILSRV[Mail Server .5]
    SW --- SRV & HRPC & ADPC & DEPT & MAYORPC & MAILSRV
  end
  FW[Firewall/Router] --- SW
  INET((Internet)) -. blocked for LMS .- FW
  note[Only registered device IPs pass the\nauthorized-device middleware]
```

## 10. Flowchart — automatic account lockout

```mermaid
flowchart TD
  S([Login attempt]) --> V{Password valid?}
  V -- yes --> R[Reset failure counter] --> OTPF[OTP flow]
  V -- no --> F[Record failed_logins row\nIP + browser + timestamp + reason]
  F --> C{Failures in window >= 3?}
  C -- no --> W[Show remaining attempts] --> S
  C -- yes --> BL[Block account 24h\nstatus=blocked, blocked_until]
  BL --> IL[intrusion_logs: auth_fail/high] --> AN[Alert admins + audit] --> E([Blocked page])
```

## 11. Flowchart — intrusion detection & auto IP block

```mermaid
flowchart TD
  RQ([Incoming request]) --> BIP{IP blocked?}
  BIP -- yes --> R403([403 blocked page])
  BIP -- no --> DEV{Authorized device?}
  DEV -- no --> LOGD[intrusion_logs: device] --> R403
  DEV -- yes --> SIG{Signature match?\nSQLi / XSS / traversal}
  SIG -- yes --> LOGS[intrusion_logs + severity]
  SIG -- no --> RATE{Rate anomaly?}
  RATE -- yes --> LOGS
  RATE -- no --> APP[Continue to app]
  LOGS --> TH{Events from IP >= threshold\nwithin window?}
  TH -- yes --> AB[Auto-insert blocked_ips 24h] --> ALERT[Queue email + dashboard alert] --> R403
  TH -- no --> APP2{Blockable category?}
  APP2 -- SQLi/XSS/traversal --> R400([400 rejected])
  APP2 -- log-only --> APP
```
