# E2E

Playwright specs, desktop (1400×950) and iPhone 13.

## Installing

```bash
npm run e2e:install      # playwright + the chromium binary
```

Playwright is a **devDependency of the module root**, not of either SPA - see the
`_comment` in `package.json` for why nothing here may be a runtime dependency. If
`node e2e/...` reports `Cannot find module 'playwright'`, this is the step that was
skipped.

| File | Covers | Signs in as |
|---|---|---|
| `full-path.js` | Survey Login gate (incl. scoping and a wrong-credential attempt), chatbot load, multi-turn conversation with context retention, bundle hygiene, reload/restore, End Session, mobile layout | a **participant**, via native Survey Login |
| `review-dashboard.js` | RA queue and its ordering, session review, evidence highlighting, jump-to-evidence, the disposition gate, audit, mobile layout and tap targets | a **REDCap user** — the dashboard is an authenticated module page |
| `module-config.js` | The read-only SafetyScan prompt panel in the module's configuration dialog: that the hook ran rather than `config.json`'s fallback, the full artifact sha256, collapse/expand, the first and last line of the artifact, a height-bounded scroll box, and its position directly above the addendum field. It also checks that **SafetyScan model alias** is a dropdown, not free text, offering the five structured-output models with GPT-5.6 Sol first, and that the saved value shows as selected (`MICA_EXPECTED_ALIAS`, default `gpt-5-6-sol`). C27–C29 check the 2026-09-30 cleanup: none of the ten removed settings is offered, the kept `chatbot_system_context_*` neighbours still render, and two real Saves write none of the removed keys back. Run it with `MICA_PID=271` or `279`; the default, 257, is deleted. 54/54 on 279, 2026-09-30 | a **design-rights REDCap user** — not an admin, deliberately |
| `close-empty-redirect.js` | Submitting and revisiting `close` at every kind of event: that a non-screening `close` lands on the `done` handoff page instead of a blank one, and that the screening `close` still hands off to the session or the `pending` page. Desktop and mobile | a **participant**, via real survey links |
| `session-handoff.js` | The two pages a participant lands on when the arm-1 chain ends with no session to send them to: that the body is not blank, that neither message reveals the allocation, mobile layout, and that the `state` parameter is never echoed | **nobody** — the page is `no-auth` |
| `weekly-sms-sunday.js` | The Arm-3 weekly check-in (`sunday`) renders: no "SURVEY ERRORS EXIST" page, no unpiped tokens, no TRAM branding, no horizontal overflow. Desktop and mobile. `node e2e/weekly-sms-sunday.js <link> <label>` | a **participant**, via a real survey link |
| `weekly-sms-asi.js` | REDCap's Online Designer loads the weekly-SMS ASI and reads back every setting that makes the cadence work (Email + `@ESMS`, AND + re-evaluate, 6 days after `first_monday_1200`, every 7 days × 12). The screenshot is the prod build template. | a **design-rights REDCap user** (`e2e-module-config-user.php`) |
| `weekly-sms-dd-upload.js` | A Data Dictionary upload through REDCap's own page, preview then Commit. Waits for REDCap's CSRF token, and treats "CSRF… successfully blocked" as the failure it is. `--no-commit` stops at the preview. | a **design-rights REDCap user** |
| `rand-trigger.js` | PID 271 randomization: a participant's `tsr` submit randomizes the record, a staff Incomplete save does not and a Complete save does, and the manual Randomize button on `admin` works. Every case checks the arm placement and `ed_session_url` in the DB. Desktop and mobile. See `docs/randomization/TRIGGER_TSR_COMPLETE_OR_MANUAL.md` | a **participant**, then a **non-super user with Randomize rights** (`e2e-admin-form-user.php 271 setup --randomize`) |
| `rand-strata.js` | Stratified randomization on a prod replica: `upload` puts a development allocation table in through REDCap's Randomization page, then `tsr` submits the record's `tsr` survey as the participant and reads back `rand_strata`, `study_group`, the allocation slot and its stratum, and REDCap's own "Randomize record" log line (the only place a failure shows). See `docs/phase-3-handoff/30-go-live-readiness.md` | a **user with Randomization Setup rights** for `upload`, then **nobody** for `tsr` |
| `eligibility-calc.js` | The screening chain on a project built from a REDCap XML: `create` (New Project page, XML upload), `run broken\|fixed\|gated` (one participant per case through the public link; stored `calc_screen_result`, the eligibility messages shown, where the flow ends, and which stop action fired), `fix` (pastes `docs/screening/calc_screen_result.txt` into the Online Designer's Calculation Equation box and checks REDCap reports it Valid), `gate` (the `pre_screen` auto-continue condition, via Survey Settings), `wrongarm` (screens through another arm's public link), `walk` (one participant with ad-hoc answers, reported rather than asserted, for any structure, e.g. the PI's 16:46 export). `stops <pid> [leak]` walks every `pre_screen` stop action, read from the project, plus an eligible control, and asserts each ends on the pre-screen's thank-you page (`leak` asserts the unfixed carry-on to AUDIT-C). The `gate` condition is derived from the same stop actions, not hard-coded. Walks *through* stop actions, because in 17.2.3 they do not end the chain. Desktop plus mobile on three paths. See `docs/screening/ELIGIBILITY_CALC_PI_XML_2026-09-25.md` | a **non-super user who can create projects** (`e2e-admin-form-user.php 271 setup --create-projects`), then **participants** |
| `critical-notify.js` | A critical disclosure reaches the CRC within 5 minutes: the participant submits `tsr` (optional, randomizes), holds a two-turn session with a synthetic overdose plan, and clicks End Session. The script then prints a timeline from the database (job, each scan attempt, the notice) and reads the CRC's mailbox, a local mail sink. It asserts a `ready_for_review` scan, a critical finding on the record, a "highest urgency: critical" email, End Session → email ≤ 300 s, and no participant words in the email. The email is rendered on desktop and phone with no sideways scroll, and REDCap's own plain-text part must read the link as `URL (URL)`. Needs `mailpit` answering as `mailhog` and a stand-in CRC (`e2e-crc-reviewer.php`). The notice goes to the Reviewer role, or to the Reviewer notification addresses when that setting is filled in, so for this run leave that setting blank or include `crc-e2e@example.org` in it, or set `MICA_CRC_EMAIL` to a named address; `e2e-crc-reviewer.php setup` warns when it would miss (2026-10-02). `MICA_TURNS=runbook` replays the six messages the prod runbook (doc 32, step 6) asks staff to type. Its turn 4 fails today, and that failure is real: Azure's content filter refuses the counselor's reply to "Honestly I don't see the point of anything anymore." (doc 31). The scan and the CRC's email still pass. A turn counts as answered only when the model answered (`provider_error` is a 200 too), and only this record's notice counts as the CRC's email, not the acknowledgement monitor's reminders about earlier sessions. See `docs/phase-3-handoff/31-critical-finding-crc-notify.md` | a **participant**; the CRC is a role member who never logs in |
| `data-import.js` | A CSV through REDCap's own Data Import Tool page: upload, review (reports REDCap's validation verdict), then **Import Data**, which waits for REDCap's CSRF token first. Clicking early gets "Multiple tabs/windows open! Your changes were not saved". Used to prove an import file on a replica before it goes to prod. See `docs/alerts/pi-review/test-6b/README.md` | a **user with data-import rights** (`e2e-admin-form-user.php`) |

