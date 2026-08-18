# MICA 3.0 Participant Authentication — Engineering Review Request

**For:** MICA / REDCap engineers (pre-PI review)
**Status:** implemented in Development on PID 257, verified, **not yet reviewed**
**Target:** REDCap 17.2.3 · project PID 257 `MICA_R01` · module `proj_mica v9.9.9`
**Date:** 2026-08-17

Please read §6 and §7 — that is what I actually want challenged. Everything above it is context
so you can challenge it credibly. Full detail lives in
[`08-auth-discovery.md`](08-auth-discovery.md) (design + options),
[`10-auth-implementation-pid257.md`](10-auth-implementation-pid257.md) (what was applied and why),
[`11-auth-manual-test-guide.md`](11-auth-manual-test-guide.md) (how to test it yourself).

---

## 1. What changed, in one paragraph

The pilot's custom two-step login (name + email → 6-hex-char code emailed → code entered) is
**retired**. Participants now reach the chat through a **record-bound REDCap survey link** and are
challenged by **REDCap-native Survey Login** before any chat content renders. All of the
security-critical machinery — credential matching, failed-attempt lockout, audit logging, session
lifetime, link expiry — is REDCap core rather than our code. Roughly 120 lines of custom auth get
deleted.

## 2. Why we did not just harden the existing OTP

The pilot flow has four defects that are in the code as shipped, not hypotheticals:

| Finding | Evidence |
|---|---|
| `verifyEmail()` resolves the participant **by submitted code alone** — never binds to the name/email that requested it | `MICA.php:561-600` |
| `two_factor_code_ts` is written and **never read** — codes never expire | `MICA.php:527-554` |
| Code is **never cleared on use** — the set of permanently-valid codes grows with enrollment, dividing the effective 24-bit search space by that count | `MICA.php:582-596` |
| No attempt limit, lockout, or resend throttle | `pages/chatbot.php:43-80` |

Two more that survive any front-door change, and are still open (§5): the post-OTP redirect is a
**permanent bearer link**, and `redcap_module_ajax` takes `participant_id` from the **client
payload** (`MICA.php:306-372`), so any authenticated survey context can act on any record.

Hardening in place would have fixed the first four but left record mapping as a first-name +
email string match that throws on duplicates — the SOW explicitly asks for deterministic record
mapping. The chosen design gets that *by construction* (link → `participant_id` → record).

## 3. The mechanism, in execution order

All four gates run **before** our hooks, so the chat UI and its bootstrap JSON cannot render or
leak pre-auth. Verified empirically as well as by reading source.

| # | Gate | Source | Configured by |
|---|---|---|---|
| 1 | Survey-level expiration | `Surveys/index.php:1021` | `redcap_surveys.survey_expiration` |
| 2 | Per-participant link time limit | `:1097` → `Survey::checkSurveyTimeLimit()` (`Classes/Survey.php:3172`) | `survey_time_limit_*` + `redcap_surveys_participants.link_expiration` |
| 3 | **Survey Login** | `:1367-1540` | `redcap_projects.survey_auth_*` + `redcap_surveys.survey_auth_enabled_single` |
| 4 | our hooks render the SPA | `:3455` / `:3847` | the module |

Survey Login specifics worth knowing:

- **Scoped per survey.** `survey_auth_apply_all_surveys = 0` + `survey_auth_enabled_single = 1`
  on the two MICA hosts only; the other 18 surveys stay ungated.
- **Matching** is `strtolower()` equality against stored data (`:1477`) — case-insensitive,
  **not** trimmed.
- **Lockout** is a *sliding window*: failures within `fail_window` minutes and since the last
  success (`:1420-1435`). There is **no admin unlock** — it clears by waiting.
- **Audit**: `Survey Login Success` / `Survey Login Failure` written to REDCap's own log
  (`:1498`, `:1516`), sharded — PID 257 uses `redcap_log_event15`.
- **Session**: two cookies. `survey_login_pid<pid>` expires after `$autologout_timer`
  (system-wide, currently 30 min); `survey_login_session_pid<pid>` dies with the browser. Both
  must match. Value is `hash($password_algo, "$pid|$record|$salt|$salt2")` — which is why §5.3 is
  possible.

## 4. Exactly what is configured on PID 257

```
redcap_projects (257)
  survey_auth_enabled            = 1
  survey_auth_apply_all_surveys  = 0
  survey_auth_field1             = last_name
  survey_auth_event_id1          = 1004        -- Day 1 (ED) ARM 1, pre-randomization
  survey_auth_field2/3           = NULL        -- load-bearing, see 6.1
  survey_auth_min_fields         = 1
  survey_auth_fail_limit/window  = 5 / 30 min
  survey_auth_custom_message     = help text (error-state only)

redcap_surveys 1315 mica_ed_session / 1316 mica_booster_session
  survey_enabled = 1, survey_auth_enabled_single = 1
  save_and_return = 1, edit_completed_response = 1   -- see 6.3
  repeat_survey_enabled = 1                          -- see 6.4
  hide_title = 1
  survey_time_limit = 24h (ED) / 14d (booster)       -- inert today, see 5.1

redcap_events_repeat: (1008|1012, mica_ed_session), (1009|1014, mica_booster_session)
redcap_metadata: one descriptive mount-point field added per host instrument
```

