# Stage 2 — R01 session engine + frontend

**Goal:** replace the pilot session engine (baseline + biweekly sessions 2–7)
with the R01 model (Day-1 ED baseline + Month-3 booster) against the rebuilt
PID 257, and adapt the `mica-chatbot` SPA. Pilot code paths are **deleted**
(pilot runs from the `pilot-final` tag).

**Depends on:** Stage 1. **Blocked-in-part by:** open questions #5 (matrix),
#7 (ED launch flow), #8 (field sign-off) — engine + frontend proceed against
a dev copy of PID 257; the production dictionary upload waits for sign-off.

## Tasks

### 2.1 `SessionStateService` (`classes/SessionStateService.php`)

Implements `SessionStateProviderInterface` (replaces the Stage-1 adapter):

```php
final class SessionStateService implements SessionStateProviderInterface {
    public function getState(string $projectId, string $record): SessionState;
    public function advancePhase(string $record, string $phase): void;     // from accepted turns
    public function writeBaselineClose(string $record, string $goalText): void; // prior_goal for booster
}
```

Resolution rules (`01-architecture.md §3`):

- `session_type`: `baseline` iff active window = Day-1 event and no finalized
  baseline transcript exists; `booster` inside the Month-3 window. Windows
  from enrollment/randomization dates + `booster-window-*` settings — **not**
  the pilot `consent_date + 14n` arithmetic (`calculateSessionInfo`,
  `MICA.php:756`, is deleted).
- `phase`: last accepted `next_phase` from `mica_turn` for the active
  session; `engage` at session start.
- `minutes_remaining`: `session-minutes` (default 15) − elapsed since first
  turn, clamped [0, 15]; server-computed only.
- `alcohol_summary`: mapping per `02-data-model.md §3.3` (confirm fields with
  the data dictionary; `null`s pass through when absent).
- `readiness`/`confidence`, `prior_goal(+status)`, `location` read from the
  session-form fields (`02-data-model.md §3.1`).
- `research_approved_resources`: from setting, one per line; empty = offer
  none.
- History: last `history-max-messages` (20) accepted messages from
  `MICAQuery`, each hard-truncated to the schema's 4000-char ceiling.
- All `\REDCap::getData()` calls scoped to the needed records/fields/events.

### 2.2 Delete pilot paths / settings

- Remove: `getSystemContextForRecord`, `initSystemContexts`,
  `summarizeCatchUp`, `calculateSessionInfo`, `getFormattedBaselineData`'s
  pilot-specific injection (`chatbot_redcap_inject` decision: keep only if
  the wrapper needs it — default remove), session 2–7 / `month3_fu` /
  `posttest` / `des_mica` logic (incl. `completeSession`'s event-name
  arithmetic and survey-link branches — `completeSession` is rewritten in
  Stage 3 anyway), the legacy `handleUserInput` model path once the flag has
  soaked, and the `counselor-contract-v2` flag itself at stage end (v2
  becomes the only path).
- `config.json`: remove pilot settings listed in `02-data-model.md §5`
  (`chatbot_system_context_*`, `llm-model`, `gpt-*` sampling params,
  `session_length_days`, `number_session_callback`); add `session-minutes`,
  `history-max-messages`, `research-approved-resources`, booster-window
  settings.

### 2.3 PID 257 dictionary + auth (revised 2026-08-17 against the as-built project)

> Rewritten against the real dictionary — see
> [`09-pid-257-structure-audit.md`](../09-pid-257-structure-audit.md) and the revised
> [`08-auth-discovery.md §6`](../08-auth-discovery.md). The researcher structure is in
> place (262 fields / 27 instruments / 18 surveys, 0 records); several assumptions here
> were wrong and are corrected below.

**Already present — do not build (audit §1/§2):**

- Arms/events, and MICA designated to Day 1 (ED) + Month 3 in **arms 2 & 3 only**. The
  arm restriction is therefore enforced by REDCap's event/form designation, not module
  logic — the "randomized-arm check" below becomes a defence-in-depth assertion, not the
  primary control.
- Contact fields, on `baseline1`: `first_name`, `last_name`, `email` (email-validated),
  `phonen` (cell), and `choice_fup_delivery` (email-vs-text preference → maps to Twilio's
  `twilio_delivery_preference_field_map`).
- Window source: `admin.randomization_date` (`date_ymd`) plus `admin.calc_month_3`.
  **There is no `consent_date` field in PID 257**, so deleting the pilot
  `consent_date + 14n` arithmetic is mandatory, not merely preferred.
- Post-session participant measure: **`postsession`** (11 MI-quality items), designated
  Day 1 (ED) + Month 3, arms 2 & 3 — the R01 equivalent of the pilot's `posttest`.
  `completeSession`'s post-session survey link should target this.
