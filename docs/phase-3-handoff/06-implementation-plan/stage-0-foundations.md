# Stage 0 — Foundations

**Goal:** freeze the pilot, vendor the handoff artifacts with hash
enforcement, stand up schema validation and the test/lint toolchain. No
behavior change for the running pilot.

## Tasks

### 0.1 Tag and protect the pilot

- `git tag pilot-final` on current `main`; push tag. Pilot deployments pin to
  this tag from now on.
- Confirm `mica-phase-3` is branched from the same commit; note the tag SHA
  in `CHANGELOG.md`.

### 0.2 Vendor handoff artifacts

- New directory `handoff/` (module root), copied **verbatim** from the
  handoff package:
  - `MICA_Prompt_R01_v2_postsession_safety.txt`
  - `MICA_counselor_output_schema_v2.json`, `MICA_wrapper_schema_v2.json`
  - `MICA_SafetyScan_PostSession_prompt.txt` (v1.1)
  - `MICA_safetyscan_postsession_input_schema.json`,
    `MICA_safetyscan_postsession_model_output_schema.json`
  - `MICA_safetyscan_review_workflow_schema.json`,
    `MICA_safetyscan_notification_policy_schema.json`
  - `MICA_safetyscan_notification_policy.default.json`
  - `manifest.json` — artifact filename → SHA-256 map from the handoff
- Verify each file's SHA-256 against the handoff manifest **at vendoring
  time** and record the check in the PR description.

### 0.3 `ArtifactRegistry` (`classes/ArtifactRegistry.php`)

Contract:

```php
final class ArtifactRegistry {
    public function __construct(string $handoffDir);          // defaults to __DIR__.'/../handoff'
    public function getText(string $name): string;            // prompt files
    public function getJson(string $name): array;             // schema files, decoded
    public function getHash(string $name): string;            // sha256 actually computed at load
    // throws ArtifactIntegrityException if computed hash != manifest.json entry
}
```

- Loads each artifact at most once per request (in-memory cache).
- **Fail-closed:** any mismatch (or missing file/manifest entry) throws
  `ArtifactIntegrityException`; callers translate to technical fallback
  (participant path) or config-error log. Never proceed on mismatch.
- `getHash()` is the value persisted to `mica_turn` / `mica_scan_run` rows —
  always the hash of what was actually used.

### 0.4 Schema validation (`classes/SchemaValidator.php`)

- `composer require opis/json-schema` (draft 2020-12 — required by the
  handoff schemas). Run `composer install` locally (no `vendor/` in the
  checkout today); `vendor/` ships with the module per deployment convention.
- Contract:

```php
final class SchemaValidator {
    public function __construct(ArtifactRegistry $artifacts);
    public function validate(mixed $data, string $schemaArtifact): ValidationResult;
    // ValidationResult: ->isValid(): bool, ->errors(): string[] (pointer + keyword)
}
```

- No exceptions for invalid *data* (that's a normal outcome the turn/scan
  pipelines branch on); exceptions only for unloadable schemas.

### 0.5 Test & lint toolchain

- `composer.json` dev deps: `phpunit/phpunit`, `squizlabs/php_codesniffer`;
  scripts: `"test": ["phpcs --standard=PSR12 classes/ MICA.php", "phpunit"]`.
- `tests/` layout: `phpunit.xml`, `bootstrap.php` (framework-free — the new
  classes must not require a REDCap bootstrap), `fixtures/` (schema
  valid/invalid payloads, model-output fixtures used by later stages).
  Copy the layouts from the `redcap-external-module` skill's
  `assets/templates/tests/` + `e2e/` (Workflow D) instead of hand-rolling;
  same for the CI workflow (`assets/templates/ci/`).
- CI workflow (`.github/workflows/ci.yml`): PHP 8.3, `composer install`,
  `composer test`. (Playwright job added in Stage 2 when the first E2E
  exists.)
- Keep `emLoggerTrait` logging in all new classes reachable from the module;
  pure classes receive a logger callable instead.

### 0.5b Fix the live outage first (`../14-live-defects.md` D1)

