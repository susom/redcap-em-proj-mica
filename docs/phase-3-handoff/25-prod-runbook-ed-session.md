# Production runbook — ED Day-1 session handoff

Everything done to **PID 257 (localhost, development)** on 2026-08-28, in the order it must be
redone on a production project. Written so it can be followed without reading
[`24-ed-session-handoff.md`](24-ed-session-handoff.md), which carries the *why*.

Substitute your production project id for `<PID>` throughout. PID 257 is a development project
(`status = 0`); production is a different project and several steps behave differently there — those
differences are called out at each step rather than collected at the end.

---

## 0. What is code and what is project configuration

Only the second list has to be repeated per project.

**Shipped with the module — deploy once, applies everywhere.** Nothing to do per project.

| | |
|---|---|
| `classes/EdSessionLink.php` | resolves allocation → arm → host instrument → event |
| `MICA.php::ensureEdSessionLink()` | mints the link and writes it |
| `pages/sessionHandoff.php` | the page a participant lands on when there is no session |
| `config.json` | the three new settings and the `no-auth-pages` entry |

**Per project — this runbook.** A data dictionary field, one survey setting, up to three module
settings, and the randomization setup.

> The read-only SafetyScan prompt panel added earlier in the same release needs **no project
> changes at all** — it is entirely module code. Do not go looking for any.

---

## 1. Preconditions — check before starting

Nothing below is created by this runbook; if any is missing, stop and fix that first.

| Check | Expected on `<PID>` |
|---|---|
| Module version deployed | the release containing `classes/EdSessionLink.php` |
| Module enabled on the project | yes |
| Chat host instrument | `mica_ed_session` exists and is designated to the Day-1 event of **each intervention arm** (arms 2 and 3 on the R01 structure) — and to **no event of the control arm** |
| Allocation field | `study_group`, coded so the value **is** the arm number (`1` SC / `2` MICA / `3` MICA + SMS) |
| Module setting | *Automatically add the record to its randomized arm* is **on** |
| The Day-1 battery | you know which survey ends it — on 257 the structure points at `tsr` (see step 3) |

```bash
# Fastest precondition check - it reports each link in the chain separately.
docker exec <web> php .../scripts/verify-ed-session-link.php <PID>
```

Before any changes it will fail on the field, the redirect and randomization. That is the expected
starting state, and re-running it after each step below is the intended way to work through this.

---

## 2. Add the `ed_session_url` field

### 2.1 The exact definition

| Property | Value |
|---|---|
| Variable name | `ed_session_url` |
| Form | `admin` |
| Field type | Text Box |
| Field label | `ED Day-1 session link (auto-filled at randomization)` |
| Field note | `Written by the MICA module when the record is randomized. Read-only: a survey redirect follows this value. Standard Care records correctly stay blank.` |
| Validation | **none** |
| Required | **No** |
| Identifier | **No** |
| Field Annotation | `@HIDDEN-SURVEY @READONLY` |
| Position | immediately **after** `study_group`, before `admin_complete` |

Three of those are load-bearing, not preference:

- **`@READONLY`** — a survey redirect follows whatever is in this field. A hand-edited value sends a
  participant somewhere nobody chose.
- **Identifier = No** — identifier fields can be excluded from piping. The redirect *is* a pipe.
- **No validation** — a URL is not one of REDCap's validation types; setting one makes the module's
  own write fail validation.

`@HIDDEN-SURVEY` matches the project's convention for module-written fields (`calcrnd`, `rnd`,
`calc_code_check`) and is belt-and-braces on a form that is not a survey.

### 2.2 On production, use the Designer or a Data Dictionary import

**Do not use `apply-ed-session-url-field.php` on production.** It writes `redcap_metadata` directly,
which only takes effect in development status — the script checks the project status and aborts on
anything else rather than appearing to succeed.

Either add the field in the **Online Designer**, or import this row via *Data Dictionary → Upload*:

```csv
"Variable / Field Name","Form Name","Section Header","Field Type","Field Label","Choices, Calculations, OR Slider Labels","Field Note","Text Validation Type OR Show Slider Number","Text Validation Min","Text Validation Max","Identifier?","Branching Logic (Show field only if...)","Required Field?","Custom Alignment","Question Number (surveys only)","Matrix Group Name","Matrix Ranking?","Field Annotation"
"ed_session_url","admin","","text","ED Day-1 session link (auto-filled at randomization)","","Written by the MICA module when the record is randomized. Read-only: a survey redirect follows this value. Standard Care records correctly stay blank.","","","","","","","","","","","@HIDDEN-SURVEY @READONLY"
```

A Data Dictionary upload **replaces the whole dictionary**, so export the current one first and add
this row to it — do not upload the single row above on its own.

On a production project both routes enter **Draft Mode**: the change is staged, then *Submit for
Review*, then an administrator approves it. Nothing takes effect until approval. Adding one optional
text field with no validation is normally auto-approvable, but that is your admin's call.

