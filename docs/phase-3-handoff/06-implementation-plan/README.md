# Phase 3 — Detailed implementation plan

Work breakdown for the stages defined in [`../03-implementation-stages.md`](../03-implementation-stages.md),
against the revised storage design in [`../02-data-model.md`](../02-data-model.md)
(REDCap fields / EM log / Entity tables — revised 2026-08-11). Each stage has
its own file with tasks, concrete file paths, code contracts, tests, and an
acceptance checklist.

## Stage tracker

| Stage | File | Depends on | Status |
|---|---|---|---|
| 0 — Foundations | [`stage-0-foundations.md`](stage-0-foundations.md) | — | **in progress** — 0.1–0.5 + 0.5b done (CI declined by decision — see the 0.5 record); remaining: cleanup (0.6) |
| 1 — Counselor v2 turn contract | [`stage-1-turn-contract.md`](stage-1-turn-contract.md) | 0 | **in progress** — parts of 1.6 done (see below); 1.1–1.5 not started |
| 2 — R01 session engine + frontend | [`stage-2-session-engine.md`](stage-2-session-engine.md) | 1 | not started — auth half (2.3) partly done, see `10-auth-implementation-pid257.md` |
| 3 — Transcript finalization + scan queue | [`stage-3-transcripts-and-queue.md`](stage-3-transcripts-and-queue.md) | 2 | not started |
| 4 — SafetyScan runner | [`stage-4-safetyscan-runner.md`](stage-4-safetyscan-runner.md) | 3 | not started |
| 5 — RA dashboard | [`stage-5-ra-dashboard.md`](stage-5-ra-dashboard.md) | 4 | not started |
| 6 — Notifications, digests, launch gates | [`stage-6-notifications-launch-gates.md`](stage-6-notifications-launch-gates.md) | 5 | not started |

Update the Status column (`not started / in progress / blocked / done`) as
work proceeds; note blockers inline with the open-question number.

### State as of 2026-08-19

Work landed on `mica-phase-3` so far came in through the live-defect pass
([`../14-live-defects.md`](../14-live-defects.md)) and the auth implementation, not
through the stage sequence, so the mapping needs stating explicitly:

**Done, and it belongs to a stage:**

- **0.5b** — D1 fixed (`e407183`): `llm-model` choices replaced with real registry
  aliases + `assertModelIsRegistered()` before every `callAI()`. PID 257 currently
  holds `claude-opus-4-7`, which matches the dev registry. Production value still
  unverified (two read-only checks in `14 §D1`).
- **Part of 1.6** — server-derived participant identity (`440ba41`, closes D2 and the
  1.6 identity item), `filterLogic` no longer interpolates user input (D3),
  restore rebuilds model context (D8, `b2c3c80`), the empty-context send hang (D7/D22,
  `c98b161`), fail-closed `saveData` checks (D11, D16).
- **Part of 2.3 (auth)** — native Survey Login scoped to the two MICA host surveys,
  applied and verified on PID 257 ([`../10-auth-implementation-pid257.md`](../10-auth-implementation-pid257.md)).
  The module-side deletions (`loginUser`/`verifyEmail`/`generateOneTimePassword`/
  `pages/chatbot.php`) are still pending and still in the Stage 2 scope.

**Done, and it belongs to no stage** (arrived as a study-operations request):
automatic arm placement — [`../15-arm-materialization.md`](../15-arm-materialization.md).

**Still open from 0.6 / 1.6, measured against the tree on 2026-08-19:**
`config.json` still carries the three `twilio-*` system settings,
`chatbot_system_context_session_2..7`, `session_length_days`,
`number_session_callback`, and the `gpt-*` sampling params;
`composer.json` still requires `php-ai/php-ml` + `twilio/sdk` and nothing else;
`no-auth-ajax-actions` still lists `login` and `verifyEmail`; there is no
`handoff/`, no `tests/`, and no `pilot-final` tag.

**Corrections to the stage files, from measurement:**

1. **Stage 0.1 is undone and is the highest-value cheap step.** `git tag` returns
   empty. `main` is at `5e56073` (2026-07-14); `mica-phase-3` is 13 commits ahead. The
   pilot is pinned to nothing, so every commit above is an unpinned potential pilot
   regression. Tag first.
2. **Stage 0.4's "`vendor/` ships with the module" contradicts the tree.**
   `.gitignore` has `*vendor` and `git ls-files vendor` returns 0 files, while
   `MICA.php`'s vendor require is deliberately conditional (that conditional is what
   fixed the enable failure). The moment `SchemaValidator` hard-depends on
   `opis/json-schema`, a missing `vendor/` stops being harmless and becomes a fatal
   inside the turn path. Decide before `composer require`: commit `vendor/`, or keep it
   ignored and add a documented build step plus a loud startup check. Not a detail.
