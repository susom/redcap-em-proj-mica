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
- PHPUnit toolchain (part of 0.5): `phpunit.xml`, framework-free
  `tests/bootstrap.php`, PSR-4 autoloading for `Stanford\MICA\`, `composer test`.
  28 tests green on PHP 8.4 (host) and 8.3 (the container REDCap runs on).

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
