# 18 — SOW status review (MICA 3.0)

**Purpose:** grade the work on `mica-phase-3` against the **customer-approved SOW**, not
against this repo's internal phase-3 plan. The two are not the same scope, and grading
against the plan hides which SOW obligations are actually met.
**Reviewed:** 2026-08-21 · **Branch:** `mica-phase-3` @ `23b6af3` · **Target project:** PID 257 `MICA_R01`
**Method:** code read at `file:line`, live MySQL queries against the running instance, and
`tools/vendor/bin/phpunit` executed (971 tests / 2776 assertions, green).

> **Scope note on the yardstick.** The handoff plan (`README.md`, `03-implementation-stages.md`)
> adds a large body of work the SOW never asked for — the counselor-v2 turn contract
> (hash-pinned prompt files, `wrapper_schema_v2`, app-side gates, MI phase matrix). That work
> is genuinely valuable and mostly unstarted, but it is **not** an SOW obligation. It appears
> below only where it bears on an SOW clause.

---

## 1. Scorecard

| # | SOW clause | Status |
|---|---|---|
| **D‑1** | Discovery — identify a 2FA replacement | ✅ **Done** — PI memo goes out 2026-08-21 (§10 A1) |
| **D‑2** | Discovery — incorporate transcribe/analysis code | ✅ **Done** |
| **V‑1** | Development — authentication change | 🟡 **Partial** — REDCap layer shipped; module still carries the old door |
| **V‑2** | Development — chatbot cleanup & SecureChatAI integration | 🟡 **Partial** — compatibility audited and the breaking defect fixed; cleanup + conformance not done |
| **V‑3** | Development — automated post-session analysis | ✅ **Done but for one live scan** — the storage clause is **met** as the study defines it (§10 A3) |
| **V‑4** | Development — REDCap project adjustment (three arms) | 🟡 **Partial** — arms/events/instruments/assignment done; Twilio/notifications/alerts **blocked on PI requirements** (§10 A5); cross-arm assurance not established |

> Statuses above incorporate the study answers of 2026-08-21 recorded in **§10**. Read §10
> before acting on §2–§7: it closes two questions, converts one gap from ours to blocked, and
> **removes a scope block whose loss leaves three enforcement gates unowned (§10 A6)**.

---

## 2. D‑1 — Identify a 2FA replacement ✅

**Delivered.** [`08-auth-discovery.md`](08-auth-discovery.md) (872 lines) answers every
sub-clause, with the SOW-clause→section map in its own §17 table:

| SOW sub-clause | Where | Evidence quality |
|---|---|---|
| Research & evaluate replacements without sacrificing security | §1, §3, §4, §5 | 7 options, 5 scored on 10 requirements; REDCap 17.2.3 core behaviour **read in source and executed** against the live instance (§11 spike, 6 scenarios) |
| Reliable record mapping (one participant → one record) | §2 R2, §4, §6 | Current mapping shown to be a name+email string match (`MICA.php:1298`); Option B makes it deterministic by construction (link → participant row → record) |
| Participant experience | §6.3 | Stated with the *costs* named, not just the wins — lockout has no admin unlock (sliding window), and "De La Cruz" vs "DeLaCruz" fails |
| Compatibility with existing session-window routing | §9 | Improves it: per-event host surveys carry the window instead of inferring it; also found that PID 257 has **no `consent_date`** (verified: 0 rows), so the pilot session engine cannot run there at all |
| Notify the PI | §12 | A PI-facing memo is **written**. Whether it was **sent** cannot be determined from the repo — see §7 Q1 |

Beyond the SOW ask, the discovery found and documented **11 findings on the current OTP**,
four of them High — verification unbound to the requesting identity, codes that never expire,
codes never invalidated on use, no attempt limit (`08 §1.2`). That materially changes the
SOW's "otherwise authentication remains as-is" fallback: as-is is not a safe resting state.
That conclusion is well-supported.

Supporting work that raises confidence: [`09`](09-pid-257-structure-audit.md) audited the
as-built PID 257 field-by-field and forced a revision of the credential choice;
[`10`](10-auth-implementation-pid257.md) records what was applied *and* a blank-credential
bypass found during implementation that **reversed** an earlier prescription in §6.1c;
[`11`](11-auth-manual-test-guide.md) is an 11-case hand-test matrix; [`12`](12-auth-engineering-review.md)
is a peer-review packet. Self-correction under test is visible in the record, which is the
right signal.

