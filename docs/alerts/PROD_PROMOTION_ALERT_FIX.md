# Promoting the alert fixes to production (PID 35968)

**Written 2026-09-21, after the work in [`PID271_ALERT_TEST_MATRIX.md`](PID271_ALERT_TEST_MATRIX.md).**
Nothing here has been applied to prod. Prod has never been inspected from this machine — every
claim about it below is a *question*, not a finding.

---

## What actually needs to travel

| # | Change | Carried by any export? | Scope |
|---|---|---|---|
| 1 | **Event-prefix fix**, alerts 02–14 — 48 attributes: `alert_condition`, `phone_number_to`/`email_to`, `cron_send_email_on_field`, `alert_message` all re-pointed at `[day_1_ed_arm_1][…]` | ❌ alerts travel only via the Alerts CSV | the whole reason the reminders never fired |
| 2 | **Follow-up copy**, alerts 07/08/12/13 — `alert_message` ×4 + `email_subject` ×2 | ❌ same | +92 and +98 were byte-identical |
| 3 | **`twilio_modules_enabled` → `SURVEYS_ALERTS`** | ❌ project setting | without it every SMS alert is silently rewritten to a recipient-less email (`Alerts.php:1286`) |

**Explicitly NOT in scope.** The Enhanced SMS Conversation config is a different mechanism with its
own Twilio credentials, and the weekly-SMS build does not exist on 271 either — see
[`PID271_ALERT_TEST_MATRIX.md` §9–10](PID271_ALERT_TEST_MATRIX.md). The record 11/37 test data
obviously does not travel.

**Draft Mode does not apply.** Verified in source: zero `draft_mode` references in `Alerts.php` or
`AlertsController.php`, because alerts live in `redcap_alerts`, not `redcap_metadata`. There is no
approve-changes step — **an alert edit on a production project is live the moment you save it.**

---

## Step 0 — audit prod first. This may change the fix.

These are [`ALERT_EVENT_PREFIX_BUG.md` §7](ALERT_EVENT_PREFIX_BUG.md)'s queries, still never run.
Run them before deciding anything.

```sql
SET @pid = 35968;

-- (0) which data table?  substitute into (a).
SELECT data_table FROM redcap_projects WHERE project_id = @pid;

-- (a) THE LOAD-BEARING ONE: where does enrolment data actually sit?
SELECT event_id, field_name, COUNT(*) FROM redcap_data          -- <- table from (0)
 WHERE project_id=@pid AND field_name IN ('phonen','email','randomization_date') AND value<>''
 GROUP BY event_id, field_name ORDER BY field_name, event_id;

-- (b) prod's event names must literally be day_1_ed_arm_1|2|3 - the fix is string substitution
SELECT em.event_id, ea.arm_num, em.descrip FROM redcap_events_metadata em
  JOIN redcap_events_arms ea ON ea.arm_id=em.arm_id WHERE ea.project_id=@pid ORDER BY ea.arm_num, em.day_offset;

-- (c) which alerts pin an enrolment field to an arm-2/3 Day-1 event
SELECT alert_id, alert_order, alert_title FROM redcap_alerts
 WHERE project_id=@pid AND CONCAT_WS(' ', alert_condition, phone_number_to, email_to,
       cron_send_email_on_field, alert_message) REGEXP '\\[day_1_ed_arm_[23]\\]';

-- (d) ...and which read them unprefixed while firing at an arm-2/3 event
SELECT alert_id, alert_order, alert_title FROM redcap_alerts
 WHERE project_id=@pid AND alert_condition REGEXP 'event-name.{0,12}day_1_ed_arm_[23]';

-- (e) P7: phonen must be phone-validated or the CSV upload rejects every SMS row
SELECT field_name, element_validation_type FROM redcap_metadata
 WHERE project_id=@pid AND field_name IN ('phonen','email');

-- (f) is anything half-fixed already, the way 271's alert 11 was?
SELECT alert_order, alert_condition REGEXP '\\[day_1_ed_arm_1\\]' AS cond_fixed,
       CONCAT_WS(' ',phone_number_to,email_to,cron_send_email_on_field) REGEXP '\\[day_1_ed_arm_[23]\\]' AS attrs_stale
 FROM redcap_alerts WHERE project_id=@pid ORDER BY alert_order;
```

