# 14c — Live defects: functional (D6–D9, D11, D16)

Part of [14 — Live defects](14-live-defects.md). Section B of the audit.

## B. Functional defects

### D6 — Restoring a session does not restore the model's context

**Severity:** high (silently degrades the intervention)
**Status: FIXED 2026-08-19** — reproduced, fixed, re-verified E2E on desktop and
mobile (9/9 each). See "Fix and verification" below.

**As observed before the fix:** on PID 257 restore did not happen **at all** —
after a reload mid-conversation the transcript was empty and only the intro
bubble showed.
`fetchSavedSession()` passes `b.name` (null on this structure) and the backend
`fetchSavedQueries` requires a name and cross-checks it against
`participant_name` (`MICA.php:515`, `:533`), so the call returns nothing. The
display-only defect described below is therefore the *next* failure a
pilot-structured project would hit, not the current one here.

`replaceSession()` (`src/contexts/Chat.jsx:110-115`) repopulates `chatContext`,
`messages`, and `msgCount` — the **display** state — but never rebuilds
`apiContext`. After a page reload mid-session, `fetchSavedQueries` restores the
visible transcript while the model receives an **empty** history, and
`Chat.jsx:118` then re-injects the system prompt into that empty context.

**Predicted symptom:** the participant sees their prior conversation, but the
counselor behaves as if the session just began. Cappy rebuilds `apiContext` from
saved turns (`redcap_chatbot_v9.9.9/chatbot_ui/src/contexts/Chat.js:94-105`).

#### Fix and verification

Restore was broken in two places — the call never succeeded on the R01 structure,
and even when it did the model context was not rebuilt:

1. `MICA.php` `fetchSavedQueries()` — the `participant_name` cross-check is now
   applied **only where the project has that field**. The pilot's second-factor
   gate is preserved wherever it exists; projects without it (R01) no longer have
   restore made impossible by a name they cannot supply. Two long-standing bugs
   in the same method went with it: the comparison now uses the project's real
   primary field instead of a hardcoded `record_id` (**D13**), and the record is
   fetched via `records` instead of interpolating user input into a `filterLogic`
   string (same class as **D3**).
2. `Chat.jsx` `replaceSession()` — rebuilds `apiContext` from the restored turns,
   not just the display state. The system context is prepended **here** rather
   than left to `callAjax()`, so it stays ahead of the history and every entry
   carries `user_id` (the backend reads the participant id off the *first*
   message).
3. `Chat.jsx` `callAjax()` — the system-context injection now triggers on "no
   system message present" instead of "context array is empty". After a restore
   the array is non-empty, so the old condition would have skipped injection and
   sent a restored conversation with no system prompt at all.

Verified E2E, desktop and iPhone 13, 9/9 each. The decisive check is not that the
transcript reappears but that the **model** has it: told a codeword, reloaded the
page, then asked for the codeword back — and got it. Re-ran the full 23-check
suite afterwards with no regressions.

### D7 — Send button hangs permanently on the "session already completed" gate

**Severity:** medium-high (dead end with no message)
**Status: FIXED 2026-08-19 (both halves).** The client-side crash went first, with
D22: `callAjax()` no longer `.pop()`s an empty array, so the send does not throw
and the spinner does not stick. The second half — the server's message being
dropped — is now fixed too: `redcap_survey_page` forwards the caught gate message
as `bootstrap.error`, and the SPA renders it on load instead of showing a chat
that looks usable but cannot send. Verified E2E, 9/9 on desktop and mobile.

`getSystemContextForRecord()` throws the completion gates (`:706`, `:728`,
`:735`). `redcap_survey_page` catches and normalizes to `$ctx = []`
(`:268-275`), so `initial_system_context` ships as `[]` (`:303`) and
`window.mica_jsmo_module.data = []` (`:329`). Then
`getInitialSystemContext().pop()` returns `undefined`
(`assets/jsmo.js:13-15` ← `Chat.jsx:119`), and `Chat.jsx:52` reads `.role` off
`undefined` → rejected promise. `footer.jsx:16` neither awaits nor catches it,
and `footer.jsx:27-31` is a no-op.