---

## 3. D‑2 — Incorporate transcribe/analysis code ✅

| SOW sub-clause | Answer as built | Evidence |
|---|---|---|
| Integration point and trigger | `completeSession` → `TranscriptFinalizer` → idempotent job enqueue → cron worker → `ScanRunner` | `classes/TranscriptFinalizer.php:28`, `classes/ScanWorker.php`, `classes/ScanQueue.php` |
| Technical fit, dependencies, constraints | [`13-securechatai-current-state-delta.md`](13-securechatai-current-state-delta.md) — the provider contract verified against provider code, item by item; two upstream PRs scoped | §1, §6, §7 |
| Criteria for "needs PI/CRC attention" | `FindingThresholds` (post-scan filter deciding what reaches the queue) + `NotificationPolicy` (who is told, how fast) | `classes/FindingThresholds.php`, `classes/NotificationPolicy.php`, `tests/Unit/FindingThresholdsTest.php` |
| How flagged output is surfaced | All three of the SOW's options: repeating `mica_safety_finding` **flag fields**, an RA **dashboard** (`pages/review.php` + `mica-review/` SPA), and **email notices** | `classes/FindingWriter.php`, `classes/NotificationService.php` |

The design decision worth surfacing to the PI: **no live safety routing**. Detection is
entirely post-session by design (handoff `README.md`), and the code "flags for human review
only", which matches the SOW. It also means nothing intervenes *during* a session — an IRB
and clinical-review matter, already listed as a non-goal/launch gate.

---

## 4. V‑1 — Authentication change 🟡

### What shipped

Option B's REDCap layer, applied to PID 257 and verified end-to-end
([`10`](10-auth-implementation-pid257.md)):

- Two host instruments given a mount field and enabled as surveys — `mica_ed_session`
  (survey_id 1315) and `mica_booster_session` (1316). **Verified live: both `survey_enabled = 1`.**
- Native **Survey Login** scoped to those two surveys only; exactly **one** credential slot
  (`last_name` @ event 1004), `min_fields = 1` — because implementation testing proved a
  second slot is a **bypass** (REDCap matches a blank submission against a blank stored value).
- Link time limits set (24 h / 14 d), with the `link_expiration`-at-issuance requirement
  documented (`08 §3.3`).
- Server-side participant identity on the no-auth AJAX surface, and no user input in
  `filterLogic` (defects D2/D3 — the SOW-relevant half of "match or exceed" security).
- Deterministic record mapping: link → participant row → record. **SOW clause met by construction.**

### What has not shipped

1. **The old OTP door is still in the module.** `loginUser()` (`MICA.php:1298`),
   `generateOneTimePassword()` (`:1359`), `verifyEmail()` (`:1439`), `pages/chatbot.php`,
   the `no-auth-pages` entry and the `login` / `verifyEmail` `no-auth-ajax-actions`
   (`config.json:44,47`) all remain.
   **Severity, resolved by measurement.** Three facts, in the order that decides it:
   the **system-level** enable row for `proj_mica` is `false`; the **only** project-level
   enable is PID 257 (`redcap_external_module_settings`); and PID 257 has **0**
   `two_factor_code*` fields and **0** `participant_name`/`participant_email` fields. A
   no-auth page takes its project from the URL `pid`, so reachability turns on the second
   fact — and on the one project that can reach the page, `loginUser()` cannot resolve a
   record. Verdict on this branch: **dead code to delete, not a live bypass.**
   ⚠️ **But the pilot is a different story.** `pilot-final` (`main` @ `5e56073`) carries this
   same OTP flow, and the pilot project *does* have `participant_name` / `participant_email` /
   `two_factor_code` — so findings #1–#4 of `08 §1.2` (verification unbound to the requesting
   identity, codes that never expire, codes never invalidated, no attempt limit) are **live in
   the running pilot**, not merely dormant here. Out of scope for this review and for this
   branch; raised because "delete it on phase 3" does not address it. `08 §7` Track A (A1+A2+A3)
   is the pilot-side answer if the pilot runs long enough to warrant one.
