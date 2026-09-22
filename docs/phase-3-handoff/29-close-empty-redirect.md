# The white page after `close`: every `close` but the screening one redirects to nothing

**Status: fixed and verified on PID 271 (2026-09-22).** Two hooks in `MICA.php`, one pure predicate
in `EdSessionLink`, three unit tests, one Playwright suite. No REDCap configuration changed, and no
backfill is needed (the fix runs on every request, including revisits of already-completed responses).

| | |
|---|---|
| Symptom | A participant finishes a chain that ends in `close` and gets a white page: `302` with `Location:` empty, body of zero bytes. No error, nothing logged. |
| Reported as | "After I finished the arm 1 ED workflow" (`?s=Pp6WJKhwXQ6S2oTg`). In fact that hash is `close` at **event 1112, arm 3 Day 1**, reached through `mica_ed_session → postsession → close`. Record 1 started in arm 1 (every record does) and was randomized to arm 3. |
| Who | **Every intervention participant finishing Day 1** (arms 2 and 3), and **everyone finishing a follow-up** (Month 3/6/12 in every arm, and the booster event 1114). Only the screening `close` (event 1104) works. |
| Root cause | `close` redirects to `[ed_session_url]`. That field lives on `admin` and only ever holds a value at the first event hosting `admin` (1104). `close` is designated at every event, and piped anywhere else the redirect resolves to `''`. |
| Fix | `redcap_survey_complete` (submit) and `redcap_every_page_before_render` (revisit) send the participant to `pages/sessionHandoff.php?state=done` whenever a redirect that pipes the session-URL field pipes to nothing. |

---

## 1. Why it happens

One survey, one redirect, many events. `redcap_surveys` holds one row per instrument, so
`close.end_survey_redirect_url = '[ed_session_url]'` applies at every event `close` is designated to:

```
PID 271   close designated at: 1104 1105 1106 1107 | 1108 1109 1110 1111 | 1112 1114 1115 1116
          admin (holds ed_session_url) at: 1104 | 1108 | 1112
          ed_session_url has a value at:   1104 only  (MICA.php::firstEventHostingForm)
```

REDCap tests the redirect **template** for emptiness and then redirects to whatever piping makes of
it. A bare `[ed_session_url]` pipes against the *current* event:

```php
// redcap_v17.2.3/Surveys/index.php, submit path
if ($end_survey_redirect_url != '' && !$deleteSurveyResponse) {        // :2805  the template: non-empty
    ...
    Hooks::call('redcap_survey_complete', ...);                         // :2822  <- the module runs here
    $end_survey_redirect_url = br2nl(Piping::replaceVariablesInLabel(   // :2825  pipes at event 1112 -> ''
        $end_survey_redirect_url, $fetched, $_GET['event_id'], ...));
    redirect($end_survey_redirect_url);                                 // :2831  redirect('')
```

The revisit path at `:1821-1851` does the same on every GET of a completed response (see
[`27-arm1-redirect-loop.md`](27-arm1-redirect-loop.md) §2), so re-opening the link is blank too.

`postsession` auto-continues into `close` at the same event, which is why the whole post-session
chain walks straight into it. It survived testing because every earlier walk ended at the screening
`close` and its handoff, which is the one place the field has a value.

## 2. Reproduced before fixing

Measured on PID 271 with the committed `MICA.php`, not read off the code:

- `curl -c jar -b jar -L` on `?s=Pp6WJKhwXQ6S2oTg` returns `302`, `Location:` (empty), 0 bytes.
- A fresh arm-3 record (`postsession` complete at 1112), `close` submitted in Chromium, gives an empty
  body at the survey's own URL. Revisiting it gives the same `302` with an empty `Location:`.

One trap on the way. Opcache here has `revalidate_freq=2`, so a run started within two seconds of
swapping `MICA.php` executes the *previous* file. The first "before" run passed for that reason
alone. Wait a few seconds after editing before measuring.

## 3. The fix

An invariant the module already stated in `ensureEdSessionLink()` (a redirect through this field must
never resolve to empty) is now enforced where REDCap actually pipes it, not only where the field is
written:

- **`EdSessionLink::redirectPipesToNothing($template, $urlField, $piped)`** is the decision. It only
  claims surveys whose template pipes *this module's* field (`redirectPipesField()`), so anybody
  else's redirect, broken or not, is left to REDCap.
- **`MICA::handoffInsteadOfEmptyRedirect()`** pipes the template exactly as `:1846`/`:2825` do and
  returns the `state=done` handoff URL when the decision says so. It logs
  `Survey redirect piped to nothing: ...` each time.
