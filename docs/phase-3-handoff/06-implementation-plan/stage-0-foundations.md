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
  `assets/templates/tests/` + `e2e/` (Workflow D) instead of hand-rolling.
- ~~CI workflow (`.github/workflows/ci.yml`): PHP 8.3, `composer install`,
  `composer test`. (Playwright job added in Stage 2 when the first E2E
  exists.)~~ **Declined 2026-08-19** — the module is baked into the REDCap
  docker image, which changes the calculus. See the decision record below.
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

- [x] `pilot-final` tag pushed; SHA recorded (2026-08-19 — `CHANGELOG.md`)
- [x] `handoff/` complete; vendoring-time hash check documented (record below)
- [x] Tamper test fails closed — and mutation-checked (record below)
- [x] All vendored schemas load and validate fixtures (draft 2020-12)
- [x] `composer test` green; PSR-12 clean on new files (2026-08-19 — `phpcs.xml`;
      record below). **CI deliberately declined** — the module ships inside the
      REDCap docker image; reasoning and the conditions for reopening it are in
      the 0.5 record below
- [x] `../14-live-defects.md` D1 fixed on dev (`e407183`); PID 257 holds
      `claude-opus-4-7`, which matches the dev registry — **prod value still
      unverified**, two read-only checks in `../14-live-defects.md` §D1
- [ ] Cleanup (0.6) landed; Playwright baseline passes before and after

## Implementation record — 0.1 / 0.2 / 0.3 (2026-08-19)

### 0.1 — pilot pin

No tags existed in the repo, local or remote, so the pilot had been pinned to
nothing while `mica-phase-3` ran 13 commits ahead. `main` was identical to
`origin/main` and is a clean ancestor of `mica-phase-3`. Annotated tag
`pilot-final` → `5e56073`, pushed.

### 0.2 — vendoring

The package's hashed files were verified **before** copying and the copies
re-verified after: 10/10 matched, so the source package is intact. Nine of the
ten are vendored into `handoff/`.

**Not vendored, deliberately:** `MICA_Final_Clinical_Review_Package.xlsx` (a
human review artifact for the clinical team, not loaded by any code path) and the
six unhashed reference documents (counselor handoff, dashboard spec,
configuration memo, model-evaluation summary, lifecycle decision, candidate
freeze record). Those inform these plan docs; putting unhashed prose behind an
integrity gate would only invite the gate to be relaxed. They stay in the source
package.

**Deviation from 0.2:** the manifest is keyed by *logical name*
(`counselor_prompt`, `wrapper_schema`, …) with `{file, type, sha256}` per entry,
not "filename → SHA-256". Code refers to a role rather than to
`MICA_Prompt_R01_v2_postsession_safety.txt` in a dozen places, so a future v3
prompt is a manifest change plus a re-verification instead of a sweep through the
codebase — and `type` lets `getJson()` reject a text artifact as a caller bug
rather than a mystery decode failure. The manifest also carries a `source` block
naming the package and its `created_date`, so provenance travels with the pin.

### 0.3 — `ArtifactRegistry`

Contract as specified, plus:

- **`verifyAll()`**, which additionally refuses any *unpinned* file in
  `handoff/`. A per-artifact read can only verify what it was asked for, so on
  its own it would never notice a tenth file appearing in the directory. This is
  the half of "missing manifest entry" that `getText()` structurally cannot
  cover, and it is also the check a launch-readiness gate wants.
- The manifest is validated as the root of trust: a `file` value must be a plain
  basename (a pin that can reach outside `handoff/` describes a file the deployer
  never reviewed), `sha256` must be 64 hex, `type` must be `text` or `json`.
- **Caching semantics, worth being explicit about:** an artifact verified once is
  served from memory for the rest of the request, so corrupting the file
  mid-request does *not* invalidate the cached copy. That is intentional — the
  bytes were vouched for when they were read, and re-hashing a 6 KB prompt on
  every counselor turn buys nothing. A fresh registry (i.e. the next request)
  catches it. Both halves are asserted.
