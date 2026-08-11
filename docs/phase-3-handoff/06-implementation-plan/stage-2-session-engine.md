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

### 2.3 PID 257 dictionary + auth (after #8 sign-off; dev copy first)

- Build fields per `02-data-model.md §3.1/§3.2` on the dev PID 257 copy:
  session instruments (arms 2 & 3 events), repeating `mica_safety_finding`
  instrument (Stage 4 writes it; created now so the dictionary ships once),
  auth/OTP + contact fields on the Demographics/auth instrument.
- Keep the existing OTP email login (`loginUser`/`verifyEmail`,
  `MICA.php:482/561`) pointed at the new field names; ED-tablet launch flow
  per #7 when decided (login UX only).
- Randomized-arm check: module refuses session start for arms without MICA.

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