- **`redcap_survey_complete`** is the submit path. It skips when auto-continue will replace the
  template (`:2799`), otherwise redirects with `redirectAfterHook()`.
- **`redcap_every_page_before_render`** is the revisit path. No survey hook runs before `:1821`,
  so this resolves the hash itself and copies `:1821`'s conditions: GET, not the public link
  (`participant_email IS NULL`), completed, no auto-continue, no save-and-return.

Why the trigger is "piped to nothing" rather than "not the screening event": the screening `close`
must keep handing off (it pipes to the session link, or to a handoff URL the module wrote), and any
event added later is covered without anyone remembering this document.

Why `state=done`: every case the guard catches is a participant who has just completed the study's
closing survey. Nothing follows it. The page never names the allocation (see
[`24-ed-session-handoff.md`](24-ed-session-handoff.md)).

Behaviour change worth knowing: the revisit guard runs *before* REDCap's own availability checks
(deleted project, disabled survey, expired link). A **completed** `close` in one of those states now
shows "finished this part of the study" instead of "this survey is not currently active". That is
true for a completed response, but it is different.

Side effect worth knowing: `redirectAfterHook()` lets every module's hook run, then exits, so on
these requests (and only these) REDCap's own `Survey::outputCustomJavascriptProjectStatusPublicSurveyCompleted`
after `:2822` does not run. Those requests would otherwise have produced a blank page.

## 4. Alternatives considered and not built

- **Event-qualified pipe `[day_1_ed_arm_1][ed_session_url]`.** Non-empty everywhere, but it sends a
  Month-3 or booster `close` back into the participant's completed Day-1 ED session.
- **Designating `admin` on every event** so the field can hold a per-event value: a structural change
  on production for a routing problem.
- **Moving the redirect back to `tsr`**: reverses the Day-1 flow rebuild's decision
  ([`26-prod-runbook-day1-flow.md`](26-prod-runbook-day1-flow.md)).

## 5. Verified after the fix

`node e2e/close-empty-redirect.js '<expect>=<close link>' ...` runs each link on desktop and iPhone 13.
The first run submits, the second revisits a completed response. **56 passed, 0 failed:**

| Case | Event | Path | Lands on |
|---|---|---|---|
| Fresh arm-3 Day-1 `close` | 1112 | submit, revisit | handoff `done` |
| Fresh arm-2 Day-1 `close` | 1108 | submit, revisit | handoff `done` |
| Booster / Month-3 `close` | 1114 | submit, revisit | handoff `done` |
| The reported `Pp6WJKhwXQ6S2oTg` | 1112 | revisit | handoff `done` |
| The record reproduced on in §2 | 1112 | revisit | handoff `done` |
| Screening `close`, arm-3 record (regression) | 1104 | revisit | the ED session (behind its Survey Login) |
| Screening `close`, unrandomized (regression) | 1104 | submit, revisit | handoff `pending` |

The suite waits for `networkidle` before judging a page blank. The ED session link opens behind
REDCap's Survey Login dialog, which renders after `DOMContentLoaded`, and read too early a working
page looks exactly like this bug. It ignores exactly one JS error: the local docker image's
"LOCALHOST" banner reading a `.navbar` that survey pages do not have. That error is not the module's
and does not exist on production.

Test records were created on PID 271 and deleted afterwards. The suite also submitted record 1's
booster `close` (1114). Its `close_complete` value, response row and participant row were deleted,
but `redcap_log_event8` still shows that submit at 11:28:18. Alerts, ASI queue and outgoing SMS/email
were checked afterwards: the submit queued and sent nothing. (The alert-03 SMS logged for record 1 at
11:29:00 followed a manual "Delete all record data for single form" on `mica_ed_session` at
11:28:48, which made that alert's condition true again.)

## 6. Production

Not re-checked from here. [`27-arm1-redirect-loop.md`](27-arm1-redirect-loop.md) §6 records that
PID 35968 has the same flow, and if `close` carries `[ed_session_url]` at all its events there, the
same participants are affected. The fix depends on REDCap's hook order as read on **v17.2.3**
(`redcap_survey_complete` before piping at `:2822`/`:2825`, `redcap_every_page_before_render` before
`:1821`), so confirm prod's REDCap version first. Then deployment is the whole fix: no settings, no
backfill. After deploying, open any completed non-screening `close` link and expect the "finished
this part of the study" page.

## 7. Local environment note

PID 268 was soft-deleted on 2026-09-22 10:30. **PID 271 ("NEW TEST MICA_R01 2") is now the live
local project**, and every survey on 268 answers "this survey is not currently active".