- `getJson()` on an artifact pinned as text throws `\InvalidArgumentException`,
  not `ArtifactIntegrityException`: the artifact is fine, the caller is wrong,
  and a caller bug must not be catchable as an integrity failure.
- **Dotfiles are excluded from the unpinned-file check.** Every pin is a plain
  basename and none begins with a dot, so a dotfile can never be mistaken for an
  artifact — whereas a `.DS_Store` from a developer opening `handoff/` in Finder
  would otherwise be enough to fail the Stage 6 launch-readiness gate and refuse
  to start a session. The check exists to catch a *plausible* extra: a stray
  `..._v3.txt` someone later wires up, or a botched re-vendoring.

**29 tests, green on PHP 8.4 (host) and 8.3 (the container REDCap runs on),
via `composer test`.** The suite was mutation-checked: replacing the
`hash_equals` comparison with `if (false)` fails 3 tests. That check found a weak
assertion — the empty-file test had been passing on an incidental JSON-decode
error rather than on the pin — which is now asserted on the pin message.

`MICA.php` gained one line (an explicit `require_once` for the registry, so the
integrity gate does not depend on the composer autoloader being present), so the
existing full-path E2E was re-run against it: **23/23, unchanged** — Survey Login
gate, chatbot load, three-turn conversation with context retained, single JS/CSS
bundle, desktop + iPhone 13. The one known gap is also unchanged: End Session
still reports that the project is not configured for MICA sessions, which is the
Stage 2 / [`09`](../09-pid-257-structure-audit.md) G1/G4 work, not a regression.

## Implementation record — 0.4 (2026-08-19)

### Dependency and toolchain decisions (with Ihab, 2026-08-19)

- **`vendor/` is committed**, built without dev dependencies. A REDCap EM deploys
  by directory copy, and `opis/json-schema` is a runtime dependency of the turn
  path, so a missing `vendor/` would be a fatal mid-turn rather than a
  deploy-time error. `MICA.php`'s require is unconditional again, but with an
  explicit message — it was briefly conditional because a missing `vendor/` made
  the class file unloadable, which REDCap surfaces as an unrelated fatal on
  enable, and the next person to hit that should read the cause.
- **`config.platform.php = 8.2`** — Composer resolves on the host (8.4) while the
  module runs on 8.3 here and an unknown version in production, so resolution is
  pinned to a conservative floor rather than to whatever the developer's laptop
  has.
- **The test toolchain lives in `tools/`, not in `require-dev`.** This is what
  makes "ships `--no-dev`" structural instead of procedural: the module's
  `composer.json` has *no* dev dependencies, so `composer install` in the module
  root cannot produce a tree that differs from what deploys, and nobody can
  commit PHPUnit to production by forgetting a flag. `composer test:install`
  populates `tools/vendor/` (gitignored); `composer test` runs from there.
- **`php-ml` and `twilio/sdk` were dropped**, which the 0.6 cleanup already
  called for and committing `vendor/` made urgent: they are **2,598 files and
  17.5 MB** with zero references in the codebase — the only mentions are the
  commented-out `sendSMS()` and three `config.json` settings. `vendor/` went from
  4,336 files / 28 MB to **235 files / 1.3 MB**.

⚠️ **Left for 0.6, deliberately:** the three `twilio-*` system settings still
exist in `config.json`, and this instance has **values stored for all three** —
a SID, an auth token and a from-number. Deleting the declarations would orphan
them in `redcap_external_module_settings`, invisible in the UI but still in the
database. If those are real Twilio credentials they want rotating, not just
deleting; that is a decision, not a cleanup.

### `SchemaValidator` / `SchemaValidationResult`

Contract as specified. Two things it exists to get right, both found by testing
rather than by reading the library docs:

1. **PHP arrays are not JSON objects.** `Helper::getJsonType()` returns `null`
   for an associative array, so validating `['a' => 1]` against
   `{"type":"object"}` *fails* with a confusing error instead of passing. Every
   caller in this codebase hands over associative arrays — `REDCap::getData()`,
   `json_decode($x, true)`, SecureChatAI's `structured_output` — so this is the
   default path, not an edge case. Everything is converted through
   `Helper::convertAssocArrayToObject()` first.
