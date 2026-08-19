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
> not observed. **D1, D9 and D22 were reproduced through the real participant
> path with Playwright on 2026-08-19** (see D1's E2E section); the rest are still
> static findings.

Refs are `MICA.php` unless another file is named. SPA paths are relative to
`mica-chatbot/`. `SCA/` = `modules-local/secure_chat_ai_v9.9.9/`.

---

## Full-path E2E run (2026-08-19)

Playwright suite over the real participant path on PID 257 — Survey Login gate
plus chatbot — desktop (1400×950) and iPhone 13. **23 checks, all passing.**

| Group | Result |
|---|---|
| **Survey Login** | Gate present on the ED-session link; chat unreachable before login; **wrong credential rejected**; the control survey (`baseline1`) correctly does **not** prompt — scoping holds |
| **Chatbot load** | Header, intro message, End Session control, composer all render; exactly **one** JS + one CSS bundle loaded (no stale-asset double-load); zero failed requests |
| **Conversation** | Two turns; message echoed; real model replies; **context retained across turns** ("You asked me what 2+2 equals."); all four `proj_mica` AJAX calls HTTP 200; zero MICA JS errors |
| **Mobile** | Renders; **no horizontal overflow** (390/390); composer inside the viewport; turn works |

Two attribution notes, so nobody chases the wrong thing:

- **The `offsetHeight` TypeError on survey pages is REDCap core, not MICA.** It
  fires on the control survey too — a page MICA's hooks never touch — and the
  stack lands in `redcap_v17.2.3/Resources/webpack/js/bundle.js`. Excluded from
  the module's error budget.
- **An earlier "auth scoping broken" reading was a false positive in the test,
  not a real finding.** `baseline1` is the Enrollment instrument and contains
  `last_name` as a genuine data-entry field, so keying on
  `input[name=last_name]` matches it. Discriminate on
  `input[name=survey-auth-submit]` + a "Log In" button instead. The repo's own
  `verify-auth-config.php` passes all checks, including "non-MICA surveys
  gated: none".

Three gaps observed in the same run, all downstream of the PID 257 structure
mismatch rather than of the chat code: no counselor persona (D22's known gap),
reload loses the transcript (D6 below), and **End Session fatals** (D16 below).

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

#### Playwright E2E confirmation (2026-08-19)

Run against the real participant path on PID 257: `manual-test-auth.php 257
setup` → open the ED-session survey link → Survey Login (`last_name =
Testerson`) → chat UI. Desktop (1400×950) and iPhone 13 viewports.

Confirmed working:

- Survey Login gate appears and is **scoped** — it prompts on
  `mica_ed_session` only, exactly as [`10`](10-auth-implementation-pid257.md)
  intends.
- The SPA mounts and renders correctly on both viewports: header, MICA avatar,
  intro bubble, composer, End Session (screenshots in the run artifacts).
- **D1 is fixed, verified through MICA's own AJAX endpoint inside the
  authenticated survey session** — i.e. through `handleUserInput()` →
  `assertModelIsRegistered()` → `callAI()` → `formatResponse()` →
  `logMICAQuery()`, over real HTTP, not a direct provider call:

  ```json
  {"response":{"role":"assistant","content":"OK"},
   "id":null,"model":"claude-haiku-4-5-20251001",
   "usage":{"prompt_tokens":14,"completion_tokens":4,"total_tokens":18},
   "user_id":"MICATEST01","query":{"role":"user","content":"Reply with the single word OK."}}
  ```

  Both transcript rows persisted with `mica_id = MICATEST01`. This also
  confirms empirically that `id` is always `null`
  ([`13`](13-securechatai-current-state-delta.md) §1.3).

Confirmed broken, by observation:

- **D9** — `typeof window.renderMicaApp === "undefined"` and
  `#mica-hide-native` was still in the DOM after mount, exactly as predicted.
- **D22 (new — see below)** — no message can be sent through the UI at all.

**Still outstanding:** the production `llm-model` value and check 2 above — I can
only measure this instance.

### D22 — On PID 257 the participant cannot send a message at all, silently

**Severity:** blocking for any UI-level testing on PID 257
**Status: FIXED 2026-08-19 — reproduced, fixed, and re-verified E2E (desktop +
mobile). See "Fix and verification" below.**

Typing a message and pressing Enter *or* clicking the send arrow does
**nothing**: the message is not echoed, no AJAX request is made, no error is
shown or logged, and the spinner does not even start. Root cause chain, each link
observed in the live page:

1. PID 257 has no `participant_name` / `participant_email` field, so the survey
   bootstrap ships `name: null`, `email: null` (`MICA.php:299-305`). Observed:
   `{"participant_id":"MICATEST01","name":null,"email":null,"current_session":null,
   "session_start_time":null,"initial_system_context":[]}`.
2. `useAuth.jsx:17` hard-requires `b.name` and aborts before writing Dexie.
   Observed: `indexedDB.databases()` returned `[]` — the `user_info` database is
   never created.
3. `Chat.jsx:46-48` `addMessage()` does `const user = await getCurrentUser();
   if (user[0]?.id) { … }` with no `else`. With no Dexie store the gate is
   falsy, so the entire body — echo, `apiContext` update, and the `callAI` call —
   is skipped **with no diagnostic**.

This is distinct from D7 (the `.pop()` chain): execution never reaches the
system-context injection, because the message is dropped one step earlier. It is
also why D1 could not be observed through the chat bubble and had to be verified
at MICA's AJAX endpoint instead.

A **second, independent** cause was found while fixing this: `cacheUser()` stored
`id: parseInt(participant_id)` (`useAuth.jsx`). Any non-numeric REDCap record id
becomes `NaN`, which is falsy and fails the very same gate — so even with a
`name` present, a project using non-numeric record ids would have been dead in
exactly the same silent way.

#### Fix and verification

Four changes, all client-side; no wire-contract change, so this is independent of
the Stage 1 §1.6 payload work:

1. `useAuth.jsx` — the bootstrap no longer requires `b.name`. It is a pilot-only
   field (`participant_name`) that does not exist in the R01 structure; requiring
   it aborted the whole bootstrap.
2. `useAuth.jsx` — `cacheUser()` keeps the record id verbatim instead of
   `parseInt()`-ing it, and no longer requires `name`. Its failure branch is now
   `console.error`, not `console.log`.
3. `Chat.jsx` — `callAjax()` checks readiness **up front**. If no identity is
   cached it echoes the participant's message, renders a plain-language error in
   the transcript, and invokes the callback so the spinner clears — instead of
   dropping everything silently.
4. `Chat.jsx` — the `.pop()` on the initial system context is replaced by a loop
   over all entries, guarded for the empty case. **This also fixes D7 and D8**,
   which live on the same two lines: the empty case no longer throws, and
   multi-entry contexts are no longer truncated to the last element.

`addMessage()` keeps the identity check as a last-resort guard, but now logs
instead of returning silently, and rejects a malformed `message` argument.

Re-verified E2E on both viewports (`npm run lint` shows 43 problems before and
after — all pre-existing; `npm run build` regenerated `dist/`):

- Participant message is echoed, a **real model reply renders in the bubble**, no
  apology, composer resets. This also closes the one hop D1's verification could
  not reach — the SPA consuming the response (`Chat.jsx:74`) and rendering it.
- Four transcript rows persisted across the two sends, all tagged
  `mica_id = MICATEST01` — a **string**, confirming the `parseInt` removal.

**Known remaining gap on PID 257 (expected, Stage 2 scope):** the reply comes
back with no counselor persona — the model introduced itself as Claude — because
`initial_system_context` is still `[]`. The pilot session engine cannot resolve
`baseline_arm_1` / `consent_date` against the R01 structure, so no prompt is
assembled. The new `console.warn` in `callAjax()` announces this. The send path
is fixed; supplying the R01 session context is
[`09-pid-257-structure-audit.md`](09-pid-257-structure-audit.md) / Stage 2 work.

**Still worth doing later:** the Dexie identity cache disappears entirely once
participant identity is server-derived (`07` decision 8 / Stage 1 §1.6). This fix
makes the current design work; it does not pre-empt that change.

**Also noted during the run:** `scripts/manual-test-auth.php` teardown deletes
**every** response-less participant row in the project
(`manual-test-auth.php:118-121`), not only the test record's. It removed five
pre-existing orphan rows for surveys 1315/1316. No response data was lost (those
rows had none), but previously generated survey links for them are now dead. The
docstring says "delete the test participant and all traces" — worth narrowing the
`DELETE` to the test record.

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
**Observed 2026-08-19:** on PID 257 restore does not happen **at all** — after a
reload mid-conversation the transcript is empty and only the intro bubble shows.
`fetchSavedSession()` passes `b.name` (null on this structure) and the backend
`fetchSavedQueries` requires a name and cross-checks it against
`participant_name` (`MICA.php:515`, `:533`), so the call returns nothing. The
display-only defect described below is therefore the *next* failure a
pilot-structured project would hit, not the current one here.

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
**Status: PARTLY FIXED 2026-08-19.** The client-side crash is gone — `callAjax()`
no longer `.pop()`s an empty array, so the send no longer throws and the spinner
no longer sticks (see D22's fix). **The server's message is still lost:**
`redcap_survey_page` catches the gate exception into `$error` (`:268-275`) and
never sends it to the browser, so the participant is told nothing about *why*
there is no session. Surfacing that text is the remaining half.

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
**Status: FIXED 2026-08-19** — `callAjax()` now iterates every entry returned by
`getInitialSystemContext()` instead of `.pop()`-ing one (see D22's fix). Not yet
observable on PID 257, where the context array is empty; needs a pilot-structured
project (or Stage 2's R01 context) to regression-test the multi-entry case.

`appendSystemContext()` `array_unshift`es a new entry when the first system
message is empty (`:165-167`), so `initSystemContexts()` can legitimately return
**more than one** element. The client then keeps only the last one:
`Chat.jsx:119` calls `.pop()`.

**Predicted symptom:** with a blank `chatbot_system_context_general`, or whenever
the catch-up summary is unshifted, the prior-session summary or the general
context is dropped before the model sees it — with no error.

### D16 — End Session fatals and silently discards the session

**Severity:** high — the participant's session is lost, and they are told nothing
**Status:** reproduced E2E 2026-08-19 (promoted from the low-severity table)

Clicking **End Session** on PID 257 returns:

```
Call to a member function getTimestamp() on null
```

`completeSession()` calls `calculateSessionInfo()` (`:887`), which returns `null`
when `baseline_arm_1` cannot be resolved (`:828`). The result is dereferenced
without a guard, so `$calc["sessionStart"]->getTimestamp()` (`:889`) is a fatal on
`null`.

What the participant experiences: `header.jsx`'s `errorCallback` runs
`handleSignOut()`, so they are **signed out and redirected to the login page**
with no message — and because the fatal happens before `REDCap::saveData()`,
`raw_chat_logs`, `session_timestamp` and `session_info_complete` are **never
written**. The whole conversation is discarded. Observed: the URL moved to
`pages/chatbot&NOAUTH` and no data row was written.

**Fix direction:** guard `$calc` (and the same null return at `:851`) and raise a
typed exception that the client renders as a real message, per the fail-closed
rule in `06-implementation-plan/README.md`. Note the underlying `null` is again
the R01/pilot event mismatch, so the guard is the durable part and the session
engine rework (Stage 2) is what makes the happy path work.

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
| D16 | **Promoted to section B** — reproduced as a fatal that loses the session. See "D16 — End Session fatals" above | `:887-889` |
| D17 | `Sanitizer` HTML-escapes prompt text **before** it reaches the model and before storage, so apostrophes persist as `&#039;` in transcripts | `classes/Sanitizer.php:16` |
| D18 | `get_magic_quotes_gpc` branch is dead under PHP 8 | `classes/Sanitizer.php:13` |
| D19 | `pages/chatbot.php:68` hardcodes `ui_hosting_instrument` in `getSurveyLink()`, so the new `chat_host_instruments` setting does not reach the OTP path | `pages/chatbot.php:68` |
| D20 | `messages.jsx:95` references an undefined `intro_text` identifier (unreachable branch) | `src/components/messages/messages.jsx:95` |
| D21 | `PROJECT_ID` vs `$this->getProjectId()` used inconsistently | `:407`, `:534`, `:894`, `:943` vs `:695`, `:718`, `:772`, `:832` |

---

## Suggested sequencing

1. ~~**D1 alone, first**~~ — **done in dev 2026-08-19** (config choices + PID 257
   value + an alias guard). Production value still to be confirmed.
2. ~~**D22 next**~~ — **done 2026-08-19**, and it carried D8 plus D7's crash with
   it. A participant can now send and receive a real reply on PID 257, desktop
   and mobile.
3. **Record the Playwright baseline** (login → chat turn → end session, desktop +
   mobile). Now unblocked. Note the baseline will show a reply with **no
   counselor persona** until the R01 session context exists (D22's "known
   remaining gap"), so record it as a send-path baseline, not a prompt baseline.
4. **D2 + D3 + D4** as one security pass — they share the "trust the client's
   participant id" root cause, and D2's fix is the same edit the SOW payload
   change needs.
5. **D6, D7, D8** — participant-visible behavior; each needs a failing E2E test
   first.
6. **D5** with the Stage 0 §0.6 logging cleanup.
7. **D9-D21** folded into Stage 0 §0.6 as behavior-preserving cleanup.