**Predicted symptom:** spinner stuck on, no error text, no recovery. The
participant is told nothing — even though the server had a specific, friendly
message to deliver ("Return in N day(s) for your next session!").

#### Fix and verification (second half)

- `MICA.php` `redcap_survey_page()` — the caught message is forwarded as
  `bootstrap.error` instead of being assigned to `$error` and dropped.
- `useAuth.jsx` — when `bootstrap.error` is present the SPA renders it into the
  transcript on load and does **not** start a chat session.
- `Chat.jsx` `callAjax()` — the not-ready message now prefers the server's reason
  over the generic "could not be started" text, so a participant who types anyway
  gets the same explanation rather than a second, vaguer one.

Verified E2E, desktop and iPhone 13, 9/9 each: the server emits an `error` key in
the real bootstrap attribute (asserted via `hasOwnProperty`, so this half is
verified against actual server output, not just the injected value); the client
reads it; the message renders on load; and sending repeats the server's reason.

**Testing note:** PID 257 cannot *produce* these gates — they need
`session_info_complete` / `month3_fu_complete`, which the R01 structure lacks, and
`calculateSessionInfo()` returns `null` there without throwing. The gate message
was therefore simulated by intercepting the assignment the page's inline bootstrap
script makes, which exercises the real client path against a realistic payload.
The server side of it is asserted separately (the `error` key is present in the
attribute PHP actually rendered). A project that can raise a real gate should
re-confirm end to end.

### D8 — Only the *last* system-context element ever reaches the model

