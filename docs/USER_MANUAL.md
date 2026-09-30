# USER MANUAL

## A Cybersecurity Integrated Digital Leave Management System With Real-Time Intrusion Alerts For Local Government Unit Of Alicia

**Local Government Unit of Alicia**
**Leave Management System (LMS)**

| | |
|---|---|
| Document | User Manual |
| System version | As deployed on the LGU Alicia local area network (LAN) |
| Prepared for | Employees, HR, Department Heads, the Municipal Mayor and the System Administrator of LGU Alicia |
| Prepared by | *[Name of proponents]* |
| Date | *[Date of release]* |

> This manual describes the system **as it is actually built**. Where the system works
> differently from what earlier project documents describe, the difference is listed in the
> **User Manual Validation Check** at the end of this document.

---

## TABLE OF CONTENTS

1. [Introduction](#1-introduction)
   - 1.1 Purpose of the User Manual
   - 1.2 System Overview
   - 1.3 Intended Users
   - 1.4 System Scope
   - 1.5 System Requirements
2. [Getting Started](#2-getting-started)
   - 2.1 Accessing the System
   - 2.2 System Login
   - 2.3 OTP Verification
   - 2.4 First Login: Changing Your Password
   - 2.5 User Dashboard
   - 2.6 Navigation
   - 2.7 Logging Out
   - 2.8 Forgot Password
3. [Employee User Guide](#3-employee-user-guide)
   - 3.1 Employee Dashboard
   - 3.2 Viewing Leave Credits
   - 3.3 Uploading Your Signature (My Signature)
   - 3.4 Applying for Leave
   - 3.5 Selecting the Leave Type
   - 3.6 Completing the Leave Application
   - 3.7 Uploading/Attaching Requirements
   - 3.8 Submitting a Leave Request
   - 3.9 Checking Leave Request Status
   - 3.10 Viewing Leave History
   - 3.11 Cancelling a Leave Request
   - 3.12 Viewing Notifications
   - 3.13 Downloading/Printing Leave Documents
   - 3.14 Viewing My Audit Log
4. [HR User Guide](#4-hr-user-guide)
   - 4.1 HR Dashboard
   - 4.2 Viewing Leave Requests
   - 4.3 Reviewing Applications
   - 4.4 Processing Leave Requests
   - 4.5 Managing Leave Records (Employees, Departments, Positions, Holidays)
   - 4.6 Managing Leave Credits
   - 4.7 Managing Leave Types
   - 4.8 Leave Rankings
   - 4.9 Reports
   - 4.10 Printing/Exporting Documents
5. [Approving Officer User Guide](#5-approving-officer-user-guide)
   - 5.1 Who Can Approve in This System
   - 5.2 Leave Approvals Page
   - 5.3 Viewing Pending Requests
   - 5.4 Reviewing Leave Details
   - 5.5 Approving a Request
   - 5.6 Disapproving (Rejecting) a Request
   - 5.7 Returning a Request for Revision
   - 5.8 Viewing Request History
   - 5.9 Municipal Mayor: Overseeing Leave Requests
   - 5.10 Department Head: Recommendation (Box 7.B)
6. [Super Admin Guide (System Administrator)](#6-super-admin-guide-system-administrator)
   - 6.1 Security Dashboard
   - 6.2 Monitoring Login Attempts
   - 6.3 Viewing Security Alerts
   - 6.4 Viewing Suspicious Activity (Intrusion Logs)
   - 6.5 Viewing IP/Device Information
   - 6.6 Blocking/Restricting Suspicious Users or IP Addresses
   - 6.7 Viewing Audit Logs and Activity Logs
   - 6.8 Managing User Accounts
   - 6.9 Roles & Permissions
   - 6.10 Authorized Devices
   - 6.11 Backups
   - 6.12 System Settings
   - 6.13 Security Reports
7. [Leave Management](#7-leave-management)
   - 7.1 Leave Types
   - 7.2 Deductible Leave
   - 7.3 Non-Deductible Leave
   - 7.4 Leave Credit Computation
   - 7.5 Leave Balance
   - 7.6 Leave Request Statuses
   - 7.7 Approval Workflow
8. [Security and Account Guidelines](#8-security-and-account-guidelines)
9. [Troubleshooting](#9-troubleshooting)
10. [Frequently Asked Questions](#10-frequently-asked-questions)
11. [System Support / Contact Information](#11-system-support--contact-information)
12. [Appendices](#12-appendices)
- [User Manual Validation Check](#user-manual-validation-check)

---

## 1. INTRODUCTION

### 1.1 Purpose of the User Manual

This manual explains, step by step, how to use the Leave Management System (LMS) of the
Local Government Unit of Alicia. It is written for the people who will use the system
every day: employees who file leave, HR personnel who process and decide leave
applications, department heads, the Municipal Mayor, and the System Administrator who
looks after accounts and security.

Read the sections that apply to your role. Every user should read **Section 2 (Getting
Started)** and **Section 8 (Security and Account Guidelines)**.

### 1.2 System Overview

The LMS replaces the paper-based leave process with a digital one. Employees fill in the
leave application on screen, following the layout of **CSC Form No. 6 (Revised 2020)**,
and HR reviews and decides it inside the system. The completed form can be downloaded as
a PDF and printed on the paper size the office uses.

The system provides:

- Digital leave application based on CSC Form No. 6
- Automatic counting of working days (weekends and holidays excluded)
- Leave credit monitoring for Vacation Leave and Sick Leave
- A single-step decision by HR (Approve, Disapprove, or Return for revision)
- An approval timeline so employees can follow their application
- In-system notifications (and email, where email is configured)
- Reports that can be viewed on screen or downloaded as PDF or Excel
- Printable CSC Form No. 6, filled in or blank
- Role-based access: each user only sees the pages their role needs
- Two-step sign-in (password, then a one-time code sent by email)
- Account lockout after repeated wrong passwords
- Audit logs and activity logs
- Intrusion detection with real-time security alerts and IP blocking

The system runs on a server inside the LGU and is used through a web browser over the
LGU's **local area network (LAN)**.

**Figure 1. System Login Page**

[INSERT SCREENSHOT: Login Page]

*The sign-in page is the first page every user sees.*

### 1.3 Intended Users

The system has **five roles**. Each account is given one or more roles by the System
Administrator.

| Role (as named in the system) | Called in this manual | What the role does |
|---|---|---|
| **Employee** | Employee | Files, tracks and cancels their own leave; views their own leave credits. |
| **Department Head** | Department Head | Everything an Employee can do. Is notified when someone in their office files leave, can record a recommendation (Box 7.B), and sees their own office's figures. **Does not approve or disapprove.** |
| **HR** | HR / Approving Officer | Everything an Employee can do. Reviews and **decides** all leave applications; manages employee leave records, leave credits, leave types, holidays, departments and positions; generates leave reports. |
| **Municipal Mayor** | Mayor | Everything an Employee can do. Can view **all** leave applications and generate leave reports. Is named as head of agency at the foot of the printed CSC Form No. 6. **Does not approve or disapprove inside the system.** |
| **System Administrator** | Super Admin / System Administrator | Manages user accounts, roles, authorized devices, security monitoring, logs, backups and system settings. Does not file or handle leave. |

> **Note:** The role list is fixed in the system. There is no separate "Vice Mayor" or
> "Super Admin" role. The highest administrative role is the **System Administrator**
> (the first account installed with the system uses the username `superadmin`).

### 1.4 System Scope

**Covered by the system:**

- Filing, tracking and cancelling leave applications (15 CSC leave types, plus any custom
  types HR adds)
- HR review and decision on leave applications
- Leave credit balances, monthly credit earning, deductions and HR adjustments
- Leave history (credit history and past applications)
- Printable CSC Form No. 6
- Leave and security reports
- User accounts, roles and permissions
- Login security, audit and activity logs, intrusion detection, IP blocking
- Database backups

**Not covered by the system:**

- Payroll or salary computation
- Recruitment, appointments or performance evaluation
- Connection to any outside government database
- Use outside the LGU network (the system is intended for the LGU's LAN)

### 1.5 System Requirements

**For users (client computer):**

| Item | Requirement |
|---|---|
| Network | A computer or device connected to the LGU Alicia local network |
| Browser | A current version of Google Chrome, Microsoft Edge or Mozilla Firefox |
| Email | Access to the email address registered on your account (used for the one-time code and password reset) |
| PDF viewer | Any PDF viewer, or the browser itself, to open and print CSC Form No. 6 and reports |
| Files | Supporting documents in PDF, JPG or PNG (up to 5 MB each). Signature image in PNG or JPG (up to 8 MB) |

**For the server (maintained by the System Administrator):** the system runs on a
Windows computer with Apache, PHP and MySQL (XAMPP). Installation steps are in
`docs/RUN_ON_YOUR_PC.md` and `docs/Deployment.md` and are not repeated here.

---

## 2. GETTING STARTED

### 2.1 Accessing the System

**Purpose:** Open the Leave Management System in your browser.

**Steps:**

1. Make sure your computer is connected to the LGU network.
2. Open your web browser.
3. Type the system address given by the System Administrator in the address bar, for
   example **`https://onealicialms.lan`**, then press **Enter**.

**Expected Result:** The **Sign in** page appears with the title "Welcome back".

**Notes:**

- The system address is set during installation. If the address above does not open,
  ask the System Administrator for the correct one.
- If you see **"Device not authorized"**, your computer is not yet registered. Ask the
  System Administrator to register it (see Section 6.10).
- If you see **"Access blocked"**, your computer's IP address has been blocked because of
  suspicious activity. Contact the System Administrator (see Section 9.3).

### 2.2 System Login

**Purpose:** Sign in with your account.

**Steps:**

1. In the **Email or username** field, type your registered email address or username.
2. In the **Password** field, type your password.
   - Click the **eye icon** to show or hide what you typed.
3. Click **Sign in**.

**Figure 2. Sign-in Page with Fields Labelled**

[INSERT SCREENSHOT: Login Page — callouts: (1) Email or username, (2) Password, (3) eye icon, (4) Forgot password?, (5) Sign in]

**Expected Result:** If your email/username and password are correct, the system sends a
6-digit code to your email and shows the **Verify it's you** page (Section 2.3).

**Notes:**

- If the password is wrong, the message tells you how many attempts remain, for example:
  *"Invalid password. 2 attempt(s) remaining before the account is blocked."*
- After **3** wrong passwords the account is **blocked for 24 hours** (default setting).
  See Section 9.3.
- Too many sign-in attempts within one minute shows *"Too many attempts. Try again in …
  seconds."* Wait, then try again.

### 2.3 OTP Verification

**Purpose:** Complete the second step of sign-in using the one-time password (OTP).

**Steps:**

1. Open the email account registered on your LMS account.
2. Look for the email containing your **6-digit code**.
3. Go back to the **Verify it's you** page and type the 6 digits in the code boxes.
4. Click **Verify & continue**.

**Figure 3. OTP Verification Page**

[INSERT SCREENSHOT: OTP Verification Page — callouts: (1) code boxes, (2) Verify & continue, (3) Resend code, (4) Cancel]

**Expected Result:** Your dashboard opens.

**Notes:**

- The code expires after **5 minutes** (default setting). The page shows the exact time.
- If the code expired or did not arrive, click **Resend code**. A new code replaces the
  old one. After several resends the button shows a countdown ("Resend in 01:30") before
  you can ask again.
- Click **Cancel** to stop signing in and return to the sign-in page.
- A wrong or expired code shows *"Invalid or expired code."*
- The OTP step can be turned off by the System Administrator (setting `auth.otp_enabled`).
  If it is off, you go straight to the dashboard after entering your password.

### 2.4 First Login: Changing Your Password

**Purpose:** Replace the first-time password given to you when your account was created
or reset.

New accounts, and accounts whose password was reset by the System Administrator, must
set a new password before they can use any other page.

**Steps:**

1. Sign in with the first-time password given to you by the System Administrator, then
   complete the OTP step.
2. The **Change password** page opens automatically.
3. Type the first-time password in **Current password**.
4. Type your new password in **New password**.
5. Type the same new password again in **Confirm new password**.
6. Click **Update password**.

**Figure 4. Change Password Page**

[INSERT SCREENSHOT: Change Password Page]

**Expected Result:** The message *"Password updated successfully."* appears and your
dashboard opens.

**Notes:** Your new password must:

- be at least **12 characters** long;
- contain both **uppercase and lowercase** letters;
- contain at least one **number**;
- contain at least one **symbol** (for example `! @ # $ %`);
- be **different** from your current password.

You can change your password again at any time from the **user menu → Change password**
(top-right corner).

### 2.5 User Dashboard

After signing in, you land on your **Dashboard**. What it shows depends on your role:

| Role | What the dashboard shows |
|---|---|
| Employee | **My leave**: credits, pending applications, recent applications, credit history |
| Department Head | Two tabs: **My leave** and **My office** (the office they head) |
| HR | Two tabs: **My leave** and **Leave management** (LGU-wide figures) |
| Mayor | **My leave** (the Mayor views all applications through *All Leave Requests*) |
| System Administrator | Goes straight to the **Security Dashboard** |

**Figure 5. Employee Dashboard**

[INSERT SCREENSHOT: Employee Dashboard]

### 2.6 Navigation

The screen has three parts:

1. **Sidebar (left).** The menu. It only lists the pages your role is allowed to open.
   Menu items are grouped under headings: **Leave**, **HR Management**, **Reports** and
   **Administration**.
2. **Top bar.** Contains, from left to right:
   - the **menu button** (collapses or opens the sidebar; on phones it opens the menu),
   - the **bell icon** (Notifications, with a count of unread items),
   - the **moon/sun icon** (switches between light and dark mode),
   - your **name and role** (click it to open the user menu with **Change password** and
     **Sign out**).
3. **Main area.** The page you are working on.

**Figure 6. Main Screen Layout**

[INSERT SCREENSHOT: Main Screen — callouts: (1) sidebar, (2) menu button, (3) bell icon, (4) light/dark mode, (5) user menu]

**Sidebar items by role:**

| Sidebar item | Employee | Dept. Head | HR | Mayor | System Admin |
|---|:-:|:-:|:-:|:-:|:-:|
| Dashboard | ✓ | ✓ | ✓ | ✓ | |
| Security Dashboard | | | | | ✓ |
| **Leave:** Apply for Leave, My Leave Requests, My Signature, My Audit Log | ✓ | ✓ | ✓ | ✓ | |
| **HR Management:** Leave Approvals | | | ✓ | | |
| All Leave Requests | | | ✓ | ✓ | |
| Employees, Departments, Positions, Leave Balances, Leave Types, Holidays | | | ✓ | | |
| Leave Rankings | | ✓ | ✓ | | |
| **Reports** | | ✓ | ✓ | ✓ | ✓ |
| **Administration:** Users, Roles & Permissions, Authorized Devices, Blocked IPs, Intrusion Logs, Audit Logs, Activity Logs, Backups, System Settings | | | | | ✓ |

**Messages on screen:** green messages confirm an action succeeded, yellow messages are
warnings you should read, and red messages mean the action was refused or something must
be corrected.

### 2.7 Logging Out

**Purpose:** End your session safely.

**Steps:**

1. Click your **name** in the top-right corner.
2. Click **Sign out**.

**Expected Result:** The sign-in page appears with the message *"You have been signed out."*

**Notes:** If you leave the system idle for **30 minutes** (default setting), you are
signed out automatically with the message *"Your session expired due to inactivity.
Please sign in again."*

### 2.8 Forgot Password

**Purpose:** Set a new password when you cannot remember yours.

**Steps:**

1. On the sign-in page, click **Forgot password?**
2. Type your registered email in **Email address**.
3. Click **Email reset link**.
4. Open the email and click the reset link.
5. On the **Choose a new password** page, enter your email, your **New password**, and
   **Confirm new password**.
6. Click **Update password**.

**Expected Result:** The sign-in page shows *"Password updated. Sign in with your new
password."*

**Notes:**

- For privacy, the system always answers *"If that email is registered, a reset link has
  been sent."* even when the email is not on file.
- Only 3 reset requests are allowed every 5 minutes from one computer.
- If you do not receive the email, ask the System Administrator to reset your password
  (Section 6.8).

---

## 3. EMPLOYEE USER GUIDE

This section applies to every user who files leave: Employees, Department Heads, HR and
the Mayor.

### 3.1 Employee Dashboard

**Purpose:** See your leave situation at a glance.

**Steps:**

1. Click **Dashboard** in the sidebar.
2. If you have two tabs (Department Head or HR), make sure **My leave** is selected.

**Expected Result:** The dashboard shows:

| Part | What it shows |
|---|---|
| **Vacation left** | Your remaining Vacation Leave credits, "of X earned" |
| **Sick left** | Your remaining Sick Leave credits, "of X earned" |
| **Waiting on a decision** | How many of your applications are still pending |
| **Taken this year** | Days of approved leave this year |
| **Credits remaining, by type** | A bar for each leave type with credits on record |
| **Recent leave applications** | Your latest applications and their status |
| **Credit history** | Every credit earned, used or adjusted, with the balance after it |

**Figure 7. My Leave Dashboard**

[INSERT SCREENSHOT: Employee Dashboard — callouts: (1) Vacation left, (2) Sick left, (3) Waiting on a decision, (4) Credits remaining, (5) Recent leave applications, (6) Credit history]

### 3.2 Viewing Leave Credits

**Purpose:** Check how many leave credits you have.

**Steps:**

1. Click **Dashboard**.
2. Read the **Vacation left** and **Sick left** cards at the top.
3. Scroll to **Credits remaining, by type** for every type that has credits.
4. Scroll to **Credit history** to see each monthly credit, deduction and adjustment.

**Expected Result:** You see your current balances and how they were reached.

**Notes:**

- A card showing "—" and "no credits accrued" means no credits have been recorded yet.
  Ask HR to check your record.
- Only Vacation Leave and Sick Leave earn credits each month (1.25 days each, default).
  See Section 7.4.

### 3.3 Uploading Your Signature (My Signature)

**Purpose:** Save an image of your signature so it appears on your printed CSC Form No. 6.

This step is optional. Without a signature image, your typed name is printed on the
applicant's line.

**Steps:**

1. Sign your name on a blank sheet of white paper.
2. Take a clear photo or scan of the signature.
3. In the sidebar, click **My Signature**.
4. Click the file field and select the image (PNG or JPG, up to 8 MB).
5. Click **Save signature** (or **Replace signature** if you already have one).

**Figure 8. My Signature Page**

[INSERT SCREENSHOT: My Signature Page]

**Expected Result:** The page shows your signature **On file** with the upload date.

**Notes:**

- The system trims the blank paper around the signature and straightens the image.
- To remove it, click **Remove signature**. Applications already filed keep the
  signature they were filed with.
- Your signature is not publicly accessible. It is shown only to you, to HR, and to the
  head of your office, and only on applications you filed.
- If the page says your account has no employee record yet, ask HR/the System
  Administrator to complete your employee profile first.

### 3.4 Applying for Leave

**Purpose:** Start a new leave application.

**Steps:**

1. In the sidebar, click **Apply for Leave**.
2. The **Application for Leave** page opens. It is divided into four steps shown at the
   top: **1 Employee**, **2 Leave type**, **3 Dates**, **4 Sign & submit**.
3. If you need the documentary requirements for your leave type, click **Instructions
   and Requirements** at the top-right.

**Figure 9. Application for Leave — Step Indicator**

[INSERT SCREENSHOT: Apply Leave Form — step indicator and "Instructions and Requirements" button]

**Step 1 — Employee information**

1. Check your **Office/Department**, **Position**, **Monthly salary**, and **Last,
   First and Middle name**. These come from your employee record and cannot be edited
   here.
2. Check the **Date of filing** (today's date is filled in).
3. Click **Continue**.

**Figure 10. Step 1 — Employee Information**

[INSERT SCREENSHOT: Apply Leave Form — Step 1]

**Notes:** If any of your details are wrong, do not continue. Ask HR/the System
Administrator to correct your employee record first, because these details are printed
on the form.

### 3.5 Selecting the Leave Type

**Step 2 — Type of leave**

1. Open the **Leave type** list and choose one type. The list follows the order of CSC
   Form No. 6.
2. If your leave is not listed, use **Others, if not listed** to describe it.
3. Under **Details of leave**, answer the questions that appear for the type you chose.
   For example:
   - *Vacation / Mandatory-Forced / Special Privilege Leave:* **Where will it be spent?**
     (Within the Philippines / Abroad) and **If abroad, specify**. For Vacation Leave the
     system requires this box even for leave within the Philippines, so always type the
     place (see Validation Check, item C-10).
   - *Sick Leave:* **Where** (In hospital / Out patient) and **Specify illness**.
   - *Maternity Leave:* **Contingency** (Live childbirth / Miscarriage or emergency
     termination), **Expected / actual date of delivery**.
   - *Study Leave:* **Purpose** (Master's degree, BAR/Board review, Other).
   - *Special Emergency (Calamity) Leave:* **Declared calamity**, **Affected area**,
     **First day of calamity declaration**.
   - *Monetization:* **Reason for monetization** and **Number of days to monetize**.
   - *Terminal Leave:* **Separation** (Retirement / Resignation).
4. Click **Continue**.

**Figure 11. Step 2 — Type of Leave and Details of Leave**

[INSERT SCREENSHOT: Apply Leave Form — Step 2 with a leave type selected]

**Notes:** Fields marked with a red asterisk (*) are required. The full list of leave
types and their rules is in Section 7.1.

### 3.6 Completing the Leave Application

**Step 3 — When you will be away**

1. In **From**, choose the first day of leave.
2. In **To**, choose the last day of leave.
3. Click **Continue**.

**Figure 12. Step 3 — Leave Dates**

[INSERT SCREENSHOT: Apply Leave Form — Step 3]

**Notes:**

- The **Working days** box reads "Counted on submission". The system counts the days
  when you submit:
  - most types count **working days** only (weekends and holidays in the Holiday
    Calendar are excluded);
  - Maternity, Study, Rehabilitation, Special Leave Benefits for Women and Adoption Leave
    count **calendar days**.
- The last day cannot be earlier than the first day.

### 3.7 Uploading/Attaching Requirements

**Step 4 — Documents and signature (upload part)**

1. Under **Supporting documents**:
   - use **Primary supporting document** for the main requirement of your leave type;
   - use **Medical certificate (if applicable)** for a medical certificate.
2. Click each field and select the file (PDF, JPG or PNG, up to 5 MB each).

**Figure 13. Step 4 — Supporting Documents and Signature**

[INSERT SCREENSHOT: Apply Leave Form — Step 4]

**Notes:**

- Check **Instructions and Requirements** for the documents your leave type needs.
- The system does **not** stop you from submitting without documents. HR checks them
  when reviewing, and may return or disapprove an application that lacks them.
- You can add more documents after submitting (Section 3.9, "Adding documents later").

### 3.8 Submitting a Leave Request

**Step 4 — Documents and signature (submit part)**

1. Check **Signature of applicant**. Your name is filled in; this is your typed
   signature.
2. Use **Back** to review earlier steps if needed.
3. Click **Submit application**.

**Expected Result:**

- The page of your new application opens with the message *"Leave application submitted
  for review."*
- A reference number is assigned, for example **LV-2026-00012**.
- The status is **Pending**.
- You receive a notification that your application was submitted.
- If your office has a Department Head on record, they are notified automatically.

**Notes — the system may refuse or warn:**

| Message (example) | Meaning / what to do |
|---|---|
| *"Insufficient vacation credits: 2.50 available, 3.0 requested."* | You do not have enough credits for a deductible leave. Shorten the leave or choose another type. |
| *"… cannot exceed 5 day(s); you requested 6."* | The leave is longer than the type allows. |
| *"The field 'Specify illness' is required for Sick Leave."* | A required detail is missing. |
| *"This sick leave is filed after the leave dates; a late-filing reason is required."* | Fill in **If filed after returning to work, why?** in Step 2. |
| *"The selected range contains no working days …"* | The dates fall only on weekends/holidays. |
| Yellow note: *"Vacation Leave is recommended to be filed at least 5 day(s) before the leave date …"* | A **warning** only. The application is still submitted; HR sees the warning. |

If the form has errors, a red summary appears at the top and all four steps are shown at
once so you can find and fix the highlighted fields.

### 3.9 Checking Leave Request Status

**Purpose:** Follow the progress of your application.

**Steps:**

1. In the sidebar, click **My Leave Requests**.
2. Find the application in the list. The **Status** column shows **Pending**,
   **Returned**, **Approved**, **Disapproved** or **Cancelled**.
3. Click **View Form** on that row.

**Figure 14. My Leave Requests**

[INSERT SCREENSHOT: My Leave Requests list — callouts: (1) Status filter, (2) Status column, (3) View Form]

**Expected Result:** The **Form Preview** page opens. It shows:

- the filled-in CSC Form No. 6 exactly as it will print;
- **Approval progress** (the timeline);
- **Application details** (reference, type, dates, working days, status and, when
  approved, days with pay and days without pay; when disapproved, the reason);
- **Supporting documents**.

**Figure 15. Form Preview with Approval Progress**

[INSERT SCREENSHOT: Form Preview page — callouts: (1) Download Form, (2) Cancel application, (3) Approval progress, (4) Application details, (5) Supporting documents]

**How to read the Approval progress timeline:**

| Timeline entry | Meaning |
|---|---|
| **Application Submitted** | The date and time you filed. |
| **Department Head Notified** | The head of your office was informed. No approval is needed from them. (Shown only if your office has a head on record.) |
| **Recommended / Not Recommended by Department Head** | Your head recorded a recommendation. HR still decides. |
| **Pending Approval** | Waiting for HR to validate and decide. |
| **Returned to you for revision** | HR sent it back. Read HR's remarks (see below). |
| **Approved / Rejected / Cancelled** | The final result, with date and time. A rejection shows the reason. |

**Adding documents later:** while an application is not yet decided, the **Supporting
documents** card has an **Upload** row. Type the **Document type** (for example
"Medical certificate"), choose the **File**, and click **Upload**.

**If your application is Returned:** the system has no "edit" or "resubmit" button for a
filed application. Read HR's comments in your notification, then either upload the
missing document (above) and inform HR, or cancel the application (Section 3.11) and file
a corrected one. HR can still act on a returned application. *(See Validation Check,
item C-3.)*

### 3.10 Viewing Leave History

**Purpose:** See all your past applications and credit movements.

**Steps:**

1. Click **My Leave Requests** for the list of all your applications, newest first.
2. To narrow the list, use the **Status** filter (for example **Approved**).
3. Click **Dashboard** and scroll to **Credit history** for the credit ledger (monthly
   credits, deductions for approved leave, adjustments by HR).

**Expected Result:** You see your full leave history.

**Notes:** The Status filter also lists *Department review (archived flow)*, *HR review*
and *Final review*. These only apply to applications filed under an older version of the
workflow. New applications never receive these statuses.

### 3.11 Cancelling a Leave Request

**Purpose:** Withdraw an application you no longer need.

**Steps:**

1. Click **My Leave Requests**, then **View Form** on the application.
2. Click **Cancel application**.
3. Confirm in the dialog box.

**Expected Result:** The message *"Leave request cancelled."* appears and the status
becomes **Cancelled**.

**Notes:**

- Only **Pending** or **Returned** applications can be cancelled. Approved and
  disapproved applications cannot.
- Cancelling cannot be undone.

### 3.12 Viewing Notifications

**Purpose:** Read updates about your applications.

**Steps:**

1. Click the **bell icon** in the top bar. A number on the bell shows unread
   notifications.
2. Click a notification to open the related application.
3. Click **Mark all read** to clear the unread count.
4. Click **See all notifications** to open the full **Notifications** page, where you can
   **Mark read** one item or **Mark all read**.

**Figure 16. Notifications**

[INSERT SCREENSHOT: Notification bell dropdown]

**You are notified when your application is:** submitted, recommended or not recommended
by your Department Head, returned for revision, approved (credits updated), or
disapproved (with the reason). These notifications are also sent to your email when email
is configured on the server.

### 3.13 Downloading/Printing Leave Documents

**Purpose:** Get a printable copy of your CSC Form No. 6.

**Steps:**

1. Click **My Leave Requests**, then **View Form**.
2. To download on **Legal / long bond (8.5 × 14 in)**, click **Download Form**.
3. For another paper size, click the **small arrow** beside **Download Form** and choose
   **Folio / short bond**, **A4** or **Letter**.
4. The PDF opens in a new tab. Print it from the PDF viewer.

**Figure 17. Download Form and Paper Size Menu**

[INSERT SCREENSHOT: Download Form button with paper size menu open]

**Expected Result:** A one-page CSC Form No. 6 named `CSC-Form6-<reference number>.pdf`.

**Notes:**

- The form includes your signature image if you uploaded one, the leave credit
  certification (Box 7.A), the Department Head's recommendation (7.B), HR's decision
  (7.C or 7.D), and the Municipal Mayor's name at the foot.
- To open an uploaded supporting document, click **Download** beside it.

### 3.14 Viewing My Audit Log

**Purpose:** See what the system recorded about your own actions.

**Steps:**

1. In the sidebar, click **My Audit Log**.
2. Use the **Action** filter to narrow the list.

**Expected Result:** A list of your own recorded actions (for example sign-ins, leave
submissions, password changes) with **Time**, **Action**, **Target** and **Changes**.

**Notes:** You can see only your own records.

---

## 4. HR USER GUIDE

This section is for users with the **HR** role. HR also files leave like any employee
(Section 3). HR is the **Approving Officer** of the system; the decision itself is
described in Section 5.

> **Account creation is not an HR function.** New accounts, and changes to a person's
> name, email, department, position or salary, are made by the System Administrator on
> the **Users** page (Section 6.8).

### 4.1 HR Dashboard

**Purpose:** See LGU-wide leave figures.

**Steps:**

1. Click **Dashboard**.
2. Click the **Leave management** tab.

**Expected Result:** The tab shows:

- **Total employees**, **Waiting on a decision**, **On leave this month**, **Filed this
  month**;
- **Applications filed** (switch between **Monthly** and **Yearly**);
- **Most applied leave type** (This month / This year);
- **Outcome of this year's applications** (Approved, Rejected, Waiting);
- **Applications by office**.

**Figure 18. HR Dashboard — Leave Management Tab**

[INSERT SCREENSHOT: HR Dashboard — Leave management tab]

### 4.2 Viewing Leave Requests

**Purpose:** Find any leave application in the LGU.

**Steps:**

1. In the sidebar under **HR Management**, click **All Leave Requests**.
2. Search by **reference number or employee name**.
3. Filter by **Status** and/or **Type**.
4. Click a reference number or employee name to open the application.

**Figure 19. All Leave Requests**

[INSERT SCREENSHOT: All Leave Requests — callouts: (1) search, (2) Status filter, (3) Type filter, (4) "pending here" count]

**Expected Result:** A list showing when each application was filed, the employee, the
leave type, dates and status.

**Notes:** The applications waiting for HR's decision are listed separately on **Leave
Approvals** (Section 5.2).

### 4.3 Reviewing Applications

**Purpose:** Check an application before deciding.

**Steps:**

1. Open the application from **Leave Approvals** (click **View** or the employee's name)
   or from **All Leave Requests**.
2. Read **Application details**: applicant, office, position, working days, leave dates,
   details of leave, late-filing reason and any **filing warnings** (yellow box).
3. Read the **Approval timeline**, including any Department Head recommendation.
4. Under **Supporting documents**, click **Download** to open each file.
5. Check the employee's credits if needed (Section 4.6 or the employee's page under
   **Employees**).
6. To see the complete form, use **Download Form**.

**Figure 20. Leave Request Details (HR View)**

[INSERT SCREENSHOT: HR Leave Request Processing Page — application details, timeline, documents]

**Expected Result:** You have the information needed to approve, disapprove or return
the application.

### 4.4 Processing Leave Requests

HR processes an application by making a decision on the **Leave Approvals** page. The
full procedure is in **Section 5.5 to 5.7**.

In summary:

| Decision | Result |
|---|---|
| **Approve** | Status becomes **Approved**. For deductible leave, the working days are deducted from the employee's credits. |
| **Disapprove** | Status becomes **Disapproved**. Your comments are saved as the reason. |
| **Return for revision** | Status becomes **Returned**. The application stays on your Leave Approvals list. |

### 4.5 Managing Leave Records

#### 4.5.1 Employees

**Purpose:** View an employee's record, applications and credits.

**Steps:**

1. Click **Employees**.
2. Search by **name, email or employee no.**, or filter by **Department** or **Position**.
3. Click an employee's name.

**Expected Result:** The employee page shows **Profile**, **Leave requests** and
**Leave credits**.

**Notes:** This page is view-only. Changes to employee details are made by the System
Administrator in **Users**.

#### 4.5.2 Departments

**Purpose:** Maintain the list of offices and name each office's head.

**Steps:**

1. Click **Departments**.
2. To add one, click **New department**, fill in **Name**, **Code** and **Department
   Head**, then click **Save department**.
3. To change one, click the edit icon on its row, make the change, then click **Save
   changes**.

**Expected Result:** The department list shows each office, its head and its number of
employees.

**Notes:** The person selected as **Department Head** here is the one notified when
someone in that office files leave, and whose name appears in Box 7.B of the form.

#### 4.5.3 Positions

1. Click **Positions**.
2. Click **New position**, enter the **Title** and **Salary grade**, then click **Save
   position**; or click the edit icon on a row to change it, then click **Save changes**.

#### 4.5.4 Holidays

**Purpose:** Keep the holiday calendar correct, because holidays are excluded when the
system counts working days.

**Steps:**

1. Click **Holidays**. The **Holiday Calendar** page opens.
2. Click **Add holiday**.
3. Enter the **Date**, **Name** and **Scope** (**National** or **Local**).
4. Click **Save holiday**.
5. To delete a holiday, click the remove icon on its row.

**Figure 21. Holiday Calendar**

[INSERT SCREENSHOT: Holiday Calendar with Add holiday form]

**Notes:** Add holidays **before** employees file leave that covers those dates.
Applications already filed are not recounted.

### 4.6 Managing Leave Credits

**Purpose:** Correct or add to an employee's leave credits (for example, carry-over
balances when the system is first used).

**Steps:**

1. Click **Leave Balances**.
2. Search for the employee, or filter by **Department**.
3. Click **Adjust** on the employee's row.
4. For each leave type you want to change, type the number of days to **add** (for
   example `5`) or **deduct** (for example `-2`).
5. Type the **Reason for this adjustment** (required).
6. Click **Apply**.

**Figure 22. Leave Balances — Adjust Window**

[INSERT SCREENSHOT: Leave Balances Adjust window]

**Expected Result:** The new balance appears on the list. The change is recorded in the
employee's **Credit history** as an adjustment, with your reason.

**Notes:**

- A balance can never go below zero. A deduction larger than the balance is refused.
- Every adjustment is recorded in the audit log.

### 4.7 Managing Leave Types

**Purpose:** Review or change the rules of each leave type.

**Steps:**

1. Click **Leave Types**.
2. To change a type, click its edit icon. To add a new type, click **Custom type**.
3. Set the fields:
   - **Code**, **Name**, **Category**, **Description**;
   - **Max days**, **Filing deadline (days)**, **Medical cert. after (days)**;
   - **Credit source** (None, Vacation or Sick);
   - checkboxes: **Deductible from credits**, **Counted in calendar days**, **Filing
     deadline is a hard rule (blocks)**, **Resets annually**, **Active**.
4. Click **Save leave type**.

**Figure 23. Edit Leave Type**

[INSERT SCREENSHOT: Leave Type form]

**Notes:** Changes affect **new** applications. Change the standard CSC types only when a
new CSC rule requires it.

### 4.8 Leave Rankings

**Purpose:** See which employees have used the most leave.

**Steps:** Click **Leave Rankings**. Search for an employee if needed.

**Expected Result:** A ranked list with **Employee**, **Office**, **Position**, **Days
used**, **Earned** and **Remaining**. HR sees the whole LGU; a Department Head sees only
their own office.

### 4.9 Reports

**Purpose:** Produce leave reports for monitoring and filing.

**Steps:**

1. Click **Reports**.
2. Find the report card you need.
3. Set the filters shown on the card (**Month**, **Year**, and/or **Department**).
4. Click one of:
   - **View** — opens the report on screen (new tab);
   - **PDF** — downloads a PDF (A4, landscape);
   - **Excel** — downloads an Excel (.xlsx) file.

**Figure 24. Reports Page**

[INSERT SCREENSHOT: Reports page — report cards with View, PDF and Excel buttons]

**Reports available to HR (and the Mayor):**

| Report | Covers |
|---|---|
| Employee Leave Report | Applications within a month/year |
| Leave Type Summary | Applications per leave type |
| Department Report | Applications per office |
| Pending Applications | Everything still waiting, as of today |
| Mandatory Leave Compliance | Mandatory/forced leave taken for a year |
| Leave Balance Report | Every employee's balances, as of today |

**Notes:** Each generated report is recorded in the audit log. Department Heads see three
different reports limited to their office (Section 5.10). Security reports are for the
System Administrator (Section 6.13).

### 4.10 Printing/Exporting Documents

| Document | Where | How |
|---|---|---|
| Filled CSC Form No. 6 | Any application page | **Download Form** (choose paper size with the arrow) |
| **Blank** CSC Form No. 6 | **Leave Approvals** page | **Blank form** button (choose paper size with the arrow). Use it for walk-in applicants or when the network is down. |
| Reports | **Reports** | **PDF** or **Excel** |
| Supporting documents | Application page | **Download** beside each document |

---

## 5. APPROVING OFFICER USER GUIDE

### 5.1 Who Can Approve in This System

In the implemented system, **only users with the HR role can approve, disapprove or
return a leave application.** The decision is a **single step**: one HR decision settles
the application.

| Role | Can decide? | What they can do |
|---|---|---|
| **HR** | **Yes** | Approve, Disapprove, Return for revision (Sections 5.2–5.8) |
| **Municipal Mayor** | No | View all applications and leave reports; named at the foot of the printed form (Section 5.9) |
| **Department Head** | No | Receives a notification; may record a recommendation for Box 7.B (Section 5.10) |

Other rules the system enforces:

- No one can decide **their own** leave application.
- Once an application is **Approved**, **Disapproved** or **Cancelled**, it can no longer
  be changed.
- If two HR officers act on the same application at the same moment, only the first
  decision is saved; the second receives *"Another authorized officer has just decided
  this application."*

### 5.2 Leave Approvals Page

**Purpose:** Open the list of applications waiting for a decision.

**Steps:**

1. In the sidebar under **HR Management**, click **Leave Approvals**.

**Figure 25. Leave Approvals**

[INSERT SCREENSHOT: Approver Dashboard / Leave Approvals list — callouts: (1) Blank form, (2) View, (3) Act]

**Expected Result:** The page "Leave Approvals — Waiting on a decision" lists each
application with **Reference**, **Employee** (and office), **Type**, **Dates** and
**Days**. If there is nothing to act on, it says *"Nothing awaiting your action."*

### 5.3 Viewing Pending Requests

The Leave Approvals list contains applications that are **Pending** and applications
that were **Returned** for revision. Newest applications appear first. Use the page
numbers at the bottom to see more.

For LGU-wide counts, open **Dashboard → Leave management** (Section 4.1) or run the
**Pending Applications** report (Section 4.9).

### 5.4 Reviewing Leave Details

1. On the Leave Approvals list, click **View** (or the employee's name).
2. Review the application as described in Section 4.3.
3. Click **Back** (or **All Leave Requests**) to return, then open **Leave Approvals**
   again to decide.

### 5.5 Approving a Request

**Purpose:** Approve an application.

**Steps:**

1. On **Leave Approvals**, click **Act** on the application's row.
2. In the decision window, set **Decision** to **Approve**.
3. Check **Days with pay** (filled in with the working days) and **Days without pay**
   (0 by default). Change them if part of the leave is without pay.
4. Type any **Comments / remarks**.
5. Check **Signature (type your name)**. Your name is filled in.
6. Click **Submit decision**.

**Figure 26. Decision Window — Approve**

[INSERT SCREENSHOT: Decision window with "Approve" selected — callouts: (1) Decision, (2) Days with pay / without pay, (3) Comments / remarks, (4) Signature, (5) Submit decision]

**Expected Result:**

- The message *"Decision recorded."* appears and the application leaves the list.
- The status becomes **Approved**.
- For a deductible leave type (for example Vacation, Forced, Sick, Monetization, Terminal
  Leave), the working days are deducted from the employee's credits and recorded in their
  Credit history.
- The Vacation and Sick Leave balances at the time of the decision are saved as the
  certification printed in Box 7.A.
- The employee is notified.

**Notes:** See Validation Check item C-4 about how "days without pay" relates to the
credit deduction.

### 5.6 Disapproving (Rejecting) a Request

**Steps:**

1. Click **Act** on the application's row.
2. Set **Decision** to **Disapprove**.
3. Type the reason in **Comments / remarks**. This text becomes the **Disapproval
   reason** shown to the employee and printed in Box 7.D.
4. Click **Submit decision**.

**Expected Result:** The status becomes **Disapproved** (shown as "Rejected" on the
timeline). No credits are deducted. The employee is notified with the reason.

**Notes:** Always give a clear reason. The system accepts a disapproval without one, but
the employee and the printed form would then show no reason.

### 5.7 Returning a Request for Revision

**Steps:**

1. Click **Act** on the application's row.
2. Set **Decision** to **Return for revision**.
3. In **Comments / remarks**, explain exactly what the employee must correct or submit.
4. Click **Submit decision**.

**Expected Result:** The status becomes **Returned**. The employee is notified. The
application **stays on your Leave Approvals list**, so you can approve or disapprove it
later (for example after the employee uploads the missing document).

### 5.8 Viewing Request History

1. Click **All Leave Requests**.
2. Filter **Status** by **Approved**, **Rejected**, **Returned** or **Cancelled**, or
   search by reference number or name.
3. Open an application to see its **Approval timeline**, including who decided it and
   when.

For a full record of decisions, the System Administrator can view the **Audit Logs**
(Section 6.7).

### 5.9 Municipal Mayor: Overseeing Leave Requests

The Mayor's account can see every application but cannot decide them in the system.

**Steps:**

1. In the sidebar under **HR Management**, click **All Leave Requests**.
2. Search or filter as described in Section 4.2.
3. Open an application to read its details, timeline and documents, or click **Download
   Form** for the PDF.
4. Click **Reports** to generate any of the six leave reports (Section 4.9).

**Notes:**

- The **Leave Approvals** page is not available to the Mayor.
- The Mayor's name and title are printed at the foot of CSC Form No. 6 as head of agency.
- The Mayor also files and tracks their own leave through Section 3.

### 5.10 Department Head: Recommendation (Box 7.B)

A Department Head is informed when someone in the office they head files leave. They may
record a **recommendation**. The recommendation does **not** approve or stop the
application; HR still decides it.

**Purpose:** Record your recommendation on an application from your office.

**Steps:**

1. Click the **bell icon** and open the notification *"Leave filed in your office: …"*,
   or open the application from the **Waiting on HR** list on **Dashboard → My office**.
2. Review the application.
3. In the **Recommendation · box 7.B** card, choose **For approval** or **For
   disapproval**.
4. If you choose **For disapproval**, type the reason (required).
5. Click **Record recommendation**.

**Figure 27. Department Head Recommendation Card**

[INSERT SCREENSHOT: Recommendation · box 7.B card]

**Expected Result:** The message *"Recommended for approval. HR still decides the
application."* (or *"Recorded as not recommended. …"*) appears. The employee is notified,
the timeline shows the recommendation, and it is printed in Box 7.B with your signature
image (if you uploaded one).

**Notes:**

- A recommendation can be recorded **only once**, and only before HR decides.
- You can only recommend on applications from the office you are named as head of (set
  by HR on the **Departments** page). You cannot recommend on your own leave.

**Other Department Head pages:**

- **Dashboard → My office:** **Awaiting HR**, **Away today**, **Filed this month**, **Open
  applications**, the **Waiting on HR** list, who is away, and **Most applied leave type
  in your office**.
- **Leave Rankings:** your office only.
- **Reports:** *Leave in my office*, *Waiting on HR*, *Leave balances in my office* (View,
  PDF, Excel).

---

## 6. SUPER ADMIN GUIDE (SYSTEM ADMINISTRATOR)

This section is for the **System Administrator** role, which is the highest
administrative role in the system (called "Super Admin" in project documents). The
System Administrator does not file or decide leave. Security features work at the
**application level**: they watch sign-ins and requests made to this system, not the
LGU's whole network.

### 6.1 Security Dashboard

**Purpose:** Monitor the system's security status.

**Steps:**

1. Sign in. The **Security Dashboard** opens automatically (it is also the second item in
   the sidebar).

**Figure 28. Security Dashboard**

[INSERT SCREENSHOT: Super Admin Security Dashboard]

**Expected Result:** The dashboard shows:

| Panel | What it shows |
|---|---|
| Summary cards | **Accounts**, **Failed sign-ins today**, **Intrusions this week**, **Blocked addresses** |
| **Attack severity** | Events graded Critical (source blocked), High (repeated attempts), Medium (single attempt) |
| **Intrusion attempts per day** | This week compared with last week |
| **Recent alerts** | Short messages graded Critical, Warning, Info or Healthy |
| **Attempts by type** | SQL injection, Input manipulation, Brute force (last 30 days) |
| **Failed sign-ins by reason** | Unknown username, Wrong password, Account blocked, Account deactivated, Wrong one-time code (last 7 days) |
| **Unreviewed events** | Intrusion events not yet reviewed |
| **Privilege changes** | Role, permission, account-status and settings changes (last 7 days) |

**Marking events as reviewed:**

1. In **Unreviewed events**, click the **✓** beside one event, or click **Mark all
   reviewed**.
2. The count goes down. Reviewing is recorded in the audit log.

### 6.2 Monitoring Login Attempts

**Purpose:** Check failed sign-ins and locked accounts.

**Steps:**

1. On the **Security Dashboard**, read **Failed sign-ins today** and **Failed sign-ins
   by reason**.
2. For one person: click **Users**, open the row menu of the account, then click
   **Account history**. The page lists **Failed login attempts** (reason, IP address,
   time), **Audit history** and **Activity**.
3. For a printable list: run the **Blocked Login Report** (Section 6.13).

**Expected Result:** You can see who failed to sign in, from which IP address, when, and
why.

**How the system reacts to failed sign-ins:**

- After **3** wrong passwords (setting `auth.lockout_attempts`), the account is blocked
  for **24 hours** (setting `auth.lockout_hours`). The block lifts by itself after that
  time.
- The lockout is recorded as a **high-severity** intrusion event and a **Security Alert**
  is sent to every System Administrator (Section 6.3).

### 6.3 Viewing Security Alerts

The system gives Security Alerts in three ways:

1. **Pop-up alert while you are signed in.** Every 15 seconds (setting
   `general.alerts_poll_seconds`), the system checks for new intrusion events. When one
   is found, an **"Intrusion alert"** box appears on your screen showing the type of
   attack and the IP address, with a link to the Intrusion Logs for that address. Close
   it with the **×**.
2. **Notification (bell icon) and email.** When an **IP address is blocked
   automatically** or an **account is locked out**, every System Administrator receives a
   notification in the bell and an email (email requires the mail server to be
   configured).
3. **Recent alerts** panel on the Security Dashboard.

**Figure 29. Real-Time Intrusion Alert Pop-up**

[INSERT SCREENSHOT: Super Admin Security Alert pop-up]

**What to do when you receive an alert:**

1. Click the link in the alert, or open **Intrusion Logs**.
2. Check the IP address, the type of event and the user (if any).
3. Decide whether to block the IP address (Section 6.6) or the account (Section 6.8).
4. Mark the event as reviewed on the Security Dashboard.

### 6.4 Viewing Suspicious Activity (Intrusion Logs)

**Purpose:** See every suspicious request the system detected.

**Steps:**

1. Click **Intrusion Logs**.
2. Search by **IP** address, or filter by **Category** and **Severity**.

**Figure 30. Intrusion Logs**

[INSERT SCREENSHOT: Intrusion Logs]

**Expected Result:** A list with **Time**, **Category**, **Severity**, **IP**, **Route**
(page targeted), **User** (if signed in) and **Rule** (what was matched).

**Categories:**

| Category | Meaning |
|---|---|
| SQLi | Text that looks like an SQL injection attempt |
| XSS | Text that looks like a script injection attempt |
| Traversal | An attempt to reach files outside the system (for example `../`) |
| CSRF | A form submitted without a valid security token |
| Rate | Too many requests from one address in a minute (default 120) |
| Auth failure | Account locked after repeated wrong passwords |
| Device | Access from a computer that is not registered (when device checking is on) |
| Privilege | A user tried to open a page their role does not allow |
| Other | Anything else recorded by the detector |

**Notes:** SQL injection, XSS and traversal attempts are **refused** by the system before
they reach any page.

### 6.5 Viewing IP/Device Information

| Information | Where to find it |
|---|---|
| IP address of each suspicious request | **Intrusion Logs** (IP column) |
| Addresses with repeated attacks in the last 7 days | **Blocked IPs** page, top panel (IP, Events, What, Last seen, Severity) |
| IP address of failed sign-ins for one account | **Users → row menu → Account history** |
| IP address and last sign-in of an account | Recorded on the account; also in **Audit Logs** |
| IP address of each page visit | **Activity Logs** |
| Registered office computers, online/offline | **Authorized Devices** (Section 6.10) |

**Notes:** The system also records the browser (user agent) of failed sign-ins and
intrusion events. The screens listed above display the IP address; browser details are
kept in the stored records.

### 6.6 Blocking/Restricting Suspicious Users or IP Addresses

#### Automatic IP blocking

If one IP address causes **5** intrusion events within **10 minutes** (settings
`security.auto_block_threshold` and `security.auto_block_window_minutes`), the system
blocks it for **24 hours** (setting `security.ip_block_hours`) and sends a Security Alert.
Anyone using that address sees the **"Access blocked"** page.

Addresses listed in `security.never_block_ips`, and the server itself, are never blocked.

#### Blocking an attacking address from the evidence

1. Click **Blocked IPs**.
2. The top panel lists addresses with attack events in the last **7** days.
3. Click **Block** on the address's row.

**Expected Result:** *"IP … blocked for 24 hours."*

#### Blocking an IP address manually

1. Click **Blocked IPs**, then **Block an IP**.
2. Enter the **IP address** (for example `192.168.1.24`).
3. Enter the **Reason** (required).
4. Enter the **Duration in hours**. Leave it empty to block until you lift it.
5. Click **Block IP**.

**Figure 31. Blocked IP Addresses**

[INSERT SCREENSHOT: Blocked IP Addresses page with Block an IP form]

#### Lifting or renewing a block

1. On **Blocked IPs**, use **Show** to choose currently blocked, **Lifted** or **All**,
   and **Source** to choose **Automatic** or **By an administrator**.
2. Click **Lift block** to unblock an address, or **Block again** to renew a lifted one.

#### Blocking a user account

1. Click **Users**.
2. Open the account's row menu and click **Block**.
3. Type the reason in the confirmation box and confirm.

**Expected Result:** *"User blocked."* The person cannot sign in until you click
**Unblock** (a manual block has no end date). **Unblock** also lifts an automatic 3-strike
lockout early.

### 6.7 Viewing Audit Logs and Activity Logs

**Audit Logs** record important actions and changes: sign-ins and sign-outs, leave
submissions and decisions, password changes, account and role changes, IP blocks,
settings changes, reports generated. Audit records cannot be edited or deleted from the
system.

**Steps (Audit Logs):**

1. Click **Audit Logs**.
2. Search by user, or filter by **Action**.

**Expected Result:** A list with **Time**, **User**, **Role**, **Action**, **Target**,
**IP** and **Changes** (old and new values).

**Activity Logs** record page visits.

**Steps (Activity Logs):**

1. Click **Activity Logs**.
2. Search by user, or filter by **Method**.

**Expected Result:** A list with **Time**, **User**, **Method**, **Path**, **Route** and
**IP**.

**Figure 32. Audit Logs**

[INSERT SCREENSHOT: Audit Logs]

### 6.8 Managing User Accounts

**Creating a user**

1. Click **Users**, then **New user**.
2. Fill in **Account** (Full name, Email, Username, Employee no.).
3. Fill in **Personal details** (First/Middle/Last name, Gender, Civil status, Birth
   date, Contact no., Address) and employment details (**Department**, **Position**,
   **Employment status**, **Monthly salary**, **Date hired**).
4. Tick one or more **Roles** (required).
5. Click **Create user**.

**Figure 33. Create User Form**

[INSERT SCREENSHOT: Create user form]

**Expected Result:** The message confirms the account was created and shows the
**first-time password**. Give it to the person privately. They must change it at first
sign-in (Section 2.4).

**Other actions (row menu on the Users page):**

| Action | What it does |
|---|---|
| **Edit** | Change details and roles, then click **Save changes** |
| **Access & permissions** | Give or deny individual permissions to one person, on top of their roles (click **Save access**) |
| **Account history** | Failed login attempts, audit history and activity |
| **Reset password** | Returns the account to the first-time password; the person must change it at next sign-in |
| **Block / Unblock** | Stop or allow sign-in (reason required to block) |
| **Deactivate / Activate** | Turn the account off or on (for example during long absence) |
| **Archive** | Removes the account from use (resigned, retired, etc.). Records are kept |
| **Restore** | Brings back an archived account (set **Show** to **Archived** to find it) |

**Notes:**

- Accounts are never permanently deleted, so leave records and logs stay complete.
- You cannot **Block**, **Deactivate** or **Archive** your own account; these items are
  greyed out on your own row. Ask another System Administrator.
- The list shows **Last name**, **First name**, **M.I.**, **Roles**, **Department**,
  **Status** and **Created**. Use the **Role**, **Status** and **Show** filters to find
  accounts.

### 6.9 Roles & Permissions

**Purpose:** Review or adjust what each role may do.

**Steps:**

1. Click **Roles & Permissions** to see the five roles, what each does, their number of
   permissions and users.
2. Click a role's edit icon.
3. Change the **Description**, **Inherit from (parent role)**, or tick/untick
   **Permissions**.
4. Click **Save role**.

**Notes:**

- The five roles are fixed; new roles cannot be created and system roles cannot be
  deleted.
- Permissions inherited from a parent role are shown and cannot be removed on the child.
- Change permissions carefully. For example, removing "Approve or disapprove leave
  applications (HR)" from HR would leave no one able to decide leave.

### 6.10 Authorized Devices

**Purpose:** Keep a list of office computers allowed to use the system.

**Steps:**

1. Click **Authorized Devices**.
2. Click **Register device** and enter the **IP address**, **Hostname** and **MAC
   address** (all required), and optionally a **Description** (for example "HR front
   desk").
3. Click **Register**.
4. Use the row actions to deactivate/activate or archive a device.

**Expected Result:** The list shows each device's **Time** (when it was last switched on
and off), **IP**, **Hostname**, **Status**, **Online** (seen in the last few minutes) and
**Last active**.

**Notes:**

- Device checking is **off** by default. It is turned on in **System Settings** with
  `security.device_enforcement`. When on, any unregistered computer sees **"Device not
  authorized"**.
- Register all office computers **before** turning device checking on. The server itself
  is always allowed.

### 6.11 Backups

**Purpose:** Keep a copy of the database and uploaded documents.

**Steps:**

1. Click **Backups**.
2. Click **Create backup now**.
3. To save a copy, click **Download** beside the backup in the list.

**Expected Result:** A `.zip` file appears in the list with the date it was **Taken** and
its **Size**.

**Notes:**

- A backup is also made automatically every night at **1:00 AM**, as long as the server's
  scheduled task is running.
- A backup marked **Incomplete** means one or more database tables could not be read.
  Follow the recovery steps in `docs/AdminGuide.md` §8.
- Backups contain all leave records and documents. Store downloaded copies securely.

### 6.12 System Settings

**Purpose:** Change security and leave settings without changing the program.

**Steps:**

1. Click **System Settings**.
2. Change the value you need in its group (**Auth**, **Security**, **Leave**,
   **General**).
3. Click **Save settings**.

**Main settings:**

| Setting | Default | Meaning |
|---|---|---|
| `auth.otp_enabled` | On | Require the emailed one-time code at sign-in |
| `auth.otp_ttl_minutes` | 5 | Minutes a code stays valid |
| `auth.lockout_attempts` | 3 | Wrong passwords before the account is blocked |
| `auth.lockout_hours` | 24 | Hours an automatically blocked account stays blocked |
| `auth.session_idle_minutes` | 30 | Idle minutes before automatic sign-out |
| `security.device_enforcement` | Off | Allow only registered devices |
| `security.ids_enabled` | On | Intrusion detection on/off |
| `security.auto_block_threshold` | 5 | Events from one IP before automatic blocking |
| `security.auto_block_window_minutes` | 10 | Time window for the threshold |
| `security.ip_block_hours` | 24 | Hours an automatically blocked IP stays blocked |
| `security.never_block_ips` | (empty) | Addresses that are never blocked |
| `security.rate_limit_per_minute` | 120 | Requests per minute before a "Rate" event is logged |
| `leave.monthly_vl_accrual` | 1.25 | Vacation Leave credits earned per month |
| `leave.monthly_sl_accrual` | 1.25 | Sick Leave credits earned per month |
| `leave.vl_hard_deadline_days` | 3 | Days ahead Vacation Leave should be filed (warning) |
| `leave.monetization_min_days` | 10 | Fewest credits that may be monetized at once |
| `leave.monetization_retain_days` | 15 | Vacation Leave days that must remain after monetizing |
| `leave.forced_leave_min_vl` | 10 | VL credits from which the 5-day mandatory leave applies |
| `general.alerts_poll_seconds` | 15 | How often the screen checks for new intrusion alerts |

**Notes:** Every change is recorded in the audit log with the old and new value and
appears under **Privilege changes** on the Security Dashboard.

### 6.13 Security Reports

1. Click **Reports**.
2. Choose a security report, set the period, and click **View**, **PDF** or **Excel**:

| Report | Covers |
|---|---|
| Intrusion Report | Intrusion events in the period |
| Audit Report | Audit records in the period |
| Blocked Login Report | Failed and blocked sign-ins in the period |
| User Activity Report | Page activity in the period |

---

## 7. LEAVE MANAGEMENT

### 7.1 Leave Types

The system comes with the 15 leave types of CSC Form No. 6. The values below are the
**installed defaults**; HR can change them on the **Leave Types** page.

| # | Leave type | Deducted from | Maximum | Counted in | Main requirements (per Instructions page) |
|---|---|---|---|---|---|
| 1 | Vacation Leave | Vacation credits | Up to available credits | Working days | File at least 5 days before (warning only) |
| 2 | Mandatory / Forced Leave | Vacation credits | 5 days/year | Working days | Applies to employees with at least 10 VL credits |
| 3 | Sick Leave | Sick credits | Up to available credits | Working days | Medical certificate (see Validation item C-5); late-filing reason if filed after the leave |
| 4 | Maternity Leave | Not deducted | 105 days (live birth); 120 for a solo parent; 60 (miscarriage/emergency termination) | Calendar days | Proof of pregnancy, medical certificate |
| 5 | Paternity Leave | Not deducted | 7 days | Working days | Child's birth certificate, marriage certificate |
| 6 | Special Privilege Leave | Not deducted | 3 days/year | Working days | File at least 7 days before (warning only) |
| 7 | Solo Parent Leave | Not deducted | 7 days/year | Working days | Solo Parent ID |
| 8 | Study Leave | Not deducted | 180 days | Calendar days | Study leave contract, enrolment documents |
| 9 | 10-Day VAWC Leave | Not deducted | 10 days (+ extra days stated in a protection order) | Working days | Protection order / police report / medical certificate |
| 10 | Rehabilitation Privilege Leave | Not deducted | 180 days | Calendar days | Accident report, medical certificate, physician's recommendation |
| 11 | Special Leave Benefits for Women | Not deducted | 60 days | Calendar days | Medical certificate, clinical summary, surgery record |
| 12 | Special Emergency (Calamity) Leave | Not deducted | 5 days/year | Working days | Must be within 30 days of the calamity declaration; government proof |
| 13 | Monetization of Leave Credits | Vacation credits | At least 10 days at a time; 15 VL days must remain | Days entered on the form | Letter request |
| 14 | Terminal Leave | Vacation credits | Up to available credits | Working days | Clearance, resignation/retirement documents |
| 15 | Adoption Leave | Not deducted | 60 days | Calendar days | PAPA, DSWD documents |

The complete requirement list is on the **Instructions and Requirements** page (link at
the top of **Apply for Leave**).

### 7.2 Deductible Leave

Deductible leave uses the employee's earned credits:

- **Vacation credits:** Vacation Leave, Mandatory/Forced Leave, Monetization, Terminal
  Leave.
- **Sick credits:** Sick Leave.

For these types the system:

1. refuses an application for more days than the available credits;
2. deducts the working days from the credits **only when HR approves**;
3. records the deduction in the employee's **Credit history**.

### 7.3 Non-Deductible Leave

All other types (Maternity, Paternity, Special Privilege, Solo Parent, Study, VAWC,
Rehabilitation, Special Leave Benefits for Women, Calamity, Adoption) do **not** use
credits. The system still checks each type's maximum days and required details.

### 7.4 Leave Credit Computation

- **Monthly earning:** on the 1st day of every month (00:05), every active employee with
  an employee record earns **1.25 days Vacation Leave** and **1.25 days Sick Leave**
  (default). Each month is credited only once. This requires the server's scheduled task
  to be running.
- **Working days:** from the first to the last day of leave, excluding Saturdays,
  Sundays and dates in the **Holiday Calendar**.
- **Calendar days:** for Maternity, Study, Rehabilitation, Special Leave Benefits for
  Women and Adoption Leave, every day in the range is counted.
- **Monetization:** the days are the number entered in **Number of days to monetize**.
- **Deduction:** on approval, `new balance = balance − working days`.
- **Adjustment:** HR may add or deduct days with a reason (Section 4.6).
- **Never negative:** no filing, approval or adjustment can bring a balance below zero.

**Example:** An employee has 10.000 VL credits and files Vacation Leave from Monday to
Friday of a week with one holiday on Wednesday. Working days = 4. After HR approves, the
VL balance is 6.000.

### 7.5 Leave Balance

Each employee has a balance per leave type made up of:

| Term | Meaning |
|---|---|
| **Earned** | Total credits received (monthly earning and additions by HR) |
| **Used** | Days deducted for approved leave |
| **Balance / Remaining** | Credits still available |

Employees see these on the Dashboard. HR sees them on **Leave Balances**, the
**Employees** page and the **Leave Balance Report**.

### 7.6 Leave Request Statuses

| Status (as shown) | Meaning | Can the employee cancel? | Can HR act? |
|---|---|:-:|:-:|
| **Pending** | Submitted, waiting for HR | Yes | Yes |
| **Returned** | Sent back by HR for revision | Yes | Yes |
| **Approved** | Approved by HR; credits deducted if deductible | No | No |
| **Disapproved** (timeline: "Rejected") | Disapproved by HR, with reason | No | No |
| **Cancelled** | Withdrawn by the employee | No | No |

*HR review*, *Final review* and *Department review (archived flow)* appear only on
applications filed under an older version of the workflow.

### 7.7 Approval Workflow

```
 Employee submits application  ──►  Status: PENDING
          │
          ├──►  Department Head of the office is NOTIFIED
          │     (may record a recommendation for Box 7.B — does not decide)
          │
          ▼
 HR opens Leave Approvals and clicks Act
          │
          ├── Approve ─────────────►  APPROVED   (credits deducted if deductible; employee notified)
          ├── Disapprove ──────────►  DISAPPROVED (reason saved; employee notified)
          └── Return for revision ─►  RETURNED   (stays in HR's list; employee notified)

 Employee may CANCEL while Pending or Returned  ──►  CANCELLED
```

---

## 8. SECURITY AND ACCOUNT GUIDELINES

### 8.1 Password Guidelines

- Use at least **12 characters** with uppercase and lowercase letters, a number and a
  symbol. The system will not accept a weaker password.
- Change the first-time password immediately (the system requires it).
- Do not use your name, birthday, employee number or "Alicia" in your password.
- Never write your password where others can see it, and never share it, not even with
  HR or the System Administrator. They do not need it; they can reset it.
- Passwords are stored by the system using password hashing, so no one, including the
  System Administrator, can read your password.

### 8.2 OTP Guidelines

- The 6-digit code is for **one sign-in only** and expires after 5 minutes.
- Never give your code to anyone. LGU staff will never ask for it.
- If you receive a code you did not request, someone may know your password. Change your
  password immediately and inform the System Administrator.
- Protect the email account that receives your codes.

### 8.3 Account Security

- Your role decides what pages you see. If you believe you are missing a page you need,
  ask the System Administrator; do not use another person's account.
- Trying to open a page your role does not allow is recorded as a **Privilege** event.
- Everything important you do is recorded in the audit log. You can review your own
  records in **My Audit Log**.

### 8.4 Suspicious Login Attempts

- If the sign-in page says your account is blocked and you did not enter wrong passwords,
  report it to the System Administrator: someone may have tried to guess your password.
- If a login fails repeatedly, stop after the second failure and use **Forgot password?**
  instead of risking a 24-hour block.
- Do not try to "test" the system by typing unusual characters, code or scripts into
  fields. The system records these as intrusion attempts and may block your computer.

### 8.5 Proper Logout

- Always click **Sign out** when you leave your computer, especially on shared computers.
- Do not only close the browser tab.
- Lock your computer (Windows key + L) when stepping away.

### 8.6 Protecting User Information

- Leave applications, medical certificates and other documents contain personal
  information. Download them only when needed and do not leave printed copies unattended.
- Upload only the documents required for the application.
- Do not share screenshots of other employees' records.
- Report lost printed forms or suspected data exposure to HR and the System
  Administrator.

---

## 9. TROUBLESHOOTING

### 9.1 Cannot Log In

| Message or problem | What to do |
|---|---|
| *"These credentials do not match our records."* | Check the spelling of your email/username. |
| *"Invalid password. N attempt(s) remaining …"* | Check Caps Lock and retype. Use **Forgot password?** if unsure. |
| *"This account is deactivated."* | Ask the System Administrator to activate your account. |
| *"Too many attempts. Try again in N seconds."* | Wait for the time shown. |
| The page does not open | Check your network connection and the address. Ask the System Administrator whether the server is running. |
| *"Access blocked"* page | See 9.3. |
| *"Device not authorized"* page | Ask the System Administrator to register your computer. |
| Browser shows a certificate/security warning | The office computer may not yet trust the system's certificate. Ask the System Administrator to set it up (`deploy/connect-client.bat`). |

### 9.2 Invalid OTP

| Problem | What to do |
|---|---|
| *"Invalid or expired code."* | Check that you typed the latest code. Codes expire after 5 minutes. Click **Resend code**. |
| No email arrived | Check the spam/junk folder. Wait a minute, then click **Resend code**. If it never arrives, contact the System Administrator (the mail server may not be configured). |
| **Resend** button shows a countdown | Wait until it reads **Resend code**. |
| *"Too many attempts. Wait a minute and try again."* | Wait one minute. |

### 9.3 Account Temporarily Restricted

| Situation | What to do |
|---|---|
| *"This account is blocked until [date and time]. Contact the System Administrator."* | Your account was locked after 3 wrong passwords. Wait until the time shown, or ask the System Administrator to click **Unblock**. |
| *"This account is blocked…"* with no end date | A System Administrator blocked it. Contact them. |
| *"Access blocked — Your IP address (…) has been temporarily blocked due to suspicious activity."* | The computer's address was blocked. Contact the System Administrator, who can check the Intrusion Logs and click **Lift block**. |
| *"Slow down — Too many requests …"* | Wait a moment and try again. |

### 9.4 Leave Request Cannot Be Submitted

| Message or problem | What to do |
|---|---|
| Red summary at the top of the form | Scroll through the four steps and fix every highlighted field. |
| *"Choose the type of leave you are applying for in section 6.A."* | Select a **Leave type** in Step 2. |
| *"Insufficient … credits"* | Reduce the number of days or choose another leave type. Ask HR if you believe your balance is wrong. |
| *"… cannot exceed … day(s)"* | Reduce the number of days. |
| *"The field '…' is required for …"* | Answer the missing detail in Step 2. |
| *"Special Emergency Leave must be availed within 30 days …"* | Your dates are outside the 30-day window. |
| *"Mandatory leave applies to employees with at least 10 vacation leave credits …"* | You are exempt from Forced Leave this year; use Vacation Leave instead. |
| File upload refused | Use PDF, JPG or PNG, 5 MB or smaller. |
| Your office/position/salary is blank or wrong | Ask HR/the System Administrator to update your employee record. |

### 9.5 Incorrect Leave Information

| Problem | What to do |
|---|---|
| Wrong dates or type on a **Pending** application | There is no edit function. **Cancel** it (Section 3.11) and file a new one. |
| Wrong working day count | A holiday may be missing from the Holiday Calendar. Inform HR. |
| Wrong leave credit balance | Inform HR. HR corrects it on **Leave Balances** with a reason. |
| Application was **Returned** | Read HR's comments; upload the missing document or cancel and refile (Section 3.9). |

### 9.6 Missing Notifications/Status Updates

| Problem | What to do |
|---|---|
| No new notifications in the bell | Refresh the page. Check **My Leave Requests** for the current status. |
| No email notifications | Email depends on the server's mail setup. The in-system notification and status are always up to date. |
| Department Head was not notified | The office has no head assigned on the **Departments** page, or the applicant is the head. Ask HR. |
| Credits did not increase this month | The monthly credit runs on the 1st at 00:05 only while the server's scheduler is running. Inform the System Administrator. |

### 9.7 Other System Errors

| Message | Meaning / what to do |
|---|---|
| **403** page (not allowed) | Your role cannot open that page. Use the sidebar. |
| **404** page (not found) | The page or record does not exist. Use the sidebar. |
| *"This application has already been decided and can no longer be changed."* | The application is final. |
| *"Another authorized officer has just decided this application."* | Another HR officer acted first. Refresh the list. |
| *"You cannot decide your own leave application."* | Another HR officer must decide it. |
| Page looks unstyled or broken | Refresh (Ctrl + F5). If it continues, report to the System Administrator. |

---

## 10. FREQUENTLY ASKED QUESTIONS

**1. Who approves my leave?**
HR. The approval is a single step in the system. Your Department Head is notified and may
add a recommendation, but HR decides.

**2. Does the Mayor approve leave in the system?**
No. The Mayor can view all applications and reports, and the Mayor's name is printed at
the foot of CSC Form No. 6 as head of agency.

**3. Can I edit an application after submitting it?**
No. You can upload additional documents while it is undecided, or cancel it and file a
new one.

**4. Why was I allowed to submit even though a yellow warning appeared?**
Warnings (for example, filing Vacation Leave less than 5 days ahead) do not block
submission. HR sees the warning and decides.

**5. When are my leave credits deducted?**
Only when HR approves the application.

**6. When do I earn leave credits?**
On the 1st of each month: 1.25 days Vacation Leave and 1.25 days Sick Leave (default).

**7. Do I need to upload documents?**
Upload the documents listed for your leave type on the **Instructions and Requirements**
page. The system accepts the application without them, but HR may return or disapprove it.

**8. Can I print the form?**
Yes. Open the application and click **Download Form**, then print the PDF. Choose the
paper size with the arrow beside the button.

**9. Is there a blank form for employees who cannot use the system?**
Yes. HR can print a blank CSC Form No. 6 from the **Leave Approvals** page (**Blank
form** button).

**10. Can I use the system from home?**
No. The system is intended for use within the LGU's local network.

**11. I forgot my password and did not receive the reset email. What now?**
Ask the System Administrator to reset your password. You will sign in with the
first-time password and set a new one.

**12. Why was I signed out automatically?**
You were idle for 30 minutes. Sign in again.

---

## 11. SYSTEM SUPPORT / CONTACT INFORMATION

| Concern | Contact | Location / local no. |
|---|---|---|
| Leave credits, leave types, application decisions, employee record errors | HR Office | *[to be filled in by the LGU]* |
| Accounts, passwords, blocked accounts/computers, system errors, backups | System Administrator | *[to be filled in by the LGU]* |
| Department assignment / Department Head | HR Office | *[to be filled in by the LGU]* |

When reporting a problem, give:

1. your name and username;
2. the page you were on;
3. the exact message shown (a screenshot helps);
4. the date and time it happened;
5. the reference number of the application, if any.

Never include your password or OTP code in a report.

---

## 12. APPENDICES

### Appendix A. Leave Workflow

See Section 7.7 for the diagram. Summary:

| Step | Who | Action | Resulting status |
|---|---|---|---|
| 1 | Employee | Submits application | Pending |
| 2 | System | Notifies employee and Department Head | Pending |
| 3 (optional) | Department Head | Records recommendation (Box 7.B) | Pending (unchanged) |
| 4 | HR | Approve / Disapprove / Return for revision | Approved / Disapproved / Returned |
| 5 | System | Deducts credits (approved, deductible types); notifies employee | — |
| Any time before a final decision | Employee | Cancel | Cancelled |

### Appendix B. Role Permissions

| Function | Employee | Dept. Head | HR | Mayor | System Admin |
|---|:-:|:-:|:-:|:-:|:-:|
| File, view and cancel own leave | ✓ | ✓ | ✓ | ✓ | |
| Upload own signature | ✓ | ✓ | ✓ | ✓ | |
| View own audit log | ✓ | ✓ | ✓ | ✓ | |
| Notified of office's leave; record Box 7.B recommendation | | ✓ | | | |
| View own office's leave, rankings and reports | | ✓ | | | |
| View all leave applications | | | ✓ | ✓ | |
| **Approve / disapprove / return leave** | | | **✓** | | |
| Print blank CSC Form No. 6 | | | ✓ | | |
| View employees; manage departments, positions, holidays | | | ✓ | | |
| Adjust leave balances; configure leave types | | | ✓ | | |
| Generate leave reports | | | ✓ | ✓ | |
| Generate security reports | | | | | ✓ |
| Manage users, roles, devices | | | | | ✓ |
| Security Dashboard, Intrusion Logs, Blocked IPs | | | | | ✓ |
| Audit Logs and Activity Logs (all users) | | | | | ✓ |
| Backups and System Settings | | | | | ✓ |

*Defaults as installed. The System Administrator can change permissions on Roles &
Permissions and per user on Access & permissions.*

### Appendix C. Important Terminology

| Term | Meaning |
|---|---|
| **Approving Officer** | The user who decides leave applications. In this system: HR. |
| **Audit Log** | Record of important actions and changes (who, what, when, from which IP). |
| **Activity Log** | Record of page visits. |
| **Blocked IP** | A computer address not allowed to use the system, automatically or by the System Administrator. |
| **CSC Form No. 6** | The Civil Service Commission Application for Leave form (Revised 2020). |
| **Credit history** | The ledger of credits earned, used and adjusted. |
| **Deductible leave** | Leave that uses Vacation or Sick credits. |
| **Employee** | Any user who files leave. |
| **HR** | The Human Resources role; the Approving Officer. |
| **Intrusion event** | A suspicious request recorded by the system (for example SQL injection). |
| **LAN** | Local area network: the LGU's internal computer network. |
| **Leave Credits** | Days of leave available to an employee. |
| **Leave History** | An employee's past leave applications and credit movements. |
| **Leave Request** | A leave application submitted by an employee. |
| **Login Attempt** | Any try to sign in, successful or failed. |
| **OTP** | One-time password: the 6-digit code emailed at sign-in. |
| **Reference number** | The application's unique number, for example LV-2026-00012. |
| **Role** | A set of permissions given to a user (Employee, Department Head, HR, Municipal Mayor, System Administrator). |
| **Security Alert** | A notice to the System Administrator about suspicious activity. |
| **Super Admin / System Administrator** | The highest administrative role; manages accounts and security. |
| **Working days** | Days in the leave range excluding weekends and holidays. |

### Appendix D. Quick Reference

| I want to… | Go to |
|---|---|
| File leave | Apply for Leave |
| See my status | My Leave Requests → View Form |
| See my credits | Dashboard |
| Print my form | My Leave Requests → View Form → Download Form |
| Change my password | Your name (top right) → Change password |
| Decide leave (HR) | Leave Approvals → Act |
| Print a blank form (HR) | Leave Approvals → Blank form |
| Adjust credits (HR) | Leave Balances → Adjust |
| Add a holiday (HR) | Holidays → Add holiday |
| Check attacks (System Admin) | Security Dashboard / Intrusion Logs |
| Unblock a person (System Admin) | Users → row menu → Unblock |
| Unblock a computer (System Admin) | Blocked IPs → Lift block |

---

## USER MANUAL VALIDATION CHECK

This check compares the manual against the source code in this repository (Laravel
application: routes, controllers, services, views, migrations and seeders). No
screenshots and no manuscript were provided, so the manuscript comparison below uses the
requirements stated in the request for this manual and the older documents in `/docs`.

### 1. Features verified from the actual system (source code)

- Sign-in with email **or** username and password; password show/hide; login throttle
  (5 tries/minute).
- Email OTP (6 digits, 5-minute validity, Resend with cooldown, Cancel); can be turned off
  in settings.
- Forced password change on first sign-in and after an admin reset; password rule 12+
  characters, upper/lowercase, number, symbol.
- Forgot/reset password by email link.
- Account lockout after 3 failed passwords for 24 hours, auto-lifted; manual block/unblock.
- 30-minute idle session timeout.
- Five fixed roles: Employee, Department Head, HR, Municipal Mayor, System Administrator;
  permission-driven sidebar; per-user permission overrides.
- Four-step Application for Leave (CSC Form No. 6 layout), 15 CSC leave types, detail
  fields per type, working/calendar day counting with holiday calendar, credit check,
  maximum-day checks, statutory rules (maternity ceilings, VAWC extension, calamity
  30-day window, monetization minimum/retention, forced-leave exemption), late sick
  leave reason, warnings for late filing.
- Supporting document upload (PDF/JPG/PNG, 5 MB) at filing and afterwards.
- Signature image upload (PNG/JPG, 8 MB) and its use on the printed form.
- Single-step decision by HR only (Approve / Return for revision / Disapprove) with days
  with/without pay, remarks and typed signature; Department Head notification and optional
  Box 7.B recommendation; Mayor view-only oversight.
- Approval timeline; statuses Pending, Returned, Approved, Disapproved, Cancelled;
  cancellation while not final.
- Credit deduction on approval, monthly accrual (1.25/1.25), HR adjustments with reason,
  never-negative balances, credit history.
- CSC Form No. 6 PDF (filled and blank) on Legal, Folio, A4, Letter.
- Notifications (bell + page); email copies for leave status and security alerts.
- Reports: 6 leave, 3 department, 4 security; outputs View, PDF, Excel.
- Security Dashboard, Intrusion Logs, Blocked IPs (auto and manual, lift, block again,
  block from evidence), real-time pop-up intrusion alerts (15-second polling), audit logs,
  activity logs, My Audit Log, authorized devices, backups (manual and nightly), system
  settings.
- Accounts are archived, never permanently deleted.

### 2. Features that require screenshot verification

All figures in this manual are placeholders. Capture each from the running system and
check that the labels in the screenshot match the labels in the text:

Login Page (Fig. 1–2), OTP page (3), Change password (4), Dashboards (5, 7, 18),
main layout (6), My Signature (8), Apply for Leave steps 1–4 (9–13), My Leave Requests (14),
Form Preview (15), Notifications (16), Download Form menu (17), All Leave Requests (19),
Leave request details (20), Holiday Calendar (21), Leave Balances adjust (22), Leave Type
form (23), Reports (24), Leave Approvals (25), Decision window (26), Recommendation card
(27), Security Dashboard (28), Intrusion alert pop-up (29), Intrusion Logs (30), Blocked
IPs (31), Audit Logs (32), Create user (33).

Particular attention: icon-only buttons (edit/remove icons on Departments, Positions,
Holidays, Leave Types, Authorized Devices) are described as "edit icon"/"remove icon";
confirm their tooltips in the screenshots.

### 3. Features that require clarification

| # | Item | Why |
|---|---|---|
| C-1 | **System address** (`https://onealicialms.lan`) | Taken from the deployment scripts. Confirm the address used in the actual LGU installation. |
| C-2 | **OTP and password-reset email delivery** | The default configuration writes emails to a log file instead of sending them. Confirm which mail server the LGU installation uses, because OTP sign-in depends on it. If email is not available, OTP may have been turned off in System Settings; the manual must then say so. |
| C-3 | **Returned applications** | HR can "Return for revision" and the employee is told to "resubmit", but there is **no resubmit or edit function** for the employee. The application stays in HR's list. Confirm the intended office procedure (upload missing document and inform HR, or cancel and refile). |
| C-4 | **Days without pay** | HR can enter days with pay and days without pay, and these print in Box 7.C, but on approval the system deducts the **full working days** from credits regardless of the split. Confirm whether this is the intended behaviour. |
| C-5 | **Sick Leave medical certificate threshold** | The Sick Leave type is set to require a medical certificate after **2** days, while the form hint says "more than five days". Neither is enforced at submission. Confirm which rule the LGU follows. |
| C-6 | **Insufficient credits at approval time** | If an employee's credits fall below the requested days between filing and approval, approval fails with a system error instead of a friendly message. Confirm what HR sees and the procedure to follow. |
| C-7 | **HR "manage employees" permission** | HR holds a "Create/update/archive employees" permission, but the Employees page is view-only; employee details are edited only by the System Administrator on Users. Confirm who maintains employee records in practice. |
| C-8 | **Support contacts** | Section 11 needs names, offices and local numbers from the LGU. |
| C-10 | **Vacation Leave location** | The Vacation Leave type requires "Specify location" even when **Within the Philippines** is chosen, although the form labels the box **If abroad, specify**. Leaving it blank refuses the application (*The field 'Specify location' is required for Vacation Leave.*). The place then prints beside **Abroad (Specify)** on CSC Form No. 6 even when **Within the Philippines** is ticked. Found while capturing the manual's screenshots; confirm whether this is intended. |
| C-9 | **Mayor's name and HR officer's name on the form** | The printed form uses names stored as settings (with built-in fallback names). Confirm they are current. |

### 4. Discrepancies between earlier project documents and the implemented system

| Earlier document / request says | Implemented system does | Manual follows |
|---|---|---|
| Approving roles are **Mayor, Vice Mayor and HR** (any one decides) — also stated in `README.md` | **Only HR** decides. The Vice Mayor role was **removed** (holders moved to Mayor), and the Mayor's approval permission was **withdrawn**; the Mayor now has view-all and reports only. | Implemented system |
| **Super Admin** role with full access | The Super Admin role was **removed**; its holders became **System Administrator**, which has no leave permissions. The bootstrap account keeps the username `superadmin`. | Implemented system ("Super Admin" used only as the manual's name for the System Administrator) |
| `docs/UserGuide.md`: workflow Submitted → **Department Head** → HR → **Mayor**; Department Head can "Recommend Approval / Disapproval / Return for Revision"; Mayor has a "Final Review" queue | Single step: HR decides. Department Head is notified and may only record a For approval / For disapproval recommendation (once). No Final Review queue. | Implemented system. `docs/UserGuide.md` is outdated. |
| `docs/UserGuide.md`: employee ticks **commutation** on the form | The commutation question was removed from the entry form (always "Not requested"). | Implemented system |
| `docs/UserGuide.md`: "My Balances" page | Removed; balances and credit history are on the Dashboard. | Implemented system |
| `README.md` / `docs/UserGuide.md`: **global search** in the top bar | No global search exists in the top bar. Search boxes exist on individual list pages. | Implemented system |
| `README.md`: reports exportable to **PDF / Excel / CSV** | Reports offer **View, PDF and Excel** only. | Implemented system |
| `README.md`: "9 reports" | 13 report definitions (6 leave, 3 department, 4 security); each role sees only its own group. | Implemented system |
| `docs/AdminGuide.md`: new users receive a **welcome email** with a temporary password; reset password **emails a link** | New users and admin resets use a **first-time password shown on screen** to the System Administrator; no welcome email is sent. | Implemented system |
| `docs/AdminGuide.md`: permanent **delete** from the archive view; **create roles** | No permanent delete of accounts; roles are fixed (edit only). | Implemented system |
| `docs/UserGuide.md`: supporting documents "required" | Uploads are optional at filing; requirements are listed on the Instructions page and checked by HR. | Implemented system |
| `docs/AdminGuide.md`: "navbar bell polls every 15 s" for security | The visible security bell was removed; polling still runs in the background and shows a pop-up "Intrusion alert". | Implemented system |

### 5. Information NOT included because it is not implemented (or must not be published)

**Not implemented — not described as features:**

- Vice Mayor approval; Mayor approval inside the system; Department Head approval.
- A separate Super Admin role with unlimited ("*") access.
- Editing or resubmitting a filed application.
- Global search; CSV report export.
- Biometric authentication, cloud or internet access, IoT, Zero Trust, enterprise
  IDS/IPS or network-wide security monitoring, payroll, recruitment, performance
  management, integration with external government databases, kiosk mode.
- Field-level encryption of stored personal data (no encrypted database fields were found;
  protection in transit is HTTPS/TLS on the LAN when set up with the deployment scripts,
  and passwords/OTP codes are stored as hashes).

**Deliberately left out for security:**

- The default first-time password, the seeded administrator password, demo account
  credentials, database and mail credentials, encryption keys and certificate private
  keys. (These exist in configuration files and must be changed and kept private.)
