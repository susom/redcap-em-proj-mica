# Stage 5 — RA dashboard (`mica-review/` SPA)

**Goal:** the authenticated review UI: queue, session review with evidence
highlighting, dispositions with optimistic locking, protocol-approved
actions, history/audit. Every read of participant-level data and every
mutation writes `mica_audit_event`.

**Depends on:** Stage 4 (findings exist). May split into two PRs:
(a) backend endpoints + roles + audit, (b) SPA.

> New page + AJAX actions follow the `redcap-external-module` skill,
> Workflow B (authenticated page, allowlisted actions with throwing
> `default`, `getUrl()` asset links, `$this->escape()` on output).

## Tasks

### 5.1 Page + app shell

- `pages/review.php` — authenticated EM page (NOT in `no-auth-pages`),
  project sidebar link in `config.json` `links.project`
  (`show-header-and-footer: true`), mounts the built SPA assets via
  `getUrl()`; 403 for users without a module role.
- `mica-review/` — second Vite+React app mirroring `mica-chatbot/` layout
  (`vite.config.js` build → `dist/`, `generateAssetFiles()`-style loader);
  vitest for pure logic.

### 5.2 Roles (`classes/RoleService.php`) + settings

- Project settings (user lists, repeatable): `role-ra-reviewer`,
  `role-pi-lead`, `role-auditor`; `sysadmin` = REDCap super users (config
  only — **cannot** submit dispositions).
- `RoleService::roleFor(string $username): ?Role`; every AJAX action starts
  with `requireRole(...)` — hiding UI is not access control.
- Care-team members are notification recipients only; no dashboard access.

### 5.3 AJAX endpoints (`auth-ajax-actions` in `config.json`)

All in the `redcap_module_ajax` switch, thin wrappers over
`classes/Review/*` services; **every** endpoint: project context + role
check + `AuditLogger` entry (actor, role, event_type, target, minimal
details) + `$this->escape()` on output of user-controlled values.

| Action | Role | Purpose |
|---|---|---|
| `reviewQueue` | ra/pi | jobs + findings joined; default sort critical→high→moderate→quality→oldest-unreviewed; `manual_review_required` pinned top; filters (date, urgency, category, status, reviewer, session type, notification state, model version) |
| `reviewSession` | ra/pi | transcript (from `TranscriptReader`), finding instances, scan-run metadata (hashes/model/version), raw model JSON behind the technical toggle |
| `submitDisposition` | ra/pi | confirm/dismiss/needs-second-review + optional corrections + **required rationale**; optimistic lock (5.4) |
| `submitAction` | ra/pi | only protocol-approved actions for that concern/urgency; template preview + explicit confirm; delegates to NotificationService (stub until Stage 6) |
| `reviewHistory` | ra/pi (auditor: deidentified aggregates only) | all sessions incl. zero-finding + failed; decisions, actions, hashes/versions; permission-gated export |
| `auditTrail` | pi/auditor | `mica_audit_event` reads |
| `getPolicy` / `savePolicy` | sysadmin | notification-policy editor (schema-validated; Stage 6 consumes) |

### 5.4 Disposition write path (`classes/Review/DispositionService.php`)

- Reads the finding instance, verifies `review_lock_version` from the client
  matches; stale ⇒ 409-style AJAX error the SPA answers with a reload
  prompt.
- Writes only review fields (`review_*`) + `review_lock_version + 1` via
  `saveData` (errors inspected); model-populated `finding_*` fields never
  touched (assert in code, not just convention).
- Rationale required server-side for confirm/dismiss.
- `scan_failure` placeholder instances take dispositions the same way
  (resolves the manual-review task).

### 5.5 SPA views (per dashboard spec, `01-architecture.md §6`)

- **Queue** — sort/filter set above; urgency chips; manual-review banner.
- **Session review** — readable conversation (participant/MICA styling,
  sequence numbers, message IDs on demand), highlighted evidence spans
  (offsets computed client-side from `exact_quote` within the cited
  message — vitest-covered), jump-to-evidence, full-transcript search,
  finding cards, raw-JSON technical toggle.
- **Disposition + Actions** — required-rationale form; approved-actions-only
  per concern/urgency; template preview with minimum-necessary content;
  explicit send confirmation; delivery/ack status display. UI never shows
  "alerted" from a model recommendation alone.
- **History & audit** — all sessions incl. zero-finding; export button
  permission-gated.
- **Settings** — policy editor + launch-readiness card (card content wired in
  Stage 6).
- Follow the frontend-design guidance for a distinct, accessible clinical
  UI; both desktop and mobile viewports are first-class.

## Tests

- **Unit (PHP):** RoleService matrix (every role × every action, incl.
  sysadmin-cannot-disposition and auditor-read-only); disposition lock
  (stale/current/racing increments); rationale enforcement; queue sort
  comparator; audit entry written per endpoint (spy logger).
- **Unit (vitest):** evidence-offset highlighting (multi-occurrence quotes,
  unicode), queue sorting/filtering reducers.
- **E2E (Playwright, `scan-mock-mode`):** scan lands → queue shows finding
  (critical first, manual-review visible) → open session → evidence
  highlighted → jump-to-evidence → confirm with rationale → action template
  preview → send (mail capture) → ack recorded → audit view shows every step;
  stale `review_lock_version` from a second tab ⇒ conflict + reload; non-role
  user gets 403 on every endpoint (API-level assertions, not just UI);
  auditor sees aggregates only; **mobile viewport passes** every flow.

## Acceptance checklist

- [ ] Full E2E chain green (queue → review → disposition → action → audit),
      desktop + mobile
- [ ] Optimistic-locking conflict surfaces correctly
- [ ] RBAC: non-role 403s everywhere; sysadmin cannot change clinical
      decisions; auditor deidentified-aggregates only
- [ ] Every participant-data read and every mutation has an audit row
- [ ] Model-populated finding fields provably untouched by dispositions
