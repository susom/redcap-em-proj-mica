# 14a — Live defects: chat path and security (D1–D5, D22)

Part of [14 — Live defects](14-live-defects.md). Section A of the audit, first half: the participant chat path and the no-auth AJAX surface.

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
([`13`](../reference/13-securechatai-current-state-delta.md) §6.1) does discriminate; and the
failed call wrote a `SecureChatLogError` row while the successful one wrote
`SecureChatLog` — with **no** `CappyChatTurn` row either time, empirically
confirming that a caller omitting `session_id` gets no turn log at all
([`13`](../reference/13-securechatai-current-state-delta.md) §7 item 3).

**Fix applied (dev):**

1. `config.json` — replaced all seven stale `llm-model` choices with the actual
   registry aliases, changed `default` from `gpt-4o` to `gpt-4-1` (the
   SecureChatAI prod default), and documented the silent-failure consequence in
   the setting's own label.
2. PID 257 `llm-model` → `claude-haiku-4-5`, the only alias this instance has
   registered. **Note for Stage 1:** Claude aliases are *not* json_schema-capable
   (`SecureChatAI.php:399`), so the counselor-v2 work still needs a
   `gpt-4-1`/`gpt-5-4` alias added to `api-settings`
   ([`13`](../reference/13-securechatai-current-state-delta.md) §8).
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
  `mica_ed_session` only, exactly as [`10`](../topics/auth/10-auth-implementation-pid257.md)
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
  ([`13`](../reference/13-securechatai-current-state-delta.md) §1.3).

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
[`09-pid-257-structure-audit.md`](../archive/09-pid-257-structure-audit.md) / Stage 2 work.

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
fail loudly. See also [`13`](../reference/13-securechatai-current-state-delta.md) §6.1 —
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