2. **`stopAtFirstError` must stay `true`.** The name is misleading: it means "stop
   descending a subschema once it failed", not "report one error". With it
   `false`, opis's evaluated-property bookkeeping breaks and
   `additionalProperties` reports **declared, valid** properties as disallowed —
   a wrapper with one out-of-range number came back also claiming
   `schema_version`, `patient_message` and `session_context` were not allowed.
   Those strings are exactly what Stage 1's `FailurePolicy` feeds back to the
   model as a corrective nudge, so believing them would make it delete the fields
   the schema requires. `true` with `maxErrors > 1` still yields one accurate
   error per failing location, which is what was actually wanted.

Also worth recording:

- **Cross-file `$ref` resolves offline.** `MICA_wrapper_schema_v2.json` has
  `$defs.model_response.$ref` pointing at `https://mica.example.org/...`, a host
  that does not exist. Every pinned schema is registered with the resolver by its
  own `$id`, and the resolver has no protocol handlers, so an unsatisfiable
  `$ref` raises instead of attempting a request. (A revision to the keyword
  inventory taken earlier: the vendored schemas **do** use `$ref`, in `$defs`.)
- **`format: date-time` is asserted by opis**, though draft 2020-12 makes
  `format` annotation-only by default. SafetyScan timestamps rely on it, so a
  test pins it — an opis upgrade that stops asserting must fail loudly rather
  than let junk timestamps into the scan input.
- Invalid *data* never throws; only an unusable *schema* does. The two are
  handled very differently downstream: the first is a retry, the second is a
  configuration fault that must not be retried against the model.

### Tests

**60 tests / 158 assertions, green on PHP 8.4 and 8.3.** Includes the plan's
`rejectsSafetyFlagResurgence` widened to five would-be triage fields
(`safety_flag`, `escalation`, `risk_level`, `alert_care_team`, `staff_notified`),
each vendored schema against a valid fixture plus mutated invalid ones, and a
check that the shipped default notification policy still validates against its
own shipped schema and still encodes both launch blockers
(`critical_acknowledgment_minutes` null, pre-review notifications disabled).

Three mutations were used to confirm the suite bites: flipping
`stopAtFirstError`, removing the array→object conversion (25 failures), and
removing the `$ref` registration. **The third initially failed to fail** — the
`$ref` test was passing because nothing in the validation tree reaches `$defs`,
so it never exercised the registration it claimed to cover. It was rewritten
against a schema whose validation tree really does contain a cross-file `$ref`,
and now fails when the registration is removed.

## Implementation record — 0.5 lint + CI (2026-08-19)

### PSR-12 scope: exclusions, not inclusions

Open decision 1 (below) is resolved. `phpcs.xml` includes `classes/` and `tests/`
**wholesale** and names the three pilot-era files as `exclude-pattern`s
(`ASEMLO`, `MICAQuery`, `Sanitizer`). The polarity matters: an include-list would
let a new file escape the standard by being forgotten, whereas an exclusion list
lints new work by default and can only ever shrink — one entry deleted per legacy
file cleaned. `MICA.php` stays out until 0.6 touches it; scoping phpcs at it today
would be red from the first run and stay red.

Two deliberate rule adjustments, both documented inline in `phpcs.xml`:

- `Generic.Files.LineLength` at 120 as an **error**, not the inherited warning —
  a warning in a `--max-warnings`-less runner is advice nobody reads.
- `PSR1.Files.SideEffects.FoundWithSymbols` excluded. `ArtifactRegistry` and
  `SchemaValidator` pair `require_once` with a class declaration on purpose: a
  REDCap EM cannot assume the composer autoloader is present, and the integrity
  gate must load even when it is not. Everything else in PSR-1 stays on.

Six over-long lines were fixed rather than grandfathered. One of them improved the
test it was in: `ArtifactRegistryTest::malformedManifests` had nine hand-written
JSON manifests, so a "mutate one field" case could drift from the valid shape in
some *other* field without anyone noticing. It now builds each case from one valid
pin with a single override.

