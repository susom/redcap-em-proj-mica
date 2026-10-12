# 08b — 2FA replacement: recommended design and rollout (§6–11)

Part of [08 — 2FA replacement discovery](08-auth-discovery.md). The recommendation in detail, what ships regardless, session binding, compatibility and the feasibility spike.

## 6. Recommendation

### 6.1 Target design

> **Revised 2026-08-17** against the as-built PID 257
> ([`09-pid-257-structure-audit.md`](../../archive/09-pid-257-structure-audit.md)). The options analysis
> (§4) and ranking (§5) are unchanged — everything load-bearing held up. What changed:
> which instrument hosts the chat, and which field is the credential.

**Where the chat lives (was an assumption, now a task).** The plan referred to a
`ui_hosting_instrument`; **no such instrument exists in PID 257** (audit G1). What does
exist, already designated to exactly the right events and arms, is:

| Instrument | Designated to | Current state |
|---|---|---|
| `mica_ed_session` | Day 1 (ED), arms 2 & 3 | placeholder — only its `_complete` field; not a survey |
| `mica_booster_session` | Month 3, arms 2 & 3 | placeholder — only its `_complete` field; not a survey |

So the host becomes **two instruments, one per session window**, rather than one shared
survey. Each needs a single descriptive field added (the mount point for the SPA) and then
"Enable as survey". Verified feasible: PID 257 already contains descriptive-only surveys
(`sms_code_check`, `sms_opt_out` — one descriptive + `_complete`), and the "instrument has
no fields" restriction in `Design/online_designer.php:710` is MyCap-specific, not survey
enablement. Survey Login is then scoped to those two surveys via
`survey_auth_enabled_single`, leaving the other 16 surveys ungated.

This is *better* than the single-host assumption: the window is carried by the link's own
event rather than inferred, which is what R6 wanted, and it removes finding #11 (the
pilot's hardcoded baseline-event redirect).

**The credential — the one real decision the audit forces.** The recommendation assumed
date of birth. **PID 257 collects no DOB** (audit §2); the only date-validated fields are
consent and admin/scheduling dates. Options, with the factor arithmetic stated honestly:

| Credential | Available now? | Factor analysis | Cost |
|---|---|---|---|
| **`last_name`** (`baseline1`, PHI-flagged) | ✅ yes | Genuine **second** factor — knowledge, not delivered over the SMS channel. Weak in isolation (discoverable/guessable), which is what the lockout is for. | none |
| **ED-issued passcode on paper/card** — fills the missing `[calcrnd]` slot (audit G2) | needs 1 field | Genuine second factor, and the strongest of the three: not derivable and not sent over the phone channel. No new PHI. | new CRC workflow step |
| **Add a DOB field** | needs 1 field | Strongest conventional knowledge factor | The researchers apparently chose not to collect DOB; reversing that is their decision, not ours |
| ~~Texted passcode as the Survey Login field~~ | — | ❌ **Not two factors.** A passcode texted to the same phone that receives the link is the *same* possession factor. Do not present this as 2FA. | — |

**Recommendation: `last_name` for tier L2 now** (zero new collection, ships immediately),
and the **ED-issued paper passcode** if the policy review requires a demonstrably strong
second factor (L3). Both are the same build; see §6.2.

**Delivery in the ED — stated plainly, because this is what the PI approves.**
The participant is on their own device in an ED bay, so delivery must not depend on them
reaching an email inbox.

- **ED default: SMS invitation, sent by the CRC while the participant is in the room**
  (Twilio is approved; the phone is already in their hand). Sent through REDCap's
  invitation mechanism, so `link_expiration` is also derived automatically.
  **Now confirmed viable by the audit:** an SMS-capable cell phone is an *eligibility
  criterion* (`screen.phone` feeds `screen_eligibility.calc_eligible`), so every enrolled
  participant has one by inclusion; `baseline1.choice_fup_delivery` already captures
  email-vs-text preference and maps onto REDCap's `twilio_delivery_preference_field_map`;
  and Twilio is enabled system-wide (`twilio_enabled_global = 1`), merely unconfigured on
  PID 257 (audit G7).
- **ED fallback, when SMS fails** (no signal, no phone, wrong number): the participant
  scans the link's **QR code** from the CRC's screen, or is given the **access code** for
  the survey short URL.
