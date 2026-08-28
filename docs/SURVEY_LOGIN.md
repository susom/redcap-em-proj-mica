# MICA session survey login — `last_name` → `phonen`

**Applied on localhost PID 257: 2026-08-27. Event corrected 2026-08-28. Not yet on prod.**

Backup of the pre-change project row:
`scratchpad/257_projects_pre_survey_auth_change.sql`

---

## ⚠️ READ THIS FIRST — 2026-08-28 correction

**Final configuration: `phonen` at event `1004` (arm-1 Day 1).** Only the *field*
changes from the original; the *event* stays where it always was.

Sections below still contain the 2026-08-27 reasoning that moved the event to **1008**
and concluded arm 3 could not be supported. **That reasoning was wrong**, and is kept
only because the code tracing in it is still accurate and useful. What it got wrong:

| 2026-08-27 assumption | Reality |
|---|---|
| "a participant's contact data lives at **their own arm's** Day 1 event" | No. Participants enter via the **public survey link** on `pre_screen`, so the whole pre-randomisation chain — including `baseline1` — is written at **event 1004**, whichever arm they are later assigned to. |
| "there is no event shared across arms" | **1004 is** effectively shared: every record passes through it before randomisation. |
| "arm 3 will be locked out" | **Not any more.** One slot at 1004 covers arms 2 and 3. |

What happens at randomisation is the key detail: the module writes **only
`study_group`** into the assigned arm's first event (`MICA.php:715`). `phonen` is
never copied there. So pinning the credential to 1008 pointed it at an event that
structurally never holds the value.

**Verified 2026-08-28** with record 6 — shaped like a real participant, `phonen` at
1004 only, arm-2 event containing just `study_group`: login prompt rendered, phone
number accepted, chat mounted, no `survey_589` "no data" error.

The **phone-format** caveat (§2 below) is unaffected and still stands.

Full flow and verification table:
[`phase-3-handoff/22-minimum-test-path.md`](phase-3-handoff/22-minimum-test-path.md).

---

## The change

REDCap's **Survey Login** feature (project-level, `redcap_projects`) gates specific
surveys behind one or more participant-supplied fields.

| Setting | Before | After |
|---|---|---|
| `survey_auth_field1` | `last_name` | **`phonen`** |
| `survey_auth_event_id1` | `1004` (arm 1 Day 1) | **`1004` — unchanged after all; see the 2026-08-28 correction below** |
| `survey_auth_enabled` | 1 | 1 (unchanged) |
| `survey_auth_min_fields` | 1 | 1 (unchanged) |
| `survey_auth_apply_all_surveys` | 0 | 0 (unchanged) |
| `survey_auth_fail_limit` / `_window` | 5 / 30 min | unchanged |

It applies to exactly two surveys, both with `survey_auth_enabled_single = 1`:

| survey_id | form |
|---|---|
| 1315 | `mica_ed_session` |
| 1316 | `mica_booster_session` |

## ~~Why the event had to change too~~ — SUPERSEDED (see the 2026-08-28 correction)

*The code tracing here is accurate; the conclusion it reaches is not.*

The event was **not** an incidental detail. `survey_auth_event_id1` is read
literally — `Surveys/index.php:1476` compares the submitted value against
`$survey_login_data[$record][$fieldEvent['event_id']][$fieldEvent['field']]`, i.e.
the value at **that one configured event**. The only fallback
(`Survey.php:2244`) fires when the event id doesn't exist at all.

The old config pointed at event **1004 = arm 1 Day 1**. But the MICA session
surveys don't exist in arm 1:

| Form | Designated at |
|---|---|
| `mica_ed_session` | **1008** (arm 2), **1012** (arm 3) |
| `mica_booster_session` | **1009** (arm 2 M3), **1014** (arm 3 M3) |

So the login was pinned to an arm that never takes these surveys. An arm-2
participant's `last_name` lives at 1008, and REDCap was looking at 1004 — where they
have no data at all.

---

## ~~Verified working for arm 2 — 2026-08-27~~ — superseded by the 2026-08-28 verification

*This test passed only because `phonen` had been hand-copied to event 1008 on record 3. A real participant never has it there.*

Fetched a real `mica_ed_session` link for record 3 at event 1008 (the link alert 02
sent, `?s=Skrmq2fcygCiNbuA`):