3. **Stage 1's dev prerequisite is unmet and fails *silently*.** The dev SecureChatAI
   registry holds exactly one alias, `claude-opus-4-7` (`api-settings` verified
   2026-08-19). `SecureChatAI.php:399`'s `$schemaModels` allowlist is OpenAI-only, so
   `json_schema` is `unset()` with no error — a counselor-v2 turn would come back as
   free text and every response-schema gate would fail for the wrong reason. A
   `gpt-4-1`/`gpt-5-4` `api-settings` row is needed. This gates exactly one acceptance
   item (Stage 1's live smoke); everything else in Stages 0–1 tests green against a
   stub through the existing `setSecureChatInstance()` seam, so do **not** sequence
   Stage 1 behind provisioning.
4. **D4 moves out of Stage 6's security pass into the near-term list.**
   `pages/sessionSelector.php:5-11` runs `completeSession` before
   `validatePermissions()`, with no CSRF token, an unsanitized `$_POST['participant_id']`,
   and an unescaped echo at `:57`. Same class as D2/D3, which are fixed; leaving it
   until Stage 6 leaves an unauthenticated caller able to close any participant's session.
5. **The apostrophe/e-mail question (D3) needs no study decision.** The stricter
   validation that rejects `o'brien@example.com` exists only on `mica-phase-3`, not on
   `main`, and Stage 2.3 deletes `loginUser`/`verifyEmail` outright in favour of native
   Survey Login. It can only ever affect code already scheduled for deletion — provided
   the pilot deployment tracks `main`/`pilot-final` and not this branch. Confirm that
   when tagging (correction 1) and the question closes.

## Working conventions

- **Branch:** all work on `mica-phase-3`; one PR per stage (Stage 5 may split
  into backend-endpoints and SPA PRs). SecureChatAI changes are separate PRs
  to that module's repo (tracked inside stages 1 and 4) and must land before
  the MICA stage that needs them is merged.
- **Every stage ends green**: `composer test` (PHPUnit + PHPCS PSR-12),
  `npm test` per SPA, and the Playwright suites that exist by that stage —
  desktop *and* mobile viewports (project standard: be picky about UI/mobile).
- **Commits:** conventional, descriptive, no AI co-author lines.
- **Docs:** every stage updates `README.md`/`CHANGELOG.md` of the module and,
  where behavior diverges from these plan docs, the plan doc itself (the plan
  is the source of truth for reviewers).
- **Bug fixes found along the way:** reproduce E2E first (Playwright against
  local REDCap, as an end user), then fix, then keep the failing-first test.
- **Use the `redcap-external-module` skill for all EM work.** Every stage
  that touches `config.json`, hooks, AJAX actions, crons, settings, or module
  pages is done through the skill (invoke it at stage start):
  - **Workflow B (extend)** for adding hooks/settings/AJAX/crons/pages —
    declare in `config.json` first, implement from the skill's templates,
    apply its guardrails (parameterized SQL, `$this->escape()`, allowlisted
    actions with throwing `default`, `getUrl()` links, scoped `getData` /
    inspected `saveData` errors, `getDataTable()` never `redcap_data`).
  - **Workflow D (test)** for the PHPUnit/Playwright scaffolding and the
    reproduce-first bug-fix loop; copy `tests/` + `e2e/` layouts from the
    skill's templates (Stage 0) rather than hand-rolling.
  - **Workflow C (review)** for the Stage 6 security/compliance pass — walk
    `references/security.md` and `references/compliance.md` checklists and
    the Module Security Scanning step from the skill.
  - Consult its `references/` (hooks signatures, config.json schema,
    framework API, EM data model) before implementing against memory.

## Cross-cutting technical decisions (apply to all stages)

1. **Thin hooks, testable core.** All new logic lives in framework-free
   classes under `classes/`; `MICA.php` hook/AJAX methods only unwrap
   payloads, construct services, and format responses. Services take their
   dependencies (module facade, clock, SecureChatAI instance) via
   constructor so PHPUnit runs without a REDCap bootstrap. The existing
   `setSecureChatInstance()` seam (`MICA.php:932`) is the model.
2. **No new module-owned DDL.** Tables come from the REDCap Entity EM
   (`redcap_entity_types()` + `EntityDB::buildSchema()`); a single
   `EntitySchemaManager` class owns the dependency check, build call, and
   idempotent index migration, guarded by the `schema-version` system
   setting (see `02-data-model.md §1`).
3. **Fail closed.** Artifact hash mismatch, wrapper-validation failure,
   `saveData` errors, oversized transcript payloads — all raise typed
   exceptions that surface as the approved technical-fallback message
   (participant path) or `manual_review_required` (scan path). Never a
   silent trim, never released model text on failure.
4. **All identifiers per `02-data-model.md §2`** (`L<log_id>`, `T<log_id>`,
   UUID `finding_id`, salted `session_pseudo_id`, `app_version`).
5. **Legacy pilot code is deleted, not flagged off**, once Stage 2 lands —
   the pilot study runs from the `pilot-final` tag, not from this branch.

## Blocking external inputs (from `../05-open-questions-and-risks.md`)

| Needed by | Item | Blocks |
|---|---|---|
| Stage 2 | #8 PID 257 field sign-off (session instruments, auth fields, `mica_safety_finding` instrument) | building the real dictionary; engine work proceeds against a dev copy |
| Stage 2 | #5 phase-transition matrix sign-off | shipping the default matrix (dev default proceeds) |
| Stage 2 | #7 ED-tablet launch flow | login UX for baseline (OTP flow proceeds meanwhile) |
| Stage 4 | SecureChatAI PR #2 review (Irvin/Jordan) | live Gemini structured output |
| Stage 6 | #4 action templates, #1 ack minutes | launch-gate content, not code structure |

None of these blocks *starting* the stage they gate; each stage file states
what proceeds with dev defaults and what waits.
