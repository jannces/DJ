# Adviser & Panel Critique — Defense Readiness Assessment

**Manuscript:** *A Cybersecurity Integrated Digital Leave Management System With Real-Time Intrusion Alerts For Local Government Unit Of Alicia* — Chapters I–IV
**Assessed as:** capstone adviser and panel member, for defensibility rather than copy-editing
**Companion document:** `ThesisReview-ExaminerReport.md` (passage-level review)

> **Scope of this critique.** At the author's direction this covers **Chapters I, III and IV only**. Results and Discussion (Chapter V) and Conclusions (Chapter VI) are **deliberately deferred** until evaluation data exists — that is the correct call, and §4 Move 2 explains why. Chapter II findings are recorded where they bear on Chapters I, III and IV, but are not sequenced into the revision plan.

---

## Verdict

**Disposition: proceed to defense with major revisions.** The project is sound and the build is well ahead of most capstones at this stage. The paper is the weaker half, and the defense is decided by the paper.

Three things are true at once, and they explain almost everything else:

1. **The manuscript is a proposal.** 270 instances of "will," zero past-tense research actions, Chapters I–IV only. It describes work not yet done — which is appropriate at this stage, but the paper never says so.
2. **The system is finished.** 13 test files with 49 tests, 26 controllers, 12 service classes, a complete 15-type CSC leave engine, an application-layer IDS, and a self-conducted penetration test report. This is a completed artifact, not a plan.
3. **The paper claims the weak contribution and hides the strong one.**

You are defending a plan for work you have already done, using a novelty claim that undersells you. Close that gap and you move from an ordinary defense to an unusually strong one.

---

## 1. What a capstone must demonstrate, and where you stand

Panels assess six things. Your standing on each:

| # | Criterion | Standing | Note |
|---|---|---|---|
| 1 | The problem is real, bounded, and matters to an identified client | **Strong** | A named LGU, 135 plantilla employees, a manual process since the 1990s, 5–25 applications monthly. This is a genuine client problem, not a hypothetical. |
| 2 | The solution is technically non-trivial and is your own work | **Strong, but unclaimed** | Concurrency-safe credit deduction, JSON-configurable leave policy, database-driven RBAC with inheritance and overrides, append-only audit. None of it appears in the paper. |
| 3 | The method is appropriate and executable | **Weak** | Three methodologies named, one elaborated, and the analysis plan does not match the label. See §3. |
| 4 | The evaluation is objective enough to support the claims | **Weak** | Almost entirely perception-based. The one objectively verifiable claim you could make — computational correctness against CSC rules — is never proposed as a test. See §5, Move 4. |
| 5 | Ethical and legal compliance | **At risk** | No written penetration-testing authorization, no ethics review, no data protection officer coordination. See §6. |
| 6 | The contribution is stated and defensible | **Weak** | Claimed as "no LAN deployment, no structured security" — a gap by absence, which panels distrust. See Move 3. |

Two strengths, one at-risk, three weak. All three weak items are fixable in writing, without touching the code.

---

## 2. The central diagnosis: one root cause, many symptoms

My earlier review listed symptoms — an unmeasurable vulnerability baseline, an experimental design with no statistics, a circular research question. Those are not six separate problems. They are one problem showing up in six places:

> **You have not decided what kind of study this is, so Chapter III borrows the vocabulary of three incompatible traditions and inherits the obligations of all of them.**

§3.1 names Design and Development, Evaluation Research, and Experimental Research. Each carries different requirements. "Experimental" obliges you to hypotheses, controls, randomisation, and inferential statistics — none of which you have. "Evaluation Research" obliges you to defined criteria and a comparison standard. "Design and Development" obliges you to requirements traceability. You are currently accountable for all three and delivering one.

**The fix is not to add the missing pieces of all three. It is to choose one frame that legitimately covers what you actually did.** That frame exists, it is standard for build-type capstones, and it is citable: **Design Science Research**.

### Why Design Science Research resolves this at the root

Design Science Research (Hevner et al., 2004; Peffers et al., 2007, the DSRM process model) is the established methodology for studies whose central output is a constructed artifact evaluated against defined objectives. Its six activities map almost exactly onto what you have already done: identify the problem, define the objectives of a solution, design and develop, demonstrate, evaluate, communicate.

Adopting it dissolves three of your hardest problems rather than patching them:

- **The vulnerability-baseline problem disappears.** DSR evaluates an artifact against its stated design objectives, not against a counterfactual. You never owed anyone a "before" measurement; you owe them evidence that the artifact meets its objectives. Your security claim becomes "the controls contain the defined attack scenarios," which is measurable, rather than "we reduced vulnerabilities by N," which is not.
- **The experimental-statistics problem disappears.** DSR's *demonstrate* and *evaluate* activities legitimately include functional verification, penetration testing, task-based observation, and expert assessment. None of these require a null hypothesis. You keep the comparison against the manual process as an *evaluation* activity, not an experiment — which is what it actually is.
- **The orphan objective disappears.** DSR requires an explicit "define the objectives of a solution" step, which is exactly where Objective 1 (build the system) belongs. It stops being an objective with no research question and becomes the artifact the whole study is about.

**What this costs you:** roughly two pages of rewriting in §3.1, plus two citations. **What it buys you:** every "your method doesn't support that claim" question is answered before it is asked.

**Caveat:** your department's capstone manual outranks my advice on required format. If it prescribes a specific methodology chapter structure, follow it and fit DSR inside that structure rather than replacing it.

---

## 3. The eight questions that will decide your defense

Ranked by how much damage an unprepared answer does. For each: what they are really testing, and what a good answer sounds like.

### Q1. "Your title says *real-time*. Define it, and show me the number."
**Testing:** whether your headline claim is a measurement or a marketing word.
**Currently:** unanswerable. "Real-time" appears 51 times, defined zero times, measured by no research question.
**Good answer:** *"Detection to administrator notification within 15 seconds. The dashboard polls the security endpoint on a 15-second interval, and high-severity events are additionally queued for email. Our measured mean latency across N simulated intrusion events was X seconds, with a maximum of Y. We also report alert precision, because a system that alerts on everything is not detecting anything."*
**Why this is safe to say:** volunteering that it is 15-second polling rather than push is far stronger than being caught claiming instantaneity. A defined, honest bound is defensible; an undefined superlative is not.

### Q2. "You claim reduced vulnerabilities. Reduced from what? Show me the insecure version."
**Testing:** whether you understand your own measurement.
**Currently:** a trap. Table 2's baselines commit you to an insecure build you never say you will make, and your framework's ORM and templating mean you cannot produce one by omission — you would have to write deliberately unsafe code.
**Good answer:** *"We don't claim a reduction, because no insecure version of this system ever existed — the controls are part of the design, not added afterwards. What we claim is containment: across N defined attack scenarios we executed M attempts, and we report the proportion contained, where contained means the action failed, the event was logged, and the automated response fired. We also publish a residual findings register, including the medium and low findings we accepted and why."*
**Why this wins:** a panel is far more impressed by a group that reports residual risk than by one claiming a clean sweep. Zero findings of any severity reads as an incomplete test.

### Q3. "You call this experimental. State your null hypothesis and name the test you will run."
**Testing:** whether the methodology label was chosen or inherited.
**Currently:** unanswerable — the word "hypothesis" does not appear, and §3.6 offers only means and percentages while §3.6 §2 uses the word "significant."
**Good answer (after Move 1):** *"We have reframed it. This is design science research; the manual-versus-system comparison is an evaluation activity, not an experiment. We report descriptive comparison with a documented baseline protocol. If the panel prefers an inferential claim, we can add a Wilcoxon signed-rank test on paired task times, but we did not want to claim a test we had not planned for."*

### Q4. "Your leave computation must follow CSC rules. Which issuance? Walk me through Special Leave Benefits for Women."
**Testing:** whether you understand the domain you automated.
**Currently:** devastating if unprepared. "CSC," "Civil Service," "Omnibus," and "Form 6" appear **zero times** in your manuscript — yet your system implements 15 CSC leave types with per-type caps, credit sources, and document rules, against CSC Form No. 6 (Revised 2020).
**Good answer:** cite the CSC Omnibus Rules on Leave, state that SLBW is non-deductible with a 60-day cap requiring surgical documentation, and show the configuration entry that encodes it.
**Why this matters beyond the question:** this is the gap that costs you the most, because the answer is already in your code. You did the work and did not write it down.

### Q5. "Who authorised you to run Hydra and SQLMap against LGU equipment?"
**Testing:** professional and legal judgement.
**Currently:** at risk. §3.7 says testing occurs in a controlled environment but names no authorising officer and no written scope.
**Good answer:** produce a signed authorisation letter naming the systems in scope, the testing window, the tools, and the LGU officer who approved it.
**Why this is not optional:** RA 10175 (Cybercrime Prevention Act) makes unauthorised access to a computer system a criminal offence. Written authorisation is not paperwork — it is the document that distinguishes your capstone from that offence. Get it before you run a single scan.

