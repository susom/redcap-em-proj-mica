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
