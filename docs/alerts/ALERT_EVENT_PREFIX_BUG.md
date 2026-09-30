# Every reminder alert reads enrolment data at the wrong event

**Found 2026-09-21 while investigating "I changed alert 11's logic and still got no SMS on prod".**

Measured on PID 268 with `REDCap::evaluateLogic()` — the same entry point `Alerts.php` uses.
**Not yet applied anywhere, and not yet checked on prod (PID 35968).**

| | |
|---|---|
| Symptom | Booster and ED reminder alerts never fire. No error, no log, no queue row. |
| Affected | **13 alerts: 02, 03, 04, 05–14.** Alert 01 is fine. |
| Root cause | MICA enrols **every** participant at arm 1's Day 1 and then materializes only `study_group` into the assigned arm. The alerts read enrolment fields at the participant's *own* arm, where nothing was ever written. |
| Fix | One event prefix: every enrolment-field reference becomes `[day_1_ed_arm_1][…]`. |

---

## 1. Why alert 11 is still silent

Alert 11's logic was rewritten to drop the event prefixes:

```
[event-name]='month_3_arm_3' and [mica_booster_session_complete]<>'2'
and [randomization_date]<>'' and [phonen]<>'' and (...) and [study_withdrawn(1)]<>'1' and [sms_stop(1)]<>'1'
```

An unprefixed token resolves at the **firing** event, and the alert fires at `month_3_arm_3`
(event 1099). That event is designated:

```
audit, auditc, bscq, close, ddq, drug_use, mica_booster_session, mica_safety_finding, postsession, sip2r
```

No `admin`. No `contact_info`. So `[randomization_date]` and `[phonen]` are not merely empty — the
forms that hold them do not exist at that event. Clause by clause, on a scratch record with every
value present at Day 1:

```
true    [event-name]='month_3_arm_3'
true    [mica_booster_session_complete]<>'2'
FALSE   [randomization_date]<>''
FALSE   [phonen]<>''
true    ([choice_fup_delivery(2)]='1' or (...))
true    [study_withdrawn(1)]<>'1'
true    [sms_stop(1)]<>'1'
```

Two ANDed clauses are permanently false, so the condition can never be true for anyone.

**The three `true`s are worse than the falses.** `[study_withdrawn(1)]<>'1'` and `[sms_stop(1)]<>'1'`
pass because the field is unreadable, not because the participant is eligible. Repair only
`randomization_date` and `phonen` and the alert starts firing — *including to people who texted
STOP and to people who withdrew*. Twilio STOP is a legal obligation, so this clause must not be
left reading a blank.

## 2. The prefixed version does not work either

Restoring `[day_1_ed_arm_3][…]` — what is stored in PID 268 today — makes the tokens structurally
valid, because `contact_info` and `admin` *are* designated at `day_1_ed_arm_3`. It does not make
them non-empty.

Built a record the way a real arm-3 participant actually arrives (enrolment at arm 1's Day 1;
arm 3 carrying nothing but the allocation) and evaluated all three variants at `month_3_arm_3`:

| variant | result |
|---|---|
| as stored — `[day_1_ed_arm_3][…]` | **FALSE** |
| the rewrite — no prefix | **FALSE** |
| **`[day_1_ed_arm_1][…]`** | **true** |

Cross-checked on a real record rather than a scratch one — record **11**, arm 2, enrolled
2026-09-15 — with alert **06** at `month_3_arm_2`:

| variant | result |
|---|---|
| as stored — `[day_1_ed_arm_2][…]` | **FALSE** |
| **`[day_1_ed_arm_1][…]`** | **true** |

## 3. Why the data is always at arm 1's Day 1

For the `contact_info` fields this is true **by construction**, not by observation:

1. **Auto-continue cannot leave the event.** `Survey::getAutoContinueSurveyUrl()` reads
   `$Proj->eventsForms[$event_id]` — a single event — slices the instruments after the current one,
   and returns `null` when that event's surveys are exhausted. There is no branch that walks into
   another event or arm. A record created by the public `pre_screen` is in arm 1, so the entire
   battery runs at `day_1_ed_arm_1`.
2. **`contact_info` is completed before the record is randomized at all.** Instrument order:
   `contact_info` = 53, `tsr` = 129. Randomization is `trigger_option = 2` on **`tsr` at event
   1089**. So the participant fills in their phone number 76 instrument-positions before an arm is
   assigned — at which point arm 2 and arm 3 do not exist for that record.
3. **There are no ASIs.** `redcap_surveys_scheduler` is empty for PID 268, so nothing ever invites a
   participant to `contact_info` at their own arm's Day 1 afterwards.
