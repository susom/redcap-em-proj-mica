# Randomization → arm placement (PID 268)

**Status:** verified end-to-end on localhost PID 268, 2026-09-15. One defect found and fixed.
**Audience:** engineering. The stakeholder-facing version is
[2026-09-15-statistician-reply.md](2026-09-15-statistician-reply.md).

Supersedes the "randomization is disabled, so the allocation field had to be invented" framing in
[../phase-3-handoff/15-arm-materialization.md](../phase-3-handoff/15-arm-materialization.md), which
was written against PID 257 before the built-in module was turned on.

> **If a copied project randomizes nobody, read
> [ALLOCATION_TABLE_DOES_NOT_COPY.md](ALLOCATION_TABLE_DOES_NOT_COPY.md) first.** The allocation
> list does not travel with a project copy — the setup row and trigger do, so everything *looks*
> configured. The symptom is not an error: the participant finishes the battery and reads
> "Someone from the study team will be with you shortly to continue." Hit on PID 271, 2026-09-22.
>
> **Prod PID 35968 has a different setup (stratified on `rand_strata`, trigger option 1)** and fails
> for two more reasons. See [PROD_35968_RANDOMIZATION_CONFIG.md](PROD_35968_RANDOMIZATION_CONFIG.md).
>
> **PID 271 randomizes on `tsr` Complete *or* a coordinator's Randomize click** (2026-09-25):
> trigger option 2, logic `[calc_screen_result]=1 AND [tsr_complete]='2'`. Both paths are E2E-verified.
> See [TRIGGER_TSR_COMPLETE_OR_MANUAL.md](TRIGGER_TSR_COMPLETE_OR_MANUAL.md).
>
> **Go-live, 2026-09-28:** prod randomizes now. Both strata were tested end to end on replica PID
> 278. What's left before the move (production allocation table, trigger with eligibility and
> consent, date stamp) is in
> [../phase-3-handoff/30-go-live-readiness.md](../phase-3-handoff/30-go-live-readiness.md).
>
> **"Why are screens logged under an arm?"** is answered for the PI in
> [2026-09-25-pi-reply-screens-in-arm-1.md](2026-09-25-pi-reply-screens-in-arm-1.md). It covers
> renaming arm 1 (safe: display name only) and the per-arm public screening link. That link skips
> eligibility and consent and was reproduced in a browser.

---

## The two halves

REDCap's built-in Randomization module and the MICA module each do exactly half the job.

| Half | Who does it | What it writes |
|---|---|---|
| Pick the allocation | REDCap Randomization module | `study_group` at arm 1's Day-1 (ED) event |
| Place the record in that arm | `MICA::ensureRecordInAssignedArm()` | `study_group` at the assigned arm's first event |

The join between them is a naming convention: **`study_group`'s coded values are the arm numbers.**
1 = Standard Care (SC) = arm 1, 2 = MICA = arm 2, 3 = MICA + Weekly SMS = arm 3.

### Both halves run in one save

`Classes/DataEntry.php` calls them in this order, on the same request:

- `:6710` `Randomization::realtimeRandomization($fetched, $event_id, $page, $instance)`
- `:6735` `Hooks::call('redcap_save_record', ...)` → MICA's hook → `ensureRecordInAssignedArm()`

So the allocation is already committed to the data table by the time the module's hook reads it.
There is no second save, no cron, and no window in which the record is randomized but un-placed.

## PID 268 configuration (`redcap_randomization`, rid = 7)

```
target_field        study_group
target_event        1089          (arm 1, "Day 1 (ED)")
stratified          0             -- unstratified
group_by            NULL
trigger_option      2             -- "Trigger logic (any user or survey participant)"
trigger_instrument  consent
trigger_logic       [calc_screen_result]=1
```

`trigger_option = 2` is required, not incidental. Option 1 is skipped for survey pages and for users
without Randomize rights:

```php
// Classes/Randomization.php:3112
if ($randAttr['triggerOption']==1 && ($user_rights['random_perform']!=1 || $isSurveyPage)) continue;
```

Consent is submitted by the participant on a survey, and the only user with rights on 268 (`ihabz`)
has `random_perform = 0`, so option 1 would never fire.

## Why only the assigned arm is materialized

