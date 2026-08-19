# 13 — SecureChatAI current-state delta (agent loop, hooks, response contract)

**Status:** EVIDENCE (verified against code 2026-08-18)
**Why this doc exists:** [`07-chatbot-cleanup-securechatai.md`](07-chatbot-cleanup-securechatai.md)
was written 2026-08-13. SecureChatAI has since gained a built-in agent loop,
a tool framework, and pre/post tool-use hooks. This doc records the **verified**
consumer-facing contract as of `secure_chat_ai_v9.9.9` @ `c847be0`, corrects the
stale claims that resulted, and answers the adoption question for MICA.

Every claim below was read out of provider source. Refs are
`SecureChatAI.php:NNN` unless another file is named; `SCA/` =
`modules-local/secure_chat_ai_v9.9.9/`.

---

## 1. The consumer contract, as verified

### 1.1 `callAI()`

```php
public function callAI($model, $params = [], $project_id = null, $username = null)   // :431
```

No type declarations, no return type. Up to **3 attempts, no backoff**
(`:435-437`, `:518`). **It never throws `\Exception`** — every failure path
returns an array (`:506`, `:521-546`). It catches `\Exception`, *not*
`\Throwable`, so an `Error`/`TypeError` raised inside a hook or the tool
pipeline still escapes to the caller.

| Arg | Effect if omitted |
|---|---|
| `$project_id` | no audit rows (`:1208`), no error rows (`:524-528`), zero tools in agent mode (`:637`) |
| `$username` | turn rows written with `username: null` (`:2295`) ⇒ any **user-scoped** `rehydrateProjectSession()` returns an empty session (`:194`); cross-project tool calls denied (`classes/ProjectAccessPreHook.php:36-40`) |

### 1.2 `$params` — what is honored, filtered, or ignored

Filtering happens in `filterDefaultParamsForModel()` (`:379-428`).

| Key | Behavior |
|---|---|
| `messages` | required for chat models |
| `session_id` | optional; **required for any rehydration** (`:2242` returns early without it); stripped before the outbound call (`:386`, `:426`); promoted to an indexed EM-log parameter (`:2317`) |
| `agent_mode` | opt-in gate — see §2 |
| `log_turn` | `=== false` skips turn logging (`:2246`) |
| `project_id` | **ignored** — stripped (`:386`, `:426`); pid comes from arg 3 only |
| `temperature`, `top_p`, `frequency_penalty`, `presence_penalty` | system-setting defaults are **always merged in** (`:53-58`, `:390`), so they are always sent for OpenAI-compatible models. Claude sends `temperature` XOR `top_p`, and **neither for Opus** (`classes/Models/ClaudeModelRequest.php:40-47`) |
| `max_tokens` | **effectively ignored for chat models** — unset at `:423`, then unconditionally overwritten by `computeDynamicMaxTokens()` (`:1171-1172`) |
| `reasoning_effort` | kept **only** for `['o1','o3-mini','gpt-5']` (`:392-394`). Note `o3`/`o4-mini` are on the strict-payload list (`:405`) but lose the key at `:393` first — a provider bug |
| `json_schema` | honored **only** for `gpt-4-1, gpt-4-1-nano, gpt-5, gpt-5-4, gpt-5-4-nano, o1, o3, o3-mini, o4-mini` (`:399-402`) |
| `tools`, `tool_choice` | passed **raw to the provider** on the normal path; `unset` in agent mode (`:952`). Native tool support is hard-disabled (`:2026`, `:2040`) |
| `stream` | **not supported** — no reader anywhere; a passed value is forwarded into the request body and the SSE reply then fails `json_decode` |

**There is no `setIfNotBlank()` in SecureChatAI** (0 hits). That helper is
Cappy's (`redcap_chatbot_v9.9.9/REDCapChatBot.php:447-452`). Nothing is dropped
for being blank — `array_merge` + `json_encode` pass `''` and `null` straight
through. A consumer that wants blank-means-absent must implement it itself.
This corrects the phrasing in `07` §"Stage 1 additions".

### 1.3 Response envelope

`callAI()` **always** returns the output of the private
`sanitizeOutputForUI()` (`:478`, `:504`, `:535`, `:556`) — never a raw
`normalizeResponse()` envelope.