2. **Survey Login session binding (`08 §8.2` step 2) is unbuilt.** Nothing in the tree
   recomputes `hash($password_algo, "$project_id|$record|$salt|$salt2")` against
   `$_COOKIE['survey_login_pid…']` — grep for `survey_login_pid` returns nothing outside
   REDCap core. So the AJAX surface today proves *"a survey context exists for this record"*,
   not *"this participant authenticated for this record"*. This is the one item that native
   Survey Login cannot do for us, and it is the difference between "front door replaced" and
   "front door replaced and the rooms behind it locked".
3. **Link issuance is not built.** The only code that mints a MICA host link is the
   manual-test helper `docs/phase-3-handoff/scripts/manual-test-auth.php:86-87`; nothing in
   `MICA.php`, `classes/` or `pages/` issues one. `10 §4.1`: `getSurveyLink()` returns **null**
   until the record exists in its randomized arm, and a null must surface as an explicit
   *"not randomized yet"* state rather than an empty link being texted.
4. **`link_expiration` is not written at issuance** — without it the configured time limits
   never fire (`08 §3.3`, measured). The limits themselves *are* set (verified live: ED 24 h in
   `survey_time_limit_hours`, booster 14 d in `survey_time_limit_days`), so this one line of
   write is all that stands between "configured" and "enforced".
5. **No rate limiting on `callAI`** (`08 §8.2` item 4). `grep` for rate/throttle across
   `MICA.php` and `classes/` returns nothing. Cost and abuse control on the participant-facing
   model call, per record per window.

**Bottom line for V‑1:** the security-critical *configuration* is in place and verified; the
*module-side* half — deletion of the old flow, session binding, and link issuance — is
outstanding. As it stands, PID 257's chat is gated by Survey Login, so this is not an open
hole on the target project; it is unfinished work.

---

## 5. V‑2 — Chatbot cleanup & SecureChatAI integration 🟡

Read strictly against the SOW's four sub-clauses:

| Sub-clause | Status | Evidence |
|---|---|---|
| Clean up the old chatbot; remove deprecated logic and unused components | ❌ **Not done** — deliberately deferred (stage 0.6). `config.json` still carries the three `twilio-*` system settings, `chatbot_system_context_session_2..7`, `session_length_days`, `number_session_callback`; pilot session paths remain | `06-implementation-plan/README.md:112` records the deferral and why |
| Integrate with the new SecureChatAI framework and structure | 🟡 **Audited, one breaking defect fixed, conformance not done.** [`13 §7`](13-securechatai-current-state-delta.md) grades MICA-as-is item by item: **1 BROKEN, 5 DEGRADED, 2 OK**. The BROKEN item (`llm-model = gpt-4o` in no registry ⇒ *every turn silently answered with a provider apology*) is fixed — `assertModelIsRegistered()` before every call (`MICA.php:384-453`). The five DEGRADED items are still degraded | `13 §7`; `MICA.php:384` |
| Update API calls, request/response handling, and system prompt handling to conform | ❌ **Not done.** Still the 3-arg `callAI()` with no `session_id` and no `$username` — so **zero** provider turn rows are logged (empirically confirmed, `13 §7` item 3). Provider errors still arrive shape-identical to answers and are **persisted as counselor turns** (item 8b). System prompts still come from `chatbot_system_context_*` project settings (`MICA.php:1631-1644`), not the pinned-artifact contract | `13 §7` "Must change for correctness" items 2; "leaves value on the table" 4–6 |
| Confirm existing MICA functionality is preserved | ✅ **The baseline now exists — this unblocks the cleanup.** Real fixes landed and are documented: the persona was configured, read and never sent (`2648d07`); a finished session ended at a login it could not pass (`f43695a`); restore rebuilds model context; the send button no longer hangs on empty context ([`16-chat-state-hardening.md`](16-chat-state-hardening.md)). And `e2e/full-path.js` is now a **43-check** end-to-end baseline over the auth gate (A1–A3), UI render (B1–B15), two-turn conversation + persona identity (C1–C10), session end (E1–E8) and mobile (F1–F6) | `16`; `e2e/full-path.js` |

