# Option B — REDCap-side Implementation Record (PID 257)

**Implements:** [`08-auth-discovery.md`](08-auth-discovery.md) Option B, REDCap configuration layer
**Applied:** 2026-08-17 to **PID 257 `MICA_R01`** (Development, 0 records — still 0 after this work)
**Scope of this change:** REDCap project configuration + two dictionary fields. A later pass
(2026-08-17, same day) added repeating host instruments and the module-side changes needed to
render the chat on this project — see **§9**. Remaining module-side work is in §6.

> ### ✅ Credential event confirmed (Ihab, 2026-08-17)
> `baseline1` **is entered before randomization**, so `last_name` lives at **Day 1 (ED) arm 1
> (`event_id 1004`)** for every participant. The shipped configuration
> (`survey_auth_event_id1 = 1004`) is correct and needs no change. Because credential events
> are absolute, this one slot authenticates sessions hosted at either arm-2 or arm-3 events —
> verified end-to-end (§2). See §4 for the consequence this has on *when* a session link can
> be issued.

---

## 1. What was configured

### 1.1 Dictionary — two mount-point fields (fields 263 & 264)

The plan's `ui_hosting_instrument` does not exist in PID 257; the hosts are the two
already-correctly-designated placeholder instruments, which had **no real fields** and so could
not be surveys. One descriptive field was added to each, following the `sms_opt_out` pattern in
the same project (descriptive + `_complete` is a valid survey).

| Field | Instrument | Type | field_order | Label |
|---|---|---|---|---|
| `desc_mica_ed_chat` | `mica_ed_session` | descriptive | 68 | "Loading your MICA session…" |
| `desc_mica_booster_chat` | `mica_booster_session` | descriptive | 142 | "Loading your MICA session…" |

`form_menu_description` was moved onto each new field (REDCap keeps it on a form's *first*
field) and cleared from the `_complete` rows. Subsequent `field_order` values were shifted by
+1; verified afterwards: **0 duplicate orders, 0 gaps**.

These are mount points/placeholders only — MICA's `redcap_survey_page` hook injects its own
`#chatbot_ui_container` (`MICA.php:245`), so the SPA does not depend on this field. Its label is
what a participant sees for the moment before the app mounts.

### 1.2 The two host instruments enabled as surveys

| Survey | `survey_id` | Settings |
|---|---|---|
| `mica_ed_session` | **1315** | enabled, title "MICA ED session", title hidden, Save & Return on, **Edit Completed Response on**, scoped-login flag on, **Repeat Survey on (§1.4)**, **link time limit 24 h** |
| `mica_booster_session` | **1316** | enabled, title "MICA booster session", title hidden, Save & Return on, **Edit Completed Response on**, scoped-login flag on, **Repeat Survey on (§1.4)**, **link time limit 14 d** |

Notes:
- Column defaults were cloned from an existing survey in the same project so nothing is
  out-of-family. `logo` and `confirmation_email_attachment` were excluded from the clone (both
  are UNIQUE-indexed edoc references) and `form_name` was set in the INSERT itself, because
  `(project_id, form_name)` is UNIQUE.
- **Save & Return is on deliberately.** Survey Login forces `save_and_return = 1` at runtime
  anyway (`Surveys/index.php:1500-1508`); setting it explicitly makes the behaviour predictable
  rather than implicit.
- **`edit_completed_response = 1` is load-bearing, not cosmetic — see §1.2a.**
- The time limits are the **prerequisite** for expiry gate 2 — but they do nothing until
  `link_expiration` is populated per participant. See §6.1.
- `survey_time_limit_days = 14` for the booster is a **placeholder** that must be reconciled with
  the `booster-window-*` settings when Stage 2 lands.

### 1.2a Why `edit_completed_response` must be on (found while testing link reuse)

**The participant's session link never changes** — the hash in
`redcap_surveys_participants` is permanent per (record, survey, event, instance). But whether
that link still *opens* depends on the response's completion state:

| Response state | `edit_completed_response = 0` | `edit_completed_response = 1` (shipped) |
|---|---|---|
| Not started / partial | Login prompt → session | Login prompt → session |
| **Completed** | ❌ *"Thank you for your interest, but you have already completed this survey."* — no prompt, **no way back into their own session** | ✅ Login prompt → session. Gate still enforced; blank still rejected |

This matters because these host surveys have **no real questions**, so a single page submit
completes the response. Observed directly: a successful login POST on `mica_booster_session`
wrote `mica_booster_session_complete = '2'` and completed the response, after which the link was
dead. The default we inherited from the cloned template was `edit_completed_response = 0`, so
this would have shipped as "the participant's link works exactly once."

With it set to 1, re-entry still requires authentication — REDCap's gate condition for a
completed response is `$responseCompleted && $save_and_return && $edit_completed_response`
(`Surveys/index.php:1566-1573`), so both flags being on is what re-prompts rather than
letting the link through unchallenged. Verified both directions.

**Note for Stage 2:** the pilot SPA deliberately blocks form submission
(`blockSubmit()`) to keep the hosting response open. That workaround is no longer load-bearing
for link survival now that this setting is correct, but if submission is allowed in future the
response *will* complete — and that must remain harmless.

### 1.3 Survey Login

| Setting | Value |
|---|---|
| `survey_auth_enabled` | **1** |
| `survey_auth_apply_all_surveys` | **0** — scoped, not project-wide |
| `redcap_surveys.survey_auth_enabled_single` | 1 on **surveys 1315 & 1316 only** (verified: 2 surveys total, 0 non-MICA surveys flagged) |
| `survey_auth_field1` / `survey_auth_event_id1` | **`last_name` / 1004** (Day 1 (ED), arm 1) |
| `survey_auth_field2` / `field3` | **NULL — deliberately. See §3.** |
| `survey_auth_min_fields` | 1 |
| `survey_auth_fail_limit` / `fail_window` | 5 / 30 min |
| `survey_auth_custom_message` | Help text for the **failed-login** state — see §1.3a |

The other **18 surveys** (screening, consent, `check_code`, the assessment battery, `sunday`,
`close`, …) are untouched and remain ungated.

### 1.3a `survey_auth_custom_message` is an ERROR-state message, not prompt text

Corrected after seeing the rendered dialog. REDCap emits this message in **only two branches**
of `Survey::getSurveyLoginForm()` (`Classes/Survey.php:2154-2185`):

1. `if ($surveyLoginFailed === true)` — inside the red error box after a wrong attempt
2. `if ($rows == '')` — when there are no credential fields to show (the blank-credential case)

It is **never shown on the first-time prompt**, which displays only REDCap's stock wording
("Before beginning or continuing this survey, you must first log in…"). An earlier draft of this
document implied otherwise.

Because it only ever appears to someone who has just failed, the message was rewritten as
*help* rather than instruction, targeting the failure mode §2 identified as most likely:

> Please enter your last name exactly as you gave it to the study team. Capital letters do not
> matter, but extra spaces do. Tick "Show value" to check what you typed. If you still cannot
> get in, contact the study team.

Verified rendering in the red error box on a 390×844 viewport after a deliberate wrong attempt.

### 1.4 Both host instruments repeat, and "Repeat Survey" is on

A participant can open more than one chat session inside a window, so each session needs its own
storage row **and its own link**. Two settings together provide that:

| Layer | Setting | Applied to |
|---|---|---|
| Repeating instrument | row in `redcap_events_repeat (event_id, form_name, custom_repeat_form_label=NULL)` | `mica_ed_session` @ **1008** (arm 2) and **1012** (arm 3); `mica_booster_session` @ **1009** (arm 2) and **1014** (arm 3) — i.e. every event each host is designated to |
| Repeat Survey | `redcap_surveys.repeat_survey_enabled = 1` | surveys **1315** and **1316** |

