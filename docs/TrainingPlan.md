# End-User Training Plan

For the go-live at the Municipality of Alicia. The stakeholders who evaluated the prototype asked
for hands-on orientation before anyone files real leave through the system; this is what that
session is, who sits in it, and how we know it worked.

Run every session against a training database seeded with `php artisan db:seed --class=DemoDataSeeder`.
Nobody practises on live records — the first thing a nervous user does is cancel something.

## 1. Who needs what

| Audience | Leaves able to | Time |
|---|---|---|
| Employees | Sign in with the e-mailed code, file a CSC Form 6 through the four steps, attach a required document, follow the application, cancel before it is approved, read their own credits | 45 min |
| Department Heads | The above, plus recommending on their department's applications and signing | 60 min |
| HR | Act on the approvals queue, maintain employees, departments, positions, holidays and leave types, adjust balances, generate and export the reports, print a blank form for a walk-in | 2 hours |
| Mayor's office | Final approval: with pay / without pay, remarks, signature | 30 min |
| System Administrator | Accounts and access, authorized devices, the security dashboard and intrusion logs, blocked IPs, audit logs, settings, backups **and restore**, the one-time sign-in code | 3 hours |

## 2. The session

1. **What changes** (5 min) — the paper CSC Form 6 stays the legal form; what changes is where it
   is filled in, how it is routed, and that credits are computed rather than counted by hand.
2. **Signing in** (10 min) — the password rules, the e-mailed code, what the three-strike lockout
   means, and what to do when the code does not arrive (ask the administrator; do not wait).
3. **Filing** (15 min) — each participant files one application end to end, using
   *Instructions and Requirements* on the form itself rather than a handout. Cover the automatic
   working-day count, the documents each leave type requires, and the signature.
4. **Following it** (10 min) — My Leave Requests, the statuses, cancelling, printing the form.
5. **Role practice** (15–60 min) — the approvals queue, HR maintenance, or the administration
   screens, depending on who is in the room.
6. **Questions, then sign-off** (10 min) — attendance sheet; each participant confirms they
   completed one transaction unaided.

## 3. Materials
- `docs/UserGuide.md` — employees, Department Heads, HR. Printed, one per participant.
- `docs/AdminGuide.md` — the System Administrator's copy, including §8 backups and restore.
- The system itself: the four-step form, *Instructions and Requirements*, and My Signature.
- One workstation per two participants, on the LAN, with the certificate already installed.

## 4. Knowing it worked
- Everyone completes one transaction unaided before they leave the room.
- HR keeps the attendance sheet; it is the evidence this was done.
- For the first two weeks the project team is reachable, and HR writes down every question asked
  more than once — those are the next round of usability fixes, not user error.
- New staff are trained at onboarding by HR from this same plan.