### `composer test` = lint + unit

`composer test` runs `phpcs` then `phpunit`; `composer lint` / `lint:fix` are
separately callable. **77 tests / 179 assertions and phpcs clean.**

### CI — built, then deliberately removed (decision 2026-08-19, with Ihab)

**There is no CI workflow, and that is a decision, not an omission. Do not
re-add one without revisiting the reasoning here.**

A `.github/workflows/ci.yml` was written (PHP 8.3 + 8.4 matrix, `mica-chatbot`
eslint + build, a disabled `e2e` placeholder) and then deleted, because the
deployment model makes it close to redundant: **the MICA EM is packaged into the
REDCap docker image and deployed with it.** What that changes:

- **A docker build answers "did the files copy?", never "does the code work?"**
  Nothing in the image build runs a test, so CI's only real role here would be
  gating a commit *before* the bake. That is worth something — but everything it
  would run, `composer test` runs locally in under a tenth of a second.
- **Half the matrix was pointless.** The image is `php:8.3-apache-bookworm`.
  Testing 8.4 was for the development host, not for any deployment target.
- **It never actually ran.** Nothing was pushed after it was added, and whether
  GitHub Actions is even enabled for `susom/redcap-em-proj-mica` was never
  confirmed. An unrun workflow is worse than none: a reader takes the file as
  evidence of coverage.

So the gate is `composer test`, run before committing, plus the E2E suite run by
hand. Two arguments were weighed and judged not to outweigh the above, but they
are the ones that would justify reopening this:

1. **Parallel workers on one branch.** Local discipline does not compose across
   people or sessions — the stale classmap below is exactly that failure, and it
   happened twice.
2. **The prompt is the intervention.** Hash-pin verification that runs
   unconditionally is a different kind of assurance from one that runs when
   someone remembers, and it produces a build record. If IRB or Stanford
   security ever asks how you know the deployed prompt is the validated one, that
   is the artifact they will want.

If either becomes pressing, the better home is the **image build pipeline**, not
GitHub Actions: a check there fails the *image* rather than a commit, and cannot
be bypassed by pushing to whatever branch the packager reads.

### What survived: `composer check:vendor`

The one thing in that workflow the plan had not asked for was worth keeping, so
it is now a composer script rather than a CI step: **prove the committed
`vendor/` is what `composer.json` resolves to.** Since `vendor/` is committed and
baked into the image verbatim, a stale tree passes every test and then ships.

Run it after **any** dependency change, and after adding a class (the optimized
classmap is part of the committed tree). It needs a clean working tree.

It found two real problems the first time it ran:

1. The committed `vendor/` had been built **with** dev dependencies
   (`installed.php` said `'dev' => true`), which is exactly what the `tools/`
   split exists to prevent.
2. Its autoload classmap was three classes behind — `SchemaValidator`,
   `SchemaValidationResult` and `UserRightsCheck` were absent. Not yet a
   production fault (the PSR-4 map is still registered as a fallback, and
   `MICA.php` requires the other new classes explicitly), but the committed
   autoloader was not describing the committed code.

Both fixed by regenerating with `--no-dev` and committing the result. The check
excludes `vendor/composer/installed.php` and `installed.json`: composer stamps the
*current git SHA* into them, so they differ on every commit regardless of
dependencies. Nothing here reads that value, and leaving them in would make the
check fail permanently — a check that always fails is a check that gets deleted.

`composer validate --strict` also needed a `license` field (`proprietary`) to pass.

## Open decisions this work surfaced

1. ~~**PSR-12 baseline**~~ — **resolved 2026-08-19**, see the 0.5 record above.
2. **Stored Twilio credentials** — see the warning above. Needs a decision before
   0.6 removes the settings. Still open: this instance has values stored for all
   three `twilio-*` system settings, and deleting the declarations would orphan
   them in `redcap_external_module_settings` (invisible in the UI, still in the
   database). If they are real credentials they want **rotating**, not deleting.
