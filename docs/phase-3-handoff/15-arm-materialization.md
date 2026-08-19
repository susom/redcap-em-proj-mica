# 15 — Automatic arm placement (`study_group` → assigned arm)

**Status:** IMPLEMENTED and verified 2026-08-19
**Problem:** a CRC creates a record during enrollment (arm 1) and then has to create it again
inside the randomized arm by hand before that arm's events and survey links work.

## The framing correction

There is nothing to "copy". A REDCap record spans arms under the **same `record_id`**; it simply
*appears* in an arm once it has **at least one saved value in an event belonging to that arm**. That
is also the condition `REDCap::getSurveyLink()` checks before it will mint a link — noted inline in
`scripts/manual-test-auth.php`, which has always relied on it.

Two mechanics worth knowing:

- REDCap caches membership in **`redcap_record_list`**, keyed `(project_id, arm, record)`. A raw
  `INSERT` into the data table would **not** update that cache and the record would stay invisible in
  the arm's Record Status Dashboard. Everything here goes through `REDCap::saveData()`, which
  maintains it.
- `eventInfo[$id]` carries `arm_num` and `day_offset` but **not** `unique_event_name` — use
  `REDCap::getEventNames(true, false)` for the name `saveData` needs.

## Only the assigned arm — not all three

Materializing every record in every arm would give a **Standard Care participant a valid
`mica_ed_session` link**, because the two host instruments are designated to arms 2 and 3. That is a
control participant able to reach the intervention — a protocol-integrity problem, not a cosmetic
one. Secondary costs: per-arm dashboard counts and arm-filtered reports stop meaning anything, and
arm-3's weekly-SMS events become schedulable for people who are not in arm 3.

So the rule is: **the record is placed in exactly the arm it was randomized to.**

## What was added

| Piece | Purpose |
|---|---|
| `study_group` field (radio, on `admin`) | The allocation. **Its value is the arm number**: 1 = Standard Care (SC), 2 = MICA, 3 = MICA + Weekly SMS |
| [`scripts/apply-study-group-field.php`](scripts/apply-study-group-field.php) | Idempotent creation of that field, anchored before `admin_complete`, with `field_order` integrity verified |
| `MICA::redcap_save_record()` | Fires on a CRC's form save and delegates to the shared method |
| `MICA::ensureRecordInAssignedArm()` | The actual logic; public so it can be driven on demand |
| [`scripts/backfill-study-group-arms.php`](scripts/backfill-study-group-arms.php) | Backfill / import coverage, with `--dry-run` |
| `materialize-assigned-arm`, `study-group-field` project settings | Off by default; the allocation field is configurable |

**Why the allocation field had to be invented:** nothing in PID 257 stored the assigned arm.
`randomize_trigger` and `randomization_date` exist on `admin`, but `desc_group_assigned` is a
*descriptive* (display-only) field, and REDCap's built-in Randomization module is disabled
(`randomization = 0`). Without a real value, no code can know the target arm. If you later enable
REDCap's Randomization module, point `study-group-field` at its allocation field instead.

## Coverage limit — read this before relying on the hook

`redcap_save_record` is fired from **exactly one place** in REDCap: `Classes/DataEntry.php:6735`,
i.e. only when someone saves a data entry form or survey page **through the UI**. Verified by
grepping the core; the hook's own documentation says the same ("whenever the user/participant clicks
the Save/Submit/Next Page button").

It therefore does **not** fire for:

- Data Import Tool / CSV imports
- the REST API
- other modules' `REDCap::saveData()` calls

Those paths need `backfill-study-group-arms.php` (safe to re-run; schedule it as a cron if you want
import coverage to be automatic). This is why the logic lives in a public method rather than inside
the hook.

## Verification (2026-08-19)

12/12 against real project data:

| Case | Result |
|---|---|
| `study_group = 2` | data rows in arms `[1,2]`; `redcap_record_list` agrees |
| `study_group = 3` | data rows in arms `[1,3]`; `redcap_record_list` agrees |
| `study_group = 1` (SC) | stays in arm `[1]` only — no arm-2/3 rows |
| **SC participant** | **no `mica_ed_session` link** at event 1008 (the property that matters) |
| groups 2 and 3 | link *does* exist at events 1008 / 1012 |
| blank allocation | no arm materialized |
| re-save | idempotent — `already-present`, arms unchanged, no errors |

Backfill: dry-run reported `would-materialize` correctly without writing; the real run materialized;
an immediate re-run reported everything `already-present`. Each materialization writes an audit row
(`record added to its randomized arm`, with record / study_group / arm / event_id).

## Operational caveats

1. **Deleting a multi-arm record leaves it in the other arm's dashboard.**
   `Records::deleteRecord()` clears the record-list cache for **one** arm (its `$arm_id` parameter),
   so a record that spans arms keeps a phantom row elsewhere with no data behind it. Evidence in this
   instance: `ZZTEST2`, `ZZTEST3`, `ZZTEST4` sit in `redcap_record_list` with no data rows at all.
   `manual-test-auth.php`'s teardown has been fixed to clear every arm; **do the same in any other
   delete path**, via `Records::deleteRecordFromRecordListCache($pid, $record, $arm)`.
2. **`apply-study-group-field.php` only works in development status.** It writes `redcap_metadata`
   directly, which production ignores in favour of the draft/approval workflow. The script checks the
   status and aborts rather than appearing to succeed. In production, add the field via the Designer
   or a Data Dictionary import with the same name, type and coded values.
3. **The value must equal the arm number.** That convention is the whole mapping; if arms are ever
   renumbered, the coded values must be renumbered with them.

## Turning it on

1. `php apply-study-group-field.php <pid>` (development) or add `study_group` via the Designer.
2. Tick **"Automatically add the record to its randomized arm"** in the MICA module's project
   settings. Optionally point **"Allocation field"** at a different field.
3. `php backfill-study-group-arms.php <pid> --dry-run`, then without the flag, to place records that
   were randomized before the hook was switched on.
