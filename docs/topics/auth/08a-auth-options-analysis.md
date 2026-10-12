# 08a — 2FA replacement: current state, requirements and options (§1–5)

Part of [08 — 2FA replacement discovery](08-auth-discovery.md). The analysis behind the recommendation: what was built, what REDCap 17.2.3 provides natively, the options and the decision matrix.

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
