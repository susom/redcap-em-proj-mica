# Stage 4 — SafetyScan runner

**Goal:** the worker actually scans: pinned v1.1 prompt + transcript JSON →
Gemini (via SecureChatAI) → schema + exact-quote verification → immutable
`mica_scan_run` row + repeating `mica_safety_finding` instances, with the
full failure taxonomy. Dev alias `gemini-2.5-flash`.

**Depends on:** Stage 3. **External:** SecureChatAI PR #2 must merge first.

## Tasks

### 4.1 SecureChatAI PR #2 (separate repo, backward-compatible)

- **Gemini structured output:** when `json_schema` is passed, set
  `generation_config.response_mime_type = "application/json"` +
  `response_schema` (translated subset — document which JSON-Schema keywords
  survive the translation), and surface parsed output as
  `structured_output` exactly like the OpenAI path.
- **Safety settings:** per-model-registry `safety_settings` config (replaces
  the hardcoded single-category `BLOCK_LOW_AND_ABOVE`).
- **Block detection:** normalize `promptFeedback.blockReason` / empty
  candidates into an explicit `content_filter` error type consumers can
  branch on.
- Acceptance: existing Gemini consumers unchanged without the new config;
  MICA records the new minimum SecureChatAI version.

### 4.2 `ScanRunner` (`classes/ScanRunner.php`)

Replaces the Stage-3 worker stub inside the claim loop:

```php
final class ScanRunner {
    public function __construct(
        ArtifactRegistry $artifacts, SchemaValidator $validator,
        CanonicalJson $canon, SecureChatCaller $chat,
        TranscriptReader $transcripts,      // re-joins chunked EM-log payload
        FindingWriter $findings, ScanRunLogger $runs, ScanConfig $config);
    public function run(ScanJob $job): ScanOutcome;
}
```

1. Load transcript via `TranscriptReader` (re-join `payload_json_*` chunks,
   verify SHA-256 against the stored hash — mismatch ⇒ `service_error`,
   manual review; never scan a corrupt payload).
2. Call `SecureChatAI::callAI(safetyscan-model-alias, …)`: pinned v1.1 prompt
   as system, transcript JSON as user content, `json_schema` = model output
   schema. `scan-mock-mode` replays fixtures from `tests/fixtures/scan/`
   instead (used by E2E).
3. Validate: JSON parse → output schema → **exact-quote verification**
   (`classes/QuoteVerifier.php`): every `evidence[].exact_quote` must be an
   exact substring (byte-level, no normalization) of the cited
   `message_id`'s content, and `speaker_role` must match. Any miss ⇒
   `citation_mismatch` ⇒ whole scan fails to manual review (released findings
   must be 100 % traceable).
4. **Success:** insert `mica_scan_run` row (verbatim `model_output_json`,
   hashes, latency, tokens, `run_status='ok'`); create finding instances
   (4.3); job → `ready_for_review`. `no_supported_concern` is a success with
   zero findings — still a reviewable record.
5. **Failure taxonomy** `timeout | refusal | invalid_json | schema_invalid |
   citation_mismatch | content_filter | service_error`: every attempt gets
   its own `mica_scan_run` row; transient classes retry per the Stage-3 state
   machine; terminal/exhausted ⇒ `manual_review_required` + `scan_failure`
   placeholder instance (4.3) + audit event + RA-notification stub.

### 4.3 `FindingWriter` (`classes/FindingWriter.php`)

- One repeating `mica_safety_finding` instance per finding via `saveData`
  (`errors` inspected; partial-write ⇒ scan attempt fails, nothing released):
  next `redcap_repeat_instance` per record/event, fields per
  `02-data-model.md §3.2`, `finding_id` = UUIDv4 (uniqueness checked against
  existing instances before write), `finding_scan_run` = entity row id,
  `review_status='pending'`, `review_lock_version=0`.
- `manual_review_required` path writes the single `scan_failure` placeholder
  instance (concern type `scan_failure`, urgency `high`, summary = failure
  class + attempt count; no evidence fields).

## Tests

- **Unit:** QuoteVerifier — exact match passes; case drift, whitespace drift,
  ellipsis, curly-quote substitution, wrong `message_id`, wrong
  `speaker_role` all fail; multi-evidence findings (one bad quote fails the
  scan). Runner branches per taxonomy class from stubbed responses; verify
  each attempt logs a run row and no findings are written on any failure.
- **Integration:** mock-mode fixture with 2 findings ⇒ 1 scan_run + 2
  instances + job `ready_for_review`; fixture `no_supported_concern` ⇒ 0
  instances, job `ready_for_review`; forced `content_filter` stub ⇒
  `manual_review_required` + placeholder instance — **never**
  `no_supported_concern`; retry produces distinct run rows with incremented
  `attempt`.
- **Live smoke (manual, dev alias):** benign synthetic transcript ⇒
  `no_supported_concern`; synthetic risk transcript ⇒ finding with verifiable
  quote; the v1.0-regression guard — transcript where "MICA" claims staff
  were alerted still yields `critical`.

## Acceptance checklist

- [ ] SecureChatAI PR #2 merged; Gemini returns `structured_output`;
      `content_filter` surfaced as its own error class
- [ ] Quote verifier rejects every near-miss class; only byte-exact
      substrings of the cited message pass
- [ ] Verbatim model output preserved on `mica_scan_run`; finding instances
      match it field-for-field
- [ ] Every failure class lands in the right terminal/retry path; no
      negative screens
- [ ] `scan-mock-mode` E2E path works without network
