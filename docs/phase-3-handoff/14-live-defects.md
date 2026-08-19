# 14 — Live defects found during the SecureChatAI audit (2026-08-18)

**Status:** FINDINGS — verified by code reading, **not yet reproduced E2E**
**Scope:** defects in the code as it stands on `mica-phase-3` @ `ab81cca`.
These are distinct from the SOW migration work in
[`07-chatbot-cleanup-securechatai.md`](07-chatbot-cleanup-securechatai.md):
they are broken now and do not need the phase-3 architecture to be fixed.

> **Process note (project standard):** every item below must be reproduced in a
> Playwright E2E run as an end user *before* it is fixed, and the failing test
> kept. Except where a defect is explicitly marked **Observed**, the evidence is
> static analysis with line-level citations — treat those symptoms as predicted,
> not observed. D1 has a partial observation from the log tables (see below);
> nothing has yet been reproduced through the UI, which is itself blocked by D1.

Refs are `MICA.php` unless another file is named. SPA paths are relative to
`mica-chatbot/`. `SCA/` = `modules-local/secure_chat_ai_v9.9.9/`.

---

## A. Blocking / security

### D1 — `llm-model = gpt-4o` is unregistered: every chat turn fails silently

**Severity:** blocking (chat is non-functional on PID 257)
**Status: FIXED in dev 2026-08-19 — reproduced and verified by live call.
Production still unverified (see below).**

PID 257 has `llm-model = gpt-4o`. That alias exists in **neither** the local
SecureChatAI registry (which holds exactly one alias, `claude-haiku-4-5`) **nor**
`SCA/model_settings_prod.txt` (15 aliases, dated 2026-05-01, `gpt-4-1` is the
default; there is no `gpt-4o`).

Chain:

1. `callLLMOnce()` throws `Unsupported model: gpt-4o` (`SCA/SecureChatAI.php:1157`).
2. `callAI()` retries 3× and then builds
   `['error'=>true,'type'=>'NETWORK_ERROR',…]` (`:521`).
3. `sanitizeOutputForUI()` rewrites that into a **friendly assistant message**
   (`:1633-1636`): *"I apologize, but I'm experiencing network difficulties.
   Please wait a moment and try again."*
4. That array has `content` set, so MICA's `formatResponse()` takes the
   **success** branch (`:119`).
5. `logMICAQuery()` persists it as a normal assistant turn (`:417-418`), so it
   also lands in `raw_chat_logs` at session close (`:912-917`).

**Predicted symptom:** every participant message is answered with that apology,
indefinitely. No error surfaces in the UI, and nothing in MICA's own logs says
"unsupported model" — that string appears only in SecureChatAI's `emDebug`.

**Observed state (dev, queried 2026-08-18):** the defect is real but
**unexercised on this instance**. `redcap_external_modules_log` holds **zero**
rows for both `secure_chat_ai` and `proj_mica` — no turn rows, no error rows, no
transcript rows, and zero messages matching `network difficulties`. Nobody has
sent a chat turn here since this configuration existed (consistent with the
module only having become enable-able today). So on dev this is a **pre-launch
blocker**, not an active incident.

**Production is unverified and is the real question.** `gpt-4o` is absent from
`model_settings_prod.txt` too, so if the production project's `llm-model` is also
`gpt-4o` *and* participants have been chatting, their transcripts already contain
apology rows stored as counselor turns. Two read-only checks to run there:

```sql
-- 1. Is prod pointed at a registered alias?
SELECT s.project_id, s.value FROM redcap_external_module_settings s
JOIN redcap_external_modules m ON m.external_module_id = s.external_module_id
WHERE m.directory_prefix = 'proj_mica' AND s.key = 'llm-model';

-- 2. Has the apology already been persisted as counselor turns?
SELECT project_id, COUNT(*) FROM redcap_external_modules_log
WHERE external_module_id = (SELECT external_module_id FROM redcap_external_modules
                            WHERE directory_prefix = 'proj_mica')
  AND message LIKE '%network difficulties%'
GROUP BY project_id;
```

If check 2 returns rows, this stops being a config fix and becomes a data-quality
remediation: those rows are in `MICAQuery` and therefore in any `raw_chat_logs`
snapshot already written at session close.

