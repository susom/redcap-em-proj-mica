# 14 — Live defects found during the SecureChatAI audit (2026-08-18)

**Status:** FINDINGS — most verified by code reading. **Fixed and verified so far:
D1, D2, D3, D4, D6, D7, D8, D10, D11, D13, D16, D22.** Still open: D5, D9, D12,
D14, D15, D17-D21
**Scope:** defects in the code as it stands on `mica-phase-3` @ `ab81cca`.
These are distinct from the SOW migration work in
[`07-chatbot-cleanup-securechatai.md`](07-chatbot-cleanup-securechatai.md):
they are broken now and do not need the phase-3 architecture to be fixed.

> **Process note (project standard):** every item below must be reproduced in a
> Playwright E2E run as an end user *before* it is fixed, and the failing test
> kept. Except where a defect is explicitly marked **Observed**, the evidence is
> static analysis with line-level citations — treat those symptoms as predicted,
> not observed. **D1, D6, D7, D9, D16 and D22 were reproduced through the real
> participant path with Playwright on 2026-08-19**, and each fix re-verified the
> same way. D7's gate message is the one partial: the server half is asserted
> against real output, the client half against a simulated payload, because PID
> 257 cannot raise that gate (see D7). The rest are still static findings.

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
mismatch rather than of the chat code. Two have since been fixed: reload now
restores both the transcript and the model's context (D6), and End Session fails
honestly instead of fataling (D16 — though finalization still cannot *succeed* on
PID 257 until the session engine is reworked). The remaining one is D22's known
gap: no counselor persona, because `initial_system_context` is still `[]`.

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
**Status: FIXED 2026-08-19** for the AJAX surface — attack attempted and blocked
E2E (4/4). `pages/sessionSelector.php` (D4) is a separate page POST and is
**still open**.

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

#### Fix and verification

**The framework already supplies the answer.** Probed at runtime: on the
survey-hosted AJAX call `$record` is populated (`MICATEST01`, type `string`)
alongside `has_survey_hash = yes`, `instrument = mica_ed_session`,
`event_id = 1008`. So identity never needed to come from the client.

- New `resolveParticipantId($record, $payload)`, called once before the action
  switch. If `$record` is present it is authoritative and the payload is ignored.
  Only an authenticated REDCap user — who already has data access, and is the only
  other principal the ajax guard admits — may name a record explicitly. Neither
  ⇒ throw.
- `callAI` uses it for the baseline injection **and** for the `mica_id` on both
  transcript rows; `fetchSavedQueries` and `completeSession` have their
  `participant_id` overwritten with it.
- `handleUserInput()` now keeps only `role` and `content`. The per-message
  `user_id` was both the identity source *and* a nonstandard key forwarded
  verbatim to the model API; dropping it fixes both (and is the SOW conformance
  item from `07` §Stage 1).

**Verified by attempting the attack**, through a real authenticated survey
session, 4/4:

| Attempt | Result |
|---|---|
| `callAI` with `user_id: 'VICTIM_RECORD_1'` | reply echoes `user_id: "MICATEST01"`; a real answer still returned |
| `fetchSavedQueries` with `participant_id: 'VICTIM_RECORD_1'` | returns the **authenticated** record's turns |
| `completeSession` with `participant_id: 'VICTIM_RECORD_1'` | runs against the authenticated record (its own finalization-failure path) |

Confirmed at the data layer afterwards: the only `mica_id` value written was
`MICATEST01`, **zero** rows under the forged id, and record `1` had no
`raw_chat_logs` / `session_info_complete` rows created.

**Note on the client:** the SPA still attaches `user_id` to outgoing messages. It
is now inert — the server drops it — so it is cosmetic cleanup for the SOW payload
work rather than a security concern.

### D3 — Raw `$_POST` interpolated into `filterLogic` on a no-auth page

**Severity:** high
**Status: FIXED 2026-08-19** — injection attempts rejected before any `getData`
call, unit-tested 11/11.

