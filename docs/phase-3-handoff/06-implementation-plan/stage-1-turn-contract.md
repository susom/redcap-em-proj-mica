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

- Per-model registry sub-settings `supports-json-schema`,
  `supports-reasoning-effort` (checkboxes); hardcoded `$schemaModels` /
  reasoning lists (`['o1','o3-mini','gpt-5']`) become fallbacks.
- Acceptance: `gpt-5-4` receives both `json_schema` and `reasoning_effort`;
  existing consumers unaffected (their models' behavior identical with flags
  unset). Reviewed by module owners; MICA `README` records the minimum
  SecureChatAI version.

### 1.6 SecureChatAI conformance — legacy path (SOW cleanup item)

Per `../07-chatbot-cleanup-securechatai.md`; the v2 path gets this via
`TurnService` by construction. Legacy (flag-off) path changes:

- SPA sends `{messages, session_id}` (Cappy shape; `sessionId` already exists
  in `contexts/Chat.jsx`); backend accepts both shapes for compatibility.
- Pass `session_id` (params) + participant id (`$username` arg) to
  `SecureChatAI::callAI()` → central turn log gains session grouping;
  `MICAQuery` transcript kept unchanged (replaced in Stage 3).
- Strip nonstandard `user_id` keys from messages before `callAI()`.
- Remove `formatResponse()` raw pass-through branch (responses are always
  normalized + sanitized by SecureChatAI).
- `setIfNotBlank()` semantics for model params; refresh `llm-model` dropdown
  to current registry aliases.
- System prompt handling **unchanged** on this path (client round-trip stays
  until TurnService; decision 2026-08-13).

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
