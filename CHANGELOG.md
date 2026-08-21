# Changelog

## Release lines

| Line | Ref | What it is |
|---|---|---|
| **Pilot** | tag `pilot-final` | The pilot study runs from here. Frozen. |
| **R01 / phase 3** | branch `mica-phase-3` | Active development. Not deployable to the pilot. |

`pilot-final` — annotated tag `d98b41302644c477c4c886cb31e49f13a54e31a1`,
pointing at commit **`5e5607336239a7960de412bbfb13addb101d7f0a`**
(`main`, 2026-07-14, "security fixes for axios"), pushed 2026-08-19.

Pilot deployments pin to that tag from now on. A pilot hotfix branches *from the
tag* and is cherry-picked forward into `mica-phase-3`, never the other way
round: phase 3 changes the turn contract, the session engine and the login
mechanism, and none of those belong in a running pilot.

---

## Unreleased — `mica-phase-3` (R01)

Plan and rationale: [`docs/phase-3-handoff/`](docs/phase-3-handoff/README.md).
Stage tracker: [`06-implementation-plan/README.md`](docs/phase-3-handoff/06-implementation-plan/README.md).

### Stage 0 — Foundations

- **0.1** Pilot pinned at `pilot-final` (above). No pre-existing tags in the
  repo, so until now nothing pinned the pilot at all.
- **0.2** Handoff artifacts vendored into `handoff/` — the nine runtime
  artifacts from `MICA_Stanford_IT_Handoff_Package` (2026-07-25), each verified
  against the package's own SHA-256 manifest at copy time (9/9 matched, plus the
  clinical-review workbook which is not vendored; 10/10 of the package's hashed
  files verified).
- **0.3** `ArtifactRegistry` — every read recomputes the SHA-256 and refuses to
  return anything on a mismatch, a missing file, an unpinned file in the
  directory, or a malformed manifest. `getHash()` returns the hash actually
  computed, since that value is what later stages persist onto turn and scan
  rows.
- **0.4** `SchemaValidator` over `opis/json-schema` (draft 2020-12 native), with
  `SchemaValidationResult`. Invalid data is a result, not an exception — both
  pipelines branch on it; only an unusable schema throws.