**Blocks 0.6 and every E2E task in this plan.** PID 257's `llm-model` is
`gpt-4o`, which is registered in neither the dev nor the prod SecureChatAI
registry, so every turn currently returns the provider's canned
"network difficulties" apology *and persists it as a counselor turn*. Set a
registered alias, confirm the production project's value too, then record the
baseline. A baseline recorded before this fix captures the apology loop, not the
product.

### 0.6 Chatbot cleanup (SOW: "Chatbot Cleanup & SecureChatAI Integration")

Per `../07-chatbot-cleanup-securechatai.md` (decisions 2026-08-13, **revised
2026-08-18**) — all behavior-preserving, verified by a Playwright baseline
recorded first. The full grep-verified inventory lives in that doc; summary:

- `config.json`: drop Twilio system settings; drop dead `login`/`verifyEmail`
  `no-auth-ajax-actions` entries; resolve the two `required: true`-but-inert
  settings (`chatbot_intro_text`, `chatbot_end_session_text` — nothing ever
  assigns the globals the SPA reads); drop
  `enable-every-page-hooks-on-system-pages` and the empty
  `links.control-center`.
- `MICA.php`: delete commented `sendSMS()`; delete write-only properties and
  zero-caller getters; metadata-only `emDebug` (5 PHI sinks, not 1);
  escape/parameterize `filterLogic` in `loginUser`/`verifyEmail` (`:525` in
  `fetchSavedQueries` is already mitigated by the sanitizer); remove the dead
  `renderMicaApp` mount contract (`../14-live-defects.md` D9 — behavior-*restoring*,
  so it needs its own before/after E2E); correctness nits D10-D16, D21.
- `classes/`: zero-caller `MICAQuery::getPayload()`/`getMICAQuery()`, commented
  `payloadCheck()`; decide whether `ASEMLO` is vendored (if so, leave its
  zero-caller methods and record that).
- `composer.json`: drop unused `php-ai/php-ml` + `twilio/sdk` while adding
  `opis/json-schema` (0.4).
- `mica-chatbot/src`: remove the six confirmed-unreferenced Cappy-inherited
  assets (`mica_logo.png` **is** in use), dead `App.jsx`, and the unreachable
  `login`/`verifyEmail` bridges; **clear `dist/assets` before rebuilding** —
  `generateAssetFiles()` emits a tag for every file it finds, so a stale hashed
  bundle would be loaded alongside the new one.

Security items D2/D3/D4 from `../14-live-defects.md` are *not* behavior-preserving
and are sequenced as their own pass; D2's fix is the same edit as the Stage 1
§1.6 payload/identity change, so they land together there.

## Tests (all new, PHPUnit)

| Test | Asserts |
|---|---|
| `ArtifactRegistryTest::hashesMatchManifest` | every vendored artifact loads; computed hash equals manifest |
| `ArtifactRegistryTest::tamperFailsClosed` | fixture dir with 1-byte-modified prompt ⇒ `ArtifactIntegrityException`; nothing returned |
| `ArtifactRegistryTest::missingEntryFailsClosed` | file present but absent from manifest ⇒ exception |
| `SchemaValidatorTest` (data provider) | each vendored schema × ≥1 valid + ≥2 invalid fixtures |
| `SchemaValidatorTest::rejectsSafetyFlagResurgence` | counselor response containing `safety_flag`/`escalation` ⇒ invalid (`additionalProperties:false`) |
| `SchemaValidatorTest::draft2020Features` | `const`, `enum`, `additionalProperties` behave per 2020-12 |

## Acceptance checklist

- [ ] `pilot-final` tag pushed; SHA recorded
- [ ] `handoff/` complete; vendoring-time hash check documented in PR
- [ ] Tamper test fails closed
- [ ] All vendored schemas load and validate fixtures (draft 2020-12)
- [ ] `composer test` green in CI; PSR-12 clean on new files
- [ ] `../14-live-defects.md` D1 fixed (dev **and** prod `llm-model` values
      confirmed against `getAvailableModels()`) — a real model reply observed
      before the baseline is recorded
- [ ] Cleanup (0.6) landed; Playwright baseline passes before and after