`loginUser()` (`:560`) and `verifyEmail()` (`:640`) interpolate values straight
into `REDCap::getData()` `filterLogic` strings, fed by unsanitized `$_POST` from
`pages/chatbot.php:44-45, 63-65`. `fetchSavedQueries` (`:525`) is mitigated —
its input passes the sanitizer at `:423`, and `htmlspecialchars(ENT_QUOTES)`
encodes `'` — but the two login paths are not.

#### Fix and verification

- `verifyEmail()` — codes are `bin2hex(random_bytes(3))`, i.e. exactly six
  lowercase hex characters, so the input is now shape-validated
  (`/^[0-9a-f]{6}$/` after `strtolower`+`trim`) and anything else is rejected
  before any `getData` call. A validated code cannot carry logic syntax.
- `loginUser()` — only a **validated e-mail** reaches `filterLogic`
  (`FILTER_VALIDATE_EMAIL`, plus an explicit reject of `' " \ [ ]`). The
  free-text **name is compared in PHP** instead of being interpolated, so no
  user-controlled string enters the logic string at all.
- `fetchSavedQueries()` stopped using `filterLogic` entirely as part of the D6
  fix — it now looks the record up via `records`.
- Also hardened while in `verifyEmail()`: the result is null-checked before
  subscripting, so a non-matching code no longer warns.

Unit-tested through the module instance, 11/11: five `verifyEmail` inputs
(`' OR [record_id] != ''`, too short, too long, quote-in-code, path traversal) and
five `loginUser` e-mails (quoted injection, malformed, double quote, single quote,
backslash) all rejected — each *before* reaching `getData` — while a well-formed
address still proceeds to the lookup.

**Known limitation, deliberate:** the e-mail validation rejects apostrophes, so a
legitimate address like `o'brien@example.com` would be refused. Apostrophes are
legal in local parts but rare. I chose a certain guarantee over guessing REDCap's
`filterLogic` escaping semantics; if a real participant hits this, the fix is to
match the e-mail in PHP as well (a full-project read on a no-auth login action,
which is why it was not done pre-emptively).

### D4 — Any project user could close any participant's session

**Severity:** high (raised from medium-high)
**Status: FIXED 2026-08-19.** Investigated properly before fixing, which corrected
two claims in the original write-up and found a worse defect than the one described.

#### What the original entry got wrong

- **"no CSRF token" — wrong.** CSRF *is* enforced. The framework calls
  `checkCSRFToken($page)` in `ExternalModules/index.php:150`, before the page file is
  included; MICA is framework 14 and `CSRF_MIN_FRAMEWORK_VERSION` is 8, and the module
  declares no `no-csrf-pages`. REDCap core supplies the token without the page doing
  anything: `HtmlPage.php:197` → `System::createCsrfToken()` → `appendCsrfTokenToForm()`
  adds `redcap_csrf_token` to **every** form, and
  `ExternalModules/redcap_connect.php:21` maps it onto the token the framework checks.
  So the "Complete Session" button was never broken, and CSRF needed no fix.
- **"`:57` echoes that value"** — it echoes `$session['participant_id']` from
  `REDCap::getData()`, not `$_POST`. Still unescaped in an HTML attribute while every
  sibling cell escaped, so still worth fixing; just not a reflection of POST input.

#### The actual defect (worse than described)

Two things compounded:

1. **`MICA.php:86-93` overrode `redcap_module_link_check_display()` and returned `$link`
   unconditionally, never calling the parent.** That hook is not cosmetic — `index.php`
   exits when it returns null, so it gates page *access*, not just the sidebar entry. The
   framework's default (`AbstractExternalModule.php:70-88`) restricts project links to
   **design-rights** users; the override removed that for **both** MICA links. Anyone with
   access to the project could open "Mica Session Admin".
