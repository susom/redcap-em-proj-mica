# Stage 1 — Counselor v2 turn contract (behind feature flag)

**Goal:** the schema'd, gated, hash-logged turn pipeline of
`01-architecture.md §2`, switchable via `counselor-contract-v2`; legacy pilot
path untouched while the flag is off. Includes the REDCap Entity plumbing
(first entity: `mica_turn`).

**Depends on:** Stage 0. **External:** SecureChatAI PR #1 (below).

> Config/hook/setting changes in this stage follow the
> `redcap-external-module` skill, Workflow B (declare in `config.json` →
> implement from templates → guardrails), per the plan README conventions.

## Tasks

### 1.1 Entity plumbing (`classes/EntitySchemaManager.php` + hook)

- `MICA.php`: implement `redcap_entity_types()` returning (this stage)
  `mica_turn` per the property table in `02-data-model.md §1.1`. Stage 3 adds
  the other three types to the same hook.
- `EntitySchemaManager`:

```php
final class EntitySchemaManager {
    public function __construct(\Stanford\MICA\MICA $module);
    public function assertEntityModuleAvailable(): void;   // throws if redcap_entity absent/disabled
    public function ensureSchema(): void;                   // buildSchema() + index migration,
                                                            // no-op when schema-version matches
}
```

- Called from `redcap_module_system_enable()` (add hook) and defensively from
  the cron entry points. Bump `schema-version` (system setting) whenever
  types/indexes change; migration is idempotent
  (`SHOW INDEX` check before each `ALTER TABLE`).
- Index migration for this stage:
  `ALTER TABLE redcap_entity_mica_turn ADD KEY idx_record (project_id, record)`.
- `config.json`: declare `redcap_module_system_enable` if the framework
  version requires it; add `crons` later (Stage 3).

### 1.2 `TurnService` (`classes/TurnService.php`)

Pipeline per `01-architecture.md §2`, dependencies injected:

```php
final class TurnService {
    public function __construct(
        ArtifactRegistry $artifacts, SchemaValidator $validator,
        SessionStateProviderInterface $state,   // Stage-1 adapter; real impl in Stage 2
        SecureChatCaller $chat,                 // thin wrapper over SecureChatAI::callAI
        TurnLogger $turnLog,                    // writes mica_turn entity rows
        TurnConfig $config                      // aliases, token/effort settings, matrix, fallback text
    );
    public function handleTurn(string $projectId, string $record, string $patientMessage): TurnResult;
    // TurnResult: assistantText, endSession, status (ok|retried_ok|fallback_ok|failed_technical|...)
}
```

Sub-components (each its own small class, unit-tested in isolation):

- `WrapperBuilder` — assembles wrapper JSON from session state; validates
  against `MICA_wrapper_schema_v2` (failure = our-bug guard → technical
  fallback, logged as config error, never a trim).
- `TurnGates` — `oneQuestion()` (`substr_count($text,'?') <= 1`),
  `wordLimit()` (whitespace-tokenized; 140 when
  `response_strategy=='summary'`, else 90), `phaseTransition()` (matrix from
  `phase-transition-matrix` setting JSON; default per `02-data-model.md §4`).
- `FailurePolicy` — violation 1 ⇒ corrective retry same model (violation fed
  back as system nudge); violation 2 ⇒ single attempt on
  `counselor-fallback-alias`; still bad / timeout / refusal / 5xx ⇒ static
  `technical-fallback-text`. **No model text released on failure.**
- Message assembly: `system` pinned prompt, `system` `SESSION_CONTEXT:` block
  (wrapper minus patient_message/history), history as user/assistant turns,
  latest user message. Call params: `json_schema` = counselor output schema
  (strict), `max_tokens` from `counselor-max-output-tokens` (1200),
  `reasoning_effort` from setting (medium), **no temperature**.
- **Reading the response (verified 2026-08-18):** do not parse `content`. For a
  schema'd reply the provider already decodes it — `normalizeResponse()` sets
  `structured_output` (the decoded array) plus `preserve_structure`, and
  `sanitizeOutputForUI()` then returns the envelope untouched
  (`SecureChatAI.php:2139-2152`, `:1657-1660`). Read
  `$response['structured_output']`, or call the public `extractResponseText()`
  which returns exactly that. Two caveats: `reasoning_effort` is stripped for
  `gpt-5-4` and `max_tokens` is overwritten regardless of setting — both are §1.5
  PR items, so this stage must not assume either param took effect.

