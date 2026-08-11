# Stage 3 — Transcript finalization + scan queue (A→B bridge)

**Goal:** on `completeSession`, snapshot an immutable canonical transcript
into the EM log, write back to the session form, and enqueue an idempotent
scan job; stand up the cron worker skeleton (claim + state machine + retries)
without the model call.

**Depends on:** Stage 2.

> The cron declaration and the `refinalizeTranscript` AJAX action follow the
> `redcap-external-module` skill, Workflow B (cron + AJAX patterns from
> `references/patterns.md` / `config-json.md`).

## Tasks

### 3.1 Remaining entity types + migration

- Extend `redcap_entity_types()` with `mica_scan_job`, `mica_scan_run`,
  `mica_audit_event` (`02-data-model.md §1.1`); bump `schema-version`.
- `EntitySchemaManager` migration adds:
  `UNIQUE uq_idem (idempotency_key)` + `KEY idx_due (status, next_attempt_at)`
  on `redcap_entity_mica_scan_job`; `KEY idx_job (job_id)` on
  `redcap_entity_mica_scan_run`; audit-table keys per the data model.

### 3.2 Canonical serialization (`classes/CanonicalJson.php`)

- `encode(array $payload): string` — fixed key order (recursive ksort against
  the input schema's property order), `JSON_UNESCAPED_UNICODE |
  JSON_UNESCAPED_SLASHES`; `hash(string $canonical): string` (SHA-256).
- This is the **only** serializer used for transcript hashing and the scan
  request body — one implementation, no drift.

### 3.3 `TranscriptFinalizer` (`classes/TranscriptFinalizer.php`)

```php
final class TranscriptFinalizer {
    public function finalize(string $projectId, string $record): FinalizeResult;
    public function refinalize(string $projectId, string $record, string $adminUser): FinalizeResult; // version+1
}
```

1. Collect the session's accepted messages from `MICAQuery` in order;
   `message_id = "L<log_id>"`, `sequence` 1..n, speaker roles, ISO-8601
   timestamps.
2. Build the SafetyScan input-schema-v1 payload (incl. `session_pseudo_id`,
   `setting` from `mica_location`); validate via `SchemaValidator` (invalid ⇒
   typed exception, surfaced to staff, nothing enqueued).
3. Canonicalize + hash; write the EM-log row
   `$module->log('mica_transcript', [...])` per `02-data-model.md §1.2` —
   **chunk the payload at 60 KB** into `payload_json`, `payload_json_2..n`;
   fail loudly rather than truncate.
4. Session-form write-back via `saveData` (errors inspected):
   `mica_session_status=finalized`, `mica_session_end_ts`, `raw_chat_logs`
   (human-readable, existing pattern), `mica_transcript_hash`,
   `mica_transcript_ref` (`T<log_id>`).
5. Enqueue `mica_scan_job` entity row, `idempotency_key =
   sha256(project_id|record|session_type|transcript_sha256)`; rely on the
   UNIQUE index — duplicate key ⇒ treat as no-op success (idempotent
   `completeSession`).
6. `refinalize()` (admin-only auth AJAX action `refinalizeTranscript`,
   super-user check + audit event): new transcript row `version+1` +
   `supersedes_log_id` + new job. Originals never mutated.

### 3.4 Rewrite `completeSession` (`MICA.php:812`)

- Replace the pilot implementation: resolve active session via
  `SessionStateService`, call `TranscriptFinalizer::finalize()`, return
  post-session redirect info (booster/baseline per R01 flow, not
  `posttest`/`month3_fu`).
- Duplicate calls (double-click, retry) return success without a second job.

### 3.5 Cron worker skeleton (`classes/ScanWorker.php` + cron)

- `config.json` `crons` entry: `mica_scan_worker`, every minute,
  `cron_max_run_time` 300; method iterates enabled projects
  (`framework->getProjectsWithModuleEnabled()`), batch 3–5 jobs.
- Atomic claim (direct SQL on the entity table):
  `UPDATE redcap_entity_mica_scan_job SET status='scanning',
  claimed_by=?, claimed_at=? WHERE status='queued' AND next_attempt_at<=?
  ORDER BY id LIMIT 1` then `SELECT ... WHERE claimed_by=?` — parameterized,
  claim token = UUID per worker pass.
- `ScanJobStateMachine` (pure class): legal transitions
  `queued→scanning→(ready_for_review | queued[retry] |
  manual_review_required)`, `ready_for_review→under_review→review_complete`,
  bounded retries (`safetyscan-max-attempts`, default 3) with exponential
  backoff via `next_attempt_at`; stale-claim reaper (claimed > N minutes ⇒
  back to `queued`, attempt++).
- This stage the worker body is a stub that immediately marks
  `manual_review_required` unless `scan-mock-mode` provides a fixture — the
  real model call is Stage 4. RA notification is a stub log line (Stage 6).
- `config.json`: `safetyscan-max-attempts`, `scan-mock-mode` settings.

## Tests

- **Unit:** canonical hash stability (key order, unicode, slashes);
  chunk/re-join round-trip >60 KB hashes identically; message_id/sequence
  assignment; input-schema validity; version-2 supersede chain; idempotency
  key derivation; state machine — every legal/illegal transition, backoff
  schedule, retry exhaustion ⇒ `manual_review_required`; stale-claim reap.
- **Integration (local REDCap):** duplicate `completeSession` ⇒ exactly one
  job row (assert UNIQUE index did its job); transcript EM-log row + session
  form fields written; two concurrent worker passes, one queued job ⇒
  exactly one claim; entity tables/indexes idempotent on re-enable.
- **E2E:** participant completes session ⇒ session form finalized +
  transcript row + queued job visible (Entity DB manager / DB assert).

## Acceptance checklist

- [ ] Duplicate `completeSession` calls create exactly one job
- [ ] Transcript payload validates against the input schema; hash stable
      across rebuild; chunking round-trips
- [ ] Refinalize creates v2 + new job; v1 row untouched
- [ ] State machine transitions + concurrency covered by tests
- [ ] `completeSession` has no pilot event/survey logic left