**This is the weakest SOW clause of the six.** The valuable half is done — the compatibility
question is answered with verified evidence rather than assumption, and the one defect that
broke every turn is fixed. The clause's own verbs ("clean up", "update API calls",
"conform") are not.

**The stated blocker on the cleanup is gone.** `06-implementation-plan/README.md:112` deferred
0.6 because it "needs a before/after Playwright baseline to be worth anything". That baseline
now exists (43 checks, above), so the deferral reason no longer holds. One caveat that is *not*
resolved: `twilio-sid` / `twilio-auth-token` / `twilio-from-number` hold **live credentials**
for a `sendSMS()` that is commented out (recorded in `0ba5eee`, deliberately left alone). That
is a **rotate-then-delete**, not a delete — and it needs doing regardless of the cleanup,
because a live auth token is sitting in the database for code that cannot run it.

One caution: the plan's counselor-v2 contract would satisfy this clause many times over, but
it is a much larger scope than the SOW bought. The conformance items in `13 §7` are the
SOW-sized version and should be sequenced first.

---

## 6. V‑3 — Automated post-session analysis ✅ (two gaps)

| Sub-clause | Status | Evidence |
|---|---|---|
| Analyze the session once marked complete, using the full transcript | ✅ | `completeSession` → `TranscriptFinalizer` (canonical payload, SHA-256, stable message IDs, idempotent enqueue) → cron `ScanWorker` (atomic claim, bounded retries) → `ScanRunner`. Duplicate `completeSession` calls create exactly one job |
| Determine whether anything requires PI/CRC attention | ✅ | `ScanRunner` + `QuoteVerifier` (rejects near-miss quotes and wrong-message citations) + `FindingThresholds`. **Every** failure path lands in `manual_review_required` — never a negative screen |
| Flagged sessions surfaced via alert, flag field, or report | ✅ all three | Repeating `mica_safety_finding` instrument — **verified live on PID 257: 28 fields, repeating in 4 events**; RA dashboard (`pages/review.php`, `mica-review/` SPA) with role enforcement via REDCap user roles (`43d33c5`), audit-on-read, optimistic locking; `NotificationService` with a policy gate, digests and an ack-overdue monitor |
| Flags for human review only | ✅ | Disposition requires a rationale; corrections stored separately; no automated clinical action anywhere in the tree |
| Transcript, LLM response and metadata stored within REDCap | 🟡 **See gap 2** | Transcripts in the EM log store; scan runs/jobs/turns/audit in `redcap_entity_*` tables — **verified live: all 5 tables exist**; findings in REDCap fields |

**Gap 1 — the live model call is unproven.** `ScanRunner` runs green in mock mode and via a
fixture caller, but the real Gemini structured-output call needs SecureChatAI PR #2
(`response_mime_type` + `response_schema`); until then SecureChatAI **drops `json_schema`**
for any model outside its OpenAI allowlist (`classes/SecureChatSafetyScanCaller.php:147`).
The module already detects and records this (`schemaWasSent: false`) rather than pretending.
Blocked on another repo — but the SOW clause is not demonstrably met until one live scan runs.

**Gap 2 — "stored within REDCap" is true in the database sense, not the field sense.** The
storage split was a decision made with you on 2026-08-11 (`02-data-model.md §1`), so this is
by design. But two things follow: PID 257 has **0 fields matching `%transcript%`** (verified),
so Stage 3's write-back of `mica_transcript_hash`/`_ref` currently writes a **named warning
instead of the pointer** (audit G4, `06-implementation-plan/README.md:117`) — there is no
REDCap-field breadcrumb from a record to its transcript. And if the study team reads
"stored within REDCap" as "visible in exports/reports", EM-log and entity storage will not
meet that expectation. See §7 Q3.

---

## 7. V‑4 — REDCap project adjustment (three arms) 🟡

