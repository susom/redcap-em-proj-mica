# `contact_info` event designation vs the alerts — found and fixed, 2026-09-21

> ## ⚠️ §3 and §4 are wrong — see [`ALERT_EVENT_PREFIX_BUG.md`](ALERT_EVENT_PREFIX_BUG.md)
>
> The designation change in §1–2 was real and is worth keeping. But §3's withdrawal of the
> `[day_1_ed_arm_1]` recommendation was itself the error, and §4's "22 live / 0 dead" verification
> is circular: it ran on scratch records whose contact data was *placed at* arm 3's Day 1, which is
> the only reason the alerts read it. Designating `contact_info` at 1093/1097 made those events
> *able* to hold contact data; nothing in the study ever *writes* it there. Re-measured against data
> placed the way the flow actually places it, 13 alerts are still dead.

**Status: RESOLVED.** `contact_info` is now designated to **every arm's Day-1 event** (1089, 1093,
1097) on PID 268, and a re-run of the structural audit returns **22 live / 0 dead**. One secondary
item remains open — §5.

Kept because the failure mode is invisible (no error, no log) and because prod has not been checked.

---

## 1. What was wrong

`contact_info` holds every participant contact field — `phonen`, `email`, `dummy_email`,
`first_name`, `calcrnd`, `choice_fup_delivery` — and it was designated to **one** event:

```
baseline1    designated at: 1089, 1093, 1097     (all three Day-1 events)
contact_info designated at: 1089                 (arm 1 Day 1 only)   <-- the problem
```

Every reminder alert pins itself to an **arm-2 or arm-3** event and then reads a contact field,
unprefixed or as `[day_1_ed_arm_2|3][…]`. Those references resolved to a field that did not exist at
that event, so they were permanently blank and the conditions could never be true. Nothing was
logged; the reminders simply never existed.

**13 of 22 alerts were affected** — 10 that could never fire (02, 03, 05–08, 10–13: *every*
participant-facing reminder) and 3 that fired with blank contact details in the body (04, 09, 14).

## 2. Root cause — and a latent bug that is *not* it

[`apply-baseline-split-and-order.php`](../phase-3-handoff/scripts/apply-baseline-split-and-order.php)
intended to designate the new form to all of `baseline1`'s events:

```php
$designated = (int) db_result(db_query("select count(*) from redcap_events_forms
    where form_name = '" . NEW_FORM . "' and event_id in $eventsScoped"), 0);

if ($designated === 0) { /* insert ... where ef.form_name = 'baseline1' */ }
else { note(NEW_FORM . ' designated', "already on $designated event(s)"); }
```

The guard counts rows and skips if **any** exist. With `contact_info` already on 1089 it reported
*"already on 1 event(s)"* and never added 1093/1097. It asserts a **count**, not the intended
**set** — the exact class of bug the script's own comment two lines above warns about ("Each step
now asserts its own end state").

**That guard is still unfixed, and fixing it is not the same thing as fixing the alerts.** Worth
repairing on its own terms so a re-run converges; but the designation was applied by hand here.

## 3. Correction to an earlier recommendation in this file

An earlier revision of this document recommended repointing every contact-field reference to
`[day_1_ed_arm_1]`, reasoning from "all 21 `phonen` rows are at event 1089" that enrolment data
always lives there.

**That was wrong, or at least premature.** The 21 rows were evidence about the *old* structure — every
one of those records predated the designation change, so they could not have had contact data
anywhere else. The correct fix was the one the study team applied: designate `contact_info` where
the alerts already expect to read it.

## 4. Verification after the fix

