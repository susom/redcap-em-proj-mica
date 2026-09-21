# The arm-1 `ERR_TOO_MANY_REDIRECTS` at the end of the Day-1 chain

**Status: fixed and verified on PID 268 (2026-09-21).** One guard in
`MICA.php::ensureEdSessionLink()`, one pure predicate in `EdSessionLink`, four unit tests.
Existing records need a backfill — see §5.

| | |
|---|---|
| Symptom | A Standard Care participant submits the last Day-1 survey and the browser fails with `ERR_TOO_MANY_REDIRECTS`. No message, no page, no way forward. |
| Who | **Arm 1 only, permanently.** Also any record that reached `close` before randomization. |
| Root cause | `session-fallback-instrument` names `close`, and `close` is now the survey whose *Redirect to a URL* is `[ed_session_url]`. The module wrote `close`'s own link into the field `close` redirects to. |
| Fix | `MICA.php` refuses a fallback instrument whose own redirect pipes the URL field; arm 1 gets `pages/sessionHandoff.php?state=done` instead. |

---

## 1. What the participant hits

Chromium gives up after 19 hops:

```
GET  /surveys/?s=<close hash>
  -> 302  Location: /surveys/?s=<close hash>      # same URL
  -> 302  Location: /surveys/?s=<close hash>      # same URL
  ...
net::ERR_TOO_MANY_REDIRECTS
```

The cycle has length **one**. `close` redirects to `close`.

## 2. Why it is a cycle

Three settings that are individually reasonable:

1. **`close` carries the handoff redirect.** `redcap_surveys.end_survey_redirect_url =
   '[ed_session_url]'` on `close` (survey 1402 on PID 268). This moved there with the Day-1 flow
   rebuild — it used to sit on `tsr` (PID 257, survey 1293).
2. **Arm 1 has no session to redirect to.** `mica_ed_session` is designated to arms 2 and 3 only, so
   `EdSessionLink::resolve()` returns `NO_SESSION_IN_ARM` and the module takes the *fallback* branch.
3. **The fallback still names `close`.** `session-fallback-instrument = close`, from when the
   redirect lived on `tsr` and `close` was genuinely the next thing in the battery.

So the module mints `close`'s own survey link and writes it into `ed_session_url`, which is exactly
what `close` redirects to.

REDCap then closes the loop on its own. `Surveys/index.php:1851` re-fires an end-of-survey redirect
on **every GET of an already-completed survey** when the survey has no auto-continue and no
save-and-return — which describes `close`:

```php
// Surveys/index.php:1821
if (!$end_survey_redirect_next_survey && !$save_and_return && !$public_survey && !isset($_POST['submit-action'])) {
    $responseCompleted = (Survey::getSurveyCompletionTime(...) != '');
    if ($responseCompleted) {
        if ($end_survey_redirect_url != '') {
            ...
            redirect($end_survey_redirect_url);   // -> itself
```

The first submit is what arms it: POST `close` → 302 to `[ed_session_url]` → that URL *is* `close`,
now completed → 302 again → forever.

**Arms 2 and 3 never see it** because their `ed_session_url` holds the `mica_ed_session` link, which
leaves `close` on the first hop. That is why this reads as an arm-1 bug rather than a broken survey.

## 3. Verified state before the fix

Every arm-1 record in PID 268 pointed at `close`; every arm-2/3 record pointed at its session:

```
record      study_group   ed_session_url points at
3, RANDTEST02, REENTRY01-03      1        close            @1089   <- cycle
RANDTEST01, RANDTEST04, 31, REENTRY04  2  mica_ed_session  @1093
CRONTEST01, ORDER01, RANDTEST03/05     3  mica_ed_session  @1097
```

No arm-1 `close` response had ever been *submitted* on this server
(`redcap_surveys_response.completion_time` was NULL for all of them), which is why the loop had not
shown up in earlier testing: the redirect only arms itself once the survey is completed once.

## 4. The fix

`MICA.php::ensureEdSessionLink()` now asks whether the configured fallback survey redirects back to
the field before using it, and falls through to the handoff page when it does:

```php
if ($this->surveyRedirectPipesField($project_id, $fallbackForm, $urlField)) {
    $this->log('ED session fallback refused: it would redirect the survey to itself', [...]);
} else {
    $candidate = (string) \REDCap::getSurveyLink($record, $fallbackForm, $writeEventId, 1, $project_id);
    ...
}
```

