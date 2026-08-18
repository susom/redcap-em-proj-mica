# Phase 3 Discovery — 2FA Replacement for MICA 3.0

**SOW item:** Discovery → "Identify a 2FA Replacement"
**Status:** DISCOVERY / RECOMMENDATION — awaiting PI + security sign-off. No code changed.
**Written:** 2026-08-17 · **Revised:** 2026-08-17 against the as-built PID 257 · **Branch:** `mica-phase-3`
**Verified against:** local REDCap **17.2.3** (`www/redcap_v17.2.3`, running instance, PID 257 `MICA_R01`)

> **Revision note.** The researcher-provided structure for PID 257 landed after the first
> draft. It was audited field-by-field — see
> [`09-pid-257-structure-audit.md`](09-pid-257-structure-audit.md) — and this memo revised
> against it. **The options analysis (§4) and ranking (§5) did not change**; everything
> load-bearing in the recommendation held. Three things did: the chat has no host instrument
> yet (§6.1), no date-of-birth field exists so the credential choice changed (§6.1), and the
> study has its own `check_code` passcode pattern that must be reconciled rather than
> duplicated (§6.1a).

## SOW obligations and where they are answered

| SOW clause | Section |
|---|---|
| Research & evaluate replacements for the current 2FA "without sacrificing system security" | §1 current state, §4 options, §5 matrix |
| Reliable record mapping (each participant resolves to a single REDCap record) | §2 R2, §4 (column "Record mapping"), §6 |
| Participant experience | §2 R5, §4, §6.3 |
| Compatibility with existing session-window routing | §9 |
| Notify the PI of findings | §12 (PI-facing summary, written to be pasted into email) |
| "If approved, built in Development. Otherwise authentication remains as-is." | §7 — why "as-is" is not a safe fallback, and what ships regardless |

---

## 0. TL;DR

1. **The current 2FA is weaker than its design implies.** `verifyEmail()` resolves the
   participant **by code alone** and never binds to the identity that requested the
   code; the stored timestamp is never checked, so codes never expire; codes are never
   cleared after use, so the set of permanently-valid codes grows with enrollment; and
   there is no attempt limit. See §1. This makes "authentication remains as-is" an
   unsafe fallback, independent of any PI decision.
2. **The front door is not the only problem.** After OTP the participant is redirected
   to a persistent, record-bound survey link that bypasses OTP on every later visit,
   and the module's AJAX endpoints take `participant_id` from the **client payload**.
   Replacing the OTP without fixing those two leaves the actual access path unchanged. See §8.
3. **REDCap 17.2.3 already implements, in core, every security-critical piece we
   currently hand-roll** — participant-scoped login on a single instrument, N-of-M
   credential matching, failed-attempt lockout, audit logging, an idle-timeout session,
   time-limited record-bound links, and SMS delivery. All of it executes **before** the
   MICA chat UI renders. Verified in source (§3) **and executed end-to-end against the
   local instance** (§11): unauthenticated access rendered the login form with no survey
   content, lockout fired exactly at the configured limit, link expiry denied an expired
   link, and every attempt landed in REDCap's own audit log. The spike also corrected one
   assumption: link expiry does not fire unless `link_expiration` is set, so the module must
   write it at issuance (§3.3) — which in turn makes expiry work on every delivery path.
4. **Recommendation (§6): retire the custom OTP entirely** and authenticate the chat
   with (a) a record-bound, time-limited participant survey link delivered over the
   participant's preferred channel (email or SMS — Twilio is approved), plus (b) native
   **Survey Login** scoped to the two MICA chat surveys. This deletes ~120 lines of
   custom auth, removes the no-auth login page, and makes record mapping deterministic
   *by construction* instead of by name+email string matching.
5. **The open policy question does not block the build.** Whether we require one factor
   or two is a REDCap **project setting** (`survey_auth_min_fields`, or Survey Login
   off), not an architecture. Engineering cost is identical across the three tiers in
   §6.2, so implementation can proceed while Stanford security/privacy and the IRB
   decide the tier.

---

## 1. Current state (MICA 1.2 / this branch)

### 1.1 The flow as built