- `alcohol_summary` sources: `audit` (+`audit_score`), `ddq` (+`max`), `sip2r`,
  `screen.auditc1-3`.

**Still to build (audit G1, G4, G5) — needs #8 sign-off; dev copy first:**

- **G1 — the chat host.** `mica_ed_session` and `mica_booster_session` exist and are
  designated correctly but are `_complete`-only placeholders and **not surveys**. There is
  no `ui_hosting_instrument` in this project. Add one descriptive field to each as the SPA
  mount point, then "Enable as survey" (descriptive-only surveys are valid — see
  `sms_code_check` / `sms_opt_out` in the same project).
- **G4 — session/transcript fields** per `02-data-model.md §3.1`: none of
  `raw_chat_logs`, `session_timestamp`, `mica_transcript_hash`, `mica_transcript_ref`
  exist yet.
- **G5 — the repeating `mica_safety_finding` instrument**: does not exist, and **no
  repeating instruments are configured on this project at all**, so repeating must be
  enabled as part of this work.
- **No `two_factor_*` fields** need creating or renaming — they do not exist in PID 257,
  and the auth recommendation retires them rather than porting them.

**Sequencing constraint discovered during the auth implementation
([`10 §4.1`](../10-auth-implementation-pid257.md)):** `baseline1` is entered **before**
randomization (confirmed with the study), and MICA's host surveys are designated to arms 2 & 3
only. `REDCap::getSurveyLink()` requires the record to exist in the requested event's **arm**, so
a MICA session link **returns null until the participant is randomized**. Link issuance must
therefore check arm presence and surface an explicit "not randomized yet" state — never treat the
null as a generic failure, and never bypass it with `$ensureThatRecordExists = false`.

**Auth (replaces the previous "keep the existing OTP email login" instruction):**

- Configure native **Survey Login** scoped to the two MICA chat surveys
  (`survey_auth_apply_all_surveys = 0` + `survey_auth_enabled_single = 1` on those two),
  credential per `08 §6.2` — **`last_name` for tier L2**, since PID 257 collects no DOB.
- Enable `survey_time_limit_*` on both chat surveys and have the module write
  `redcap_surveys_participants.link_expiration` at link issuance (`08 §3.3`).
- Delete `loginUser`/`verifyEmail`/`generateOneTimePassword` and `pages/chatbot.php`
  rather than repointing them at new field names.
- Do **not** route the chat through the study's `check_code` passcode survey — it is a
  survey-queue gate for the assessment battery, not an access boundary (`08 §6.1a`).
- Session-entry checks must additionally refuse **withdrawn** participants
  (`admin.study_withdrawn`) and respect SMS opt-out (`admin.sms_stop`) — `08 §6.1b`.
- Randomized-arm check retained as a server-side assertion.

### 2.4 Frontend (`mica-chatbot/` SPA)

- `end_session: true` handling: disable input, show wrap-up prompt, drive the
  existing PostSession → `completeSession` flow (`src/views/PostSession`,
  `src/contexts/Chat.jsx`).
- Session countdown from new `session_minutes` bootstrap field (same clock
  the server enforces); replace any pilot-session UI/state (sessions 2–7
  copy, 14-day messaging).
- Keep the wire shape from Stage 1 (`content` + `end_session`) — no API
  churn.
- Add `vitest` for pure logic (countdown, message shaping); Playwright specs
  under `e2e/` (first E2E in the repo — wire the CI job).

## Tests

- **Unit:** window resolution (before/inside/after each window; boundary
  timestamps; timezone), phase persistence/reset, minutes clamping,
  alcohol-summary mapping incl. nulls, history truncation (count + 4000-char),
  pseudo-id non-leakage (no record id in wrapper payload).
- **E2E (Playwright, dev PID 257, model stubbed):**
  1. OTP login → baseline chat → multi-turn → `end_session` → PostSession →
     session form written.
  2. Booster window: login → `prior_goal` surfaced in wrapper → complete.
  3. Gate-failure UX: forced malformed fixture ⇒ approved technical message,
     never raw model output.
  4. Completed baseline re-login ⇒ "return for booster" (no 14-day text).
  - All of the above on desktop + mobile viewports.

## Acceptance checklist

- [ ] Pilot code paths and settings removed; no references to
      `session_[2-7]|month3_fu|posttest|des_mica|consent_date` outside docs
- [ ] E2E flows 1–4 green, desktop + mobile
- [ ] Booster reads `prior_goal` written at baseline close
- [ ] Field dictionary drafted on dev PID 257; sign-off (#8) tracked
- [ ] `counselor-contract-v2` flag retired; v2 is the only turn path