### Q6. "Only 1 of your 30 respondents is from HR, but HR is the only office that computes credits. How is your usability finding valid?"
**Testing:** whether you understand your own instrument.
**Currently:** hard to defend. Your allocation gives 5 respondents to the Mayor's Office and 1 to HR, and asks all 30 to rate functional suitability of modules most of them never touch.
**Good answer (after Move 5):** *"We use role-differentiated instruments. Employees rate the functions they use — filing, balance viewing, status tracking. HR and approving officers rate the workflow, certification, and reporting modules. IT experts rate security and maintainability. Each characteristic is rated only by respondents who exercise it."*

### Q7. "What is actually new here? There are dozens of leave systems."
**Testing:** whether you have a contribution or only an implementation.
**Currently:** weak. Your gap claim is that existing systems lack LAN deployment and structured security — an argument from absence, which panels distrust because absence in a literature sample is not evidence of need.
**Good answer (after Move 3):** *"Two things. First, leave policy as configuration rather than code: the 15 CSC leave types, their caps, credit sources, and document requirements are data, so a new CSC issuance is a configuration change, not a code change and redeployment. Second, intrusion detection at the application layer inside the HRIS itself, rather than at a network perimeter — appropriate for a municipal office that has no security operations capability. No study in our review does both."*

### Q8. "Your one-day and ten-minute manual baselines — where did those numbers come from?"
**Testing:** whether SOP 1 has any foundation.
**Currently:** unanswerable. Both figures are asserted with no source, no sample size, and no measurement protocol, and they carry your entire efficiency claim.
**Good answer:** a documented baseline protocol — how many applications were timed, by whom, over what period, measuring which segments of the process.
**Time-critical:** see Risk R1. Once the system is deployed, the manual baseline is gone forever.

---

## 4. Seven structural moves

Ordered by leverage. Moves 1–3 are conceptual and change how the paper is read; 4–7 are concrete additions.

### Move 1 — Adopt Design Science Research as the single methodological frame
**Where:** §3.1, rewritten; §2.5 added.
**What:** replace the three-methodology paragraph with DSR, mapping your existing phases onto the DSRM activities. Keep everything you already do — requirement analysis, design, development, testing, ISO 25010 evaluation, penetration testing. Only the framing changes.
**Why:** see §2 above. This single change answers Q2 and Q3 and repairs the orphan objective.
**Cost:** ~2 pages rewritten, 2 citations added.

### Move 2 — Declare the manuscript's stage, and defend the proposal with a working prototype
**Where:** §4.1 (new Development Status subsection); a single sentence in §1.1 and §3.1.
**The situation:** your paper is written as a proposal; your artifact is complete. That mismatch surfaces the moment a panelist asks "have you built it?" — and right now the paper gives them no way to know.

**The decision is already made and it is the right one:** defend as a proposal, with the system running. Do not add Chapter V or VI yet.

**Why deferring results is correct, not a shortfall.** You have no human data. No respondents surveyed, no baseline measured, no task timings recorded. A results chapter written now would either be empty or padded with the test suite dressed up as findings — and a panel reads a thin Chapter V as evidence you did not understand what a result is. Nothing is gained by writing it early, and credibility is lost.

**What to do instead — one short subsection, high return.** Add **§4.x Development Status** to Chapter IV stating plainly:

- The artifact is complete and deployed to a test environment.
- Functional verification is in place: 13 test files, 49 automated tests covering authentication, OTP, RBAC, leave workflow, credit computation, working-day calculation, intrusion detection, reporting and the API.
- What remains is evaluation with human participants, which Chapter III specifies and which will produce Chapters V and VI.

Then change the tense **only in that subsection** — everything describing evaluation stays in future tense, because that work genuinely has not happened.

**Why this is a strong position, not a weak one:** most groups defend a proposal with slides and a mockup. You would defend one with a running system, a passing test suite, and a penetration test report. Panels notice the difference, and the Development Status section is what tells them — without it, your strongest asset is invisible in the document.

**Trigger for Chapter V:** begin it once you hold (a) the manual baseline measurements, (b) the completed gold-standard case results, and (c) respondent data. Not before.

### Move 3 — Reclaim the real contribution
**Where:** §1.1 closing and §1.2 (in scope now). The matching revision to §2.4 and the Table 1 gap column is deferred with the rest of Chapter II, but the claim must be stated in Chapter I first — that is where the panel meets it.
**What:** replace "existing systems lack LAN deployment and structured security" with a positive, specific contribution claim:

1. **Policy as configuration.** CSC leave rules — 15 types, day caps, credit sources, document thresholds, conditional form fields — are expressed as data rather than code, so a new CSC issuance is a configuration change rather than a code change and redeployment. This is a maintainability contribution with real institutional value: the system outlives the developers.
2. **Application-layer intrusion detection inside the HRIS.** Detection sits in the request pipeline of the application itself rather than at a network perimeter, which suits a municipal office with no security operations capability and no dedicated appliance.
3. **The combination.** No reviewed study does both, and the combination is what the client's situation requires.

