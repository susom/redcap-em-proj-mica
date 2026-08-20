# E2E

Playwright specs for the participant-facing path. Desktop (1400×950) and iPhone 13.

| File | Covers |
|---|---|
| `full-path.js` | Survey Login gate (incl. scoping and a wrong-credential attempt), chatbot load, multi-turn conversation with context retention, bundle hygiene, reload/restore, End Session, mobile layout |

## Running

```bash
php docs/phase-3-handoff/scripts/manual-test-auth.php 257 setup      # prints the links
node e2e/full-path.js <edSessionLink> <baseline1ControlLink>
php docs/phase-3-handoff/scripts/manual-test-auth.php 257 teardown
```

The teardown is not optional — it removes the test participant, its survey responses
and its record-list cache rows in every arm. It does **not** remove the module's EM-log
transcript rows; delete those separately if you care about a clean log.

## Two things to know before trusting a red run

1. **A changed bundle hash is not a regression.** `B6` prints the loaded bundle. If the
   SPA was rebuilt between runs, selectors may have moved. Composer selectors here are
   element-agnostic for exactly that reason.
2. **`offsetHeight` page errors are REDCap core, not MICA.** They fire on non-MICA survey
   pages too and the stack lands in `redcap_vNN/Resources/webpack/js/bundle.js`. The suite
   counts them separately and never attributes them to the module.

## Known non-failures

`E. END SESSION` reports that the project is not configured for MICA sessions. That is
real, expected, and tracked: PID 257 lacks the session/transcript fields the finalizer
needs (`docs/phase-3-handoff/09-pid-257-structure-audit.md`, gaps G1/G4). It is Stage 2
work, not a defect in the turn path.

## Not yet done (Stage 0.5)

This is a plain node script, not a `@playwright/test` suite, and there is no CI job. Both
are open 0.5 items — see `docs/phase-3-handoff/06-implementation-plan/stage-0-foundations.md`.
