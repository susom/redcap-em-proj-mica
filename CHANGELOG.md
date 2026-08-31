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

### GPT-5.6 selectable — and it is reasoning-class, not a chat model

New aliases `gpt-5-6-sol`, `gpt-5-6-luna`, `gpt-5-6-terra` in MICA's `llm-model`
dropdown and in both of REDCap Chatbot's. Defaults untouched.

**Two things were wrong on the first pass, both found by checking rather than
reasoning.**

*The names.* I extrapolated `gpt-5-6` + `gpt-5-6-nano` from the 5.4 base/nano
pair. The AI Hub service spec's `deployment-id` enum has no such entries — the
variants are sol/luna/terra. The alias is also the URL path segment, so an
invented name is a 404. `SchemaModelMirrorTest::testTheInventedGpt56NamesAreAbsent`
pins this.

*The parameter class.* I then routed the trio like `gpt-5-4` — ordinary chat
model, `max_tokens`, sampling params — and flagged that the spec could not
confirm it because one request schema covers every deployment. It was wrong, and
a configured counselor produced nothing but `I apologize, but I'm experiencing
network difficulties`: an HTTP 400 that `callAI()` rewrites into a canned
apology, with the response body deliberately omitted for PHI safety, so the log
said only `HTTP error: 400 (length=254 bytes)`. Replaying the same request
outside the module with a non-PHI prompt got the real answer. Verified against
AI Hub 2026-08-24, resolved model `gpt-5.6-sol-2026-07-09`:

| Parameter | Result |
|---|---|
| `max_tokens` | **400** — `Use 'max_completion_tokens' instead` |
| `temperature` ≠ 1 | **400** — `Only the default (1) value is supported` |
| `top_p` ≠ 1 | **400** — `not supported with this model` |
| `frequency_penalty` ≠ 0 | **400** — `not supported with this model` |
| `max_completion_tokens`, `reasoning_effort`, `presence_penalty: 0`, `stop: null` | accepted |
| `response_format` json_schema with `strict: true` | accepted, returns conforming JSON |
| `tools` | accepted |

So the trio joins `o1`/`o3-mini`/`o3`/`o4-mini`/`gpt-5` in the strict branch:
seven lists in `SecureChatAI.php` (reasoning-param allowlist `:393`, strict set
`:405`, agent-mode token param `:936`, compaction `:1805`, Claude-compat endpoint
`:2635`, plus `getModelContextSpec()` and `computeDynamicMaxTokens()` with
`param => max_completion_tokens`). They stay in both `$schemaModels` lists
(`:399`, `:893`) because structured output is confirmed working — which is what
keeps SafetyScan on real `json_schema` rather than the prompt-injected fallback,
and why `SecureChatSafetyScanCaller::OPENAI_SCHEMA_MODELS` still lists them.

Post-fix payload is exactly `{model, messages, max_completion_tokens,
reasoning_effort}`. Verified three ways: the filter logic replayed in isolation,
that payload sent live (HTTP 200), and the participant E2E — **45/45 including
C2 "real reply received (not the provider apology)"**, with the log confirming
every turn served by `gpt-5.6-sol-2026-07-09` and zero errors.

**Pre-existing bug fixed in passing.** Bare `gpt-5` sat in the strict branch
(which sets `max_completion_tokens`) while its `computeDynamicMaxTokens()` entry
said `param => max_tokens`, so `callLLMOnce()` re-added `max_tokens` and Azure
rejected the request for carrying both. `gpt-5` could never have worked. The
o-series entries were already correct; only `gpt-5` was wrong.

**Settings labels corrected.** `GPT Temperature`, `Top P`, `Frequency/Presence
Penalty` and `Reasoning Effort` now say which model classes they actually affect
— for a 5.6 counselor the four sampling settings are inert, and the reasoning
verifier (`verify-settings.php`) no longer reports `reasoning-effort` as inert
for them.

**Cost reporting.** Six alias rules (dotted and dashed per variant) above the
`/^gpt-5/` catch-all, which would otherwise have billed 5.6 at 5.2 rates
silently. All three share one `gpt-5.6` key whose rate is **provisional,
borrowed from 5.4** — AI Hub publishes TPM thresholds, not dollars. Three
distinct models sharing a placeholder, not a verified tier; split it when rates
publish.

**api-version.** The spec accepts only `2024-10-21` or `2025-04-01-preview`. The
new rows use the latter. Every pre-existing Azure row is registered with
`2024-06-01` or `2024-12-01-preview` — outside that enum — and was left alone
rather than silently rewritten.

**1018 PHP tests**, 82 review-UI tests, 45/45 participant E2E, phpcs + eslint clean.

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