### What the answers change

| Result | Consequence |
|---|---|
| **(a)** `phonen`/`email` cluster at the **arm-1 Day-1** event | the `contact_info` half of the fix is right — same as 271 |
| **(a)** `randomization_date` is **spread across arms** | ⚠️ **stop.** On 271 it split 2-at-arm-1 vs 4-at-arm-3, and the prefix doc calls the `admin` fields "a recommendation, not a measured fact". A blanket re-point would blank the anchor for every record whose date sits at its own arm. Split the transform by field family, or stamp the date at arm 1 first. |
| **(b)** event names differ from `day_1_ed_arm_N` | the transform needs its literals changed; the scripts derive them, a hand CSV edit does not |
| **(e)** `phonen` is not `phone`-validated | **fix that first** — `getPhoneFieldsList()` rejects the row otherwise and the upload fails wholesale |
| **(f)** some alerts already half-fixed | expected — that is what triggered all of this. The transform handles it: it is idempotent and repairs all four attributes together. |

---

## Step 0b — size the blast radius before you apply anything

Prod has real participants, and these alerts have been **silently dead since go-live**. That means
there are no `redcap_alerts_sent` suppression rows protecting anyone. The instant the fix lands,
every participant randomized more than 92/98/105 days ago has a true condition, a resolving anchor
and a send time in the past.

```sql
-- how many people become immediately due?  (adjust the data table)
SELECT COUNT(DISTINCT d1.record)
FROM redcap_data d1
LEFT JOIN redcap_data d2 ON d2.project_id=d1.project_id AND d2.record=d1.record
     AND d2.field_name='mica_booster_session_complete' AND d2.value='2'
WHERE d1.project_id=35968 AND d1.field_name='randomization_date'
  AND d1.value <> '' AND d1.value < DATE_SUB(CURDATE(), INTERVAL 92 DAY)
  AND d2.record IS NULL;
```

Two things blunt this, and one does not:

- ✅ **Nothing queues until a save lands on the alert's own firing event**
  ([§7b](PID271_ALERT_TEST_MATRIX.md)). So it will not stampede at import — it will fire
  unpredictably, whenever a CRC next opens a Month 3 record.
- ✅ `ensure_logic_still_true = 1` cancels a queued send if the participant completed in the
  meantime.
- ❌ Neither protects a participant who genuinely did not complete and was randomized 6 months ago.
  They will get a "your booster session is ready" text that is months late.

**If that count is non-trivial, stage it:** apply the alert fixes while `twilio_modules_enabled` is
still `SURVEYS` (so SMS stays inert), confirm the email side behaves, then flip step 3 deliberately.

---

## Step 1 — apply the alert changes

Two routes. **Route B needs nothing but the REDCap UI and is the tested one** — use it unless you
already have server access.

### Route A — you (or a REDCap admin) can run PHP on the prod server

Preferred: this is the tooling that was actually used and verified on 271. Both scripts take a pid,
derive the arm-1 Day-1 event structurally rather than by hard-coded id, refuse to run if the alert
set does not match, validate every rewritten condition with `LogicTester::isValid()` **before**
writing, are idempotent, and emit rollback SQL.

```bash
php apply-alert-event-prefix.php   35968            # dry run - prints the full diff
php apply-alert-event-prefix.php   35968 --apply
php apply-booster-followup-copy.php 35968           # dry run
php apply-booster-followup-copy.php 35968 --apply
```

Read the dry-run diff against step 0's audit before passing `--apply`.

### Route B — UI only ✅ recommended, and tested

