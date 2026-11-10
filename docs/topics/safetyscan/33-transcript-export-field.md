# 33. Session transcripts in a REDCap instrument, for utterance-level coding

Status: **built and verified locally on PID 271, 2026-11-10; merged into `mica-phase-3`. Not on prod.** See [Prod steps](#prod-steps).

## The request

- Brian (2026-11-09): the team highlights MICA sessions in the Safety Scan review page and pastes them into a Box
  folder, so they can code each participant and MICA statement. This is error-prone.
- Carl: if the transcripts are in a field the REDCap API can read, he will handle the rest.
- The highlighted statements (the ones the Safety Scan flagged) must be included.

## Why it was manual

- Transcripts live only in the EM log (`mica_transcript` rows, hash-pinned). Reports and the API cannot read the
  EM log.
- The review page shows only the **latest** transcript of a session. A participant who returned after End Session
  has a second transcript that holds only the new messages, so a copy made from the page loses the first part.

## What was built

### The instrument: `mica_transcript` (MICA Transcript)

- **Repeating, one instance per statement**, on the session events (Day 1 (ED) and Month 3, arms 2 and 3). A report
  or API export gives one row per statement, ready for coding in a spreadsheet.
- **Staff-only.** It is not a survey, so no participant ever sees it.
- **`@READONLY` on every field.** The module writes it.
- Files:
  - Upload ZIP: `docs/phase-3-handoff/dictionary/mica_transcript_instrument.zip`
  - The same rows as CSV: `docs/phase-3-handoff/dictionary/mica_transcript.csv`

| Field | Type | Content |
| --- | --- | --- |
| `tx_session_type` | text | `baseline` (Day 1 ED) or `booster` (Month 3) |
| `tx_seq` | integer | Statement order within the session, from 1 |
| `tx_message_id` | text | `L<log id>`, the ID the Safety Scan cites. Blank on a no-reply row |
| `tx_speaker` | dropdown | `participant`, `mica`, `no_reply` |
| `tx_timestamp` | datetime (Y-M-D H:M:S) | Local server time |
| `tx_text` | notes, **Identifier** | The statement, verbatim |
| `tx_flagged` | yes/no | The Safety Scan highlighted this statement |
| `tx_flag_quote` | notes, **Identifier** | The exact highlighted words |
| `tx_flag_concern` | text | Concern type: the reviewer's correction if set, otherwise the scan's |
| `tx_flag_urgency` | text | Urgency: the reviewer's correction if set, otherwise the scan's |
| `tx_flag_review` | text | `pending`, `confirmed`, `dismissed`, `needs_second_review` |
| `tx_flag_finding_ids` | text | `finding_id` on MICA Safety Finding |
| `tx_transcript_ref` | text | The finalized transcript the statement came from (`T<log id>`) |

When several findings cite one statement, each `tx_flag_*` column lists them in the same order, separated by ` | `.

### The copy: cron `mica_transcript_records`, every 15 minutes

Code:

- `classes/TranscriptRecordBuilder.php`: the rules. Pure; unit-tested in `tests/Unit/TranscriptRecordBuilderTest.php`.
- `classes/TranscriptRecordDiff.php`: what changed. Also pure.
- `classes/RedcapTranscriptRecordSync.php`: REDCap reads and writes.
- `MICA.php`: `micaTranscriptRecordsCron()` and `syncTranscriptRecords()`.

Behavior:

- **Uploading the instrument is the switch.** A project without the instrument is skipped. A project with only some
  of the fields gets an error, never a partial copy.
- **Each pass rebuilds every session from the log and the findings, and writes only what changed.** That one pass
  covers all of these, with nothing hooked into those code paths:
  - sessions finalized before the instrument existed (the backfill);
  - continuations and re-finalizations;
  - findings that arrive after the transcript;
  - reviewer decisions.

  An unchanged session writes nothing, so REDCap's logging is not flooded.
- **Every part of a session is included.** All of a slot's transcripts are combined, deduped by `message_id`, and
  ordered by log id. Findings come only from transcripts that no re-finalization replaced, and from each one's
  latest scan run.
- **Failed turns are visible.** A participant message with no MICA reply after it is followed by a `no_reply` row
  that reads "[No MICA reply was logged for the message above.]". The row text states only that fact, because what
  the participant saw instead isn't logged and depends on the failure: a provider failure (for example the AI Hub
  content filter) returns SecureChatAI's canned apology ("...experiencing network difficulties"), and other
  failures may show something else. A retried message appears twice, because that is what happened.
