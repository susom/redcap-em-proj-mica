# 07 — Chatbot Cleanup & SecureChatAI Integration (SOW item)

**Status:** PLAN — decisions 2026-08-13 (with Ihab); **revised 2026-08-18**
against verified provider code
**SOW section:** *Chatbot Cleanup & SecureChatAI Integration*
**Placement decision:** folded into **Stage 0** (cleanup, no behavior change) and
**Stage 1** (SecureChatAI conformance) — no standalone migration stage.

**What changed in the 2026-08-18 revision.** SecureChatAI gained a built-in agent
loop, a tool framework, and pre/post tool-use hooks after this doc was first
written. The whole provider contract was re-verified line by line; the results,
the corrections to *this* doc and to the Stage 0/1 docs, and the agent-mode
decision live in [`13-securechatai-current-state-delta.md`](13-securechatai-current-state-delta.md).
The audit also turned up defects that are broken **now**, independent of phase 3
— those are in [`14-live-defects.md`](14-live-defects.md). **D1 in that doc means
MICA's chat is currently non-functional on PID 257; fix it before anything here.**

## SOW traceability

| SOW bullet | Covered by |
|---|---|
| Clean up the old MICA chatbot, removing deprecated logic and unused components | Stage 0 task 0.6 (below) — inventory now grep-verified |
| Integrate the chatbot with the new SecureChatAI framework and structure | Stage 1 tasks 1.4/1.6 + SecureChatAI PR #1 (scope revised — [`13`](13-securechatai-current-state-delta.md) §6) |
| Update API calls, request/response handling, and system prompt handling to conform to the SecureChatAI structure | Stage 1 task 1.6 (legacy path) + TurnService (v2 path, stage-1 §1.2) |
| Confirm existing MICA functionality is preserved after migration | Preservation checklist (below) + Stage 1 acceptance "flag off ⇒ pilot behavior unchanged". **Compatibility verdict for the pre-migration state: [`13`](13-securechatai-current-state-delta.md) §7** |

## Background: how MICA's chatbot diverged from Cappy

MICA's chatbot is a fork of the Cappy chatbot (`redcap_chatbot`); both call the
SecureChatAI EM. Cappy has since been updated to SecureChatAI's current
structure; MICA has not. Differences that matter here — all refs verified
2026-08-18 (`MICA.php` @ `ab81cca`, Cappy = `redcap_chatbot_v9.9.9/REDCapChatBot.php`):

| Area | MICA today | Cappy / current SecureChatAI structure |
|---|---|---|
| Ajax payload | a **bare top-level array** of messages (`Chat.jsx:127`), each carrying a nonstandard `user_id` that reaches the model API unstripped (`MICA.php:106-110`) | `{messages: [...], session_id}` (Cappy `Chat.js:403-406`), and `sanitizeInput()` rebuilds each message as `role`+`content` only, dropping every other key (Cappy `:184-195`) |
| Participant identity | **client-supplied** — `current($payload)["user_id"]` off the raw payload (`MICA.php:393`) on a no-auth action with no ownership check (`:376-381`) | framework-supplied `$user_id` from the ajax hook signature, never client-supplied (Cappy `:674`) |
| `callAI()` signature use | `callAI($model, $params, PROJECT_ID)` — 3 args (`MICA.php:407`) | `callAI($model, $params, $pid, $username)` + `session_id` in params ⇒ per-turn audit log with session grouping + working rehydration (Cappy `:674`) |
| System prompt | assembled server-side, then **shipped to the browser** in a `data-bootstrap` attribute (`MICA.php:303-312`) and echoed back every turn (`Chat.jsx:118-127`) | assembled server-side inside the ajax handler and never emitted to the browser — Cappy's `injectJSMO()` deliberately nulls the old browser leg (`:64-73`) |
| Response handling | `formatResponse()` keeps a raw `choices[0]` fallback (`MICA.php:122-126`) — now unreachable, and broken if reached | SecureChatAI always normalizes + sanitizes; consumers read `content`, or `structured_output` for schema'd output |
| Failure handling | none — provider errors arrive shape-identical to answers and are **persisted as counselor turns** (`MICA.php:417-418`) | Cappy has the same gap; the fix belongs upstream ([`13`](13-securechatai-current-state-delta.md) §6.1) |
| Params | 6 params sent even when the setting is blank (`MICA.php:190` guards `!== null`) | `setIfNotBlank()` — **Cappy's own helper** (`:447-452`), *not* a SecureChatAI feature. Two of MICA's params are inert either way ([`13`](13-securechatai-current-state-delta.md) §7 item 5) |
| Logging hygiene | `emDebug` logs full ChatML plus name/email — 5 sinks (`MICA.php:398`, `:308`, `:891`, `:934`, `ASEMLO.php:284`) | metadata-only: role + content **length** (Cappy `:642-652`) |
| Agent mode / tools | not used, and **not reachable** — MICA never sets `$params['agent_mode']` | used by Cappy: 3 gates, `tools.json`, `redcap_module_api()` dispatch, a scope pre-hook |

