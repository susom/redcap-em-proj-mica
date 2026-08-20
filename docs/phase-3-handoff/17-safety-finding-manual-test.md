# Manual test: the MICA safety finding, end to end

How to see a safety finding produced, reviewed, and acted on — by hand, in a browser.

Companion to `11-auth-manual-test-guide.md`. Read §0 first: on PID 257 today, **two of the six links
in the pipeline are broken**, and one of them cannot be fixed from a settings screen.

---

## 0. Start here: what works today, and what does not

Run the preflight. It is read-only and safe against a live study.

```bash
cp docs/phase-3-handoff/scripts/preflight-safetyscan.php ../../temp/mica/
docker exec redcap_2023_1_web php /var/www/html/temp/mica/preflight-safetyscan.php 257
```

It checks the whole chain — session → transcript → job → scan → finding → review — and names the
broken link. That matters because **a break anywhere shows up at the far end as "no findings", which
is indistinguishable from "nothing to find"**. That ambiguity is the thing this entire handoff exists
to prevent, so it should not be how you discover a misconfiguration.

On PID 257 as of 2026-08-20 it reports five blockers. Four are settings you can change (§1). The
fifth is not:

> **`completeSession()` cannot finalize a transcript on PID 257.** It calls
> `calculateSessionInfo()`, which requires an event literally named `baseline_arm_1` and a
> `consent_date` on the record at that event — the *pilot's* scaffolding. PID 257 is the R01
> structure: its arm-1 events are `day_1_ed_arm_1`, `month_3_arm_1`, and no record has a
> `consent_date`. So pressing **End Session** throws *"the project is not configured for MICA
> sessions"* before anything is written.

That is **Stage 2** (the R01 session engine), deliberately deferred — not a Stage 6 defect. Its
consequence for you is concrete: **you cannot drive this pipeline by having a chat.** Everything from
the transcript onwards is fully testable, and §3 seeds a real transcript directly.

Two routes, depending on what you want to look at:

| Route | Use it to test | Needs |
|---|---|---|
| **A — seeded** (§3–§6) | the finding instrument, the review dashboard, disposition, actions | nothing but the fixture |
| **B — a real model call** (§7) | that a live SafetyScan produces schema-valid, quote-verified findings | a registered model alias |

---

## 1. Configure the four settings

**External Modules → MICA → Configure**, on PID 257.

| Setting | Set it to | Why |
|---|---|---|
| Finalize transcripts and queue safety scans | **ticked** | Off means a finished session writes nothing and no scan is queued. The pipeline has no input. |
| SafetyScan model alias | an alias the registry lists | Unset falls back to a hardcoded `gemini-2.5-flash`, which **is not in this server's registry** (it holds `claude-opus-4-7`). An unregistered alias returns the provider's canned apology rather than an error — so sessions look screened and are not. |
| Reviewer role(s) | a REDCap user role | Access follows REDCap roles. With nothing mapped, *nobody* can open the dashboard — not even an admin. |
| PI / protocol-lead role(s) | a REDCap user role | Needed for the audit trail and the launch-readiness checklist. |

Leave **Scan mock mode** off unless you are doing §7 without a working model — it replays fixtures
from disk, which is fine for exercising the UI and useless for testing the model.

`session-host-map` can stay blank: blank means the R01 defaults
(`mica_ed_session:baseline:emergency_department`, `mica_booster_session:booster:remote_followup`),
which already match this project.

Re-run the preflight. Everything except the session-engine link should now be `[ok]`.

---

## 2. Confirm the instrument itself

The finding instrument must exist **and be repeating on the session events** — a finding is one
instance of a repeating instrument.

```bash
docker exec redcap_2023_1_web php \
  /var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/apply-safety-finding-instrument.php 257
```

Idempotent — safe to re-run. On PID 257 it is already applied to events 1008, 1009, 1012, 1014.

Now look at the form itself, in REDCap: **Record Status Dashboard → any record → Mica Safety
Finding**. Three things to check by eye:

1. **15 fields are `@READONLY`.** Everything under *Finding* (the model's output) and
   `action_payload_min` / `action_delivery_status`. You should not be able to type in them. This is
   what stops a reviewer editing what the model actually said — the authoritative copy lives on the
   insert-only scan-run row, so a discrepancy would be detectable, but detectable-after-the-fact is
   not the same as impossible.
2. **The action checkboxes are hidden** until `review_status` is `confirmed` (or the concern type is
   `scan_failure`). Set `review_status` to *Confirmed* and watch `action_types` appear.
3. **`finding_concern_type` includes `scan_failure`** — labelled "SCAN DID NOT COMPLETE — session not
   screened". That is the placeholder written when the scanner gives up, and it is deliberately in
   the same list as real concerns so it cannot be filtered out of a queue by accident.

> Ticking an action checkbox on this form **records a decision; it does not send anything.** Delivery
> only happens through the dashboard's action path, and `action_delivery_status` is `@READONLY`, so
> the form cannot fake a delivery.

---

## 3. Seed a real session and findings

This is the step that gets around the Stage 2 blocker: it finalizes a transcript directly, queues a
scan job, and writes two findings with quotes that verify against the transcript.

```bash
docker exec redcap_2023_1_web php \
  /var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/e2e-review-fixture.php 257 setup
```

It prints a username, a password and the dashboard URL. It creates a **throwaway** REDCap account
with no design and no user-rights privileges, in a throwaway role mapped as both Reviewer and PI.

It seeds two findings on record 2: one **critical / self-harm**, one **moderate**, each citing a
quote that really appears in the transcript — including one with multi-byte text (`caña 日本語 🙂`) so
you can see highlighting survive it.

**Tear it down when you are finished** (§8). The account has a known password.

---

## 4. Review it in the dashboard

Sign in as the printed user, open the printed URL. Or: **project sidebar → MICA Safety Review**.

Walk these in order — each one is a claim the pipeline makes:

1. **The queue orders by urgency, and says so in words.** Critical sits above moderate, and the badge
   reads `CRITICAL`, not just a red bar. An unscreened session sorts *above* everything, because
   "not screened" is more urgent than any finding.
2. **Open the critical finding.** The transcript appears with the cited quote **highlighted**. Click
   the quote's source link — focus jumps to that message.
3. **Check the wording on a recommendation.** It says *"Nobody has been notified."* The model
   recommends; it never notifies. If that sentence is missing, stop and report it.
4. **Try to confirm without a rationale.** *Record decision* stays disabled. Type a rationale and it
   enables. A confirmed critical finding with no stated reason is not reviewable by the next person —
   including you, in three months.
5. **Record the decision.** The screen reloads from the server, so what you see is what is stored,
   including the new lock version.
6. **Open the same finding in a second tab and save again.** You get a conflict, not a silent
   overwrite. Two RAs on one queue is the normal case.

Then check it landed: **Record Status Dashboard → record 2 → Mica Safety Finding**. `review_status`,
`review_reviewer`, `review_reviewed_at` and `review_lock_version` are filled; every `finding_*` field
is untouched.

### Mobile

Do steps 1–5 again at phone width (DevTools → iPhone 13). No horizontal scrolling anywhere, the
badge moves above the row text rather than squeezing it, and the transcript scrolls with the page
instead of inside a nested box.

---

## 4b. Tuning what reaches the queue (the PI's dial)

Two settings under **External Modules → MICA → Configure**:

| Setting | Effect |
|---|---|
| Minimum urgency reaching the review queue | Blank = show everything (default). `moderate` / `high` set a floor. |
| Concern types to keep out of the review queue | Repeatable. Names a category the PI does not want in the queue. |

**This is a post-scan filter, not a prompt change.** The model is still asked about everything, and
its full answer is still stored verbatim on the insert-only scan-run row — so a filtered finding is
recoverable in full, and turning the filter off makes it reappear in future scans. Narrowing the
*prompt* instead would be cheaper and is the wrong trade for a safety instrument: a concern the model
was never asked about is one nobody can later discover was there.

Three things a study cannot switch off, all verified on live settings:

1. **`critical` always reaches the queue**, even if its concern type is excluded. A floor above
   critical is not a preference, it is a way of not being told.
2. **Four concern types can never be excluded** — self-harm, violence, medical emergency, and abuse
   or environmental danger. Selecting one is silently ignored rather than honoured.
3. **The `scan_failure` marker is never filtered.** "Not screened" is the last thing a filter should
   be able to hide.

The list is a **denylist** on purpose. An allowlist would mean adding a concern type to the taxonomy
later silently hides it on every project already configured — a new category of harm arriving switched
off.

### Seeing what was held back

A short queue and a quiet session must not look the same. When anything is filtered, the scan-run row
records both the thresholds in force and what they held back:

```bash
docker exec redcap_2023_1_db mysql -uroot -proot redcap -e \
  "SELECT model_output_json FROM redcap_entity_mica_scan_run ORDER BY id DESC LIMIT 1\G"
```

Look for `thresholds` and `filtered` in the JSON — each filtered entry names the finding index, the
concern type, the urgency and the rule that held it. It carries no participant text: the words stay
in `model_output` in the same row, which is the authoritative copy. Nothing is added to the row on a
project that filters nothing, so diffing two runs stays meaningful.

## 5. The launch-readiness checklist

Still in the dashboard: the **Launch readiness** tab (visible to PI and sysadmin, not to a plain
reviewer).

Seven gates, each `PASSING`, `BLOCKING`, or `AWAITING A DECISION`. Read the summary line first:

- On PID 257 (development status) it says **"development only"** and explains that sessions are still
  starting *because this is a development project*, and would be refused in production. If it ever
  just says sessions are running, without that reason, that is a bug worth reporting — it is the
  exact misreading the gates exist to prevent.
- `critical_acknowledgment_minutes` shows as **awaiting a decision**, not as an error. The handoff
  ships it unset on purpose: how fast a critical finding must be acknowledged is a governance call
  for study leadership, and a default would answer it on their behalf.
- Press **Re-check** after changing a setting; the checklist re-reads.

---

## 6. Notifications

Nothing reaches a care team, PI or protocol lead until a human confirms a finding. To see that
enforced rather than take my word for it:

```bash
cp docs/phase-3-handoff/scripts/verify-notifications.php ../../temp/mica/
docker exec -e MICA_MODULE_DIR=/var/www/html/modules-local/proj_mica_v9.9.9 \
  redcap_2023_1_web php /var/www/html/temp/mica/verify-notifications.php
```

46 checks, self-cleaning, sends no email. It proves the gate refuses a care-team alert on an
unconfirmed finding, that the refusal is recorded, that a confirmed one delivers, and that the
database itself rejects a duplicate send.

To read the actual emails, add `MICA_REAL_EMAIL=you@example.org` — every message is delivered for
real with **all recipients redirected to that one address**, so the project's configured care-team and
PI lists are never touched. Note that this docker stack has no outbound relay (`mailhog` is commented
out of `rdc/docker-compose.yml`), and Stanford's SMTP refuses unauthenticated relay — so you need a
local catcher or credentials. See the Stage 6 record for the four rendering defects this found.

---

## 7. Optional: a real model call

Only worth doing once §1's alias is set to something the registry lists.

```bash
# 1. Confirm the scan path itself, with fixtures (no model, no cost)
cp docs/phase-3-handoff/scripts/verify-safetyscan.php ../../temp/mica/
docker exec redcap_2023_1_web php /var/www/html/temp/mica/verify-safetyscan.php

# 2. Then a live scan: seed a session, and let the cron pick the job up
docker exec redcap_2023_1_web php .../e2e-review-fixture.php 257 setup
#    mica_scan_worker runs every 60s. Watch the job move:
docker exec redcap_2023_1_db mysql -uroot -proot redcap -e \
  "SELECT id,status,attempts,last_error FROM redcap_entity_mica_scan_job WHERE project_id=257;"
```

What each landing state means:

| `status` | Reading |
|---|---|
| `queued` | waiting, or backing off after a transient failure — check `next_attempt_at` |
| `ready_for_review` | scanned, findings written and quote-verified |
| `manual_review_required` | **gave up.** A `scan_failure` placeholder finding was written so a human sees it. `last_error` says why. This is not an all-clear. |

A `citation_mismatch` in `last_error` means the model quoted something not in the transcript, and the
whole scan was rejected rather than partially released — a fabricated quote is worse than no finding.

---

## 8. Tear down

```bash
docker exec redcap_2023_1_web php .../e2e-review-fixture.php 257 teardown
```

Removes the throwaway account and role, the finding instances, the scan jobs and runs, the audit
events, the notification rows for that record, and the role mappings. Re-run the preflight to
confirm the project is back where it started.

**Re-seed between dashboard runs.** §4 records a real disposition, and a settled finding correctly
sorts *below* a pending one — so a second pass over the same data sees a different (still correct)
order.

---

## Where things live, when something looks wrong

| Question | Look |
|---|---|
| Did a transcript get written? | `redcap_external_modules_log`, `log_type = mica_transcript` |
| Was a scan queued? | `redcap_entity_mica_scan_job` |
| What did the model actually return? | `redcap_entity_mica_scan_run` — insert-only, one row per attempt |
| Who looked at what, and who decided? | `redcap_entity_mica_audit_event`, or the dashboard's Audit trail tab |
| Was anyone told? | `redcap_entity_mica_notification` — includes `failed` and `refused` rows, because a trail of only successes cannot answer "was the PI ever told" |
| Why did the module refuse something? | emLogger, if `enable-project-debug-logging` is on |