4. **Materialization writes one field.** `MICA.php::ensureRecordInAssignedArm()` writes only
   `study_group` into the assigned arm — deliberately, so a control participant cannot be handed the
   intervention. Nothing copies contact details across.

The module's own event resolution already encodes this: `firstEventHostingForm()` derives arm 1
Day 1 as *"the event every record passes through before randomization"*, and that is where
`ed_session_url` and the randomization-date stamp are written.

Confirmed against the data. Every `phonen` value in PID 268 — all 21 — is at event 1089, including
record **31**, created 2026-09-21 *after* the designation change:

```
20260921102527   record 31    event 1089   first_name = 'Ihab', ... phonen = '(330) ...'
```

The only records with contact data at 1097 are `ZZARM3` and `ZZBOOST`, seeded by hand at 12:41 on
2026-09-21 to test these very alerts.

### The `admin` fields are a weaker case — treat them separately

`randomization_date`, `study_withdrawn` and `sms_stop` live on **`admin`**, which is designated at
**all three** Day-1 events. Unlike `contact_info`, nothing forces where they are written: `admin` is
a data-entry form, so the value lands wherever the CRC happens to open it. The only two real
`randomization_date` values in PID 268 are at 1089, but both were typed by hand — two keystrokes are
not a rule.

**Make it a rule instead: enable `stamp-randomization-date-field`.** It is currently unset on
PID 268. When set, `MICA.php::writeSessionUrl()` stamps the date at
`firstEventHostingForm($proj, 'admin')`, which is arm 1 Day 1 by derivation — so
`[day_1_ed_arm_1][randomization_date]` becomes true by construction, exactly as it already is for
`contact_info`. It only writes when the field is empty across *every* event, so a date a CRC already
entered is never overwritten — which means enabling it fixes new records and leaves existing ones to
be checked by hand.

Until that is settled, `[day_1_ed_arm_1]` on the `admin` fields is the best available choice — it is
the one event all three arms share as the enrolment event — but it is a recommendation, not a
measured fact. Worth one question to the study team: *where do CRCs open the admin form?*

## 4. Correction to `ALERTS_CONTACT_FIELD_BREAKAGE.md` §3 and §4

That document withdrew an earlier recommendation to use `[day_1_ed_arm_1]`, on the grounds that the
21 rows at 1089 were "evidence about the *old* structure". **For the `contact_info` fields the
earlier recommendation was right** — §3 above proves it by construction. For the `admin` fields it
is still the best choice but remains unproven either way.

The designation change made `contact_info` *exist* at 1093/1097. It did not make anything *write*
there, and §3 does not identify a mechanism that would — because there isn't one (§3 above).

§4's end-to-end verification then reported alerts 03, 11, 13, 14 as **true** "on scratch records …
with the contact fields entered at Arm 3's Day 1". That is circular: the data was placed where the
alerts expected it, so the test could only pass. Re-run against data placed where the study actually
puts it, the same alerts are false.

The designation change was not wasted — `contact_info` at all three Day-1 events is harmless and
makes the arm-2/3 events usable if the study ever does collect there. It just is not the fix.

## 5. What to change

Three attributes per alert, not just the condition. A correct condition with a blank
`phone-number-to` sends to nobody and **logs nothing** (`Message.php:974` deletes the
`redcap_outgoing_email_sms_log` row on failure), and a blank time-lag anchor is never scheduled at
all.

| Attribute | Now | Should be |
|---|---|---|
| `alert_condition` | unprefixed, or `[day_1_ed_arm_2\|3][…]` | `[day_1_ed_arm_1][…]` |
| `phone_number_to` / `email_to` | `[day_1_ed_arm_2\|3][phonen\|email]` | `[day_1_ed_arm_1][…]` |
| `cron_send_email_on_field` | `[day_1_ed_arm_2\|3][randomization_date]` | `[day_1_ed_arm_1][randomization_date]` |
| `alert_message` body | `[day_1_ed_arm_2\|3][first_name]` etc. | `[day_1_ed_arm_1][…]` |

`[literal_unique_event_name][field]` is the one cross-event form the alert importer accepts for
`phone-number-to` and `send-on-field` — smart variables like `[first-event-name][…]` are rejected on
import even though they resolve at send time. So `[day_1_ed_arm_1][phonen]` is legal in all four
places.

### Alerts 05–14 — mechanical prefix swap

86 references across 10 alerts. `[day_1_ed_arm_2]` → `[day_1_ed_arm_1]` and `[day_1_ed_arm_3]` →
`[day_1_ed_arm_1]`, in `alert_condition`, `phone_number_to`/`email_to`,
`cron_send_email_on_field` and `alert_message`. The `[event-name]='month_3_arm_2|3'` clause stays
as it is — that one correctly names the firing event.