**Ending a session closes it too.** `completeSession` now writes the same form
status, closing the re-entry gap in `18 §10 A6` gate 3: Repeat Survey is on for both
hosts, and nothing server-side stopped a finished participant reopening their link
and appending a second conversation to a session already finalized and scanned. It
never throws — their transcript is already safe by that point, and turning a status
write into "your session could not be saved" would be a lie that costs them their
ending.

**Every host link now gets an expiry.** `survey_time_limit_*` was configured on both
hosts and did nothing, because `checkSurveyTimeLimit()` allows access whenever
`link_expiration` is empty and `getSurveyLink()` leaves it NULL. The cron fills it
in, once, with `link_expiration_override = 1`. That is what bounds a session the
closer will never touch — opened and never used, so no first message, so no window.
Between the two mechanisms every session is bounded. The anchor is a stated
compromise: nothing records when a module-minted link was issued, so a row first
seen without an expiry gets `now + the survey's own limit`.

Four defects the live run found that no unit test would have:
`REDCap::getEventNames()` throws outside a project context, so cron needs `$Proj`;
the host surveys have *Repeat Survey* enabled but are **not** repeating instruments,
so passing `redcap_repeat_instrument` made `saveData` reject the write with
`item_count 0`; and `queryLogs` cannot filter on the message column, so the
close-once guard needs `log_type` as an explicit parameter — without it a reopened
session was closed again on the next pass; and `link_expiration` is a `DATETIME`, so
comparing it to `''` is rejected under strict mode and took the whole query with it.

The participant E2E is now **45 checks**: mobile moved ahead of End Session (it was
typing into a session that correctly no longer has a composer), and `E9`/`E10` pin
that a finished session cannot be re-entered from the same link and says so in
words.

### The validated SafetyScan prompt is now visible in the module's settings

`safetyscan-prompt-addendum` asked an administrator to write text that is appended to
a prompt they had never been shown. The instructions in force were a file in
`handoff/`, readable by whoever had a shell on the server and by nobody else, while
the setting's own label described them in prose and told the reader not to contradict
them — which is not something you can do blind. The prompt now renders **read-only,
in the configuration dialog, directly above the field that appends to it**, collapsed
by default, with the artifact's full SHA-256 beside it.

It is rendered from `ArtifactRegistry`, not from `config.json`. Pasting 4.4 KB of a
hash-pinned artifact into a `descriptive` setting's `name` would have created a second
copy with no pin on it, free to drift from `handoff/manifest.json` the moment either
side was edited and displayed with equal confidence either way — the exact failure the
registry exists to prevent. `config.json` therefore contains only an **anchor**: a
`descriptive` setting that reserves the position, whose label
`redcap_module_configuration_settings()` replaces with the verified artifact at render
time. So the dialog cannot show a prompt other than the one a scan would send, and if
the hook ever stops running the fallback text says so in place of the prompt rather
than showing stale text. `PinnedPromptViewTest` pins the anchor's key, its type and
its position from both ends.

Two things it refuses to do. A hash mismatch renders a red warning and **no prompt
text**: bytes that failed their pin are not the validated prompt, and showing them as
if they were is worse than showing nothing — the same read is failing inside
`ScanRunner` at that moment, so the notice says scans are down rather than implying a
display glitch. And nothing escapes: `manager/ajax/get-settings.php` is a JSON
endpoint whose output *is* the whole settings dialog, so an uncaught throwable there
would leave an administrator with no editable configuration at all. Every failure is
caught and rendered as content.

The addendum's own label lost the paragraph the panel now covers and points up at it
instead. The escaping is not decorative even though today's artifact contains no
HTML-special character: `get-settings.php` explicitly declines to escape the config
("It breaks HTML in module setting names") and `globals.js` interpolates the label
directly, so the next re-pinned prompt containing a `<` would otherwise break the
dialog's markup. `ENT_SUBSTITUTE` is there for a quieter version of the same thing —
without it, invalid UTF-8 makes `htmlspecialchars()` return `''`, and the endpoint's
`JSON_PARTIAL_OUTPUT_ON_ERROR` would hand the dialog a blank panel and no error.

New E2E: `e2e/module-config.js`, 43 checks — 20 × desktop and mobile plus a save
round-trip — signed in as a **design-rights** user rather than an admin, because
`hasProjectSettingSavePermission()` short-circuits to `true` for a super user and so an
admin run cannot tell you whether an ordinary study designer can open the dialog at
all. It exists because `descriptive` is a client-side-only setting type: whether a
`<details>` nested inside a `<label>` still toggles is not answerable from PHP. Its
width check is *differential* — REDCap's own settings modal is 866px in a 390px
viewport and clips every setting equally, with the panel, without it, and with the
whole table hidden, so an absolute assertion there fails for a reason the module cannot
cause.

