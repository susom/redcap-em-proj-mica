# 07 — Chatbot Cleanup & SecureChatAI Integration (SOW item)

**Status:** PLAN (decisions recorded 2026-08-13, with Ihab)
**SOW section:** *Chatbot Cleanup & SecureChatAI Integration*
**Placement decision:** folded into **Stage 0** (cleanup, no behavior change) and
**Stage 1** (SecureChatAI conformance) — no standalone migration stage.

## SOW traceability

| SOW bullet | Covered by |
|---|---|
| Clean up the old MICA chatbot, removing deprecated logic and unused components | Stage 0 task 0.6 (below) |
| Integrate the chatbot with the new SecureChatAI framework and structure | Stage 1 tasks 1.4/1.6 + SecureChatAI PR #1 (stage-1 doc §1.5) |
| Update API calls, request/response handling, and system prompt handling to conform to the SecureChatAI structure | Stage 1 task 1.6 (legacy path) + TurnService (v2 path, stage-1 §1.2) |
| Confirm existing MICA functionality is preserved after migration | Preservation checklist (below) + Stage 1 acceptance "flag off ⇒ pilot behavior unchanged" |

## Background: how MICA's chatbot diverged from Cappy

MICA's chatbot is a fork of the Cappy chatbot (`redcap_chatbot`); both call the
SecureChatAI EM. Cappy has since been updated to SecureChatAI's current
structure; MICA has not. Differences that matter here:

| Area | MICA today | Cappy / current SecureChatAI structure |
|---|---|---|
| Ajax payload | Raw message array; each message carries a nonstandard `user_id` key that flows into the model request | `{messages: [], session_id}` |
| `callAI()` signature use | `callAI($model, $params, PROJECT_ID)` | `callAI($model, $params, $pid, $username)` + `session_id` in params → central per-turn audit log with session grouping + rehydration |
| System prompt | Bootstrapped **to the browser** (survey page / `verifyEmail`), echoed back by the client every turn | Injected server-side in the ajax handler; frontend never sees prompts |
| Response handling | `formatResponse()` still carries a raw pass-through branch (`choices[0]…`) | SecureChatAI always normalizes (`normalizeResponse`) and sanitizes (`sanitizeOutputForUI`) before returning |
| Params | `reasoning_effort`, params always sent even when blank | `setIfNotBlank()`; `reasoning`/`reasoning_effort` filtered per model by SecureChatAI |
| Logging hygiene | `emDebug` logs full message arrays (PHI) | Metadata-only logging (role + content length) |

Cappy-only features **not** being adopted (out of scope for MICA): agent mode /
tools, RAG injection, page actions, every-page widget injection.

## Decisions (2026-08-13)

1. **Fold into Stage 0/1** — no separate like-for-like migration stage; the
   Stage-1 feature flag (`counselor-contract-v2` off) is the functional
   preservation mechanism.
2. **System prompt stays on the client round-trip for now** — the legacy path
   keeps bootstrapping context to the SPA; server-side prompt assembly arrives
   with `TurnService` (v2 path). Do **not** move it early.
3. **Adopt SecureChatAI session logging additively** — pass `session_id` +
   participant identifier (username slot) to `callAI()`; **keep `MICAQuery`**
   as the app transcript until Stage 3 replaces it.
4. **Remove now:** Twilio system settings + commented `sendSMS()`; dead
   `login`/`verifyEmail` entries in `no-auth-ajax-actions`.
   **Keep:** Dexie/IndexedDB persistence (load-bearing: per-message `user_id`
   source); `sessionSelector.php` + `fetchIncompleteSessions()`.

## Stage 0 additions — task 0.6 "Chatbot cleanup" (no behavior change)

- `config.json`: remove the three Twilio system settings (`twilio-sid`,
  `twilio-auth-token`, `twilio-from-number`); remove `login` and `verifyEmail`
  from `no-auth-ajax-actions` (the login flow lives in `pages/chatbot.php`
  POST handlers; the ajax switch never handled them).
- `MICA.php`: delete the commented-out `sendSMS()` block.
- `mica-chatbot/src`: delete unused Cappy-inherited assets (verified
  unreferenced: `cappy.png`, `chatGPT_logo.png`, `redcap_logo.png`,
  `stanford_home.webp`, `top_level_drill_down.webp`, `react.svg`); re-verify
  each with grep at implementation time; rebuild `dist/`.
- Security cleanup (behavior-preserving): stop interpolating user input into
  `filterLogic` strings in `loginUser()`, `verifyEmail()`,
  `fetchSavedQueries()` — escape/parameterize the values (framework
  guardrail; these are no-auth entry points).
- PHI logging hygiene: change `emDebug` calls that log message content
  (`MICA.php` callAI case) to metadata-only (role + length), matching Cappy.
- Baseline E2E (Playwright) recorded **before** these edits: welcome → login →
  OTP → chat turn → end session (desktop + mobile projects); re-run after.

## Stage 1 additions — task 1.6 "SecureChatAI conformance (legacy path)"

Applies to the flag-off (legacy) path; the v2 path gets all of this via
`TurnService` by construction.

- **Payload shape:** SPA sends `{messages: [...], session_id}` (Cappy shape).
  `sessionId` already exists in `contexts/Chat.jsx`; thread it through
  `callAjax` → `jsmo.callAI`. Backend accepts both shapes
  (`isset($payload['messages'])` fallback, as Cappy does) so an un-rebuilt
  SPA keeps working.
- **`callAI()` call:** pass `session_id` in params and the participant id in
  the `$username` argument → SecureChatAI's `logConversationTurn` groups
  MICA turns per session and `rehydrateProjectSession` becomes usable.
- **Message hygiene:** strip the nonstandard `user_id` key from messages
  server-side before `callAI()` (SecureChatAI does not strip message-level
  keys; today they flow to the model API).
- **Response handling:** SecureChatAI always returns the normalized,
  sanitized shape — remove `formatResponse()`'s raw pass-through branch
  after confirming no registry model bypasses `normalizeResponse()`.
- **Params:** adopt `setIfNotBlank()` semantics for the model params so blank
  project settings stop overriding SecureChatAI defaults; keep
  `reasoning_effort` (SecureChatAI filters it per model).
- **Model registry:** refresh the legacy `llm-model` dropdown to current
  SecureChatAI aliases (dev counselor `gpt-5-4`); the v2 path uses
  `counselor-model-alias` per stage-1 §1.4.

## Functional preservation checklist (SOW: "confirm existing MICA functionality")

Verified via the Stage-0 baseline E2E + Stage-1 "flag off" regression:

- [ ] Welcome → terms → name/email login → OTP email → survey-hosted chat UI
- [ ] Session calculation (baseline + sessions 2–7, `session_length_days`)
- [ ] Session-specific system context + general context + catch-up summaries
- [ ] Baseline instrument data injection (`chatbot_redcap_inject`)
- [ ] Transcript persistence (`MICAQuery`) + `fetchSavedQueries` restore
- [ ] `completeSession` → `raw_chat_logs` save + posttest / month3_fu survey link
- [ ] "Session already completed" / "study completed" gates
- [ ] Session admin page (`sessionSelector.php`)
- [ ] Mobile rendering of chat UI (Playwright mobile project)