A REDCap record spans arms under the **same `record_id`**; it *appears* in an arm once it has at
least one saved value in an event of that arm. Materializing all three arms would give a Standard
Care participant a valid `mica_ed_session` link, because those instruments are designated to arms 2
and 3 only — a control participant able to reach the intervention. Now verified directly in 268:
see the SC row in the results table below.

## Analysis caution: arm membership ≠ study group

Arm 1 does double duty. It is both the screening/enrollment path (`pre_screen` … `consent` are
designated there) **and** the Standard Care arm. Consequently:

- Everyone is in arm 1.
- Participants allocated to 2 or 3 are in arm 1 **and** their assigned arm.
- Standard Care participants are in arm 1 only.

**Derive study group from the `study_group` field, never from arm membership.** An arm-1 record count
is an enrollment count, not an SC count.

Also note *where the data lives*: the entire pre-session battery (`pre_screen`, `auditc`, `screen2`,
`baseline1`, `ddq`, `audit`, `sip2r`, `bscq`, `drug_use`, `tsr`, `close`, `admin`) is designated to
all three arms but is collected at the **arm-1** Day-1 event. Only `study_group` and the session
instruments (`mica_ed_session`, `postsession`, `mica_safety_finding`) sit at 1093/1097. An
arm-2-or-3-filtered report therefore shows the session data and little else — it is not a view of
those participants' baseline.

## `saveData()`'s randomization target-field guard

Once randomization is set up, `Records::saveData()` refuses writes to the target field:

```php
// Classes/Records.php:6783-6789 (inside Records::saveData)
$fieldAndEventIsTarget = Randomization::getFieldRandomizationIds($fieldname, $this_event_id, $project_id, false, true);
if (!$bypassRandomizationCheck && count($fieldAndEventIsTarget) > 0) { /* error */ }
```

The check is **field + event** scoped (`getFieldRandomizationIds` compares `$event_id == $thisEvt`,
`Randomization.php:2354`). The module writes `study_group` at 1093 or 1097 (arms 2/3) while the
target event is 1089 (arm 1), so the guard returns empty and those writes are allowed.

**Arms 2 and 3 are safe structurally. Arm 1 is safe only because of the `already-present`
short-circuit** — which is exactly what the defect below had broken. If the target event is ever
moved off arm 1's Day-1 event, re-check this.

---

## Verification run — 2026-09-15

PID 268 had **no allocation table** (`redcap_randomization_allocation` had zero rows for rid 7), so
nothing could randomize. The `study_group` values and arm-2/3 membership already in the project came
in with the bulk record copy from PID 257 — inherited data, not behaviour produced in 268.

Loaded `dev-allocation-table-TESTING-ONLY.csv` as the **development** table: 60 slots, balanced
1:1:1 (20/20/20), permuted blocks of 6. Its sequence begins `2 1 3 2 3 1 1 1 2 3 3 2 …`.

Then seeded three eligible participants and submitted the consent survey in Chromium as a
participant would. The engine drew in order — slots 61, 62, 63 → groups 2, 1, 3 — which is the
table's own first three, so allocation is sequential and unstratified as configured.

| Participant | Slot → group | Consent submitted as | `consent_complete` | Arms after save | `ed_session_url` resolves to |
|---|---|---|---|---|---|
| `RANDTEST01` | 61 → 2 (MICA) | partial, unsigned | `0` | 1, **2** | `mica_ed_session` @ 1093 (arm 2) |
| `RANDTEST02` | 62 → 1 (**SC**) | **signed, auto-continued** | **`2`** | **1 only** | **`close` @ 1089 (arm 1)** — not a session |
| `RANDTEST03` | 63 → 3 (MICA+SMS) | **entirely blank** | `0` | 1, **3** | `mica_ed_session` @ 1097 (arm 3) |

`ed_session_url` is populated for all three, so the column name is misleading on its own: for
Standard Care it holds the **arm-1 `close` survey** ("you have finished"), not a session link.
`EdSessionLink` returns `no-session-in-arm` for SC and the handoff falls through to `close`, which is
the behaviour described in [the CHANGELOG entry](../../CHANGELOG.md) for the session hand-off.
Resolved from the stored hashes:

```
BZ6TGIgJSpe2bNfR  mica_ed_session  event 1093  arm 2  RANDTEST01
GiXz47WuVgsnZIqq  close            event 1089  arm 1  RANDTEST02   <-- SC
xigMqLw85DZhkM7I  mica_ed_session  event 1097  arm 3  RANDTEST03
```