The save path is checked in both directions, because adding a key to the section is
exactly the change that could break saving for its neighbours. `C21`–`C23` type an
addendum, save, confirm it round-trips and that the panel still renders afterwards, and
restore the original value. The other direction is a residue check in
`verify-settings.php` §10: a display-only setting must never own a stored row, since
REDCap's save path reads `$_POST[$key]` for every declared key and a permanently-empty
invisible row is the same shape as the three orphaned twilio rows this project already
had to clean up. That check was written, planted with a row, and found to report
all-clear — `getModuleDirectoryName()` returns `proj_mica_v9.9.9` while
`directory_prefix` holds `proj_mica`, so the join matched nothing and the check passed
for every input. It now uses `$module->PREFIX` and fails on a planted row.

### Day-1 flow rebuilt: ID split from demographics, and MICA reached after TSR

The PI reported the Day-1 flow was out of order and MICA never loaded. Three changes, all on the
project rather than in the module except where noted.

**`baseline1` split.** It carried contact/identity fields and the demographics questions together.
The contact fields moved to a new `contact_info` instrument placed straight after consent; the three
hidden fields went with them, and that is not cosmetic - `calcrnd` is the passcode `check_code`
validates, so it has to be generated before check code runs. `baseline1` keeps its form name (two
alert conditions test `baseline1_complete`, and renaming it would break them quietly) and is
retitled "Demographics". Alert 01 was retargeted to the new form. Verified: participant data
survived the move, and survey login still resolves - it is keyed on the field, not the form.

**Order and auto-continue.** Six instruments had auto-continue off, which is why the chain stopped.
The Day-1 journey now walks continuously: consent → person_obtaining_consent → contact_info →
check_code → sms_code_check → baseline1 → the battery → tsr. `person_obtaining_consent` keeps its
break on purpose; it is the staff consent-witness signature.

**MICA after TSR — and a correction.** The first attempt was to move `mica_ed_session` after `tsr`
and let auto-continue reach it. **That cannot work, and REDCap's own resolver says so:**
`getAutoContinueSurveyUrl` walks only `$Proj->eventsForms[$event_id]` (`Survey.php:2702`), the whole
participant journey runs at the arm-1 Day-1 event, and `mica_ed_session` is designated to the
intervention arms' own events. Measured: `tsr @ 1004 → close`, MICA skipped. Auto-continue cannot
cross events, so the redirect is the mechanism after all - `tsr`'s *Redirect to a URL* now pipes
`[ed_session_url]`.

That put a Standard Care participant in a worse place than before: no session, but not finished
either, and the handoff page would have told them "you have finished" while the study's own closing
survey and its gift-card wording sat one step away. So the new `session-fallback-instrument` setting
names a survey (`close` on PID 257) to send them to instead, exactly where auto-continue would have.
The guard in `writeSessionUrl()` had to widen with it: the fallback is now an ordinary `?s=` link, so
"is this value ours to replace" resolves the hash against `redcap_surveys_participants` for this
project rather than only recognising the handoff page - otherwise a participant randomized after the
fallback was written would never receive their session link.

Verified in a browser end to end: a randomized arm-2 participant submitting TSR lands on **MICA ED
session**; an unrandomized one lands on **Close**.

Two things found and deliberately left alone, both study decisions: `postsession` (CEMI post-session)
has **no survey row at all**, so a participant cannot reach it once MICA ends; and `tsr` is
designated to all twelve events while `ed_session_url` lives on `admin` (Day-1 events only), so the
pipe is empty at Month 3/6/12 - out of scope for the Day-1 testing this was for.

### The ED Day-1 session link is minted at randomization and handed to the participant

Two changes, one flow. When a record is randomized, the module resolves which arm's event hosts
that record's Day-1 session, mints the survey link, and writes it to `ed_session_url` on the
`admin` form. When the participant finishes the arm-1 screening chain, the handoff survey's
*Redirect to a URL* pipes that field and drops them straight into their own session.

**Why the URL has to be stored rather than expressed.** The session instrument is designated to
one event *per intervention arm*, so there is no static expression for "this record's session".
The obvious shortcut is REDCap's `[survey-url:mica_ed_session]`, and it is worse than useless
here: piped from an arm-1 survey it returned an ordinary-looking URL and **created a new
participant row at event 1004**, where the instrument is not designated. `getSurveyLink()`
(`REDCap.php:1740-1745`) checks that the instrument is a survey project-wide and that the record
exists in the **arm of the event it was given** — never that the instrument is designated to that
event. Follow that through for a Standard Care record, which exists in arm 1 by definition, and
the control participant is handed the intervention. So the arm is resolved per record, by
`EdSessionLink`, which returns `no-session-in-arm` as an explicit outcome rather than letting "no
event found" fall through — and `EdSessionLinkTest` asserts from both directions that a resolved
event is always inside the assigned arm. Verified live: a Standard Care record gets no URL and
**no participant row is even created**.