- PHPUnit toolchain (part of 0.5): `phpunit.xml`, framework-free
  `tests/bootstrap.php`, PSR-4 autoloading for `Stanford\MICA\`, fixtures.
  **60 tests green on PHP 8.4 (host) and 8.3 (the container REDCap runs on).**

**Dependency layout (decided 2026-08-19):** `vendor/` is committed and deploys
with the module, built without dev dependencies, and `MICA.php` requires it
unconditionally again. The test toolchain lives in `tools/` rather than in
`require-dev`, so the module has no dev dependencies at all and a test framework
cannot reach production by way of a forgotten flag —
`composer test:install` populates `tools/vendor/` (gitignored). Composer resolves
against `platform.php = 8.2` rather than the developer's PHP.

`php-ml` and `twilio/sdk` were dropped: 2,598 files and 17.5 MB with zero
references anywhere in the codebase. `vendor/` is 235 files / 1.3 MB.

### SecureChatAI conformance — SOW "update API calls, request/response handling"

The three items `13 §7` listed as *must change for correctness* / *leaves value on
the table*, scoped from [`18-sow-status-review.md`](docs/phase-3-handoff/18-sow-status-review.md) §5.

- **A provider failure is no longer stored as counselor speech.** `callAI()` never
  throws; it rewrites a failure as an assistant message carrying a canned apology,
  which is shape-indistinguishable from an answer — so it was being written into
  the participant's transcript as words MICA said, and that transcript is what
  SafetyScan analyses. The apology still reaches the screen; the stored row has its
  counselor text emptied and carries a `provider_error` block instead
  (`classes/ProviderFailure.php`). `TranscriptBuilder` and `MICAQuery::getLogsFor()`
  already dropped an empty-content turn, so the failed turn now takes a path that
  was written for it.
- **`session_id` is sent, so provider turn rows exist at all.** Without it
  `logConversationTurn()` returns early and MICA's conversations produced **zero**
  turn rows. The value is the **session pseudo id** — the same salted digest the
  scan path derives, so a counselor turn and its SafetyScan run group under one
  identifier. Verified live: turn rows now carry
  `session_id 2b8490313901f2765cdc6ba29a247054`, and the scan of that session
  reports the same `session_id_pseudonymous`.
- **`$username` is passed** when a REDCap user drives the chat. On the participant
  path it stays null, deliberately: a participant holds no REDCap account, and
  synthesizing one — or passing the record id — would put a direct identifier into
  another module's audit log.

The failure heuristic (`model === null && usage === null`) is now defined once and
shared with `SecureChatSafetyScanCaller`. It is sound only because MICA never opts
into agent mode; that reasoning is in the class docblock, along with what has to
change if MICA ever does.

**981 PHP tests** (10 new, including a regression guard that asserts the *old*
behaviour so a bypass upstream would fail it), **43/43 participant E2E**, lint clean.

### Session windows — an hourly cron that closes a session when its time is up

New: `close-expired-sessions` (off by default), `ed-session-window-hours` (24),
`booster-session-window-days` (14), and the `mica_session_closer` cron. Closes the
first of the three enforcement gates that
[`18 §10 A6`](docs/phase-3-handoff/18-sow-status-review.md) listed as unowned after
the R01 session engine went out of scope.

**The clock starts at the participant's first message**, per session type. A session
that was opened and never used therefore has no window and is never closed by the
cron — REDCap's own link time limit is what bounds those, and the closer counts and
reports every session it skipped so "nothing closed" and "nothing needed closing"
are never the same silence.

**Closing means the host instrument's form status becomes Complete**, and the module
refuses session entry on that field. It is the control surface, not REDCap's
enforcement: writing `<form>_complete` does not set
`redcap_surveys_response.completion_time`, so REDCap would still serve the survey.
A CRC reopens a session by setting the form status back to Incomplete on the record
page — and the cron closes each session **once** and never re-closes it, so that
action is not undone on the next pass.

**Order of operations is the safety-critical part.** For a session the participant
abandoned without pressing End Session, closing is the last moment its conversation
can still be screened — so the cron finalizes the transcript and queues the scan
*first*, and if that fails it leaves the session open and logs loudly rather than
marking Complete. A session that reads as finished but was never screened is the one
state this pipeline exists to prevent.

Three defects the live run found that no unit test would have:
`REDCap::getEventNames()` throws outside a project context, so cron needs `$Proj`;
the host surveys have *Repeat Survey* enabled but are **not** repeating instruments,
so passing `redcap_repeat_instrument` made `saveData` reject the write with
`item_count 0`; and `queryLogs` cannot filter on the message column, so the
close-once guard needs `log_type` as an explicit parameter — without it a reopened
session was closed again on the next pass.

### `safetyscan-prompt-addendum` — study guidance appended to the analysis prompt

New project setting carrying extra instructions for the post-session safety
analysis. It is **appended to** the hash-pinned prompt the research team validated,
never substituted for it. Blank — the normal state, and where PID 257 is left —
sends the pinned artifact byte-identically.

Appending, not replacing, is what makes it safe: the validated prompt is always sent
in full, so the two properties the 120-case suite established are still instructed
by the text the research team wrote. Because that prompt's own last line is *"Return
only the JSON object required by the schema"*, study text appended after it would
otherwise be the last thing the model reads — so the addendum goes inside a labelled
block and the output and verbatim-quote contracts are **restated after it**, with
precedence stated explicitly. An addendum can compete for the model's attention; it
cannot remove a contract, and none of the app-side gates depend on the prompt anyway.

The load-bearing part is provenance. `ScanRunner` used to read the prompt text
(`getText`) and its hash (`getHash`) as two independent registry calls, so composing
in the first alone would have left every run row recording the *bare pinned* hash
while the model was sent something longer. The prompt is now resolved once,
memoized, into text + sha256 + source + addendum hash, and both the model call and
the run row use that one resolution. So a run row always says exactly what produced
it: `prompt_sha256` is the hash of the composed prompt, and `prompt_source` plus
`prompt_addendum_sha256` are written into `model_output_json` only when an addendum
was used — absent means the validated prompt unmodified, the same convention
`schema_in_prompt` already uses. In the payload rather than a new column because
`redcap_entity` cannot ALTER an existing type.

`verify-settings.php` reports which prompt is in force either way.

### Chatbot cleanup (0.6, partial — see 18 §5.1)

- **The Twilio credentials are gone.** `twilio-sid` / `-auth-token` / `-from-number` removed
  from `config.json`, the commented-out `sendSMS()` deleted, and the three orphaned rows
  purged from `redcap_external_module_settings` — undeclaring a setting leaves its value in
  the database, invisible in the UI, which is worse than leaving it declared.
  `verify-settings.php` §9 now checks for the orphan rather than the unused value.
  **Owed by a human:** rotate the token in Twilio, purge the rows on any other instance
  where this module was enabled.
- **`formatResponse()`'s `choices[0]` fallback deleted** — unreachable, and wrong if reached.
- **The pilot code paths were deliberately NOT deleted.** Decision 5 conditions that on
  Stage 2, which is out of scope; `getSystemContextForRecord()` is the only implementation of
  the session-completed / study-completed gates, so deleting it would turn a gap into a
  blank page. Reasoning in [`18 §5.1`](docs/phase-3-handoff/18-sow-status-review.md).

### Earlier phase-3 work on this branch

Landed through the live-defect pass ([`14-live-defects.md`](docs/phase-3-handoff/14-live-defects.md))
and the auth implementation rather than the stage sequence — see the "State as of
2026-08-19" section of the stage tracker for which stage each piece belongs to:

- `llm-model` values validated against the SecureChatAI registry instead of
  silently answering every turn with a provider apology (D1).
- Participant identity derived server-side on the no-auth ajax surface; no
  user-supplied id, no user input in `filterLogic` (D2, D3).
- Native Survey Login scoped to the two MICA host surveys on PID 257.
- Fail-closed `REDCap::saveData()` checks in the OTP and session-close paths
  (D11, D16); model context rebuilt on restore (D8); the send button no longer
  hangs on an empty context (D7, D22).
- Automatic arm placement from `study_group`
  ([`15-arm-materialization.md`](docs/phase-3-handoff/15-arm-materialization.md)).
