# Examiner-Style Review — `Leave_Manus_updated.docx`

**Manuscript:** *A Cybersecurity Integrated Digital Leave Management System With Real-Time Intrusion Alerts For Local Government Unit Of Alicia* (Chapters I–IV, ~17,400 words, 33 sources)
**Reviewed against:** argument clarity, logical structure, evidence quality, critical analysis, methodological consistency, repetition, unsupported claims, alignment with the stated research questions.
**Corroborating basis:** this repository (the implemented system) — used *only* to test whether the manuscript's technical claims match what was actually built. No code changes are proposed here.

> **Standing constraint honoured throughout:** nothing below rewrites the chapter wholesale or changes your argument. Your core claim — *that a LAN-confined, CSC-compliant leave system with integrated, application-level security controls is the appropriate solution for LGU Alicia* — is sound and is left intact. Where a change would alter an argument, the reason is stated explicitly and marked **[ARGUMENT CHANGE]**.

---

## 0. Alignment map: research questions → objectives → instruments → analysis

This is the first table a panel will build in their heads. Yours currently reads:

| SOP (1.1.2) | Objective (1.2) | Instrument (3.4) | Analysis (3.6) | Verdict |
|---|---|---|---|---|
| — *(none)* | Obj. 1 — develop the system | — | — | **Orphan objective.** No SOP asks it. |
| SOP 1 — % reduction in processing time | Obj. 2 | Task-based testing | Comparative | Aligned, but baseline unsourced (§2, W3) |
| SOP 2 — rate of improvement in computation accuracy | Obj. 3 | *not specified* | "decrease in errors" | **No instrument.** No error definition, no ground truth |
| SOP 3 — reduction in detected vulnerability count | Obj. 4 | Pen-test tools | Security Evaluation | **Unmeasurable as posed** (W2) |
| SOP 4 — "level of system usability based on ISO/IEC 25010 in terms of functionality, usability, performance, and security" | Obj. 5 | ISO 25010 questionnaire | Descriptive | Aligned, but circular wording (§2, W1c) |
| — *(none)* | — *(none)* | — | — | **Real-time intrusion alerts — the title's headline claim — is measured by no SOP.** (W1) |

Two of the six rows are broken and one is missing entirely. Fixing this table is the single highest-return revision in the manuscript.

---

## 1. The five most important weaknesses

### W1 — The title's headline contribution is never operationalised or evaluated
**Severity: critical. Affects: Ch. I, Ch. III, Ch. IV.**

"Real-time" appears **51 times** in the manuscript. It is defined **zero** times. No SOP measures it, no data-gathering instrument captures it, and §3.6 contains no metric for it. Objective 4 mentions "number of detected and generated intrusion alerts" as a raw count — but a count of alerts is not evidence that alerting is *real-time*, and it says nothing about false positives.

This is the question a panel asks first: *"Your title promises real-time intrusion alerts. Which SOP measures them?"* At present there is no answer.

It is also the easiest weakness to fix, because the system already produces the evidence. The implementation delivers alerts by 15-second AJAX polling of `/api/v1/security/alerts` (`docs/adr/ADR-012-polling-alerts.md`), with high-severity events additionally queued to email. That gives you a defensible, honest, *measurable* definition — and one you should state rather than hide, since an unqualified "real-time" invites the objection that polling is not real-time at all.

**Recommended fix:** add SOP 5 and a matching objective and metric (see rewrite R1 and outline §3). Define real-time explicitly as *detection-to-dashboard latency ≤ 15 s under a 15-second polling interval*, and add **alert precision** (true alerts ÷ total alerts) so the claim is falsifiable in both directions.

---

### W2 — SOP 3's before/after vulnerability count cannot be measured as designed
**Severity: critical. Affects: 1.1.2, 1.2 (Obj. 4), 3.6 §3, Table 2.**

SOP 3 asks for "the decrease in detected vulnerability count **before and after** the implementation of security mechanisms." Table 2's *Baseline Condition* column commits you to conditions such as:

- "Existing vulnerabilities identified before implementation"
- "Unauthorized access successful during unsecured testing"
- "System vulnerable to SQL injection"
- "No login blocking mechanism implemented"

Those baselines describe a **deliberately insecure build of your own system that the manuscript never says will be produced**, and §3.2 (System Development Method) contains no phase in which one is created. Two further problems compound this:

1. **The baseline is not achievable by omission.** The delivered system is built on a framework whose ORM parameterises queries and whose templating escapes output by default. You cannot obtain "System vulnerable to SQL injection" by simply switching a control off; you would have to write deliberately unsafe code, and then the measured "reduction" measures the deliberateness of your sabotage, not the strength of your design.
2. **The manual system is not a valid baseline either.** A paper-based process has no SQL injection surface, so "vulnerabilities before implementation" has no referent in the pre-system state. The comparison has no defined comparator.

**[ARGUMENT CHANGE — and here is why]** Your security claim must shift from *"we reduced N vulnerabilities to M"* to *"under defined attack scenarios, the implemented controls contained X of Y attempts, with the residual risk documented."* This is a change of measurement claim, not of argument: you still claim the system is secure, and you still evidence it with controlled penetration testing. It is a change I am recommending because the original claim is not obtainable, and a panel that spots this will treat any reported "reduction" figure as fabricated. The reframed claim is both defensible and stronger, because it is verifiable. It also matches what the repository's own assessment already reports: findings are expressed as controls-verified with documented residual items, not as a before/after delta.

