# Auth configuration scripts (Option B)

Reproducible apply + verify for the REDCap-side authentication configuration described in
[`../08-auth-discovery.md`](../08-auth-discovery.md) and recorded in
[`../10-auth-implementation-pid257.md`](../10-auth-implementation-pid257.md).

These exist because the correct configuration is **counterintuitive** — one credential slot,
read from the pre-randomization event, with slots 2 and 3 left empty — and a well-meaning
admin reproducing it by hand from prose could easily add a second slot, which is a login
bypass (`10 §3`). The verifier asserts that specifically.

| File | Purpose |
|---|---|
| `apply-auth-config.php` | Idempotent. Adds the mount-point field to each host instrument, makes each host instrument repeating at every event it is designated to, enables both as surveys with link time limits and "Repeat Survey", and configures scoped Survey Login with a single credential slot. Event ids are derived from the instruments' own designations, not hardcoded. |
| `verify-auth-config.php` | Independent check of the intended end state. Does not reuse the apply logic. Exit 0 = pass. |
| `manual-test-auth.php` | Test-participant lifecycle for hand-testing the gate: `setup` \| `links` \| `reset-lockout` \| `teardown`. Creates a participant that satisfies the real preconditions (credential at the pre-randomization event, then randomized into an arm so a link can exist). Walkthrough: [`../11-auth-manual-test-guide.md`](../11-auth-manual-test-guide.md). |

## Running

Both scripts locate `redcap_connect.php` automatically (walking up from their own directory,
falling back to `/var/www/html`); override with `REDCAP_ROOT` if needed.

Against the dockerised REDCap used in development:

```bash
CONTAINER=redcap_2023_1_web    # the REDCap web container

# copy the whole directory (avoid `cp dir/. dest/` - it can silently under-copy)
docker cp docs/phase-3-handoff/scripts $CONTAINER:/var/www/html/temp/
S=/var/www/html/temp/scripts

# arguments: <pid> <credential_field> <credential_event_id>
docker exec $CONTAINER php $S/apply-auth-config.php  257 last_name 1004
docker exec $CONTAINER php $S/verify-auth-config.php 257 last_name 1004

docker exec $CONTAINER rm -rf /var/www/html/temp/scripts   # clean up
```

Defaults are `pid=257`, `credential_field=last_name`, `credential_event_id=1004`
(Day 1 (ED) arm 1 — the pre-randomization enrollment event).

## Before running against another project

1. **`apply` refuses to run unless the project is in Development**, because adding dictionary
   fields in Production must be drafted through REDCap's change-request flow instead.
2. **Confirm the credential event.** It must be an event where the credential field is
   populated for *every* participant who will use MICA. If it is blank for a record, that
   record can be entered with a blank submission (`10 §3`) — `verify` reports the count of
   such records, and the module must refuse to issue links for them.
3. The host instruments (`mica_ed_session`, `mica_booster_session`) must already exist and be
   designated to their events in the MICA arms. `apply` checks this and aborts otherwise, and
   it reads those designations to decide where to make each instrument repeating — so the
   designations must be correct *before* `apply` runs.

## Rollback

See [`../10-auth-implementation-pid257.md`](../10-auth-implementation-pid257.md) §5 for the
revert SQL.
