# Stage 6 — Notifications, digests, launch gates, hardening

**Goal:** policy-driven notifications and digests, acknowledgment tracking,
the launch-readiness validator wired to `production-mode`, and the final
security/regression pass. This stage makes the module *refuse* to operate in
production until leadership resolves the deliberate blockers.

**Depends on:** Stage 5.

## Tasks

### 6.1 `NotificationService` (`classes/NotificationService.php`)

- Channel abstraction (`EmailChannel` via `\REDCap::email`; SMS via existing
  Twilio system settings later if approved — interface only, no
  implementation now).
- Policy = `notification-policy-json` project setting, validated against the
  vendored policy schema on save (`savePolicy`, Stage 5) **and** on read
  (fail closed to the vendored default). Ships as the handoff default:
  RA-ready on, pre-review **off**, `critical_acknowledgment_minutes: null`.
- Triggers:
  - `ready_for_review` / `manual_review_required` ⇒ RA "findings ready"
    notice (replaces Stage-3/4 stubs). Subject/body: counts + dashboard deep
    link — **no participant-level content, no PHI in subjects**.
  - Confirmed-finding actions (`submitAction`) ⇒ render protocol-approved
    template (open question #4) with minimum-necessary fields per recipient
    class + deep link.
  - Pre-review path (if ever enabled by protocol): body force-prefixed with
    the "Unverified automated SafetyScan finding pending human review" label
    from the policy schema; never changes finding state.
- Every delivery attempt ⇒ `mica_notification` EM-log row
  (`02-data-model.md §1.3`); failures visible in the dashboard and
  retriable; `action_delivery_status` on the finding instance updated
  `pending→sent/failed` (never silently complete).

### 6.2 Digest + acknowledgment crons

- `config.json` crons: `mica_digest_daily`, `mica_digest_weekly`,
  `mica_ack_monitor` (every 15 min).
- Digests: aggregate counts only (scanned, awaiting review, overdue,
  confirmed/dismissed rates, scanner failures, quality trends) + dashboard
  link; recipients from policy.
- Ack monitor: when `critical_acknowledgment_minutes` is configured,
  unacknowledged critical actions past the deadline ⇒ `ack_overdue`
  notification + audit event; idempotent (one nag per overdue window).

### 6.3 `LaunchReadiness` (`classes/LaunchReadiness.php`)

- `evaluate(): GateResult[]` — pass/fail per gate:
  1. `critical_acknowledgment_minutes` non-null (**the deliberate blocker**)
  2. notification recipients + RA role assignments present
  3. artifact hashes verified against manifest
  4. counselor + scan model aliases resolve in SecureChatAI
  5. notification policy schema-valid
- Enforcement: `production-mode` setting cannot be enabled while any gate
  fails (validated in `redcap_module_save_configuration` or the settings
  endpoint); REDCap project in Production status with `production-mode` off
  or gates failing ⇒ **session start refused** with staff-facing
  explanation; Development status ⇒ visible "launch gates unmet —
  development only" banner in the chatbot. Scanning/review never blocked.
- Launch-readiness card on the dashboard Settings view (Stage 5 placeholder
  wired to `evaluate()`).

### 6.4 Security & compliance pass (`redcap-external-module` skill, Workflow C)

- Run the skill's review workflow: walk `references/security.md` and
  `references/compliance.md` checklists over everything new: `$this->escape()` on
  all output of user-controlled values; parameterized SQL only (claim query,
  index migration checks); CSRF via framework AJAX; every AJAX/API action
  allowlisted with throwing `default`; no-auth surface (`callAI`, `login`,
  `verifyEmail`, `completeSession`) — session-token binding after OTP +
  `throttle()` rate limiting; `getSafePath()`/whitelist on any file access.
- Compliance sweep: no field values in `emDebug` logs on the new paths; no
  PHI in email subjects/URLs; digests aggregate-only; model calls
  pseudonymous-only (assert against built payloads).
- Run Control Center → Module Security Scanning (Psalm) and resolve every
  finding; `composer audit` + `npm audit` on both SPAs.

### 6.5 Full regression + release

- Entire Playwright suite (participant + RA paths, desktop + mobile),
  PHPUnit, vitest, lint — all green via `composer test` / `npm test` (no CI; see stage 0.5).
- Update module `README.md` (architecture summary, settings reference,
  Entity/SecureChatAI dependencies + minimum versions, ops runbook: cron
  cadence, Entity DB manager, refinalize procedure), `CHANGELOG.md`.
- Version bump + versioned directory deployment note
  (`proj_mica_v9.9.10`+); pilot stays on `pilot-final`.

## Tests

- **Unit:** policy validation (default passes; malformed rejected;
  pre-review label forced; null ack minutes ⇒ gate 1 fails); each gate
  independently togglable in fixtures; ack-monitor idempotency; digest
  aggregation math; template rendering minimum-necessary assertion (no
  transcript text in any outbound body).
- **Integration:** delivery failure ⇒ EM-log row `failed` + dashboard
  visibility + retry path; `production-mode` save rejected while a gate
  fails.
- **E2E:** gate scenario — ack minutes null ⇒ production-mode blocked +
  session start refused (staff explanation shown) in Production status;
  set minutes + roles + recipients ⇒ gates pass ⇒ session start allowed;
  digest email captured with aggregate-only content; overdue critical ack ⇒
  nag captured.

## Acceptance checklist

- [ ] All five gates enforced; production-mode un-enableable while failing;
      session start refused per spec; dev banner shows
- [ ] Notifications policy-driven; deliveries logged; failures retriable
- [ ] Pre-review notices labeled and state-inert (even though shipped off)
- [ ] Psalm/security scan + audits clean; checklist findings resolved
- [ ] Full CI matrix green; docs + changelog updated; release tagged