```
pages/chatbot.php (no-auth page)
  welcome → "Continue"
  → login view: first name + email
  → MICA::loginUser()          matches a record with
                               filterLogic [participant_name] AND [participant_email]
  → MICA::generateOneTimePassword()
                               code = bin2hex(random_bytes(3))         (6 hex chars)
                               saveData two_factor_code, two_factor_code_ts
                               REDCap::email(code)
  → otp view: code
  → MICA::verifyEmail()        filterLogic [two_factor_code] = '<submitted code>'
  → redirect to REDCap::getSurveyLink(record, 'ui_hosting_instrument', baseline_arm_1)
  → survey hosts the React SPA (redcap_survey_page)
  → SPA talks to redcap_module_ajax (callAI / fetchSavedQueries / completeSession)
```

### 1.2 Findings

| # | Finding | Evidence | Severity |
|---|---|---|---|
| 1 | **Verification is not bound to the requesting identity.** `verifyEmail()` looks the record up by submitted code across all records; the `name`/`email` held in `$_SESSION` from the previous step are never used. Any outstanding code authenticates whoever submits it, as that code's record. | `MICA.php:561-600`; `pages/chatbot.php:62-70` | High |
| 2 | **Codes never expire.** `two_factor_code_ts` is written (`MICA.php:536`) and never read anywhere in the module. | `MICA.php:527-554`, `MICA.php:561-600` | High |
| 3 | **Codes are never invalidated on use.** No clear/rotate after a successful verify, so every code ever issued stays valid until that record logs in again. The population of live codes grows monotonically with enrollment, dividing the effective search space by the number of enrolled participants. | `MICA.php:582-596` | High |
| 4 | **No attempt limit, lockout, or throttle** on the OTP submission, and no resend throttle on `loginUser` (each call overwrites the code and sends an email). Code entropy is 24 bits (`random_bytes(3)`); combined with #2/#3 an online search is cheap. | `pages/chatbot.php:43-80` | High |
| 5 | **Post-OTP credential is a permanent bearer link.** `REDCap::getSurveyLink()` returns a record-bound URL with no expiry; once obtained (bookmark, browser history, shared device, forwarded message) it re-enters the chat with no OTP. There is no server-side session tying the chat to a verified login. | `pages/chatbot.php:65-70` | High |
| 6 | **Participant identity on the AJAX surface is client-asserted.** `redcap_module_ajax` authorizes on `!empty($survey_hash)` and then takes `participant_id` / `user_id` from the request payload — so any survey context can read, write, or close another participant's session. `fetchSavedQueries` additionally checks a name string; `callAI` and `completeSession` check nothing. | `MICA.php:306-372`, `MICA.php:446-470`, `MICA.php:812-870` | High |
| 7 | **Record mapping is a string-equality match, not a resolution.** First name + email equality; duplicates throw ("duplicate entries for …"); typos, case/whitespace differences, name changes, or a shared household email all break or block login. No canonical participant identity. | `MICA.php:482-518` | Med (SOW-named) |
| 8 | Unescaped user input interpolated into `filterLogic` strings in `loginUser()`, `verifyEmail()`, `fetchSavedQueries()`. | `MICA.php:493`, `MICA.php:573`, `MICA.php:456-459` | Med |
| 9 | OTP stored in plaintext in a REDCap field — readable by anyone with data-view rights on that instrument, and captured in the data audit trail and in exports. | `MICA.php:531-540` | Med |
| 10 | UI labels the code "6-digit" but `bin2hex()` produces hex (`a3f0b1`); `autocomplete="one-time-code"` and mobile numeric keypads mislead. | `pages/chatbot.php:209-214` vs `MICA.php:531` | Low (UX) |
| 11 | The post-OTP redirect always targets the **baseline** event link regardless of the active session window; the active session is then recomputed server-side inside the survey. Works, but hosting responses all accumulate on the baseline event and the URL carries no window information. | `pages/chatbot.php:67`, `MICA.php:617-677` | Observation |

### 1.3 What is *not* wrong (checked, so it is not repeated as a finding)

- **The `filterLogic` interpolation is not an authentication bypass.** Both call sites
  compare the *returned* value back against the submitted string
  (`$check['two_factor_code'] === $code`, `MICA.php:582`;
  `$check['participant_name'] === $name`, `MICA.php:512`), which defeats a tautology
  injection — an injected predicate that returns every record either trips the
  `count() > 1` exception or fails the final comparison. Finding #8 stands as an
  injection-hardening and error-disclosure item, not a bypass.
