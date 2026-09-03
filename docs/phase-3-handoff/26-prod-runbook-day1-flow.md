# Production runbook — Day-1 flow, CEMI handoff, booster context

The project changes made on **PID 257** after
[`25-prod-runbook-ed-session.md`](25-prod-runbook-ed-session.md), in the order to redo them on
production. That runbook covers the `ed_session_url` field and randomization; **do it first**, this
one builds on it.

Captured from 257 on 2026-09-02. Substitute your production project id for `<PID>`.

---

## 0. What is code and what is project configuration

**Code — deploy once, nothing per project:** `MICA.php` (the session-completion continuation, the
booster inject path) and `config.json` (two new settings). Both are in the module.

**Project configuration — this runbook:** one instrument split, an instrument reorder, five survey
settings, one alert edit, two module settings.

> **Read this before you start.** Steps 1 and 2 are **data dictionary** changes. On a production
> project they enter Draft Mode and need administrator approval, and step 1 *moves fields between
> instruments*. Data is preserved — REDCap keys values by field name, not by form — but form
> completion status is per-instrument, so **records that already completed enrolment will have
> `baseline1_complete = 2` and no `contact_info_complete`**. That matters for step 4; see the note
> there. If production already has enrolled participants, get agreement on that before proceeding.

---

## 1. Split `baseline1` into ID and demographics

The PI's requirement: *"Demographics needs to be separated from ID stuff. ID stuff should come after
consent. Then Check code. Then Demographics and rest of baseline stuff."*

Create a new instrument **`contact_info`** and move these eight fields to it from `baseline1`, in
this order:

| Field | |
|---|---|
| `first_name`, `last_name`, `email`, `phonen`, `choice_fup_delivery` | the ID/contact fields |
| `rnd`, `calcrnd`, `dummy_email` | hidden machinery — **must move with them** |

`baseline1` is left with exactly: `sex`, `hisp`, `race`, `educ`, `work`.

**The hidden three are not optional.** `calcrnd` is the passcode `check_code` validates, so it has to
be generated *before* check code runs — which is only true if it lives on the instrument that comes
before it. `dummy_email` carries the `@DEFAULT` that ASIs trigger on, and `rnd` feeds `calcrnd`.

**Do not rename `baseline1`.** Two alert conditions test `[baseline1_complete]`, and renaming the
instrument renames that field silently. Only its *survey title* changes (step 3).

On production, do this in the **Online Designer** or by Data Dictionary import — both enter Draft
Mode. `apply-baseline-split-and-order.php` is **development only**; it writes `redcap_metadata`
directly and refuses to run on any other project status.

## 2. Order the instruments

Drag to this order in the Online Designer. Only the relative order of the **surveys a participant
sees** matters — REDCap's auto-continue walks the instruments designated to the participant's current
event and takes the next enabled survey.

```
pre_screen → screen → screen_eligibility → consent → person_obtaining_consent
  → contact_info → check_code → baseline1
  → ddq → audit → sip2r → phq → bscq → drug_use → tsr
  → close → mica_ed_session → postsession → mica_booster_session
  → sunday → sms_opt_out → admin → mica_safety_finding → sms_code_check
```

Two things about that tail that look wrong and are not:

- **`close` before `mica_ed_session`** is harmless. `tsr` does not auto-continue (step 3) — it
  redirects — so nothing walks from `tsr` into `close` by that route.
- **`sms_code_check` at the end**, outside the main chain. That matches the PI's order, which goes
  *check code → demographics* directly. `sms_code_check` also displays the passcode
  ([`22-minimum-test-path.md`](22-minimum-test-path.md) §Findings), so keeping it off the
  participant's path is the safer place for it.

> **Do not try to reproduce a `field_order` numbering.** The numbers on 257 have already drifted from
> what the migration script wrote — instruments were reordered in the Designer afterwards — and the
> flow still walks correctly, because what matters is relative order, not the integers. Chasing exact
> `field_order` values on production would be effort spent on something REDCap does not treat as
> meaningful.

## 3. Survey settings

Immediate on production — survey settings are **not** part of the data dictionary draft and need no
approval.

### 3.1 Enable and title

| Instrument | Enable as survey | Title |
|---|---|---|
| `contact_info` | **yes** (new) | `Enrollment` |
| `baseline1` | already is | retitle to `Demographics` |
| `postsession` | **yes** — it was never enabled at all | `CEMI (post-session)` |

`postsession` not being a survey is why CEMI could not open: there was nothing for a participant to
open. If you enable it by copying settings from another survey, **check its Survey Termination
Options afterwards** — cloning `tsr` on 257 silently copied `tsr`'s `[ed_session_url]` redirect,
which would have sent the participant back into the MICA session they had just finished.

### 3.2 Auto-continue and redirects

| Instrument | Auto-continue | Redirect to a URL |
|---|---|---|
| `pre_screen`, `screen`, `screen_eligibility`, `consent` | **on** | — |
| `person_obtaining_consent` | **off** | — |
| `contact_info`, `check_code`, `baseline1` | **on** | — |
| `ddq`, `audit`, `sip2r`, `phq`, `bscq`, `drug_use` | **on** | — |
| **`tsr`** | **off** | **`[ed_session_url]`** |
| `mica_ed_session`, `mica_booster_session` | **on** | — |
| `postsession` | **off** | — |
| `close` | off | — |

Four of those carry a reason:

