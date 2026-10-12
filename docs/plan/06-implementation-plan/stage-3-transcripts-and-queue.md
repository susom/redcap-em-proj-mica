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

## ✅ Implementation record — 3.1–3.3 + 3.5 state machine (2026-08-19/20)

**Done:** 3.1 (landed with Stage 1.1 — all four entity types, one migration), 3.2
`CanonicalJson`, 3.3 `TranscriptFinalizer` + `refinalize`, and the `ScanJobStateMachine`
+ `ScanQueue` half of 3.5. **Remaining:** the cron declaration and `ScanWorker` body
(3.5), and the `completeSession` rewrite (3.4).

### The session boundary: derived, not read

3.3 assumed a session start timestamp. There isn't one — PID 257 has no
`mica_session_start_ts` (audit G4) and **no `consent_date` at all**, and `mica_id` is
the participant, not the session. So the boundary is derived instead:

> **This session is every message row after the `max_message_log_id` recorded on the
> previous finalized transcript for this (record, session_type, instance).**

`log_id` is monotonic and database-assigned, which makes the boundary immune to clock
skew, to a participant's device clock, and to two sessions starting in the same second.
The first session for a slot has no previous transcript and takes every row. The
high-water mark is written **onto the transcript row**, so it travels with the
transcript rather than being recomputed from a field that might change.

**This removes Stage 3's dependency on Stage 2.** Nothing here waits on the dictionary.

### Deviations from the task list

- **`message_id` needed no suffix, but only because of how rows are read.** `callAI`
  writes two rows per turn (the participant's message before the model call, the whole
  turn after), so the participant's words appear twice. Participant messages are taken
  only from the standalone rows and MICA messages only from `response.content` — one
  message per `log_id`, and **a participant message whose turn failed is still in the
  transcript.** Reusing `MICAQuery::getLogsFor()` would have dropped it; see
  `TranscriptBuilder`'s class comment.
- **The idempotency key gained `instance` *and* `version`.** `version` because 3.3.6
  wants a refinalize to produce a new job, and a correction with byte-identical content
  hashes identically — without the version, "duplicate is success" would silently
  enqueue nothing and the admin would see the correction accepted with no rescan.
- **`finalize()` is idempotent rather than an error on a repeat call.** See below.
- **The session-form write-back is non-fatal.** The scanner reads the EM-log row, not
  the form, so a missing dictionary field degrades to a named warning instead of
  blocking a scan. Refusing to scan a participant's conversation over a missing display
  column would be the wrong trade. Loud, though: logged, returned on `FinalizeResult`,
  and a Stage 6 launch gate.
- **`scan_failed` is unreachable.** It stays in the entity choices for data-model
  parity, but no transition reaches it: a terminal status meaning "the scan did not
  happen" that raises no review task is the silent negative screen the handoff forbids.
  Every give-up lands in `manual_review_required`.

### Four bugs only a live run could find

All from `scripts/verify-transcript-store.php` against the container.

1. **`queryLogs()` cannot filter on the `message` column.** `where message = ?` matches
   nothing, silently. `latestTranscript()` found no previous transcript, so **every
   session looked like the first one** and the scanner would have re-read the whole
   history and re-reported every finding, forever. Transcript rows now carry an explicit
   `log_type` *parameter*.
2. **`log()` infers `project_id` from the request.** A cron or CLI write lands with a
   NULL `project_id` — invisible to every read in the store, and unremovable by
   `removeLogs()`, which refuses to run without a `project_id` in its where clause.
   Passed explicitly now.
3. **`\REDCapEntity\EntityFactory` is not loaded on a survey page**, which is where
   `completeSession` runs. The scan-job insert died with a bare "class not found" at the
   worst moment: transcript written, nothing queued. The guard existed in the schema
   manager and not in the queue store — which is what remembering per call site gets
   you — so it is one shared `RedcapEntityLoader` now.
4. **That failure exposed a state with no way out.** A finalize that wrote the
   transcript and then died left the boundary advanced past every message the transcript
   contained, so every retry reported an empty session and the conversation could
   *never* be scanned. `finalize()` now recovers it: a repeat call with nothing new
   returns the existing transcript and queues the scan if it is missing. Which also
   makes a double-clicked **End Session** an idempotent success rather than a "no
   messages could be read" error the participant would loop on.

Also worth noting: `redcap_entity`'s `record` property type validates through
`Records::recordExists(PROJECT_ID, …)`, so a scan job's `record` must be a real record
and `PROJECT_ID` must be defined. True for every production caller; it bites synthetic
test data.

### Verified

- **441 unit tests**, phpcs clean, including the built payload validated against the
  hash-pinned input schema (plus a companion test proving that check bites).
- **`verify-transcript-store.php`: PASS** — messages read, transcript written, stored
  payload re-hashes to the recorded hash and is still schema-valid, the failed turn's
  message survived, no participant or record id anywhere in the payload, job queued and
  pointing at the right transcript, `latestTranscript()` finds it, a second session
  reads only what came after, refinalize re-reads the whole session and rescans, and
  every row it wrote is removed.
- **`verify-transcript-chunking.php`: PASS** — >60 KB multi-byte payloads survive the
  EM log with their hash intact, verified twice (via `queryLogs` and the raw parameters
  table). It also reports whether a *naive* cut would have split a character: 4 of 5
  fixtures do, snapping back 1–3 bytes. Without that measurement the probe could have
  passed while testing nothing.
- Live confirmation of **audit G4** from the outside: all four session-form fields are
  absent on PID 257, the write-back names each one, and the scan queues regardless.

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