- **Security consequence of the fallback: none, provided the module writes
  `link_expiration` at issuance** (§3.3). That is the reason for that recommendation —
  without it the fallback paths would drop to a single gate, with it they keep both gates
  and expiry. Both paths therefore give: expiring record-bound link **+** Survey Login.
- **Additional bound that already exists:** the session engine refuses to serve a session
  outside its window (`getSystemContextForRecord()` throws, `MICA.php:617-677`), so even a
  leaked link is only useful inside an open session window.
- **Booster:** ASI invitation over the participant's `delivery_preference` when the Month-3
  window opens.

Concretely:

1. CRC triggers the MICA session invitation for the record — via REDCap's invitation
   mechanism (participant list or ASI), SMS-preferred in the ED.
2. The participant opens their **record-bound chat link** from the text/email, or via QR /
   access code as the fallback.
3. The link is **time-limited**: `survey_time_limit_*` enabled on the chat survey, plus
   `link_expiration` written at issuance (suggest 24 h for baseline, the window length for
   the booster) so the link stops being a credential after the visit — on every delivery
   path.
4. REDCap's **Survey Login** challenges them for 1–2 known record fields before the chat
   renders.
5. Chat runs in the survey as today, inside a REDCap-managed session with a 30-minute
   idle timeout and a cookie that dies when the browser closes.

**Booster (Month 3, remote)** — identical, with the invitation sent by an **Automated
Survey Invitation** when the Month-3 window opens, and a longer link time limit (suggest
the length of the booster window).

