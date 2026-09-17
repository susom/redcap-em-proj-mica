# Arm 3 weekly SMS — actual state, 2026-09-15

**Asked for:** a PI-facing test document for the Arm 3 weekly SMS.
**Found:** there is nothing to test yet. The weekly SMS has **no sender** and the `sunday`
instrument is an **unported ASPIRE/TRAM artifact** that REDCap itself refuses to run.

The PI deliverable built from this is
[`pi-review/MICA_Arm3_Weekly_SMS_PI_Review.pdf`](pi-review/) — it asks for the two design
decisions, and gives the PI the one task that *is* on the critical path today (reviewing the
message library).

Companions: [`README.md`](README.md) (the 21 built alerts),
[`../ALERTS_AUDIT_257_vs_262.md`](../ALERTS_AUDIT_257_vs_262.md) §4b (where the deferral is
recorded).

---

## 1. Nothing sends the weekly SMS

Four independent confirmations, all against PID 257 on localhost:

| Check | Result |
|---|---|
| Alert titles in `MICA_257_alerts_import.csv` | 21 rows enumerated — passcode, ED ladder, booster ladders ×2, 7 ASPIRE ports. **None is a weekly SMS.** |
| `build_alerts_csv.py` | no `weekly`/`sunday`/`thursday` cadence; the only `sunday` hit is alert 21's opt-out clause |
| `redcap_surveys_scheduler` for `sunday` / `sms_code_check` / `sms_opt_out` | **no ASI rows at all** (LEFT JOIN returns NULL) |
| `redcap_events_repeat WHERE event_id=1013` | **empty** — "Weeks 1–12" is a single, non-repeating event |

The audit already recorded this as deferred, not built: ASPIRE drives the cadence with 24 ASIs
(`sunday` ×12 + `thursday` ×12) on 12 dedicated events `1023`–`1034`, and MICA arm 3 has one
`1013` event instead. The audit's words: *"a different (repeating-instance) design. Not a 1:1
port."* The Design-decisions table in §5 of that audit never covers the weekly SMS — requirements
1–4 were the passcode, the ED ladder and the booster ladder.

**So the cadence was never specified, and consequently never built.** That is the gap.

## 2. The `sunday` instrument does not run

Rendered the real participant survey link for record 4 at event 1013
(`REDCap::getSurveyLink('4','sunday',1013)`) in Chromium. What a participant would get today:

> **SURVEY ERRORS EXIST: CANNOT CONTINUE!**
> Branching Logic errors exist in these fields: `sd_1 … sd_12`, `bd_1 … bd_12`.
> This survey will not function correctly until these errors have been fixed.

REDCap blocks the survey. Behind the dialog, 28 field rows render, including **all 24 feedback
messages at once** — every `sd_*` ("you stayed within lower-risk levels") *and* every `bd_*`
("this week's drinking was in the range considered unhealthy"), simultaneously and
contradictorily. Typing `dquant = 10` changed nothing, so the threshold split is not
mis-calibrated, it is inert.

Screenshot: `pi-review/sunday-as-rendered.png`.

### Root cause — the same defect class as O1/P8, on a form P8 didn't cover

`sunday` references **two events that do not exist in MICA**:

| Reference in `sunday` | Fields affected | Reality in PID 257 |
|---|---|---|
| `[event-name]="week_1_sms_arm_1"` … `week_12_sms_arm_1` | **61** branching expressions | no such events. Arm 3 has one `Weeks 1–12` (ev 1013). The 13 events are `day_1_ed`/`month_3`/`month_6`/`month_12` × arms 1–3, plus 1013. |
| `[baseline_arm_1][…]` | **27** references | no such event. Arm 3's Day 1 is `day_1_ed_arm_3` (ev 1012). |

Consequences, each observed:

1. `[baseline_arm_1][calc_binge_threshold]` resolves to blank, so both `[dquant] < ''` and
   `[dquant] >= ''` are true → all 24 `sd_*`/`bd_*` show, and REDCap flags the 24 expressions as
   errors. **The field itself is fine** — `calc_binge_threshold` lives on `auditc` and has been
   updated to `if([s_sex]='1',5,if([s_sex]='2',4,''))`. The only defect is the dead
   `baseline_arm_1` event prefix in front of it. *(Corrected 2026-09-15: an earlier revision of
   this doc said the field was on `screen` and keyed on `birth_sex`. Both were wrong.)*