| Case | Keys returned | Ref |
|---|---|---|
| Success (prose) | `role`, `content`, `model`, `usage` (+ `tools_used` if non-empty) | `:1690-1702` |
| Success (JSON content, non-agent) | the **full** normalized envelope: `content` (raw JSON string), `structured_output` (decoded array), `preserve_structure => true`, `role`, `model`, `usage` | `:1657-1660`, `:2139-2152` |
| Agent turn | `role`, `content`, `tools_used`; **`model` and `usage` are `null`** | `:1130-1134`, `:493-498` |
| **Failure** | **`role`, `content` only** — a canned apology string | `:1633-1636` |

Two consequences that matter more than anything else in this doc:

1. **`id` is never set.** Neither `normalizeResponse()` nor
   `sanitizeOutputForUI()` produces an `id`. MICA's `formatResponse()` reads
   `$response['id']` (`MICA.php:129`), so the `id` it returns to the SPA and
   stores in every `MICAQuery` row is **always `null`**.
2. **Failure is shape-indistinguishable from success.** The error envelope
   carries no `error`, no `type`, no `model`, no `usage` — only `role` +
   `content`, where `content` is one of 8 canned apologies (`:1619-1628`). A
   consumer can only detect failure by string-matching the apology, or by
   inferring from `model === null && usage === null` — which also matches a
   legitimate agent turn. See §6.1.

`extractResponseText($response)` (`:2424-2431`) still exists, is public, and is
not deprecated. It now returns `structured_output` (**an array**) when present,
else `content`, else `json_encode($response)` — it **no longer parses
`choices[0]`** at all.

---

## 2. Agent mode — how it actually gates

**Three independent gates.** All must hold:

1. SecureChatAI system setting `enable_agent_mode` (`:447`) — currently `true`
   on this server.
2. The **caller** passes `$params['agent_mode'] = true` (`:446`). Requested but
   not enabled ⇒ silently stripped, plain call proceeds (`:463-466`).
3. A **schema-capable alias** must be configured, or `runAgentLoop` throws
   (`:894-911`).

**MICA never sets `agent_mode`** (0 grep hits in the module). Therefore the
agent loop, the tool pipeline, and every hook are **unreachable** for MICA
today. `enable_agent_mode = true` at system level does not change that.

Other verified mechanics, for the record:

- **Tools** come from the tool EM's root `tools.json`, discovered by prefix from
  `agent_tool_em_prefixes` (system) **∪** `project_agent_tool_em_prefixes`
  (project) — a union since `408423b` (`:673-678`). Neither is set on this
  server, so an agent turn here would run with `TOOLS AVAILABLE: (none)`.
- **Dispatch** is in-process EM-to-EM: `getModuleInstance($prefix)
  ->redcap_module_api($action, $payload)` (`classes/JsonConfigToolAdapter.php:84`).
  Tools therefore run with **full server privileges, not the calling user's
  REDCap rights**.
- **Tool results** are appended to the conversation as synthetic
  **`role: 'user'`** messages (`:1119-1127`), capped at 8000 chars with
  trailing keys/items silently dropped (`:1512-1584`).
- **Hooks are tool-use hooks only.** The interfaces are `PreToolUseHook` /
  `PostToolUseHook` (`SCA/classes/HookInterface.php:10-26`; there is no class
  named `HookInterface`), registered by FQCN in SecureChatAI's own
  `pre_tool_use_hooks` / `project_pre_tool_use_hooks` settings, and fired only
  inside `ToolPipeline` (`SCA/classes/ToolPipeline.php:68`, `:84`). **They cannot
  intercept a plain completion.**
- `ProjectAccessPreHook` is always-on and prepended (`:1302`), with no toggle.
  It **denies** any tool whose input carries a `pid` when `username` is empty
  (`ProjectAccessPreHook.php:36-40`), and allows unconditionally — without
  checking user rights — when the input `pid` equals the `$project_id` the
  consumer passed (`:31-33`).
- `spawnAgent` is **auto-injected** into the tool catalog in agent mode
  (`:848-870`) and bypasses the hook pipeline entirely (`:1224-1226`).
- **No streaming, no working cancellation.** `AbortController` exists but
  `isAborted()` is never checked; the 120s timeout is only evaluated inside the
  tool-call branch after execution (`:1112`), and the per-call cURL timeout is
  500s (`classes/Models/BaseModelRequest.php:66`).

Three settings present in this server's DB are **dead code** — removed upstream
in `cb090cd` and never read: `agent_tool_registry`, `agent_tools_project_pid`,
`agent_max_clarifications`. Do not reason from them.

