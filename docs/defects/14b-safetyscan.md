# 14b — Live defects: SafetyScan (D23–D25)

Part of [14 — Live defects](14-live-defects.md). Section A of the audit, second half: the post-session safety scan.

### D23 — The provider rejects the SafetyScan schema: **no post-session scan can ever succeed** on `gpt-5-6-sol`

**Severity:** blocking (safety-critical — the scan is the mechanism that surfaces self-harm
disclosures for review, and it fails 100% of the time)
**Status: FIXED 2026-09-28** (working tree, not yet committed or deployed) — reproduced through the
participant path, fixed at send time, re-verified the same way. See "Fix and verification" below.
Found 2026-08-25 by capturing the outgoing body; see `20-llm-request-capture.md`.

The pinned output schema uses `uniqueItems`, and Azure OpenAI structured outputs does not allow it.
Every SafetyScan call returns HTTP 400 before the model sees anything:

```json
{"error":{"message":"Invalid schema for response_format 'response': In context=('properties','findings','items','properties','recommended_actions'), 'uniqueItems' is not permitted.","type":"invalid_request_error","param":"response_format"}}
```

`uniqueItems: true` appears twice in `handoff/MICA_safetyscan_postsession_model_output_schema.json`
(lines 102 and 118 — `recommended_actions` and `recommended_notification_targets`) and four more
times in `handoff/MICA_safetyscan_notification_policy_schema.json`. The provider reports the first one
it reaches, so removing only line 102 will move the error, not clear it.

#### Where it lands, traced through the code

The fail-safe design holds — the failure is visible as a task, it is never mistaken for a clean
screen — but it costs nine rejected provider calls per session and names the wrong cause:

| Step | Where | Result |
|---|---|---|
| 3 identical requests, all 400 | `SecureChatAI.php:435` (`$retries = 2`) | `callAI()` does not throw; it returns `['error' => true, 'type' => 'NETWORK_ERROR', 'message' => 'Error after 2 retries: HTTP error: 400 (response body omitted; length=…)']` |
| the caller reads that error | `SecureChatSafetyScanCaller.php:115-125` → `classifyErrorText()` | no `timeout`/`content_filter`/`refusal` needle matches, so it falls through to **`service_error`** |
| the state machine classifies it | `ScanJobStateMachine.php:59` — `service_error` ∈ `TRANSIENT` | job **requeued** with 60s, then 240s backoff |
| attempts run out | `safetyscan-max-attempts`, unset on PID 257 → default **3** | job → **`manual_review_required`**, which is a visible task and correctly not a negative screen |

So one completed session burns **3 job attempts × 3 `callAI` tries = 9 rejected requests** over about
five minutes before a human is asked to read the transcript by hand. Every session, forever, on every
project pointed at this alias.

#### Why nothing had noticed

The `service_error` classification is the problem: it says *transient*, and this is the opposite of
transient. Retrying a schema the provider will never accept is a guaranteed-loss loop, and the rows
left behind invite exactly the wrong diagnosis — "the AI Hub is flaky," not "our schema is invalid."

The 400's message is the only place the real cause is ever stated, and it is discarded at that one
point on purpose: `executeAPICall` omits the response body from the exception because it can echo PHI.
So the `scan_run` row records `service_error` plus `HTTP error: 400 (response body omitted;
length=…)` — enough to know the provider refused, never enough to learn it was `uniqueItems`. No log
level would have helped.

It surfaced as three byte-identical 8777-byte captures one second apart (`20260825-17040{0,1,2}`),
after a real session was completed through the SPA. Replaying one captured curl returned the 400
above — deterministic, not transient.

The existing verifier does not catch it: `scripts/verify-safetyscan.php` passes because it drives the
mock path (`scan-mock-mode`), which never builds a `response_format`. Any coverage that would have
caught this has to reach the provider with the real schema.

#### Fixing it is not a one-line edit

The schema is hash-pinned. `ArtifactRegistry` verifies the bytes against `handoff/manifest.json` on
every read and throws `ArtifactIntegrityException` on a mismatch, and the hash is recorded per run as
`output_schema_sha256` in `redcap_entity_mica_scan_run`. So a fix is: strip `uniqueItems`, re-pin the
manifest hash, and accept that runs before and after the change carry different schema hashes — which
is the pinning mechanism working as designed, not a problem to hide. Uniqueness would then be enforced
where it can be, in `ScanRunner`'s post-hoc validation (`:248`) rather than by the provider.

