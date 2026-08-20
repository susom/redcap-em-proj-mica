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

## ✅ Implementation record — 4.2 / 4.3 (2026-08-20)

**Done:** `ScanRunner`, `QuoteVerifier`, `FindingWriter`, `SecureChatSafetyScanCaller`,
`FixtureSafetyScanCaller` (`scan-mock-mode`), and the fixture set. **Not done:** 4.1, the
SecureChatAI PR — it is a separate repo and a separate review. Everything here works
against a stub and against mock mode; only the *live* Gemini smoke waits on it.

### 4.1 is still the blocker for a real Gemini call, and the code says so out loud

`SecureChatAI.php:399` allowlists `json_schema` to nine OpenAI aliases. Gemini is not
one, so structured output is **requested and silently dropped**, the model answers in
prose, and the scan fails as `invalid_json` — for a configuration reason that reads like
a model fault. Rather than wait, `SecureChatSafetyScanCaller` records
`schemaWasSent: false` on the run row and says in the error text that this is
configuration, not the model. Two other verified provider facts shape the same class:

- `sanitizeOutputForUI()` strips `error`/`type`, so a consumer cannot tell a provider
  failure from a real answer. The interim signal is `model === null && usage === null`,
  quarantined in one method so PR #1 removes it in one edit. Sound only because MICA
  never sets `agent_mode` — the request deliberately does not.
- `structured_output` is populated only on the OpenAI-compatible path, so for a
  Claude/Gemini alias `normalizeResponse()` sets `content` alone. Parsing `content` is
  therefore load-bearing, not defensive: it is the only way a non-OpenAI alias works.

### Four ways a schema-valid answer is still not a clean screen

The taxonomy is not only about transport, and this is the part worth reviewing:

| Branch | Why it is not `ok` |
|---|---|
| `unable_to_assess` | The model says so itself. A **valid** `scan_result` with an empty findings list — shaped identically to `no_supported_concern`. The single easiest way to build a silent negative screen into this pipeline. Mapped to `refusal`; terminal. |
| `citation_mismatch` | Fails the **whole** scan, not the offending finding. Releasing the verified subset would publish a partial picture as a complete one. Terminal: a scanner that fabricated evidence does not get a second try at automatic release. |
| `schema_invalid` | Structured output that does not satisfy the pinned schema. |
| no review target | Findings exist, `mica_safety_finding` does not (audit G5). Fails with the verbatim output preserved on the run row. |

### New: a `terminal` flag, and why the taxonomy alone was not enough

A missing review instrument classifies as `service_error` — the pinned taxonomy has no
value for a configuration fault — but `service_error` is *transient*, so the job would
re-call and re-pay for the model twice more against something that cannot resolve
between attempts. `ScanOutcome::$terminal` lets the runner say "retrying cannot help".
It can only ever escalate a retry into manual review, **never the reverse**, so it
cannot be used to route a finding away from a human. Asserted both ways.

### `run_status` and job status answer different questions

Worth stating because the verification script got it wrong first:
`mica_scan_run.run_status` describes **the model call** (answered, schema-valid, quotes
verified). Whether anything was **released** is the job's status, with `last_error` for
the reason. An `ok` run row under a `manual_review_required` job is not a contradiction —
it is a scan that worked and a release that did not, which is exactly what a missing
review instrument produces.

### Two bugs in QuoteVerifier, both found by testing

1. `str_contains($anything, '')` is **TRUE** in PHP, so an empty `exact_quote` verified
   against every message in the transcript. The output schema forbids one — but the
   release gate does not get to assume an earlier check ran.
2. The paraphrase diagnosis compared prefixes from byte 0 of two strings, when a quote is
   a substring from the *middle* of a message. It aligned immediately-divergent and
   reported every paraphrase as a fabrication. Now a longest-matching-prefix search
   (binary, since the predicate is monotonic — this runs inside a cron with a budget).

### Mock mode does not bypass verification

Fixtures go through the same schema validation and the same byte-exact quote check as a
real response, which is what makes `fabricated_quote` a regression guard rather than a
comment. Its first live run proved the point: fixtures hardcoded `message_id: "L1"` while
real transcripts use `L<log_id>`, so **every** mock scan failed as `citation_mismatch`
and the release path was untestable. Fixtures now cite `#1` and the caller resolves it
against the transcript — an *identifier* only; `exact_quote` is never rewritten.

### Post-review fixes (2026-08-20)

Two real bugs, from a review pass after the runner landed:

1. **`event_id` was never on the scan job.** `ScanRunner` read `$job['event_id']` from a row that
   had no such property, so **every finding would have been written to event 0** —
   `REDCap::getEventNames()` for event 0 yields a name that is either wrong or rejected. Invisible
   in every green run because the review instrument does not exist, so the write never executed.
   Same omission class as `instance`, which *was* caught; the difference is that nothing asserted
   `event_id` survived from `enqueue()` to the finding write. That test exists now, and fixing this
   is what surfaced the redcap_entity ALTER limitation recorded in
   [`stage-1-turn-contract.md`](stage-1-turn-contract.md).

2. **`nextFindingInstance` had a dead branch.** `MAX` over an empty set returns a row containing
   NULL, never no row, so its `$row === null` guard could not fire. It worked, for a different
   reason than its comment claimed. Now `MAX` + `COUNT` in one query, because `MAX` alone cannot
   distinguish an empty record from one holding only REDCap's NULL-numbered first instance —
   `COALESCE(MAX, 0) + 1` returns 1 for both and collides. The residual same-record cross-pass race
   is stated rather than engineered around.

And one design change: **the run row's status now describes the whole attempt.** The predictable
release failure is checked *before* the row is written, so it records `service_error` rather than an
`ok` a reviewer would read as a completed scan. Provenance forces run-row-before-findings, so this
has to be a look-ahead. The residual exception — instrument exists, `saveData` refuses — is the one
place `run_status` and job status genuinely diverge, and is why **Stage 5's session view must show
job status and `last_error` beside `run_status`** rather than the run row alone.

### Verified

`scripts/verify-safetyscan.php`: **PASS** — all six fixtures driven through seed →
finalize → real cron pass → assert, on the live instance:

| Fixture | run_status | job | released |
|---|---|---|---|
| `no_supported_concern` | `ok` | `ready_for_review` | nothing, correctly |
| `self_harm_critical` | `service_error` | `manual_review_required` (first attempt — terminal) | nothing; names the missing instrument, verbatim output still preserved |
| `fabricated_quote` | `citation_mismatch` | `manual_review_required` (first attempt) | **nothing** |
| `unable_to_assess` | `refusal` | `manual_review_required` (first attempt) | nothing |
| `content_filter` | `content_filter` | `manual_review_required` (first attempt) | nothing |
| `timeout` | `timeout` | `queued`, backing off | nothing |

**534 unit tests**, phpcs clean. All four live probes green.

## Acceptance checklist

- [ ] **SecureChatAI PR #2 merged** (separate repo — the only open item); Gemini
      returns `structured_output`; `content_filter` surfaced as its own error class.
      Until then `schemaWasSent: false` is recorded on the run row and the failure text
      says it is configuration, not the model.
- [x] Quote verifier rejects every near-miss class; only byte-exact
      substrings of the cited message pass (2026-08-20)
- [x] Verbatim model output preserved on `mica_scan_run` (2026-08-20). Finding
      instances cannot be compared field-for-field until the instrument exists (G5)
- [x] Every failure class lands in the right terminal/retry path; no negative
      screens (2026-08-20 — six branches verified live)
- [x] `scan-mock-mode` E2E path works without network (2026-08-20)