---

## 3. Recommendation: do **not** put the MICA counselor turn in agent mode

This reverses nothing — `07` already scoped agent mode out — but the reasons are
now concrete rather than "not needed", and they are worth recording because the
question will be asked again.

1. **It contradicts the R01 turn contract.** The handoff architecture is one
   model call per turn producing schema-valid `counselor_output_schema_v2`, gated
   app-side (`README.md:18`, `01-architecture.md §2`). The agent loop is N model
   calls per turn against a *different*, provider-owned schema
   (`final_answer`/`tool_call`/`thinking`), and it unwraps that schema before the
   consumer sees it.
2. **It destroys the per-turn telemetry Stage 1 is built to record.** Agent turns
   return `model => null` and `usage => null` (§1.3), so `mica_turn` rows would
   lose resolved model, tokens, and latency attribution — the whole point of
   `02-data-model.md §1.1`.
3. **Tools are structurally unavailable on the participant path.** MICA's
   `callAI` is a **no-auth** AJAX action (`config.json:53-59`) on a no-auth page,
   and there is no `USERID` anywhere in `MICA.php`. With no REDCap username to
   pass, `ProjectAccessPreHook` fails closed on every `pid`-bearing tool.
   Passing MICA's own `PROJECT_ID` as both context and tool arg would instead hit
   the *unconditional allow* branch — i.e. the only way to make tools work here
   is to disable the guard that makes them safe.
4. **Tool results would corrupt the transcript.** Results enter the conversation
   as `role: 'user'` messages. For a counseling transcript that is later
   snapshotted, hashed, and fed to SafetyScan (Stages 3-4), synthetic user turns
   are a data-integrity problem, not a cosmetic one.
5. **Uncontrolled recursion surface.** `spawnAgent` is injected automatically and
   bypasses hooks; suppressing it requires setting
   `agent_max_subagent_depth = 0`, a setting MICA does not own.
6. **No cancellation, no streaming, weak timeout** — for a participant-facing
   chat with a `minutes_remaining` session model, that is the wrong latency
   contract.

**Where agent mode could earn its place later** (explicitly out of scope now):
the *authenticated, staff-facing* surfaces — an RA-facing assistant on the
Stage-5 dashboard, or a session-admin helper — where a real REDCap username
exists, `ProjectAccessPreHook` works as designed, and the transcript is not a
study artifact. Revisit after Stage 5.

## 4. Hooks: not a PHI guard for MICA

Worth stating plainly because "pre/post hooks" sounds like a general
interception point: they are not. They fire only on tool calls (§2), so with no
tools there is nothing to hook. A `MicaScopePreHook` would be dead code.