**What gets deleted:** `loginUser()`, `generateOneTimePassword()`, `verifyEmail()`,
`pages/chatbot.php` and its `no-auth-pages` entry, the `login`/`verifyEmail`
`no-auth-ajax-actions` entries, and the Twilio system settings in `config.json` (superseded
by REDCap's own project-level Twilio). Constraint F1 stops applying, because there is no
custom pre-auth page left. Note the `two_factor_code` / `two_factor_code_ts` fields need no
migration — **they do not exist in PID 257** (audit §4), so this is a deletion from the
pilot dictionary only.

### 6.1a Relationship to the study's own `check_code` pattern

The audit found an existing participant-verification mechanism the plan did not know about
(`09-pid-257-structure-audit.md §3`): text a random `[calcrnd]` passcode, participant types
it into the `check_code` survey, `calc_code_check` compares. It is designated at Day 1 and
all three follow-ups, in all three arms.

**These are complementary, not competing, and should not be merged:**

- `check_code` gates **survey-queue release / branching** for the follow-up assessment
  battery across all arms. It is a workflow gate. It does **not** prevent direct access to a
  survey link, because a calc field is not an access boundary.
- Survey Login gates **rendering**, enforced by REDCap core before the module hooks run
  (`Surveys/index.php:1367` vs 3455/3847). It is an access boundary.

**Recommendation:** leave `check_code` to the assessment battery it was built for, and gate
the two MICA chat surveys with Survey Login. Do **not** route the chat through `check_code`
— it would add a step, and it would not be an access control.

Two things follow for the study team either way:

1. `[calcrnd]` must be created for `check_code` to work at all (audit G2) — that is a
   researcher-side defect independent of MICA, and it is *also* the field the L3 tier would
   reuse as the ED-issued passcode.
2. The participant-facing text in `sms_code_check` / `sms_opt_out` names the **ASPIRE** and
   **TRAM** studies and must be corrected before launch.

### 6.1b Withdrawal and opt-out must be honoured at the auth boundary

The audit surfaced two fields with no equivalent in the plan: `admin.study_withdrawn`
("CRC checks this box to withdraw participant") and `admin.sms_stop` (set when the
participant texts STOP). Authentication must refuse a withdrawn participant, and the
invitation path must not text a participant who has opted out. The pilot has an analogous
check for completed participants (`MICA.php:505-508`) but nothing for withdrawal. Add both
to the session-entry checks in Stage 2.

### 6.1c ⚠️ Multi-arm constraint — exactly ONE credential slot

> **CORRECTED 2026-08-17 during implementation.** This section originally prescribed *one
> credential slot per MICA arm* and attributed multi-arm failure to REDCap's arm filter.
> Both were wrong, and the prescription was **unsafe**. Implementation testing
> ([`10-auth-implementation-pid257.md §3`](10-auth-implementation-pid257.md)) established:
>
> 1. REDCap treats a **blank submitted credential as matching a blank stored value** — there
>    is no empty-value guard (`Surveys/index.php:1470-1481`), and blank-valued credentials are
>    *removed from the form* entirely (`Classes/Survey.php:2066-2075`).
> 2. A per-arm slot configuration therefore **guarantees a bypass**: whichever arm the
>    participant is in, the other slot points at an event where they have no data, so posting
>    an empty credential matches it. **Confirmed on PID 257** — two slots + blank POST →
>    chat rendered; one slot + blank POST → rejected.
> 3. The arm filter (`Classes/Survey.php:2040-2057`) never fires here:
>    `Records::getRecordListPerArm()` reports records as present in *all* arms in this project.
>
> **Correct configuration: exactly one slot**, whose event is one where the credential is
> always populated — and because credential events are absolute, a credential stored at the
> pre-randomization Day-1 event authenticates sessions hosted at *either* arm's event. That
> is verified working and is what shipped. `survey_auth_field2/3` must stay NULL.
>
> The mechanism analysis below is retained for the record; treat its *prescription* as
> superseded by the above and by `10 §1.3`.

**This is the single most important implementation detail in the design, and it is easy to
get wrong silently.**

Survey Login stores a credential as a (field, **event**) pair —
`survey_auth_field1..3` + `survey_auth_event_id1..3` — and the configured event is
**absolute**, not relative to the survey being opened
(`Classes/Survey.php:2227-2253`; the match reads
`$survey_login_data[$record][$configuredEventId][$field]`, `Surveys/index.php:1477`).

In a multi-arm project each arm has its own events, so `baseline1.last_name` for an arm-2
participant is stored at *Day 1 (ED) arm 2* (event 1008) and for an arm-3 participant at
*Day 1 (ED) arm 3* (event 1012). REDCap handles the mismatch like this
(`Classes/Survey.php:2040-2057`):

```php
if ($multiple_arms) {
    foreach ($surveyLoginFieldsEvents as $key=>$fieldEvent) {
        $thisEventArm = $Proj->eventInfo[$fieldEvent['event_id']]['arm_num'];
        if (!isset($recordArms[$thisEventArm])) {
            unset($surveyLoginFieldsEvents[$key]);   // silently drop the credential
            $auth_field_count--;
        }
    }
    if (empty($surveyLoginFieldsEvents)) return false;   // no login form at all
}
```

**Consequence if you configure only one event.** An arm-3 participant authenticating
against a credential configured on arm 2's event has that credential silently dropped, all
credentials are then empty, and `getSurveyLoginForm()` returns bare `false`
(`Classes/Survey.php:2055`). Traced to its conclusion at the call site
(`Surveys/index.php:1576-1616`): `list($html, $fields) = false` assigns **null** to both,
`null !== false` is **true**, so the branch is entered, an **empty** login dialog is printed
with the survey hidden (`#pagecontainer{display:none}`), and it `exit`s.

**Net effect: fail-closed but broken** — the participant gets a blank, unusable page rather
than either a login prompt or the chat. Verified: the return shapes at
`Classes/Survey.php:2055` vs `:2193`, and the PHP semantics of `list(...) = false`,
`null !== false`, and `foreach(null)` were each executed to confirm. So this is a **launch
blocker for the uncovered arm, not a security hole** — but it would present as "MICA is
broken for half our participants" and would be easy to misdiagnose.

**Required configuration** — exactly ONE slot, at an event where the credential is always
populated (as shipped, `10 §1.3`):

| Slot | Field | Event |
|---|---|---|
| `survey_auth_field1` / `event_id1` | `last_name` | Day 1 (ED) **arm 1** (1004) — the pre-randomization enrollment event. ✅ Confirmed by the study (2026-08-17): `baseline1` is entered before randomization, so the value is always there. |
| `survey_auth_field2` / `field3` | **NULL** | — a second slot reintroduces the blank-match bypass |
| `survey_auth_min_fields` | **1** | — |

⚠️ Because MICA's host surveys are designated to arms 2 & 3 only, and `getSurveyLink()` requires
the record to exist **in the requested event's arm**, a session link cannot be issued until the
participant has been **randomized**. Order of operations and the resulting module requirements:
[`10 §4.1`](10-auth-implementation-pid257.md).

Because the credential event is absolute, this one slot authenticates sessions hosted at
*either* arm-2 or arm-3 events — verified end-to-end (`10 §2`). Arm 1 (Standard Care) needs
nothing; it has no MICA chat survey at all.

**Knock-on: the design is capped at one credential field** — not by the 3-slot budget, but by
the blank-match behaviour, which makes any additional slot a bypass. §6.2's L3 tier therefore
*replaces* the credential with a stronger one rather than adding a second. This does not
reduce the design to one factor — the delivered expiring link remains the possession factor.

**Residual risk of the single slot:** it is blank-safe only for records whose credential value
is populated. A record with an empty `last_name` can still be entered with the link plus a
blank submission, so the module **must** refuse to issue a session link when the credential is
blank (`10 §3`). Should two knowledge factors ever be required, native Survey Login cannot
provide them safely here — that would need a module-side challenge, i.e. custom auth code.

### 6.2 The policy question is a setting, not a design — so it does not block us

| Tier | Configuration | Factors | Use when |
|---|---|---|---|
| **L1** | Survey Login off; time-limited record-bound link only | 1 (possession) | Policy treats a delivered, expiring, record-bound link as sufficient — REDCap's own standard for identified survey data |
| **L2** ⭐ | `survey_auth_min_fields = 1`, field = **`last_name`** (exists today) | 2 (possession + knowledge) | Recommended default; one extra field to type, no dictionary change |
| **L3** | Same shape as L2, but the credential **is replaced** by an ED-issued paper passcode (one new field, fills the missing `[calcrnd]` slot) rather than `last_name`. Still `min_fields = 1`, still one slot per arm — see §6.1c for why a *second* field is not possible. `fail_limit = 5`, `fail_window = 15–30` | 2 (possession + a strong, non-guessable, non-delivered knowledge factor) | Policy requires a demonstrably strong knowledge factor |

All three are the same build. **Recommend L2 as the default to implement**, with the tier
confirmed by Stanford security/privacy + IRB before production. If they require L3, it is
a checkbox and one more field; if they accept L1, it is a checkbox off.

### 6.3 Participant-experience trade-offs, stated honestly

- **Better than today:** no name/email typing, no hex code to transcribe, no
  email-deliverability dependency in an ED bay (SMS or QR instead), no "duplicate
  entries" dead end, and a link that resumes the session for 30 minutes if the phone
  locks or the tab is closed.
- **Worse than today:** a participant whose credential does not match *as recorded* is
  locked out after `fail_limit` attempts and needs staff help. With `last_name` the realistic
  failure modes are enrollment typos, hyphenated or two-part surnames, and diacritics —
  note REDCap's match is case-insensitive but otherwise exact
  (`strtolower()` equality, `Surveys/index.php:1477`), so "De La Cruz" vs "DeLaCruz" fails.
  ⚠️ The spike (§11 S5) established that **REDCap has no admin "unlock" action**, and the
  mechanism is a *sliding window* — failures simply age out of `fail_window`. Mitigations:
  use L2 (single field); the CRC entered `last_name` at enrollment and can read it back;
  keep `fail_window` short enough to self-heal within a visit (15–30 min) with a low
  `fail_limit`; and document the CRC path — correct the stored value, or hand over the
  access code — in the study runbook.
- **Deliberate consequence:** the link alone stops being enough. A participant who
  returns a day later re-authenticates. That is the point of finding #5, and it is a UX
  cost worth naming to the PI.

---

## 7. Track A — remediation that ships regardless of the PI's decision

The SOW's fallback ("authentication remains as-is") currently means shipping findings
#1–#6. These items involve no protocol, consent, or participant-experience change, and
therefore need no PI approval. **They should be scheduled now, whichever option is
chosen** — under option B most of them are deleted rather than fixed, but until B lands
the pilot code path is what exists.

| # | Fix | Addresses | If option B is approved |
|---|---|---|---|
| A1 | Bind verification to the record that requested the code (carry the resolved record id in the server session; verify code **and** record together) | #1 | Superseded (code deleted) |
| A2 | Enforce `two_factor_code_ts` — reject codes older than 10 minutes | #2 | Superseded |
| A3 | Clear the code on successful verification (single use) | #3 | Superseded |
| A4 | Attempt counter + lockout on verify; throttle resends | #4 | Superseded (core lockout replaces it) |
| A5 | Store only a hash of the code | #9 | Superseded |
| A7 | Numeric 6-digit code to match the UI and mobile keypads | #10 | Superseded |
| A6 | Parameterize / strictly validate every `filterLogic` interpolation | #8 | **Survives** — `fetchSavedQueries()` and the session-engine queries keep using `filterLogic` |
| A8 | Server-side participant identity on all module AJAX actions | #6 (§8) | **Survives** — no REDCap setting can do this for us |
| A9 | Session binding so the survey link alone does not grant chat access | #5 (§8) | **Survives**, and gets stronger: the §8.2 cookie check becomes available |

**Triage — what to schedule now.** A6, A8, A9 survive either way and should be scheduled
immediately; they are also the highest-severity items after A1. A1–A5 and A7 are work on
code that option B deletes, so:

- **If the PI decision lands within the Stage 0–2 window:** do A1 + A2 + A3 only — three
  small, contained changes that close the exploitable cluster (unbound verification,
  no expiry, no single-use) and cost little if later deleted. Skip A4, A5, A7 as
  throwaway.
- **If the decision slips or option B is declined:** do the full A1–A9 set; option A in
  §4 becomes the design rather than a fallback.

Either way, the pilot (`pilot-final` tag) keeps whatever it ships with — this triage
applies to the phase-3 branch.

---

## 8. The other half of the hole: session binding and AJAX identity

Replacing the front door does nothing if the rooms behind it are unlocked. This is the
`05-open-questions-and-risks.md` risk row "No-auth `callAI` surface abuse — session-token
binding after OTP".

### 8.1 The problem

`redcap_module_ajax` (`MICA.php:306-316`) authorizes on `!empty($survey_hash) ||
!empty($_SESSION['username'])`, then reads the participant from the **payload**:
`callAI` takes `current($payload)["user_id"]` (`MICA.php:326`), `completeSession` and
`fetchSavedQueries` take `payload['participant_id']`. Any authenticated survey context can
therefore act on any record.

### 8.2 The fix

1. **Resolve the record server-side from the survey context** — the `$record` /
   `$survey_hash` arguments REDCap already passes to `redcap_module_ajax` — and *ignore*
   any participant identifier in the payload (or accept it only to assert equality and
   reject on mismatch, logging the attempt).
2. **Additionally verify the Survey Login session** for that record by recomputing
   `hash($password_algo, "$project_id|$record|$salt|$salt2")` and comparing it to
   `$_COOKIE['survey_login_pid'.$project_id]` — the same check core does at
   `Surveys/index.php:1537-1541`. This is what turns "a survey hash was present" into
   "this participant authenticated for this record", and it is only available to us
   because we adopt native Survey Login. **Verified**: the required globals are in scope in
   a REDCap request and the recomputation reproduces the issued cookie exactly (§11 S3,
   S3b). Remaining check at implementation time: that the same globals are populated in the
   specific `redcap_module_ajax` request path.
3. **Scope every data operation** to the resolved record: transcript reads, `logMICAQuery`
   writes, and `completeSession`.
4. **Rate-limit** `callAI` per record per window (cost and abuse control).

---

## 9. Compatibility with session-window routing

Today the window is derived entirely server-side: `calculateSessionInfo()`
(`MICA.php:756-804`) computes the session from `consent_date` and
`session_length_days`, and `getSystemContextForRecord()` (`MICA.php:617-677`) throws
"Session already completed. Return in N day(s)" when the window is closed. The URL carries
no window information — the post-OTP redirect is hardcoded to the baseline event
(`pages/chatbot.php:67`, finding #11).

⚠️ **The audit makes this a hard break, not a preference:** PID 257 has **no `consent_date`
field** (audit G3), so `calculateSessionInfo()` returns `null` there — the pilot session
engine cannot run against this project at all. The replacement source is confirmed present:
`admin.randomization_date` (`date_ymd`), with `admin.calc_month_3` already deriving the
booster date via `@CALCDATE`. Two caveats carried from the audit: those `@CALCDATE`
expressions currently reference the non-existent event `[baseline_arm_1]` (39 such
references project-wide), and `[consent_date]` is referenced by 7 `admin` fields — both
researcher-side items in `09 §5`.

Under option B this **improves and stays compatible**:

- `REDCap::getSurveyLink($record, $hostInstrument, $event_id)` is per-event — and with the
  two host instruments (`mica_ed_session` at Day 1, `mica_booster_session` at Month 3, both
  arms 2 & 3), the Day-1 link and the Month-3 booster link are distinct participant rows on
  distinct instruments. The window is
  explicit in the credential instead of inferred from arithmetic.
- The phase-3 session engine (`01-architecture.md §3`: `session_type`, `location`,
  window computed from enrollment/randomization dates) remains the authority on whether a
  session may run. Auth answers *who*; the engine answers *whether* — unchanged division
  of responsibility.
- The booster invitation becomes an **Automated Survey Invitation** fired when the
  Month-3 window opens, which is also the cleanest answer to "how does the participant
  know it is time".
- Window-closed messaging still comes from the engine, after authentication, exactly as
  the `04-test-plan.md` case "completed baseline re-login shows *return for booster*"
  expects.
- Because Survey Login is scoped per instrument, the other **16 surveys** in PID 257
  (screening, consent, `check_code`, the assessment battery, `sunday`, `close`, …) and the
  RA dashboard path are unaffected.
- The Day-1 host is designated to arms 2 & 3 only, so **arm 1 (Standard Care) participants
  have no MICA chat survey at all** — the arm restriction is enforced by REDCap's event/form
  designation, not by module logic. That is a stronger guarantee than the plan assumed.

---

## 10. Impact on the existing phase-3 plan

| Doc | Change |
|---|---|
| `05-open-questions-and-risks.md` #7 (ED-tablet vs OTP) | **Answered**: participant's own device at Day 1; this memo is the resulting design |
| `05-open-questions-and-risks.md` #10 (booster `location`) | Not blocking — the design works for remote and ED walk-in |
| `05-open-questions-and-risks.md` risk "No-auth `callAI` surface abuse" | Promoted from a Stage-6 mitigation to §8, with a concrete mechanism |
| `01-architecture.md:142` ("existing OTP email flow is kept for the remote booster") | ✅ **Revised 2026-08-17** — now states the OTP flow is retired, names the two session hosts, and links here |
| `02-data-model.md §3` / `:211-213` (auth fields on the Demographics/auth instrument) | ✅ **Revised 2026-08-17** — contact fields already exist on `baseline1`, no OTP fields needed, chat-host and randomized-arm reality recorded |
| `06-implementation-plan/stage-0-foundations.md:92-96` | Already drops the Twilio settings and dead no-auth AJAX actions — extend to the full deletion list in §6.1 |
| `06-implementation-plan/stage-2-session-engine.md` §2.3 | ✅ **Rewritten 2026-08-17** — split into "already present, do not build" vs "still to build (G1/G4/G5)", plus the Survey Login configuration replacing the OTP instruction |
| `06-implementation-plan/stage-6-notifications-launch-gates.md:70-71` | Security pass narrows to §8 items once the OTP surface is gone |
| `04-test-plan.md:51-56` | E2E scenarios re-written from "OTP login" to "invitation link + Survey Login"; add lockout and expired-link cases |
| **All docs referring to `ui_hosting_instrument`** | **Revise** — that instrument does not exist in PID 257; the hosts are `mica_ed_session` + `mica_booster_session` (§6.1, audit G1) |
| [`09-pid-257-structure-audit.md`](../../archive/09-pid-257-structure-audit.md) | New — the as-built audit this revision is based on; also lists the researcher-side items (G2, G6, §5) |

New REDCap configuration this implies for PID 257 (all inside the approved change budget):

| Setting | Value | Scope |
|---|---|---|
| **Add one descriptive field to `mica_ed_session` and `mica_booster_session`, then "Enable as survey"** | the SPA mount point; both are currently `_complete`-only placeholders | instrument |
| `survey_auth_enabled` | 1 | project |
| `survey_auth_apply_all_surveys` | 0 | project |
| `redcap_surveys.survey_auth_enabled_single` | 1 on **both** MICA chat surveys only (leaves the other 16 surveys ungated) | survey |
| `survey_auth_field1` + `event_id1` = `last_name` @ Day 1 **arm 1** (1004); `field2`/`field3` **NULL**; `survey_auth_min_fields = 1` | ⚠️ **exactly one slot** — a second slot is a bypass (§6.1c). Applied and verified: `10 §1.3`. | project |
| `survey_auth_fail_limit` / `survey_auth_fail_window` | e.g. 5 / 15–30 min (§6.3) | project |
| `survey_time_limit_days/hours/minutes` | non-zero on **both** chat surveys — required for gate 2 to run at all | survey |
| `redcap_surveys_participants.link_expiration` | **written by the module at link issuance** (§3.3) | per participant |
| Twilio account SID / token / from-number + `twilio_delivery_preference_field_map` → map to **`baseline1.choice_fup_delivery`** | study's approved Twilio account. `twilio_enabled = 0` on PID 257 today, but `twilio_enabled_global = 1` system-wide, so this is configuration not a blocker (audit G7) | project |
| ASI on `mica_booster_session` | fires when the Month-3 window opens, driven by `admin.calc_month_3` | project |
| `$autologout_timer` | ⚠️ **system-wide REDCap setting, currently 30 min** — this is the Survey Login idle timeout (`Classes/Survey.php:2263-2267`). Confirm 30 min is acceptable for MICA; **do not change it for MICA**, since it affects every project and every user session on the instance. | system |

---

## 11. Feasibility spike — executed 2026-08-17

Run against the local REDCap 17.2.3 on scratch project **254** ("Test Survey UI",
Development, 0 records) with a temporary record `SPIKE1`. Nothing was committed to the
branch; PID 254's configuration was captured beforehand and **restored afterwards**
(verified: `survey_auth_enabled=0`, `apply_all_surveys=1`, all other `survey_auth_*` NULL,
`survey_auth_enabled_single=0`, 0 data rows, spike participant/response rows removed).
PID 257 was not touched.

| ID | Question | Result |
|---|---|---|
| S1 | Is the `redcap_surveys_response` row always present, or is the `ERROR: Could not find response_id!` hard-exit (`Surveys/index.php:1395`) reachable? | ✅ **PASS.** `REDCap::getSurveyLink()` created participant row `2105` **and** response row `2238` at link-generation time (0 response rows before the call). The hard-exit path is not reachable in this flow. **B/C are not blocked.** |
| S2 | Does forced `save_and_return = 1` + the return-code auto-post conflict with the SPA? | ⚠️ **No blocker, two implications.** After a *successful login* the survey body renders in the same response (`<title>Basic Demography Form</title>`, `questiontable` present). But **cookie-based re-entry** returns a self-submitting form posting `__code` + `__response_hash__` (`document.form.submit()`), so (a) re-entry reaches the chat page via **POST**, and (b) Save & Return is force-enabled, so return codes exist on the chat survey and REDCap may show its Save-&-Return chrome — MICA already hides native chrome in `redcap_survey_page_top` and would need to cover that too. Full confirmation with the real SPA belongs in Stage 2. |
| S3 | Are the survey-login session globals in scope in a REDCap request? | ✅ **PASS.** `$salt` set, `$GLOBALS['salt2']` set (128 chars, persisted in `redcap_config` and rehydrated per request — `Classes/System.php:655-672`), `$password_algo = sha512`, `$autologout_timer = 30` min. |
| S3b | Can the module actually *verify* survey-login state for a record server-side? | ✅ **PASS — the key result.** Recomputing `hash($password_algo, "$pid\|$record\|$salt\|$salt2")` reproduced the issued `survey_login_pid254` cookie **exactly**. §8.2 step 2 is implementable, which is what upgrades R9 from "a survey hash was present" to "this participant authenticated for *this* record". |
| S4 | Does per-survey scoping work? | ✅ Confirmed in source and by configuration: `survey_auth_apply_all_surveys=0` + `redcap_surveys.survey_auth_enabled_single=1` gates only that survey (`Surveys/index.php:1367`, `Classes/DataEntry.php:3549`). Re-verify on PID 257's multi-arm structure at implementation time. |
| S5 | Lockout end to end | ✅ **PASS, precise behaviour recorded.** With `fail_limit=3`/`fail_window=30`: wrong credential on attempts 1–2 re-showed the login form; **attempt 3 returned "access denied" and removed the form**; attempt 4 stayed denied and was *not* recorded (the lockout check exits before the attempt is logged — 3 rows in `redcap_surveys_login`, not 4). ⚠️ **There is no admin "unlock" action.** The mechanism is a **sliding window**, not a timed lock: the query counts only failures newer than `fail_window` minutes (and only since the last success, `Surveys/index.php:1420-1435`), so access returns once the *old attempts age out of the window* — the participant must simply stop retrying for that long. The staff runbook must say this correctly (see §6.3). |
| S6 | Does `survey_time_limit_*` fire for a module-generated link? | ❌ **NO — corrected the draft.** With the survey-level limit enabled and `link_expiration` NULL (the state `getSurveyLink()` leaves), the survey **rendered normally**: `checkSurveyTimeLimit()` returns `true` on an empty value (`Classes/Survey.php:3180`). |
| S6b | Does gate 2 actually deny once `link_expiration` is set? | ✅ **PASS — positive and negative both proven.** `link_expiration` 2 h in the **past** → survey did **not** render (denied); 2 h in the **future** → rendered. Also measured: `getSurveyLink()` writes `participant_email` as an **empty string, not NULL**, so the `participant_email is not null` conditions in both the survey-login response lookup and `getLinkExpirationTimes()` are satisfied. Together with the cached-value precedence at `Classes/Survey.php:3194-3204`, this establishes that **the module can write `link_expiration` itself** and have gate 2 enforce it on every delivery path — which is what resolved the ED-fallback trade-off in §6.1. |
| — | Gate ordering, empirically | ✅ **PASS.** An unauthenticated GET of the participant link returned the **login form only** (`survey-auth-submit`, `survey_auth_form`, a masked `dob` input) with **no survey content**. Confirms the source reading that the chat UI and its bootstrap JSON cannot render pre-auth. |
| S8 | **Multi-arm credential resolution** (added 2026-08-17, after the PID 257 audit) | ⚠️ Constraint found — §6.1c. Initially analysed as the arm filter (`Classes/Survey.php:2040-2057`) returning `false` and producing a blank page. **Superseded during implementation:** the arm filter never fires in this project, and the real behaviour is the blank-credential match (S9). See §6.1c's correction box and `10 §3`. |
| S9 | **Does a blank submitted credential match a blank stored value?** (implementation, 2026-08-17) | 🔴 **YES — and it decided the shipped configuration.** No empty-value guard in the comparison (`Surveys/index.php:1470-1481`); blank-valued credentials are also stripped from the rendered form (`Classes/Survey.php:2066-2075`). Verified twice: blank POST against a record with no stored credential ⇒ `Survey Login Success`, cookie issued, survey rendered. Consequence: a per-arm two-slot config is **bypassable by design** (confirmed on PID 257), so **exactly one credential slot** ships, and the module must refuse to issue a link when the credential is blank. |
| — | Audit trail, empirically | ✅ **PASS.** `Survey Login Failure` ×3 then `Survey Login Success` written against `pk = SPIKE1` in REDCap's own sharded log table (`redcap_log_event15` for that project), including which fields were used. Outside module control — satisfies R7. |

**Net effect on the recommendation:** unchanged and strengthened. The two potential
blockers (S1, S3) both cleared; the strong server-side identity check (S3b) is confirmed
available; and S6/S6b turned an apparent constraint into a design improvement — because the
module can write `link_expiration` itself, link expiry applies on **all** delivery paths,
which is what let §6.1 name SMS-in-the-bay as the ED default with QR/access-code as a
fallback that gives up nothing. One operational gap was identified (S5: no admin unlock,
sliding window), and two SPA-integration implications (S2) were handed to Stage 2.

Spike artifacts (throwaway scripts, captured HTML responses) live in the session scratchpad
and are not part of the repo; the two temporary records were deleted.

### Two items the spike could not close

| ID | Item | Why it is still open |
|---|---|---|
| S7 | SMS delivery end to end (`SMS_INVITE_WEB`) | No Twilio credentials on this instance. Also unverified: whether a **Twilio-delivered** invitation populates `redcap_surveys_scheduler_queue.time_sent` and therefore derives `link_expiration` automatically. Not a risk to the design — the module writes `link_expiration` itself (§3.3) — but confirm on the study's Twilio account. |
| S2 | Post-login POST re-entry and forced Save & Return against the real MICA SPA | Needs the EM enabled on a survey with the chat UI; belongs in Stage 2, not discovery. |

**Pilot evidence:** none is reachable from this environment — the only MICA project on this
instance is `MICA_R01` (PID 257, Development, 0 records); there is no MICA 1.2 pilot
project or EM log history here. Quantifying today's OTP failure rate would need access to
the production pilot project's REDCap logs. Recommend requesting it, but the design
argument does not depend on it.

---