**Recommended fix:** replace the *Baseline Condition* column with *Attack Scenario Definition*; report **control effectiveness rate** per scenario (attempts blocked ÷ attempts made) plus a **residual findings register** classified by severity. See rewrite R2.

---

### W3 — The design claims to be experimental but the analysis plan is not
**Severity: major. Affects: 3.1, 3.5, 3.6.**

§3.1 names three methodologies, including "Experimental Research methodology... to compare the proposed system with the existing manual leave monitoring process." An experimental claim carries obligations the manuscript does not meet:

- **No hypotheses.** The word "hypothesis" appears zero times. An experimental comparison without H₀/H₁ has nothing to test.
- **No inferential statistics.** §3.6 offers mean, frequency, percentage, and informal comparison only. There is no paired t-test, no Wilcoxon signed-rank, no significance level, no effect size. You cannot claim an experiment and then report only descriptive means; "a significant reduction" (§3.6 §2, *Processing Time*) is a statistical term used non-statistically.
- **Order and learning effects are uncontrolled.** §3.5 step 3 has each participant "perform selected tasks using both the manual process and the proposed system," always in that order. The second performance benefits from having just done the first. Some portion of your headline efficiency gain will be a learning effect, and a panel will say so. Counterbalancing (half the participants system-first) or a washout interval is needed.
- **Baselines are asserted, not measured.** §3.6 §2 states manual processing takes "an average of one (1) day" and form-filling "approximately 10 minutes per employee" with no source, no sample size, and no measurement protocol. These two numbers carry SOP 1 entirely.
- **No sample size justification.** 30 of 135 employees (22%) with no Slovin/power computation; strata sizes per office are never given, so the claim of *proportional* stratified sampling in §3.3 cannot be verified. The stated allocation also gives **5 respondents to the Mayor's Office and 1 to the Office on Human Resource Management** — HR is the system's primary power user and the only office that performs credit computation. Under-sampling your principal user group weakens every usability finding.
- **Reliability is conditional.** §3.4 says a pilot test "**may** also be conducted." No Cronbach's alpha is promised. For an adapted instrument, that is a gap.

**Recommended fix:** either (a) keep the experimental claim and add hypotheses, counterbalancing, a paired test with α = 0.05, and a documented baseline-measurement procedure (n, observer, timing method); or (b) downgrade the label to *quasi-experimental one-group pretest–posttest* and say so plainly. Option (b) is honest and adequate for a capstone; option (a) is stronger if you have the time.

---

### W4 — The evidence base is thinnest exactly where the argument is load-bearing
**Severity: major. Affects: Ch. II throughout, References.**

Three uncited stretches sit under three of your central claims:

1. **The RBAC block** (2.2, *Role-Based Access Control in Information Systems*, four consecutive paragraphs beginning "Access control mechanisms play a vital role...") carries **no citation at all**, including the sentence "Research indicates that RBAC enhances system security..." — a bare appeal to unnamed research. RBAC is one of your two named security mechanisms; it cannot be the least-evidenced section in the review.
2. **The ISO/IEC 25010 block** (2.2, three paragraphs) has no citation, and **the standard itself does not appear in the reference list**, despite being named in SOP 4, Objective 5, §3.4, §3.5, and §3.6. You must cite the standard, and specify the edition — ISO/IEC 25010:2011 and the 2023 revision differ in characteristic structure, and a panel member who works with the 2023 edition will notice you have not said which you used.
3. **The Philippine LGU block** (2.2 Local Literature, opening three paragraphs) makes empirical claims about digitalisation in Philippine municipalities before any citation appears.

Two further gaps are more serious than they look:

- **The CSC Omnibus Rules on Leave are never cited — not once.** "CSC," "Civil Service," "Omnibus," and "Form 6" each appear **zero** times in the manuscript. Yet the entire deductible/non-deductible distinction that your argument rests on (1.1.1, 2.3, 2.4) is a *legal* classification set by the Civil Service Commission, and the delivered system implements 15 CSC leave types against CSC Form No. 6 (Revised 2020) with per-type policy rules. Your strongest differentiator from every study in Table 1 is currently invisible because you have described it generically as "leave classification" instead of naming its regulatory source. This is a self-inflicted wound: the paper undersells the system.
- **RA 10173** is discussed in 2.2 and relied on in 3.7 but is absent from the reference list.

**Reference-list defects requiring verification before submission:**

| Entry | Problem |
|---|---|
| Khan et al. (2023) | Year, volume, and DOI disagree — *IEEE Access* vol. 9 is 2021, and the DOI is `...ACCESS.2021.3110310`. Also IEEE Access does not use issue numbers in the form "9(2)". |
| Alade et al. (2022) | Journal and DOI disagree — listed as *IJACSA* 13(4) but the DOI prefix `10.7753/IJCATR1104...` belongs to *IJCATR*, vol. 11 iss. 4. |
| Alade et al. — in text | Cited as **2022** (2.1, 2.2, 2.4) and as **2023** (2.2, 2.4). Only a 2022 entry exists. |
| Choudhari et al. (2023) | Two-author work cited with "et al."; APA 7 requires "Choudhari and Yengantiwar (2023)". |
| Adamu (2020) / Alade et al. (2022) | Near-identical titles. Confirm both exist and are distinct. |
| ISO/IEC 25010; RA 10173 | Cited in text and central to the design; **missing from the list entirely**. |
| CSC Omnibus Rules on Leave | Not cited anywhere; must be added (see above). |
| Jadhav (2023), Maulana & Amelia (2025), Moreno & Zabala (2023), Farayola (2024), Manoharan (2024), Adamu (2020), Choudhari (2023) | Reviewed in the text but absent from Table 1, whose inclusion criteria are never stated. |