If MICA ever does adopt tools, the registration recipe is: implement
`\Stanford\SecureChatAI\PreToolUseHook` with a no-arg constructor, make the
class loadable via `spl_autoload_register` in `MICA.php` (**not** an eager
`require_once` — that pattern broke Cappy's EM-enable path, commit `8706f83`),
and paste the FQCN into SecureChatAI's `project_pre_tool_use_hooks` for MICA's
pid. Nothing is added to MICA's `config.json`. Note the failure mode: an
unloadable hook is **silently skipped** (`:1367-1377`), indistinguishable from
no hook.

---

## 5. Stale claims in the existing plan docs

| Where | Claim | Status | Evidence / replacement |
|---|---|---|---|
| `README.md:27` | build against `gpt-5-4`, "already registered in SecureChatAI" | **STALE (dev env)** | this server's registry holds exactly one alias, `claude-haiku-4-5`, which is **not** schema-capable. A `gpt-4-1`-class alias must be registered before Stage 1 dev work — see §7 |
| `stage-1-turn-contract.md:104-112` (PR #1) | add per-model `supports-json-schema` **and** `supports-reasoning-effort`; acceptance "`gpt-5-4` receives both" | **HALF DONE** | `gpt-5-4` **is** in the `json_schema` allowlist (`:399`) ⇒ drop that half. It is **not** in the `reasoning_effort` allowlist `['o1','o3-mini','gpt-5']` (`:392`) ⇒ that half still needed |
| `stage-1-turn-contract.md:76` | call params include `max_tokens` from `counselor-max-output-tokens` | **INEFFECTIVE** | `max_tokens` is unset for non-reasoning models (`:423`) and overwritten by `computeDynamicMaxTokens()` (`:1171-1172`). Either accept provider-computed limits or add it to PR #1 |
| `07:29` | "`setIfNotBlank()`" as SecureChatAI behavior | **MISATTRIBUTED** | it is Cappy's helper; SecureChatAI drops nothing for blankness (§1.2) |
| `07:28` | SecureChatAI "always normalizes and sanitizes" | **TRUE but incomplete** | it also converts **errors** into indistinguishable success-shaped apologies (§1.3), which `07` does not account for |
| `07:32-33` | agent mode / tools "not being adopted" | **CONFIRMED, now with reasons** | §3 |
| `01-architecture.md`, `stage-1` §1.2 | `TurnService` parses the counselor JSON out of `content` | **REFINE** | for schema'd output the provider already decodes it: read `$response['structured_output']` (`:2139`, `:1657-1660`), or call `extractResponseText()` which returns it |

---

## 6. Revised SecureChatAI change requests (PR #1 scope)

### 6.1 NEW — preserve a machine-readable failure signal

The blocker for Stage 1's `FailurePolicy`. `01-architecture.md §2` and
`stage-1-turn-contract.md:68-71` promise "no model text released on failure" and
a static `technical-fallback-text`. That is **not implementable** against the
current provider: by the time MICA sees a failure it is a friendly assistant
message with no error marker (§1.3). Today MICA persists those apologies into
the study transcript as genuine counselor turns.

Ask (small, backward compatible): keep `error` and `type` on the sanitized error
envelope at `:1633-1636` — additive keys only, no existing consumer reads them.

Interim MICA-side mitigation until that lands: treat
`model === null && usage === null` as failure on the non-agent path. Sound while
MICA never uses agent mode; document it as a temporary heuristic.

### 6.2 Still needed — per-model `supports-reasoning-effort`

Per §5. Registry sub-setting replacing the hardcoded
`['o1','o3-mini','gpt-5']` (`:392-394`), so `gpt-5-4` can receive
`reasoning_effort`. Fix the `o3`/`o4-mini` ordering bug (`:393` strips before
`:411` reads) in the same PR.

### 6.3 Dropped from PR #1

`supports-json-schema` — already satisfied for `gpt-5-4` (`:399`).

### 6.4 Worth reporting upstream, not blocking

- **Undeclared dependency:** `SecureChatAI.php:29` imports `Google\Exception`,
  but the module's `composer.json` requires only `yethee/tiktoken` and its
  `vendor/` contains no `google` package. It works **only** because REDCap core
  bundles `google/apiclient`
  (`redcap_v17.2.3/Libraries/vendor/google/apiclient/src/Exception.php`, which
  does extend `\Exception`). If that ever moves, every `throw` inside
  SecureChatAI becomes an uncaught `Error`.
- **Alias punctuation traps:** capability tables are keyed by exact string, so
  the configured `claude-haiku-4-5` misses `claude-haiku-4.5` (`:1881`) and
  falls back to a wrong context window — **silently**. Same shape for prod's
  `claude-sonnet-4.6` vs `:1888`.
- **`:1163` required-parameter check is a no-op** — `getSubSettings()` yields a
  scalar, so `foreach ($modelConfig['required'] as …)` iterates nothing.
- **`computeDynamicMaxTokens()` estimates from `messages[0]` only** —
  `$fullPrompt` is undefined at `:1171`.
- **Plaintext credential in the repo:** `SCA/model_settings_prod.txt` contains a
  production API token. Rotate and move it out of version control.

---

## 7. Compatibility verdict — MICA as-is against current SecureChatAI

The SOW question "is it still compatible?" answered item by item. Verdicts are
against provider code at `c847be0`; MICA refs are `MICA.php` @ `ab81cca`.

| # | Item | Verdict | Runtime symptom |
|---|---|---|---|
| 1 | `getModuleInstance('secure_chat_ai')` + `\Stanford\SecureChatAI\SecureChatAI` type hint (`MICA.php:986-994`) | **OK** | class/namespace unchanged. Resolves despite system `enabled = false` (`ExternalModules.php:3905-3922`, `:3980-3987`) |
| 2 | 3-arg `callAI($model, $params, PROJECT_ID)` (`MICA.php:407`) | **DEGRADED** | signature still accepts it (`:431`). Turn rows get `username: null` (`:2295`) ⇒ user-scoped rehydration returns empty (`:194`). No participant-facing symptom |
| 3 | No `session_id` in `$params` | **DEGRADED** (empirically confirmed) | `logConversationTurn()` returns early (`:2242`) ⇒ **no turn rows at all**. Verified 2026-08-19: two live `callAI()` calls without `session_id` produced a `SecureChatLog` row and a `SecureChatLogError` row but **zero** `CappyChatTurn` rows. Audit rows are unfindable by session (`:2373`); provider-side rehydration impossible; MICA's own `MICAQuery` transcript unaffected |
| 4 | `formatResponse()` fallback branch reading `choices[0]` (`MICA.php:122-126`) | **OK (dead)** | `sanitizeOutputForUI` always sets `content` for chat models, so the success branch always wins. The fallback is unreachable for MICA's model list — and would emit a JSON blob as `content` if it ever fired, since `extractResponseText()` no longer parses `choices[0]` (`:2424-2431`) |
| 5 | Params sent even when the project setting is blank (`MICA.php:190` guards `!== null`) | **DEGRADED** | `''` forwarded as a string. `gpt-max-tokens` (800) is discarded for most models (`:423`, `:1171`); `reasoning-effort` (`medium`) is stripped for every model MICA can select (`:392`). Both settings are inert, not harmful |
| 6 | `llm-model = gpt-4o` vs the registry | **BROKEN → fixed in dev 2026-08-19** | `gpt-4o` was in neither this registry (only `claude-haiku-4-5`) nor `model_settings_prod.txt`; *no* dropdown option was valid. `callLLMOnce` throws `Unsupported model` (`:1157`) → 3 retries → `NETWORK_ERROR` → apology. **Every turn failed, silently.** Reproduced and verified by live call — [`14-live-defects.md`](14-live-defects.md) D1. Production value still unconfirmed |
| 7 | Could the new pre-hooks intercept a plain `callAI()` turn? | **OK — no** | hooks fire only inside `ToolPipeline`, reached only from `runAgentLoop` (`:1041`), reached only when the caller sets `agent_mode` (`:446-451`). MICA never does. System-wide `enable_agent_mode = true` is irrelevant |
| 8 | Any other provider change a 3-arg, no-session-id, no-tools caller hits | **DEGRADED** | (a) `id` is never returned ⇒ MICA stores `id: null` on every transcript row (`MICA.php:129`); (b) errors arrive shape-identical to answers and are **persisted as counselor turns** (§1.3, §6.1); (c) `Sanitizer` HTML-escapes before storage, so apostrophes persist as `&#039;` in transcripts (`classes/Sanitizer.php:16`) |

**Must change for correctness**

1. `llm-model` → a registered alias (D1). Without this nothing else matters.
2. Detect provider failures instead of persisting them as counselor turns
   (§6.1 interim heuristic now; provider flag later).
3. Server-side participant identity — the payload-supplied `user_id` is an
   auth defect, not a shape nit ([`14`](14-live-defects.md) D2).

**Leaves value on the table (no participant-visible symptom)**

4. `session_id` + `$username` to `callAI()` ⇒ session-grouped provider audit log
   and working rehydration.
5. Drop or fix the two inert model params (`gpt-max-tokens`,
   `reasoning-effort`) so settings don't imply control they don't have.
6. Remove the dead `formatResponse()` fallback branch.

## 8. Dev-environment prerequisites (blocks Stage 1 work, not planning)

| Item | Current | Needed |
|---|---|---|
| SecureChatAI model registry | one alias, `claude-haiku-4-5` (Bedrock) | a schema-capable alias (`gpt-4-1` or `gpt-5-4`) added to `api-settings` — required for **any** `json_schema` turn and for agent mode |
| MICA `llm-model` (PID 257) | ~~`gpt-4o` — **unregistered**~~ → **fixed 2026-08-19**: `claude-haiku-4-5`, plus corrected dropdown choices and an alias guard ([`14`](14-live-defects.md) D1) | production value still unconfirmed. Note the fix unblocks the *pilot* path only — `claude-*` is not json_schema-capable, so Stage 1 still needs a `gpt-4-1`/`gpt-5-4` alias registered |
| SecureChatAI enablement | system `enabled = false`; no project-level row | not blocking: `getModuleInstance()` resolves from the `version` row alone (`ExternalModules.php:3980-3987`, `shouldExcludeModule()` `:3905-3922`), so instantiation works. Enable it anyway for clarity |