### 2.3 The field's existence is the switch

There is no separate checkbox. `MICA::ensureEdSessionLink()` returns `no-field` and does nothing
until this field exists, and starts working the moment it does — on the next save of any form or
survey for a record.

---

## 3. Point the end of the ED battery at it

*Online Designer → **the survey that ends the Day-1 battery** → Survey Settings → Survey Termination Options →
**Redirect to a URL***:

```
[ed_session_url]
```

Bare, **no event prefix**. The module writes the value to the earliest event that hosts the `admin`
form — arm 1 Day 1 on the R01 structure — which is the same event the screening surveys run at, so
the pipe resolves without one.

Two settings on that same survey must stay **off**, and they are hard requirements of
`Surveys/index.php:1822`, not style. If either is on the redirect silently never fires:

| Setting | Required |
|---|---|
| Auto-continue to next survey | **off** |
| Save & Return Later | **off** |

Survey settings are **not** part of the data dictionary draft — they apply immediately, even in
production. This step needs no approval.

> **Which survey is that?** The Day-1 battery runs `… ddq → audit → sip2r → phq → bscq → drug_use →
> tsr`, and on the intervention arms `postsession` (CEMI post-session, arms 2/3 only) follows the
> chat — so the structure places the session after **`tsr`**. Note `tsr` is designated to all twelve
> events while `ed_session_url` lives on `admin` (Day-1 events only), so the pipe resolves empty at
> Month 3/6/12 — see [`24-ed-session-handoff.md`](24-ed-session-handoff.md) §5.1.

---

## 4. Module settings

*External Modules → MICA → Configure.* All three are optional; the middle one is not optional in
practice if you are automating randomization.

| Setting | Set to | Effect if left blank |
|---|---|---|
| Field that receives the ED Day-1 session link | *(leave blank)* | defaults to `ed_session_url` — correct |
| **Stamp the randomization date into this field** | **`randomization_date`** | **off — see below** |
| Wording shown while a participant waits | study's approved sentence, or blank | a built-in sentence |

**The middle one matters more than it looks.** Automating randomization takes the CRC off the form
that used to carry `randomization_date` by hand, and that date anchors **every reminder ladder,
alerts 02–14** (13 references in `docs/alerts/MICA_257_alerts_import.csv`). Leave it blank with
randomization automated and those alerts have no anchor.

The module writes it **only when the field is empty across every event**, so a date a human recorded
on any arm's `admin` form is never overwritten and never duplicated.

The waiting message is the only participant-facing wording that is configurable. The message shown
when a participant has **no** session is deliberately fixed: it is a generic completion sentence,
because saying anything more specific would tell a Standard Care participant which arm they are in.

---

## 5. Randomization

This is the only step that needs an artifact you have to produce — the allocation table — and the
only one with a decision that is expensive to get wrong.

### 5.1 Model — get this right the first time

*Project Setup → Randomization module → enable → Randomization setup.*

| Setting | Value |
|---|---|
| Randomization field | `study_group` |
| **Target event** | **Day 1 (ED), arm 1** — the event every record passes through before randomization |
| Stratification | the study's choice (site, sex, …) — affects the allocation table, not this handoff |

**The model is effectively frozen once production randomization begins.** Erasing a setup
(`Randomization::eraseRandomizationSetup`) requires development status or a super user. Assume you
get one attempt.

### 5.2 Trigger — the one that fails silently

*Same page → the real-time execution option.*

| Setting | Value |
|---|---|
| Trigger instrument | `baseline1` ("Demographics & BL Data") |
| Trigger event | Day 1 (ED), arm 1 |
| **Trigger option** | **"Trigger logic, for all users (including survey respondents)"** |
| Trigger logic | see below |

> ### Option 2, never option 1
>
> `Randomization.php:3112`:
> ```php
> if ($randAttr['triggerOption']==1 && ($user_rights['random_perform']!=1 || $isSurveyPage)) continue;
> ```
> Option 1 is **skipped on survey pages**, and skipped again for anyone without Randomize rights. A
> participant is both. So option 1 randomizes perfectly when a CRC saves the form and does
> **absolutely nothing** when a participant submits the survey — no error, no log line, no clue.
> `verify-ed-session-link.php` §3 fails loudly on option 1 for exactly this reason.

Unlike the model, **the trigger option can be changed later in production**
(`Randomization::saveRealtimeOption()` is a plain UPDATE with no status guard). So this one is
recoverable if you get it wrong.

**Trigger logic.** The trigger is evaluated on *every save* of that instrument, so "when completed"
has to be in the logic:

- `[baseline1_complete] = '2'` is the natural expression — **but test it.** The trigger runs at
  `DataEntry.php:6710`, and whether the form-status field already holds `2` in the data at that
  moment has not been confirmed on this project.
- `[phonen] <> ''` is a field-based alternative that does not depend on that timing.

Test whichever you pick on a throwaway record before opening enrolment.

