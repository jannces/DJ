# Input Validation — Live System Evidence

Screenshots captured from the **running application** (driven by a real browser),
showing the system actually performing input validation and recording the
events — the "as it happens in the system" counterpart to the automated report.

| File | What it shows |
|------|---------------|
| `01-login-empty-rejected.png` | Login with blank fields → "The identifier field is required / The password field is required" (input rejected, clean message, no error page). |
| `02-login-xss-blocked.png` | `<script>alert(1)</script>` submitted at login → the IDS **"Access blocked"** page (IP temporarily blocked); no script executes. |
| `03-login-sqli-blocked.png` | `admin' OR 1=1 --` submitted at login → IDS block page. |
| `04-employee-dashboard.png` | A real employee signed in — the live system in normal use. |
| `05-leave-form.png` | The digital CSC Form 6 leave-application form. |
| `07-admin-dashboard.png` | System Administrator signed in. |
| `08-security-dashboard.png` | The Security Dashboard. |
| `09-intrusion-log.png` | **Intrusion Logs** listing the xss and sqli events the attempts above just created (high severity, POST /login, matched_rule xss_signature / sqli_signature). |
| `10-audit-log.png` | The append-only Audit Log. |

These were produced against a local instance (SQLite, OTP disabled for the
walkthrough) using `scripts/capture-system-evidence.cjs`. The events in
`09-intrusion-log.png` are the live records written by the payloads in
screenshots 02–03 — the same rows the automated `InputValidationTest` captures
into `intrusion_logs`.

> Note: the leave-form field-level validation errors are proven by the automated
> `InputValidationTest` (the form is a multi-step UI whose submit button is on
> the final step, so it is not captured here); screenshot `05` shows the form
> itself.
