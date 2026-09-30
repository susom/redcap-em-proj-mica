# A copied project randomizes nobody, and says nothing about it

**Found 2026-09-22 on PID 271**, from the report *"why can't I access the MICA session on
localhost"*. Fixed the same day.

| | |
|---|---|
| Symptom | A participant finishes the whole battery and lands on a page reading *"Thank you. Someone from the study team will be with you shortly to continue."* No error anywhere. |
| Root cause | **`redcap_randomization_allocation` does not travel with a project copy.** PID 271 arrived from prod with the randomization *setup* intact and **zero allocations**. |
| Fix | Load a development allocation table — [`../phase-3-handoff/scripts/load-dev-allocation-table.php`](../phase-3-handoff/scripts/load-dev-allocation-table.php) |

---

## Why the message looks like a bug and is not

The page is the module's own [`pages/sessionHandoff.php`](../../pages/sessionHandoff.php) with
`state=pending`. It is the deliberate landing place for *"the chain finished and there is no
session to send you to"*, and its wording is vague on purpose: saying "you have no MICA session"
would tell the participant they are in the control arm. Two states, neither naming an arm —
`pending` ("someone will be with you") and `done` ("you have finished this part of the study").

So the message was correct. It was reporting, accurately, that the record was never randomized.

## The chain, end to end

```
allocation table empty
  -> `tsr` save fires the trigger, REDCap finds no allocation, writes no study_group
    -> EdSessionLink::resolve() returns NOT_RANDOMIZED          (classes/EdSessionLink.php:120)
      -> MICA.php:908 maps that to state = 'pending'
        -> writeSessionUrl() stores the handoff URL in `ed_session_url`
          -> the survey's "Redirect to a URL" pipes that field
            -> participant reads "someone will be with you shortly"
```

`ed_session_url` is never left empty on purpose: REDCap tests a redirect template *before* piping
(`Surveys/index.php:1833`), so an empty value still reaches `redirect('')` — measured as a 302 with
an empty `Location:` and a zero-byte body. A blank screen instead of a sentence.

## What was and was not present on 271

Everything except the allocations was correct, which is what makes this hard to spot:

| | PID 268 (working) | PID 271 (broken) |
|---|---|---|
| `redcap_randomization` row | `rid=7`, `study_group` @1089 | `rid=8`, `study_group` @1104 ✅ |
| Trigger | `trigger_option=2`, `tsr`, `[calc_screen_result]=1` | **identical** ✅ |
| `redcap_projects.randomization` | 1 | 1 ✅ |
| **`redcap_randomization_allocation`** | **60 rows** | **0 rows** ❌ |

Record 38 had `calc_screen_result=1` and `tsr_complete=2` — it satisfied the trigger perfectly.
There was simply nothing to allocate.

## The fix, as applied

```bash
php load-dev-allocation-table.php 271 dev-allocation-table-MICA-ONLY.csv          # dry run
php load-dev-allocation-table.php 271 dev-allocation-table-MICA-ONLY.csv --apply
```

60 allocations, 30 × group 2 (MICA) and 30 × group 3 (MICA + Weekly SMS). MICA-ONLY was chosen over
the balanced 1:1:1 table so that every test record lands on a real session — it contains no
Standard Care allocations and is **not a valid trial schedule**, which the file says in its own
comment rows. Swap in `dev-allocation-table-TESTING-ONLY.csv` to exercise the arm split.

The script refuses to run on a project whose status is not Development, and refuses to append if
unused allocations already exist (that would silently change the ratio).

### Verified end to end

Record 38 was already stuck — it had completed the survey before any allocations existed. After
loading the table:

| Step | Result |
|---|---|
| `Randomization::randomizeRecord(8,'38')` | claimed `aid 121`, group **3**; 59 unused remain |
| `study_group` written at event 1104 | by SQL — `Records::saveData()` refuses writes to a randomization target field at its target event (`Records.php:6783-6789`) |
| **Browser save of `admin`** | module log: *"record added to its randomized arm"*, *"ED session link stored"* |
| Record arms | `1` → **`1,3`** |
| `ed_session_url` | handoff page → `http://redcap.local/surveys/?s=c7Euy34jXemHXXuY` |
| That link | hash resolves to **`mica_ed_session` at event 1112**, record 38 — HTTP 200, title *"MICA ED session"* |