2. **`validatePermissions()` did not check the calling user at all.** It read
   `current(UserRights::getPrivileges(PROJECT_ID)[PROJECT_ID])` — and `getPrivileges($pid)`
   with no `$userid` returns *every* user in the project ordered by username, so `current()`
   took the **alphabetically first user**. The answer was independent of who was asking.

   Demonstrated on PID 257 rather than argued: adding `test` (`user_rights=0, design=0`)
   alongside `ihabz` (`user_rights=1`) made the expression return `'1'`, so `test` was
   granted. The mirror case denies a legitimate administrator whenever the
   alphabetically-first user lacks the right. The fixture was removed afterwards.

Net effect: any user with project access could open the page and click Complete Session on
an arbitrary participant, and the one function meant to stop them would have said yes.

#### Fix

- `redcap_module_link_check_display()` now calls
  `parent::redcap_module_link_check_display()` first and returns null when the parent
  refuses. The `&NOAUTH` rewrite for the chatbot link is applied only after approval.
  Verified this does **not** break participant access: `isNoAuth()` is
  `isset($_GET['NOAUTH'])`, so the parent approves a `&NOAUTH` request, and
  `index.php:88` independently refuses `NOAUTH` on any page outside `no-auth-pages`
  (`['pages/chatbot']`) — confirmed by request: the admin page with `&NOAUTH` returns
  *"The NOAUTH parameter is not allowed on this page."*
- `validatePermissions()` delegates to the new, unit-tested
  [`classes/UserRightsCheck.php`](../../classes/UserRightsCheck.php), which requires a
  username, looks it up **by key**, and honours super users. 13 tests, including both
  directions of the ordering bug.
  One non-obvious interaction it defends against: `getPrivileges($pid, '')` does *not*
  scope the query — its guard is `if ($userid != null)` and `'' != null` is false — so an
  empty username returns every user. The check refuses anonymous before any lookup.
- `pages/sessionSelector.php` gates on `validatePermissions()` at the top of the file,
  before the POST branch, and renders an explicit refusal. `completeSession()` is wrapped
  (it throws) so a failure shows a message instead of a bare REDCap error page, and the
  `participant_id` attribute is escaped.

#### Not addressed here

The pilot runs from the `pilot-final` tag and does **not** receive this fix. Whether to
ship a pilot hotfix off that tag is a study decision — see `CHANGELOG.md` for the
branch policy.

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

### D23 — The provider rejects the SafetyScan schema: **no post-session scan can ever succeed** on `gpt-5-6-sol`

**Severity:** blocking (safety-critical — the scan is the mechanism that surfaces self-harm
disclosures for review, and it fails 100% of the time)
**Status:** open. Found 2026-08-25 by capturing the outgoing body; see `20-llm-request-capture.md`.

The pinned output schema uses `uniqueItems`, and Azure OpenAI structured outputs does not allow it.
Every SafetyScan call returns HTTP 400 before the model sees anything:

```json
{"error":{"message":"Invalid schema for response_format 'response': In context=('properties','findings','items','properties','recommended_actions'), 'uniqueItems' is not permitted.","type":"invalid_request_error","param":"response_format"}}
```

`uniqueItems: true` appears twice in `handoff/MICA_safetyscan_postsession_model_output_schema.json`
(lines 102 and 118 — `recommended_actions` and `recommended_notification_targets`) and four more
times in `handoff/MICA_safetyscan_notification_policy_schema.json`. The provider reports the first one
it reaches, so removing only line 102 will move the error, not clear it.

#### Where it lands, traced through the code

The fail-safe design holds — the failure is visible as a task, it is never mistaken for a clean
screen — but it costs nine rejected provider calls per session and names the wrong cause:

| Step | Where | Result |
|---|---|---|
| 3 identical requests, all 400 | `SecureChatAI.php:435` (`$retries = 2`) | `callAI()` does not throw; it returns `['error' => true, 'type' => 'NETWORK_ERROR', 'message' => 'Error after 2 retries: HTTP error: 400 (response body omitted; length=…)']` |
| the caller reads that error | `SecureChatSafetyScanCaller.php:115-125` → `classifyErrorText()` | no `timeout`/`content_filter`/`refusal` needle matches, so it falls through to **`service_error`** |
| the state machine classifies it | `ScanJobStateMachine.php:59` — `service_error` ∈ `TRANSIENT` | job **requeued** with 60s, then 240s backoff |
| attempts run out | `safetyscan-max-attempts`, unset on PID 257 → default **3** | job → **`manual_review_required`**, which is a visible task and correctly not a negative screen |