Worth deciding at the same time whether `notification_policy_schema`'s four occurrences are ever sent
to a provider or are validation-only; if the former, they fail the same way.

#### Fix and verification (2026-09-28)

Still live on 2026-09-28, a month after it was found: every scan on this instance since then had
failed (jobs 330–334 on PIDs 268 and 271), and the one on 271 had also emailed nobody because the
reviewer role had no users. Found while checking the PI's requirement that a CRC hears about a
critical finding within 5 minutes — see [`31-critical-finding-crc-notify.md`](../topics/safetyscan/31-critical-finding-crc-notify.md).

**Reproduced first**, twice:
- **At the provider** (non-PHI synthetic transcript, the pinned schema, `strict: true`):
  - with the pinned schema, **HTTP 400**: "`'uniqueItems' is not permitted`"
  - with the same schema minus `uniqueItems`, **HTTP 200** and schema-valid output

  So `uniqueItems` is the only keyword it refuses. `minLength`, `maxLength`, `minItems`, `maxItems`,
  `minimum`, `maximum`, `$schema`, `$id` and `title` are all accepted.
- **As a participant** (`e2e/critical-notify.js`, PID 271, record `SCAN5MIN01`): a disclosure of an
  overdose plan for tonight, then End Session. Timeline after End Session:
  - +33 s, +156 s, +456 s: the three attempts, each `service_error`
  - +456 s: `manual_review_required`
  - +457 s: the CRC email, "A session could not be screened and needs manual review"

  The record got only a `scan_failure` placeholder. 7 of 17 checks failed.

**The fix withholds the keyword from the request, not from the artifact**
(`SecureChatSafetyScanCaller::providerSchema()`, `PROVIDER_WITHHELD_KEYWORDS`).
- **The artifact is untouched.** Editing it would have broken the manifest's provenance (its hashes
  are copied from the handoff package) and turned the artifacts launch gate red.
- **The provider's copy:** the caller hands SecureChatAI the pinned schema minus `uniqueItems`, and
  nothing else changes. The prompt fallback for non-OpenAI aliases still embeds the full schema.
- **Uniqueness is still enforced after decoding.** `ScanRunner` validates the answer against the full
  pinned schema, so a duplicated `recommended_actions` entry is still `schema_invalid`.
- **The run row says what was sent.** Its payload records `provider_schema_withheld: ["uniqueItems"]`
  beside `output_schema_sha256`, so the row never names a schema it did not send.

**Re-verified the same way:** records `SCAN5MIN02`, `SCAN5MIN03` and `SCAN5MIN04`, the same
disclosure, 19 of 19 checks each.
- Scan `ok` on the first attempt (5.6–10.4 s at the provider).
- Findings: `self_harm` **critical** ("a specific overdose plan for tonight") plus
  `dangerous_alcohol_use` high.
- CRC email "highest urgency: critical" **42 s, 65 s and 22 s after End Session**. Most of that is the
  wait for the once-a-minute cron.

Unit tests: `tests/Unit/ProviderSchemaTest.php` and three in `ScanRunnerTest`. Also brought into line:
`scripts/safetyscan-payload-sample.php` and [`23-safetyscan-payload.md`](../reference/23-safetyscan-payload.md).

**Deploy before go-live.** Prod runs whatever MICA code it was given. Until this change is there,
every prod session reaches its reviewers as "could not be screened", about 7.5 minutes after it ends.

Two things this does not change:
- **The misclassification is unchanged.** A deterministic 400 is still classified `service_error` and
  retried as transient. It no longer happens for this cause, but a future unsupported keyword would
  again cost three attempts before manual review.
- **The preflight was falsely green.** `scripts/preflight-safetyscan.php` reported **PASS** on 271
  throughout: it never calls the provider, and it counts a mapped reviewer role without checking that
  anyone is in it. The Launch readiness tab's reviewer gate does check that, so use it and a synthetic
  session, not the preflight, as go-live evidence.

---

### D24 — Any project's scan pass took every project's jobs, and scanned and announced them as its own

**Severity:** blocking, and a disclosure: one project's reviewers were told about another project's
participants.
**Status: FIXED 2026-09-28** (working tree). Reproduced on PIDs 271 and 279 first; the before and
after are on the same job.

