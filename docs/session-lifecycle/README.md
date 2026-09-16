# Ending a session, and why the Back button used to reopen it

**Status:** fixed and verified end-to-end on localhost PID 268, 2026-09-16.
**Reported as:** "when user clicks end session they could click back button from browser, go back to
the survey and continue talking to MICA."

---

## The report was accurate, and the mechanism was not the obvious one

The obvious explanation is the browser back/forward cache: the page is restored from memory, no
request reaches the server, so nothing can refuse it. **That is not what was happening.**

Driving the real participant path in Chromium and listening for navigation requests on `goBack()`:

```
navigation requests on goBack: ["GET  http://redcap.local/surveys/?s=…",
                                "POST http://redcap.local/surveys/?s=…"]
```

A real round trip. REDCap re-served the survey, the SPA re-bootstrapped, and the chat came back
live — composer, End Session button, no terminal notice. So the server was asked, and the server
said yes.

Two independent reasons it said yes.

### 1. REDCap is configured to re-serve a completed response

Both chat hosts on PID 268:

| Survey | `save_and_return` | `edit_completed_response` | `repeat_survey_enabled` |
|---|---|---|---|
| `mica_ed_session` | 1 | **1** | 0 |
| `mica_booster_session` | 1 | **1** | 1 |

`edit_completed_response = 1` means "allow respondents to return and modify completed responses".
That is a project setting, not a code path, and it is why the link still opens. See
[Recommended configuration change](#recommended-configuration-change).

### 2. The module's own gate could never fire — because `completeSession()` blocked its own write

`sessionIsClosed()` carried this comment:

> ⚠️ This check is the enforcement.

It read one thing: the host instrument's form status, `<host>_complete == '2'`, written on End
Session by `markSessionCompleteOnFinish()` via `REDCap::saveData()`. That write failed every time:

```
saveData return: {"errors":["\"RANDTEST01\",\"mica_ed_session_complete\",\"2\",\"This field is a
Form Status field, whose value cannot be modified for survey responses…\""],"item_count":0}
```

That is `survey_403` (`Records.php:6420-6430`), and there is no bypass flag.

**But the refusal is conditional, and the condition was self-inflicted.** Core builds the lookup
the guard tests against like this:

```php
// Records.php:6238 - "records that are survey responses (either partial or completed)"
... from redcap_surveys_participants p, redcap_surveys_response r
    where ... and r.first_submit_time is not null
```

So the write is refused exactly when the response has a **`first_submit_time`** — and
`markSurveyResponseSubmitted()` is what sets that column. It ran *first* inside `completeSession()`,
stamping the response and thereby blocking the status write two calls later.

Measured across every session response on PID 268, the correlation is exact:

| Record | `first_submit_time` | form status written |
|---|---|---|
| `CRONTEST01` (abandoned, never submitted) | NULL | **`2`** ✔ |
| `RANDTEST01` (End Session) | set | NULL ✗ |
| `RANDTEST04` (End Session) | set | NULL ✗ |
| `RANDTEST05` (End Session) | set | NULL ✗ |
| `REENTRY04` (End Session) | set | NULL ✗ |

Nothing about the write is impossible — it was only ever impossible *after* the stamp. Reversing
the two calls makes both succeed.

**Why nobody noticed:** the failure was reported through `emError()`, which writes to the emLogger
*file*, not to `redcap_external_modules_log`. Confirmed: `mica_ed_session_complete` had no row at
all for a participant who had ended their session, and the module log showed no error.

### What that cost

Not just re-entry — the transcript kept growing after it was sealed. From the module log during the
reproduction:

```
3299  09:50:23  mica_transcript                                              <- finalized, scan queued
3300  09:50:37  {"role":"user","content":"Actually I want to keep talking after ending."}
```

`callAI` writes the participant's message with `logMICAQuery()` **before** any other check —
before `assertModelIsRegistered()`. So a post-End-Session turn was persisted into the chat log even
when the model call then failed, landing after the transcript had been finalized and its SafetyScan
queued. A disclosure made in that window is never scanned.

---

## The fix

Three changes, in order of what actually enforces.

### 0. The call order, which was the actual cause

`completeSession()` now writes the form status **before** stamping the response, and
`finalizeAndCloseSession()` (the cron) does the same. Both carry a `⚠️ ORDER IS LOAD-BEARING`
comment naming the other, because the two calls look independent and are not.

One record proves it — identical timestamps, opposite outcomes:

| Record | `first_submit_time` | `completion_time` | form status |
|---|---|---|---|
| `RANDTEST01` (before the fix) | 09:50:23 | 09:50:23 | **NULL** |
| `ORDER01` (after the fix) | 10:26:09 | 10:26:09 | **`2`** |

### 1. `sessionIsClosed()` reads a signal that is genuinely written

`markSurveyResponseSubmitted()` already stamps `redcap_surveys_response.completion_time` on End
Session, by direct SQL, and it works — verified on the reproduction record (response 2462,
`completion_time = 2026-09-16 09:50:23`). That is now the primary signal, via the new
`hostSurveyResponseSubmitted()`:

```sql
SELECT 1 FROM redcap_surveys_response r
  JOIN redcap_surveys_participants p ON p.participant_id = r.participant_id
  JOIN redcap_surveys s             ON s.survey_id      = p.survey_id
 WHERE s.project_id = ? AND s.form_name = ? AND r.record = ?
   AND r.completion_time IS NOT NULL
   AND p.event_id = ?          -- when the caller knows the event
```

The same three-table join `markSurveyResponseSubmitted()` writes through, read back. The form-status
check is kept as a second signal, because it still works when a CRC marks the form complete by hand
— and setting it back to Incomplete remains how a session is reopened.

Event-scoped on the participant's path: *this* session, not any session on the record. Neither chat
host is a repeating form (only `mica_safety_finding` is), so there is no instance to disambiguate —
noted in the code, because making a host repeating would need an instance filter.

### 2. `callAI` refuses a closed session, before it records anything

The bootstrap gate only runs when the page is built. Anything holding a stale page — an old tab, a
bfcached page, a replayed request — never re-runs it. So the check is repeated on the turn itself,
placed at the very top of `case "callAI"`, ahead of `handleUserInput()` and ahead of
`logMICAQuery()`:

```php
if ($turnHost !== '' && $this->isChatHostInstrument($turnHost)) {
    if ($this->sessionIsClosed($participant_id, $turnHost, (int) $event_id ?: null)) {
        $this->log('turn refused: the session is already closed', [...]);
        throw new \Exception('Session already completed. Thank you. …');
    }
}
```

`$instrument` and `$event_id` are the framework's values for the request, not payload fields — the
same reason `resolveParticipantId()` ignores the payload.

**Deliberate choice:** when the instrument is absent or is not a configured chat host, the turn is
*allowed* and a log line is written. Refusing would break callers whose host cannot be resolved, and
the bootstrap gate still covers the participant's path. The log line is there so a guard that has
quietly stopped matching is visible rather than silent — the failure mode this whole bug was.

### 3. A bfcache restore re-asks the server

A page restored from the back/forward cache skips the bootstrap entirely — no request, so no gate.
Added to the inline bootstrap:

```js
window.addEventListener('pageshow', function(e){
    if (e.persisted) window.location.reload();
});
```

Reload re-runs the bootstrap, which re-runs the gate and renders the terminal state that already
exists — rather than inventing a second way to say "this is over". No loop: the reloaded page has
`persisted === false`.

**Why it is belt-and-braces, and how far it was verified.** REDCap serves survey pages with

```
cache-control: no-store, no-cache, must-revalidate
```

and `no-store` disqualifies a page from bfcache in Chrome and Firefox. That is why Back issues a
real GET+POST here and why the session gate, not this handler, is what fixes the reported bug.
Forcing the feature on (`--enable-features=BackForwardCache:enable_same_site/true`) still produced
no restore:

```
pageshow events after goBack: [{"persisted":false}]
bfcache restore observed (persisted=true): false
```

So **the `persisted === true` branch is unverified on this instance** — state that plainly rather
than counting it among the tested properties. What *was* verified is that the listener binds, fires
on every load, and correctly evaluates false on a normal one, so it cannot cause a reload loop.

It is kept because **Safari is the known exception that bfcaches `no-store` pages anyway**, and iOS
Safari is the likeliest browser on an ED tablet. If that path is ever exercised on real hardware,
confirm there that the reload lands on the terminal notice.

### Also corrected

- The `⚠️ This check is the enforcement` comment on `sessionIsClosed()`, and the docblock on
  `markSessionCompleteOnFinish()` claiming it is "what stops a participant re-entering". Both
  described behaviour that never happened.
- `markSessionCompleteOnFinish()` now recognises the `survey_403` refusal as expected and logs it at
  debug level, and routes any *other* failure through `$this->log()` so it reaches
  `redcap_external_modules_log` instead of a file nobody reads. The call is kept because it still
  succeeds where there is no survey response.

---

## Verification

PID 268, fresh participants, full participant path in Chromium each time: consent → randomization →
survey login → chat → End Session → browser Back.

| | Before | After |
|---|---|---|
| Back button issues a real request | yes (GET+POST) | yes — unchanged, REDCap still serves the page |
| Composer present after Back | **yes** | **no** |
| End Session button present after Back | **yes** | **no** |
| Terminal notice shown | **no** | **yes** |
| Server accepts a turn after ending | **yes** | **no** — `Session already completed.` |
| Post-End-Session message written to the log | **yes** (`3300`) | **no** |
| Form status written on End Session | **no** | **yes** (`ORDER01`) |
| Cron closes an already-ended session | **no** — `finalize_failed`, every run | **yes**, once, then `already_closed` |

Normal sessions are unaffected — a turn before End Session is accepted on every run (`B1`), checked
on an arm-2 participant (`RANDTEST04`) and an arm-3 participant (`RANDTEST05`).

Not covered: the `pageshow` / bfcache branch, for the reason given in
[fix 3](#3-a-bfcache-restore-re-asks-the-server).

**The server refuses independently of the UI.** Calling the AJAX action straight from the page
context, which is what a stale tab does, with the UI blocked:

```
server answered: {"error":"Session already completed. Thank you. If you think this session should
                  still be open, please contact the study team.","success":false}
PASS  the server refuses a replayed turn into a closed session
```

and nothing was recorded — `SELECT COUNT(*) … message LIKE '%Replaying a turn%'` returns **0**,
against the pre-fix run's message which is still there. The module log shows the refusal itself:

```
3309  10:00:10  turn refused: the session is already closed
```

Suite: 1054 tests, 2979 assertions, OK. `phpcs` on `MICA.php` unchanged at 157 pre-existing
violations — none added.

Regression test: [`e2e/session-reentry.js`](../../e2e/session-reentry.js), `npm run e2e:reentry`.

**Note on the local run:** `gpt-5-6-luna` is not registered in this instance's SecureChatAI, so MICA
never actually answers here. That does not weaken the result — the bug and the fix are both about
whether the *server accepts and records the turn*, which is observed directly in the module log,
upstream of the model call.

---

## Recommended configuration change

Code now refuses re-entry, but REDCap still hands out the page first. To have REDCap itself refuse,
set **"Allow respondents to return and modify completed responses"** to **No** on both chat hosts
(`edit_completed_response = 0`). Leave *Save & Return Later* **on** — that is what lets a
participant resume a session they were interrupted in the middle of, which is a different and
wanted behaviour.

Not applied from module code on purpose: silently rewriting a study's survey settings is not this
module's call. Apply on PID 268 and on prod (PID 35968) through the Designer.

---

## The window-expiry cron, fixed in the same pass

`closeExpiredSessions()` → `finalizeAndCloseSession()` shared the root cause and had a second
problem of its own: it wrote only the form status and **returned `false` the moment that failed**,
which skipped the `SESSION_CLOSED_LOG` write — and that log is what `sessionWasClosedBefore()`
reads for the close-once guard. So a session that failed to close was never recorded as closed, so
it stayed a candidate, so every later pass retried it. Forever.

Measured before the fix, and identical on every run:

```
closeExpiredSessions => {"closed":1,"skipped":{"finalize_failed":4,"no_messages":1}}
```

The one that closed (`CRONTEST01`) was an abandoned session the participant never submitted, so its
`first_submit_time` was NULL and the status write was allowed — the same mechanism as above, seen
from the other side. The four failures were the sessions participants had properly ended.

Three changes:

- **Order**, as in `completeSession()`: form status first, then stamp.
- **The stamp is what closes it.** `markSurveyResponseSubmitted()` now runs in the closer too, so
  the cron's close is enforced by the same signal as the participant's. `$projectId` is passed
  explicitly — cron has no `PROJECT_ID` constant, and without it the `UPDATE` would match zero rows
  and report success.
- **Best-effort form status.** A failed status write logs through `$this->log()` (the module log,
  not the emLogger file) and the session still closes. `false` is now returned only when *neither*
  signal took — the case that genuinely must stay open and loud.

After:

```
closeExpiredSessions => {"closed":4,"skipped":{"no_messages":1}}          <- finalize_failed: 0
closeExpiredSessions => {"closed":0,"skipped":{"already_closed":4,...}}   <- guard holds, no retry
```

The second pass is the one that matters: the close-once guard now engages, so the retry loop is
gone.

`openSessionsByRecord()` was left alone. It selects candidates on the form status only, which is
correct again now that the status is actually written, and its comment about that field being the
CRC's reopen control stays true. Sessions ended *after* this fix drop out of the candidate list
entirely; the four records closed before it keep `form_status = NULL`, so they stay listed and take
the cheap `already_closed` skip on each run — visible in the output above, and not a leak.

**The cron was enabled on PID 268 only to run this, and has been set back to its original
`close-expired-sessions = false`.** Turning it on is a study decision; it is worth making now that
the closer works, but it was not mine to make.

---

## Reopening a closed session

Closing is now real, so it needs an undo. **Setting the form status back to Incomplete on the
record page is no longer sufficient** — enforcement reads the response row, which that does not
touch.

```bash
php docs/phase-3-handoff/scripts/reopen-session.php <pid> <record> <host_instrument> [event_id] [--dry-run]
```

It clears `first_submit_time` / `completion_time` and sets `<host>_complete` back to Incomplete —
in that order, which is the close path's ordering dependency in reverse: the status write is only
accepted once the timestamps are gone.

Verified on `ORDER01`: closed (composer gone, terminal notice, server refusing), reopened, and the
session usable again — `composer: true | End Session: true | terminal notice: false`.

**Deliberately a script, not an inference.** The tempting alternative is to treat an explicit
`<host>_complete = '0'` as "a human reopened this", which would restore the old control surface
with no new tooling. It was rejected: it rests on `'0'` never appearing for any other reason, and
if it ever did, sessions would silently reopen and this bug would come back quietly. An explicit,
auditable action is worth the extra step.

Two things the script does not do, both on purpose: it does not re-open the transcript (the earlier
conversation stays finalized and screened; new messages are finalized on the next close), and the
expiry cron will not re-close the session, because `sessionWasClosedBefore()` still holds its close
record — which is the existing design's choice, since a reopened session's window is past by
definition.