So one completed session burns **3 job attempts × 3 `callAI` tries = 9 rejected requests** over about
five minutes before a human is asked to read the transcript by hand. Every session, forever, on every
project pointed at this alias.

#### Why nothing had noticed

The `service_error` classification is the problem: it says *transient*, and this is the opposite of
transient. Retrying a schema the provider will never accept is a guaranteed-loss loop, and the rows
left behind invite exactly the wrong diagnosis — "the AI Hub is flaky," not "our schema is invalid."

The 400's message is the only place the real cause is ever stated, and it is discarded at that one
point on purpose: `executeAPICall` omits the response body from the exception because it can echo PHI.
So the `scan_run` row records `service_error` plus `HTTP error: 400 (response body omitted;
length=…)` — enough to know the provider refused, never enough to learn it was `uniqueItems`. No log
level would have helped.

It surfaced as three byte-identical 8777-byte captures one second apart (`20260825-17040{0,1,2}`),
after a real session was completed through the SPA. Replaying one captured curl returned the 400
above — deterministic, not transient.

The existing verifier does not catch it: `scripts/verify-safetyscan.php` passes because it drives the
mock path (`scan-mock-mode`), which never builds a `response_format`. Any coverage that would have
caught this has to reach the provider with the real schema.

#### Fixing it is not a one-line edit

The schema is hash-pinned. `ArtifactRegistry` verifies the bytes against `handoff/manifest.json` on
every read and throws `ArtifactIntegrityException` on a mismatch, and the hash is recorded per run as
`output_schema_sha256` in `redcap_entity_mica_scan_run`. So a fix is: strip `uniqueItems`, re-pin the
manifest hash, and accept that runs before and after the change carry different schema hashes — which
is the pinning mechanism working as designed, not a problem to hide. Uniqueness would then be enforced
where it can be, in `ScanRunner`'s post-hoc validation (`:248`) rather than by the provider.

Worth deciding at the same time whether `notification_policy_schema`'s four occurrences are ever sent
to a provider or are validation-only; if the former, they fail the same way.

---

## B. Functional defects

### D6 — Restoring a session does not restore the model's context

**Severity:** high (silently degrades the intervention)
**Status: FIXED 2026-08-19** — reproduced, fixed, re-verified E2E on desktop and
mobile (9/9 each). See "Fix and verification" below.

**As observed before the fix:** on PID 257 restore did not happen **at all** —
after a reload mid-conversation the transcript was empty and only the intro
bubble showed.
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

#### Fix and verification

Restore was broken in two places — the call never succeeded on the R01 structure,
and even when it did the model context was not rebuilt:

1. `MICA.php` `fetchSavedQueries()` — the `participant_name` cross-check is now
   applied **only where the project has that field**. The pilot's second-factor
   gate is preserved wherever it exists; projects without it (R01) no longer have
   restore made impossible by a name they cannot supply. Two long-standing bugs
   in the same method went with it: the comparison now uses the project's real
   primary field instead of a hardcoded `record_id` (**D13**), and the record is
   fetched via `records` instead of interpolating user input into a `filterLogic`
   string (same class as **D3**).
2. `Chat.jsx` `replaceSession()` — rebuilds `apiContext` from the restored turns,
   not just the display state. The system context is prepended **here** rather
   than left to `callAjax()`, so it stays ahead of the history and every entry
   carries `user_id` (the backend reads the participant id off the *first*
   message).
3. `Chat.jsx` `callAjax()` — the system-context injection now triggers on "no
   system message present" instead of "context array is empty". After a restore
   the array is non-empty, so the old condition would have skipped injection and
   sent a restored conversation with no system prompt at all.

