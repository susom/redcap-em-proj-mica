# Implementation stages

Ordered so each stage is independently testable and reviewable; every stage
ends green (lint + tests) before the next starts. Workstream A = Stages 1–3,
Workstream B = Stages 3–6 (Stage 3 is the bridge).

> Detailed per-stage work breakdown (tasks, file paths, code contracts,
> tests, acceptance checklists): [`06-implementation-plan/`](06-implementation-plan/README.md).

## Stage 0 — Foundations

- Tag current `main` as `pilot-final` (pilot study keeps running on this tag;
  R01 work proceeds on `mica-phase-3`).
- Vendor handoff artifacts into `handoff/` + `manifest.json`;
  `ArtifactRegistry` class with SHA-256 verification; unit tests
  (tampered-file ⇒ fail-closed).
- `composer require opis/json-schema`; commit `vendor/`; `SchemaValidator`
  wrapper service + tests against the vendored schemas (valid/invalid
  fixtures, including a response carrying `safety_flag` ⇒ rejected).
- PHPUnit scaffolding for the module (none exists today); GitHub-style
  `docs/` updates.
- **Accept:** artifact tamper test fails closed; all vendored schemas load and
  validate fixtures under draft 2020-12.

## Stage 1 — Counselor v2 turn contract (behind feature flag)

- `TurnService` implementing the pipeline in `01-architecture.md §2`:
  wrapper build+validate, pinned-prompt messages, `json_schema` call,
  response validation, app gates (one-question, 90/140 words, phase matrix),
  retry → fallback → static technical fallback; never silently release.
- `mica_turn` Entity type + logging (hashes, resolved model, params, latency,
  tokens, status, gate failures, phase transition, end_session). Includes the
  REDCap Entity dependency check + `redcap_entity_types()` hook +
  `EntityDB::buildSchema()`/index migration on system-enable (guarded by
  `schema-version`).
- Feature flag `counselor-contract-v2` switches `callAI` ajax to TurnService;
  legacy path untouched while flagged off.
- SecureChatAI PR #1: config-driven `supports-json-schema` /
  `supports-reasoning-effort`; verify `gpt-5-4` receives both.
- **Accept:** unit suite covers all gate/failure branches with fixture model
  outputs (ok / >1 question / 91-word / illegal phase / malformed JSON /
  refusal / timeout); integration test against real `gpt-5-4` returns a
  schema-valid turn end-to-end; `mica_turn` rows carry correct hashes.

## Stage 2 — R01 session engine + frontend

- `SessionStateService` (R01 windows, phase persistence, minutes_remaining,
  alcohol_summary mapping, prior-goal read/write, resources).
- Remove pilot code paths (`session_2..7`, `month3_fu`, `posttest`,
  `des_mica` logic); prune/add project settings per `02-data-model.md §5`.
- Build the PID 257 REDCap fields (session instruments + auth fields + the
  repeating `mica_safety_finding` instrument) once the study team signs off
  the draft (open question #8).
- Frontend: `end_session` handling, session countdown, PostSession flow
  against new contract; remove pilot-session UI assumptions.
- **Accept:** Playwright E2E on local PID 257 — OTP login → baseline chat
  (stand-in model) → `end_session` → PostSession → `completeSession` writes
  the session form; booster session sees `prior_goal`; desktop + mobile
  viewports pass (per project UI standards).

## Stage 3 — Transcript finalization + scan queue (bridge)

- Remaining Entity types (`mica_scan_job`, `mica_scan_run`,
  `mica_audit_event`) + post-`buildSchema` index/UNIQUE migration
  (`02-data-model.md §1`), still guarded by `schema-version`.
- `TranscriptFinalizer`: canonical payload, hash, immutable EM-log transcript
  row (chunked payload), REDCap form write-back
  (`mica_transcript_hash`/`_ref`), idempotent job enqueue; admin-only
  re-finalization creates version 2 + new job.
- Cron worker skeleton: atomic claim, state machine, bounded retries/backoff,
  `manual_review_required` terminal path + RA notification stub.
- **Accept:** duplicate `completeSession` calls create exactly one job;
  transcript payload validates against the input schema; hash is stable
  across rebuilds; state machine transitions covered by tests.

## Stage 4 — SafetyScan runner

- SecureChatAI PR #2: Gemini structured output (`response_mime_type` +
  `response_schema`), configurable safety settings, normalized
  `content_filter` error surface.
- `ScanRunner`: model call, output-schema validation, exact-quote verifier,
  finding persistence (immutable `mica_scan_run` Entity row + one repeating
  `mica_safety_finding` instance per finding, UUID `finding_id`s), failure
  taxonomy → `manual_review_required` + `scan_failure` placeholder instance;
  `scan-mock-mode` replays fixture outputs for dev/E2E without a live model.
- **Accept:** quote verifier rejects near-miss quotes (whitespace/casing
  drift) and wrong-message citations; a fixture "MICA claimed staff were
  alerted" transcript still yields `critical` (the v1.0→v1.1 lesson) when run
  live; content-filter block lands in `manual_review_required`, never
  `no_supported_concern`.

## Stage 5 — RA dashboard (`mica-review/` SPA)

- Vite+React app; authenticated EM page mount; module ajax endpoints with
  role enforcement + audit-on-read.
- Queue view (default sort critical→…→oldest unreviewed; spec filters),
  session review (conversation view, evidence highlight, jump-to-evidence,
  search, finding cards, raw-JSON technical toggle), dispositions (required
  rationale, corrections stored separately, optimistic locking), actions
  (approved-actions-only per concern/urgency, template preview + explicit
  confirm, delivery/ack tracking), history/audit views.
- **Accept:** Playwright E2E — scan lands → RA notified → queue shows finding
  → open session → evidence highlighted → confirm with rationale → action
  template → send (mock mail) → ack → audit trail complete; stale
  `review_lock_version` submission surfaces a conflict; a non-RA REDCap user gets
  403s; mobile viewport passes.

## Stage 6 — Notifications, digests, launch gates, hardening

- `NotificationService` + policy validation, RA-ready notices, action
  deliveries, pre-review path (label-forced, off by default), digest crons,
  ack-overdue monitor.
- `LaunchReadiness` validator + settings card + `production-mode` gating of
  session start.
- Security pass: REDCap EM security review checklist (escaping, CSRF via
  framework ajax, SQL parameterization, role checks on every endpoint), rate
  limiting on no-auth actions, Psalm scan.
- Full regression: entire E2E suite, both workstreams, dev models.
- **Accept:** with `critical_acknowledgment_minutes` null, `production-mode`
  cannot be enabled and session start is refused in production status with
  the staff-facing explanation; digests render aggregate-only content; Psalm
  + lint + full test suite green.

## Deployment / coordination notes

- SecureChatAI changes ship as separate backward-compatible PRs to that
  module's repo; MICA declares a minimum SecureChatAI version.
- Luna / Gemini 3.5 Flash cutover is a registry entry + settings change +
  qualification run (latency, content filters, structured output per the
  memo) — no MICA code change by design.
- Pilot project stays pinned to `pilot-final`; R01 gets the new version. Both
  use the same directory prefix, so production deployment must version the
  module directory (`proj_mica_v9.9.10` etc.) and enable per-project.