## Running the participant path

```bash
php docs/phase-3-handoff/scripts/manual-test-auth.php 257 setup      # prints the links
node e2e/full-path.js <edSessionLink> <baseline1ControlLink>
php docs/phase-3-handoff/scripts/manual-test-auth.php 257 teardown
```

## Running the review dashboard

```bash
docker exec <web> php .../scripts/apply-safety-finding-instrument.php 257   # once, if not built
docker exec <web> php .../scripts/e2e-review-fixture.php 257 setup          # creds + seeded findings
node e2e/review-dashboard.js
docker exec <web> php .../scripts/e2e-review-fixture.php 257 teardown
```

**Re-seed between runs.** The spec records a real disposition, and a settled finding correctly sorts
below a pending one — so a second run against the same data sees a different (still correct) order.
The ordering assertion compares only pending rows for that reason, but re-seeding is cheaper than
reasoning about it.

The fixture creates a **throwaway REDCap user role** (`E2E MICA Reviewer`), puts a throwaway user
(`e2e_mica_reviewer`) in it, and maps that *role* in the module settings, because access to the
review dashboard follows REDCap roles (Reviewer or PI-lead) rather than a list of usernames — the
module configures which role reviews findings, and people are added by being put in the role. Who is
*emailed* can be a plain address list instead (Reviewer notification addresses, 2026-10-02), and then
the Reviewer role is optional, but being on that list grants no dashboard access.

The user has **no design and no user-rights** privileges, deliberately: the dashboard has to work for
an ordinary reviewer, and running as an admin hid a real bug where the framework's design-rights
default meant only a project *designer* could open the safety-review page.

The three ways a user can lack access — not on the project, on it with **no** REDCap role, and in a
role that is **not mapped** — are each asserted by `verify-disposition.php`, which also proves that
re-mapping restores access (so the denials were the mapping and not something incidental).

## Running the module-configuration dialog