**You only need the Alerts export — not the data dictionary.** Alerts live in `redcap_alerts` and
are not in `redcap_metadata`, so a Data Dictionary round trip carries **none** of these changes.

The round trip updates in place rather than duplicating, because of `Alerts.php:5766-5781`: a
populated `alert-unique-id` of the form `A-<alert_id>` that exists in the current project sets
`index_modal_update` and edits that alert; blank creates a new one.

```bash
# 1. Alerts & Notifications -> Download alerts.   KEEP THAT FILE - it is your rollback.
# 2. transform it (runs against this local REDCap, which supplies LogicTester)
docker cp prod-alerts.csv redcap_2023_1_web:/tmp/prod-alerts.csv
docker exec redcap_2023_1_web php \
  /var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/transform-alerts-csv.php \
  /tmp/prod-alerts.csv /tmp/prod-alerts-FIXED.csv
docker cp redcap_2023_1_web:/tmp/prod-alerts-FIXED.csv .
# 3. read the printed diff, then Alerts & Notifications -> Upload, SAME project.
```

[`transform-alerts-csv.php`](../phase-3-handoff/scripts/transform-alerts-csv.php) does everything
the server-side scripts do, on the CSV instead of the database. It:

- detects the delimiter and preserves the BOM, column order and quoting
- **refuses to run** if the arm-1 Day-1 event name is absent from the file (pass
  `--enrol-event=<name>` if prod names events differently — audit query (b))
- prefixes bare enrolment references **only on alerts that can never fire at the enrolment event**,
  which is what leaves alerts 01/16/17/21 alone without hard-coding their numbers
- never touches `study_group`, `[event-name]` clauses, smart variables, `alert-unique-id`, or any
  column outside the five
- identifies the +98 booster rungs by lag + condition content, not by row position
- runs `LogicTester::isValid()` on every rewritten condition and **exits non-zero** if any fails
- is idempotent — re-running on its own output reports 0 changes

#### It was verified against the database path, not just eyeballed

On 271: export the fixed state, revert both fixes from their rollback files, export the *pre-fix*
state, re-apply, export again. The two post-fix exports are byte-identical, so the revert/re-apply
cycle is lossless. Then `transform-alerts-csv.php` was run on the pre-fix export:

```
13 alerts changed, 50 cells, all conditions valid
parsed CSV identical to the database-path result: True
```

Every one of the 982 fields matches. The only byte-level difference is a trailing newline REDCap's
own export omits, which no CSV parser cares about.

> ### 🔴 A rollback file will corrupt your alert text if you restore it the obvious way
>
> Found the hard way during that test. Piping a rollback `.sql` through the mysql client
> **without** `--default-character-set=utf8mb4` silently mangles every non-ASCII character. The
> em dash in `— The MICA Study Team` came back as `â€”` in four alerts, and the middle dot in the
> CRC emails likewise. The alerts still "work"; the participant just gets mojibake.
>
> ```bash
> mysql --default-character-set=utf8mb4 -u… -p… redcap < rollback-….sql   # the flag is not optional
> ```
>
> This applies to **any** rollback in this repo, and to any hand-written SQL touching
> `alert_message`. Re-applying through the PHP scripts is unaffected — they go through REDCap's
> own connection, which is already utf8mb4. After any SQL restore, check:
> `SELECT COUNT(*) FROM redcap_alerts WHERE project_id=… AND alert_message LIKE BINARY '%â%';`

> 🔴 **Never upload 271's export to prod.** Prod's `alert_id` auto-increment is independent, so
> 271's ids (5749–5770) may exist there as *different* alerts — the upload would silently overwrite
> the wrong ones. Export from prod, transform, upload to prod.

**Three CSV traps**, all previously hit:

| Trap | Detail |
|---|---|
| **Delimiter** | `uploadAlerts` parses with the *logged-in user's* `csv_delimiter`; the download uses the same preference. Whatever delimiter prod hands you back is the one to write. A mismatch reports *"One or more components are missing"* — which reads like a bad header. |
| **No dry run** | `Alerts::validateCSVContent()` looks like a validator and **performs the import**. There is no preview. Validate conditions with `LogicTester::isValid()` on 271 first (same REDCap version). |
| **Importer stricter than runtime** | `[literal_event_name][field]` is accepted; smart variables like `[first-event-name][…]` are rejected for `send-on-field`, `phone-number-to`, `email-to`. The fix only uses literal names, so this is fine — do not "simplify" it. |

---

## Step 2 — `twilio_modules_enabled` (prerequisite P1)

**Project Setup → Enable Twilio → set the scope to cover Alerts** (`SURVEYS_ALERTS`, or `ALERTS`).
Does not travel in any export; a project copied from prod loses it, which is exactly how 271
arrived broken.

`Project.php:582` gates `twilio_enabled_alerts` on this. Until it is set, all six SMS alerts are
rewritten to `EMAIL` at `Alerts.php:1286` and — because SMS alerts keep their recipient in
`phone_number_to` and leave `email_to` empty — sent to nobody, with **no error, no log row and no
`redcap_alerts_sent` row**. That silence is the whole bug report this work started from.

Do this **last**, after step 1 is verified, per step 0b.

---

## Step 3 — verify on prod

1. Re-export the alerts and **diff every column** against the step-1 pre-upload file. Expect
   changes only in the five transformed attributes. The known benign asymmetry is REDCap storing
   NULL subjects for SMS alerts.
2. Confirm nothing is half-fixed — audit query **(f)** should return no row with
   `cond_fixed=1, attrs_stale=1`.
3. Zero stale references:
   ```sql
   SELECT COUNT(*) FROM redcap_alerts WHERE project_id=35968
    AND CONCAT_WS(' ', alert_condition, phone_number_to, email_to, cron_send_email_on_field, alert_message)
        REGEXP '\\[day_1_ed_arm_[23]\\]\\[(randomization_date|phonen|email|choice_fup_delivery|study_withdrawn|sms_stop|first_name)';
   ```
4. Pick one consenting test participant, or make a scratch record, and drive it the way
   [`../../e2e/alert-11-sms.js`](../../e2e/alert-11-sms.js) drives 271 — the save must land on the
   alert's own firing event, not on the enrolment event.

---

## Reverting on 271 (the local reference)

Two files, applied **in this order**, return 271 to its pre-fix state:

```bash
cd docs/phase-3-handoff/scripts
mysql --default-character-set=utf8mb4 -u… -p… redcap < rollback-booster-followup-copy-271-20260921-164109.sql
mysql --default-character-set=utf8mb4 -u… -p… redcap < rollback-alert-event-prefix-271-20260921-154625.sql
```

The prefix rollback carries 48 updates and the copy rollback 6. Re-running the apply scripts after
a revert reproduces the fixed state byte-for-byte — verified, that is the round trip described
above. (`rollback-record37-sms-test-*.sql` is separate: it restores alert activation and
`twilio_modules_enabled`.)

---

## Still-open items that travel with this

These are unresolved on 271 and will be unresolved on prod. None blocks the promotion; all are
worth a decision.

| | Item |
|---|---|
| **O5** | Nothing writes `sms_stop`. The `[sms_stop(1)]<>'1'` gate now *points at the right event* but still passes on a blank, so it is not a working opt-out. Twilio blocks STOP at its end; REDCap never learns. |
| **01** | The passcode SMS cannot fire where `rnd`/`calcrnd` are empty — on 271 that was all 37 records. Check prod. |
| **Month 3 trigger** | The booster ladder only queues when a save lands on the Month 3 event. **What writes there on prod, and does anything write for a participant who never engages?** If nothing does, the reminders never queue for exactly the people they target. |
| **Placeholder** | `micastudy@stanford.edu` is still the CRC address in 9 alerts. |
