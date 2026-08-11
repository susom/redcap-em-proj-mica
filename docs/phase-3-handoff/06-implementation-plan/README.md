# Phase 3 — Detailed implementation plan

Work breakdown for the stages defined in [`../03-implementation-stages.md`](../03-implementation-stages.md),
against the revised storage design in [`../02-data-model.md`](../02-data-model.md)
(REDCap fields / EM log / Entity tables — revised 2026-08-11). Each stage has
its own file with tasks, concrete file paths, code contracts, tests, and an
acceptance checklist.

## Stage tracker

| Stage | File | Depends on | Status |
|---|---|---|---|
| 0 — Foundations | [`stage-0-foundations.md`](stage-0-foundations.md) | — | not started |
| 1 — Counselor v2 turn contract | [`stage-1-turn-contract.md`](stage-1-turn-contract.md) | 0 | not started |
| 2 — R01 session engine + frontend | [`stage-2-session-engine.md`](stage-2-session-engine.md) | 1 | not started |
| 3 — Transcript finalization + scan queue | [`stage-3-transcripts-and-queue.md`](stage-3-transcripts-and-queue.md) | 2 | not started |
| 4 — SafetyScan runner | [`stage-4-safetyscan-runner.md`](stage-4-safetyscan-runner.md) | 3 | not started |
| 5 — RA dashboard | [`stage-5-ra-dashboard.md`](stage-5-ra-dashboard.md) | 4 | not started |
| 6 — Notifications, digests, launch gates | [`stage-6-notifications-launch-gates.md`](stage-6-notifications-launch-gates.md) | 5 | not started |

Update the Status column (`not started / in progress / blocked / done`) as
work proceeds; note blockers inline with the open-question number.

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