**Cappy features deliberately not adopted:** agent mode / tools, RAG injection,
page actions, every-page widget injection, crons. Agent mode is now declined for
concrete, recorded reasons rather than by omission —
[`13`](13-securechatai-current-state-delta.md) §3.

## Decisions

### 2026-08-13

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
   **Keep:** ~~Dexie/IndexedDB persistence (load-bearing: per-message `user_id`
   source)~~ *(superseded by decision 8 — identity moves server-side, so Dexie
   stops being load-bearing)*; `sessionSelector.php` +
   `fetchIncompleteSessions()`.

### 2026-08-18 (revisions, from the verified audit)

5. **Agent mode and hooks: declined for the counselor turn**, with reasons
   recorded ([`13`](13-securechatai-current-state-delta.md) §3-§4). The decisive
   ones: it contradicts the one-call-per-turn schema'd contract, agent turns
   return `model`/`usage` as `null` (destroying `mica_turn` telemetry), tool
   results enter the transcript as synthetic `role: user` messages, and the
   participant path has **no REDCap username**, which is exactly what the
   always-on `ProjectAccessPreHook` fails closed on. Revisit only for
   authenticated staff-facing surfaces after Stage 5.
6. **Live defects are split out and sequenced first.** [`14`](14-live-defects.md).
   D1 (unregistered `llm-model`) blocks *any* E2E baseline, so it precedes the
   Stage 0 cleanup rather than being part of it.
7. **Decision 2 is amended: the payload-shape change is no longer optional
   deferrable polish.** The per-message `user_id` is not merely a nonstandard key
   — it is the module's only participant identity on a no-auth endpoint, and it
   lets a caller read another participant's baseline data through the prompt
   ([`14`](14-live-defects.md) D2). Server-derived identity and the
   `{messages, session_id}` envelope are the *same edit*, so they land together
   in Stage 1 §1.6. The *system prompt* round-trip still stays until
   `TurnService` — decision 2 holds for prompts only.
8. **Dexie is no longer "load-bearing".** Decision 4 kept it because it was the
   per-message `user_id` source. Once identity is server-derived (7), nothing in
   IndexedDB is data the server lacks: `cached_chats` is already write-only, and
   `user_info` is replaceable by `window.mica_bootstrap`. Removing the
   `getCurrentUser()` gate (`Chat.jsx:46-48`) also deletes a silent-failure mode
   where an empty Dexie store drops every message.
9. **SecureChatAI PR #1 scope changed** — one item added (preserve a
   machine-readable error flag), one dropped (`supports-json-schema`, already
   satisfied for `gpt-5-4`), one kept (`supports-reasoning-effort`).
   [`13`](13-securechatai-current-state-delta.md) §6.

## Stage 0 additions — task 0.6 "Chatbot cleanup" (no behavior change)

Prerequisite: [`14`](14-live-defects.md) D1 fixed and a Playwright baseline
recorded (welcome → login → OTP → chat turn → end session, desktop **and**
mobile). Without D1 the baseline records the apology loop, not the product.

All items below are grep-verified as unused; **re-verify each at implementation
time** rather than trusting this list.

**`config.json`**
- Remove Twilio system settings `twilio-sid`, `twilio-auth-token`,
  `twilio-from-number` (`:263-280`) — sole references are the commented
  `sendSMS()` lines.
- Remove `login` and `verifyEmail` from `no-auth-ajax-actions` (`:55-56`) — the
  ajax switch (`MICA.php:384-439`) never handled them, and their `jsmo.js` /
  `useAuth.jsx` callers are unreachable too, so removal masks nothing.
- Decide `chatbot_intro_text` (`:76-80`) and `chatbot_end_session_text`
  (`:96-100`): both are `required: true` yet **inert** — their only readers are
  zero-caller getters (`MICA.php:33-39`), and the SPA reads
  `window.mica_jsmo_module.intro_text` / `.end_session_text`, which **nothing
  ever assigns** (`MICA.php:322-330` sets only `.data` and `.this_session`), so
  the hardcoded SPA fallbacks always win. Either wire them into the bootstrap or
  delete both settings and the getters. Do not leave them declared-and-required.
- Remove `enable-every-page-hooks-on-system-pages: true` (`:24`) — no every-page
  hook exists. Remove the empty `links.control-center` (`:43-45`).

**`MICA.php`**
- Delete the commented `sendSMS()` block (`:801-822`).
- Delete zero-caller methods `getIntroText()` / `getEndSessionText()`
  (`:33-39`) if decision above is "delete".
- Delete write-only properties `$system_context_global`, `$system_context_session`
  (`:25-26`), `$primary_field` (`:27`).
- Metadata-only `emDebug` — the 5 PHI sinks in [`14`](14-live-defects.md) D5.
- Escape/parameterize the `filterLogic` interpolation in `loginUser()` (`:560`)
  and `verifyEmail()` (`:640`) — [`14`](14-live-defects.md) D3. (`:525` is
  already mitigated.)