### 1.3 `TurnLogger` (`classes/TurnLogger.php`)

- Inserts one `mica_turn` entity row per attempt-resolved turn via
  `\REDCapEntity\EntityFactory` (insert-only; no update method exists on the
  class). Fields per `02-data-model.md §1.1`, incl. `message_log_ids`
  (`L<log_id>` of the two message rows), all three hashes from
  `ArtifactRegistry::getHash()`, `app_version` (module version + git
  short-sha constant baked by the build), latency/tokens from the
  SecureChatAI response, `gate_failures`, `phase_from/phase_to`,
  `end_session`.

### 1.4 Wire the flag in `MICA.php`

- `redcap_module_ajax` `callAI` case (`MICA.php:306` switch): when project
  setting `counselor-contract-v2` is checked, route to
  `TurnService::handleTurn()`; else legacy `handleUserInput()` untouched.
- Response wire shape unchanged (`content` = assistant text) plus
  `end_session` boolean — keeps Stage-2 frontend delta minimal.
- Accepted turns persist participant + assistant messages through the
  existing `MICAQuery`/`ASEMLO` EM-log store (transcript source of truth).
- `config.json` additions (all from `02-data-model.md §5`):
  `counselor-contract-v2` (checkbox), `counselor-model-alias`,
  `counselor-fallback-alias`, `counselor-max-output-tokens`,
  `counselor-reasoning-effort`, `technical-fallback-text`,
  `phase-transition-matrix`.

### 1.5 SecureChatAI PR #1 (separate repo, backward-compatible)

**Scope revised 2026-08-18** against verified provider code — see
`../13-securechatai-current-state-delta.md` §6.

- **NEW, and the one item that blocks §1.2's `FailurePolicy`:** preserve a
  machine-readable failure signal. `sanitizeOutputForUI()` currently strips
  `error`/`type` and returns a friendly assistant message
  (`SecureChatAI.php:1633-1636`), so a consumer **cannot tell a provider failure
  from a real answer**. Without this, "no model text released on failure" and
  the static `technical-fallback-text` promise in §1.2 are not implementable —
  MICA today persists those apologies as counselor turns. Ask: keep `error` and
  `type` on the error envelope (additive keys; no existing consumer reads them).
- **KEPT:** per-model `supports-reasoning-effort`; the hardcoded
  `['o1','o3-mini','gpt-5']` list (`:392-394`) becomes the fallback. `gpt-5-4` is
  **not** in that list today. Fix the `o3`/`o4-mini` ordering bug in the same PR
  (`:393` strips the key before `:411` reads it).
- **DROPPED:** `supports-json-schema` — already satisfied; `gpt-5-4` **is** in
  the schema allowlist (`:399`).
- **Consider:** `max_tokens` is unset for non-reasoning models (`:423`) and then
  overwritten by `computeDynamicMaxTokens()` (`:1171-1172`), so
  `counselor-max-output-tokens` (§1.2) has no effect. Either accept
  provider-computed limits and delete the setting, or add an honored override
  here.
- Acceptance: `gpt-5-4` receives `reasoning_effort`; a forced provider failure
  returns a distinguishable envelope; existing consumers unaffected (behavior
  identical with flags unset). Reviewed by module owners; MICA `README` records
  the minimum SecureChatAI version.

**Interim, if PR #1 lands after Stage 1:** treat
`model === null && usage === null` as failure on the non-agent path. Sound only
while MICA never sets `agent_mode` (agent turns share that shape) — document it
as temporary.

**Dev-environment prerequisite:** this instance's SecureChatAI registry holds one
alias, `claude-haiku-4-5`, which is not schema-capable. A `gpt-4-1`/`gpt-5-4`
alias must be added to `api-settings` before any `json_schema` turn can be
tested at all.

### 1.6 SecureChatAI conformance — legacy path (SOW cleanup item)

