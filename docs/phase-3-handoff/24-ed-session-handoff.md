# Handing the participant to the ED Day-1 session

**The module side is implemented and verified on PID 257 (2026-08-28). What remains is REDCap
configuration that needs the study's allocation table — see §8.**

| | |
|---|---|
| `classes/EdSessionLink.php` | resolves arm → host instrument → event, framework-free; 21 unit tests |
| `MICA.php::ensureEdSessionLink()` | mints the link and writes it, from the existing `redcap_save_record` |
| `pages/sessionHandoff.php` | the two pages a participant lands on when there is no session (`no-auth`) |
| `ed_session_url` on `admin` | created by `scripts/apply-ed-session-url-field.php`; its existence is the switch |
| `scripts/verify-ed-session-link.php` | read-only check of all six links in the chain |
| `e2e/session-handoff.js` | 36 checks, desktop + iPhone 13 |

Revised 2026-08-28 after the decision to randomize on completion of the Demographics & BL survey
rather than as a later CRC action.

Two changes requested:

1. When the record is randomized and `study_group` is set, generate the **ED Day-1 session
   survey URL** and store it in a hidden field on the `admin` form.
2. When the **arm-1 surveys are finished**, redirect the participant straight into that
   ED Day-1 session.

---

## 0. The short version

**Randomizing on the BL survey makes this work, and REDCap already supports it natively —
no code is needed to trigger it.** REDCap has built-in auto-randomization triggers
(`redcap_randomization.trigger_option`), and the trigger fires *inside the same save*, 25
lines **before** the module's hook:

| | |
|---|---|
| `Classes/DataEntry.php:6710` | `Randomization::realtimeRandomization(...)` — allocates and writes `study_group` |
| `Classes/DataEntry.php:6735` | `Hooks::call('redcap_save_record', ...)` — the module's hook |

So on the participant's own `baseline1` submit, in one request and in this order:

```
participant submits "Demographics & BL Data" (baseline1 @ event 1004)
  └─ DataEntry::saveRecord()
       ├─ 6710  REDCap randomizes → writes study_group to the target event
       └─ 6735  redcap_save_record fires
                 ├─ ensureRecordInAssignedArm()  → record appears in arm 2 or 3
                 └─ NEW: mint mica_ed_session link → write ed_session_url
… participant continues to check_code → sms_code_check
       └─ redirect finds a populated ed_session_url
```

The ordering problem from the previous draft of this plan is therefore **resolved by your
workflow change**, not by engineering. What remains is three prerequisites (§2), one
dependency the change creates (§3), and the two builds themselves (§4, §5).

---

## 1. What constrains the design

Checked against PID 257 on localhost, not assumed.

| Fact | Where |
|---|---|
| `baseline1` is menu-named **"Demographics & BL Data"** — the "demo BL" survey | `redcap_metadata.form_menu_description` |
| The participant chain runs entirely at event **1004** and ends at `sms_code_check` | [`22-minimum-test-path.md`](22-minimum-test-path.md) §2 |
| `admin` exists at 1004 (arm 1), 1008 (arm 2), 1012 (arm 3) | `redcap_events_forms` |
| `mica_ed_session` exists **only** at 1008 (arm 2) and 1012 (arm 3) — never arm 1 | same |
| `study_group` codes are `1, Standard Care (SC)` / `2, MICA` / `3, MICA + Weekly SMS` — i.e. the arm numbers | `redcap_metadata.element_enum` |
| Arm materialization already runs from `redcap_save_record` | `MICA.php:757`, `:791` |
| `redcap_save_record` is fired from **exactly one place**, and it covers survey submits | `DataEntry.php:6735` (`$isSurveyPage ? $_GET['s'] : null`) |
| `REDCap::getSurveyLink()` **creates** the participant + response rows at issuance | `MICA.php:2683` comment |
| Survey login is scoped to `mica_ed_session` + `mica_booster_session` only; credential `phonen` @ 1004 | `redcap_surveys.survey_auth_enabled_single` |
| `mica_ed_session` link limit = **24 h** | `redcap_surveys` 1315 |
| `end_survey_redirect_url` supports piping, then a bare `redirect()` | `Surveys/index.php:1846-1851` |
| `@HIDDEN-SURVEY` is already the project convention | `calcrnd`, `rnd`, `calc_code_check`, `audit_score` |