```bash
docker exec <web> php .../scripts/e2e-module-config-user.php 257 setup      # creds + the EM URL
node e2e/module-config.js
docker exec <web> php .../scripts/e2e-module-config-user.php 257 teardown
```

**This spec writes one setting.** The render checks are read-only, but `C21`–`C23` type into
`safetyscan-prompt-addendum` and click **Save**, because adding a key to the section is exactly the
change that could break the save path for its neighbours — a panel that renders and then costs the
study its addendum would be worse than no panel. It reads the current value first and writes it back
through the same dialog, so a project with a configured addendum is left as it was found; a run
interrupted between the two saves leaves the marker text behind, which is why the marker says what it
is. The complementary guarantee — that the display-only anchor never *gains* a settings row — is
checked by `verify-settings.php` §10, which can see the settings table.

**Why this needs a browser at all.** The panel is returned by
`redcap_module_configuration_settings()`, and `descriptive` is a **client-side-only** setting type —
its entire render lives in `ExternalModules/manager/js/globals.js`, which drops the setting's `name`
into a `<label>` as raw HTML. A unit test proves the string; only a browser proves that a `<details>`
nested inside a `<label>` still toggles, and that 4.4 KB of prompt stays inside the modal.

**Why a design-rights user and not an admin.** `hasProjectSettingSavePermission()` short-circuits to
`true` for a super user, so an admin run cannot tell you whether an ordinary study designer — the
person who writes the addendum — can open the dialog at all.

**The mobile dialog overflows and it is not the module's.** At 390px REDCap's settings modal is
866px wide and every setting in it is clipped identically; the overflow is unchanged with the panel,
without it, and with the whole settings table `display:none`. `C16` is therefore *differential* — it
asserts the panel adds no width — and prints the absolute figure so a reader is not misled by it.

## Running the session handoff

```bash
docker exec <web> php .../scripts/verify-ed-session-link.php 257     # server-side: is it wired?
node e2e/session-handoff.js
```

No fixture and no login: the handoff page is `no-auth` and takes no record identifier, by design —
its two messages are static, so there is nothing to authenticate and nothing to leak.

**Why this page exists, and what the suite is really guarding.** REDCap's survey redirect is
all-or-nothing: the guard at `Surveys/index.php:1833` tests the redirect template **before** piping,
so `[ed_session_url]` piping to an empty string still reaches `redirect('')`. Measured in a browser:
`302` with `Location:` empty and a body of **zero bytes** — a participant who had just finished
screening saw a blank screen. `C3` asserts a non-blank body for that reason.

`C4` is the other load-bearing one. The `done` message is what a **Standard Care** participant sees,
so "you have no MICA session" would tell them they are in the control arm. That is unblinding, and it
is exactly the kind of sentence that gets edited into a page nobody re-reviews — so the suite greps
for it rather than trusting it.

## Running the `close` empty-redirect suite

```bash
node e2e/close-empty-redirect.js 'done=<close link at a non-screening event>' 'session=<screening close link>' ...
```

Each argument is `<expected>=<link>`, where the expectation is `done`, `pending` or `session`. Mint
un-submitted links with `REDCap::getSurveyLink()`. The desktop run submits and the mobile run
revisits, which covers both of REDCap's redirect paths. The suite submits real responses, so run it
against throwaway records. See
[`../docs/phase-3-handoff/29-close-empty-redirect.md`](../docs/phase-3-handoff/29-close-empty-redirect.md)
§5 for the matrix and why it waits for `networkidle`.

## Two things to know before trusting a red run

1. **A changed bundle hash is not a regression.** If a SPA was rebuilt between runs, selectors may
   have moved. Selectors here are element-agnostic for exactly that reason.
2. **`offsetHeight` page errors are REDCap core, not MICA.** They fire on non-MICA pages too and the
   stack lands in `redcap_vNN/Resources/webpack/js/bundle.js`. Both suites count them separately and
   never attribute them to the module.

## Hostname matters for the review spec

REDCap builds absolute asset URLs from its **configured** base URL, so a browser on a different
hostname makes the module's own JS a cross-origin request. The spec resolves the name in-browser
(`--host-resolver-rules`) rather than requiring an `/etc/hosts` entry; override with `MICA_BASE` and
`MICA_HOST_RULES` if your instance differs.

## Known non-failures

- The finalizer warns that four session-form fields are missing (`mica_session_status`,
  `mica_session_end_ts`, `mica_transcript_ref`, `mica_transcript_hash`). That is audit G4 — Stage 2
  builds them. The scan is queued regardless.

## Testing it by hand instead

`../docs/phase-3-handoff/17-safety-finding-manual-test.md` walks the same ground in a browser, and
starts with a preflight that names which of the six pipeline links is broken - because a break
anywhere shows up at the far end as "no findings", which looks exactly like "nothing to find".