Module side: new `chat_host_instruments` project setting (comma-delimited, default
`ui_hosting_instrument`, so pilot projects are unaffected) replaces the hardcoded instrument gate;
bootstrap made null-safe; REDCap's `.surveysubmit` chrome hidden on chat hosts.

**Reproducible:** `docs/phase-3-handoff/scripts/apply-auth-config.php` (idempotent) and
`verify-auth-config.php` (independent, 46 assertions, exit-code contract, negative-tested by
swapping in a wrong instrument → 6 failures). Rollback SQL in `10 §5`.

## 5. What is deliberately NOT true yet

**Please do not read "Survey Login enabled" as "done".**

1. **The link does not expire.** Gate 2 needs `redcap_surveys_participants.link_expiration`, and
   `checkSurveyTimeLimit()` returns *allow* when it is empty (`Classes/Survey.php:3180`). REDCap
   only populates it for **invitations it actually sent** (`:3207-3237`) or via
   `changeLinkExpiration()` (`:3245-3252`). A link from `REDCap::getSurveyLink()` has it NULL —
   measured. So the configured time limits currently do nothing.
2. **Therefore today's posture is one factor plus a non-expiring link** — i.e. the pilot's
   permanent-bearer-link problem relocated, not resolved.
3. **AJAX identity is still client-asserted.** Unchanged from the pilot.
4. Twilio/SMS is not configured on the project (not blocked — `twilio_enabled_global = 1`), and
   there is no booster ASI yet.

Required module-side work, in priority order:

| # | Work | Closes |
|---|---|---|
| M1 | Write `link_expiration` at link issuance (same column REDCap's own admin action writes) | 5.1 / 5.2 |
| M2 | Refuse to issue a link when the credential value is blank for that record | 6.1 residual |
| M3 | Resolve the record **server-side** from the survey context; ignore payload identifiers; additionally verify the Survey Login cookie by recomputing the hash for that record | 5.3 |
| M4 | Delete `loginUser` / `verifyEmail` / `generateOneTimePassword` / `pages/chatbot.php` | dead surface |
| M5 | Refuse withdrawn (`admin.study_withdrawn`) and SMS-opted-out (`admin.sms_stop`) participants | protocol |

## 6. The four decisions I most want you to check

### 6.1 Exactly one credential slot — because a second one is a login bypass

**This is the single most important thing in the design and it is counterintuitive.**

REDCap treats a **blank submitted credential as matching a blank stored value**. There is no
empty-value guard in the comparison (`Surveys/index.php:1470-1481`; `$fieldsWithValues` is
collected but used only in the log message), and blank-valued credentials are *removed from the
rendered form* entirely (`Classes/Survey.php:2066-2075`).

Consequence: because credential slots are `(field, event)` pairs and events are arm-specific, the
"obvious" multi-arm configuration — `last_name @ arm 2` + `last_name @ arm 3`, `min_fields = 1` —
guarantees that one slot points at an event where the participant has no data. **Posting
`last_name=` matches it.** Demonstrated on PID 257: two slots + blank POST → chat rendered; one
slot + blank POST → rejected.

So `survey_auth_field2/3` **must stay NULL**, and `verify-auth-config.php` asserts that
specifically. Residual risk: a single slot is only blank-safe for records whose credential is
populated — hence M2.

**Asks:** (a) does this match your understanding of REDCap's behaviour? (b) is this worth
reporting upstream to Vanderbilt? (c) is M2 sufficient mitigation, or do you want a stronger
server-side check?

### 6.2 The credential is `last_name` read from the **arm-1** Day-1 event

PID 257 collects **no date of birth**, so the usual knowledge factor is unavailable. `last_name`
lives on `baseline1`, and Ihab confirmed baseline is entered **before randomization** — so the
value sits at Day 1 (ED) **arm 1** (event 1004) for everyone. Because the configured credential
event is absolute (not relative to the survey being opened), that one slot authenticates sessions
hosted at arm-2 *or* arm-3 events. Verified end-to-end.

Consequence worth knowing: `REDCap::getSurveyLink()` requires the record to exist **in the
requested event's arm** (`Classes/REDCap.php`), so a session link cannot be issued until the
participant is randomized. Order is: baseline (arm 1) → randomize → link.

**Asks:** (a) is `last_name` an acceptable knowledge factor, or should we push for the
paper-passcode option in `08 §6.2`? (b) matching is case-insensitive but **whitespace-sensitive**,
and the input is masked — a mobile keyboard's trailing space fails invisibly. Verified. Do you
consider that an acceptable support burden?

### 6.3 `edit_completed_response = 1` is load-bearing

The host surveys have no real questions, so a single page submit completes the response — I
observed a successful login POST write `mica_booster_session_complete = '2'`. With
`edit_completed_response = 0` (the default we inherited from the cloned template), a completed
response makes the participant's own link dead: *"you have already completed this survey"*, no
prompt, no way back in. With it on, re-entry still requires authentication, because REDCap's gate
for a completed response is `$responseCompleted && $save_and_return && $edit_completed_response`.

**Ask:** any objection to Save & Return + Edit Completed Response being on for these two surveys?

### 6.4 Repeating host instruments — permanent design, or dev convenience?

Both hosts are now repeating instruments with Repeat Survey enabled, so each session attempt gets
its own instance, its own link, and its own login. (Note `getSurveyLink()` silently clamps
`instance` to 1 unless the form actually repeats.)

This has a data-model consequence I have flagged but **not** resolved
(`02-data-model.md`): session fields become keyed by `(record, event, instance)`, and — the one
that fails *silently* — the scan-job `idempotency_key` currently hashes
`project_id|record|session_type|transcript_sha256`. **Without the instance, two sessions in the
same window collide or one is dropped as a duplicate.**

**Ask:** is multiple chat sessions per window clinically intended? If not, we should revert
repeating (SQL in `10 §5`) before it propagates into the transcript/scan design.

## 7. Other things I would like a second opinion on

1. **Should we own link expiry, or route all invitations through REDCap?** M1 writes
   `link_expiration` ourselves (what REDCap's admin action does), which makes expiry work on
   *every* delivery path including QR/access-code. The alternative is mandating ASI/participant-list
   invitations so REDCap derives it. I prefer M1; it is less brittle operationally.
2. **`$autologout_timer` is system-wide.** 30 min is the Survey Login idle timeout and we cannot
   tune it per project. Acceptable for a ~15-min session?
3. **Submission is now suppressed twice** — the SPA's `blockSubmit()` JS and a CSS rule hiding
   `.surveysubmit`. Correct today (End Session goes through AJAX), but Stage 2 should pick one
   deliberately.
4. **Dead code:** `window.renderMicaApp` does not exist in the built bundle, so `tryMount()` and
   `unmask()` never fire; the SPA self-mounts from `main.jsx`. Harmless now, but if a future build
   exports `renderMicaApp`, `unmask()` would start stripping the chrome-hiding CSS.
5. **`pages/chatbot.php:68`** still hardcodes `ui_hosting_instrument` — latent break now reachable
   via the new setting. Slated for deletion in Stage 2; flagging in case that slips.

## 8. Try it yourself (5 minutes)

Test record `1` (Alex Rivera) is live on PID 257, set up the way a real participant arrives:

- **ED session:** `http://redcap.local/surveys/?s=dpfyLogr3N2xADIa` — credential: last name `Rivera`
- ED instance 2 (fresh login): `...?s=mJoCwHWYry6rUeRW` · Booster: `...?s=kayrxUfkfAtS72E8`
- Ungated control (must NOT prompt): `baseline1` `...?s=vsIFgFtbZKowcboU`

Worth trying: empty submission (must be rejected), 5 wrong tries (lockout, and the correct value
is then refused too), and reload vs full browser restart. The chat renders but **sending a message
will not work** — no SecureChatAI, no session engine; that is Stage 1/2.

```bash
docker cp docs/phase-3-handoff/scripts redcap_2023_1_web:/var/www/html/temp/
docker exec redcap_2023_1_web php /var/www/html/temp/scripts/verify-auth-config.php 257 last_name 1004
```

## 9. Verification status, honestly labelled

| Claim | How established |
|---|---|
| Gate ordering; chat cannot render pre-auth | source + HTTP (login form only, no chat content) |
| Blank credential matches blank stored value | **empirically, twice** — `Survey Login Success` + cookie issued |
| Two-slot config is bypassable; one slot is not | **empirically on PID 257** |
| Lockout thresholds, sliding window, no admin unlock | empirically (5 attempts; correct value refused while locked) |
| Cookie is server-side verifiable per record | empirically — recomputed hash == issued cookie |
| Audit events land in REDCap's log | empirically (`redcap_log_event15`) |
| `link_expiration` NULL ⇒ no expiry; set ⇒ denies | empirically, both directions |
| Chat renders after auth, desktop + mobile 390×844 | Playwright, screenshots captured |
| SMS delivery end to end | **not tested** — no Twilio credentials on this instance |
| iOS Safari behaviour | **not tested** — matters for the whitespace failure mode |
| Behaviour at scale / with real records | **not tested** — project has only test records |