### Why REDCap's `[survey-url:…]` smart variable cannot replace part 1 — and why it is worse than useless here

An earlier draft of this document said the smart variable "resolves empty". **That was wrong, and the
truth is worse.** Piped from an arm-1 survey on PID 257, `[survey-url:mica_ed_session]` returned a
perfectly ordinary-looking URL — and created a **new participant row at event 1004**, an event where
`mica_ed_session` is not designated:

| participant | hash | event | record |
|---|---|---|---|
| 2292 (from the smart variable) | `GAbfjPsE…` | **1004** — arm 1 | 6 |
| 2289 (the real one) | `zhpWrdLL…` | 1008 — arm 2 | 6 |

The cause is in `REDCap::getSurveyLink()` (`REDCap.php:1740-1745`). It checks that the instrument is a
survey **project-wide**, and that the record exists in the **arm of the event it was given**. It never
checks that the instrument is designated to that event. `Piping.php:1896-1912` leaves the event as the
*context* event when no prefix is given — 1004 for the participant chain — so the call succeeds and
mints there.

Follow that through for a **Standard Care** record: it exists in arm 1 by definition, so
`getSurveyLink($record, 'mica_ed_session', 1004)` returns a link and the control participant is handed
the intervention. An explicit prefix is no better: it hardcodes one arm.

So a single static string cannot branch on allocated arm, and the one that looks like it can quietly
breaks the control arm. Resolving the arm per record is the mechanism — and it is why
`EdSessionLink::resolve()` returns `no-session-in-arm` as an explicit outcome rather than letting
"no event found" fall through, and why `EdSessionLinkTest` asserts that a resolved event is always
inside the assigned arm. `verify-ed-session-link.php` §6 checks the database for exactly these stray
rows.

---

## 2. Prerequisites — randomization is currently OFF

`redcap_projects.randomization = 0` and there is **no `redcap_randomization` row for 257**.
`study_group` is a plain hand-filled radio today. Before any of this works:

### 2.1 Enable and set up randomization

*Project Setup → Randomization module → enable, then Randomization setup:*

| Setting | Value | Why |
|---|---|---|
| Target field | `study_group` | already coded 1/2/3 = arm numbers |
| **Target event** | **Day 1 (ED) — arm 1 (event 1004)** | §4.2 |
| Stratification | study's choice (site? sex?) | affects the allocation table, not this plan |
| Trigger instrument | `baseline1` | the demo/BL survey |
| Trigger event | **1004** | where the participant fills it |
| **Trigger option** | **"Trigger logic, for all users (including survey respondents)"** | see below |
| Trigger logic | see §2.2 | |

### 2.2 Trigger option 2, not 1 — this one will bite

`Randomization.php:3112`:

```php
if ($randAttr['triggerOption']==1 && ($user_rights['random_perform']!=1 || $isSurveyPage)) continue;
```

Option **1** ("for users with Randomize permission only") is **explicitly skipped on survey
pages**. A participant is not a user with randomize rights, so with option 1 nothing
happens and the whole chain silently fails. It must be option **2** — *"Trigger logic, for
all users (including survey respondents)"* (`random_204`).

Also note the trigger runs on **every save of that form**, not only on completion — the
"when completed" part has to be expressed in the trigger logic:

- `[baseline1_complete] = '2'` is the natural expression, **but verify it**: the trigger is
  evaluated at line 6710, and whether the form-status field is already `2` in the data at
  that point needs to be seen, not assumed.
- A safer alternative that does not depend on that timing is to gate on a field the
  participant actually fills on the last page, e.g. `[phonen] <> ''`.
- Test both. REDCap also offers `[is-survey]` / `[is-form]` smart variables inside trigger
  logic (`random_206`) if the behaviour should differ between the two.

The existing `randomize_trigger` field (`1, Randomize` / `0, Do not randomize yet`) is **not
read by the module** and has no branching logic. If a staff gate is still wanted, it is now
a natural part of the trigger logic rather than a decorative field.

### 2.3 Allocation tables

REDCap keeps **separate allocation tables for development and production**, and promoting to
production requires uploading the production table. Whoever owns the randomization schedule
needs to produce both. Nothing in this plan can proceed on a project with no allocations —
`Randomization.php` returns "no available allocations" and the trigger silently does nothing.