- Remove the dead `renderMicaApp` mount contract — [`14`](14-live-defects.md) D9.
  This is behavior-*restoring* (it makes `unmask()` work and stops the ~100
  duplicate submit listeners), so it needs its own before/after E2E.
- Correctness nits D10-D16, D21.

**`classes/`**
- `MICAQuery::getPayload()` (`:34`) and `getMICAQuery()` (`:42`) — zero callers.
- Commented `payloadCheck()` (`MICAQuery.php:27-31`).
- `ASEMLO::purgeChangeLogs()` (`:421`), `renameObject()` (`:148`) — zero callers.
  Keep if ASEMLO is treated as a vendored library; note the decision either way.

**`composer.json`** — drop `php-ai/php-ml` and `twilio/sdk`; neither is
referenced. (The `vendor/autoload.php` require is already conditional as of
`74c48a5`.) Stage 0 §0.4 adds `opis/json-schema`, so this is the moment to make
the dependency list honest.

**`mica-chatbot/src`** — delete the six confirmed-unreferenced Cappy-inherited
assets (`cappy.png`, `chatGPT_logo.png`, `redcap_logo.png`, `stanford_home.webp`,
`top_level_drill_down.webp`, `react.svg`; `mica_logo.png` **is** in use), delete
dead `App.jsx`, delete the unreachable `login`/`verifyEmail` bridges
(`assets/jsmo.js:37-59`, `useAuth.jsx:84-159`), then rebuild `dist/`. Note
`generateAssetFiles()` emits a tag for *every* file it finds
(`MICA.php:41-70`) — a stale hashed bundle left behind will be loaded alongside
the new one, so clear `dist/assets` before rebuilding.

## Stage 1 additions — task 1.6 "SecureChatAI conformance (legacy path)"

Applies to the flag-off (legacy) path; the v2 path gets all of this via
`TurnService` by construction.

- **Payload shape + identity (one change, per decision 7).** SPA sends
  `{messages: [...], session_id}`; backend accepts both shapes
  (`isset($payload['messages'])` fallback, as Cappy does) so an un-rebuilt SPA
  keeps working. **Participant id is derived server-side** from `$record` /
  `$survey_hash`, and any payload-supplied `user_id` is ignored. Strip all
  non-`role`/`content` keys from messages before `callAI()` — copy Cappy's
  rebuild-don't-filter approach (`:184-195`).
- **`callAI()` call:** pass `session_id` in params and the participant id in the
  `$username` argument ⇒ SecureChatAI's `logConversationTurn` starts writing turn
  rows at all (today it returns early with no `session_id`, so there are none)
  and groups them per session. Note `session_id` has **no consumer inside MICA**:
  `MICAQuery::getLogsFor()` keys on `project_id + mica_id` and infers session
  windows by timestamp (`MICAQuery.php:67`, `:73-81`). Using it as MICA's own
  session key is Stage 3 work, and needs a trust decision first — client
  `Date.now()` ids are guessable and collide across tabs.
- **Response handling:** remove `formatResponse()`'s raw `choices[0]` fallback
  (`:122-126`) — unreachable, and broken if reached. Add failure detection per
  [`13`](13-securechatai-current-state-delta.md) §6.1 so provider errors stop
  being persisted as counselor turns. Expect `id` to always be `null`.
- **Params:** implement blank-means-absent locally (SecureChatAI does **not** do
  this). Drop or relabel `gpt-max-tokens` and `reasoning-effort`, which the
  provider discards for every model MICA can select.
- **Model registry:** replace the `llm-model` dropdown values with current
  SecureChatAI aliases and validate the configured alias against
  `getAvailableModels()`; the v2 path uses `counselor-model-alias` per stage-1
  §1.4.
- **System prompt handling unchanged on this path** (decision 2) — but fix the
  `.pop()` context loss ([`14`](14-live-defects.md) D8) and the empty-context
  hang ([`14`](14-live-defects.md) D7) while the round-trip still exists.

## Functional preservation checklist (SOW: "confirm existing MICA functionality")

Verified via the Stage-0 baseline E2E + Stage-1 "flag off" regression. Recorded
**after** [`14`](14-live-defects.md) D1, since before it every turn returns the
provider apology.

- [ ] Welcome → terms → name/email login → OTP email → survey-hosted chat UI
- [ ] Session calculation (baseline + sessions 2–7, `session_length_days`)
- [ ] Session-specific system context + general context + catch-up summaries
      (including the multi-element case that D8 currently truncates)
- [ ] Baseline instrument data injection (`chatbot_redcap_inject`)
- [ ] Transcript persistence (`MICAQuery`) + `fetchSavedQueries` restore
- [ ] **Restored session continues coherently** — i.e. the model has the prior
      turns, not just the display (currently broken: [`14`](14-live-defects.md) D6)
- [ ] `completeSession` → `raw_chat_logs` save + posttest / month3_fu survey link
- [ ] "Session already completed" / "study completed" gates **render their
      message** rather than hanging the send button ([`14`](14-live-defects.md) D7)
- [ ] Session admin page (`sessionSelector.php`)
- [ ] Mobile rendering of chat UI (Playwright mobile project)
