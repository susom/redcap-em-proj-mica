# Test plan

Principles: reproduce-first for any bug (per project convention), Playwright
for all browser E2E, deterministic fixtures for model behavior, live-model
integration tests kept small and separate (cost + nondeterminism).

## 1. Unit (PHPUnit, no REDCap bootstrap where avoidable)

| Area | Cases |
|---|---|
| ArtifactRegistry | hash match, tampered file ⇒ fail-closed, missing file |
| SchemaValidator | each vendored schema × valid/invalid fixtures; counselor response with `safety_flag`/`escalation` rejected; wrapper with `safety_state` rejected; draft 2020-12 features (const, enum, additionalProperties) |
| Turn gates | one-question (0/1/2 `?`), word limits (89/90/91; 139/140/141 for summary), phase matrix (every legal + illegal pair), tolerance for unicode/whitespace in word counting |
| Failure policy | malformed → retry → ok; retry → fallback → ok; all-fail → static fallback; timeout/refusal branches; assert no model text released on failure |
| TranscriptFinalizer | canonical hash stability (key order, unicode), message_id/sequence assignment, input-schema validity, version-2 supersede, payload chunk/re-join round-trip (>60 KB payload hashes identically) |
| Idempotency | same session+hash ⇒ single job; new hash ⇒ new job |
| Quote verifier | exact match; case drift; whitespace drift; ellipsis; wrong message_id; wrong speaker_role — only exact substring of cited message passes |
| Scan state machine | queued→scanning→ready; retries/backoff; exhausted→manual_review_required; content_filter terminal |
| Notification policy | default policy validates; malformed policy rejected; pre-review label forced; null ack minutes ⇒ launch gate fails |
| Pseudonym/IDs | pseudo-id stability + salt dependence; no record id leakage in model payloads (assert on built wrapper/scan payloads) |

## 2. Integration (module in local REDCap, mocked SecureChatAI)

- `SecureChatAIStub` injected via `setSecureChatInstance()` replaying fixture
  responses (ok, malformed, refusal, timeout, content-filter) — full
  TurnService and ScanRunner paths without network.
- `mica_turn` / `mica_scan_run` Entity rows: correct hashes (match vendored
  manifest), params, statuses, no transcript text columns populated; entity
  tables + UNIQUE/index migration created idempotently on enable.
- Findings land as repeating `mica_safety_finding` instances (one per
  finding, `saveData` errors surfaced); a `manual_review_required` job
  produces exactly one `scan_failure` placeholder instance.
- Cron claim concurrency: two workers, one job — exactly one claim.

## 3. Live-model smoke (small, explicit, dev aliases)

- One real `gpt-5-4` turn: schema-valid JSON, one question, ≤90 words —
  contract holds on a real model.
- One real `gemini-2.5-flash` scan on a synthetic benign transcript
  (`no_supported_concern`) and one synthetic risk transcript (finding with
  verifiable quote).
- The v1.0-regression guard: synthetic transcript where "MICA" claims staff
  were alerted — scan must still assign `critical` urgency.
- Run manually / on demand, not in CI; results logged in docs.

## 4. E2E (Playwright, local REDCap PID 257, `scan-mock-mode` + mailhog-style capture)

Participant path (desktop + mobile viewports — UI/mobile perfection per
project standards):

1. OTP login → baseline chat → multi-turn → `end_session` → PostSession →
   session form finalized, transcript row + job created.
2. Booster window: login → prior goal surfaced → complete.
3. Gate failure UX: forced malformed fixture → participant sees the approved
   technical message, never raw model output.
4. Session-window edge: completed baseline re-login shows "return for booster"
   messaging (no pilot 14-day text).

RA path:

5. Scan completes → RA email captured → queue ordering (critical first,
   manual_review_required visible) → filters.
6. Session review: evidence highlight, jump-to-evidence, search, raw-JSON
   toggle gated to technical role.
7. Disposition: rationale required; concurrent-edit conflict (stale
   `review_lock_version`) surfaces correctly; corrections don't mutate model
   finding fields (and the instance still matches `model_output_json`).
8. Actions: only approved actions listed for concern/urgency; template
   preview; send; delivery + ack recorded; "no action" documented.
9. RBAC: non-role user 403 on every dashboard endpoint; auditor sees
   aggregates only; sysadmin cannot submit dispositions.
10. Audit: every step above visible in the audit view.

Launch gates:

11. `critical_acknowledgment_minutes` null ⇒ production-mode toggle blocked +
    session-start refusal in production status; setting it + roles ⇒ gates
    pass.

## 5. Regression obligations (lifecycle policy)

Any material model / prompt / schema change requires the research team's
frozen suites (67-context counselor regression, 120-case SafetyScan suite) —
**these artifacts are not in the handoff package**; only summaries are.
Engineering must not improvise substitutes. Tracked as open question #9; until
delivered, treat prompt/schema/model as frozen (hash-enforced) and restrict
changes to config-level fallback swaps already evaluated (gpt-5-4).

## 6. Tooling gaps to close in Stage 0

- PHPUnit not present in module — add dev dependency + CI script.
- No JS tests for either SPA — add vitest for pure logic (evidence
  highlighting offsets, queue sorting), rely on Playwright for flows.
- Lint: PHP_CodeSniffer (PSR-12) + eslint for SPAs, wired to a single
  `composer test` / `npm test` entry per package.