---

## 3. The dependency this change creates: `randomization_date`

**This is the part most likely to be missed.** `randomization_date` is a plain text field
with **no action tag**, typed by hand by the CRC today. It is the anchor for every reminder
ladder — 13 references in `docs/alerts/MICA_257_alerts_import.csv` and 12 in its generator,
covering alerts 02–14 ([`22-minimum-test-path.md`](22-minimum-test-path.md) §4 calls it
"the anchor for **every** reminder ladder").

If randomization becomes automatic on a participant survey submit, **no CRC is on the form
to type it**, so it stays empty and every ladder loses its anchor. Options:

- **`@DEFAULT="@TODAY"` / `@NOW` on the field** — simplest, but `@DEFAULT` only applies when
  the form is *rendered*, and under this workflow `admin` may never be opened. Verify before
  relying on it; it is likely insufficient here.
- **Write it in the same module hook** that writes `ed_session_url` — one save, one place,
  and it is then guaranteed to exist exactly when the allocation does. Recommended.
- **A REDCap action tag on a second field** (`@CALCDATE`-style) anchored on something already
  populated. Works, but adds a field whose meaning duplicates `randomization_date`.

Whichever is chosen, add it to §7's verification: *a randomized record has a non-empty
`randomization_date`.*

---

## 4. Part 1 — write the URL into `admin`

### 4.1 Create the field

On the `admin` form, after `study_group`:

| | |
|---|---|
| Field name | `ed_session_url` |
| Type | Text |
| Label | ED Day-1 session link (auto-filled at randomization) |
| Action tags | `@HIDDEN-SURVEY @READONLY` |
| Identifier | **No** |
| Validation | none |

`@READONLY` because it is module-written — a CRC editing it silently breaks part 2.
Add it in the Designer or via the data dictionary, **not** by SQL; REDCap caches metadata.

### 4.2 Write to event 1004, and say so in the code

**The URL goes to the arm-1 Day 1 `admin` (event 1004).** Three reasons, all worth a comment:

1. It is the randomization **target event**, so `study_group` and the URL live together.
2. It is where the CRC works, so the link appears on the form they already open.
3. It is the same event as `sms_code_check`, so part 2 pipes a bare `[ed_session_url]` with
   **no event prefix** and no arm dependency.

This is precisely the detail that caused the survey-login bug in
[`22-minimum-test-path.md`](22-minimum-test-path.md) §Findings — a credential written at 1008
and read at 1004. Do not leave it implicit.

### 4.3 Mint and store the link

In `MICA.php`, alongside the existing `ensureRecordInAssignedArm()` call in
`redcap_save_record`:

1. Read the allocation using the existing `study-group-field` setting and the
   first-non-empty-value scan at `MICA.php:805-816`.
2. Resolve the arm's ED host event from the **host instrument's own designation** for that
   arm. Do not hardcode 1008/1012; `SessionHostMap` already names `mica_ed_session`, so read
   the instrument from there and derive the event the way `getFirstEventIdForArm()` does.