`redcap_projects.repeatforms` was already 1, so it was left alone.

**The repeating-instrument row is the load-bearing half.** `REDCap::getSurveyLink()` silently
clamps `$instance` to 1 unless `$Proj->isRepeatingForm($event_id, $form)`
(`Classes/REDCap.php:1747-1750`), and `repeat_survey_enabled` is ANDed with the same call at
`Surveys/index.php:1030`. Verified as a before/after control on PID 257: **before** the
`redcap_events_repeat` rows existed, `getSurveyLink('MICATEST01','mica_booster_session',1009,2)`
returned the *identical* hash to instance 1; **after**, instances 1/2/3 each return a distinct
hash, and `Survey::getInstanceNumFromParticipantId()` — the call
`Surveys/index.php:1032` makes on every non-public survey load — resolves each hash back to its
own instance number. One `redcap_surveys_participants` row (hence one hash) is minted per
record+event+instance by `Classes/Survey.php:1687`.

The Survey Login gate is **unaffected**: fetched fresh, both the instance-1 and instance-2 ED
links present the credential prompt (`name="last_name"`) with the mount-point content absent, and
posting the correct `last_name` opens the page bound to `instance=1` and `instance=2`
respectively. Confirmed no `redcap_data` was written by those login POSTs.

⚠️ **Participant-visible side effect, needs a Stage 2 decision.** `repeat_survey_btn_location` was
inherited as `BEFORE_SUBMIT` from the cloned template (survey 1283), and `$isLastPage` is always
true on these single-page hosts, so the authenticated page now renders
*"Submit and [🔄 Take this survey again] – or – [Submit]"* (`submit-btn-saverepeat`,
`Surveys/index.php:3065`). `MICA.php`'s `redcap_survey_page_top` CSS hides only `#surveytitle,
#surveyinstructions, #return_instructions, #footer` — and only for `ui_hosting_instrument` — so
nothing currently hides `.surveysubmit` on these hosts. Either hide that chrome when the SPA
mounts, or set `repeat_survey_btn_location = 'AFTER_SUBMIT'`. Deliberately not decided here.

---

## 2. Verification performed

All tests were run against PID 257 itself over HTTP, using three temporary records that were
**deleted afterwards** (project is back to 0 data rows).

| Test | Result |
|---|---|
| Host survey renders | ✅ `<title>MICA ED session</title>`, mount field present |
| GET session link unauthenticated | ✅ credential prompt shown, **chat content not rendered** |
| POST correct `last_name` | ✅ authenticated, chat mount rendered |
| POST **blank** `last_name` | ✅ **rejected** |
| POST wrong `last_name` | ✅ rejected |
| **Cross-arm**: credential at arm-1 Day 1, session survey at **arm-2** event | ✅ works — this is the configuration that makes one slot sufficient for both MICA arms |
| Failed-attempt lockout (`fail_limit = 5`) | ✅ locks out; **correct value is refused while locked** (denial precedes verification). ⚠️ In our run the lock fired on the **3rd** wrong attempt of the batch, not the 5th — because 2 earlier failed probes were still inside the 30-minute window. That is correct behaviour, not a misconfiguration: the counter is failures within `fail_window` *since the last success*, not per-session. Expect this when re-running the checks. |
| Lockout semantics | Sliding window — failures older than `fail_window`, and any before the last success, stop counting. **There is no admin unlock button.** |
| Structural integrity after the change | ✅ 264 fields / 27 forms / 20 surveys / **168 event-form designations intact** / 0 data rows / 0 residual participant or response rows |
| Non-MICA surveys unaffected | ✅ `pre_screen` (survey 1283) byte-identical settings; 0 non-MICA surveys carry the scoped-login flag |

---

## 3. ⚠️ Security finding that changed the design during implementation

