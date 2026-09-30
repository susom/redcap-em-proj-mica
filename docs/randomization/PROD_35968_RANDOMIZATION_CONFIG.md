# Prod PID 35968 randomizes nobody: trigger mode + blank stratum

**Found 2026-09-23** from the prod XML export `NEWTESTMICAR01_2026-09-23_1011.REDCap.xml`.
Diagnosis only. Nothing has been changed on prod, and the fix has **not** been reproduced on localhost yet.

## Prod vs localhost

| Setting | Localhost PID 271 (works) | Prod PID 35968 (export) |
|---|---|---|
| `stratified` | 0 | **1** |
| strata field | — | **`rand_strata`** @ `day_1_ed_arm_1` |
| `trigger_option` | **2** (any user or survey participant) | **1** (users with Randomize rights, data-entry form only) |
| trigger instrument | `tsr` | `tsr` |
| trigger logic | `[calc_screen_result]=1` | `[tsr_complete]=2` |
| target | `study_group` @ arm 1 Day-1 | same |

## Cause 1: trigger option 1 never fires from a survey

`redcap_v17.2.3/Classes/Randomization.php:3112`:

```php
if ($randAttr['triggerOption']==1 && ($user_rights['random_perform']!=1 || $isSurveyPage)) continue;
```

Participants submit `tsr` as a survey, so REDCap skips randomization on every submission. It
logs nothing. Staff don't trigger it either: the export's only role, "Data Entry", has
`random_perform="0"`.

## Cause 2: `rand_strata` is blank at the moment of randomization

`rand_strata` lives on `audit`, directly after the `audit_score` calc on the same page, and is set
by `@IF(... @SETVALUE ...)`. `@SETVALUE` is evaluated **when the page loads**. At that point
`audit4`–`audit10` are unanswered, so `audit_score` is empty and the `@IF` sets `''`. The field only
gets a value if somebody reopens the `audit` form after it has been saved.

The prod data shows this. There are 26 records with `tsr_complete=2` and 25 with `audit_complete=2`,
but only **5** have a `rand_strata` value (38, 39, 40, 42, 47).

With a blank stratum, `Randomization.php:3120-3127` aborts and logs:

> Randomize record (via trigger) failed due to missing stratification data for field(s): rand_strata

So fixing cause 1 on its own still randomizes nobody. The failure then appears in Logging as the
message above.

Record 47 is the only record with `study_group` at the arm-1 target event. `saveData()` can't write
that field, so the engine drew it. It has `rand_strata=1` and no `calc_screen_result`, which
suggests staff randomized it by hand. That also suggests a stratum-1 allocation existed at that
point.

## Required changes (prod, in this order)

1. **Move `rand_strata` from `audit` to the top of `tsr`**, keeping its current annotation. By the
   time `tsr` loads, `audit_score` and `s_sex` are saved, so `@SETVALUE` gets real values. The
   `tsr` submit then saves the stratum, and randomization reads it on that same save
   (`realtimeRandomization` runs after the record save). The field must stay radio/dropdown,
   because REDCap only accepts multiple-choice strata fields, so a calc is not an option. The 5
   populated records show that the `@HIDDEN-SURVEY @READ-ONLY @SETVALUE` combination does save.
2. **Randomization setup, Step 3: set the trigger to "Trigger logic (any user or survey
   participant)"** (option 2). Recommended logic: `[calc_screen_result]=1`, the same as
   localhost. Optionally add `AND [consent_complete]='2'`.
3. **Allocation table: confirm or re-upload.** It must have a `rand_strata` column with rows for
   **both 0 and 1**, uploaded **in the project's current status**. REDCap only reads rows whose
   `project_status` matches (`Randomization.php:2173`). Changing the strata setup deletes the
   existing table, so re-upload it after any change to step 1 or 2.
4. **Records already stuck.** These have `tsr_complete=2` and no arm-1 `study_group`: 4–8, 12, 13,
   17, 18, 20, 24–26, 28, 30, 33, 35, 37–40, 42, 45, 46, 54. A config change does not
   retro-randomize them. Each one needs `rand_strata` filled in (reopen and save `audit`/`tsr`),
   then a manual Randomize by a user with `random_perform=1`. The alternative is to delete them,
   since this is a test project. Many of them carry arm-2/3 `study_group` values inherited from a
   project copy. Those values were never drawn from this project's table.

## Diagnostic script caveat

[`../phase-3-handoff/scripts/diagnose-session-handoff.php`](../phase-3-handoff/scripts/diagnose-session-handoff.php)
prints the trigger option and strata fields. It does **not** flag trigger option 1 as fatal for
surveys, and it does not check whether the strata field is blank or whether allocations remain for
each stratum. Read its output with both causes above in mind.

## Done 2026-09-28: fixed on prod, stratified path tested

Prod has since moved `rand_strata` to `tsr` and switched the trigger to option 2. 8 of the 9 who
finished `tsr` since 09-24 were randomized. The end-to-end test ran on a replica of the 09-28 export
(PID 278): `dev-allocation-table-STRATIFIED-TESTING-ONLY.csv` was uploaded through REDCap's page, and
`tsr` was submitted as the participant. Both strata randomize, and a missing AUDIT answer fails with
REDCap's "missing stratification data". Remaining randomization work is in
[`../phase-3-handoff/30-go-live-readiness.md`](../phase-3-handoff/30-go-live-readiness.md).