3. `REDCap::getSurveyLink($record, $hostInstrument, $hostEventId)`.
4. `REDCap::saveData()` the result into `ed_session_url` at **event 1004** (plus
   `randomization_date` if §3's recommended option is taken).

Four things to get right:

- **Do not condition on `ensureRecordInAssignedArm()`'s return value.** It returns
  `'already-present'` and exits early on every save after the first, so records materialized
  before this ships — record 6, and anything from `backfill-study-group-arms.php` — would
  never get a URL. Condition on *"assigned arm known and `ed_session_url` empty"* so it
  self-heals on the next save.
- **`study_group = 1` must produce no URL.** Standard Care has no ED session and a link there
  would be a protocol breach, not a bug. Make that an explicit early return, not a
  side-effect of arm 1 having no host instrument.
- **Idempotent** — only write when empty or changed. `getSurveyLink()` returns a stable hash
  once the participant row exists, so a re-save should be a no-op, not link churn.
- **Reuse the `static $running` re-entry guard** (`MICA.php:762`) and the existing
  never-throw wrapper (`MICA.php:768-772`). This write is another `saveData()` that
  re-enters the hook.

### 4.4 Cover the non-UI paths

`redcap_save_record` fires on UI saves and survey submits only — not on API or data-import
writes. Add the same URL write to `backfill-study-group-arms.php`, or records randomized by
import sit in the right arm with an empty field.

---

## 5. Part 2 — redirect at the end of the arm-1 chain

### 5.1 Which survey carries the redirect is a project decision, not the module's

The module's job ends at the field. Where the redirect goes is set in REDCap and is yours to choose.

The Day-1 battery is longer than the screening chain: after `check_code` / `sms_code_check` come
`ddq`, `audit`, `sip2r`, `phq`, `bscq`, `drug_use`, `tsr`. On the intervention arms `postsession`
(CEMI post-session) sits after `tsr` and exists **only** on arms 2 and 3 — so the structure puts the
chat between the last assessment and the post-session questionnaire:

```
… ddq → audit → sip2r → phq → bscq → drug_use → tsr → [mica_ed_session] → postsession → close
```

That makes **`tsr`** the survey the handoff follows.

**One consequence to be aware of before you set it.** `tsr` is designated to all twelve events, while
`ed_session_url` lives on `admin`, which exists only at the Day-1 events. A bare `[ed_session_url]`
piped from `tsr` therefore resolves at the *context* event — correct at Day 1, and **empty at
Month 3, 6 and 12**, which REDCap turns into a `302` with an empty `Location` and a blank page
(§5.3). Whether that matters depends on how those later timepoints are delivered.

### 5.2 Set the redirect

*Designer → the chosen survey → Survey Termination Options → Redirect to a URL* → `[ed_session_url]`

Bare, no event prefix, because §4.2 put the value at the same event. Leave *auto-continue*
**off** — the guard at `Surveys/index.php:1822` requires `!$end_survey_redirect_next_survey`,
so auto-continue and a redirect are mutually exclusive.

### 5.3 Two cases still redirect to an empty field

Your workflow change removes the common case, but not these two:

- **Standard Care (`study_group = 1`)** — correctly has no URL, forever.
- **The trigger did not fire** — missing stratification data makes
  `Randomization.php:3126` log and skip; an exhausted allocation table does the same.

An empty pipe is **not** a graceful no-op. Measured, not inferred: the guard at
`Surveys/index.php:1833` tests the template *before* piping, so `[ed_session_url]` enters the
block; piping yields `''`; and `redirect('')` served over HTTP is
**`HTTP/1.1 302 Found`, `Location:` empty, `Content-Length: 0`** — a dead page with no
acknowledgement text and no message. `MultiLanguage::getSurveyRedirectUrl()` does not
short-circuit on empty either.

So `ed_session_url` must be **non-empty for every record that reaches the end of the chain**.
Two ways:

- **(a) Write a fallback URL for the non-intervention cases** — a small no-auth module page
  that says "this participant has no ED session" (arm 1) or "your coordinator will start your
  session shortly" (not randomized). The field then always means "where this participant goes
  next", and part 2 stays pure native piping.
- **(b) Point the redirect at a resolver page for everyone** and let it decide at click time.
  More robust, and the only option that handles a CRC randomizing while the participant is
  mid-chain — but the field's value is then no longer what the participant follows.

**(a) is the better fit now** that randomization precedes the end of the chain. If you build
a page for either, declare it in `config.json` under `no-auth-pages`, and do not let it take
a redirect target from the query string — resolve server-side from a record identifier and
refuse what it cannot resolve.

---

## 6. Decisions needed

1. **Trigger logic** — `[baseline1_complete] = '2'` or a field-based gate (§2.2), and whether
   `randomize_trigger` becomes part of it.
2. **How `randomization_date` gets populated** (§3). This blocks alerts 02–14, not this
   feature — but it breaks at the same moment.
3. **Which survey hands off** — `check_code` or `sms_code_check` (§5.1).
4. **What Standard Care participants see** at the end of the chain (§5.3).
5. **The survey-login handoff.** `mica_ed_session` requires login (`phonen` @ 1004); no arm-1
   survey does. So the redirect lands the participant on a login prompt asking for the phone
   number they typed into `baseline1` minutes earlier. With a CRC present in the ED that may
   be exactly right — possession of the phone as a second factor — but it should be a stated
   decision, not a surprise discovered in testing.

## 7. Interaction to watch: minting the link early

`getSurveyLink()` creates the `redcap_surveys_participants` row at issuance, so from
randomization onward a link row exists whether or not anyone opens it.

`close-expired-sessions` is **`false` on PID 257 today**, so this is inert. If it is turned
on, the closer backfills `link_expiration = now + the survey's own limit` for any MICA host
link with none (`MICA.php:2626-2664`), anchored on **when the cron first sees the row** — not
on first use. Minting at randomization therefore starts the ED session's 24-hour clock from
the cron pass after randomization. Probably fine when the handoff is immediate, but it is a
real coupling between this change and that setting, and should be verified rather than
discovered.

## 8. What is still yours to configure

The module writes the link and the fallback for every record on every UI save and survey submit
already. Nothing below is code; all of it needs decisions or artifacts the study owns. Run
`verify-ed-session-link.php 257` at any point — it names whichever of these is still missing.

1. **Enable and set up Randomization.** Currently `randomization = 0` on 257 with no config row, so
   nothing is automatic yet: the module writes the link whenever `study_group` is set, by whatever
   means. Target field `study_group`, **target event arm-1 Day 1**, trigger instrument `baseline1`,
   trigger event arm-1 Day 1, and **trigger option 2** — option 1 is skipped on survey pages
   (`Randomization.php:3112`) and fails with no log line at all. The verifier fails loudly on
   option 1 for exactly this reason.
2. **Allocation tables, for development *and* production separately.** Without free allocations the
   trigger runs and logs "no available allocations". The verifier counts them.
3. **Trigger logic.** `[baseline1_complete] = '2'` is the natural expression but is worth testing —
   the trigger is evaluated at `DataEntry.php:6710` and whether the form-status field is already `2`
   in the data at that moment has not been confirmed. A field-based gate such as `[phonen] <> ''`
   does not depend on that timing.
4. **Name a field in "Stamp the randomization date into this field"** (blank = off). Automating
   randomization takes the CRC off the form that carried `randomization_date` by hand, and that date
   anchors alerts 02–14. The module writes it only when empty, so a human's value is never touched.
5. **Confirm the handoff survey.** The redirect is currently on `sms_code_check` — the last
   participant-facing survey, and the only one already shaped to accept a redirect (auto-continue
   and save-and-return both off, which `Surveys/index.php:1822` requires). It also **displays the
   passcode**, which §5.1 flags. Moving it to `check_code` means turning that survey's auto-continue
   off, which changes the chain.
6. **Review the participant wording.** `session-handoff-text` overrides the waiting message; the
   no-session message is deliberately fixed (§5.3). Both should be wording the study is happy to
   show, and the waiting one may want to name the CRC.
7. **The survey-login handoff, which is a decision and not a bug.** Verified in a browser: the
   redirect lands the participant on `mica_ed_session`'s **Survey Login** prompt, asking for the
   phone number they typed into `baseline1` minutes earlier. With a CRC present in the ED that may
   be exactly right — possession of the phone as a second factor. If it is not what the study wants,
   the alternative is exempting the ED session from login, which weakens the gate.

## 9. How to verify

Run the flow in [`22-minimum-test-path.md`](22-minimum-test-path.md), which will need
rewriting for this workflow (its §4 "Randomise, on the arm-1 admin form" becomes automatic).

| Check | Expected |
|---|---|
| Submit `baseline1` as a participant | `study_group` allocated **in the same request** — no CRC action |
| Trigger option set to 1 instead of 2 | nothing happens — confirms §2.2 is real, worth seeing once |
| After that submit | record present in arm 2 or 3; `ed_session_url` populated at 1004 |
| `study_group = 2` vs `3` | URL points at the arm-2 vs arm-3 event respectively |
| `study_group = 1` | `ed_session_url` **empty** (or the arm-1 fallback from §5.3(a)) |
| `randomization_date` | **non-empty** — otherwise alerts 02–14 have no anchor (§3) |
| Re-submit / re-save | URL unchanged; exactly one `redcap_surveys_participants` row per record per host survey |
| Record 6 (materialized before this ships) | gets a URL on its next UI save |
| Finish the chain | lands on the ED session (login prompt), not a `302` with an empty `Location` |
| Allocation table exhausted | trigger logs and skips; participant still gets a sane end-of-chain page |

Add the participant-facing half to `e2e/full-path.js` rather than checking it only by hand —
the redirect is now a real step in the participant journey that suite already covers.