| Check | Result |
|---|---|
| HTTP status | **200** |
| Page title | *"MICA ED session"* |
| `survey_login_dialog` rendered | **yes** |
| Login input name | **`phonen`** — the change is live |
| `#pagecontainer { display:none }` | **yes** — survey content gated behind login |
| `survey_589` "no data for the required fields" error | **absent** — a value exists at 1008 |

This works because record 3 has `phonen` at event **1008**. It is exactly the
condition that fails for arm 3 — see below.

**Decision 2026-08-27: keep this configuration as-is.** The two caveats below are
documented and accepted, not resolved.

---

## Two problems raised on 2026-08-27 — the first is now RESOLVED, the second still stands

### 1. One event slot cannot cover both arms

`survey_auth_event_id1` is a single project-wide value, but a participant's contact
data lives at **their own arm's** Day 1 event. There is no event shared across arms
in this design — `baseline1` is designated at Day 1 of all three arms, and each
participant only ever populates one of them.

So whichever event is chosen, one arm is wrong:

| `survey_auth_event_id1` | arm 2 | arm 3 |
|---|---|---|
| 1004 (the old value) | ❌ broken | ❌ broken |
| **1008 (applied now)** | ✅ works | ❌ broken |
| 1012 | ❌ broken | ✅ works |

**Superseded 2026-08-28 — the answer is 1004, and arm 3 is no longer a problem.**
See the correction section below.

What happens to the broken arm, traced through the code: `Survey.php:2041-2056`
removes a login field whose event is on an arm where the record doesn't exist, and
then `return false` — the comment says the intent is *"do not force login"*. But the
caller destructures it as `list($loginFormHtml, $loginFields) = false`
(`Surveys/index.php:1576`), which in PHP 8.3 yields **`null`, not `false`** (verified
in the container). The guard `if ($loginFormHtml !== false)` therefore passes, and
the page renders `#pagecontainer { display:none; }` plus an **empty login dialog**.
Net effect: the participant sees a blank dialog and **cannot reach the survey.**

*Using the 3 auth slots for the 3 arms does not work as a workaround*: the login
input is rendered with `'name' => $survey_auth_field_variable` (`Survey.php:2125`),
so two slots holding `phonen` produce two inputs with the **same HTML name** and
`$_POST['phonen']` keeps only the last one.

**Options, in order of recommendation:**

1. **Drop survey login on these two surveys and rely on the per-participant survey
   link.** The links sent by alerts 02/03 are already unique per participant and
   instance and are not guessable. This is what most studies do, works for every arm,
   and removes the failure mode entirely.
2. **Keep login, accept it works for one arm only** — fine if arm 3 enrollment
   hasn't started, but it will silently lock arm-3 participants out.
3. **Add a shared enrollment event** outside the per-arm Day 1 events and designate
   the contact form there. Correct, but an event-structure change to a running study.

### 2. Phone numbers are compared as exact strings

The comparison is `strtolower($_POST[...]) === strtolower($stored)`
(`Surveys/index.php:1476`). The only normalisation anywhere in the path is a
date-format conversion for date/time fields (`:1455-1463`). **There is no phone
normalisation.**

The `phone` (North America) validation regex accepts all of these as valid:

```
(650) 555-0123      650-555-0123      6505550123      650.555.0123
```

Record 3 is stored as `(650) 555-0123`. A participant who types `6505550123` — a
perfectly valid entry that passes the field validation — **will be denied**, and
after 5 tries in 30 minutes they are locked out (`survey_auth_fail_limit`).

> **The number above is redacted.** Record 3's real `phonen` on localhost was a working
> line that received the test SMS; since this repository is public and `phonen` *is* the
> survey login credential, it is replaced throughout with `(650) 555-0123` (reserved
> fictional range). What matters here is the four formats, not the digits. Record 6 in
> [`phase-3-handoff/22-minimum-test-path.md`](phase-3-handoff/22-minimum-test-path.md)
> uses `(650) 555-0199` and is a different record — do not conflate them.

`last_name` did not have this problem: `strtolower()` makes it case-insensitive and
there is only one way to spell a name.

**Mitigations:**
- Put the expected format in the field's `element_note` — it is displayed on the
  login form (`Survey.php:2103-2109`), so it's the natural place to say
  *"Enter as (XXX) XXX-XXXX"*.
- Or normalise stored values so everyone matches one canonical format, and say so on
  the form.
- Or use a field with a single unambiguous representation instead.

---