**The ordering works out natively, which is the nice part.** REDCap's own auto-randomization
trigger fires at `DataEntry.php:6710` and `redcap_save_record` at `:6735` — 25 lines apart, inside
the same `saveRecord()`. So on the participant's own BL-survey submit, in one request: REDCap
randomizes, the existing hook materializes the arm, and the link is minted. Nothing polls and
nothing waits for a CRC. One trap, and the verifier fails loudly on it:
`Randomization.php:3112` skips **trigger option 1** on survey pages, so a setup that randomizes
perfectly for a CRC does nothing whatsoever for a participant, with no log line. It must be
option 2.

**An empty field was a blank screen, not a no-op.** REDCap's redirect is all-or-nothing: the guard
at `Surveys/index.php:1833` tests the template *before* piping, so `[ed_session_url]` piping to
`''` still reaches `redirect('')`. Measured in a browser: `302` with `Location:` empty and a body
of **zero bytes** — a participant who had just finished screening saw nothing at all. So the field
is now never empty for a record that can reach the end of the chain: `pages/sessionHandoff.php`
(no-auth) carries a waiting message for "not randomized yet" and a plain completion message for
"no session in this arm". Neither message names an arm, deliberately — telling a Standard Care
participant they have no session tells them which arm they are in, and `session-handoff.js` greps
for that rather than trusting it.

Two settings, both with the field-existence-is-the-switch shape this project already uses:
`ed-session-url-field` (blank = `ed_session_url`; no such field means the feature is off and
silent) and `stamp-randomization-date-field` (blank = off). The second exists because automating
randomization takes the CRC off the form that carried `randomization_date` by hand, and that date
anchors **alerts 02-14**; it is only ever written when empty, so a human's value is never
overwritten.

The write refuses rather than clobbers. `writeSessionUrl()` will overwrite its own handoff
fallback with a real link, but never anything else — a real session link must not be replaced by a
fallback, and a value a human put there is not the module's to take. The field ships `@READONLY`
for the same reason: a survey redirect follows whatever is in it.

`verify-ed-session-link.php` checks all six links separately so a broken chain names its own
cause, including a database check for session links sitting at events that do not host the
session. `backfill-study-group-arms.php` gained the link write, because the hook fires only on UI
saves and survey submits — an imported randomization would otherwise sit in the right arm with an
empty field.

### The SafetyScan request body is now inspectable without a live scan

`safetyscan-payload-sample.php` prints the exact body a scan sends — the composed system
prompt, the canonical transcript, and the wrapped `response_format` — at all three
levels: what MICA hands to `callAI()`, what SecureChatAI's parameter filter leaves, and
the bytes on the wire. Snapshot and commentary in
[`23-safetyscan-payload.md`](docs/phase-3-handoff/23-safetyscan-payload.md).

The two existing options were both bad. Arming `capture-llm-payload.php` on a live scan
writes PHI to disk, and its `shape` mode covers the counselor path only and deliberately
prints no prompt text. Hand-writing a sample starts drifting the moment the prompt is
re-pinned or SecureChatAI's filter changes. So every value is derived from live code —
four private methods reached by reflection, including `ScanRunner::resolvePrompt()` so
the addendum delimiters are never retyped — and the transcript is **synthetic and
validated against the pinned input schema**, because the request body *is* the
transcript. One step is replicated rather than called and says so in both the script and
the doc: `GenericModelRequest::sendRequest()` wraps `json_schema` into `response_format`
inside the method that performs the HTTP request, so it cannot be invoked without calling
the provider.

Three things it makes visible that reading the code does not. PID 257 runs
**`gpt-5-6-sol`, not Gemini** — that alias *is* in SecureChatAI's `json_schema`
allowlist, so `response_format` is genuinely sent and the append-the-schema-to-the-prompt
fallback does not fire on this project today. No sampling parameters reach the call at
all: a reasoning alias makes `filterDefaultParamsForModel()` discard the merged
parameters and rebuild from four keys, so there is no `temperature`, `top_p`, penalty or
`stop` in the body. And `reasoning_effort` comes from SecureChatAI's own system setting,
not MICA's project-level `reasoning-effort`, because the SafetyScan caller passes only
`messages` and `json_schema` — the project setting belongs to the counselor path.

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