**Why this matters:** an argument from absence ("no one else did X") invites the reply "perhaps no one needed X." An argument from institutional fit ("X is required because the client has no SOC and CSC rules change by issuance") does not.

### Move 4 — Rebuild the evaluation on three kinds of evidence
**Where:** §3.4, §3.6.
**The problem:** your evaluation is almost entirely perception. Panels discount perception-only evidence, because a satisfied user is not proof of a correct system.

Add two objective evidence types alongside it:

| Type | What it establishes | How |
|---|---|---|
| **Correctness (objective)** | The computation is right | A **gold-standard case set**: 40–60 leave scenarios spanning all 15 CSC types and the edge cases — year boundaries, holidays, half-days, insufficient balance, monetisation, terminal leave. The HR officer adjudicates the expected result for each, independently of the system. Report exact-match rate and analyse every mismatch. |
| **Performance (objective)** | It is faster, by how much | Task timings with counterbalanced order, against a baseline measured *before* rollout under a documented protocol. |
| **Perception (subjective)** | Users find it usable and appropriate | ISO 25010 instrument, role-differentiated (Move 5). |

**Why the gold-standard set is the single best addition to this study:** it converts SOP 2 from unmeasurable into rigorous, it uses the domain authority (HR) as ground truth rather than the researchers, it is entirely within your control, and it directly evidences the contribution claimed in Move 3. It is also the kind of result a panel remembers.

### Move 5 — Role-differentiated evaluation instruments
**Where:** §3.3, §3.4.
**The validity problem:** you ask 30 employees to rate functional suitability, security, and performance efficiency — but most employees touch two functions (file a request, view a balance). They cannot assess reporting, RBAC, audit logging, or the security dashboard. Averaging their ratings of things they never used produces a number that means nothing.
**The fix:** three instrument versions. Employees rate what they use. HR and approving officers rate workflow, certification, and reporting. IT experts rate security, maintainability, and portability. Report each characteristic from the group that exercises it, and say so.
**Also:** raise HR and approving-officer representation. Publish office strata sizes so "proportional" can be verified. Add expert qualification criteria (years of experience, relevant certifications) and, with only five experts, report the spread of their ratings rather than the mean alone.

### Move 6 — Legal and ethical hardening
**Where:** §3.7, expanded.
Five additions, in priority order:

1. **Written penetration-testing authorization** from a named LGU officer, specifying systems in scope, testing window, tools, and rules of engagement. Non-negotiable — see Q5.
2. **Ethics review** per your institution's process, with the approval reference cited.
3. **Data protection officer coordination.** If any real employee data is used, RA 10173 requires a lawful basis; if only synthetic data is used, state that explicitly, which is the cleaner path.
4. **Data retention and disposal plan** — what is kept, where, for how long, and how it is destroyed.
5. **Test environment isolation statement** — that security validation runs against an instance containing no live employee records, on an isolated segment.

### Move 7 — Add the missing structure
**Where:** Chapter I and the end of the manuscript.

- **§1.5 Conceptual Framework (IPO).** Input: manual process, CSC rules, threat model. Process: DSR activities. Output: artifact plus the SOP findings. Most Philippine BSIT panels expect this and its absence is noticed immediately.
- **§1.6 Definition of Terms.** Real-time alert; deductible and non-deductible leave; control effectiveness rate; plantilla employee; policy as configuration.
- **§4.x Development Status** — the artifact is complete; here is the evidence. See Move 2.

*Not now:* Chapter V and VI skeletons. Empty result tables invite the panel to ask what goes in them, and you do not yet have the answer. Add them when the data exists.

---

## 5. Risk register

Things that can stop the project, as distinct from things that weaken the paper.

