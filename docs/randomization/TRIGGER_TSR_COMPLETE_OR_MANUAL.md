# Randomization on PID 271: `tsr` Complete, or a coordinator's Randomize click

**Applied 2026-09-25 on localhost PID 271. Verified end to end, 35/35.**

The requirement: a record is randomized either **automatically, when `tsr` is Complete**, or
**manually, when staff click Randomize**. Either way the record is placed in its arm and gets its
session link.

## What changed

Only the trigger logic (`redcap_randomization`, rid 8):

| | Before | After |
|---|---|---|
| `trigger_option` | 2 | 2 (unchanged) |
| `trigger_instrument` | `tsr` @ 1104 | unchanged |
| `trigger_logic` | `[calc_screen_result]=1` | `[calc_screen_result]=1 AND [tsr_complete]='2'` |

Applied with [`apply-rand-trigger-tsr-complete.php`](../phase-3-handoff/scripts/apply-rand-trigger-tsr-complete.php),
which goes through `Randomization::saveRealtimeOption()`, the same function the setup page uses. That
makes the change show up in the project log (log_event 27000, "Save randomization execute option").
Rollback: `rollback-rand-trigger-271-20260925-094738.sql`.

## Why `trigger_option` stays 2

Option 2 already covers both paths:

- **Automatic.** `realtimeRandomization()` runs on every save of `tsr` @ 1104, whether it comes from a survey or from data entry.
- **Manual.** REDCap renders the Randomize button on the target field's form (`admin` @ Day 1 (ED)) for
  anyone with Randomize rights, *whatever the trigger option is* (`DataEntry.php:4282-4296`).

Option 1 would break the automatic path: it is skipped on survey pages (`Randomization.php:3112`).
Option 0 would switch the automatic path off entirely.

## Why the `[tsr_complete]='2'` clause

`tsr` is a single-page survey with save-and-return off, so a participant's submit is always a
Complete save. The clause changes nothing for participants. It matters for staff: without it, a CRC
saving `tsr` as **Incomplete** on the data-entry form would randomize the record.

## Who can click Randomize

Anyone with the **Randomize** user right (`random_perform`). A super user always has it:
`UserRights::getSuperUserPrivileges()` overrides the project row, so `ihabz` shows
`random_perform = 0` in `redcap_user_rights` and can still randomize. **Coordinators need the right
granted to their role**, which is why the E2E runs as a non-super user.

## The manual path places the arm by itself

`Randomization/randomize_record.php` saves the allocation through
`DataEntry::saveRecord(..., $callSaveRecordHook = true)`, so MICA's `redcap_save_record` runs on the
Randomize click itself. The E2E checks arm placement and `ed_session_url` **before** the `admin`
form is saved, so none of it depends on the coordinator saving the form afterwards.

## Verification — `e2e/rand-trigger.js`

Staff steps run as a throwaway **non-super** user with Randomize rights
(`e2e-admin-form-user.php 271 setup --randomize`). Every assertion reads the database:
`study_group` @ 1104, the audit row, the charged allocation slot, `redcap_record_list` arms, the
module's "record added to its randomized arm" row, and `ed_session_url`.

| Case | Record | Result |
|---|---|---|
| A: participant submits `tsr` survey | TRIGTSR03 | randomized via trigger, placed in its arm, link stored |
| N1: staff save `tsr` **Incomplete** | TRIGNEG02 | **not** randomized, arm 1 only, no slot charged |
| N2: same record saved **Complete** | TRIGNEG02 | randomized via trigger, placed, link stored |
| M: staff click Randomize, `tsr` never touched | TRIGMAN02 | "Randomize record" audit row, placed, link stored |

Screenshots: `e2e/shots/rand-trigger-*.png` (the `tsr` survey on desktop and mobile, the Randomize
confirmation, and the randomized `admin` form on mobile).

```bash
S=/var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts
for r in TRIGTSR04 TRIGNEG03 TRIGMAN03; do docker exec redcap_2023_1_web php $S/seed-rand-test.php 271 $r; done
docker exec redcap_2023_1_web php $S/e2e-admin-form-user.php 271 setup --randomize
L=$(docker exec -w /var/www/html redcap_2023_1_web php -r '$_GET["pid"]=271; define("NOAUTH",true);
  require "redcap_connect.php"; echo REDCap::getSurveyLink("TRIGTSR04","tsr",1104);')
node e2e/rand-trigger.js "$L" TRIGTSR04,TRIGNEG03,TRIGMAN03
docker exec redcap_2023_1_web php $S/e2e-admin-form-user.php 271 teardown
```

Each run spends three allocation slots, because randomization cannot be undone. **51 unused dev slots remain**
(MICA-only table, groups 2/3).

## Caveats

- **The manual button does not evaluate trigger logic.** Staff can randomize a record that screened
  ineligible (`calc_screen_result` ≠ 1). This is how REDCap works, not something this change introduced.
- **A participant already on the "someone will be with you shortly" page is not moved.** If a
  coordinator randomizes them manually afterwards, the coordinator has to hand them the
  `ed_session_url` link.
- **Both paths draw from the same allocation table.**
- **Prod 35968 is configured differently** (trigger option 1, stratified on `rand_strata`). There, only
  users with Randomize rights on data entry trigger it, and a participant's survey submit never does. See
  [PROD_35968_RANDOMIZATION_CONFIG.md](PROD_35968_RANDOMIZATION_CONFIG.md).
- `randomization_date_stamped = no` in the module's link log on 271 for every record, including
  ones randomized before this change. Not investigated here.
- Test records TRIGTSR01–03, TRIGNEG01–02 and TRIGMAN01–02 were left in place and hold slots 123–129
  (121 and 122 belong to records 1 and 2). Delete them per the multi-arm caveat in
  [README.md](README.md#reproducing).
