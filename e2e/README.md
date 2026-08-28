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
| `module-config.js` | The read-only SafetyScan prompt panel in the module's configuration dialog: that the hook ran rather than `config.json`'s fallback, the full artifact sha256, collapse/expand, the first and last line of the artifact, a height-bounded scroll box, and its position directly above the addendum field | a **design-rights REDCap user** — not an admin, deliberately |

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
(`e2e_mica_reviewer`) in it, and maps that *role* in the module settings. That mirrors what a study
team actually does, because MICA access follows REDCap roles rather than a list of usernames — the
module configures which role reviews findings, and people are added by being put in the role.

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