- CSRF tokens are present on every form in `pages/chatbot.php`.
- Completed participants are blocked from re-entry (`MICA.php:505-508`), and study/session
  completion is enforced in `getSystemContextForRecord()`.

---

## 2. Requirements used to score the options

| ID | Requirement | Source |
|---|---|---|
| R1 | No loss of security relative to the intended (not actual) 2FA design | SOW |
| R2 | **Deterministic record mapping** — a session resolves to exactly one REDCap record *by construction*, not by matching participant-supplied strings | SOW |
| R3 | Works for a participant on **their own device, in the ED**, at Day 1 (answered: participant's own device) | Open question #7 |
| R4 | Works for the **remote Month-3 booster** on the same participant's device | R01 session model |
| R5 | Participant experience: no account, no app install, minimal typing, accessible on a phone, tolerant of a bad ED network | SOW |
| R6 | Compatible with session-window routing (baseline vs booster, window open/closed) | SOW |
| R7 | Auditable — successful and failed access attempts recorded outside the module's own log | Phase-3 review workflow |
| R8 | Session must expire; the entry URL must not remain a standing credential | Finding #5 |
| R9 | Server-side participant identity for every AJAX action | Finding #6 |
| R10 | Minimise custom security code we own and must maintain | Engineering |

### Elimination filters (applied before the matrix)

- **F1 — No JS-driven auth on a no-auth page.** JSMO is blocked on no-auth pages from
  REDCap 15.5.x onward; this is why commit `9b69d2c` moved the login flow out of the
  React app into `pages/chatbot.php`. Any option that needs module JS before
  authentication is dead on arrival.
- **F2 — Participants cannot hold REDCap accounts.** Rules out REDCap user auth and
  Stanford SSO/SUNet for participants.
- **F3 — No app install.** Rules out MyCap as the auth vehicle for a web chat SPA.

---

## 3. What REDCap 17.2.3 provides natively (verified in source)

Everything below is core REDCap on the instance we are targeting. The **execution order
matters**: all four gates run before either MICA survey hook fires, so the chat UI and its
bootstrap JSON cannot render — or leak — before authentication.

| Order | Gate | Where | Configured by |
|---|---|---|---|
| 1 | Survey-level expiration | `Surveys/index.php:1021` | `redcap_surveys.survey_expiration` |
| 2 | **Per-participant link time limit** — link dies N days/hours/minutes after the participant's initial invitation. ⚠️ **Only fires for links whose invitation was actually sent through REDCap** — see §3.3 | `Surveys/index.php:1097` → `Survey::checkSurveyTimeLimit()` (`Classes/Survey.php:3172`) | `redcap_surveys.survey_time_limit_days/hours/minutes` + `redcap_surveys_participants.link_expiration` |
| 3 | **Survey Login** | `Surveys/index.php:1367-1540` | `redcap_projects.survey_auth_*` + `redcap_surveys.survey_auth_enabled_single` |
| 4 | MICA renders | `redcap_survey_page_top` = `Surveys/index.php:3455`; `redcap_survey_page` = `:3847` | the module |

### 3.1 Survey Login — exact semantics

- **Scoped to one instrument.** `survey_auth_apply_all_surveys = 0` plus
  `redcap_surveys.survey_auth_enabled_single = 1` on the chat survey only, so enabling it
  does not gate the other 26 instruments in PID 257. (`Surveys/index.php:1367`,
  `Classes/DataEntry.php:3549`)
- **Up to 3 credential fields, each with its own event**, and an
  **N-of-M** threshold: `survey_auth_field1..3` / `survey_auth_event_id1..3` /
  `survey_auth_min_fields` (1–3). Longitudinal-safe by design.
  (`Classes/Survey.php:2227-2253`)
- **Matching** is case-insensitive string equality against the stored record data, with
  automatic MDY/DMY→YMD conversion for date-validated text fields, so a date credential
  tolerates whatever format the participant types. Non-date credentials are matched by
  case-insensitive **exact** string equality (`Surveys/index.php:1477`) — no trimming or
  normalisation beyond case, which matters for name fields (§6.3).
  (`Surveys/index.php:1460-1485`)
- **Lockout**: `survey_auth_fail_limit` failures within `survey_auth_fail_window`
  minutes → access denied, no login form shown. Attempts are counted per
  `response_id` and only since the last success. (`Surveys/index.php:1420-1435`,
  `:1523-1531`; table `redcap_surveys_login`)
- **Audit**: every attempt writes a REDCap log event — `Survey Login Success` /
  `Survey Login Failure`, naming which fields matched — into REDCap's own logging,
  outside the module's control. Satisfies R7. (`Surveys/index.php:1498`, `:1516`)
- **Session**: two cookies, `survey_login_pid<pid>` (expires after the auto-logout
  timer) and `survey_login_session_pid<pid>` (expires when the browser closes). **Both**
  must be present and equal or the participant must log in again. Value is
  `hash($password_algo, "$project_id|$record|$salt|$salt2")` — a server-secret-derived,
  record-specific value, which is what makes R9 achievable (see §8.2).
  (`Surveys/index.php:1509-1512`, `:1368-1375`)
- **Idle timeout** = REDCap's `$autologout_timer`, default 30 minutes.
  ⚠️ **This is a system-wide REDCap setting, not per-project** — we can rely on it but
  cannot tune it for MICA alone. (`Classes/Survey.php:2263-2267`)
- **Caveat, resolved:** the gate requires an existing `redcap_surveys_response` row and
  hard-exits with `ERROR: Could not find response_id!` otherwise
  (`Surveys/index.php:1395`). `REDCap::getSurveyLink()` →
  `Survey::getFollowupSurveyParticipantIdHash()` inserts both the participant and
  response rows (`Classes/Survey.php:1687`, `:1755`), so the row always exists in our
  flow — **confirmed empirically**, §11 S1.
- **Second caveat, partially resolved:** on success REDCap forces `$save_and_return = 1`
  and, on later cookie-based re-entry, emits a self-submitting form posting the return
  code (`Surveys/index.php:1500-1508`, `:1537+`). The spike found no blocker but two
  implications for the SPA — re-entry arrives via POST, and Save & Return chrome must be
  hidden. See §11 S2; final confirmation with the real SPA belongs in Stage 2.

### 3.2 Delivery and possession factors

- **Record-bound participant links**: `REDCap::getSurveyLink()` creates a participant row
  with a unique `hash`, permanently tied to (record, survey, event, instance).
  (`Classes/Survey.php:1687-1760`)
- **Per-participant delivery preference**, including SMS:
  `redcap_surveys_participants.delivery_preference ∈ {EMAIL, VOICE_INITIATE,
  SMS_INITIATE, SMS_INVITE_MAKE_CALL, SMS_INVITE_RECEIVE_CALL, SMS_INVITE_WEB}`, with
  `participant_phone` on the same row and a project-level
  `twilio_delivery_preference_field_map`. Twilio is a first-class per-project
  integration (`redcap_projects.twilio_*`). **Twilio is approved for this study**, so
  SMS is available now.
- **Survey access codes**: `redcap_surveys_participants.access_code` (9 chars) and
  `access_code_numeral` (10 digits), both uniquely indexed, plus 6-char short codes
  (`redcap_surveys_short_codes`), generated on demand by `Survey::getAccessCodes()`
  (`Classes/Survey.php:2273+`) and surfaced at `Surveys/get_access_code.php`. This is a
  possession factor that needs **no delivery channel at all** — it can be read aloud or
  printed in the ED.
- **QR codes** for participant links: `Surveys/survey_link_qrcode.php`,
  `survey_link_qrcode_svg.php` — participant scans with their own phone, no typing, no
  email, no signal dependency beyond loading the page.

### 3.3 Link expiry: how gate 2 actually behaves, and how to make it fire

Gate 2 needs **two** things, and the spike (§11) tested all three states:

1. `survey_time_limit_days/hours/minutes > 0` on the chat survey — otherwise
   `checkSurveyTimeLimit()` returns early (`$timeLimitSeconds == 0`).
2. A non-empty `redcap_surveys_participants.link_expiration` for that participant —
   otherwise it also allows access:
   ```php
   if ($linkExpirationTime == "") return true;                     // Classes/Survey.php:3180
   if (strtotime(NOW) < strtotime($linkExpirationTime)) return true;
   return false;                                                   // expired -> denied
   ```

Measured (§11 S6, S6b): with the survey-level limit enabled but `link_expiration` NULL —
the state a plain `REDCap::getSurveyLink()` leaves behind, which is what
`pages/chatbot.php:65-70` does today — **the survey renders; the link never expires**.
With `link_expiration` in the past, **access is denied**; in the future, allowed. So the
gate works, but nothing sets the column for a module-generated link.

REDCap populates `link_expiration` in two ways:

- **Derived from a sent invitation** — `getLinkExpirationTimes()` joins
  `redcap_surveys_emails_recipients` + `redcap_surveys_scheduler_queue` on
  `time_sent is not null` and computes `time_sent + time_limit`
  (`Classes/Survey.php:3207-3237`). Only participants with an actually-sent invitation
  (participant list / ASI) get a value.
- **Set explicitly** — `Survey::changeLinkExpiration()` writes the column directly with
  `link_expiration_override = 1` (`Classes/Survey.php:3245-3252`); this is the admin
  action REDCap itself exposes in the participant list.

**Design consequence (load-bearing for §6):** the module should **write
`link_expiration` at link-issuance time**, exactly as REDCap's own admin action does, and
enable `survey_time_limit_*` on the chat survey. A stored value is authoritative — 
`getLinkExpirationTimes()` reads cached values first and removes those participants from
the recompute set (`Classes/Survey.php:3194-3204`), so it is never overwritten. This makes
gate 2 fire on **every** delivery path — SMS invitation, email invitation, QR code, or
access code — rather than only on invitation-derived links, and it is what allows §6.1's ED
fallback to keep its expiry. (`participant_email` must be non-NULL for these queries;
`getSurveyLink()` writes an empty string, not NULL, so the condition is satisfied —
verified §11 S6b.)

---

## 4. Options

| | Option | Mechanism | Factors | Record mapping | Custom security code we own |
|---|---|---|---|---|---|
| **A** | **Harden the existing email OTP in place** | Keep `loginUser`/`verifyEmail`; bind verify to the requesting record, hash the code, 10-min expiry, single-use, attempt lockout, resend throttle, numeric code | 2 (email possession + …nothing; email is the only factor in practice) | Unchanged: name+email string match (**fails R2**) | All of it |
| **B** | **Record-bound delivered link + native Survey Login** ⭐ | Participant link for the MICA chat survey, time-limited, delivered by email or SMS per `delivery_preference`; native Survey Login (N-of-M record fields — see §6.1 for which are available) scoped to that survey only | 2: possession (link to a verified channel) + knowledge (record fields) | **By construction**: link → `participant_id` → record | ~none (config + link issuance) |
| **C** | **Native access code + native Survey Login** | Participant goes to the survey short URL and enters their 9-char/10-digit access code (given in the ED, printed, or read aloud); then Survey Login | 2: possession (code) + knowledge (fields) | By construction | ~none |
| **D** | **SMS OTP via Twilio, custom EM code** | Replace the email channel of today's flow with SMS | 2 (phone possession + …) | Unchanged (**fails R2**) | All of it |
| **E** | **Custom signed magic link issued by the EM** | HMAC(record, window, nonce, exp) token, single-use, hash stored in an entity table; EM validates | 1–2 | By construction | New crypto + token store + expiry + replay handling |
| **F** | REDCap accounts / Stanford SSO for participants | — | — | — | *Eliminated by F2* |
| **G** | MyCap | — | — | — | *Eliminated by F3* |

Notes on the eliminated-in-practice options:

- **A** is the SOW's "authentication remains as-is" branch, hardened. It fixes findings
  #1–#4 but leaves #7 (R2) unsolved and keeps every line of auth code in our
  maintenance surface. It is the right *fallback*, and its hardening work is Track A
  (§7) regardless of which option wins.
- **D** buys better delivery for the ED population — and the audit confirms SMS is the right
  channel there (a texting-capable phone is an eligibility criterion) — but it changes
  nothing structural: the same code-only lookup, the same string-match record mapping, the
  same custom code. That same SMS benefit is available inside option B via
  `delivery_preference = SMS_INVITE_WEB` **without** the custom code, which is why B
  dominates D rather than trading off against it.
- **E** is the "build it properly ourselves" option. It is technically clean and gives us
  window-aware tokens, but it re-implements items 2 and 3 of §3 — link expiry and login
  session — in code we then own, review, and defend. Reach for it only if B/C are blocked
  by a REDCap behaviour the spike uncovers.

---

## 5. Decision matrix

Scoring: ✅ meets, ⚠️ partial / needs work, ❌ fails.

| Requirement | A (hardened OTP) | **B (link + Survey Login)** | C (access code + Survey Login) | D (SMS OTP) | E (custom magic link) |
|---|---|---|---|---|---|
| R1 no security loss | ✅ | ✅ | ✅ | ✅ | ✅ |
| R2 deterministic record mapping | ❌ string match | ✅ by construction | ✅ by construction | ❌ string match | ✅ by construction |
| R3 ED, own device | ⚠️ email-only delivery in an ED bay (SMS would require building it — which is option D) | ✅ SMS or QR in the room | ✅ no channel needed | ✅ SMS is reliable here — every participant has a texting phone by eligibility | ✅ |
| R4 remote booster | ✅ | ✅ | ⚠️ code must reach them | ✅ | ✅ |
| R5 participant UX | ⚠️ two steps, hex code typo-prone | ✅ tap link + 1–2 known fields | ⚠️ types a 9–10 char code | ✅ | ✅ |
| R6 session-window routing | ⚠️ always lands on the baseline link (#11) | ✅ per-event link; ASI can open the booster window | ✅ | ⚠️ same as A | ✅ window in the token |
| R7 external audit trail | ❌ module log only | ✅ REDCap log events | ✅ | ❌ | ⚠️ what we write |
| R8 session expiry / no standing URL | ⚠️ must be built | ✅ core: two-cookie session + 30-min idle timeout + link expiry (gate 2), on all delivery paths once the module writes `link_expiration` (§3.3) | ✅ same, via the same mechanism | ⚠️ must be built | ⚠️ must be built |
| R9 server-side AJAX identity | must be built (§8) | must be built (§8) — but verifiable against the core cookie | must be built (§8) | must be built | must be built |
| R10 minimal custom security code | ❌ ~120 lines retained | ✅ ~120 lines deleted | ✅ | ❌ | ❌ net new |
| Effort | Low | **Low–Med** (config + link issuance + deletions) | Low–Med | Low | High |

**B wins, with C as its ED fallback** (they share the same Survey Login half and can
coexist: a participant who cannot receive the link gets an access code instead).

---

## 6. Recommendation

### 6.1 Target design

> **Revised 2026-08-17** against the as-built PID 257
> ([`09-pid-257-structure-audit.md`](09-pid-257-structure-audit.md)). The options analysis
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
| [`09-pid-257-structure-audit.md`](09-pid-257-structure-audit.md) | New — the as-built audit this revision is based on; also lists the researcher-side items (G2, G6, §5) |

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

## 12. PI-facing summary

> **MICA 3.0 — login and verification: findings and recommendation**
>
> As part of Phase 3 discovery we reviewed how participants currently get into the MICA
> chat and evaluated alternatives.
>
> **What we found.** The current two-step login (name + email, then a code emailed to the
> participant) does not verify the code against the person who requested it, the codes
> never expire or get used up, and there is no limit on guessing attempts. Separately, the
> web address a participant lands on after entering their code keeps working indefinitely,
> so a shared or bookmarked link re-enters the chat without a code. We also found that a
> participant is matched to their study record by comparing the first name and email they
> type against what is stored — so a typo, a name change, or a shared family email address
> can block a participant or, in the case of duplicates, stop the login entirely.
>
> **What we recommend.** Retire the custom code-by-email step and use REDCap's own
> built-in participant login instead. The participant gets a private link to their own
> session — by text message (preferred in the ED, since it arrives on the phone in their
> hand) or by email — and REDCap then asks them to confirm one or two details we already
> hold in their record, such as date of birth, before the chat opens. The link expires
> after the visit, and the session times out after 30 minutes of inactivity.
>
> **Why this is better.** Each participant's link points to exactly one study record, so
> the "which record is this?" problem disappears by design rather than being solved by
> name matching. Every login attempt, successful or failed, is recorded in REDCap's own
> audit log, and repeated failed attempts lock the session automatically. It also removes
> a large amount of custom security code that we would otherwise have to maintain and
> defend — the protections come from REDCap itself.
>
> **What it costs the participant.** One extra detail to confirm at login, and they will
> need to log in again if they return the next day rather than the link simply working.
> A participant who cannot give the detail exactly as it is recorded will be locked out
> after a few tries; the lock clears itself after a short waiting period, and we will give
> the CRC a documented way to help (correct the stored value, or hand over an access code).
> We tested this behaviour on a scratch project, so these are measured behaviours rather
> than expectations.
>
> **What we need from you.**
> 1. Approval to replace the emailed-code step with REDCap's built-in participant login.
> 2. A decision, with Stanford security/privacy and the IRB, on whether one factor (the
>    private expiring link) is sufficient or whether a second detail must be required.
>    This is a configuration setting either way, so it does not delay the build.
> 3. **Which detail to ask for.** The study database does not record date of birth, which
>    would be the usual choice, so the options are:
>    **(a) last name** — already collected, nothing new to add, and our recommendation;
>    **(b) a short passcode printed on a card and handed to the participant in the ED** —
>    the strongest option, because it is not sent to the same phone that receives the link,
>    but it adds a step for the CRC; **(c) begin collecting date of birth** — your call,
>    since not collecting it appears to have been deliberate.
> 4. Agreement that we may text participants their session link. (Every enrolled
>    participant has a texting-capable phone, since that is an eligibility requirement, so
>    text is the most reliable channel in the ED.)
> 5. Note that some fixes to the current login are being scheduled regardless of this
>    decision, because leaving it exactly as-is is not a safe option.
>
> **Two separate items for the study team, found while reviewing the new database
> structure** (details in the audit document, not blocking the above):
> the passcode feature on the `check_code` form refers to a passcode field that does not
> exist yet, so it cannot currently succeed; and some participant-facing text still names
> the **ASPIRE** and **TRAM** studies rather than this one.

---

## Appendix — evidence index

| Claim | Evidence |
|---|---|
| Auth gates precede module rendering | `Surveys/index.php` 1021 / 1097 / 1367 vs hooks at 3455 / 3847 |
| Survey Login scoping | `redcap_projects.survey_auth_apply_all_surveys`, `redcap_surveys.survey_auth_enabled_single`; `Surveys/index.php:1367`, `Classes/DataEntry.php:3549` |
| N-of-M credential fields | `redcap_projects.survey_auth_field1..3`, `survey_auth_event_id1..3`, `survey_auth_min_fields`; `Classes/Survey.php:2227` |
| Lockout | `redcap_projects.survey_auth_fail_limit/fail_window`; `redcap_surveys_login`; `Surveys/index.php:1420-1435`, `:1523-1531` |
| Audit events | `Surveys/index.php:1498`, `:1516` (`Survey Login Success` / `Failure`) |
| Two-cookie session + idle timeout | `Surveys/index.php:1509-1512`, `:1368-1375`; `Classes/Survey.php:2263-2267` |
| Per-participant link expiry | `Surveys/index.php:1097`; `Classes/Survey.php:3172-3186`; `redcap_surveys.survey_time_limit_*`, `redcap_surveys_participants.link_expiration` |
| Delivery preference / SMS | `redcap_surveys_participants.delivery_preference` enum, `participant_phone`; `redcap_projects.twilio_*` |
| Access / short codes | `redcap_surveys_participants.access_code`, `access_code_numeral`; `redcap_surveys_short_codes`; `Classes/Survey.php:2273+`; `Surveys/get_access_code.php` |
| QR links | `Surveys/survey_link_qrcode.php`, `survey_link_qrcode_svg.php` |
| Participant + response row creation | `Classes/Survey.php:1687` (`getFollowupSurveyParticipantIdHash`), `:1755`; empirically §11 S1 (participant 2105 / response 2238) |
| Link expiry not enforced for uninvited links | `Classes/Survey.php:3180`; empirically §11 S6 (`link_expiration` NULL) |
| Gate order (login form, no survey content, pre-auth) | §11, unauthenticated GET of a participant link |
| Lockout thresholds and non-recording of the post-lockout attempt | §11 S5 (`redcap_surveys_login`, 3 rows for 4 attempts) |
| Cookie is server-side verifiable per record | §11 S3b (recomputed `hash(sha512, "pid\|record\|salt\|salt2")` == issued cookie) |
| Audit events land in REDCap's own log | §11, `redcap_log_event15`, `Survey Login Success` / `Failure` on `pk=SPIKE1` |
| JSMO blocked on no-auth pages | commit `9b69d2c` message; current `pages/chatbot.php` is server-rendered PHP |
| No pilot data locally | `redcap_projects`: only `MICA_R01` PID 257, status 0, 0 records |
| Spike isolation | scratch PID 254 only; config captured and restored; `SPIKE1` and its participant/response rows deleted; PID 257 untouched |