**The code.** `mica_scan_worker` runs one pass per MICA-enabled project, and each pass is wired with
that project's settings. `RedcapScanQueueStore::claim()` took the oldest due job with
`WHERE status = ? AND next_attempt_at <= ?`, with no project in it. So the first project's pass
claimed everyone's jobs and, for each one:
- **scanned it with its own settings:** its model alias, prompt addendum, thresholds and mock mode
- **notified its own reviewers,** and recorded the notice under its own project
- **made the SecureChatAI call under its own project id**

Transcripts and findings were still read from and written to the right record, because those follow
the job row. That is why the dashboard looked plausible.

**Whether it hit prod 35968 is not established.** It needs a second MICA-enabled project on the same
server, and that query was never run. What prod did show fits it, but doesn't prove it:
- **The model:** job 20's attempts (09-28, 14:48) ran on `gemini-2.5-flash`, the built-in fallback
  for a blank alias. That happens either because 35968's own alias was blank at the time, or because
  another project's pass claimed the job.
- **The traces:** 35968 had no SecureChatAI row and no notice row for the scan. That is expected under
  D24, but it wasn't followed up.
- **The later failures had a different cause:** a mistyped alias, after these fixes were deployed. See
  [31](../topics/safetyscan/31-critical-finding-crc-notify.md).

**Reproduced locally** (271 and 279, both MICA-enabled; 271's pass runs first):
- **The first observation:** a notice for 279's job 343 was stored under **271** and emailed to 271's
  reviewer. Its event, 1216, belongs to 279.
- **The deliberate reproduction:**
  1. 271's alias was blanked, to play prod's other project.
  2. Scan job 344 was queued for 279's record 1 (279's alias is `gpt-5-6-sol`).
  3. Attempt 1 ran on **`gemini-2.5-flash`**: `service_error` in **5 ms**, with SecureChatAI logging
     "Unsupported model: gemini-2.5-flash" **under 271**. Prod's exact shape.

**The fix.** The project is now part of the queue contract.
- `claim()` and `findStaleClaims()` take a project id, and the SQL filters on it.
- `ScanQueue` is built with the project it serves. It throws `LogicException` if asked to claim or
  reap without one, so an unscoped claim can't come back quietly. Enqueue needs no project.
- **Verified:**
  - **The same job, after the fix:** attempt 2 was claimed by **279's own pass** and ran on
    **`gpt-5-6-sol`** in 7.3 s. Its notice was stored under **279** and went to 279's reviewer.
  - **Unit tests:** 6 in `ScanQueueTest` and 1 in `ScanWorkerTest`.

**On prod:** deploy the fix. Also check which other projects have MICA enabled. Any that aren't in use
should have it switched off.

### D25 — MICA's own input escaping made a correct quote fail, and threw the whole scan away

**Severity:** blocking. Real participants type "I'm" and "don't", and a quote containing one could
discard a critical finding.
**Status: FIXED 2026-09-28** (working tree).

**The cause.**
- **Where the escaping happens:** `handleUserInput()` passes every participant message through
  `Sanitizer` (`htmlspecialchars`, `ENT_QUOTES`) before it is logged.
- **What the transcript held:** the escaped copy, "I&#039;m".
- **What the scanner did:** it quoted the participant's real words, "I'm going to take all of them
  tonight".
- **Why it failed:** the quote check is byte-exact and doesn't retry, so the scan ended
  `citation_mismatch`. The critical finding was dropped and the session showed "NOT SCREENED".
- **When it happens:** only when the model decodes the entity. On 271 it had copied the entity, so it
  passed; on 279 (job 343) it decoded it and failed.

**The fix.** `TranscriptBuilder` now undoes exactly that escaping (`htmlspecialchars_decode`,
`ENT_QUOTES`) for participant rows. The transcript holds what was typed, and the check stays
byte-exact.
- **It is an exact inverse, checked.** A participant who literally typed `&#039;` keeps it, and
  Unicode is untouched.
- **MICA's replies are not touched;** they were never escaped.
- **It is safe to display.** The review dashboard renders transcript text as React text, never as
  markup, and there is no `dangerouslySetInnerHTML` in `mica-review/src`.
- **Reviewers see it correctly** too. Until now they saw "I&#039;m".
- **Only new transcripts are affected;** already-finalized ones keep their stored text and hash.
- **Verified:** the runbook's six messages on 271 (`APOS01`) gave `ok` and a critical finding. The
  evidence quotes read "I don't…" and "I've…", and the CRC's email arrived at 65 s. There are 4 unit
  tests in `TranscriptBuilderTest`, including the regression.

**Not changed:** the counselor model still receives the escaped text ("don&#039;t"). That affects
reply quality, not safety.

---