---

### W5 — Substantial repetition, plus drift between the manuscript and the delivered system
**Severity: major. Affects: Ch. I, II, IV.**

**(a) Two literal copy-paste duplications** — these will be read as carelessness and cost you disproportionately:

- **§1.2, opening paragraph.** The sentence beginning *"The primary objective of this study is to develop a Cybersecurity Integrated Digital Leave Management System..."* is pasted **twice inside the same paragraph**, the first copy breaking off mid-clause at "through the implementation" before restarting.
- **Table 1 note.** The note begins *"Note: Table 1 presents the comparison of related literature and studies..."*, runs on, and then restarts mid-sentence with a second *"Note: Table 1 presents the comparison of related studies relevant to the proposed system..."* — one note pasted over another.

**(b) Structural redundancy:**

| Repeated content | Where | Recommendation |
|---|---|---|
| Manual process → delays, errors, retrieval difficulty | 1.1 ¶1–3; 1.1.1 ¶2–3; 1.1.2 ¶1; 2.2; 2.4 | Say once in 1.1. Later sections cite forward, not restate. |
| The same four research gaps | **2.3 (¶2–4) and 2.4 (¶3–6) state them twice, in the same order** | Merge. 2.3 = comparison of studies; 2.4 = synthesis and gap statement. Currently both do both. |
| "In response, this study proposes a Cybersecurity Integrated Digital Leave Management System..." | Ends 2.1, 2.3, **and** 2.4 (2.3's and 2.4's closing sentences are near-identical) | Keep once, at the end of 2.4. |
| LAN rationale (reduced external exposure, controlled access, faster internal comms) | 1.1 ¶7; 4.1.1; 4.2.1 ¶1 and ¶7; Figure 2 note; Figure 5 note; 4.9.5 | Argue once in 4.2.1. Figure notes should describe the figure, not re-argue the design. |
| Attack pass/fail criteria for SQLi and brute force | **Table 2 and Table 5 both define them** | Merge into one table (see outline). |
| Full system title spelled out | 30+ times | Define once, then "the proposed system." |

**(c) Manuscript-vs-system drift.** Checked against the repository, four descriptions in Chapters III–IV do not match what was built:

| Manuscript says | System actually does | Why it matters |
|---|---|---|
| "Develop the system using PHP, MySQL, HTML, CSS, and JavaScript" (§3.2, §4.4.5) — no framework named | Laravel 12 / PHP 8.3, Bootstrap 5, Chart.js, Sanctum-authenticated REST API | The framework's ORM and auto-escaping templating are *what actually delivers* the SQLi/XSS resistance you claim in §4.4.4 and Table 5. Omitting it means your defence has no stated mechanism. |
| Users are employees, HR, department heads, and system administrator; approval is a single review step (§4.1.1, §4.3.1) | Six roles, and a **Department Head → HR (certify) → Mayor (final)** three-stage workflow with automatic balance deduction on final approval | Your workflow is more rigorous than the paper describes, and the Mayor's approval stage — a genuine LGU governance feature — is invisible. |
| "Leave classification (deductible and non-deductible)" (§3.2, §4.1.1) | 15 CSC leave types with per-type JSON policy rules, credit sources, max-day caps, and document requirements | Undersells your main contribution. See W4. |
| Figure 9: "Database Server (Encrypted)"; §4.9.5: "encryption ensures that sensitive information is protected both at rest and during retrieval"; firewall drawn **between** web server and database server | No at-rest database encryption is implemented; the deployment is a **single host** with the database on `localhost`, so no firewall sits between the two tiers | **This is an unsupported claim in a figure and its narrative.** Either implement at-rest encryption and say so precisely, or correct the figure and the text. Do not leave it as drawn. |

---

## 2. Specific passages needing revision

Ordered by chapter. "Locator" gives the section plus the passage's opening words.

### Chapter I