2. `sun_sms_start` pipes `[baseline_arm_1][first_name]` → renders **"Hi \_\_\_\_\_, it's the MICA
   Team checking in."** `first_name` actually lives on `contact_info`, which is **not** designated
   at 1013, so this genuinely needs a cross-event prefix — the correct one,
   `[day_1_ed_arm_3][first_name]`.
3. All 12 `ar_*` (alcohol-free), 12 `gp_*`, 12 `gg_*` and `sun_end_week_12` are gated on
   `week_N_sms_arm_1` → **permanently false, never display.** That is 37 of the 63 messages
   unreachable.
4. `ar_12` is branched on `week_11_sms_arm_1` — a duplicate-week copy/paste bug that is wrong even
   in ASPIRE's own terms.
5. `no_plan` ("Great! Thanks for letting us know.") displays before `drink_fut` is answered.

This is exactly the disease fixed as migration step **P8** on the `close` form, where four
descriptives branched on ASPIRE event names and rendered `close` as a blank survey. P8 did not
touch `sunday`. The precise scan (`baseline_arm_1` / `week_N_sms_arm_1`, excluding legitimate
MICA `*_arm_1` names) finds the remaining references concentrated there:

| Form | `week_N_sms_arm_1` branches | `baseline_arm_1` refs |
|---|---|---|
| **`sunday`** | **61** | **27** |
| `admin` | 0 | 2 |
| `close` | 0 | 1 |
| `audit` | 0 | 1 |

The 4 stragglers on `admin`/`close`/`audit` are worth a separate look but are not participant-blocking.

### Survey text and branding

- `redcap_surveys.instructions` for `sunday`: *"Hi again. It's the **TRAM** Team checking in."* —
  a third study's name. It is the **only** survey in PID 257 whose title/instructions still
  mention TRAM or ASPIRE, so P4/P5-style rebranding missed this one.
- `redcap_surveys.title` is **NULL** (the browser tab is blank).
- The header renders "Stanford | ASPIRE". Each survey in the project carries its own logo record,
  so this is **project-wide** branding, not specific to the weekly SMS — flagged separately, not
  as an Arm-3 defect.
- `debug_sunday` ("FOR DEBUGGING: …") is `@HIDDEN-SURVEY`, so participants do **not** see it. It
  is still visible on the data-entry form.

## 3. What *is* proven for arm 3

The plumbing is not the gap, and the PI should know that:

| Capability | Evidence |
|---|---|
| SMS leaves Twilio and arrives | Test 1 — alert 01 delivered a 4-digit passcode to a real handset |
| `twilio_modules_enabled = SURVEYS_ALERTS` | migration P1 |
| `phonen` accepted as an SMS recipient | migration P7 (`phone` validation) |
| Arm-3 isolation works | Test 6 — record 4 queued only alerts 10/12/14, zero cross-firing |
| Arm-3 survey links resolve | `getSurveyLink('4','sunday',1013)` minted a working link |
| `sms_code_check` / `sms_opt_out` | clean — MICA-branded, no ASPIRE refs, no branching |

## 4. The two decisions needed (and why they block)