#### Reproduction and verification (2026-08-19, live calls against the dev registry)

The defect was **worse than the setting value**: every one of the seven options
in MICA's `llm-model` dropdown was invalid — `gpt-4o`, `gpt-4.1`, `o1`,
`o3-mini`, `claude`, `gemini20flash`, `llama-Maverick`. None appears in the dev
registry or in `model_settings_prod.txt` (note `gpt-4.1` vs the real alias
`gpt-4-1`, and bare `claude` vs `claude-sonnet-4.6`). Changing only the stored
value would therefore have been undone by the next save of the settings form.

Reproduced, then verified, by calling the provider directly with pid 257:

```
AVAILABLE ALIASES : ["claude-haiku-4-5"]

BEFORE  callAI('gpt-4o', …)            → keys ["role","content"]
                                         content "I apologize, but I'm experiencing
                                                  network difficulties…"
                                         model NULL   usage NULL

AFTER   callAI('claude-haiku-4-5', …)  → keys ["role","content","model","usage"]
                                         content "OK"
                                         model "claude-haiku-4-5-20251001"
                                         usage {prompt:14, completion:4, total:18}
```

This confirms three things previously only inferred: the apology chain is real
and arrives shape-identical to an answer; the
`model === null && usage === null` failure heuristic
([`13`](13-securechatai-current-state-delta.md) §6.1) does discriminate; and the
failed call wrote a `SecureChatLogError` row while the successful one wrote
`SecureChatLog` — with **no** `CappyChatTurn` row either time, empirically
confirming that a caller omitting `session_id` gets no turn log at all
([`13`](13-securechatai-current-state-delta.md) §7 item 3).

**Fix applied (dev):**

1. `config.json` — replaced all seven stale `llm-model` choices with the actual
   registry aliases, changed `default` from `gpt-4o` to `gpt-4-1` (the
   SecureChatAI prod default), and documented the silent-failure consequence in
   the setting's own label.
2. PID 257 `llm-model` → `claude-haiku-4-5`, the only alias this instance has
   registered. **Note for Stage 1:** Claude aliases are *not* json_schema-capable
   (`SecureChatAI.php:399`), so the counselor-v2 work still needs a
   `gpt-4-1`/`gpt-5-4` alias added to `api-settings`
   ([`13`](13-securechatai-current-state-delta.md) §8).
3. `MICA.php` — new `assertModelIsRegistered()`, called before `callAI()`. It
   validates the configured alias against `getAvailableModels()` and throws a
   participant-safe message instead of letting the silent apology through. It
   no-ops when the registry is unreadable/empty so a SecureChatAI
   misconfiguration cannot turn the guard into a second outage. Verified:
   `claude-haiku-4-5` passes, `gpt-4o` is blocked, empty is blocked with a
   distinct message.

**Still outstanding:** the production `llm-model` value and check 2 above — I can
only measure this instance. And a UI-level Playwright reproduction is still owed
per the process note; the provider-boundary reproduction above is narrower than a
participant-path E2E.

**Guard against recurrence:** validate the configured alias against
`getAvailableModels()` (`SCA/SecureChatAI.php:578`) at turn time or on save, and
fail loudly. See also [`13`](13-securechatai-current-state-delta.md) §6.1 —
until the provider preserves an error flag, MICA cannot distinguish this class
of failure from a real answer.

### D2 — No ownership binding on the no-auth AJAX surface

**Severity:** high — cross-participant PHI disclosure and transcript tampering

`redcap_module_ajax` authorizes on the mere *existence* of a survey hash or a
session (`:376-381`): `$isSurvey = !empty($survey_hash)`,
`$isUser = !empty($_SESSION['username'])`. There is **no binding between the
payload's participant and `$record`/`$survey_hash`**, so any live survey hash
authorizes every action for every record. All five actions are declared
`no-auth-ajax-actions` (`config.json:53-59`).

Three concrete consequences:

| Path | Effect |
|---|---|
| `callAI` (`:393`) | `$participant_id = current($payload)["user_id"]` — read from the **raw, unsanitized** payload. Flows to `getFormattedBaselineData()` (`:394-397` → `:485-489`), which injects **that record's** baseline instrument data into the system prompt. Supplying another participant's id can surface their REDCap data through the model's reply |
| `callAI` (`:390`, `:418`) | transcript rows are written under the client-chosen `mica_id`, so a caller can forge or pollute any participant's transcript |
| `completeSession` (`:880`) | trusts `payload['participant_id']` outright and overwrites that record's `raw_chat_logs` and `session_info_complete` (`:912-917`) — i.e. can close another participant's session |

`fetchSavedQueries` is the only action with even a knowledge check (name + id,
`:533`).

**Fix direction:** derive the participant id server-side from the survey
context (`$record` / `$survey_hash`) and ignore any payload-supplied identity.
This is the same edit the SOW payload-shape change needs (see `07` §Stage 1),
which is why the two should land together.

### D3 — Raw `$_POST` interpolated into `filterLogic` on a no-auth page

**Severity:** high

`loginUser()` (`:560`) and `verifyEmail()` (`:640`) interpolate values straight
into `REDCap::getData()` `filterLogic` strings, fed by unsanitized `$_POST` from
`pages/chatbot.php:44-45, 63-65`. `fetchSavedQueries` (`:525`) is mitigated —
its input passes the sanitizer at `:423`, and `htmlspecialchars(ENT_QUOTES)`
encodes `'` — but the two login paths are not.

### D4 — Unauthenticated session completion on the admin page

**Severity:** medium-high

`pages/sessionSelector.php:5-11` executes `completeSession` **before and without**
`validatePermissions()` (which is only called inside `fetchIncompleteSessions()`,
`:942-947`), with no CSRF token and an unsanitized `$_POST['participant_id']`.
`:57` then echoes that value unescaped into a `value` attribute.

### D5 — PHI in module debug logs

**Severity:** medium (HIPAA hygiene)

Five sinks log participant content or identifiers:

| Ref | What leaks |
|---|---|
| `:398` | the **entire** ChatML array (every participant utterance) |
| `:308` | bootstrap JSON including `participant_name` and `participant_email` |
| `:891`, `:934` | payload + session/participant identifiers |
| `classes/ASEMLO.php:284` | `emDebug("About to save $message: ", …)` where `$message` is the transcript JSON |

Cappy's convention is the target: metadata only — role + content **length**
(`redcap_chatbot_v9.9.9/REDCapChatBot.php:642-652`).

---

## B. Functional defects

### D6 — Restoring a session does not restore the model's context

**Severity:** high (silently degrades the intervention)

`replaceSession()` (`src/contexts/Chat.jsx:110-115`) repopulates `chatContext`,
`messages`, and `msgCount` — the **display** state — but never rebuilds
`apiContext`. After a page reload mid-session, `fetchSavedQueries` restores the
visible transcript while the model receives an **empty** history, and
`Chat.jsx:118` then re-injects the system prompt into that empty context.

**Predicted symptom:** the participant sees their prior conversation, but the
counselor behaves as if the session just began. Cappy rebuilds `apiContext` from
saved turns (`redcap_chatbot_v9.9.9/chatbot_ui/src/contexts/Chat.js:94-105`).

### D7 — Send button hangs permanently on the "session already completed" gate

**Severity:** medium-high (dead end with no message)

`getSystemContextForRecord()` throws the completion gates (`:706`, `:728`,
`:735`). `redcap_survey_page` catches and normalizes to `$ctx = []`
(`:268-275`), so `initial_system_context` ships as `[]` (`:303`) and
`window.mica_jsmo_module.data = []` (`:329`). Then
`getInitialSystemContext().pop()` returns `undefined`
(`assets/jsmo.js:13-15` ← `Chat.jsx:119`), and `Chat.jsx:52` reads `.role` off
`undefined` → rejected promise. `footer.jsx:16` neither awaits nor catches it,
and `footer.jsx:27-31` is a no-op.

**Predicted symptom:** spinner stuck on, no error text, no recovery. The
participant is told nothing — even though the server had a specific, friendly
message to deliver ("Return in N day(s) for your next session!").

### D8 — Only the *last* system-context element ever reaches the model

**Severity:** medium (silent prompt loss)

