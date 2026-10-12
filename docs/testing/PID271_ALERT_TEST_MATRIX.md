# PID 271 — every alert tested, four attributes each

**Run:** 2026-09-21, on PID **271** (`NEW TEST MICA_R01 2`, created 15:04 the same day, status
Development, data table `redcap_data7`). 22 alerts, 37 records.

**Status: the event-prefix fix was APPLIED to alerts 02–14 at 15:46** — 48 attributes across 13
alerts, by [`../phase-3-handoff/scripts/apply-alert-event-prefix.php`](../phase-3-handoff/scripts/apply-alert-event-prefix.php).
Everything below is the before-and-after. See [§6](#6-the-fix-as-applied) for what changed and how
to roll it back.

**Then verified live at 16:16–16:18** on record 11 — two CRC emails delivered with correct
cross-event piping, three SMS alerts silently swallowed by the §2 blocker, and one new finding
about when the booster ladder can queue at all. [§7](#7-live-fire-test--record-11-backdated).

**Then alerts 11 and 13 were texted to a real handset** — record 37, driven through Playwright,
both `delivered` per Twilio. [§7b](#7b-alert-11-texted-a-real-handset--record-37-via-the-browser)
(which also **corrects §7's trigger rule**) and [§7c](#7c-alert-13--and-a-content-defect-that-only-a-live-send-exposes)
(which found that **the +92 and +98 rungs sent byte-identical text**, on both channels and in both
arms — a defect the static matrix cannot see; **fixed at 16:41**).

> ### 🔴 State left behind in 271 — read before the next save
>
> | Change | Revert |
> |---|---|
> | **`twilio_modules_enabled` = `SURVEYS_ALERTS`** (was `SURVEYS`) — SMS alerts are now genuinely deliverable, and all 22 alerts are active | `rollback-record37-sms-test-*.sql` |
> | record **11** `randomization_date` @1104 = 2026-06-01 (was 2026-09-09), plus a new `mica_booster_session_complete = 0` @1109 | `test-backdate-alert-fire.php 271 11 --restore` |
> | record **37** `randomization_date` @1104 = 2026-06-02 (previously absent; its 1112 date is untouched), and `mica_booster_session` @1114 re-saved Incomplete | delete the 1104 field |
>
> This was left deliberately: `SURVEYS_ALERTS` is prerequisite **P1** and the correct setting for a
> project mimicking prod — reverting it would silently re-break the thing §7b just proved works.
> But it is a live SMS configuration now. The exposure is bounded and worth knowing exactly:
>
> **Only records 11 and 37 can text**, because they are the only two with a `randomization_date`
> at event 1104; every other record's anchor pipes blank and is never scheduled. Record 11 →
> (408) 205-2230 via alerts 03/06/08. Record 37 is now **fully spent**: 11 and 13 both have sent
> rows, and 03 is blocked by `mica_ed_session_complete = 2`. Both are known test handsets. Per §7b
> the trigger is a save **at the alert's own firing event**.

**Harness:** [`../phase-3-handoff/scripts/test-alert-logic.php`](../phase-3-handoff/scripts/test-alert-logic.php)
— read-only. Evaluates and pipes; writes nothing, queues nothing, sends nothing.

| | Before | After the fix |
|---|---|---|
| Alerts whose four attributes all resolve for a realistic participant | **2** (16, 17) | **10** — +03, 04, 06, 08, 09, 11, 13, 14 |
| …of those, actually due today | 2 | **3** — 03 is now live-due on record 11 |
| Broken by the event-prefix bug | **13** (02–14) | **0** |
| Blocked by a project setting | **6** — every SMS alert, silently converted to EMAIL | **resolved 16:25** — `twilio_modules_enabled` → `SURVEYS_ALERTS`; two real texts delivered since (§7b, §7c) |
| Waiting only on test data, no defect found | 15, 18, 19, 20, 21, 22 | same |
| Cannot fire for anyone in 271 regardless | **01** — `calcrnd` empty on all 37 records | same |

> ### ⚠️ Two things changed outside these scripts, mid-session
>
> 1. **All 22 alerts were activated** between 15:30 and 15:47 (`email_deleted = 0` on every row;
>    the 15:30 harness run recorded "all alerts deactivated", the 15:47 run "SOME ALERTS ARE LIVE").
>
>    **Nothing has sent, and three independent things are why** — measured at 15:51, after several
>    cron ticks; `redcap_alerts_sent`, `_sent_log`, `redcap_alerts_recurrence` and
>    `redcap_outgoing_email_sms_log` are all **0** for 271:
>
>    | Layer | Why it holds |
>    |---|---|
>    | **Nothing re-evaluates the conditions** | `AlertsNotificationsDatediffChecker` — *"Check all conditional logic in Alerts"*, the only cron that sweeps records — is **DISABLED**. `…Checker2` only *"process[es] records that are already queued"*, and `…Sender` only drains `redcap_alerts_recurrence`. Both queues are empty. Conditions are evaluated **on record save**. |
>    | **A downgraded SMS has no recipient** | after §2 rewrites `alert_type` to `EMAIL`, `Alerts.php:1335` reads `email_to` — which is **empty on all six SMS alerts**, since their recipient lives in `phone_number_to`, and the EMAIL branch never reads it. No send, and no `redcap_alerts_sent` row, so no suppression key is burned. |
>    | **Mail cannot leave the box** | `sendmail_path = /usr/bin/msmtp -t` with `SMTP_SMARTHOST=mailhog:1025`. Everything is captured locally. |
>
>    So the exposure is **saving a record in 271**, not the passage of time. The first save
>    evaluates every condition and queues what matches; `AlertsNotificationsSender` drains it
>    within 60 s. Do not flip `twilio_modules_enabled` without first reading §12 item 2.
> 2. **The ESMS module configuration was overwritten** through the UI — see §9.

---

## 1. Why "I fixed alert 11 and it still doesn't work" — *resolved by §6*

Alert 11 (`alert_id 5759`) **was** in a half-fixed state in 271. Its condition carried the
`[day_1_ed_arm_1]` prefix — the repair from `ALERT_EVENT_PREFIX_BUG.md` — but its recipient and its
time-lag anchor were left pointing at arm 3:

```
alert_condition          ... [day_1_ed_arm_1][randomization_date] ... [day_1_ed_arm_1][phonen] ...   <- fixed
phone_number_to              [day_1_ed_arm_3][phonen]                                                <- not fixed
cron_send_email_on_field     [day_1_ed_arm_3][randomization_date]                                    <- not fixed
```

Measured against realistically-shaped record 9:

```
condition ......... TRUE
anchor ............ BLANK  [day_1_ed_arm_3][randomization_date] -> ''
phone_number_to ... BLANK  [day_1_ed_arm_3][phonen]             -> ''
```

Both blanks fail **silently and differently**:

| Attribute | What a blank does | Where |
|---|---|---|
| `cron_send_email_on_field` | the alert is **never scheduled** — no queue row, no log, no error | `Alerts.php:1181` returns `false` |
| `phone_number_to` | sends to nobody, **and the log row is deleted** | `Message.php:974` |

So the condition going true produced no queue row, no sent row, and no error — which reads exactly
like "the logic still isn't right", and sends you back to edit the condition again.

This is §5 of the prefix doc playing out in practice: *three attributes per alert, not just the
condition.* Alert 11 is the only one of 05–14 in the mixed state, which is the fingerprint of a
hand-edit made through the UI's logic box.

**A condition-only test reports alert 11 as PASS.** That is why the harness pipes all four
attributes through the same calls `Alerts.php` uses (`:1176` anchor, `:1385` phone, `:1608` email).

Post-fix, the same measurement reads:

```
condition ......... TRUE
anchor ............ ok       [day_1_ed_arm_1][randomization_date] -> '2026-09-09'
phone_number_to ... ok       [day_1_ed_arm_1][phonen]             -> '4082052230'
due ............... future   2026-12-10
```

## 2. The second blocker: every SMS alert is silently turned into an email

`redcap_projects.twilio_modules_enabled = 'SURVEYS'` on PID 271 (`twilio_enabled = 1`).

`Project.php:582` computes `twilio_enabled_alerts` as true only for `ALERTS` or `SURVEYS_ALERTS`.
With `SURVEYS`, it is **false**, and then:

```php
// Alerts.php:1286
if (!((… twilio_enabled_global …) && $Proj->twilio_enabled_alerts) && $alert_type != "SENDGRID_TEMPLATE")
    $alert_type = 'EMAIL';
```

The alert is not rejected and nothing is logged — `alert_type` is **rewritten to `EMAIL`**. All six
SMS alerts (01, 03, 06, 08, 11, 13) carry an empty `email_to`, because their recipient lives in
`phone_number_to`. So they are converted to an email with no recipient and vanish.

`twilio_enabled_global` is `1` on this instance, so the project column is the only thing missing.
This is prerequisite **P1** in `README.md` (removed 2026-10-12); it does
not travel with any export, which is why a project copied to mimic prod loses it.

**This blocks all six SMS alerts independently of the prefix bug.** Fixing the prefixes alone will
not produce a text.

> **Resolved 2026-09-21 16:25.** `twilio_modules_enabled` was set to `SURVEYS_ALERTS` in order to
> run §7b/§7c, and **left that way** — it is prerequisite P1 and the correct setting. Everything
> above is the diagnosis, kept because it explains §7's three silently-swallowed SMS alerts and
> because any project copied from prod will arrive in the broken state. The live consequence of the
> flip is in the state block at the top of this document.

## 3. Method — three passes, because one is misleading

Testing alerts against whatever records a project happens to hold is circular. MICA enrols every
participant at **arm 1's Day 1** and materializes only `study_group` into the assigned arm
([`ALERT_EVENT_PREFIX_BUG.md` §3](../topics/alerts/ALERT_EVENT_PREFIX_BUG.md), true by construction). An alert that
reads enrolment data at arm 3's Day 1 therefore passes if someone once seeded a test record there,
and fails for every real participant.

271 contains both shapes, so the distinction is not hypothetical:

| Shape | Count | Meaning |
|---|---|---|
| `realistic` | 23 | enrolment fields at event 1104 (`day_1_ed_arm_1`) and at no other arm's Day 1 |
| `seeded` | **4** | records 32, 34, 35, 37 — enrolment fields at event 1112 (`day_1_ed_arm_3`) |
| `empty` | 10 | no enrolment fields at all |

| Pass | What it evaluates | Question answered |
|---|---|---|
| **A — live** | only `(record, event)` pairs where the record is genuinely in that event's arm (`redcap_record_list`) | does anything fire in 271 *today*? |
| **B — shape** | every alert against one realistic record (**9**), at each firing event | would it fire for a **real participant**? ← the production question |
| **C — fix** | the same, with the §5 prefix repair applied **in memory only** | does the documented fix actually work? |

Each pass reports four verdicts, and the distinction between the last two matters:

| Verdict | Meaning |
|---|---|
| `OK` | all four attributes resolve **and** the send time is in the past — it would go out on the next cron tick |
| `PENDING <date>` | all four attributes resolve; correctly scheduled for the future. **Not a defect** — but indistinguishable from breakage by observation, because nothing arrives either way |
| `COND-FALSE` | the condition is false; the clause-by-clause breakdown says which clause and why |
| `BROKEN: …` | the condition is true but an attribute fails to resolve — the silent-failure class |

**Probe record 9 is in arm 1 only.** Passes B and C therefore evaluate it at arm-2 and arm-3 events
it does not occupy. That is deliberate: it isolates the event-prefix variable from every other
difference between records, and it is how the prefix bug was originally measured on PID 268.

Pass A on its own produces three false passes — alerts **04, 13, 14** report OK, and every record
they pass on is mis-seeded test data. Pass B shows all three are broken. Conversely alert **17**
fails pass B only because probe record 9 never completed `close`; pass A proves it works on
realistic record 2. The passes correct each other; neither is sufficient alone.

## 4. The matrix

`A` = live in 271 · `B` = realistic participant. Both columns shown **before → after** the §6 fix.
Raw output: [`pid271-alert-matrix-run.txt`](PID271_ALERT_TEST_MATRIX.md) (post-fix run).

| # | Type | Alert | A before → after | B before → after | Reading |
|---|---|---|---|---|---|
| 01 | SMS | Phone check — passcode | NO-DATA | COND-FALSE | untouched by the fix. `calcrnd` empty on **all 37 records** — §8 |
| 02 | EMAIL | ED session +7 | NO-DATA | COND-FALSE → COND-FALSE\* | \*fixed; only the email-channel gate is false, which is correct |
| 03 | SMS | ED session +7 | NO-DATA → **OK** | COND-FALSE → **OK** | **fixed and live-due today** on record 11 |
| 04 | EMAIL | CRC — ED +14 | PEND 2026-10-05† → **PEND 2026-09-23** | COND-FALSE → PEND 2026-09-23 | fixed. †was passing only on seeded record 32 |
| 05 | EMAIL | Booster +92 arm 2 | NO-DATA | COND-FALSE → COND-FALSE\* | fixed; channel gate |
| 06 | SMS | Booster +92 arm 2 | NO-DATA → **PEND 2026-12-10** | COND-FALSE → PEND 2026-12-10 | fixed |
| 07 | EMAIL | Booster +98 arm 2 | NO-DATA | COND-FALSE → COND-FALSE\* | fixed; channel gate |
| 08 | SMS | Booster +98 arm 2 | NO-DATA → **PEND 2026-12-16** | COND-FALSE → PEND 2026-12-16 | fixed |
| 09 | EMAIL | CRC — booster arm 2 | NO-DATA → **PEND 2026-12-23** | COND-FALSE → PEND 2026-12-23 | fixed |
| 10 | EMAIL | Booster +92 arm 3 | NO-DATA | COND-FALSE → COND-FALSE\* | fixed; channel gate |
| 11 | SMS | Booster +92 arm 3 | NO-DATA | **BROKEN** → PEND 2026-12-10 | **the half-fix, now repaired — §1** |
| 12 | EMAIL | Booster +98 arm 3 | NO-DATA | COND-FALSE → COND-FALSE\* | fixed; channel gate |
| 13 | SMS | Booster +98 arm 3 | PEND 2026-12-21† → **NO-DATA** | COND-FALSE → PEND 2026-12-16 | fixed. †the seeded records correctly stopped matching |
| 14 | EMAIL | CRC — booster arm 3 | PEND 2027-01-04† → **NO-DATA** | COND-FALSE → PEND 2026-12-23 | fixed. †as 13 |
| 15 | EMAIL | Participant — consent PDF | NO-DATA | COND-FALSE | untouched. No record has `consent_pdf`. No defect found |
| 16 | EMAIL | CRC — consented | **OK** | **OK** | untouched. Worked before and after |
| 17 | EMAIL | CRC — baseline complete | **OK** | COND-FALSE | untouched. Works — proven on realistic record 2; probe 9 never finished `close` |
| 18 | EMAIL | CRC — Month 3 done | NO-DATA | COND-FALSE | untouched. No `close_complete='2'` at any month event. No defect found |
| 19 | EMAIL | CRC — Month 6 done | NO-DATA | COND-FALSE | as 18 |
| 20 | EMAIL | CRC — Month 12 done | NO-DATA | COND-FALSE | as 18 |
| 21 | EMAIL | CRC — SMS opt-out | NO-DATA | COND-FALSE | untouched. `sms_stop` empty everywhere — **O5**, nothing writes it |
| 22 | EMAIL | Not eligible but needs help | NO-DATA | COND-FALSE | untouched. `calc_eligible` empty on all records |

Pass B totals: **COND-FALSE 20 / BROKEN 1 / OK 1** → **COND-FALSE 13 / OK 2 / PENDING 7**.

\* **Not a defect.** Alerts 02/05/07/10/12 now have *every* clause true except the email-channel
gate `([day_1_ed_arm_1][choice_fup_delivery(1)]='1' and [day_1_ed_arm_1][choice_fup_delivery(2)]<>'1')`.
Probe record 9 has `choice_fup_delivery = 2` (SMS), so the email sibling is correctly suppressed and
the SMS sibling fires instead — the channel split working as designed. Confirmed clause-by-clause on
all five. Proving these five positively needs a realistic record with `choice_fup_delivery = 1` and
a `randomization_date`; **271 has none**, so that remains untested.

### Three rows worth reading twice

- **13 and 14 went from PENDING to NO-DATA in pass A. That is the fix working.** They were matching
  only on records 32/34/35/37, whose enrolment data sits at arm 3's Day 1 — the shape the fix exists
  to stop trusting. They now correctly match nobody in 271.
- **03 went from NO-DATA to OK.** Record 11 (arms 1, 2; `randomization_date` 2026-09-09 at event
  1104) now satisfies it, and +7 days is in the past. It is an **SMS**, and the only thing stopping
  a text to (408) 205-2230 is §2.
- **11's pass A is still NO-DATA** even though it is fully repaired, because no arm-3 record in 271
  has a `randomization_date` at event 1104. §5.

## 5. Arm 3 still has no record that can fire — arm 2 has exactly one

The fix is correct, but 271's data limits what you can *observe*. Cross-referencing arm membership
against where `randomization_date` actually sits:

| Record | Arms | `randomization_date` @1104 | @1112 | After the prefix fix |
|---|---|---|---|---|
| 9 | 1 | 2026-09-09 | — | not in arm 2 or 3 — never reaches a booster event |
| **11** | **1, 2** | **2026-09-09** | — | **carries the whole arm-2 ladder: 03 due now, 04/06/08/09 scheduled** |
| 32, 34, 35, 37 | 1, 3 | — | 2026-09-14 … | **now dead, by design** — the anchor points at 1104, which is empty for them |
| 4, 6, 12, 13, 20, 26 | 1, 3 | — | — | realistic and in arm 3, but no `randomization_date` at all |

**Arm 3 therefore has zero records that can fire any booster alert**, which is why 11/13/14 read
`NO-DATA` in pass A despite being fully repaired. Arm 2 has one — record 11 — and it was enough to
exercise the whole arm-2 ladder: **this is what §7 then did**, backdating record 11's
`randomization_date` at event 1104 to 2026-06-01 and letting the real cron run.

**Arm 3 is still untested end to end.** To cover it, give one of records 4/6/12/13/20/26 a
backdated `randomization_date` at event **1104** — not 1112, which is the shape the fix exists to
reject — and a row at event 1114 (`month_3_arm_3`), per §7's last finding.

Remember `reevaluate_send_time = 0`: clear `redcap_alerts_recurrence` and `redcap_alerts_sent` for
the record first, or the backdate changes nothing. §11.

## 6. The fix, as applied

```bash
php apply-alert-event-prefix.php 271            # dry run, prints the diff
php apply-alert-event-prefix.php 271 --apply    # commits + writes a rollback file
```

| | |
|---|---|
| Scope | alerts **02–14** (`alert_order`), all 5 piped attributes each |
| Written | **48 attributes** across 13 alerts; 17 already correct |
| Conditions validated | `LogicTester::isValid()` → true on all 12 rewritten conditions, **before** the write. Alert 11's condition was already correct, so only its recipient/anchor/body changed |
| Idempotent | a second `--apply` reports `0 rewritten / 65 already ok` |
| Residual stale refs | **0** — no `[day_1_ed_arm_2\|3][<enrolment field>]` survives anywhere in all 22 alerts |
| Rollback | `scripts/rollback-alert-event-prefix-271-20260921-154625.sql` — one `UPDATE` per attribute, original values |

### What the transform does, and what it deliberately leaves alone

Per-field, not a blanket prefix swap:

```
[day_1_ed_arm_2|3][<enrolment field>]  ->  [day_1_ed_arm_1][<enrolment field>]
[<enrolment field>]                    ->  [day_1_ed_arm_1][<enrolment field>]
```

for `randomization_date`, `phonen`, `email`, `choice_fup_delivery`, `study_withdrawn`, `sms_stop`,
`first_name`. Idempotency comes from a negative lookbehind: a reference already carrying any event
prefix ends in `][` and is skipped.

**`study_group` is excluded, and the exclusion is load-bearing.** A blanket
`[day_1_ed_arm_3][` → `[day_1_ed_arm_1][` swap — the obvious implementation, and the one the
simulation originally used — would also re-point it. But `study_group` is the *one* field MICA
materializes into the assigned arm, so it is legitimately readable there, and on record 32 it
exists at 1112 **and nowhere else**. Re-pointing it would have blanked "Study group:" in the CRC
escalation emails for exactly the records where the arm matters most. It appears only in the bodies
of alerts 04 and 14, so the cost would have been cosmetic rather than a failed send — but it would
have been wrong. Also untouched: `[event-name]` clauses, smart variables, and alerts 01 and 15–22.

## 7. Live fire test — record 11, backdated

The matrix is a static check: it pipes the four attributes and reads the result. This is the
dynamic one — REDCap's own sender, on its own cron, with nothing simulated.

**Setup.** Record 11 (arms 1 & 2, `choice_fup_delivery = 2` → SMS, no session-complete flags).
`randomization_date` at event 1104 backdated **2026-09-09 → 2026-06-01** via `REDCap::saveData()`,
by [`../phase-3-handoff/scripts/test-backdate-alert-fire.php`](../phase-3-handoff/scripts/test-backdate-alert-fire.php).
All 22 alerts were live and the sender cron was running normally throughout.

| # | Type | Due | Predicted | Actual |
|---|---|---|---|---|
| 02 | EMAIL | +7 | must NOT fire — channel gate | ✅ never queued |
| **03** | **SMS** | 2026-06-08 | fire | ⚠️ **queued, then consumed without sending** |
| **04** | **EMAIL** | 2026-06-15 | fire | ✅ **DELIVERED 16:16:00** |
| 05, 07 | EMAIL | — | must NOT fire — channel gate | ✅ never queued |
| **06** | **SMS** | 2026-09-01 | fire | ⚠️ **consumed without sending** |
| **08** | **SMS** | 2026-09-07 | fire | ⚠️ **consumed without sending** |
| **09** | **EMAIL** | 2026-09-14 | fire | ✅ **DELIVERED 16:18:00** |
| 10–14 | — | — | must NOT fire — arm 3 | ✅ never queued |

Final counters: `sent=2  queued=0  outgoing=2  twilio_errors=0`.

### ✅ The prefix fix is verified end to end

Alert 09 fired at event **1109** (`month_3_arm_2`) and piped every enrolment field from event
**1104**, two events and one arm away — the exact cross-event resolution that was returning blank
before §6:

```
Study group: MICA · Randomized: 2026-06-01
Action: phone the participant. Contact: (408) 205-2230 / jmschult@stanford.edu
```

`Randomized` and `Contact` come from `[day_1_ed_arm_1][randomization_date|phonen|email]`.
`Study group: MICA` comes from the **unprefixed** `[study_group]`, resolving at the firing event —
which is why excluding it from the transform (§6) was right: had it been re-pointed it would still
have worked here, but on record 32 it would have gone blank. Alert 04's email is identical in shape
at event 1108, and its `[form-link:admin:…]` resolved to
`pid=271&page=admin&id=11&event_id=1108`.

The channel split also held: every EMAIL sibling (02, 05, 07) correctly stayed silent because this
participant chose SMS, and no arm-3 alert touched an arm-2 record.

### ⚠️ All three SMS alerts vanished, and left nothing behind at all

03, 06 and 08 queued, the cron picked them up, and then: **no sent row, no
`redcap_alerts_sent_log` row, no `redcap_outgoing_email_sms_log` row, no
`redcap_twilio_error_log` row — and the queue row was deleted.** Zero trace of any kind.

This is §2 executing exactly as read: `Alerts.php:1286` rewrote `alert_type` to `EMAIL`, the EMAIL
branch read `email_to` (empty on all six SMS alerts) and had nobody to send to. It is the cleanest
possible demonstration of why the original "the alert still isn't working" was so hard to diagnose.

**One thing that did go right: no suppression key was burned.** `redcap_alerts_sent` gained no row
for 03/06/08, so the unique key on (alert, record, event, instrument, instance) is still free and a
real SMS test on record 11 remains possible — it just needs a re-save to re-queue, since the
recurrence rows are gone.

### 🔴 New finding — the booster ladder cannot queue until the Month 3 event has data

Alerts 06/08/09 did **not** queue on the Day-1 save, even though the backdate had made all three
due and `REDCap::evaluateLogic()` returned **true** for all three at `month_3_arm_2`. Record 11 had
data at events 1104 and 1108 only. Writing a single field at event 1109 and re-saving queued all
three immediately.

So the condition being true is not sufficient — **save-time alert evaluation only visits events
where the record already has data.** For the Month 3 booster ladder that means something must write
at the Month 3 event before any of 05–14 can ever be scheduled.

That is worth checking on prod, because the population these alerts target is exactly the one least
likely to generate a Month 3 write — a participant who has not engaged with the booster session:

- PID 271 has **no ASIs at all**, so nothing would ever create a Month 3 row automatically here.
- `AlertsNotificationsDatediffChecker` — the only cron that re-evaluates conditions across records
  — is **DISABLED**, and in any case targets `datediff()`-based conditions, which MICA's are not.

**Question for the study team:** on PID 35968, what writes to the Month 3 event, and when? If the
answer is "the Month 3 survey invitation", the ladder works. If it is "nothing until the
participant responds", the booster reminders never queue for the people who need them.

## 7b. Alert 11 texted a real handset — record 37, via the browser

Run 2026-09-21 16:27. Driven through Playwright as a coordinator would
([`../../e2e/alert-11-sms.js`](../../e2e/alert-11-sms.js)), not through the API, so REDCap's own
DataEntry save path and client-side engine were exercised.

**Record 37** is arm 3, `choice_fup_delivery = 2` (SMS), `phonen` `(330) 622-2938` at event 1104,
and already had a row at event 1114 (`month_3_arm_3`). It had **no `randomization_date` at 1104** —
its date was seeded at 1112 — which is exactly why §6 made alert 11 go quiet for it. Typing the
date at 1104 is the realistic repair.

| Step | Result |
|---|---|
| Playwright: sign in, open `admin` at event 1104, type `2026-06-01`, save | 6/6 checks passed; value persisted |
| Gating | only alert 11 left active; `twilio_modules_enabled` → `SURVEYS_ALERTS` |
| Queue | `alert 11, record 37, event 1114, first_send_time 2026-09-02` |
| Send | `redcap_alerts_sent` 16:27:00, `redcap_outgoing_email_sms_log` type **SMS** → **+1 330-622-2938** |
| Twilio API | **`delivered`**, `error_code = None`, `+16506188235 → +13306222938` |

```
Hi Ihab, it's the MICA Team. Your 3-month booster session is ready:
http://redcap.local/surveys/?s=u6PnIqbo2KUWKmb5 Reply STOP to opt out.
```

`[day_1_ed_arm_1][first_name]` piped as **Ihab** cross-event — the prefix fix working on the SMS
channel, not just email.

Two things worth noting from the send record:

- **`redcap_alerts_sent_log.phone_number_to` reads `[day_1_ed_arm_1][phonen]`** — the *unpiped
  template*, not the recipient. That column always does this (`Alerts.php:1833` inserts the alert
  definition verbatim). The authoritative recipient is `redcap_outgoing_email_sms_log.recipients`.
  Do not read the template there as a piping failure.
- **The survey link is `http://redcap.local/...`**, a private hostname. The text arrives, but the
  link is unreachable from a real phone. Harmless locally; on prod the base URL is public.

### 🔴 Correction to §7 — the trigger is narrower than "a save"

§7 concluded that the booster ladder needs data at the Month 3 event. True, but not sufficient.
Measured on record 37, which *had* a Month 3 row throughout and whose condition evaluated **true**
at `month_3_arm_3` the whole time:

| Save | Queued alert 11? |
|---|---|
| Browser, `randomization_date` at event 1104 (blank → 2026-06-01) | **no** |
| `saveData()`, `randomization_date` at 1104 (2026-06-01 → 2026-06-02, a real change) | **no** |
| `saveData()` at **event 1114 itself** | **yes, immediately** |

So changing the enrolment data an alert *reads* does not schedule it. **The save has to land on the
alert's own firing event.** For the booster ladder that means a Month 3 save, not a Day-1 one.

This does not fully explain record 11, where a save at 1104 queued alerts 03/04 at event 1108 — so
the cross-event scoping rule is not completely characterised, and the two cases differ. What is
reproducible, and what matters operationally, is the table above: **to schedule a Month 3 alert,
save at Month 3.** It sharpens §7's question for the study team rather than replacing it — on prod,
what writes at the Month 3 event, and does anything write there for a participant who never engages?

## 7c. Alert 13 — and a content defect that only a live send exposes

Run 2026-09-21 16:35 on record 37 via [`../../e2e/alert-13-sms.js`](../../e2e/alert-13-sms.js):
Playwright opens `mica_booster_session` at event 1114, leaves it **Incomplete** (marking it
Complete is the one thing that correctly cancels the reminder) and saves. 5/5 checks passed.

| | |
|---|---|
| Queued | `13 SMS @1114 due 2026-09-08`, `14 EMAIL @1114 due 2026-09-15` — and nothing else |
| Sent | 16:35:00, both |
| Twilio | **`delivered`**, `error_code = None` → +1 330-622-2938 |
| Alert 14 | CRC email, `Study group: MICA + Weekly SMS · Randomized: 2026-06-02` — arm-3 label and cross-event date both correct |

**What could *not* fire, and why that is right:** alert 03 is blocked because record 37 has
`mica_ed_session_complete = 2` at event 1112 — the participant did attend, so the ED nudge is
correctly suppressed. Alert 11 is suppressed by its own sent row from §7b. Alerts 10/12 lose the
channel gate (this participant chose SMS). So 13 was the only text available, which is what the
run produced.

### 🔴 The +92 and +98 rungs send the *identical* message

Receiving two texts six days apart made this obvious in a way the static matrix never could — it
reports `message ... ok` because every token resolves. They resolve to the same sentence.

Hashing the bodies (`<p>` stripped) across the whole ladder:

| Body hash | Alerts | Lag | Channel |
|---|---|---|---|
| `a9843172…` | **06, 08, 11, 13** | +92 **and** +98 | SMS |
| `ff35e0df…` | **05, 07, 10, 12** | +92 **and** +98 | EMAIL |

> Hi [first_name], it's the MICA Team. Your 3-month booster session is ready:
> [survey-url:mica_booster_session] Reply STOP to opt out.

Both arms, both channels. The alert *titles* distinguish them — "booster session reminder" vs
"booster session **1-week reminder**" — but the participant-facing text does not. Someone who
ignores the day-92 message receives a byte-identical one on day 98, with nothing to signal it is a
follow-up. The CRC escalations (09, 14) are correctly distinct, as are the ED-session pair (02, 03).

### ✅ Fixed 2026-09-21 16:41

Applied by [`../phase-3-handoff/scripts/apply-booster-followup-copy.php`](../phase-3-handoff/scripts/apply-booster-followup-copy.php)
(idempotent; rollback at `rollback-booster-followup-copy-271-20260921-164109.sql`). Six fields on
four alerts: `alert_message` on 07/08/12/13, plus `email_subject` on the two EMAIL ones — **the
subject was duplicated too**, which in an inbox is the more visible half.

Nothing else was touched: the +92 rungs are the first contact and keep their wording, and no
condition, recipient or anchor was changed.

Rendered through the real piping path for record 37:

| | Day 92 | Day 98 |
|---|---|---|
| **SMS** | *Hi Ihab, it's the MICA Team. Your 3-month booster session is ready: … Reply STOP to opt out.* (140 ch) | *Hi Ihab, the MICA Team again. Your booster session is still open: … Reply STOP to opt out.* (136 ch) |
| **Email subject** | Your MICA booster session is ready | Reminder: your MICA booster session is still open |
| **Email body** | It's time for your 3-month MICA booster session. | Just a reminder — we wrote last week about your 3-month MICA booster session, and our records show it isn't complete yet. There's still time… |

Body hashes after the fix — the +92/+98 collision is gone in both arms:

```
05 EMAIL +92  ff35e0df     10 EMAIL +92  ff35e0df
07 EMAIL +98  a19678ad     12 EMAIL +98  a19678ad
06 SMS   +92  a9843172     11 SMS   +92  a9843172
08 SMS   +98  e9692a84     13 SMS   +98  e9692a84
```

Arm 2 and arm 3 still share wording (05≡10, 06≡11, 07≡12, 08≡13). That is deliberate and
pre-existing: the booster session is the same in both arms, so splitting the copy by arm would
invent a distinction the study does not make.

**Two decisions worth recording:**

- **"3-month" was dropped from the +98 SMS.** The day-92 message already established which session
  this is, and the eight characters buy segment headroom. The script asserts the point rather than
  assuming it: piped with a 17-character first name and a production-length survey URL the body is
  **151 characters**, inside the 160-character single-segment limit. Going over would silently
  double the per-message cost and can split mid-URL.
- **Alert 11's SMS body still carries a stray `<p>…</p>` wrapper** (06/08/13 do not). REDCap strips
  it before sending — the delivered text in §7b was clean — so it is cosmetic, and 11 was outside
  the requested scope. Worth tidying next time that alert is edited.

**Not verified by a live send.** The static hashes and the rendered piping above are the evidence;
alert 13 already has a sent row for record 37, so re-texting it would need that suppression key
cleared first.

Four of its five clauses are true on record 9. The fifth, `[calcrnd]<>''`, is false — and
`calcrnd` and `rnd` are **empty on every one of the 37 records**. For comparison, PID 268 has
exactly one record with them.

The formulas themselves are right (prerequisite P2 *is* applied here):

```
rnd      = if([rnd]='', random (1000, 9999), [rnd])     @HIDDEN-SURVEY
calcrnd  = [rnd]                                        @HIDDEN-SURVEY
```

`random()` is evaluated when the form is saved, so the value exists only for records saved through
a path that runs the calc. Copying a project does not carry it. Until a record actually generates a
passcode, requirement 1 cannot be tested at all — this needs a live browser save of `contact_info`,
not a logic fix.

## 9. Enhanced SMS Conversation — enabled and configured on 271

Applied by [`../phase-3-handoff/scripts/apply-esms-config.php`](../phase-3-handoff/scripts/apply-esms-config.php)
(idempotent). Before this run the module had a single `enabled = false` row and no configuration.

| Setting | Value |
|---|---|
| `phone-field` / `-event-id` | `phonen` / **1104** (`day_1_ed_arm_1`) |
| `sms-opt-out-field` / `-event-id` | `sms_stop` / **1104** |
| `twilio-number` / `-sid` / `-token` | `+16506188235`, from the project's own Twilio row |
| `study-withdrawn-logic` | `[day_1_ed_arm_1][study_withdrawn(1)]="1"` |
| reminder / expiry | 60 min / 180 min, ASPIRE PID 192's wording |
| `disable-incoming-sms` | **true** — no tunnel is pointed here; see the build doc's step 7 |

Verified against record 1: phone resolves to `+14129016892`, `isWithdrawn` false,
`getRecordOptOutStatus` false.

### ⚠️ Saving the module's config dialog in the UI wipes settings it doesn't show a value for

This happened mid-session and is worth recording. After the script ran clean (and re-ran reporting
`0 changed / 17 already ok`), a later SQL read showed only **9 of 18** rows left:

| | |
|---|---|
| Deleted | all six pacing/message settings, plus `sms-opt-out-field`, `-event-id` and `study-withdrawn-logic` |
| Corrupted | `twilio-number` held the **account SID**, not the phone number |
| Survived | `phone-field`, `-event-id`, `twilio-sid`, `-token`, the three checkboxes |

The surviving and deleted sets line up exactly with config.json's field order, which is the
signature of a **config-dialog save**: the framework writes every declared setting from the POST,
so any box left blank is cleared rather than left alone. A `twilio-number` holding the SID makes
every outbound SMS fail at Twilio.

Re-applied (`12 changed / 5 already ok`) and verified this time by reading
`redcap_external_module_settings` directly rather than trusting `getProjectSetting()` — the script's
own verification had reported everything fine right before the regression, so it cannot detect this
class of drift on its own. **After any UI visit to the module's config page, re-run the script.**

**Two things this does not do, worth being explicit about:**

1. **It does not affect alerts 01–22.** ESMS runs the weekly check-in over its *own* Twilio
   credentials and ignores `twilio_modules_enabled` entirely
   (`ARM3_WEEKLY_SMS_BUILD.md` §1a (removed 2026-10-12)). The two mechanisms share a vendor
   and nothing else. Section 2's blocker is still a blocker.
2. **Nothing will send.** The module is inert until an ASI with `@ESMS` in its subject fires, and
   **PID 271 has no ASIs at all**.

## 10. The weekly-SMS build is not in 271 — and no longer in 268 either

Checked while looking for the sender. `ARM3_WEEKLY_SMS_BUILD.md` (removed 2026-10-12)
describes a PID 268 state that **no longer exists in the database**:

| Piece the build doc records as done | 268 now | 271 |
|---|---|---|
| Event made repeating for `sunday` | absent | absent |
| Recurring ASI `ss_id 255` on `sunday` | **no ASIs at all** | **no ASIs at all** |
| `sunday` branching rewritten to `[current-instance]=N` | reverted — 61 `week_N_sms_arm_1` gates | 61 |
| `[baseline_arm_1]` references repointed | reverted — 27 refs | 27 |
| `calc_gset_threshold` created | field gone | absent |

`apply-weekly-sms-branching.php` and `apply-weekly-sms-asi.php` are both still in the repo and
idempotent, so this is re-appliable — but it is a rebuild, not a config change, and it is not part
of the alert work above.

## 11. Re-running

```bash
# apply the prefix fix (dry run first; --apply writes a rollback file next to the script)
docker exec redcap_2023_1_web php \
  /var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/apply-alert-event-prefix.php 271
… apply-alert-event-prefix.php 271 --apply

# the matrix (read-only; ~2 min for 22 alerts × 37 records)
docker exec redcap_2023_1_web php \
  /var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/test-alert-logic.php 271

# add the fix simulation
… test-alert-logic.php 271 --simulate-prefix-fix

# one alert, with the piped message body
… test-alert-logic.php 271 --alert=11 --simulate-prefix-fix --verbose

# ESMS config (idempotent)
… apply-esms-config.php 271
```

After any real logic change, a record that has already been evaluated will not re-queue —
`reevaluate_send_time = 0` on all of these. Clear it first
([`ALERT_EVENT_PREFIX_BUG.md` §6](../topics/alerts/ALERT_EVENT_PREFIX_BUG.md)):

```sql
DELETE FROM redcap_alerts_recurrence WHERE alert_id = <id> AND record = '<rec>';
DELETE FROM redcap_alerts_sent       WHERE alert_id = <id> AND record = '<rec>';
```

## 12. Open decisions

1. ~~**Apply the prefix fix to 271?**~~ **Done — §6.** All four attributes on all 13 alerts, in one
   transaction per alert, so the partial-repair failure mode that produced alert 11 cannot recur
   here. That mattered: `[study_withdrawn(1)]<>'1'` and `[sms_stop(1)]<>'1'` had been evaluating
   true *because the field was unreadable at that event*, not because the participant was eligible.
   Repairing only `randomization_date`/`phonen` would have started texting people who withdrew and
   people who sent STOP. (`ALERT_EVENT_PREFIX_BUG.md` §1.)

   **But note what this did and did not buy.** Those two gates now *point at the event where a
   value would live* — they are structurally correct. They are still not **meaningful** on 271,
   because `sms_stop` and `study_withdrawn` are empty on every record, so both clauses pass on a
   blank either way. Nothing writes `sms_stop` (**O5**), and that is unchanged by this fix. The
   suppression gate becomes real only when something populates the field.

   **Not yet applied to prod (PID 35968)** — run §10's audit queries there first; the script takes
   a pid argument.

2. 🔴 **Flip `twilio_modules_enabled` to `SURVEYS_ALERTS`? — now the highest-consequence switch in
   the project.** Required for any SMS alert, and **not flipped.**

   **§7 removed the last doubt about this.** Alerts 03, 06 and 08 all queued on a real save, the
   cron picked all three up, and all three died only at `Alerts.php:1286`. Their recipient pipes
   cleanly to **4082052230** (record 11). Flip this setting and re-save record 11, and three real
   texts go out within a minute — the logic, the anchor, the recipient and the due dates are all
   already correct and proven.

   Before flipping: confirm whose handset (408) 205-2230 is, deactivate the alerts you are not
   testing, or both. Note the failed attempts burned no suppression keys, so all three will
   re-queue on the next save.

3. **`randomization_date` placement.** 271's data splits 2 records at 1104 and **4 at 1112** —
   which contradicts the blanket arm-1 rule for the `admin` fields specifically. The `contact_info`
   fields are unambiguous (27 at 1104, 3 at 1112, all three seeded). The prefix doc already called
   `admin` "a weaker case"; 271 sharpens the question: **where do CRCs open the `admin` form?**
   Enabling `stamp-randomization-date-field` would make it arm-1 by construction — but `proj_mica`
   **is not enabled on 271 at all**, so no stamping and no arm materialization happens there.
4. **`proj_mica` not enabled on 271.** If 271 is meant to mimic prod, this is a divergence worth
   closing before drawing conclusions from it.