| # | Locator | Issue | Action |
|---|---|---|---|
| 1.1 | §1.1 ¶1 "The Local Government Unit (LGU) of Alicia currently manages..." vs §1.1.1 ¶2 "In the LGU of Alicia, leave monitoring is currently performed..." | Near-duplicate paragraphs | Cut the 1.1.1 restatement; keep 1.1.1 for institutional/HR-function framing only |
| 1.2 | §1.1 ¶2 "approximately 135 employees and an average of 5 to 25 leave applications per month" | Figures repeated in §1.1.2, §1.3, §3.3, §2.4 with no source | State once with a source (HR records, date accessed); cite thereafter |
| 1.3 | §1.1.1 ¶7 "Unlike attendance systems, leave management involves complex classification..." | Unsupported comparative claim used to justify scope | Support with the CSC Omnibus Rules, or soften to "involves policy-based classification under CSC rules" |
| 1.4 | §1.1.2 ¶3–6 (the four SOPs) | Missing SOP for real-time alerts; SOP 2 has no instrument; SOP 3 unmeasurable; SOP 4 circular | **Rewrite — see R1** |
| 1.5 | §1.2 ¶1 "The primary objective of this study is..." | **Sentence duplicated inside the paragraph** | Delete the duplicate |
| 1.6 | §1.2 Obj. 1 | No corresponding SOP | Either add a development SOP or reclassify Obj. 1 as the general objective |
| 1.7 | §1.2 Obj. 4 | Bundles seven controls and five output counts into one objective | Split: 4a implementation of controls; 4b measurement of control effectiveness |
| 1.8 | §1.3 ¶1 "approximately 135 employees" vs §3.3 "135 plantilla employees" vs §1.3 ¶4 "limited to permanent employees" | Population defined three ways | Fix on one term (plantilla/permanent) and use it throughout |
| 1.9 | §1.4 Significance | Four stakeholder bullets, all generic | Tie each to a specific SOP finding the study will produce |
| 1.10 | Chapter I as a whole | **No conceptual/theoretical framework and no definition of terms** | Add both (see outline §1.5, §1.6) — most Philippine BSIT panels require them |

### Chapter II

| # | Locator | Issue | Action |
|---|---|---|---|
| 2.1 | 2.2 *Role-Based Access Control in Information Systems*, all four ¶s | **Zero citations**, including "Research indicates that RBAC enhances..." | **Rewrite — see R3** |
| 2.2 | 2.2 *ISO/IEC 25010 Software Quality Model*, all three ¶s | No citation; standard absent from reference list; edition unspecified | Cite ISO/IEC 25010 directly; name the edition used |
| 2.3 | 2.2 Local Literature ¶1–3 "Digital transformation has influenced the adoption..." | Empirical claims precede any citation | Attach citations or move after the Moreno & Zabala paragraph |
| 2.4 | 2.2 *Digital Information Systems for Government Data Management*, five ¶s | Five consecutive paragraphs on **one** source (Moreno & Zabala 2023), largely restating each other; ¶5 begins "Overall, the study demonstrated..." and repeats ¶1 | Compress to one paragraph; the space belongs to the uncited sections above |
| 2.5 | 2.3 ¶2–4 vs 2.4 ¶3–6 | **The same four gaps stated twice** | Merge — 2.3 compares, 2.4 synthesises |
| 2.6 | 2.3 final ¶ vs 2.4 final ¶ | Near-identical closing sentences | Keep one |
| 2.7 | Table 1 note | **Note duplicated mid-sentence over itself** | Delete the second copy |
| 2.8 | Table 1 | Inclusion criteria unstated; 7 reviewed sources omitted; the "Limitations Identified" column reads as uniform ("no LAN, no cybersecurity") | State criteria; add a *Relevance to this study* column so the gap argument is visible per row |
| 2.9 | Throughout Ch. II | Every source is reported, none is appraised — no study design, sample size, or limitation is questioned | Add one appraisal sentence per major source. **This is the single biggest lift to your "critical analysis" mark.** |
| 2.10 | 2.2 Foreign Literature | Nothing on IDS/IPS literature despite intrusion detection being in the title | Add a short subsection on application-level intrusion detection; cite it |

### Chapter III

| # | Locator | Issue | Action |
|---|---|---|---|
| 3.1 | §3.1 ¶1 "multi-method research design integrating..." | Three methodologies named; only design-and-development is actually elaborated | Justify each, or drop "Experimental" (see W3) |
| 3.2 | §3.1 ¶4–5 | Penetration testing scope hedged three times in successive paragraphs | Compress to one paragraph |
| 3.3 | §3.3 ¶3–4 | Proportional allocation given without office strata sizes; **HR allocated 1 respondent, Mayor's Office 5** | Publish strata sizes; raise HR/approver representation |
| 3.4 | §3.3 | No sample size justification | Add Slovin's formula or a power computation |
| 3.5 | §3.4 ¶1 "A pilot test **may** also be conducted" | Reliability left optional | Commit to it; report Cronbach's α |
| 3.6 | §3.4 ¶3 "The use of a four-point scale will eliminate neutral responses and will improve accuracy" | Unsupported methodological claim | Cite a forced-choice-scale source, or state it as a design choice with its trade-off acknowledged |
| 3.7 | §3.4 *Task-Based Testing* success criteria | Four criteria listed, but "≤10% performance gap" is not defined until §3.6 | Define at first use |
| 3.8 | §3.5 step 3 "Participants will perform selected tasks using both the manual process and the proposed system" | **Order effect uncontrolled** | Counterbalance; state it |
| 3.9 | §3.6 §2 "manual leave processing will take an average of one (1) day... approximately 10 minutes per employee" | **Unsourced baselines carrying SOP 1** | Describe how they were measured: n, method, observer, period |
| 3.10 | §3.6 §2 "A significant reduction... will indicate improved efficiency" | "Significant" used without a test | Add the statistical test, or change the word |
| 3.11 | §3.6 §3 + Table 2 *Baseline Condition* column | **Unmeasurable baseline** | **Rewrite — see R2** |
| 3.12 | Table 2 vs Table 5 | Overlapping scenarios and pass/fail criteria | Merge |
| 3.13 | §3.6 | No inferential statistics anywhere | Add, or relabel the design |
| 3.14 | §3.7 | No ethics-review/IRB approval; no data retention or disposal plan; **no written LGU authorisation for penetration testing** | Add all three. The pen-test authorisation is not optional — you are attacking a system on an LGU network |