| Sub-clause | Status | Evidence |
|---|---|---|
| Review and adjust the project, building on template PID 35756 | 🟡 | The rebuild targeted **PID 257** against the study's schedule-of-assessments screenshot (`docs/project-structure/`). Whether PID 257 *is* "Brian's new project built from 35756" is not recorded anywhere — see §7 Q4 |
| Restructure into three arms (SMS-only / MICA-only / MICA+SMS) | ✅ | 3 arms, 13 events, 168 designations, 27 instruments; independently verified by SQL diff against a declared target (`verify-sql.js`, all 5 checks pass), plus per-arm record-home smoke shots. Note the arm naming differs from the SOW: **Arm 1 = Standard Care (SC)**, Arm 2 = MICA, Arm 3 = MICA + Weekly SMS |
| Configure arms, events, instruments, **and arm assignment logic** | ✅ | Arm assignment is automatic: `study_group` (verified present on PID 257) → `MICA::ensureRecordInAssignedArm()` on save, placing the record in **only** the assigned arm — deliberately, because materializing all arms would hand a Standard Care participant a working `mica_ed_session` link ([`15`](15-arm-materialization.md)). Documented limit: `redcap_save_record` fires on UI saves only |
| **Configure Twilio, notifications, and alerts per team requirements** | ❌ **Not done.** Live query on PID 257: `twilio_enabled = 0`, account SID empty, **0 ASIs, 0 alerts** | audit G7 predicted this; it is still open |
| **Ensure post-session analysis functions correctly across each arm** | ❌ **Not established** | See below |

**The cross-arm clause needs a stated answer.** `grep -rn "study_group|arm_1|SMS" classes/`
returns **nothing** — the scan pipeline has no arm awareness at all. The de-facto behaviour,
derived from the design rather than from any test:

- **Arm 1 (Standard Care)** — the two host instruments are designated to arms 2 & 3 only, so
  an arm-1 participant has no chat survey, no `completeSession`, and therefore no scan job.
  Correct by construction, and a stronger guarantee than module logic would be (`08 §9`).
- **Arms 2 and 3** — identical chat path; arm 3 differs only in the weekly-SMS events, which
  produce no chatbot transcript. No scan job arises from them.