`EdSessionLink::resolve()` checked directly for each arm, which is the behaviour to expect from now
on:

```
study_group=1 -> no-session-in-arm  (Standard Care - correct, there is no session)
study_group=2 -> resolved, event 1108
study_group=3 -> resolved, event 1112
```

## Two things this exposed that are worth knowing

### The module's save hook does not run on `Records::saveData()`

Three separate API saves on record 38 produced no log line, no arm materialization and no link.
The same record saved once through the browser did all three. So anything that depends on
`redcap_save_record` — arm materialization, the session link — **cannot be driven or tested
through the API on this project**. Same family as finding O7 for alerts. Use a browser.

### 35 of 271's records still carry production URLs

`ed_session_url` was copied in with the data and points at the live server:

```
https://redcap.stanford.edu/api/?...&pid=35968&state=pending
```

| Host | Kind | Count |
|---|---|---|
| `redcap.stanford.edu` | real survey link | 32 |
| `redcap.stanford.edu` | handoff page | 3 |
| `redcap.local` | (record 38, after the fix) | 1 |

Clicking any of those on localhost leaves the instance entirely. They are rewritten as each record
is next saved through the browser, but until then every pre-existing record's session link is
wrong. Not worth a bulk fix on a test project — just don't trust an old record's link.

## The same message on production — a different cause

Reported on PID 35968 on 2026-09-22, right after a code deploy. **The deploy did not cause it.**
`pages/sessionHandoff.php` arrived in commit `d8535d3`; before that, this exact condition produced
`redirect('')` — a 302 with an empty `Location:` and a zero-byte body. The message replaced a
blank screen, so a deploy that introduces it is *surfacing* a pre-existing problem, not creating
one.

On a production-status project the likeliest cause is not an empty table but the **wrong one**:

```sql
-- Randomization.php:2173
inner join redcap_projects p on ra.project_status = p.status
```

REDCap only ever reads allocations whose `project_status` matches the project's **current**
status. A project promoted from Development to Production therefore stops seeing every allocation
uploaded while it was in Development. If no *production* table was uploaded, it randomizes nobody
— silently.

Run [`../phase-3-handoff/scripts/diagnose-session-handoff.php`](../phase-3-handoff/scripts/diagnose-session-handoff.php)
— read-only, safe on prod:

```bash
php diagnose-session-handoff.php 35968            # project-wide
php diagnose-session-handoff.php 35968 <record>   # one participant
```

It prints the allocation table split by status, marks which set REDCap actually reads, counts how
many records already point at the handoff page, and names the cause. Verified against a simulated
status mismatch on 271, so the detection is tested and not merely written.

| What it reports | Fix |
|---|---|
| allocations exist only in the **other** status set | upload an allocation table **while the project is in its current status** |
| the live set is **exhausted** (0 unused) | upload more — every new participant fails from that point on |
| **no** allocation rows at all | upload one (this is the PID 271 case above) |
| record fails the **trigger logic** `[calc_screen_result]=1` | not a randomization fault; the record was never eligible |
| `no-session-in-arm` | Standard Care — correct, and it should show the *"you have finished"* wording rather than *"pending"* |

Production allocation tables come from the study statistician — **never** load
`dev-allocation-table-*.csv` there. The loader script refuses on any non-Development project for
exactly this reason.

## The general point

**`redcap_randomization_allocation` is one more thing that does not travel**, alongside
`twilio_modules_enabled` (alerts prereq P1), SecureChatAI's model registry
([`../phase-3-handoff/28-model-alias-not-registered.md`](../phase-3-handoff/28-model-alias-not-registered.md))
and External Module configuration. Any clone needs its own table before a single participant can
be randomized — and, on a status change, a *fresh* table for the new status.