`appendSystemContext()` `array_unshift`es a new entry when the first system
message is empty (`:165-167`), so `initSystemContexts()` can legitimately return
**more than one** element. The client then keeps only the last one:
`Chat.jsx:119` calls `.pop()`.

**Predicted symptom:** with a blank `chatbot_system_context_general`, or whenever
the catch-up summary is unshifted, the prior-session summary or the general
context is dropped before the model sees it — with no error.

### D9 — `renderMicaApp` does not exist: dead mount contract

**Severity:** low-medium (cosmetic + listener leak)

`window.renderMicaApp` is defined **nowhere** — 0 hits in `src/` and 0 in the
shipped bundle `dist/assets/index-BadDVS26.js`. The SPA self-mounts as an import
side-effect (`src/main.jsx:13`). So `tryMount()` (`:350-353`) always returns
false, which means:

- `unmask()` (`:343-346`) never runs, so `#mica-hide-native` persists for the
  page lifetime — contradicting the comment at `:252`.
- The `setInterval` (`:361-364`) runs ~100 iterations over 10s, re-invoking
  `blockSubmit()` and re-attaching capture-phase `submit` + `keydown` listeners
  each time (`:336-339`).

`src/App.jsx` is also dead — imported at `main.jsx:3`, never rendered
(`main.jsx:16` renders `<AppRouter/>`).

---

## C. Correctness defects (low severity, fix while in the file)

| # | Defect | Ref |
|---|---|---|
| D10 | `$event_id` is undefined when `emDebug`'d for sessions 2-6 — assigned only at `:925`/`:929` | `:934` |
| D11 | `REDCap::saveData()` return is subscripted without a type check on three paths; `:919` passes whatever comes out into `Exception`'s string param | `:609`, `:618`, `:918` |
| D12 | `summarizeCatchUp` builds `session_0_arm_1` when `$i === 0`; `getEventIdFromUniqueEvent` returns null and `'events' => [null]` reaches `getData` | `:768-769` |
| D13 | `fetchSavedQueries` compares `$check['record_id']` instead of the `$primary_field` it just fetched | `:533` |
| D14 | `handleUserInput` reads `$data['user_id']` unconditionally though only `role`/`content` are validated ⇒ undefined-key warning and `user_id: null` on every assistant turn | `:109` |
| D15 | `$messages[sizeof($messages)-1]` becomes `$messages[-1]` when `handleUserInput` returns `[]` (non-array payload) | `:389` |
| D16 | `$calc` may be null (`calculateSessionInfo` returns null at `:828`/`:851`) and is dereferenced unguarded | `:887-889` |
| D17 | `Sanitizer` HTML-escapes prompt text **before** it reaches the model and before storage, so apostrophes persist as `&#039;` in transcripts | `classes/Sanitizer.php:16` |
| D18 | `get_magic_quotes_gpc` branch is dead under PHP 8 | `classes/Sanitizer.php:13` |
| D19 | `pages/chatbot.php:68` hardcodes `ui_hosting_instrument` in `getSurveyLink()`, so the new `chat_host_instruments` setting does not reach the OTP path | `pages/chatbot.php:68` |
| D20 | `messages.jsx:95` references an undefined `intro_text` identifier (unreachable branch) | `src/components/messages/messages.jsx:95` |
| D21 | `PROJECT_ID` vs `$this->getProjectId()` used inconsistently | `:407`, `:534`, `:894`, `:943` vs `:695`, `:718`, `:772`, `:832` |

---

## Suggested sequencing

1. ~~**D1 alone, first**~~ — **done in dev 2026-08-19** (config choices + PID 257
   value + an alias guard). Production value still to be confirmed.
2. **Record the Playwright baseline** (welcome → login → OTP → chat turn → end
   session, desktop + mobile) *after* D1 and *before* anything else. Stage 0
   §0.6 depends on that baseline existing.
3. **D2 + D3 + D4** as one security pass — they share the "trust the client's
   participant id" root cause, and D2's fix is the same edit the SOW payload
   change needs.
4. **D6, D7, D8** — participant-visible behavior; each needs a failing E2E test
   first.
5. **D5** with the Stage 0 §0.6 logging cleanup.
6. **D9-D21** folded into Stage 0 §0.6 as behavior-preserving cleanup.