Alert 11 in full:

```
[event-name]='month_3_arm_3'
and [mica_booster_session_complete]<>'2'
and [day_1_ed_arm_1][randomization_date]<>''
and [day_1_ed_arm_1][phonen]<>''
and ( [day_1_ed_arm_1][choice_fup_delivery(2)]='1'
      or ([day_1_ed_arm_1][choice_fup_delivery(1)]<>'1' and [day_1_ed_arm_1][choice_fup_delivery(2)]<>'1') )
and [day_1_ed_arm_1][study_withdrawn(1)]<>'1'
and [day_1_ed_arm_1][sms_stop(1)]<>'1'
```

with `phone-number-to` = `[day_1_ed_arm_1][phonen]` and send-on-field =
`[day_1_ed_arm_1][randomization_date]`.

### Alerts 02, 03, 04 — add a prefix that is not there at all

These fire at `day_1_ed_arm_2|3` and read `[randomization_date]`, `[phonen]`, `[email]`,
`[choice_fup_delivery]`, `[study_withdrawn]`, `[sms_stop]` **unprefixed**, so they resolve at the
firing event — which is the participant's own arm, where the data is not. Same swap: prefix each
with `[day_1_ed_arm_1]`. Leave the `[event-name]` clause alone.

### Alert 01 — leave it

It fires at whichever Day-1 event is being saved and reads unprefixed `[consent_complete]`,
`[contact_info_complete]`, `[phonen]`, `[calcrnd]`. For a real participant that save *is* at
`day_1_ed_arm_1`, so the unprefixed references resolve correctly.

## 6. After changing the logic, the alert still will not send until the queue is cleared

`reevaluate_send_time = 0` on all of these, so the send time is computed **once**, when the logic
first goes true. Editing the condition does not reschedule an existing recurrence row, and
`redcap_alerts_sent` has a unique key on (alert, record, event, instrument, instance) that
suppresses a re-send.

To retest a record after a fix:

```sql
DELETE FROM redcap_alerts_recurrence WHERE alert_id = <id> AND record = '<rec>';
DELETE FROM redcap_alerts_sent       WHERE alert_id = <id> AND record = '<rec>';
-- then re-save the record so the condition is re-evaluated
```

Then force the cron; note `AlertsNotificationsSender` has `cron_frequency = 60`, so two forced runs
inside a minute silently do nothing — backdate `redcap_crons.cron_last_run_start`/`_end` between
runs.

Also check the time lag: alert 11 is `randomization_date + 92 days`. A participant randomized less
than 92 days ago is correctly not due yet.

## 7. Checking prod

Nothing here has been applied to PID 35968, and prod's structure has never been verified against
this. Run there:

```sql
SET @pid = 35968;

-- (0) which data table does this project use? substitute it into (a).
SELECT data_table FROM redcap_projects WHERE project_id = @pid;

-- (a) where does enrolment data actually sit? expect one event: the arm-1 Day 1.
SELECT event_id, field_name, COUNT(*) FROM redcap_data        -- <- table from (0)
 WHERE project_id=@pid AND field_name IN ('phonen','email','randomization_date') AND value<>''
 GROUP BY event_id, field_name ORDER BY field_name, event_id;

-- (b) which alerts pin a field to an arm-2/3 Day-1 event
SELECT alert_id, alert_title FROM redcap_alerts
 WHERE project_id=@pid AND CONCAT_WS(' ', alert_condition, phone_number_to, email_to,
       cron_send_email_on_field, alert_message) REGEXP '\\[day_1_ed_arm_[23]\\]';

-- (c) and which read them unprefixed while firing at an arm-2/3 event (alerts 02-04 shape)
SELECT alert_id, alert_title FROM redcap_alerts
 WHERE project_id=@pid AND alert_condition REGEXP 'event-name.{0,12}day_1_ed_arm_[23]';
```

If (a) returns a single arm-1 Day-1 event for `phonen` — which §3 shows the design forces — then
every row (b) and (c) return is dead, and the same prefix swap applies.

**Identifier piping is not a factor here, checked:** `phonen` and `email` carry `field_phi = 1`, but
`first_name` does not, so the `[…][first_name]` greeting in alerts 05–13 pipes fine despite
`prevent_piping_identifiers = 1`. The three alerts that *do* pipe `[phonen]`/`[email]` in the body —
04, 09, 14, the CRC escalations — already have `prevent_piping_identifiers = 0`.