### The happy path — `RANDTEST02`, signed and complete

The signature was drawn on the canvas and saved (`cf_hipaa_adult_sig = 2503`, an edoc id), the form
saved as `consent_complete = 2`, and REDCap auto-continued to the next survey ("Person Obtaining
Consent"). Audit trail:

```
26207  Update survey response            study_group = '1'
26208  Randomize record (via trigger)    record_id = 'RANDTEST02', randomization_id = 7
```

**This is the Standard Care protection, observed in 268 rather than argued from 257:** group 1 was
drawn, and the record stayed in **arm 1 only** — no arm-2/3 data row, no arm row in
`redcap_record_list`, and the stored handoff URL resolves to the arm-1 `close` survey rather than
`mica_ed_session`. A control participant cannot reach the intervention.

### `RANDTEST01` — the full placement chain

Every row below is timestamped `14:12:06`, i.e. one save:

| Evidence | Source | Value |
|---|---|---|
| Consent page saved | `redcap_log_event8` 26189 | `Update survey response` — `agree_sud = 'JT'` … |
| Engine wrote allocation | `redcap_log_event8` 26192 | `Update survey response` — `study_group = '2'` |
| Randomization audit row | `redcap_log_event8` 26193 | `MANAGE` — `Randomize record (via trigger)`, `randomization_id = 7` |
| Module placed record in arm 2 | `redcap_log_event8` 26196 | `INSERT Create record` — `record_id = 'RANDTEST01', study_group = '2'` |
| Module audit row | `redcap_external_modules_log` 3289 | `record added to its randomized arm` |
| Session link minted | `redcap_external_modules_log` 3290 | `ED session link stored` |
| Allocation slot consumed | `redcap_randomization_allocation` | `aid 61`, group `2`, `is_used_by = RANDTEST01` |
| Arm membership | `redcap_record_list` | `RANDTEST01` → arms `1` and `2` |

`ed_session_url` was populated in the same save, so the participant's intervention link exists the
moment they are randomized.

---

## Finding 1 (open): the trigger does not require consent to be given

`trigger_logic` is only `[calc_screen_result]=1`. It says nothing about consent.

`RANDTEST03` submitted the consent survey with **nothing filled in and the signature dialog never
opened**. REDCap's own response says what happens:

> **NOTE: Some fields are required!** … *Your data was successfully saved*, but you did not provide a
> value for some fields that require a value. … Provide a value for… • Signature of Adult
> Participant • Print Name of Adult Participant

REDCap saves the page, *then* enforces required fields as a post-save warning — and
`realtimeRandomization()` runs on that save. `RANDTEST03` was randomized to group 3, placed in arm 3,
issued a session link, and charged allocation slot 63, with `consent_complete = 0` and no signature
row at all.

So **any eligible participant who opens the consent survey and clicks Submit is randomized, whether
or not they consent.** Required fields are soft on surveys and cannot prevent this.

PID 244 on this instance gates on completion (`[randomization_consent]=0 AND [consent_complete]='2'`),
which is the pattern to copy:

```
[calc_screen_result]=1 AND [consent_complete]='2'
```

Moving the trigger to the end of the baseline battery (finding 3) resolves this too. Not yet
changed — it is a protocol decision.

## Finding 2 (FIXED): `recordExistsInArm()` read the wrong data table

`RANDTEST02` (Standard Care) logged `arm materialization failed: saveData reported errors`.

Root cause, `MICA.php:1379`:

```php
$dataTable = method_exists($this, 'getDataTable') ? $this->getDataTable($project_id) : 'redcap_data';
```

The framework exposes `getDataTable()` on `Framework`, which `AbstractExternalModule` reaches
through `__call`. `method_exists()` does not see magic methods, so the check is **always false**:

```
module class: Stanford\MICA\MICA
method_exists($this,'getDataTable'): FALSE
is_callable:                         TRUE
framework->getDataTable(268):        redcap_data7
```

So the query always ran against `redcap_data`. On PID 257 that is accidentally correct
(`data_table = redcap_data`) — which is why doc 15's 12/12 verification passed. On PID 268
(`data_table = redcap_data7`) the same query matched 0 rows where the real table has 33, so
`recordExistsInArm()` always answered "not in this arm".

Consequences:

1. **Standard Care took the materialize path.** `already-present` never fired, so the module tried
   to write `study_group` at event 1089 — the randomization target field *at its target event* —
   and `Records::saveData()` correctly rejected it. The *outcome* was still right (SC stayed in arm 1)
   but every SC participant logged a failure, which would mask a genuine one.
2. **Idempotency was gone.** Re-saves re-attempted the write for arms 2/3 instead of
   short-circuiting. Benign (same value) but contrary to doc 15's stated guarantee.

Fixed by using the resolver the rest of the module already uses (`\Records::getDataTable()`,
7 other call sites). Verified after the fix:

```
RANDTEST01  group 2 -> arm 2         => already-present
RANDTEST02  group 1 -> arm 1 (SC)    => already-present
RANDTEST03  group 3 -> arm 3         => already-present
```

`\Records::getDataTable(257)` still returns `redcap_data`, so PID 257 is unaffected. Full suite:
1054 tests, 2979 assertions, OK.

## Finding 3 (open): randomization precedes the whole baseline battery

`field_order` on the Day-1 event:

| Instrument | Order |
|---|---|
| `pre_screen` | 1 |
| `auditc` | 16 |
| `screen2` | 24 |
| `screen_eligibility` | 29 |
| **`consent`** — trigger fires here | **35** |
| `person_obtaining_consent` / `contact_info` / `check_code` | 48–62 |
| `baseline1`, `ddq`, `audit`, `sip2r`, `bscq`, `drug_use`, `tsr` | 68–129 |
| `mica_ed_session`, `postsession` (arms 2/3 only) | 138–140 |

Allocation is therefore known before any baseline measure is collected. Nothing in the flow requires
that: retargeting the trigger to `tsr` (order 129, the last baseline instrument) still precedes the
ED session, which is the only hard constraint — the session instruments are arm-scoped and the
session link cannot be minted until the record is in its arm. The whole pre-session battery is
designated to all three arms, so the participant's path up to `tsr` does not depend on the arm.

**Not tested.** Re-point the trigger and re-run the full Day-1 flow before committing to it; the
handoff reads `ed_session_url`, which is written during materialization, so the interaction with
the session hand-off is the part to watch.

---

## Reproducing

```bash
# 1. dev allocation slots (development status only)
#    docs/phase-3-handoff/scripts/dev-allocation-table-TESTING-ONLY.csv, via
#    Randomization setup > Upload allocation table, or insert rid/project_status/target_field rows.

# 2. seed an eligible, un-randomized participant and print the consent survey link
php docs/phase-3-handoff/scripts/seed-rand-test.php 268 RANDTEST04

# 3. submit that link in a browser, then check
#    - redcap_log_event8 for "Randomize record (via trigger)"
#    - redcap_external_modules_log for "record added to its randomized arm"
#    - redcap_record_list for the new arm row
#    - redcap_randomization_allocation for is_used_by
```

`realtimeRandomization()` fires only on a UI save, so a script that calls `REDCap::saveData()` on
`consent` proves nothing — the submission has to go through a browser.

`RANDTEST01`–`03` were left in place as evidence and hold dev allocation slots 61–63. **Do not delete
them through the UI without clearing every arm from the record-list cache:** `RANDTEST01` and
`RANDTEST03` are multi-arm records, and `Records::deleteRecord()` clears the cache for only its
`$arm_id`, leaving a phantom row in the other arm's dashboard — the trap documented in
[15-arm-materialization.md caveat 1](../phase-3-handoff/15-arm-materialization.md#operational-caveats)
(cf. the `ZZTEST2/3/4` leftovers). Use
`Records::deleteRecordFromRecordListCache($pid, $record, $arm)` for each arm.

## Open items for the statistician / PI

1. **Trigger timing** — consent (today) or after `tsr`? (finding 3)
2. **Consent gate** — add `AND [consent_complete]='2'` regardless of (1). (finding 1)
3. **Stratification** — none configured (`stratified = 0`). If strata are wanted (site, sex), they
   must be set up before production; strata cannot be added to an existing randomization without
   redoing the setup.
4. **Production allocation table** — must come from the statistician. REDCap keeps development and
   production tables separate; the dev table here is explicitly labelled test data.
5. **Inherited `study_group` values** — the records copied from 257 have `study_group` values that
   were never drawn from 268's allocation table, so `is_used_by` does not reflect them. Harmless in
   dev; production must start from an empty project.