**A. How do 12 weekly sends fit the calendar?** Arm 3 has one non-repeating `Weeks 1–12` event, so
today there is exactly one slot for twelve messages. Options: 12 dedicated events (ASPIRE's shape,
and what the message library's `week_N` design assumes); make 1013 repeating with 12 instances; or
one event re-sent 12 times. The message library is authored as 12 *distinct* weekly messages per
branch, which is evidence for the 12-slot reading — but it is the PI's call, and the fix to
`sunday`'s 61 branches depends entirely on the answer.

**B. Sunday only, or Sunday + Thursday?** ASPIRE sent twice weekly (24 sends). MICA has only a
`sunday` form. Confirm the intended cadence before anything is built.

Also needs an answer eventually, but not blocking: `calc_esms_valid` on `admin` is ASPIRE's gate
for the weekly cadence, and reusing it is the obvious default — **but it cannot currently evaluate
to 1.** Its equation requires `[birth_sex] <> ''`, and `birth_sex` has **zero rows** in PID 257's
metadata; the project uses `sex` (on `baseline1`) and `s_sex` (on `pre_screen`) instead. Repoint or
drop that clause before relying on the gate. Irrelevant to the four alerts in
[`pi-review/`](pi-review/)'s manual — none of them reads `calc_esms_valid`.

Standing caveat that applies to any SMS work here: **`sms_stop` has no automatic writer** (O5 in
[`README.md`](README.md)). Twilio blocks a STOP at its end, REDCap never learns, and the
suppression gate is only real if the CRC ticks the box.

## 5. Recommended order once A and B are answered

1. Materialize the chosen calendar shape (events or repeating instances) in arm 3.
2. Rewrite `sunday`'s 61 branches onto the real slots, and the 27 `[baseline_arm_1]` refs onto
   `[day_1_ed_arm_3]`. Fix `ar_12`'s duplicate week and `no_plan`'s premature display.
3. Rebrand: survey instructions (TRAM → MICA), set a survey title.
4. Add the sender — ASI on `sunday` per slot, gated on `calc_esms_valid` + `study_withdrawn` +
   `sms_stop`, mirroring the suppression the 21 alerts already use.
5. Then, and only then, the delivery test the PI originally asked for is runnable.

## 6. Still to confirm on production — PID 35968, not 257

**Every finding above is from the development copy, PID 257.** Production runs
`NEW TEST MICA_R01` on **PID 35968** (still Development *status*, which is a separate thing — see
the launch-gate banner). The two projects diverge by design: the production-replication checklist
in [`README.md`](README.md) lists ten prerequisites that have to be re-applied by hand, so prod's
`sunday` branching and ASI state are genuinely unknown until checked.

The PI PDF carries a plain-language caveat saying so. Close it with these three read-only queries —
the same ones that produced §1 and §2, with the PID swapped:

```sql
SET @pid = 35968;

-- (a) Does anything schedule the weekly survey? Expect 0 rows if it is unbuilt there too.
SELECT s.form_name, ss.event_id, ss.active, LEFT(ss.condition_logic,80) AS cond
FROM redcap_surveys s
LEFT JOIN redcap_surveys_scheduler ss ON ss.survey_id = s.survey_id
WHERE s.project_id = @pid AND s.form_name IN ('sunday','sms_code_check','sms_opt_out');

-- (b) The broken-reference scan. Expect sunday = 61 / 27 if prod matches dev.
--     Note the REGEXP, not LIKE '%_arm_1%' - the latter also matches MICA's own legitimate
--     day_1_ed_arm_1 / month_3_arm_1 event names and reports false positives.
SELECT form_name,
       SUM(branching_logic REGEXP 'week_[0-9]+_sms_arm_1') AS week_sms_branches,
       SUM(COALESCE(branching_logic,'') LIKE '%baseline_arm_1%'
        OR COALESCE(element_label,'')  LIKE '%baseline_arm_1%'
        OR COALESCE(element_enum,'')   LIKE '%baseline_arm_1%') AS baseline_arm_1_refs
FROM redcap_metadata WHERE project_id = @pid
GROUP BY form_name HAVING week_sms_branches > 0 OR baseline_arm_1_refs > 0;

-- (c) Is the Weeks 1-12 event repeating there? Find its event_id first, then:
SELECT em.event_id, em.descrip, a.arm_num, (r.event_id IS NOT NULL) AS is_repeating
FROM redcap_events_metadata em
JOIN redcap_events_arms a ON a.arm_id = em.arm_id
LEFT JOIN redcap_events_repeat r ON r.event_id = em.event_id AND r.form_name = 'sunday'
WHERE a.project_id = @pid AND a.arm_num = 3;
```

If all three match dev, drop the caveat from `build-pdf.js` and rebuild. If any differs, the PDF's
factual claims need correcting **before** it goes to the PI.

## 7. Reproducing section 2

All paths below are from the module root.

```bash
# 1. mint the participant link (read-only)
docker cp docs/alerts/pi-review/sunday-link.php redcap_2023_1_web:/tmp/sunday-link.php
docker exec redcap_2023_1_web php /tmp/sunday-link.php 257 4 1013

# 2. render it and list what actually displays (types dquant to fire branching, never submits)
NODE_PATH="$PWD/node_modules" node docs/alerts/pi-review/render-sunday.js "<link>" /tmp/sunday

# 3. rebuild the PI PDF (reads message-library.tsv + sunday-crop.png)
NODE_PATH="$PWD/node_modules" node docs/alerts/pi-review/build-pdf.js
```

Neither script writes study data. `NODE_PATH` is needed because Playwright is a devDependency at
the module root and these scripts live three directories down.

The PDF embeds the screenshot, not the link: `redcap.local` resolves only inside the dev setup, so a
link in a PI-facing document would simply fail to open. Section 5 of the PDF offers a screen-share
instead.