Structural audit (resolve every field reference against the events each alert pins itself to; ask
whether that field's form is designated there — data independent):

```
22 live, 0 can never be true on PID 268
```

And end-to-end on scratch records, evaluating each alert's real condition:

| Alert | Event | Data entered | Condition |
|---|---|---|---|
| 01 passcode | `day_1_ed_arm_1` | consent + contact_info complete, `phonen`, `calcrnd` | **true** |
| 03 ED 1-week reminder (SMS) | `day_1_ed_arm_3` | `randomization_date` −7d, `phonen`, SMS tick — **all at that event** | **true** |
| 11, 13, 14 booster (arm 3) | `month_3_arm_3` | same values at arm-3 Day 1 | **true** |

So the reminder tests belong in **Arm 3**, with the contact fields entered at Arm 3's Day 1.

`consent` and `check_code` are still designated at **1089 only**, so alert 01 can only fire at
`day_1_ed_arm_1` — the passcode test has to be run in Arm 1. That is correct behaviour, not a
defect: Arm 1's Day 1 is MICA's enrolment event for every participant.

## 5. Still open — the CRC escalation bodies at Month 3

Unaffected by the designation fix, because it is about **Month 3**, not Day 1.

Alerts **09** and **14** fire correctly, but their message bodies pipe `[phonen]`, `[email]`,
`[randomization_date]` and `[study_group]` **unprefixed**, which resolve at the firing event. Month 3
(e.g. 1099) is designated `audit, auditc, bscq, close, ddq, drug_use, mica_booster_session,
mica_safety_finding, postsession, sip2r` — **no `admin`, no `contact_info`** — so all four come out
blank:

```
body piping for alert 14 at month_3_arm_3:
  [phonen]             no (blank)
  [email]              no (blank)
  [randomization_date] no (blank)
  [study_group]        no (blank)
```

The body reads *"**Action: phone the participant.** Contact: [phonen] / [email]"*. It fires, tells
the coordinator to call, and gives them nothing — **Requirement 4 failing** on the one alert
deliberately given `prevent_piping_identifiers = 0` so it *could* show contact details.

Alert **04** is fine now: it fires at `day_1_ed_arm_2|3`, where `contact_info` is designated, so its
body resolves provided the contact fields were entered there.

**Fix for 09/14:** prefix those four tokens in the message bodies with `[day_1_ed_arm_2]` /
`[day_1_ed_arm_3]` to match each alert's arm. Two alert definitions, bodies only — no condition
changes. Not applied.

## 6. Check production before assuming it matches

Prod is PID **35968** on another instance. Because the root cause is an order-dependent guard, prod
could be in either state.

```sql
SET @pid = 35968;

-- (a) the discriminating check. contact_info must appear on all three Day-1 events.
SELECT ef.form_name, GROUP_CONCAT(ef.event_id ORDER BY ef.event_id) AS events
FROM redcap_events_forms ef
WHERE ef.form_name IN ('contact_info','baseline1','consent','check_code','admin')
  AND ef.event_id IN (SELECT e.event_id FROM redcap_events_metadata e
                      JOIN redcap_events_arms a ON a.arm_id = e.arm_id WHERE a.project_id = @pid)
GROUP BY ef.form_name;

-- (b) do the CRC escalations still pipe contact fields unprefixed? (§5)
SELECT alert_title FROM redcap_alerts
 WHERE project_id = @pid AND alert_title LIKE '09 %' OR alert_title LIKE '14 %';
```

If (a) shows `contact_info` on one event only, prod has the breakage this document describes and
needs the same designation applied.

## 7. Consequence for the PI test manual

[`pi-review/MICA_Arm3_SMS_Test_Manual.pdf`](pi-review/) is rebuilt against the fixed structure: five
tests again, with test 1 in **Arm 1** and tests 2–5 in **Arm 3**. Two things the manual had wrong
independently of this, both corrected:

- It sent the PI to Arm 3 for `consent` and `contact_info`. Those steps could not work.
- It dropped the `calcrnd` check. Alert 01 requires `[calcrnd] <> ''`, and that is the clause that
  actually blocks it — verified on a scratch record with consent and `contact_info` complete and a
  phone number present, where the full condition still evaluated **false** because the calc had not
  populated. `calcrnd` is `[rnd]`, and `rnd` is `if([rnd]='', random(1000,9999), [rnd])`;
  `random()` *is* a server-side function (`LogicParser.php:141`) and `Calculate::saveCalcFields()`
  computes it, but a plain `REDCap::saveData()` of other fields does not, because `rnd`'s only
  dependency is itself so nothing marks it for recalculation. A browser form save posts the
  client-computed value, which is why real records have it. **Consequence beyond testing: a
  `contact_info` import via API or the Data Import Tool leaves the passcode blank and the alert
  silently never sends** — a second, independent reason imports break alert 01, on top of the
  form-trigger finding in `TEST_PLAN_LOCALHOST.md`.