### Chapter IV

| # | Locator | Issue | Action |
|---|---|---|---|
| 4.1 | §4.1 ¶1–5 | Five paragraphs previewing the chapter before it starts | Compress to one |
| 4.2 | §4.1.1 ¶1–2 and §4.2.1 ¶1, ¶7 | LAN rationale argued four times in two sections | Argue once in §4.2.1 |
| 4.3 | §4.1.1 "Regular employees... HR personnel... Department heads... system administrator" | **Omits the Mayor's final-approval stage and one of six roles** | Correct to the implemented Dept Head → HR → Mayor workflow |
| 4.4 | §4.1.1 "Due to the implementation of automated computation... responsibilities related to leave computation will be streamlined" | Organisational-change claim with no change-management evidence | Support or remove |
| 4.5 | §4.2.3 Figure 2 note, and Figure 5 note (§4.9.1) | The two notes describe substantially the same topology at length | Keep Figure 5 as the topology; make Figure 2's note descriptive only |
| 4.6 | §4.3.2, each component bullet | CIA-triad mapping appended to every bullet, then repeated wholesale in §4.3.4 Table 3 | Keep the table; drop the per-bullet mapping |
| 4.7 | §4.4.5 "PHP – will be used for backend processing" | **Framework never named** | Name Laravel 12; state that its ORM and templating provide the parameterisation and escaping claimed in §4.4.4 |
| 4.8 | §4.4.7 "Will be use to perform..." (×3) | Grammatical error repeated three times | "will be used to" |
| 4.9 | §4.4.3 Real-Time Intrusion Alert Workflow | Describes the workflow well, but never states detection latency or the polling mechanism | Add the ≤15 s figure and the mechanism; this is what makes W1's fix possible |
| 4.10 | §4.9.5 Figure 9 note, ¶3 "Database Server (Encrypted)... protected both at rest and during retrieval" | **Unsupported by the implementation** | Correct the figure and text, or implement and evidence it |
| 4.11 | §4.9.5 Figure 9 note, ¶2 firewall between web and database server | Deployment is a single host, database on `localhost` | Redraw to match the real topology |
| 4.12 | §4.8 Assumptions | OTP is delivered by email, but §4.8 never assumes a reachable LAN mail relay while §4.2.1 stresses "will not depend on external internet connectivity" | Add the assumption explicitly, and note the audited `otp_enabled` fallback |
| 4.13 | §4.7 *Availability* "accessible... during office hours" | Weakest of the six security considerations; one sentence, no mechanism | Add backup/restore and recovery provisions |

---

## 3. Improved outline

Changes from your current structure are marked **[NEW]**, **[MOVED]**, **[MERGED]**, or **[CUT]**. Chapter and section numbering is otherwise preserved so your existing text can be dropped in.

```
CHAPTER I — INTRODUCTION
 1.1  Project Context
      1.1.1  Background of the Study        [MERGED] absorb 1.1 ¶1–3; no restatement
      1.1.2  Statement of the Problem       [REWRITTEN — R1]
             SOP 1  processing time
             SOP 2  computation accuracy
             SOP 3  control effectiveness under simulated attack   (was: vulnerability reduction)
             SOP 4  ISO/IEC 25010 product quality
             SOP 5  intrusion-alert timeliness and precision       [NEW — closes W1]
 1.2  Objectives                            general + five specific, 1:1 with SOP 1–5
 1.3  Scope and Limitations                 [MERGED] absorb Ch. IV §4.8 limitations; keep Ch. IV assumptions
 1.4  Significance of the Study             each stakeholder tied to a specific SOP
 1.5  Conceptual Framework (IPO)            [NEW] Input: manual process, CSC rules, threat model
                                                  Process: development + evaluation
                                                  Output: system + five SOP findings
 1.6  Definition of Terms                   [NEW] real-time alert; deductible/non-deductible leave;
                                                  control effectiveness rate; LAN isolation; plantilla employee

CHAPTER II — REVIEW OF RELATED LITERATURE AND STUDIES
 2.1  Introduction
 2.2  Related Literature
      2.2.1  Digital HRIS and leave management                    (appraise, don't just report)
      2.2.2  CSC leave policy and Form No. 6 in Philippine LGUs   [NEW] closes the W4 gap
      2.2.3  Access control and authentication in information systems   (add citations)
      2.2.4  Application-level intrusion detection and alerting   [NEW] supports the title
      2.2.5  Information security standards: ISO/IEC 27001, RA 10173
      2.2.6  ISO/IEC 25010 product quality model                  (cite the standard; name the edition)
 2.3  Related Studies                       comparison only; Table 1 with stated inclusion criteria
 2.4  Synthesis and Research Gap            [MERGED] the four gaps stated ONCE, here
 2.5  Theoretical/Conceptual Grounding      [NEW] CIA triad + defence-in-depth + ISO 25010, linked to 1.5

CHAPTER III — RESEARCH METHODOLOGY
 3.1  Research Design                       label matched to the analysis actually planned (W3)
 3.2  System Development Method             name the framework and stack
 3.3  Participants and Sampling             strata sizes; sample-size justification; HR/approver weighting
 3.4  Research Instruments
      3.4.1  ISO/IEC 25010 questionnaire    validation + Cronbach's α committed, not optional
      3.4.2  Task-based testing protocol
      3.4.3  Manual-process baseline protocol            [NEW] closes the unsourced-baseline gap
      3.4.4  Security testing protocol      [MERGED] Tables 2 + 5 into one scenario table
 3.5  Data Gathering Procedure              counterbalanced order for the manual/system comparison
 3.6  Data Analysis
      3.6.1  Descriptive (means, frequencies, ISO 25010 interpretation scale)
      3.6.2  Inferential (paired test, α, effect size)  [NEW] — required if "experimental" is kept
      3.6.3  Security analysis (control effectiveness rate + residual findings register)  [REWRITTEN — R2]
      3.6.4  Alert timeliness analysis (latency distribution, precision)  [NEW] serves SOP 5
 3.7  Ethical Considerations                + ethics review, data retention/disposal,
                                              and written LGU authorisation for penetration testing

CHAPTER IV — TECHNICAL BACKGROUND
 4.1  Introduction                          [CUT] five preview ¶s → one
 4.2  System Overview                       correct to six roles and the Dept Head → HR → Mayor workflow
 4.3  Network Architecture                  LAN rationale argued ONCE, here
 4.4  Application Architecture              name the framework; state the CSC leave-type policy engine
 4.5  Security Architecture
      4.5.1  Control mapping (Table 3)      keep; remove the duplicate per-bullet CIA mapping in 4.3.2
      4.5.2  Threat model (Table 4)
      4.5.3  Real-time alert workflow       state the mechanism and the ≤15 s latency
 4.6  Tools and Technologies
 4.7  Security Testing Environment
 4.8  Assumptions                           [MOVED] limitations to 1.3; add the LAN mail-relay assumption
 4.9  System Diagrams                       Figure 9 corrected to the real single-host topology;
                                              remove the unimplemented at-rest-encryption claim
```