## Applying it on production

### 🔴 Not carried by the Data Dictionary

These are `redcap_projects` columns, not `redcap_metadata` — so this joins the same
list as the Twilio scope and the `calc_eligible` consent gate. See
[`alerts/PROD_PROMOTION.md`](alerts/PROD_PROMOTION.md).

### Where the setting actually lives

Traced in source rather than guessed (an earlier version of this doc said
*"Project Setup → Survey Login"*, which does not exist):

> **Project → Designer (Online Designer) → the orange `Survey Login` button**

It sits in the button row above the instrument list, between *Survey Notifications*
and the auto-invite controls (`Design/online_designer.php:1527`, label
`survey_573 = "Survey Login"`). It opens a **modal dialog**, not a page —
`showSurveyLoginSetupDialog()` in `Resources/js/DesignForms.js:815` POSTs to
`Design/survey_login_setup.php`. A green tick on the button means survey login is
already enabled. It requires the **Project Design and Setup** user right
(`UserRights.php:288`).

### Steps (UI)

1. Prod PID → **Designer** → **`Survey Login`** button.
2. Top row — leave **"Enable survey login?"** on **Enabled**.
3. First field dropdown (`survey_auth_field1`): change from **`last_name`** to
   **`phonen`**. The list is grouped by instrument, so look under *Enrollment*
   (`baseline1`).
4. ✅ **Leave the event alone.** Immediately right of the dropdown is a selector
   labelled **"from event"**, currently set to **arm-1 `Day 1 (ED)`**. That is
   **correct** — it is the pre-randomisation event where the public-link chain writes
   `baseline1`, and it is the only event every record passes through regardless of
   assigned arm. *(An earlier version of this doc told you to change it to the arm-2
   event. Don't — that points the credential at an event which only ever receives
   `study_group`, and the login then finds nothing.)*
5. Leave **"You must successfully enter at least N out of the N fields below"** at
   **1**, and do **not** use the green *add field* link — a second slot is what
   creates the empty-credential bypass described in §"the one result that matters
   most" of [`phase-3-handoff/11-auth-manual-test-guide.md`](phase-3-handoff/11-auth-manual-test-guide.md).
6. Leave **"Apply to all surveys"** **off** — the login must stay scoped to
   `mica_ed_session` and `mica_booster_session` only.
7. Leave the lockout at **5 failures / 30 minutes**.
8. **Save**.

Then confirm the two per-survey toggles are still set: the "apply to this survey"
flag is `redcap_surveys.survey_auth_enabled_single`, edited in each survey's own
settings (`Surveys/edit_info.php:180`), not in this dialog.

### Which fields appear in the dropdown

`Survey::getTextFieldsForDropDown()` (`Survey.php:2202`) filters on **only** two
things: `element_type == 'text'`, and not the record-ID field. **There is no
validation-type requirement**, so `phonen` appears regardless — migration step
**P7** is *not* a prerequisite here. (P7 matters for the *alerts* recipient list,
`getPhoneFieldsList()`, which is a different function with a stricter filter. An
earlier version of this doc conflated the two.)

One real constraint, shown as a notice in the dialog itself
(`survey_1294`): *"Fields existing on repeating instruments/events will not work as
login fields."* Checked — `baseline1` is not repeating at any event in PID 257 (only
`mica_safety_finding` is), so `phonen` is safe.

### Verify on prod

```sql
SELECT survey_auth_enabled, survey_auth_field1, survey_auth_event_id1,
       survey_auth_min_fields, survey_auth_apply_all_surveys,
       survey_auth_fail_limit, survey_auth_fail_window
  FROM redcap_projects WHERE project_id = <prod_pid>;
```

Expect `phonen` + the **arm-1 Day 1 (pre-randomisation)** event id,
`min_fields = 1`, `apply_all_surveys = 0`. `survey_auth_field2`/`field3` must stay
NULL.

Then walk one real participant's `mica_ed_session` link and confirm the prompt asks
for the phone number and accepts the **stored format**. Do it for an arm-2 **and** an
arm-3 participant — with the credential at 1004 both should now work.

### Verify on prod

Open a real arm-2 participant's `mica_ed_session` survey link and confirm the login
prompt asks for the phone number and accepts the **stored format**. Then confirm an
arm-3 participant — if arm 3 is enrolling, expect the blank-dialog failure described
above, which is the trigger to pick option 1 or 3.