- **`person_obtaining_consent` off** — it is the staff consent-witness signature. The chain is
  *meant* to stop while the CRC signs.
- **`tsr` off with a redirect** — `Surveys/index.php:1822` makes auto-continue and a redirect
  mutually exclusive. With auto-continue on, the redirect silently never fires.
- **`mica_ed_session` / `mica_booster_session` on** — this is what makes CEMI open. The chat is a SPA
  that never submits its own form, so the module now marks the response submitted and then reads
  *these* settings to decide where to go. Turn them off and CEMI stops opening again.
- **`postsession` off** — with it on, auto-continue at the session's event walks *backwards* into the
  battery (measured: it resolved to `sms_code_check`). The participant's journey runs at the arm-1
  Day-1 event while the session and CEMI are at the intervention arm's event, where every other
  instrument is an empty duplicate. Off means CEMI ends on REDCap's completion acknowledgement.
  **Where it should actually go after CEMI is an open study decision** — see §7.

## 4. Alert 01

The passcode SMS. Its trigger and its condition both referenced `baseline1`, which after the split no
longer holds the fields it tests.

| | Before | After |
|---|---|---|
| Trigger instrument | `baseline1` | **`contact_info`** |
| Condition | `… and [baseline1_complete]='2' and [phonen]<>'' and [calcrnd]<>'' …` | `[baseline1_complete]` → **`[contact_info_complete]`** |

`[phonen]` and `[calcrnd]` need no edit — logic references fields, not forms.

**Alert 17** (CRC – baseline complete) also tests `[baseline1_complete]` and is deliberately left
alone: `baseline1` still carries the demographics, which is what "baseline complete" means there.

> **Existing records.** A record that completed enrolment before the split has
> `baseline1_complete = 2` and no `contact_info_complete`, so this condition is false for it and
> alert 01 will not re-fire. Harmless — those participants already received their passcode — but it
> means you cannot use a pre-split record to test the alert. Use a fresh one.

Alert 01 also **ships deactivated**. It is still deactivated on 257. If the passcode text does not
arrive, run `why-no-passcode-sms.php <PID> <record>` before anything else — it checks that, the
`twilio_modules_enabled` ALERTS trap, and whether every field the condition names still exists.

## 5. Module settings

*External Modules → MICA → Configure.*

| Setting | Set to | Why |
|---|---|---|
| **Where a participant with no session goes instead** | `close` | A Standard Care participant has no session but has **not finished** — the closing page with its gift-card wording is one step away. Without this they get the module's handoff page saying "you have finished", which is both a regression and subtly untrue. |
| **Instruments to inject into the Chat Context — BOOSTER session** | `ddq,sunday` | 3-month follow-up for arms 2 and 3, plus weekly SMS for arm 3. |
| **Stamp the randomization date into this field** | `randomization_date` | Still **blank on 257**. Carried over from [`25`](25-prod-runbook-ed-session.md) §4 and still not done: with randomization automated, no CRC types that date, and it anchors alerts 02–14. |

**You do not need a separate arm-3 SMS list.** The SMS instrument is designated to arm 3 only, so an
arm-2 participant has no rows for it and it contributes nothing, while an arm-3 participant's data
appears. One list serves both arms and no code knows which arm anyone is in.

The general *Comma delimited list of instruments to inject* is unchanged and still serves the **Day-1**
session. Blank booster list falls back to it.

## 6. Verify

```bash
docker exec <web> php .../scripts/verify-ed-session-link.php <PID>
```

Then walk one participant through, and check each of these:

| Check | Expected |
|---|---|
| The chain after consent | `contact_info` → `check_code` → `baseline1` → the battery → `tsr` |
| Passcode SMS | arrives after `contact_info` is submitted (alert 01 must be **activated**) |
| Submitting `tsr`, randomized to arm 2/3 | lands on **MICA ED session** (login prompt is expected) |
| Submitting `tsr`, Standard Care | lands on **Close**, not the handoff page |
| Ending the MICA session | **CEMI opens by itself** |
| After CEMI | REDCap's completion acknowledgement, **not** a jump back into the battery |
| Booster session context | contains DDQ labelled by event; the SMS block appears for arm 3 only |

For the booster inject specifically, the fastest check is to look at what the model is actually sent
rather than inferring it — the block headings carry the event name, so a Day-1 DDQ and a Month-3 DDQ
are distinguishable at a glance.

## 7. Still open — decisions, not work

1. **What follows CEMI.** `postsession` auto-continue is off because turning it on walks backwards
   into a duplicate battery (§3.2). Sending the participant to `close` would need `close`'s link at
   the session's event, and which event that should be is a study decision.
2. **Month 3/6/12 and the `tsr` redirect.** `tsr` is designated to all twelve events while
   `ed_session_url` lives on `admin` (Day-1 events only), so at the later timepoints the pipe is
   empty — and REDCap turns an empty pipe into a `302` with no location, i.e. a blank page. Fine
   while only Day-1 is in use; a live landmine the moment Month-3 follow-up opens.
3. **The booster session's own continuation.** `mica_booster_session` auto-continue is on, but at the
   Month-3 event it resolved to nothing. Same event-mismatch shape as (1).
4. **The Day-1 inject reads only one event.** `getFormattedBaselineData()` takes the *first* row
   `getData` returns, which on a longitudinal project is one event's copy. The booster path was
   written to read every event and label each block; the Day-1 path was deliberately left as-is
   because it is what the validated Day-1 counselor already sees. Worth a decision separately.