**Net effect:** four new subsections that close genuine gaps, roughly 2,000–2,500 words recovered from redundancy, and every SOP traceable to an instrument and an analysis.

---

## 4. Sample rewrites of the three weakest passages

These three were selected because each one is *load-bearing* — the rest of the manuscript depends on it — and each is currently the weakest link in its chapter.

---

### R1 — §1.1.2, Statement of the Problem (the four SOPs)

**Why this passage:** every downstream chapter is judged against these four questions. Two are unmeasurable as posed, one has no instrument, one is circular, and the title's headline feature is missing.

**Current:**

> Specifically, this study aims to determine the following:
> 1. What is the percentage reduction in leave processing time achieved by the proposed system compared to the existing manual process?
> 2. What is the rate of improvement in the accuracy of leave credit computation using the automated system?
> 3. How effective is the system in reducing system vulnerabilities as measured by the decrease in detected vulnerability count before and after the implementation of security mechanisms and validation through controlled penetration testing?
> 4. What is the level of system usability based on ISO/IEC 25010 in terms of functionality, usability, performance, and security?

**Proposed:**

> Specifically, this study seeks to answer the following questions:
>
> 1. What is the percentage reduction in leave processing time achieved by the proposed system, compared with a measured baseline of the existing manual process, in terms of (a) end-to-end request turnaround and (b) time to complete a single leave application form?
> 2. What is the improvement in the accuracy of leave credit computation, measured as the leave-credit error rate — the proportion of processed applications whose computed balance deviates from the balance derived by the CSC Omnibus Rules on Leave — under the manual process compared with the automated system?
> 3. How effective are the implemented security controls in containing simulated attacks, measured as the control effectiveness rate (attempts contained ÷ attempts made) across the defined attack scenarios, and what residual findings remain after controlled penetration testing?
> 4. What is the level of product quality of the proposed system as evaluated by end users and IT experts using ISO/IEC 25010, in terms of functional suitability, usability, performance efficiency, and security?
> 5. What is the timeliness and precision of the system's intrusion alerting, measured as (a) the detection-to-notification latency for each simulated intrusion event and (b) alert precision, the proportion of generated alerts corresponding to genuine intrusion attempts?

**What changed, and why:**

| # | Change | Reason |
|---|---|---|
| 1 | Split into two measurable sub-items | "Processing time" conflated two different quantities you already measure separately in §3.6 (one day vs ten minutes) |
| 1 | "a measured baseline" | Commits you to §3.4.3, closing the unsourced-baseline gap |
| 2 | Defined *leave-credit error rate* with CSC rules as ground truth | Your only defensible reference standard, and it names the regulatory basis the manuscript currently omits entirely |
| 3 | **[ARGUMENT CHANGE]** "reducing vulnerabilities" → "containing simulated attacks" + residual findings | The original quantity cannot be obtained — see W2. Your security claim is preserved; only the measurement is made obtainable |
| 4 | "usability... in terms of ... usability" → "product quality" | Removes circularity; "product quality" is ISO/IEC 25010's own term |
| 4 | "functionality" → "functional suitability" | The standard's actual characteristic name |
| 5 | **New** | Closes W1 — the title's headline feature now has a research question, and both metrics are producible by the system as built |

---