- **A session with no messages** fail-closes: `TranscriptBuilder` throws rather than enqueue
  (`classes/TranscriptBuilder.php:77` — *"A session with no messages is not a transcript, and
  enqueueing a scan of one is a bug"*), covered by `TranscriptBuilderTest.php:187`.

So the answer is probably "yes, by construction" — but the SOW named this as an acceptance
condition, and it is currently neither documented as such nor demonstrated per arm. The
honest status is **not established**. Closing it is cheap: one E2E pass creating a record in
each arm and asserting the queue contents (0 / 1 / 1).

Also carried forward from the audit, both researcher-side but launch-blocking: `[calcrnd]`
does not exist, so the study's own `check_code` mechanism cannot work; and the participant-facing
text in `sms_code_check` / `sms_opt_out` still names the **ASPIRE** and **TRAM** studies.

---

## 8. Engineering quality

**Good, and verified rather than asserted:**

- **971 tests / 2776 assertions green** (executed 2026-08-21), PSR-12 gate in `composer test`.
  Calibration: this covers the post-session pipeline, review dashboard and notifications
  (V‑3). It says nothing about V‑1 or V‑2.
- Fail-closed is applied consistently: artifact hash mismatch, schema failure, `saveData`
  error, oversized payload, content filter — all become `manual_review_required` or the
  approved technical fallback. Never a silent trim, never released model text on failure.
- The bug-fix discipline the project asked for is visible in the log: defects were found by
  **reading a delivered email**, **reading screenshots**, and running the pipeline end to end
  — not by staring at passing tests (`0fb3f78`, `94e087d`: *"four rendering defects found by
  reading a delivered email"*, *"four things the screenshots showed that 40 passing checks did not"*).
- No module-owned DDL; entity tables come from the REDCap Entity EM.
- Reversals are recorded in place rather than quietly patched — `08 §6.1c` carries a
  **CORRECTED** banner stating its original prescription was unsafe. That is the behaviour you
  want in a handoff document.

**Weak spots:**

1. **The stage tracker understates what shipped.** `06-implementation-plan/README.md` still
   says Stages 5 and 6 are *"not started"*; both landed 2026-08-20/21 (`c02b667`, `43d33c5`,
   `561ceef`, `ae4a4ca`, `34cf64d`). Its "still open" list is also stale — `handoff/`,
   `tests/`, committed `vendor/` and the `pilot-final` tag all now exist. The repo's own
   convention says *"the plan is the source of truth for reviewers"* (`:130`), so a
   reviewer reading the tracker today gets a materially wrong picture. Fix the tracker.
2. **E2E coverage is two scripts** (`e2e/full-path.js`, `e2e/review-dashboard.js`), both
   bespoke Node scripts rather than a Playwright suite with a runner — no test-level
   isolation, no retries, no report. Given the project standard (reproduce E2E first), the
   participant path deserves a real suite before V‑2's cleanup starts touching those files.
3. **Mobile is covered — correcting an assumption I started with.** Both scripts take a
   `mobile` mode backed by Playwright's `devices['iPhone 13']`
   (`e2e/full-path.js:26`, `e2e/review-dashboard.js:38`), with 15 screenshot artifacts in
   `e2e/shots/` including `F-mobile.png` and mobile shots of the queue, session and gate
   views. Better than that, the chat path makes real mobile *assertions* rather than just
   taking pictures — `F3 composer within viewport` and a banner-height-share check
   (`:312`, `:323`), the latter written against a defect where the launch banner clipped the
   top of the conversation on an iPhone viewport. That is the standard being met, not claimed.

---

## 9. Questions for you

1. **Was the PI actually notified?** D‑1's last sub-clause is "notify the PI of our findings
   and results". `08 §12` is a memo *written to be pasted into an email*, and `12` says to
   circulate to engineers *before* the PI memo. If it has not gone out, D‑1 is not closed —
   and since the entire V‑1 Development branch is "contingent on Discovery findings and PI
   approval", nothing downstream of it is formally unblocked either.
2. **Has the credential tier been signed off?** The build works for L1/L2/L3 identically, but
   production needs Stanford security/privacy + IRB to pick one (`08 §6.2`, recommendation L2
   = `last_name`). Is that decision in flight?
3. **What does the study team mean by "stored within REDCap"?** If they expect the transcript
   and LLM response to be **exportable REDCap field data**, the current EM-log + entity split
   does not meet it and the G4 pointer fields become mandatory rather than nice-to-have. If
   they mean "inside REDCap's database, retrievable by the RA dashboard", it is already met.
4. **Is PID 257 the project the SOW means?** The SOW says "Brian's new project, building upon
   the template in project ID 35756". All work here targets **PID 257 `MICA_R01`** (status 0 =
   Development) and no doc records the relationship. **PID 35756 does not exist on this
   instance** (live query returns only 257), so it is a production PID that was never
   available locally — meaning the "building upon the template" step cannot have been done
   here and must have been done, or still needs doing, on the production server. If Brian's
   project is a different PID, the arms/events/designations,
   the Survey Login configuration, the two host surveys, the `study_group` field and the
   `mica_safety_finding` instrument all have to be re-applied there — the scripts in
   `docs/project-structure/scripts/` and `docs/phase-3-handoff/scripts/` are idempotent and
   built for exactly that, but it is a real work item that is currently invisible.
5. **Who owns Twilio/notifications/alerts configuration, and against what requirements?**
   Nothing is configured on PID 257 and no requirements document exists in the repo. This is
   an explicit SOW deliverable and it currently has no owner or spec.
6. **What is the priority order for the remaining work?** Ordered by **SOW exposure**, since
   that is the yardstick:
   1. **Cross-arm scan E2E** (V‑4) — closes a *named* SOW acceptance condition for the cost of
      one test. Highest ratio of clause-closed to effort in the whole list.
   2. **Twilio / notifications / alerts on PID 257** (V‑4) — an explicit SOW deliverable with
      nothing built and no requirements document. Needs your input before it can start (Q5).
   3. **The `13 §7` conformance items** (V‑2) — pass `session_id` + `$username`, and stop
      persisting provider errors as counselor turns. This is the SOW-sized reading of "update
      API calls, request/response handling" and it is a handful of changes, not a rewrite.
   4. **One live SafetyScan** (V‑3) — blocked on SecureChatAI PR #2, but the clause is not
      demonstrably met until it runs once.
   5. **Delete the dead OTP surface + the rest of the 0.6 cleanup** (V‑1/V‑2) — hygiene on this
      branch rather than SOW exposure, since it is dead on PID 257. Best done in the *same
      pass* as the V‑2 cleanup, which touches the same files and needs the same E2E baseline.
   6. **Survey Login session binding + `link_expiration` at issuance + the "not randomized
      yet" state** (V‑1) — the module-side half of the auth change.
   7. **Stage tracker refresh** — 20 minutes, and it stops the next reviewer starting from a
      wrong picture.

   The counselor-v2 turn contract and the R01 session engine are the **plan's** remaining
   scope, not the SOW's. They are the largest block of unbuilt work in the folder and worth an
   explicit decision: in this engagement, a follow-on, or dropped.

---

## 10. Answers received — 2026-08-21 (Ihab)

The six questions in §9, answered, with what each one changes.

### A1 — PI notification: going out today

D‑1's last sub-clause closes on send. Nothing downstream needs to wait on it *technically*,
but the SOW makes the whole V‑1 branch "contingent on Discovery findings and PI approval", so
**V‑1's remaining work should not be treated as approved until the PI responds** — in
particular the deletion of the OTP flow, which is the irreversible half.

### A2 — PID 257 **is** the project the SOW means

Q4 closed. The arms/events/designations, Survey Login configuration, two host surveys,
`study_group` field and `mica_safety_finding` instrument are all applied to the right project;
no re-application elsewhere is owed. One residue: **PID 35756 does not exist on this
instance**, so "building upon the template in project ID 35756" cannot be evidenced locally.
If the study needs that lineage documented, it has to come from the production server. Treated
here as satisfied by the study's own confirmation.

### A3 — "Stored within REDCap" means **the LLM findings are saved in a REDCap instrument** ✅ met

This closes V‑3's storage clause. Verified live on PID 257 — `mica_safety_finding`, repeating,
**28 fields**, and the model's output is all of it, not a summary of it:

| What the model produced | Field |
|---|---|
| Concern type / urgency | `finding_concern_type`, `finding_urgency` |
| Its narrative finding | `finding_summary` |
| **Its exact supporting quotes** | `finding_evidence_json` (quote-verified before write — `classes/QuoteVerifier.php`) |
| Recommended actions / targets | `finding_rec_actions_json`, `finding_rec_targets_json` |
| Confidence | `finding_confidence` |
| Identity + provenance | `finding_id` (UUID), `finding_scan_run`, `finding_index`, `finding_source_role` |
| Human review lifecycle | `review_status`, `review_reviewer`, `review_corrected_*`, `review_rationale`, `review_lock_version`, … |
| Action lifecycle | `action_types`, `action_delivery_status`, `action_ack_by/at`, … |

**Consequence for the G4 gap:** the missing `mica_transcript_hash`/`_ref` fields drop from
*mandatory* to *nice-to-have*. §6 gap 2 is downgraded accordingly — the findings are in REDCap
fields, exportable and reportable; only the full transcript body stays in the EM log store,
which is by the 2026-08-11 design decision. **V‑3 now has exactly one open item: one live scan
through the real model** (SecureChatAI PR #2).

### A4 — Credential tier not yet signed off

Unchanged: build proceeds on **L2** (`last_name`, `min_fields = 1`), which is what is applied.
L1/L2/L3 are the same build, so this gates production enablement, not development. Worth
noting to the PI in the same memo as A1, since it is a policy answer and the memo is the
natural vehicle.

### A5 — Twilio / notifications / alerts: the **PI** owns the requirements

Reclassified from *"our gap"* to **blocked on external input**. Measured state is unchanged and
still zero: `twilio_enabled = 0`, account SID empty, **0 ASIs, 0 alerts** on PID 257. What this
means practically:

- The **configuration** is still our deliverable; only the **requirements** are the PI's.
- It is on the critical path for more than itself: A5 is how the participant *receives* the
  session link (`08 §6.1` — SMS invitation in the ED, ASI for the Month-3 booster). Until
  Twilio is configured and an ASI exists, **there is no delivery path for the authenticated
  link the whole V‑1 design depends on**. The auth mechanism is built and verified; the way a
  participant gets to it is not.
- Recommend asking the PI for three specific things rather than "requirements": (1) SMS sender
  identity + message wording for the ED invitation, (2) when the Month-3 booster invitation
  fires and how often it retries, (3) who receives safety-finding notices and the
  `critical_acknowledgment_minutes` target (the Stage-6 launch gate refuses production while
  that is null).

### A6 — Counselor-v2 and the R01 session engine: **out of scope** ⚠️ read this one

Accepted, and it is defensible: `SessionHostMap` already derives `session_type` and `setting`
from the hosting instrument (`classes/SessionHostMap.php`), which is what SafetyScan actually
needs, so the window arithmetic the plan wanted was never load-bearing for the SOW. Dropping
the turn contract, MI phase matrix, `minutes_remaining` and the R01 window engine costs the SOW
nothing.

**But three enforcement gates were riding along inside that scope and are now unowned — and one
of them is not the same kind of thing as the other two.** Gate 2 below is a human-subjects
obligation: a withdrawn participant reaching an intervention chat is a protocol deviation the PI
has to report. Gates 1 and 3 are cost and hygiene. They should be decided separately, not
dismissed as a bundle along with the engine work. On
PID 257 the module takes the non-pilot branch — `hasPilotSessionScaffolding()` returns false
(no `baseline_arm_1` event, no `raw_chat_logs`/`session_timestamp` fields), so
`getSystemContextForRecord()` delegates to `systemContextWithoutPilotScaffolding()`
(`MICA.php:1601`), which builds the persona and **performs no gating whatsoever**. Measured
consequences:

| # | Gate | State on PID 257 | Why it mattered |
|---|---|---|---|
| 1 | **Session window** | **Absent.** The pilot's `"Session already completed. Return in N day(s)"` throw lives in the pilot branch only. Nothing refuses a session outside its window | `08 §9` cited exactly this engine refusal as an *additional bound on a leaked link* ("even a leaked link is only useful inside an open session window"). On PID 257 that bound does not exist |
| 2 | **Withdrawal / opt-out** | **Absent.** `study_withdrawn` and `sms_stop` appear **nowhere** in the module (grep across `MICA.php`, `classes/`, `pages/`) | `08 §6.1b` names both as auth-boundary requirements: a withdrawn participant must not authenticate, and an opted-out participant must not be texted. The pilot had an analogous completed-participant check; the R01 path has none |
| 3 | **Re-entry / repeat sessions** | **Unbounded.** Repeat Survey is enabled on both hosts (verified live: `repeat_survey_enabled = 1` on `mica_ed_session` and `mica_booster_session`) and nothing gates a second run | Each re-entry finalizes another transcript and queues another scan — extra model spend and extra RA queue items, from a participant simply re-opening their link |

**What still protects the chat:** Survey Login on both hosts, link expiry (verified: ED **24 h**
in `survey_time_limit_hours`, booster **14 d** in `survey_time_limit_days` — `10 §1.2` is
accurate), and the arm designation that keeps Standard Care participants out entirely. So this
is **not an open access hole** — it is an absence of study-protocol enforcement.

**Recommendation, split by kind:**

- **Gate 2 — withdrawal / opt-out. Do this one.** Two field reads: refuse session entry when
  `admin.study_withdrawn` is set, and never invite a participant whose `admin.sms_stop` is set.
  Named in `08 §6.1b` as an auth-boundary requirement before any of this scope existed. It needs
  no session engine, no window arithmetic and no dictionary work — the fields are already in
  PID 257. **Priority: position 3 in §9, above the V‑2 conformance items.**
- **Gates 1 and 3 — window and repeat-entry. Your call.** Both are cost and protocol tidiness:
  sessions outside their window, and a participant re-opening a live link to generate extra
  transcripts, scans and RA queue items. Mitigated already by link expiry (24 h / 14 d) and
  Survey Login, so the exposure is bounded. Cheapest version is a *completed-session* check,
  which subsumes most of the value of both without any window arithmetic.

All three fit one framework-free `SessionEntryGate` called from `getSystemContextForRecord()`
and again before `completeSession()` — unit-testable through the existing seams, reopening none
of the scope A6 declined. The point of listing them is that they should be **decided
explicitly**, not inherited as a silent gap from a scope cut.