- **It is safe:**
  - Saves use `bypassAlertAsiTrigger` and no auto-calcs, so they fire no alert, ASI or calculation in the Day 1
    or Month 3 events.
  - A deleted record is never recreated: jobs and log rows survive record deletion, and the copy checks that the
    record exists.
  - **A reused record ID does not inherit the deleted record's sessions.** REDCap's auto-numbering can give a deleted
    record's ID to the next record, and MICA's message log isn't deleted with the record. The finalizer then stores
    the new record's first session as a continuation of the old one. The copy leaves out every message logged
    before the record's latest deletion (event `DELETE` on `redcap_data` in the project log). Found 2026-11-10:
    record 1 on PID 271 was deleted and recreated, and its form opened with the deleted record's 2026-09-22 chat.
  - Statements that no longer belong are **deleted** with `REDCap::deleteRecord(pid, record, null, event,
    'mica_transcript', instance)`. Blanking every field instead leaves the instance behind as an empty
    "Incomplete" instance.
  - A transcript that fails its hash check skips the whole session; a coder never gets a session with a hole in it.
- **Size is not an issue.** Each statement is at most 12,000 bytes (the transcript schema's limit). That is far
  under REDCap's 64 KB per value, and there is no per-session blob.
- **To run it now** instead of waiting for the cron:
  `php docs/phase-3-handoff/scripts/sync-transcript-records.php <pid> [record]`, or `--cron` for the cron method.

## Local verification (PID 271, 2026-11-10)

MICA v9.9.9 was enabled for the run. Local is back on v9.9.8 (PR #14) afterwards.

| Check | How | Result |
| --- | --- | --- |
| Rules | `tests/Unit/TranscriptRecordBuilderTest.php`: single, resumed, corrected, corrected-resumed, highlights, reviewer correction, scan-failure placeholder, no-reply, verbatim text, diff | 10/10 pass, lint clean |
| Prod steps 1-3 through REDCap's UI | `e2e/transcript-instrument.js`: ZIP upload in the Online Designer, designation on 4 events, repeating on 4 events | 11/11 PASS; DB confirms Identifier on `tx_text` and `tx_flag_quote` |
| Nothing happens without the instrument | Sync before upload | `no_instrument`, nothing written |
| Backfill | Sync after upload | 13 sessions, 96 statements. 48 participant + 38 MICA = 86, the transcripts' `message_count` total. 10 no-reply rows |
| Idempotent | Cron method again | "nothing to do" |
| No side effects | Alerts sent/queued and ASI queue before vs after | 5/5/0 before and after |
| Live session and highlights | `e2e/critical-notify.js` on record TXEXP1110 (two turns, End Session, real scan, critical). Then sync | 4 statements. L4633 flagged `self_harm \| dangerous_alcohol_use`, `critical \| high`; L4629 flagged `dangerous_alcohol_use`. Matches the two findings' evidence exactly; every highlighted quote is a substring of its statement |
| Reviewer decision flows through | Set the finding to confirmed with corrected urgency high, then sync | 1 statement rewritten: `confirmed \| pending`, `high \| high` |
| API export | `content=record&forms[]=mica_transcript` with a token | 96 rows, one per statement, all columns |
| Identifiers | Same export with "Remove all tagged identifiers" rights | `tx_text` and `tx_flag_quote` left out |
| Report | `e2e/transcript-report.js`: report builder, then the report page (desktop and iPhone 13), then API `content=report` | 100 rows, all statements, 2 flagged (report 2639 on PID 271) |

Caveats:

- The CRC email part of `critical-notify.js` failed (4 checks). Its setup helper `e2e-crc-reviewer.php` crashes on
  the EM framework's "permission to save project settings" check, so the reviewer list stayed empty. That is
  unrelated to this change.
- PID 271's older findings point at scan runs that `verify-safetyscan.php` deleted (it clears the scan tables), so
  they cannot be matched to a session. On prod the runs are kept.

What I learned building the report (in the prod steps):

- With the default "Show data for all events or repeating instruments/events for each record returned" ticked,
  the report also returns one record-ID-only row per record and event (51 extra rows here).
- The filter `[tx_seq] <> ""` alone still left 29 extra rows. Unticking that option removes them.

Left on PID 271:

- the instrument;
- record TXEXP1110;
- the "MICA transcripts" report (2639).
- 10 no-reply rows that still carry the first, longer wording. The next sync under v9.9.9 rewrites them, since the diff sees the text change.

The test user, its API token, the two draft reports and the mail sink were removed.

## Prod steps

Prod is PID 35968. It is in production status.

**Order matters.** Uploading the instrument is the switch, and REDCap grants some users rights on a new instrument
automatically: locally the uploader and `ihabz` got full rights. So the rights are locked down before the module
version that writes transcripts is installed. Until that version is installed, the form stays empty.

Before step 1:

- **Release.** This change is on `mica-phase-3` (branch `feature/transcript-instrument`). Prod needs a module release
  built from a branch that contains it. Its files:
  - `classes/TranscriptRecordBuilder.php`, `classes/TranscriptRecordDiff.php`, `classes/RedcapTranscriptRecordSync.php`;
  - the `mica_transcript_records` cron in `config.json`, and `micaTranscriptRecordsCron()` / `syncTranscriptRecords()`
    plus one `require_once` in `MICA.php`;
  - `tests/Unit/TranscriptRecordBuilderTest.php`;
  - `docs/phase-3-handoff/scripts/sync-transcript-records.php`;
  - `e2e/transcript-instrument.js`, `e2e/transcript-report.js`;
  - `docs/phase-3-handoff/dictionary/mica_transcript.csv` and `mica_transcript_instrument.zip`;
  - this doc and the README rows.
- **REDCap version.** The copy suppresses alerts and ASIs with `REDCap::saveData`'s `bypassAlertAsiTrigger`. This was
  confirmed in 17.5.9 (local). Check that prod's 17.5.x has it, since an older version ignores the key silently.
  If it doesn't, alerts and ASIs in the session events would be evaluated on every copy.

Steps:

1. **Online Designer → Enter Draft Mode → Upload instrument ZIP** (`mica_transcript_instrument.zip`) → check that it
   is listed as "MICA Transcript" with no renamed fields → **Submit Changes for Review**. It only adds fields, so
   it should auto-approve.
2. **User Rights, for MICA Transcript:**
   - **No Access** (data viewing and data export) for everyone who should not read transcripts. Check each user and
     role, because REDCap may have granted some of them access.
   - Carl's API user: **Read Only** data viewing, **Full Data Set** data export, **API Export**.
   - Brian's coders: **Read Only** and **Full Data Set** export, if they will download the report themselves.
3. **Project Setup → Designate Instruments for My Events.** For each of Arm 2 and Arm 3: **Begin Editing** → tick
   **MICA Transcript** on **Day 1 (ED)** and **Month 3** → **Save**. Changing designations in production may need
   a REDCap admin.
4. **Project Setup → Repeating instruments and events → Modify.** For Day 1 (ED) and Month 3 in Arms 2 and 3, set
   the event to "Repeat instruments (repeat independently of each other)" if it isn't already, tick **MICA
   Transcript** → **Save**.
5. **Install the MICA release that contains this change.**
6. **Wait up to 15 minutes**, then open a session record's Day 1 (ED) event. MICA Transcript shows one instance per
   statement. That is the backfill of every session since 2026-10-12.
7. **Data Exports → Create New Report:**
   - Title "MICA transcripts".
   - Fields: record ID, then **Add all fields from an instrument → MICA Transcript**.
   - **Use advanced filter logic:** `[tx_seq] <> ""`.
   - **Untick** "Show data for all events or repeating instruments/events for each record returned".
   - **Save Report**.
   - Report rights: share it only with the users from step 2.
8. **Check:**
   - Open the report and compare one session's row count and flagged statements with the same session on the
     Safety Scan review page.
   - Note that the review page shows only the latest part of a resumed session.
   - Then export: **Export Data → CSV (raw)**, or through the API: `content=report&report_id=<N>`.

**Governance.** The review page records every transcript read in MICA's audit log. Form data gets only REDCap's
standard logging (exports are logged; views are not logged per statement). The study's data plan or IRB
documents may need to say where transcripts now live and who can export them.

## Alternatives considered

- **One JSON notes field per session.** It is capped at 64 KB per value, and a report then gives one row per
  session, which the coders would have to split. Replaced by one instance per statement.
- **A module API action** (`content=externalModule`). It is not what Carl asked for, and it can't be used from a
  report.