### R2 — §3.6 §3, Security Evaluation Analysis, and Table 2's baseline column

**Why this passage:** it is the analysis plan for the security half of a *cybersecurity* thesis, and as written it promises a number that cannot be produced.

**Current:**

> Security evaluation will be conducted based on the results of controlled penetration testing and system validation within the Local Area Network (LAN) environment. The evaluation aims to determine whether the implemented security mechanisms effectively reduce vulnerabilities and prevent unauthorized activities within the system. The interpretation will be based on the following:
> - **Vulnerability Identification** — The number of vulnerabilities detected during testing will be recorded.
> - **Vulnerability Reduction** — A lower number of identified vulnerabilities after implementing security mechanisms will indicate improved system security.
> - **Effectiveness of Security Controls** — The ability of the system to prevent common threats... will indicate that the implemented security mechanisms are functioning effectively.

**Proposed:**

> Security evaluation will be conducted through controlled penetration testing of the deployed system within an isolated Local Area Network segment containing no live employee data. Because the system's security controls are integral to its design rather than added after development, no unsecured version of the system exists and a pre-implementation vulnerability count is not obtainable. The evaluation therefore measures how completely the implemented controls contain a defined set of attack scenarios, and what risk remains after they do. Three measures will be reported:
>
> **1. Control effectiveness rate.** For each attack scenario in Table 2, a fixed number of attempts will be executed against the running system. The control effectiveness rate is the proportion of attempts contained — blocked, rejected, or denied — out of attempts made. An attempt counts as contained only if all three of the following hold: the action does not succeed; the event is recorded in the audit or intrusion log; and, where the scenario defines an automated response, that response is triggered. A scenario passes at a control effectiveness rate of 100%, since a partial defence against a deterministic attack is a defect, not a degree.
>
> **2. Residual findings register.** Every observation that is not fully contained, together with any weakness surfaced by automated scanning, will be recorded with its CVSS-style severity (Critical / High / Medium / Low / Informational), its affected component, and its disposition — remediated, mitigated, or accepted with justification. The system meets its security objective when no Critical or High finding remains open at the close of testing. Medium and Low findings that remain open will be reported with the reason for acceptance, rather than omitted.
>
> **3. Detection and alerting performance.** For each scenario that should raise an alert, the study will record the detection-to-notification latency and whether the corresponding record appears in the audit trail. Alert precision — genuine intrusion attempts as a proportion of all alerts raised, measured across the full test run including benign administrative activity — will also be reported, so that alerting sensitivity is not overstated by counting false positives as detections.
>
> These three measures together answer SOP 3 and SOP 5: the first establishes whether the controls hold, the second establishes what risk survives them, and the third establishes whether the system's monitoring makes an intrusion visible to an administrator within a usable interval.

**And in Table 2, replace the *Baseline Condition* column heading and contents:**

| Old column | Old value | New column | New value |
|---|---|---|---|
| Baseline Condition | "System vulnerable to SQL injection" | Attack Scenario Definition | "10 injection attempts across login and leave-form inputs using tautology, UNION, and time-delay payloads" |
| Baseline Condition | "Unauthorized access successful during unsecured testing" | Attack Scenario Definition | "Direct URL access to 5 role-restricted endpoints by an authenticated employee-role account, and by an unauthenticated session" |
| Baseline Condition | "No login blocking mechanism implemented" | Attack Scenario Definition | "20 consecutive failed authentications against one account from a single source address, within a 60-second window" |
| Baseline Condition | "No intrusion alert mechanism available" | Attack Scenario Definition | "Each of the above scenarios executed once with alert latency and audit-record presence measured" |

**What changed, and why:**

1. **[ARGUMENT CHANGE — stated openly]** The before/after framing is replaced with containment-plus-residual-risk. The rewrite says so *in the text itself* ("no unsecured version of the system exists"). Volunteering this is far stronger than having a panel discover it: it converts your biggest methodological liability into evidence of methodological awareness. Your claim that the system is secure is unchanged.
2. **A three-part definition of "contained"** (action fails **and** event logged **and** response triggered). Without this, "blocked" is a judgement call, and a panel will ask who judged it.
3. **100% as the pass threshold** for deterministic attacks, with the reasoning given. Table 5 already implies this ("All 10 attempts are blocked"); §3.6 currently contradicts it with the softer "reduced or significantly reduced."
4. **Residual findings are reported, not suppressed** — including accepted Medium/Low items. A report claiming zero findings of any severity reads as an incomplete test; a report with a documented, justified residual register reads as a competent one.
5. **Alert precision measured "including benign administrative activity"** — otherwise precision is trivially 1.0 because you only measured during attacks, which is the standard flaw in student IDS evaluations.

---

### R3 — §2.2, *Role-Based Access Control in Information Systems*

**Why this passage:** four consecutive paragraphs, zero citations, supporting one of the two security mechanisms named in your objectives. It is the most exposed passage in the manuscript — a panel can dismiss it in one sentence.

**Current (paragraphs 3 and 4 of the subsection):**

> Research indicates that RBAC enhances system security by preventing unauthorized access to sensitive data. It also simplifies the management of user permissions by grouping access privileges according to organizational roles. This model is widely used in enterprise systems, government platforms, and human resource management applications where different users require varying levels of access to system resources.
>
> Integrating RBAC with authentication mechanisms and encryption protocols further strengthens the security of information systems. These security measures ensure that digital platforms maintain the confidentiality, integrity, and reliability of organizational data.