Per `../07-chatbot-cleanup-securechatai.md` (revised 2026-08-18); the v2 path
gets this via `TurnService` by construction. Legacy (flag-off) path changes:

- **Payload shape and participant identity are one change** (07 decision 7). SPA
  sends `{messages, session_id}` (Cappy shape; `sessionId` already exists in
  `contexts/Chat.jsx:10` but is never sent); backend accepts both shapes for
  compatibility. **The participant id is derived server-side** from `$record` /
  `$survey_hash`; any payload-supplied `user_id` is ignored. This is a security
  fix, not a shape nit — today `MICA.php:393` reads the id off the raw payload on
  a no-auth action, which lets a caller read another participant's baseline data
  through the prompt (`../14-live-defects.md` D2).
- Strip every non-`role`/`content` key from messages before `callAI()` —
  rebuild rather than filter (Cappy `REDCapChatBot.php:184-195`).
- Pass `session_id` (params) + participant id (`$username` arg) to
  `SecureChatAI::callAI()`. Note this makes the provider's turn log *start
  existing*: with no `session_id`, `logConversationTurn()` returns early
  (`SecureChatAI.php:2242`) and writes nothing today. `MICAQuery` transcript kept
  unchanged (replaced in Stage 3); `session_id` has **no consumer inside MICA**
  until then, and adopting it as MICA's own session key needs a trust decision
  (client `Date.now()` ids are guessable and collide across tabs).
- Remove `formatResponse()`'s raw `choices[0]` branch — unreachable today, and
  broken if reached (`extractResponseText()` no longer parses `choices[0]`).
  Read `structured_output` for schema'd output. Expect `id` to always be `null`
  — the provider never sets it.
- Add failure detection (§1.5 interim heuristic or the PR flag) so provider
  errors stop being stored as counselor turns.
- Blank-means-absent for model params — implemented **locally**;
  `setIfNotBlank()` is Cappy's helper, not a SecureChatAI feature. Drop or
  relabel `gpt-max-tokens` and `reasoning-effort`: the provider discards both for
  every model MICA can currently select.
- Refresh the `llm-model` dropdown to current registry aliases **and** validate
  the configured value against `getAvailableModels()` — the unvalidated setting
  is what caused `../14-live-defects.md` D1.
- System prompt handling **unchanged** on this path (client round-trip stays
  until TurnService; decision 2026-08-13) — but fix the `.pop()` context loss
  (D8) and the empty-context send-button hang (D7) while it still exists.

## Tests

- **Unit** (fixture model outputs; no REDCap): every gate boundary
  (0/1/2 questions; 89/90/91 and 139/140/141 words; every legal + illegal
  matrix pair; unicode/whitespace tokenization), failure ladder
  (malformed→retry→ok, retry→fallback→ok, all-fail→static fallback,
  timeout/refusal), wrapper-validation failure path, **no model text on any
  failure branch** (assert on returned TurnResult).
- **Integration** (local REDCap, `SecureChatAIStub` via
  `setSecureChatInstance()`): flag off ⇒ legacy path byte-identical; flag on
  ⇒ turn persists messages + `mica_turn` row with hashes matching the
  vendored manifest; entity table + index created idempotently on enable
  (enable twice, no error).
- **Live smoke** (manual): one real `gpt-5-4` turn returns schema-valid JSON
  passing all gates.

## Acceptance checklist

- [ ] Flag off: pilot behavior unchanged (regression: existing chat E2E)
- [ ] Flag on: end-to-end schema-valid turn against `gpt-5-4`
- [ ] `mica_turn` rows carry correct hashes/params/status; no message text
- [ ] All gate/failure unit branches covered; suite green
- [ ] SecureChatAI PR #1 merged + version pin recorded
- [ ] Legacy path passes `session_id`/username; MICA turns visible in
      SecureChatAI project logs grouped by session (1.6)
- [ ] Participant identity is server-derived; a forged `user_id` in the payload
      changes nothing (1.6 / `../14-live-defects.md` D2)
- [ ] A forced provider failure (e.g. an unregistered alias) yields the static
      technical fallback and is **not** written to the transcript as a counselor
      turn (1.5 / `../14-live-defects.md` D1)