Verified E2E, desktop and iPhone 13, 9/9 each. The decisive check is not that the
transcript reappears but that the **model** has it: told a codeword, reloaded the
page, then asked for the codeword back — and got it. Re-ran the full 23-check
suite afterwards with no regressions.

### D7 — Send button hangs permanently on the "session already completed" gate

**Severity:** medium-high (dead end with no message)
**Status: FIXED 2026-08-19 (both halves).** The client-side crash went first, with
D22: `callAjax()` no longer `.pop()`s an empty array, so the send does not throw
and the spinner does not stick. The second half — the server's message being
dropped — is now fixed too: `redcap_survey_page` forwards the caught gate message
as `bootstrap.error`, and the SPA renders it on load instead of showing a chat
that looks usable but cannot send. Verified E2E, 9/9 on desktop and mobile.

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

#### Fix and verification (second half)

- `MICA.php` `redcap_survey_page()` — the caught message is forwarded as
  `bootstrap.error` instead of being assigned to `$error` and dropped.
- `useAuth.jsx` — when `bootstrap.error` is present the SPA renders it into the
  transcript on load and does **not** start a chat session.
- `Chat.jsx` `callAjax()` — the not-ready message now prefers the server's reason
  over the generic "could not be started" text, so a participant who types anyway
  gets the same explanation rather than a second, vaguer one.

Verified E2E, desktop and iPhone 13, 9/9 each: the server emits an `error` key in
the real bootstrap attribute (asserted via `hasOwnProperty`, so this half is
verified against actual server output, not just the injected value); the client
reads it; the message renders on load; and sending repeats the server's reason.

**Testing note:** PID 257 cannot *produce* these gates — they need
`session_info_complete` / `month3_fu_complete`, which the R01 structure lacks, and
`calculateSessionInfo()` returns `null` there without throwing. The gate message
was therefore simulated by intercepting the assignment the page's inline bootstrap
script makes, which exercises the real client path against a realistic payload.
The server side of it is asserted separately (the `error` key is present in the
attribute PHP actually rendered). A project that can raise a real gate should
re-confirm end to end.

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

**Severity:** high — the session is not finalized, and the participant is told nothing
**Status: FIXED 2026-08-19** — reproduced, fixed, re-verified E2E on desktop and
mobile (8/8 checks). Promoted here from the low-severity table.

> **Correction to an earlier draft of this entry:** it said the *whole
> conversation* was discarded. That overstated it. `logMICAQuery()` writes each
> turn to the EM log as it happens, so the per-turn rows survive; verified after
> a failed finalization — 2 transcript rows present, `raw_chat_logs` rows: 0.
> What is lost is the **session finalization**: the `raw_chat_logs` snapshot,
> `session_timestamp`, and the `session_info_complete` flag.

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

#### Fix and verification

Three changes on the failure path, plus the two happy-path bugs that live in the
same function:

1. `MICA.php` `completeSession()` — guard the `calculateSessionInfo()` result
   (`null`, or a non-`DateTime` `sessionStart`) and throw a participant-safe
   `Exception` instead of dereferencing `null`. The technical detail goes to
   `emError`; the participant gets plain language.
2. `assets/jsmo.js` — `completeSession` now surfaces `parsed.error` rather than
   handing the caller `"Unexpected response: " + <raw JSON>` to display.
3. `header.jsx` — the error callback **no longer signs the participant out**. It
   renders the message into the transcript and leaves them on the page, so a
   failed finalization is visible and retryable instead of looking like a normal
   exit.
4. **D11** (same function) — the `saveData` result now goes through the shared
   `describeSaveDataErrors()` helper; see the D11 entry below.
5. **D10** (same function) — `$event_id` is initialized to `null`, so the
   `emDebug` call for sessions 2-6 no longer reads an undefined variable.