| ID | Risk | Severity | Window | Mitigation |
|---|---|---|---|---|
| **R1** | **Manual baseline is lost.** SOP 1 and SOP 2 both depend on measuring the manual process. Once the system is deployed, that measurement can never be taken. | **High** | **Immediate — before any rollout** | Measure now. Time 15–20 real applications end to end and 15–20 form completions, with a written protocol. This is the single most time-critical action in this document. |
| **R2** | **No penetration-testing authorization.** Without it, SOP 3 cannot be executed lawfully and the security half of the study collapses. | **High** | Before any scanning | Obtain a signed authorisation letter. Do not run Hydra or SQLMap until it is in hand. |
| **R3** | **Employee workstation access.** LAN-only deployment assumes employees can reach a networked PC. Field-based staff in agriculture, engineering, and health services may not have one. If a foreman must walk to an office terminal, your efficiency gain does not materialise for them. | Medium | Before evaluation | Either state the assumption and its limits, or provide a mitigation — HR-assisted filing, shared kiosk, or in-network mobile access — and evaluate it. |
| **R4** | **OTP delivery depends on a LAN mail relay.** The system is LAN-isolated with no internet dependency, but the second factor is delivered by email. If the LGU has no internal mail server, the OTP path fails during the demo. | Medium | Before demo | Confirm the relay exists, test it, and document the audited administrative toggle as the disclosed contingency. |
| **R5** | **HR under-representation.** One HR respondent cannot validate the workflow and computation modules that only HR uses. | Medium | Before data collection | Raise HR and approver counts; adopt role-differentiated instruments (Move 5). |
| **R6** | **Respondent availability.** 35 respondents across 17 offices during working hours, in an office that processes 5–25 applications a month. | Low–Medium | Scheduling | Coordinate through HR; schedule by office; build in a replacement margin. |

---

## 6. Sequenced revision plan

Dependencies matter more than effort here. Steps 1 and 2 are time-critical and gate later work.

**Phase 1 — Do not delay (this week)**
1. **Measure the manual baseline.** R1. Everything in SOP 1 and SOP 2 depends on data that stops existing the moment you deploy.
2. **Request the penetration-testing authorization letter.** R2. Approval takes time; start the clock.
3. **Draft §4.x Development Status** (Move 2) — the single subsection that tells the panel the artifact exists. Short, and it changes how everything else is read.

**Phase 2 — Conceptual repairs (fixes the most questions per page rewritten)**
4. Rewrite §3.1 around Design Science Research (Move 1). Repairs Q2, Q3, and the orphan objective.
5. Rewrite the SOPs and objectives to 1:1 alignment, adding the alerting question (see the companion examiner report, R1). Repairs Q1.
6. Rewrite the contribution and gap statement (Move 3). Repairs Q7.
7. Rewrite §3.6 §3 and Table 2 around control effectiveness and residual findings (companion report, R2). Repairs Q2.

**Phase 3 — Evidence design**
8. Build the gold-standard case set with your HR officer (Move 4). This is your strongest single addition.
9. Split the instrument by role; fix the sampling allocation and publish strata sizes (Move 5). Repairs Q6.
10. Write the baseline measurement protocol into §3.4 using the data from step 1. Repairs Q8.

**Phase 4 — Compliance and structure**
11. Expand §3.7 with all five ethical and legal additions (Move 6). Repairs Q5.
12. Add §1.5 Conceptual Framework and §1.6 Definition of Terms (Move 7). Chapter V–VI structure stays out until data exists.

**Phase 5 — Evidence and citation repair**
13. Add the CSC Omnibus Rules, ISO/IEC 25010, and RA 10173 to the reference list and cite them at their Chapter I and Chapter IV points of use. Repairs Q4.
14. Fix the reference-list defects, the §1.2 copy-paste duplication, and the manuscript-vs-system drift in Chapter IV (companion report, W4 and W5).

**Deferred with Chapter II:** the RBAC and ISO 25010 citation repairs, the §2.3/§2.4 merge, the Table 1 note duplication, and the appraisal sentences throughout the review. They are real and they are documented in the companion report — they are simply not in this pass.

---

## 7. What I would not change

An adviser who asks for everything gets nothing. These are right and should survive revision:

- **The client and the problem.** A named LGU with a real manual process is a better capstone problem than a generic one. Do not abstract it.
- **The LAN-confinement decision.** Well motivated, consistently held, and defensible on both security and infrastructure grounds. It is over-argued in the text, not wrong.
- **The scope boundary.** Excluding payroll, biometrics, performance management, and external integrations is correct. Panels reward a defended boundary and punish an undefended sprawl. Do not widen it under questioning.
- **ISO/IEC 25010 as the quality frame.** Appropriate and standard. It needs citing and role-differentiating, not replacing.
- **The four-point forced-choice scale.** A legitimate design decision. Cite it and acknowledge the trade-off rather than defending it as strictly superior.
- **The engineering itself.** The concurrency-safe credit deduction, append-only audit trail, JSON-configurable policy engine, and inheritance-based RBAC are genuinely above the median for this level. The task is to write them down, not to change them.

---

## 8. The one-sentence version

**Your engineering is ahead of your writing, your paper claims less than you built, and the defense is decided by the paper — so spend the remaining time on the framing, the baseline data, and the authorization letter, not on the code.**