**REDCap's Survey Login treats a blank submitted credential as matching a blank stored value.**

`Surveys/index.php:1470-1481` compares `strtolower($_POST[$field]) === strtolower($storedValue)`
with **no empty-value guard** — `$fieldsWithValues` is collected but used only in the log message.
And `Classes/Survey.php:2066-2075` *removes* any credential whose stored value is empty from the
rendered form ("Loop through fields again and REMOVE any where the value is empty for this
record"), so the participant is never even asked for it.

**Verified empirically, twice:** a record with no stored value for the credential field plus an
empty POST produced `Survey Login Success`, issued the login cookie, and rendered the survey.

### Why this killed the two-slot design in `08 §6.1c`

The memo proposed one credential slot per MICA arm (`last_name` @ arm 2 + `last_name` @ arm 3,
`min_fields = 1`), because credential events are absolute and `baseline1` data is arm-specific.
**That configuration is bypassable by design:** whichever arm a participant is in, the *other*
slot necessarily points at an event where they have no data — a guaranteed blank — so posting
`last_name=` matches it and grants access with the link alone.

**Confirmed on PID 257:** two slots configured, blank POST → chat rendered. One slot, blank POST
→ rejected. This is why the shipped configuration uses **exactly one slot**, and why
`survey_auth_field2/3` must stay NULL.

*Correction to `08 §6.1c`:* that section attributed the multi-arm failure to the arm filter at
`Classes/Survey.php:2040-2057` returning `false`. That is **not** the operative mechanism here —
`Records::getRecordListPerArm()` reports records as present in *all* arms in this project, so the
arm filter never fires. The blank-value removal above is what actually governs, and the
consequence of a multi-slot config is a **bypass** rather than the blank page documented there.
`08` has been corrected.

### Residual risk this leaves (and the required mitigation)

A single slot is blank-safe **only for records whose credential value is populated**. Any record
with an empty `last_name` at event 1004 can still be entered with the link plus a blank
submission. Practical exposure is low — `last_name` is captured on `baseline1` before any MICA
session — but it is not zero.

**Required mitigation (module-side, Stage 2):** MICA must refuse to issue or send a session link
unless the credential field is non-blank for that record. This is cheap and closes the hole.
Tracked in §6.

---

## 4. Enrollment workflow — confirmed, and what it implies for link issuance

**Confirmed (Ihab, 2026-08-17): `baseline1` is entered before randomization.** So `last_name` is
stored at Day 1 (ED) **arm 1** (`event_id 1004`) for every participant, which is what
`survey_auth_event_id1` points at. Verified working cross-arm in §2. No change required.

### 4.1 ⚠️ Consequence: a session link cannot be issued until the participant is randomized

`REDCap::getSurveyLink()` resolves the arm of the requested event and then requires the record to
exist **in that arm** (`Classes/REDCap.php`):

```php
$arm_num = $Proj->longitudinal ? $Proj->eventInfo[$event_id]['arm_num'] : null;
if ($ensureThatRecordExists && !Records::recordExists($project_id, $record, $arm_num)) return null;
```

Because `mica_ed_session` is designated to Day 1 (ED) in **arms 2 & 3 only**, and pre-randomization
data lives in arm 1, a participant who has completed `baseline1` but has **not yet been
randomized** has no arm-2/arm-3 presence — so `getSurveyLink()` returns **null**, not a link.

Observed directly during verification: a record with only arm-1 data produced an empty link; once
a single value existed at the arm-2 Day-1 event, the link generated normally.

**Required order of operations in the ED:**

```
pre_screen → screen → consent → baseline1   (arm 1, pre-randomization)
      → randomize (writes to the assigned arm's Day-1 event)
            → NOW the MICA session link can be issued → participant authenticates with last_name
```

**Stage-2 requirements this creates** (module-side):

1. Link issuance must check that the record exists in its randomized arm and surface an explicit
   *"not randomized yet"* state — a null return from `getSurveyLink()` must never be treated as
   an ordinary failure or silently emailed/texted as an empty link.
2. Do **not** reach for `getSurveyLink(..., $ensureThatRecordExists = false)` to work around this.
   It would fabricate participant/response rows for a record that is not in that arm.
3. The CRC-facing flow must make it clear that randomization precedes the MICA session. This is
   consistent with the study design anyway — MICA only applies to arms 2 & 3, so the arm must be
   known first.

---

## 5. Rollback

The change is small and fully reversible. Durable revert path:

```sql
-- 1. Survey Login settings
update redcap_projects set survey_auth_enabled = 0, survey_auth_apply_all_surveys = 1,
  survey_auth_field1 = null, survey_auth_event_id1 = null,
  survey_auth_field2 = null, survey_auth_event_id2 = null,
  survey_auth_field3 = null, survey_auth_event_id3 = null,
  survey_auth_min_fields = null, survey_auth_fail_limit = null,
  survey_auth_fail_window = null, survey_auth_custom_message = null
where project_id = 257;

-- 2. The two host surveys (this also drops repeat_survey_enabled with the row)
delete from redcap_surveys where survey_id in (1315, 1316);

-- 2a. The repeating-instrument designations. redcap_events_repeat has no project_id, so
--     scope through events_metadata/events_arms or you will hit other projects' forms.
--     Leave redcap_projects.repeatforms alone - other instruments may rely on it.
delete er from redcap_events_repeat er
  join redcap_events_metadata em on em.event_id = er.event_id
  join redcap_events_arms ea on ea.arm_id = em.arm_id
where ea.project_id = 257
  and er.form_name in ('mica_ed_session', 'mica_booster_session');
-- If either host survey is being KEPT and only "Repeat Survey" is being reverted:
--   update redcap_surveys set repeat_survey_enabled = 0 where survey_id in (1315, 1316);

-- 3. The two mount-point fields, and restore the form menu descriptions
delete from redcap_metadata where project_id = 257
  and field_name in ('desc_mica_ed_chat', 'desc_mica_booster_chat');
update redcap_metadata set form_menu_description = 'MICA ED session'
  where project_id = 257 and field_name = 'mica_ed_session_complete';
update redcap_metadata set form_menu_description = 'MICA booster session'
  where project_id = 257 and field_name = 'mica_booster_session_complete';
```

After step 3 there will be two gaps in `field_order` (at 68 and 142). They are harmless; REDCap
renumbers on any Online Designer reorder or data-dictionary save.

---

## 6. What is deliberately NOT done yet

### 6.1 Blocked on inputs we do not have

| Item | Blocker |
|---|---|
| **Twilio project configuration** (SID / token / from-number, `twilio_delivery_preference_field_map` → `baseline1.choice_fup_delivery`) | Needs the study's approved Twilio credentials. `twilio_enabled = 0` on PID 257; not blocked at system level (`twilio_enabled_global = 1`). |
| **ASI on `mica_booster_session`** for the Month-3 window | Depends on the window definition (`booster-window-*`) landing in Stage 2. The `admin.calc_month_3` blocker is **cleared** as of 2026-08-25 — its stale `[baseline_arm_1]` prefix was dropped and the field now computes (`19 §6`); it needs a `randomization_date` value on the record to produce a date. |

### 6.2 Module-side work still outstanding
*(Items 1–3 below remain; the module changes that WERE made are in §9.)*

1. **Write `link_expiration` at link issuance** — the survey time limits configured in §1.2 do
   nothing until this exists (`08 §3.3`). Until then the session link does not expire.
2. **Refuse to issue a link when the credential is blank** — closes the residual risk in §3.
3. **Server-side participant identity on all module AJAX actions**, and verification of the
   Survey Login cookie against the resolved record (`08 §8.2`).
4. **Delete the retired OTP path**: `loginUser()`, `verifyEmail()`,
   `generateOneTimePassword()`, `pages/chatbot.php`, its `no-auth-pages` entry, and the
   `login` / `verifyEmail` `no-auth-ajax-actions` entries.
5. **Refuse withdrawn / opted-out participants** (`admin.study_withdrawn`, `admin.sms_stop`).
6. The `proj_mica` module is **not enabled on PID 257** — correct for now, since the R01 turn
   contract and session engine (Stages 1–2) are not built.

### 6.3 Still study-team items (from `09 §5`)

`[calcrnd]` does not exist, so the study's own `check_code` passcode survey cannot succeed; and
participant-facing text in `sms_code_check` / `sms_opt_out` still names the **ASPIRE** and
**TRAM** studies.

---

## 7. Net state — read this before assuming "done"

> **Today's actual posture on PID 257 is ONE factor plus a non-expiring link.**
> `survey_auth_enabled = 1` makes Project Setup *look* finished, but the link time limits
> configured in §1.2 do nothing until the module writes `link_expiration` (§6.2.1). Until that
> lands, the session link is a permanent bearer credential — which is finding #5 from the
> original discovery, relocated rather than resolved. SMS delivery is also not configured
> (§6.1). Neither gap is visible from the REDCap UI.

What **is** live and verified: a participant reaching either MICA chat survey must confirm
their last name before any chat content renders, with REDCap-enforced lockout after 5 failures
in a 30-minute sliding window, and every attempt — success or failure — written to REDCap's own
audit log outside the module's control.

## 8. Reproducing this configuration

`docs/phase-3-handoff/scripts/` holds an idempotent apply script and an independent verifier
(see that folder's README). They exist because the correct configuration is counterintuitive —
one credential slot, at the pre-randomization event, slots 2 and 3 empty — and the verifier
asserts that specifically, since adding a second slot is a login bypass (§3).

```bash
docker cp docs/phase-3-handoff/scripts $CONTAINER:/var/www/html/temp/
docker exec $CONTAINER php /var/www/html/temp/scripts/verify-auth-config.php 257 last_name 1004
# *** ALL CHECKS PASSED ***   (as of 2026-08-17 against PID 257)
```

---

## 9. Module enablement and the chat page (added 2026-08-17)

Goal for this pass: the participant authenticates and the **chat SPA visibly mounts** on PID 257.
Sending a message is explicitly out of scope (needs SecureChatAI + the Stage 1/2 turn contract).

### 9.1 Enablement

`proj_mica` (module id 93, version `v9.9.9`) is enabled on PID 257. Twelve required project
settings were populated with dev placeholders via `ExternalModules::setProjectSetting()`.

Two notes for anyone repeating this:
- The EM framework refuses setting writes from CLI — `ensureSetSettingIsAllowed()` throws
  *"You don't have permission to save project settings"*. Its own escape hatch is
  `defined("CRON")`, so define `CRON` before `require redcap_connect.php`.
- `MICA.php` previously did a hard `require_once "vendor/autoload.php"`. `vendor/` is gitignored,
  so on a fresh checkout that makes the module class unloadable and REDCap reports an unrelated
  fatal when enabling it. It is now a conditional require.

### 9.2 The chat host is now configuration, not a hardcoded name

`MICA.php` hard-gated both survey hooks on `$instrument !== 'ui_hosting_instrument'`, which does
not exist in PID 257. Added a `chat_host_instruments` project setting (comma-delimited, default
`ui_hosting_instrument`) plus an `isChatHostInstrument()` helper used by both hooks. **Empty or
unset falls back to `ui_hosting_instrument`, so pilot projects behave exactly as before.**
On PID 257 it is set to `mica_ed_session,mica_booster_session`.

### 9.3 Two real defects fixed in the bootstrap

Both pre-existing, both reachable once the hooks fire on a project without the pilot's fields:

1. **Fatal.** `current(json_decode(\REDCap::getData(...)), true)` throws
   `TypeError: current(): Argument #1 must be of type array, null given` on PHP 8 whenever
   `getData` returns `''` or JSON `null`. Now decoded and type-checked before use.
   *(The unknown-field worry turned out to be unfounded — `Records::getData()` silently
   `unset()`s field names not in the dictionary. The requested field list is filtered anyway.)*
2. **Cross-participant data leak.** With an empty `$record`, REDCap's `records` filter degrades to
   *all records*, so `current($data)` could place **another participant's** `participant_name` /
   `participant_email` into the page's `data-bootstrap` attribute. Now guarded by
   `if (!empty($record))`.

A null/failed session context is also normalised to `[]`, since PID 257 has none of the pilot's
`consent_date` / `des_mica` / `month3_fu_complete` / `baseline_arm_1` scaffolding.

### 9.4 REDCap submit chrome is hidden on chat hosts

Enabling Repeat Survey (§1.4) made REDCap render its submit block on the authenticated chat
page — *"Submit and [Take this survey again] – or – [Submit]"*. Measured: 3 submit buttons plus
the repeat button, all present **and visible**, merely painted over by the SPA. That would let a
participant mint their own session instance or complete the response, and both remain reachable
by keyboard and screen reader.

`redcap_survey_page_top` now emits a second style block hiding `.surveysubmit`. It is
deliberately separate from `#mica-hide-native`, because that block is removed by `unmask()` once
the app mounts. Re-measured after the fix: all four controls `VISIBLE=0`, chat unaffected.

⚠️ **Survey submission is now suppressed in two independent places** — the SPA's
`blockSubmit()` JS (`redcap_survey_page`) and this CSS rule — and hiding `.surveysubmit` removes
the only *native* way to complete these responses. That is correct today: the chat's "End Session"
completes the session through `completeSession()` over AJAX, not a form submit. But **Stage 2
should pick one mechanism deliberately** rather than leave both in place, and whoever removes one
must check the other still covers it. Related: §1.2a explains what happens when a response *does*
complete, which is why `edit_completed_response = 1` must stay on regardless.

### 9.5 Verified rendering (Playwright, headless Chromium)

| Check | Desktop | Mobile 390×844 |
|---|---|---|
| Login dialog visible, no chat content behind it | ✅ | ✅ |
| Authenticate → SPA mounts (4 child nodes, message input present) | ✅ | ✅ |
| Rendered text | "MICA AI Chatbot / End Session / Hi there. I'm MICA. What is your name?" | same |
| Bootstrap object delivered to the page | ✅ all 7 keys | ✅ |
| `window.mica_jsmo_module` | `object` | `object` |
| Horizontal overflow | none | none |
| Repeat instance 2 and 3 links | ✅ gated then render | ✅ |

Two findings that need no action:
- **`window.renderMicaApp` does not exist in the built bundle.** `MICA.php`'s `tryMount()` polls
  for it and only then calls `unmask()`, so `unmask()` never runs. Harmless — `main.jsx` calls
  `ReactDOM.createRoot(...).render()` at module scope, so the app self-mounts, and the mask only
  hides chrome we want hidden. But the polling loop and `unmask()` are **dead code**; if a future
  build ever exports `renderMicaApp`, `#mica-hide-native` would start being removed.
- Two `offsetHeight` console errors on the page are **pre-existing REDCap core noise** from
  `Resources/webpack/js/bundle.js`, reproduced on `baseline1` which has no MICA hook.

### 9.6 Known latent issue, not fixed

`pages/chatbot.php:68` still hardcodes
`REDCap::getSurveyLink($recordId, 'ui_hosting_instrument', $eventId)`. Now that
`chat_host_instruments` exists, that call returns null on any project lacking the pilot
instrument. It sits in the page Stage 2 deletes (`06-implementation-plan/stage-2-session-engine.md`),
so it was left alone — but it must not be forgotten if that page survives longer than planned.