Verified E2E, desktop and iPhone 13, 8/8: no PHP fatal, no framework
ajax-error wrapper, the participant-safe message returned and rendered in the
transcript, no raw JSON leaked to the UI, participant **not** signed out (URL
unchanged), no unexpected page errors.

**Not verified: the happy path.** No project in this environment has the pilot
structure, so `completeSession()`'s success branch — `saveData()`, the
`raw_chat_logs` write, and the posttest / month3_fu survey-link lookup — was not
exercised. The D10 change there is defensive and was checked by inspection and
`php -l` only; the D11 helper it calls is unit-tested (see D11 below). That branch becomes testable when the Stage 2 session engine
lands (or against a pilot-structured project).

**Still the root cause:** the `null` comes from the R01/pilot event mismatch. This
fix makes the failure honest and non-destructive; it does not make finalization
*work* on PID 257. That is Stage 2 /
[`09-pid-257-structure-audit.md`](09-pid-257-structure-audit.md).

### D11 — `saveData` results: the OTP path failed *open*

**Severity:** medium-high — a participant could be locked out of their own study
**Status: FIXED 2026-08-19 (all call sites), unit-tested 12/12**

Originally logged as a low-severity type-check nit. It is worse than that in
`generateOneTimePassword()`, because there the subscript **was the success
condition**:

```php
$response = \REDCap::saveData('json', json_encode($saveData), 'overwrite');
if (empty($response['errors'])) { /* email the code */ }
else { /* throw */ }
```

`empty()` suppresses the illegal-offset diagnostic, so **any non-array response
evaluates as success**. The participant would then be emailed a verification code
that was never written to `two_factor_code` — and `verifyEmail()` looks the code
up by `filterLogic` on that field, so the code can never match. The failure mode
is a participant holding a valid-looking code that cannot possibly work, with
nothing logged.

**Fix.** One shared, fail-closed helper, `describeSaveDataErrors($response)`,
returning `''` only when the save genuinely reported no errors:

- normalizes the documented `returnFormat=json` string shape by decoding it;
- treats an unparseable string or any non-array as an **error**, not success;
- flattens `errors` whether it is a string, a list, or REDCap's nested rows
  (`json_encode`-ing non-scalars rather than stringifying an array).

`generateOneTimePassword()` now checks the save **before** sending any email, and
`completeSession()` was refactored onto the same helper so there is one
implementation rather than two.

Unit-tested through reflection, 12/12: empty errors, absent `errors` key, string
error, list of strings, nested error rows, JSON-string success, JSON-string with
errors, unparseable string, `null`, `false`, `int` — plus an explicit assertion
that a non-array response **fails closed** so no OTP would be sent. The End
Session E2E was re-run after the refactor (8/8) to confirm no regression.

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
| D10 | ~~`$event_id` undefined when `emDebug`'d for sessions 2-6~~ **FIXED 2026-08-19** with D16 | `:934` |
| D11 | ~~`REDCap::saveData()` return subscripted without a type check~~ **FIXED 2026-08-19 — all call sites.** See "D11 — saveData results" in section B | `:641`, `:960` |
| D12 | `summarizeCatchUp` builds `session_0_arm_1` when `$i === 0`; `getEventIdFromUniqueEvent` returns null and `'events' => [null]` reaches `getData` | `:768-769` |
| D13 | ~~`fetchSavedQueries` compares `$check['record_id']` instead of `$primary_field`~~ **FIXED 2026-08-19** with D6 | `:533` |
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
4. **D2 + D3 done 2026-08-19**; **D4 still open** — `sessionSelector.php` runs
   `completeSession` before `validatePermissions()`, with no CSRF token. It is a
   page POST rather than part of the AJAX surface, so it was not covered by the
   D2 fix.
5. ~~**D6, D7, D8**~~ — **done 2026-08-19**, all three E2E-verified (D8's
   multi-entry case still needs a project that produces more than one context
   entry).
6. **D5** with the Stage 0 §0.6 logging cleanup.
7. **D9-D21** folded into Stage 0 §0.6 as behavior-preserving cleanup.