The decision is `EdSessionLink::redirectPipesField()` — a pure predicate over the *unpiped* template,
so it is true before the field holds anything and stays true after it is overwritten. It recognises
both shapes REDCap accepts, `[ed_session_url]` and `[day_1_ed_arm_1][ed_session_url]`, and does not
match a different field whose name shares a prefix (`[ed_session_url_2]`).

**Why a code guard and not just a setting change.** Clearing `session-fallback-instrument` would fix
PID 268 today. It would also break again the next time the flow is reordered, and it would break the
*control arm* — the one that takes the fallback forever and the one nobody exercises during a
feature test. The setting is now harmless whether or not anyone remembers to clear it.

The fallback itself is still right when it is right: while the redirect sits on a survey *before* the
one being fallen back to, a Standard Care participant is carried on through the study's own closing
page rather than shown a module page. Only the self-referential case is refused.

### Where arm 1 lands now

`pages/sessionHandoff.php` with `state=done` — "Thank you. You have finished this part of the study."
That is the correct destination now that `close` is the last survey: there is nothing after it, and
the page deliberately never names the allocation (see `24-ed-session-handoff.md` §on unblinding).

## 5. Repairing records that already hold the bad URL

**The submit repairs itself.** `redcap_save_record` fires *before* REDCap resolves and pipes
`end_survey_redirect_url` in the same request, so a record still holding the poisoned link at the
moment the participant presses Submit is repaired in time and never sees the loop. Measured: an
arm-1 record with `ed_session_url` forced back to its own `close` link settled in one hop on the
handoff page, and the field read back as the handoff URL afterwards.

So the backfill is only load-bearing for records that **completed `close` before this fix shipped**.
Those never save again, so their stored link stays poisoned and any revisit of their `close` link
still loops:

```
docker exec redcap_2023_1_web php \
  /var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/backfill-study-group-arms.php <pid> --dry-run
```

It re-runs `ensureEdSessionLink()` for every record regardless of arm status. Verified on PID 268:
records `3`, `RANDTEST02`, `REENTRY01`, `REENTRY02`, `REENTRY03` went from `close` to the handoff
page; arm-2/3 records reported `already-set` and were untouched.

**Six records returned `refused-existing-value`** (7, 8, 9, 18, 25, 28). Their stored URLs are
`https://redcap.stanford.edu/...` hashes copied in from production, which do not resolve against the
local `redcap_surveys_participants` table — so `writeSessionUrl()` correctly treats them as somebody
else's value and leaves them alone. Those same hashes are *expected* to resolve on the production
server, in which case the backfill repairs them there — unverified from here, so read the prod
dry-run report rather than assuming it.

No arm-1 record is stranded on the `pending` message: the two handoff URLs differ by their `state`
query parameter, so `writeSessionUrl()`'s `already-set` short-circuit does not fire when a record
goes from not-randomized (`state=pending`) to Standard Care (`state=done`). Checked across PID 268 —
the only `pending` record is one with no allocation, which is correct.

## 6. Production

PID 35968 has the same flow and, as of this writing, the same two settings. After deploying:

1. Run the backfill with `--dry-run`, read the report, then run it for real.
2. Spot-check one arm-1 record: `ed_session_url` should be a `pages/sessionHandoff` URL ending
   `&state=done`, not a `?s=` survey link.
3. `scripts/verify-ed-session-link.php <pid>` for the rest of the chain.

Leaving `session-fallback-instrument = close` in place is now safe, and is the honest record of what
the study configured. Clearing it changes nothing at runtime.

## 7. How it was reproduced

Worth keeping, because the first two attempts measured nothing.

- **Cookie jar or it did not happen.** `curl` without `-c/-b` returned `200, 0 redirects` against a
  completed survey link — REDCap's survey flow is session-driven, and the redirect branch is never
  reached without a session cookie.
- **PID 257 is soft-deleted.** `redcap_projects.date_deleted = 2026-09-15` on the local MICA_R01, and
  `Surveys/index.php:1065` short-circuits every survey in a deleted project to "this survey is not
  currently active". Any test run there measures the trash can. The live local project is
  **PID 268, "NEW TEST MICA_R01"**, whose data lives in `redcap_data7`.
- The reproduction is a real POST of the `close` survey followed by manual `Location:` walking, then
  confirmed in Chromium via Playwright (`net::ERR_TOO_MANY_REDIRECTS`, 19 hops). After the fix the
  same POST settles in one hop on the handoff page; the page was re-checked at 320×568 and 1280×800
  with no horizontal overflow.
