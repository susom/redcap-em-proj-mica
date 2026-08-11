# Data model

## 1. Storage strategy

**Revised 2026-08-11** (supersedes the 2026-08-05 "module-owned custom MySQL
tables" decision): the module ships **no hand-written `CREATE TABLE` DDL**.
Three stores, chosen per data class:

| Store | What lives there | Why |
|---|---|---|
| **REDCap fields** (PID 257) | Session-form fields (§3.1); **findings + human review + actions** on a new repeating instrument (§3.2) | Study team sees / exports / reports it with native REDCap tooling; every change is captured by REDCap's own data audit log; user-rights control it |
| **EM log store** (`redcap_external_modules_log*`) | Chat messages (existing pattern); finalized canonical **transcripts** (§1.2); **notification** delivery records (§1.3) | Append-only by construction (the framework has no UPDATE path for log rows); already the message source of truth |
| **REDCap Entity tables** (via the `redcap_entity` EM) | `mica_scan_job`, `mica_scan_run`, `mica_turn`, `mica_audit_event` (§1.1) | Backend workflow / telemetry / audit rows that are not project data; framework-managed table lifecycle + a free admin list UI (Entity DB Manager) |

**Dependency:** the [REDCap Entity](https://github.com/ctsit/redcap_entity)
module (installed locally as `redcap_entity_v9.9.9`, enabled globally) becomes
a hard dependency. `proj_mica` verifies it at `redcap_module_system_enable`
and fails loudly if absent. Entity types are declared in the
`redcap_entity_types()` hook; tables are created idempotently at system-enable
via `\REDCapEntity\EntityDB::buildSchema()` (guarded by the `schema-version`
system setting), and admins can inspect/rebuild them in the control-center
Entity DB Manager.

Every entity table is named `redcap_entity_<type>` and gets framework columns
`id` (PK, auto-increment), `created`, `updated` (epoch seconds).
Property→column mapping: `text`/`user`/`record` → VARCHAR(255),
`integer`/`date` → INT (dates stored as epoch), `boolean` → TINYINT,
`json`/`long_text` → TEXT, `data` → MEDIUMTEXT.

Two Entity-framework limitations are compensated in a one-time migration run
immediately after `buildSchema()` plus code discipline:

- Entity creates **no secondary indexes or UNIQUE constraints** → the module
  adds them idempotently: `UNIQUE uq_idem (idempotency_key)` and
  `KEY idx_due (status, next_attempt_at)` on `redcap_entity_mica_scan_job`;
  `KEY idx_job (job_id)` on `redcap_entity_mica_scan_run`;
  `KEY idx_record (project_id, record)` on `redcap_entity_mica_turn`;
  `KEY idx_target (target_kind, target_id)`, `KEY idx_actor (actor)` on
  `redcap_entity_mica_audit_event`.
- Entity's save path is generic CRUD → **insert-only** discipline for
  `mica_scan_run`, `mica_turn`, `mica_audit_event` is enforced exactly as
  before: no UPDATE code path in the module. The queue claim remains a direct
  atomic `UPDATE redcap_entity_mica_scan_job SET status='scanning',
  claimed_by=:token, ... WHERE status='queued' AND next_attempt_at<=... LIMIT 1`.

Immutability rule (revised for the new stores) — the spec's "source
transcripts, model findings, and human review records remain separately
versioned" maps to:

- **Source transcript** — EM-log row (§1.2); log rows cannot be updated;
  corrections are new versioned rows.
- **Model findings** — the authoritative, immutable copy is the verbatim
  `model_output_json` on the insert-only `mica_scan_run` entity row. The
  REDCap finding instances (§3.2) are the *review working copy*: their
  model-populated fields are never written after instance creation
  (app-enforced; `@READONLY` on forms) and any drift is both detectable
  (against `model_output_json`) and audited (REDCap data log).
- **Human review** — mutable fields on the same finding instance, with
  REDCap's field-level change history as the version trail and
  `review_lock_version` for optimistic locking.

### 1.1 Entity type definitions (`redcap_entity_types()`)

#### `mica_scan_job` → `redcap_entity_mica_scan_job` (mutable queue / state machine)

| Property | Entity type | Notes |
|---|---|---|
| `project_id` | project | `special_keys.project` |
| `record` | record | |
| `session_type` | text | choices `baseline` / `booster` |
| `transcript_ref` | integer | EM-log `log_id` of the transcript row (§1.2) |
| `idempotency_key` | text | UNIQUE via post-build migration |
| `status` | text | choices `queued / scanning / ready_for_review / under_review / review_complete / scan_failed / manual_review_required` |
| `attempts` | integer | |
| `next_attempt_at` | date | epoch |
| `claimed_by` | text | claim token (UUID) |
| `claimed_at` | date | |
| `last_error` | long_text | |

#### `mica_scan_run` → `redcap_entity_mica_scan_run` (insert-only; retries = new rows)

| Property | Entity type | Notes |
|---|---|---|
| `job_id` | entity_reference → `mica_scan_job` | |
| `attempt` | integer | |
| `model_alias` / `resolved_model` | text | exact deployment reported back |
| `prompt_sha256` / `input_schema_sha256` / `output_schema_sha256` | text | verified hashes actually used |
| `app_version` | text | module version + git short-sha |
| `latency_ms` / `prompt_tokens` / `completion_tokens` | integer | |
| `run_status` | text | choices `ok / timeout / refusal / invalid_json / schema_invalid / citation_mismatch / content_filter / service_error` |
| `model_output_json` | data (MEDIUMTEXT) | **verbatim model output — the authoritative immutable findings record** |

#### `mica_turn` → `redcap_entity_mica_turn` (insert-only; no message text)

| Property | Entity type | Notes |
|---|---|---|
| `project_id` / `record` | project / record | |
| `session_type` | text | choices `baseline` / `booster` |
| `turn_index` | integer | |
| `message_log_ids` | text | `"L123,L124"` pointers into the EM-log message store |
| `model_alias` / `resolved_model` | text | |
| `prompt_sha256` / `wrapper_schema_sha256` / `output_schema_sha256` | text | |
| `app_version` | text | |
| `params_json` | json | accepted params (effort, max_tokens) |
| `latency_ms` / `prompt_tokens` / `completion_tokens` | integer | |
| `turn_status` | text | choices `ok / retried_ok / fallback_ok / failed_technical / refused / timeout` |
| `gate_failures` | text | e.g. `"one_question,word_limit"` |
| `phase_from` / `phase_to` | text | choices `engage / focus / evoke / plan / close` |
| `response_strategy` | text | |
| `end_session` | boolean | |

#### `mica_audit_event` → `redcap_entity_mica_audit_event` (append-only)

| Property | Entity type | Notes |
|---|---|---|
| `actor` | user | |
| `actor_role` | text | |
| `event_type` | text | e.g. `queue_view, session_view, disposition, action_sent, policy_change` |
| `target_kind` / `target_id` | text | what was accessed/mutated |
| `details` | json | minimum necessary; never transcript text |

### 1.2 Transcript rows (EM log store)

`TranscriptFinalizer` writes one `$module->log('mica_transcript', [...])` row
per finalized version, with parameters: `record`, `session_type`, `setting`,
`session_pseudo_id`, `version`, `supersedes_log_id` (corrections only),
`started_at`, `ended_at`, `message_count`, `transcript_sha256`,
`payload_json` (canonical input-schema payload; overflow continues in
`payload_json_2..n`).

- Transcript reference everywhere = **`"T" + log_id`** (mirrors the message
  scheme `"L" + log_id`); stored on the scan job (`transcript_ref`) and the
  session form (`mica_transcript_ref`).
- Log rows have no framework UPDATE path → insert-only by construction.
  Admin-only correction inserts a **new** row (`version + 1`,
  `supersedes_log_id` pointing back) + a new scan job; "current" = highest
  version for (project, record, session_type). Originals are never mutated.
- **Size guard:** `redcap_external_modules_log_parameters.value` is TEXT
  (~64 KB). The canonical payload string is chunked at 60 KB into
  `payload_json`, `payload_json_2`, …; `transcript_sha256` is computed over
  the full re-joined string. Readers must re-join before parse/verify.

### 1.3 Notification records (EM log store)

`$module->log('mica_notification', [...])` per outbound delivery attempt:
`kind` (`ra_ready / manual_review / action_delivery / pre_review / digest /
ack_overdue`), `recipient`, `channel` (`email`), `subject`, `delivery_status`
(`sent / failed`), plus `finding_id` / `job_id` refs where applicable.
Retries append new rows (latest row wins for display); failures stay visible
and retriable, never silently marked complete. No participant-level content
in any parameter.

Justification (for EM review): project-visible clinical workflow data
(findings, review decisions, actions) belongs in REDCap fields, where the
study team gets native change logging, exports, reports, and user-rights
control; large append-only text (messages, transcripts) stays in the EM log
store the module already uses; and queue/telemetry/audit rows that need SQL
semantics live in Entity tables — created and admin-browsable via the REDCap
Entity framework instead of hand-maintained DDL. The two hard SQL requirements
that drove the original custom-table design — a UNIQUE idempotency key and an
atomic queue claim — survive as a one-time index migration and a direct
`UPDATE` against the entity table. The dashboard spec's "separately versioned
and immutable" requirement is met by keeping the verbatim model output on the
insert-only `mica_scan_run` row (see the immutability rule in §1).

## 2. Identifier & hash schemes

- **`message_id`** = `"L" + <redcap_external_modules_log.log_id>` of the stored
  message row — immutable, unique, stable across re-reads; what SafetyScan
  cites and what quote-verification resolves.
- **`transcript_ref`** = `"T" + <redcap_external_modules_log.log_id>` of the
  finalized transcript row (§1.2) — same immutability properties.
- **`finding_id`** = app-generated UUID (spec requirement), stored on the
  REDCap finding instance; uniqueness enforced app-side at instance creation
  (one instance per finding_id, checked before `saveData`).
- **`session_pseudo_id`** = first 32 hex chars of
  `sha256(system_salt | project_id | record | session_type)`; the only session
  identifier ever sent to a model. Salt = module system setting generated once.
- **`transcript_sha256`** over the canonical serialized input-schema payload
  (fixed key order, unescaped unicode/slashes). Idempotency key =
  `sha256(project_id|record|session_type|transcript_sha256)`.
- **`app_version`** = `config.json` version + git short SHA (baked at deploy).
- Artifact hashes come from `ArtifactRegistry` (computed at load, verified
  against the vendored manifest).

## 3. REDCap field additions (PID 257)

The `mica_ed_session` and `mica_booster_session` instruments are currently
empty placeholders (created 2026-08-04). Proposed fields — **draft for study
team sign-off** (open question #8):

### 3.1 Session instruments (`mica_ed_session` / `mica_booster_session`)

| Field (both instruments unless noted) | Type | Purpose |
|---|---|---|
| `mica_location` | radio `ed / remote_followup / other_approved` | wrapper `location` / scan `setting` |
| `mica_session_start_ts` | datetime | window + minutes_remaining anchor |
| `mica_session_end_ts` | datetime | finalization time |
| `mica_session_status` | radio `not_started / in_progress / finalized` | staff visibility |
| `mica_transcript_hash` | text @READONLY | SHA-256 of the finalized canonical payload |
| `mica_transcript_ref` | text @READONLY | `T<log_id>` pointer to the EM-log transcript row (§1.2) |
| `mica_raw_chat_log` | notes @HIDDEN | human-readable log (existing pattern) |
| `mica_readiness` | integer 0–10 | wrapper `readiness_score` |
| `mica_confidence` | integer 0–10 | wrapper `confidence_score` |
| `mica_goal_text` (ED form) | notes, ≤500 chars | baseline goal → booster `prior_goal` |
| `mica_goal_status` (booster form) | radio: enum from wrapper schema | `prior_goal_status` |
| `mica_booster_window_open` (booster) | date | window logic (or derive from randomization date) |

Also needed on enrollment/randomization forms: enrollment datetime and
randomized arm (drives whether MICA is offered at all — arms 2 & 3 only).
Participant contact fields (`participant_name/email/phone`, OTP fields) must
exist in PID 257 for the login flow — currently absent; they belong on the
`baseline1` (Demographics) or a dedicated auth instrument.

### 3.2 Findings + review: repeating instrument `mica_safety_finding`

New **repeating instrument** on the same events as the session instruments
(Day-1 and Month-3, arms 2 & 3); one instance per finding. `ScanRunner`
creates instances via `saveData` (errors inspected) after a successful scan;
the RA dashboard writes only the review/action fields. Normal data-entry
roles get **read-only** user rights on this instrument — hiding it is not
access control, but nobody edits findings by hand.

**Model-populated fields** (written once at instance creation, `@READONLY`,
never touched again — authoritative copy stays on `mica_scan_run`, §1):

| Field | Type | Purpose |
|---|---|---|
| `finding_id` | text @READONLY | app-generated UUID (spec requirement) |
| `finding_scan_run` | text @READONLY @HIDDEN | `redcap_entity_mica_scan_run.id` provenance |
| `finding_index` | integer @READONLY | order within the scan output |
| `finding_source_role` | radio `participant / mica / multiple` | |
| `finding_concern_type` | dropdown (enum from output schema + `scan_failure`, see below) | |
| `finding_urgency` | radio `quality / moderate / high / critical` | queue sorting |
| `finding_summary` | notes | model's summary (≤800 chars) |
| `finding_evidence_json` | notes @HIDDEN | `[{message_id, speaker_role, exact_quote}]` — rendered by the dashboard |
| `finding_rec_actions_json` / `finding_rec_targets_json` | notes @HIDDEN | model recommendations (never auto-acted on) |
| `finding_confidence` | text (number 0–1) | |

**Review fields** (RA dashboard only):

| Field | Type | Purpose |
|---|---|---|
| `review_status` | radio `pending / confirmed / dismissed / needs_second_review` | default `pending` |
| `review_reviewer` | text @READONLY | username, set by the module |
| `review_corrected_concern_type` | dropdown (optional) | corrections stored separately — model fields untouched |
| `review_corrected_urgency` | radio (optional) | |
| `review_rationale` | notes | **required on confirm/dismiss** (app-enforced) |
| `review_notes` | notes | never edits transcript or model output |
| `review_reviewed_at` | datetime @READONLY | |
| `review_lock_version` | integer @HIDDEN | optimistic locking (stale ⇒ 409 + reload) |

**Action fields** (only meaningful when `review_status = confirmed`, or on a
`scan_failure` instance):

| Field | Type | Purpose |
|---|---|---|
| `action_types` | checkbox: `alert_care_team / alert_pi / alert_protocol_lead / second_reviewer / privacy_review / model_quality_review / document_no_action` | protocol-approved list |
| `action_initiated_by` | text @READONLY | |
| `action_payload_min` | notes @HIDDEN | minimum-necessary content actually sent |
| `action_delivery_status` | radio `pending / sent / failed / not_applicable` | per-delivery detail lives in `mica_notification` EM-log rows (§1.3) |
| `action_ack_by` / `action_ack_at` | text / datetime | critical-acknowledgment tracking |
| `action_completed_at` | datetime | |
| `action_notes` | notes | |

Design notes (flagged for review):

- **One action bundle per finding.** `action_types` is a checkbox, so several
  action types can be recorded on one finding, but they share one
  delivery/ack track; individual delivery attempts and retries are visible in
  the `mica_notification` EM-log rows. If the protocol needs independent ack
  tracking per action, actions get their own repeating instrument instead.
- **Scan failures become a placeholder instance.** When a job lands in
  `manual_review_required`, `ScanRunner` creates one instance with
  `finding_concern_type = scan_failure` (app value, not in the model output
  enum) so the RA disposition + rationale for failed scans live in the same
  instrument and failed sessions stay visible in history.
- **Zero-finding sessions** create no instances; they remain visible in the
  dashboard history via the scan job (`review_complete`) and the
  `no_supported_concern` scan run — never a silent negative screen.

### 3.3 `alcohol_summary` mapping (draft — needs data-dictionary confirmation)

| Wrapper field | Candidate source (PID 257) |
|---|---|
| `drinking_days_past_30` | DDQ (`ddq` instrument) drinking-days item — field TBD |
| `typical_drinks_per_drinking_day` | DDQ typical-quantity item — TBD |
| `maximum_drinks_on_one_day` | DDQ max item / `screen.auditc3` — TBD |
| `participant_reported_consequences` | SIP-2R endorsed items (labels, ≤160 chars each) — TBD |

Missing values pass as `null`s (schema allows) — the prompt is built to work
from participant statements when context is absent.

## 4. MI phase-transition matrix (proposed default, configurable)

MI is deliberately flexible; the handoff requires "allowed" transitions
without defining them. Proposal: forward moves of any distance, backward moves
of one step, self-loops always, `close` reachable from anywhere, nothing
leaves `close`.

| from \ to | engage | focus | evoke | plan | close |
|---|---|---|---|---|---|
| engage | ✓ | ✓ | ✓ | ✓ | ✓ |
| focus  | ✓ | ✓ | ✓ | ✓ | ✓ |
| evoke  | ✗ | ✓ | ✓ | ✓ | ✓ |
| plan   | ✗ | ✗ | ✓ | ✓ | ✓ |
| close  | ✗ | ✗ | ✗ | ✗ | ✓ |

(engage/focus rows permissive because early phases legitimately jump ahead;
regressions past one step blocked as likely model drift.) Stored as a JSON
project setting so the study team can tune without code. Violation = gate
failure → corrective retry. **Needs sign-off — open question #5.**

## 5. Project settings changes

**Removed** (pilot-era): `chatbot_system_context_general`,
`chatbot_system_context_baseline`, `chatbot_system_context_session_2..7`,
`llm-model` dropdown, `gpt-temperature/top-p/frequency/presence` (handoff:
omit sampling params), `session_length_days`, `number_session_callback`.

**Added:**

| Setting | Type | Default |
|---|---|---|
| `counselor-model-alias` | text | `gpt-5-4` (dev) → pinned Luna alias |
| `counselor-fallback-alias` | text | `gpt-5-4` |
| `counselor-max-output-tokens` | number | 1200 |
| `counselor-reasoning-effort` | dropdown | medium |
| `session-minutes` | number | 15 |
| `history-max-messages` | number | 20 |
| `research-approved-resources` | textarea (one per line) | — |
| `technical-fallback-text` | textarea | pre-approved static message |
| `phase-transition-matrix` | textarea JSON | §4 matrix |
| `safetyscan-model-alias` | text | `gemini-2.5-flash` (dev) → `gemini-3.5-flash` |
| `safetyscan-max-attempts` | number | 3 |
| `notification-policy-json` | textarea JSON | vendored default policy |
| `role-ra-reviewer` / `role-pi-lead` / `role-auditor` | user-list (repeat) | — |
| `care-team-recipients` / `pi-recipients` | text (repeat, email) | — |
| `production-mode` | checkbox | off (blocked by launch validator) |
| `scan-mock-mode` | checkbox (dev/test only) | off |

**Module dependencies:** REDCap Entity EM enabled globally (§1) — verified at
system-enable; setup docs must list it as a prerequisite.

**Composer:** add `opis/json-schema` (draft 2020-12 — the handoff schemas'
dialect; the common `justinrainbow` lib does not support it). PHP 8.3 ✔.
`vendor/` must ship with the module (present in prod deployments; run
`composer install` locally — the local checkout currently has no `vendor/`).