**Severity:** medium (silent prompt loss)
**Status: FIXED 2026-08-19** — `callAjax()` now iterates every entry returned by
`getInitialSystemContext()` instead of `.pop()`-ing one (see D22's fix). Not yet
observable on PID 257, where the context array is empty; needs a pilot-structured
project (or Stage 2's R01 context) to regression-test the multi-entry case.

`appendSystemContext()` `array_unshift`es a new entry when the first system
message is empty (`:165-167`), so `initSystemContexts()` can legitimately return
**more than one** element. The client then keeps only the last one:
`Chat.jsx:119` calls `.pop()`.

**Predicted symptom:** with a blank `chatbot_system_context_general`, or whenever
the catch-up summary is unshifted, the prior-session summary or the general
context is dropped before the model sees it — with no error.

### D16 — End Session fatals and silently discards the session

**Severity:** high — the session is not finalized, and the participant is told nothing
**Status: FIXED 2026-08-19** — reproduced, fixed, re-verified E2E on desktop and
mobile (8/8 checks). Promoted here from the low-severity table.

> **Correction to an earlier draft of this entry:** it said the *whole
> conversation* was discarded. That overstated it. `logMICAQuery()` writes each
> turn to the EM log as it happens, so the per-turn rows survive; verified after
> a failed finalization — 2 transcript rows present, `raw_chat_logs` rows: 0.
> What is lost is the **session finalization**: the `raw_chat_logs` snapshot,
> `session_timestamp`, and the `session_info_complete` flag.

Clicking **End Session** on PID 257 returns:

```
Call to a member function getTimestamp() on null
```

`completeSession()` calls `calculateSessionInfo()` (`:887`), which returns `null`
when `baseline_arm_1` cannot be resolved (`:828`). The result is dereferenced
without a guard, so `$calc["sessionStart"]->getTimestamp()` (`:889`) is a fatal on
`null`.

What the participant experiences: `header.jsx`'s `errorCallback` runs
`handleSignOut()`, so they are **signed out and redirected to the login page**
with no message — and because the fatal happens before `REDCap::saveData()`,
`raw_chat_logs`, `session_timestamp` and `session_info_complete` are **never
written**. The whole conversation is discarded. Observed: the URL moved to
`pages/chatbot&NOAUTH` and no data row was written.

#### Fix and verification

Three changes on the failure path, plus the two happy-path bugs that live in the
same function:

1. `MICA.php` `completeSession()` — guard the `calculateSessionInfo()` result
   (`null`, or a non-`DateTime` `sessionStart`) and throw a participant-safe
   `Exception` instead of dereferencing `null`. The technical detail goes to
   `emError`; the participant gets plain language.
2. `assets/jsmo.js` — `completeSession` now surfaces `parsed.error` rather than
   handing the caller `"Unexpected response: " + <raw JSON>` to display.
3. `header.jsx` — the error callback **no longer signs the participant out**. It
   renders the message into the transcript and leaves them on the page, so a
   failed finalization is visible and retryable instead of looking like a normal
   exit.
4. **D11** (same function) — the `saveData` result now goes through the shared
   `describeSaveDataErrors()` helper; see the D11 entry below.
5. **D10** (same function) — `$event_id` is initialized to `null`, so the
   `emDebug` call for sessions 2-6 no longer reads an undefined variable.

Verified E2E, desktop and iPhone 13, 8/8: no PHP fatal, no framework
ajax-error wrapper, the participant-safe message returned and rendered in the
transcript, no raw JSON leaked to the UI, participant **not** signed out (URL
unchanged), no unexpected page errors.

**Not verified: the happy path.** No project in this environment has the pilot
structure, so `completeSession()`'s success branch — `saveData()`, the
`raw_chat_logs` write, and the posttest / month3_fu survey-link lookup — was not
exercised. The D10 change there is defensive and was checked by inspection and
`php -l` only; the D11 helper it calls is unit-tested (see D11 below). That branch becomes testable when the Stage 2 session engine
lands (or against a pilot-structured project).

**Still the root cause:** the `null` comes from the R01/pilot event mismatch. This
fix makes the failure honest and non-destructive; it does not make finalization
*work* on PID 257. That is Stage 2 /
[`09-pid-257-structure-audit.md`](../archive/09-pid-257-structure-audit.md).

### D11 — `saveData` results: the OTP path failed *open*

**Severity:** medium-high — a participant could be locked out of their own study
**Status: FIXED 2026-08-19 (all call sites), unit-tested 12/12**

Originally logged as a low-severity type-check nit. It is worse than that in
`generateOneTimePassword()`, because there the subscript **was the success
condition**:

```php
$response = \REDCap::saveData('json', json_encode($saveData), 'overwrite');
if (empty($response['errors'])) { /* email the code */ }
else { /* throw */ }
```

`empty()` suppresses the illegal-offset diagnostic, so **any non-array response
evaluates as success**. The participant would then be emailed a verification code
that was never written to `two_factor_code` — and `verifyEmail()` looks the code
up by `filterLogic` on that field, so the code can never match. The failure mode
is a participant holding a valid-looking code that cannot possibly work, with
nothing logged.

**Fix.** One shared, fail-closed helper, `describeSaveDataErrors($response)`,
returning `''` only when the save genuinely reported no errors:

- normalizes the documented `returnFormat=json` string shape by decoding it;
- treats an unparseable string or any non-array as an **error**, not success;
- flattens `errors` whether it is a string, a list, or REDCap's nested rows
  (`json_encode`-ing non-scalars rather than stringifying an array).

`generateOneTimePassword()` now checks the save **before** sending any email, and
`completeSession()` was refactored onto the same helper so there is one
implementation rather than two.

Unit-tested through reflection, 12/12: empty errors, absent `errors` key, string
error, list of strings, nested error rows, JSON-string success, JSON-string with
errors, unparseable string, `null`, `false`, `int` — plus an explicit assertion
that a non-array response **fails closed** so no OTP would be sent. The End
Session E2E was re-run after the refactor (8/8) to confirm no regression.

### D9 — `renderMicaApp` does not exist: dead mount contract

**Severity:** low-medium (cosmetic + listener leak)

`window.renderMicaApp` is defined **nowhere** — 0 hits in `src/` and 0 in the
shipped bundle `dist/assets/index-BadDVS26.js`. The SPA self-mounts as an import
side-effect (`src/main.jsx:13`). So `tryMount()` (`:350-353`) always returns
false, which means:

- `unmask()` (`:343-346`) never runs, so `#mica-hide-native` persists for the
  page lifetime — contradicting the comment at `:252`.
- The `setInterval` (`:361-364`) runs ~100 iterations over 10s, re-invoking
  `blockSubmit()` and re-attaching capture-phase `submit` + `keydown` listeners
  each time (`:336-339`).

`src/App.jsx` is also dead — imported at `main.jsx:3`, never rendered
(`main.jsx:16` renders `<AppRouter/>`).

---
