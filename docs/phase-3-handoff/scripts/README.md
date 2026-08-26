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

## `admin` form logic-error scripts

Supporting [`../19-admin-form-logic-errors.md`](../19-admin-form-logic-errors.md).

| File | Purpose |
|---|---|
| `apply-admin-calc-event-prefix.php` | Idempotent, supports `--dry-run`. Drops the stale `[baseline_arm_1]` event prefix from the four `admin` `@CALCDATE` fields whose only defect is that prefix (`calc_month_3/6/12`, `first_monday`). Asserts its own preconditions before writing — that `randomization_date` exists, that `baseline_arm_1` really is absent, and that the form is on **one event per arm** (otherwise a bare reference would be ambiguous and it aborts). Prints a before/after diff per field and reports how many `[baseline_arm_1]` references remain project-wide, which it deliberately does not touch. |
| `e2e-admin-form-user.php` | `setup` \| `teardown`. Creates a throwaway account with ordinary data-entry rights on every instrument and **no** design / user-rights / super_user, so the branching-logic banner it sees is the banner a coordinator sees. Prints the username, password and a direct data-entry URL. Password is hashed through `Authentication::hashPassword()` and then re-checked with `verifyTableUsernamePassword()`, because a fixture whose password silently does not work is the least diagnosable failure there is. **Tear it down when finished** — the password is in the source. |

## LLM request capture

Supporting [`../20-llm-request-capture.md`](../20-llm-request-capture.md).

| File | Purpose |
|---|---|
| `capture-llm-payload.php` | `on` \| `off` \| `status` \| `list` \| `last` \| `shape`. Shows the exact JSON body sent to the provider and emits a runnable curl for it. `shape` derives the envelope from this project's settings without a network call or any prompt text, so it is safe to paste into a ticket; `on`/`last` capture the real bytes. **Off by default and it must stay that way** — the request body is the prompt, which is PHI. Arming needs both a configured directory and that directory to exist; deleting either disarms it. The capture point is one line in `secure_chat_ai_v9.9.9/classes/Models/BaseModelRequest.php`, i.e. **a module outside this repository** — inert unless armed, but it must be carried across when SecureChatAI is updated. It covers six of the seven model classes, every chat path included; text-to-speech runs its own curl and is not captured. The emitted curl never contains the API key. `tests/Unit/ReasoningModelMirrorTest.php` keeps `shape`'s alias list from drifting away from SecureChatAI's. |

For "just show me the payload" there is no script — `BaseModelRequest::logFullRequest()` appends one
JSON line per request to `/var/log/apache2/secure_chat_ai.json` on **development servers only**
(gated on REDCap's `is_development_server`), so `docker exec redcap_2023_1_web tail -f
/var/log/apache2/secure_chat_ai.json` is the whole workflow. See
[`../20-llm-request-capture.md`](../20-llm-request-capture.md) §4b, including why the file must be
owned by `www-data` or web-path turns silently stop logging.

### `verify-safetyscan.php` destroys evidence — read the queue first

Its cleanup runs **unqualified** deletes (`verify-safetyscan.php:326-327`):

```php
$module->query('DELETE FROM redcap_entity_mica_scan_run', []);
$module->query('DELETE FROM redcap_entity_mica_scan_job', []);
```

Not "its own fixtures" — every scan job and every run row on the instance, including the real ones a
live session just queued. It cost the D23 investigation the failing job's rows (2026-08-25); the
diagnosis had to be rebuilt by replaying a captured body instead. **Query
`redcap_entity_mica_scan_job` before running it, not after.** Its passing result also proves less
than it looks: it drives the mock path (`scan-mock-mode`), which never builds a `response_format`, so
it cannot see a schema the provider would reject.

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