### 5.3 Allocation tables

REDCap keeps **separate allocation tables for development and production**. Promoting the project
does not carry the development table across — upload the production one explicitly. With no free
allocations the trigger runs, logs *"no available allocations"*, and the participant gets the waiting
page. `verify-ed-session-link.php` counts the remaining allocations for the project's current status.

---

## 6. The sequence in one request, once it is all in place

Worth knowing so a failure can be located:

```
participant submits "Demographics & BL Data" (baseline1, arm-1 Day 1)
  └─ DataEntry::saveRecord()
       ├─ :6710  REDCap randomizes → writes study_group to the target event
       └─ :6735  redcap_save_record fires
                  ├─ ensureRecordInAssignedArm()  → record appears in arm 2 or 3
                  └─ ensureEdSessionLink()        → mints the link → ed_session_url
… participant continues through the Day-1 battery to its last survey
       └─ redirect pipes [ed_session_url] → their own session (Survey Login prompt)
```

**Expect the Survey Login prompt.** `mica_ed_session` requires login (`phonen` at the arm-1 Day-1
event) and none of the screening surveys do, so the participant is asked for the phone number they
typed into `baseline1` minutes earlier. Verified in a browser; it is a design decision, not a fault.
If it is not what the study wants, the alternative is exempting the ED session from survey login,
which weakens the gate.

---

## 7. Verify on production

```bash
docker exec <web> php .../scripts/verify-ed-session-link.php <PID>
```

Exit 0 and six green sections. It checks the field and its `@READONLY`, which event the module
writes to, the host map and which arms designate the session, the randomization setup **including
the trigger option**, the redirect and both guards it depends on, every record's stored value, and
that no session link exists at an event that does not host the session.

Then one live record end to end:

| Check | Expected |
|---|---|
| Enrol a test participant through the public link to the end of `baseline1` | `study_group` allocated in the same request, no CRC action |
| `admin` form | `ed_session_url` populated; `randomization_date` non-empty |
| The link | points at the **assigned arm's** Day-1 event — verify arm 3 too, not only arm 2 |
| Finish the chain | lands on the ED session's login prompt |
| A Standard Care allocation | `ed_session_url` holds the **handoff page**, never a session link |
| `redcap_surveys_participants` | no `mica_ed_session` row at any event that does not host it |

That last row is worth running deliberately. `REDCap::getSurveyLink()` does **not** check that an
instrument is designated to the event it is given (`REDCap.php:1740-1745`) — it checks only that the
record exists in that event's arm. A link minted at a control arm's event therefore looks valid and
would hand a control participant the intervention. The verifier checks the database for these.

### Existing records

Records randomized before this was deployed have an empty field. The hook fills it on their next UI
save; to do them all at once:

```bash
docker exec <web> php .../scripts/backfill-study-group-arms.php <PID> --dry-run
docker exec <web> php .../scripts/backfill-study-group-arms.php <PID>
```

Also required for anything randomized by **API or data import** — `redcap_save_record` fires only on
UI saves and survey submits, so those records land in the right arm with an empty field.

---

## 8. Rollback

| To undo | How |
|---|---|
| The whole feature | Rename or delete `ed_session_url`. The module returns `no-field` and does nothing. Clear the survey's Redirect to a URL in the same change, or the redirect pipes a field that no longer exists. |
| Just the redirect | Clear *Redirect to a URL* on whichever survey carries it. The field keeps being written and the CRC can still copy it from the `admin` form. |
| The date stamping | Blank the *Stamp the randomization date* setting. Dates already written stay. |
| Automatic randomization | Set the trigger option back to *Manual only*. The model stays; a CRC clicks **Randomize** and everything downstream still works. |

Clearing the redirect and removing the field are **not** independent — do them together, in that
order. A redirect piping a deleted field pipes an empty string, and REDCap tests the redirect
template before piping, so it still reaches `redirect('')`: a `302` with an empty `Location` and a
blank page.

---

## 9. Exactly what changed on PID 257

For diffing against production later.

| Change | Value |
|---|---|
| `redcap_metadata` | one row: `ed_session_url` on `admin`, `text`, annotation `@HIDDEN-SURVEY @READONLY`, `field_order` 267 (between `study_group` 266 and `admin_complete` 268) |
| `redcap_surveys` | **nothing** — a redirect was set on `sms_code_check` during testing and has been reverted. Choosing the survey that carries it is step 3. |
| Module settings | none changed — all three new settings are blank on 257, i.e. defaults, with the date stamp therefore **off** |
| Randomization | **not configured on 257** (`randomization = 0`). Production still needs §5 in full. |
| Record data | `ed_session_url` populated on records 1–6 and `MICATEST01` by running the backfill |

Two changes on 257 were **testing artifacts** and must not be reproduced: `MICATEST01`'s
`sms_code_check` response was reopened (completion time cleared, `sms_code_check_complete` deleted),
and its `study_group` was set and cleared several times.