**Proposed:**

> Role-Based Access Control was formalised by Sandhu et al. (1996) and standardised as ANSI INCITS 359-2004, which defines core RBAC, hierarchical RBAC with role inheritance, and constrained RBAC with separation-of-duty constraints. Its central principle is that permissions are assigned to roles rather than to individual accounts, and users acquire permissions only through role membership — an arrangement that supports least privilege because a user's authority is bounded by the role they hold rather than accumulating over their tenure (Sandhu et al., 1996).
>
> For organisations of moderate size, the practical case for RBAC is administrative rather than cryptographic: it reduces the number of independent authorisation decisions from one per user to one per role, so that a change in organisational policy is applied once and takes effect for every holder of the role. This property is directly relevant to the LGU of Alicia, where seventeen offices share a small number of functional positions in the leave workflow — filer, department head, HR certifier, and final approver — and where authorisation must follow the position rather than the person, since personnel rotate between offices while the approval structure does not.
>
> The literature is not uniformly favourable, and two limitations bear on this study. First, RBAC is coarse-grained with respect to data: a role determines which *functions* a user may invoke, but not always which *records* they may act upon (Ferraiolo et al., 2001). A department head who legitimately holds approval authority may, without an additional ownership or scope check, be able to view leave records outside their own department. Second, RBAC is vulnerable to role explosion, in which fine-grained access requirements are met by proliferating narrow roles until the model becomes as difficult to administer as the per-user assignment it replaced.
>
> The present study responds to both. Hierarchical RBAC with role inheritance is adopted to limit the number of distinct roles, and per-user allow/deny overrides are provided so that exceptional cases are handled without defining a new role. Function-level role checks are combined with record-level ownership and department-scope checks at the point of access, so that horizontal privilege escalation between peers of equal role is denied rather than relying on role separation alone. This design decision, and its consequences for the system's access control architecture, are elaborated in Section 4.3.

*(Sandhu et al. 1996, ANSI INCITS 359-2004, and Ferraiolo et al. 2001 are the standard citations for these claims; verify each against the published source before inserting, and add all three to your reference list.)*

**What changed, and why:**

| Change | Reason |
|---|---|
| Replaced "Research indicates that..." with named, foundational sources | An unattributed appeal to unnamed research is the weakest possible support; these are the canonical RBAC references and are straightforward to obtain |
| Added the ANSI standard and named the three RBAC variants | Shows you know which variant you implemented — hierarchical with overrides — rather than treating "RBAC" as a single undifferentiated thing |
| Added a paragraph applying RBAC to *your* seventeen-office context | The original said RBAC is "widely used in enterprise systems, government platforms" — true, generic, and does nothing for your argument. This paragraph does |
| **Added a paragraph of limitations** (coarse granularity, role explosion) | This is the change that most affects your critical-analysis mark. Chapter II currently reports every source approvingly and criticises none; a panel reads that as summarising rather than reviewing |
| Added a paragraph stating how the study answers those limitations | Turns the review into an argument for your design choices, and gives Chapter IV something to be the payoff of |
| Forward reference to §4.3 | Removes the need to re-explain RBAC in Chapter IV — recovering some of the space this expansion costs |

**Note on argument:** this rewrite does not change your position. You still argue that RBAC is the right access-control model for this system. It adds the counter-case and answers it, which is what an examiner means by critical analysis. The added claim — that record-level scope checks are combined with role checks — must be true of your implementation; verify before submitting.

---

## 5. What I did not change, and why

Five things a harsher reviewer might have rewritten, which I recommend you keep:

1. **The LAN-confinement argument.** It is well motivated, consistently held, and defensible on both security and infrastructure grounds. It is repeated too often (W5), but the argument itself is sound.
2. **The deductible/non-deductible distinction as your central gap claim.** It is genuinely your differentiator against every study in Table 1. It needs the CSC Omnibus Rules citation and it undersells what you built, but the claim is right.
3. **The four-point Likert scale.** Forced-choice is a legitimate design decision. It needs a supporting citation, not replacement.
4. **The three-way structure of Chapter IV** (network → application → security). It reads clearly and maps well onto the diagrams.
5. **The overall scope.** Excluding payroll, biometrics, and external integrations is correct for a capstone and is defended properly in §1.3. Do not widen it.

## 6. Suggested order of work

1. Delete the two copy-paste duplications (§1.2, Table 1 note) — ten minutes, and they are the errors most likely to colour a first read.
2. Rewrite the SOPs (**R1**), then propagate to Objectives, §3.4, §3.5, §3.6 so the alignment table in §0 closes.
3. Rewrite §3.6 §3 and Table 2 (**R2**) — this is the fix that survives the hardest question you will be asked.
4. Add the CSC Omnibus Rules and ISO/IEC 25010 to the reference list and cite them where they belong; fix the reference defects in W4.
5. Merge 2.3 into 2.4; compress the Moreno & Zabala block; add the RBAC and IDS citations (**R3**).
6. Correct the Chapter IV drift: name Laravel, correct the approval workflow to include the Mayor, and fix Figure 9's encryption and firewall claims.
7. Add the conceptual framework and definition of terms (§1.5, §1.6).
