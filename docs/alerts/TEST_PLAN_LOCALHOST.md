# End-to-end test plan — MICA alerts on localhost (PID 257)

Companion: [`test_helpers.sql`](test_helpers.sql) — every SQL block referenced as
**W1**, **S1**, **T1** etc. lives there.
Project: <http://redcap.local/redcap_v17.2.3/index.php?pid=257> (login `ihabz`)

> **Recipient numbers below are redacted.** Every `+1 650-555-0123` in this file was a
> real, working line that received the SMS during the run; this repository is public and
> `phonen` doubles as the survey login credential, so it is replaced throughout with a
> reserved fictional number. The delivery evidence is unaffected — what each row asserts
> is that the SMS reached *record 3's `phonen`*, not which digits those were. Email
> recipients are study/staff mailboxes and are left as they were.

---

## ✅ Step 0 is DONE (applied 2026-08-27 by request)

| | |
|---|---|
| Alerts in PID 257 | **21** — duplicate 5670 deleted |
| Active | **0 — all 21 deactivated** |
| MailHog | **running**, inbox at <http://127.0.0.1:8025/mailhog/> |
| Twilio | live credentials, scope `SURVEYS_ALERTS` — **SMS still really sends** |

<details>
<summary>What Step 0 had to fix, and why (background)</summary>

`redcap_log_event15` showed that on 2026-08-27 at 10:23, account `ihabz` clicked
through the Alerts UI: **25 × "Reactivate alert"** (`AlertsController:deleteAlert`)
and **1 × "Copy alert"** (`AlertsController:copyAlert`). The copy produced alert
**5670**, byte-identical to 5668 (`20 CRC - Month 12 follow-up done`), which would
have double-sent that CRC email. All 22 were left ACTIVE with live Twilio.

Nothing had fired — alerts only evaluate **on record save**, and no record had been
saved since. Both issues are now resolved. A pre-change backup of `redcap_alerts`
is in the session scratchpad as `257_alerts_pre_step0.sql`.
</details>

**Both 0d and 0e are now settled** — your own mobile, on **record 3** (arm 1 event
1004 for Test 1, arm 2 event 1008 for Tests 2 onward).

---

## Step 0 — Make the sandbox safe

### 0a. Delete the duplicate — ✅ DONE
```bash
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
SELECT alert_id, alert_order, alert_title FROM redcap_alerts
 WHERE project_id=257 AND alert_title LIKE '20 CRC%';"
# confirm 5668 and 5670 are the pair, then:
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
DELETE FROM redcap_alerts WHERE alert_id=5670;"
```

### 0b. Deactivate everything — ✅ DONE (helper **S1**)
Controlled testing means one alert at a time. You cannot tell which of 21 alerts
sent an email if they all fire at once.
```bash
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
UPDATE redcap_alerts SET email_deleted=1 WHERE project_id=257;"
```

### 0c. MailHog — ✅ DONE

Email goes msmtp → smarthost `mailhog`. That container was commented out in
`rdc/docker-compose.yml`; it is now uncommented (backup:
`docker-compose.yml.bak-20260827-104109`) and running. This mattered because
without it every email send *fails*, and a failed send is **invisible**:

- `redcap_alerts_sent` / `redcap_alerts_sent_log` are only written **inside the
  success branch** (`Alerts.php:1515`)
- the `redcap_outgoing_email_sms_log` row is **deleted** on failure (`Message.php:974`)

So a broken alert and a working alert whose email bounced look **identical**.

**Inbox: <http://127.0.0.1:8025/mailhog/>** — note the `/mailhog` path
(`MH_UI_WEB_PATH: mailhog`); bare `127.0.0.1:8025` returns 404.

Verified end-to-end: a message pushed through `/usr/sbin/sendmail` in the web
container arrived in the inbox, so REDCap's own mailer will too.

**The inbox has ~4,900 old messages** from the persisted `mailhog-volume`. Clear it
so your test emails are easy to spot:
```bash
curl -X DELETE http://127.0.0.1:8025/mailhog/api/v1/messages
```

To restart it later: `cd .../rdc && docker compose up -d mailhog`

### 0d. Decide your SMS policy — ⬅ YOUR CALL

6 of the 21 alerts are `alert_type='SMS'` and Twilio is live. Pick one:

- **Recommended:** put **your own mobile** in `phonen` on the test record. You'll
  see the real text, which is the only way to confirm the passcode flow properly.
- **Cheapest:** leave `phonen` as a fake number. Twilio rejects it, the alert logs
  nothing, and you check `redcap_twilio_error_log` (helper **D1**) to confirm it
  *tried*. Proves the condition and recipient resolution, not delivery.
- **Zero risk:** never activate the 6 SMS alerts; test the email half only.

### 0e. Which record — ✅ SETTLED: **record 3**

Test 1 ran on record 3 at event **1004** (arm 1). Record 3 was then extended into
arm 2, so it holds data at **1004 and 1008**, and Tests 2+ run at **1008**.

Records 1, 2 and MICATEST01 are still unusable: 1 and 2 sit at
`baseline1_complete = 1` (Unverified) while every gate is written against `'2'`, and
they carry two different `calcrnd` values across arms.

**A record spanning two arms is fine here — this was verified, not assumed.** Every
field reference in the ladder alerts is unprefixed, so it resolves to the alert's own
event; `REDCap::evaluateLogic` confirms arm-1 values are invisible at 1008 and that
`[event-name]` blocks 02/03/04 at 1004 entirely. Details in Tests 2+3, Step 1.

The one consequence: **every reset must be scoped to record *and* event**, or it
deletes Test 1's evidence at 1004. Helper **R1** enforces this.

---

## How the machinery works (30 seconds, saves an hour of confusion)

1. **Alerts evaluate on record save.** Saving any form re-evaluates every active
   alert for that record. The cron does *not* discover newly-true conditions for
   non-datediff alerts.
2. **The cron sends — but only `TIME_LAG` alerts.** `send-on: NOW` alerts (01, 15–21)
   are sent **synchronously inside the save**; no recurrence row is ever created and
   the cron plays no part. For `TIME_LAG` alerts (02–14),
   `AlertsNotificationsSender` runs at frequency **60 s**. To force it:
   ```bash
   docker exec redcap_2023_1_cron wget -q -O- http://web/cron.php
   ```
   ⚠️ Forcing it twice inside 60 s **silently skips the task** — backdate
   `redcap_crons.cron_last_run_start` first (see the harness note under Tests 2+3).
   And remember an empty queue is **not** evidence a `NOW` alert failed — check
   `redcap_alerts_sent`.
3. **Two distinct states.** `redcap_alerts_recurrence` = *scheduled*.
   `redcap_alerts_sent` = *delivered*. Helper **W1** shows both.
4. **`reevaluate_send_time = 0`** on all 21. The send time is computed **once**,
   when the logic first becomes true. Backdating `randomization_date` afterwards
   does **not** reschedule an existing queue row — you must clear the queue and
   re-save. That's why helper **T1** is ordered clear → backdate → re-save.

---

## Test 1 — Requirement 1: the passcode SMS

**Alert 01.** Fires on **`baseline1`** submit at a Day 1 event when `consent` and
`baseline1` are both Complete, `phonen` and `calcrnd` are non-empty, and the
participant hasn't withdrawn or opted out. Texts `[calcrnd]` to `[phonen]`. Once per
record. *(Changed from `close` on 2026-08-27 — see O4 in [`README.md`](README.md).)*

1. Activate only it:
   ```bash
   docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
   UPDATE redcap_alerts SET email_deleted=1 WHERE project_id=257;
   UPDATE redcap_alerts SET email_deleted=0 WHERE project_id=257 AND alert_title LIKE '01 %';"
   ```
2. In the UI: **Add new record**, choose arm **2 — MICA**, event **Day 1 (ED)**.
3. Complete `consent` → status **Complete**.
4. Complete `baseline1` (Enrollment). Fill `first_name`, `email`, **`phonen` = your
   mobile**, and tick `choice_fup_delivery`. Status **Complete**.
   - Confirm `calcrnd` is now a **4-digit** number. If it's 8 digits the P2 fix
     didn't take — run Data Quality rule **H**.
   - `dummy_email` should self-populate to `noreply@stanford.edu` (`@DEFAULT`).
5. Complete the baseline battery, then `close` → status **Complete**.
6. Wait ~70 s or force the cron. Run **W1**.

**Pass:** one row under SENT, `alert_type=SMS`, `phone_number_to` = your number,
body reads *"MICA: your verification passcode is NNNN"* with a real 4-digit code.
**And the text arrives.**

7. **Close the loop:** open `check_code`, type that code into `passcode`, save.
   `calc_code_check` must compute **1**, and `desc_code_pass` (not
   `desc_code_wrong`) must display. *This is the actual requirement — the SMS is
   only half of it.*

> If nothing sends, run helper **D1**. The usual cause is a form left at
> Unverified (`1`) instead of Complete (`2`).

### ✅ Test 1 RESULT — 2026-08-27 — passed, with one caveat

Record **3**, event **1004** (arm 1 Day 1). The full requirement closed:

| Step | Evidence |
|---|---|
| SMS sent | `redcap_alerts_sent` row, alert 5649, `2026-08-27 11:13:46` |
| Delivered to the right number | `redcap_outgoing_email_sms_log.recipients = +1 650-555-0123` = record 3's `phonen` |
| Body piped correctly | *"MICA: your verification passcode is **2576**…"* — 4 digits, so the **P2 `calcrnd` fix took** |
| Loop closed | `passcode = 2576` → `calc_code_check = **1**` |

**Ignore `redcap_alerts_sent_log.phone_number_to` — it reads the literal `[phonen]`.**
That is not a piping failure. `addRecordSent()` (`Alerts.php:1833`) copies that
column straight from the *alert definition*, unpiped; the piped value
(`Alerts.php:4442`) only ever lands in `redcap_outgoing_email_sms_log`. **W1** has
been corrected to read the recipient from the authoritative table.

#### Alert 01 was edited mid-test — reconciled

`redcap_log_event15` records two `Modify alert` events on A-5649 at **11:09:35** and
**11:11:04**, immediately before the 11:13 send: trigger form `close` → `baseline1`,
and the `[close_complete]='2'` clause dropped. The tested alert therefore fires as
soon as `baseline1` is Complete, not after the baseline battery.

**Resolved the same day: `baseline1` is the shipping behaviour.** The build script
and all five prod upload variants were regenerated to match, and a re-run of the
full 21-alert DB-vs-CSV diff shows **0 drift**. Test 1 stands as passed against the
config that will actually ship. Rationale and evidence: [`README.md`](README.md) O4.

The plan text above still describes the old `close` trigger — **alert 01 now fires
on `baseline1` save**, so a `close`-completion step is no longer needed for Test 1.

---

## Tests 2 + 3 — one pass: the +1 week reminder AND the channel split

**Run these together.** They use the same two alerts (02/03), the same anchor, and
the same record; Test 3's positive case *is* matrix row **B** below.

### 🔴 Read this first — three traps

1. **The record must be in arm 2 or arm 3.** Alerts 02/03/04 are gated to
   `[event-name]='day_1_ed_arm_2' or 'day_1_ed_arm_3'`. Record 3 is in **arm 1
   (Standard Care)**, so it can **never** trigger them — correctly, since arm 1
   gets no MICA session and `mica_ed_session` isn't even designated at event 1004.
   **Record 3 cannot be reused for these tests.**
2. **Three of the four rows send a real text to your mobile.** Twilio is live.
3. **No randomization needed.** The conditions key off `[event-name]`, not
   `[study_group]`, so just pick the arm when you create the record.

### Setup — already applied

Only **02** and **03** are active (all other 19 deactivated, including 01 — so the
passcode SMS won't fire again on the new record):

```
5650  02 MICA ED session 1-week reminder (email)   EMAIL   ** ACTIVE **
5651  03 MICA ED session 1-week reminder (SMS)     SMS     ** ACTIVE **
```

### Step 1 — the record: reuse **record 3 at the arm-2 Day 1 event**

Record 3 was extended into arm 2 on 2026-08-27, so it now holds **both** arms:

| Record 3 | Event | Contents |
|---|---|---|
| arm 1 Day 1 | **1004** | the full Test 1 dataset (73 fields, `calcrnd` 2576) |
| arm 2 Day 1 | **1008** | `study_group=2` + `admin` saved (6 fields) |

That dual-arm shape is unrealistic for a real participant, but it is **provably safe
for these tests**. Every field reference in alerts 02/03/04 is unprefixed, so it
resolves to the alert's *own* event. Verified with `REDCap::evaluateLogic` against
record 3 before testing:

| probe | @1004 (arm 1) | @1008 (arm 2) |
|---|---|---|
| `[event-name]='day_1_ed_arm_2'` | false | **TRUE** |
| `[randomization_date]<>''` | TRUE | false |
| `[email]` / `[phonen]` / `[calcrnd]` | TRUE | false |
| **alert 02 full condition** | **false** | false *(until 1008 is filled)* |

So arm-1 data cannot leak into the arm-2 evaluation, and **alerts 02/03/04 can never
fire at 1004** — `[event-name]` blocks them. No new record needed.

### Step 2 — fill four fields AT THE ARM-2 EVENT

🔴 **Everything goes in at `Day 1 (ED)` under arm 2 — not arm 1.** The arm-1 copies
of these fields are already populated from Test 1 and are invisible to the arm-2
evaluation, so filling the wrong arm produces silent nothing.

These alerts need **no** `_complete` statuses, so this is lighter than Test 1.

| Form | Field | Value |
|---|---|---|
| `admin` | `randomization_date` | **today minus 7 days** — type it directly, no SQL |
| `baseline1` | `email` | your address (MailHog catches it) |
| `baseline1` | `phonen` | your mobile |
| `baseline1` | `choice_fup_delivery` | per the matrix below |

Leave `mica_ed_session` **incomplete**. Leave `study_withdrawn` and `sms_stop` unticked.

Typing the anchor as today−7 avoids the `reevaluate_send_time=0` trap entirely:
send time = anchor + 7d = today 00:00, already past, so it's due on the next cron.

**Alert 01 will not re-fire**, so the arm-2 `baseline1` save is safe: `alert_stop_type
= RECORD` (not `RECORD_EVENT`), and record 3 already has its sent row. It's also
deactivated. No duplicate passcode SMS.

⚠️ **Resets must be event-scoped.** Record 3's Test 1 evidence is
`alert_sent_id 132` at event **1004**; a record-only reset would delete it. Helper
**R1** is now scoped to `@rec='3', @ev=1008` and re-checks that row afterwards.

### Step 3 — the matrix

For each row: set `choice_fup_delivery` on `baseline1` → **Save** → force the cron →
run **W1**. Between rows run the scoped **R1** reset (`@rec='3', @ev=1008`).

| # | `choice_fup_delivery` | Anchor | Expected under QUEUED | Expected under SENT |
|---|---|---|---|---|
| **A** | Email only (1) | today−**6** | **alert 02 row, `next_send_time` = tomorrow** | **empty** |
| **B** | Email only (1) | today−**7** | — | alert **02** only, email |
| **C** | SMS only (2) | today−7 | — | alert **03** only, SMS |
| **D** | **both** ticked | today−7 | — | alert **03** only, SMS wins |
| **E** | **neither** ticked | today−7 | — | alert **03** only, SMS fallback |

Force the cron with:
```bash
docker exec redcap_2023_1_cron wget -q -O- http://web/cron.php
```

**Pass:** rows B–E each send **exactly one** alert, the one named. Two sends, or
zero, is a failure.

> ⚠️ **Row A will show a QUEUED row — that is the correct result, not a failure.**
> The condition *is* true at day 6; only the send time isn't reached. So the pass
> criterion for A is **QUEUED populated, SENT empty**, not "nothing anywhere". If
> row A shows nothing at all under QUEUED either, something is wrong with the
> condition, not the timing.
>
> Judge that from **`first_send_time`**, not `next_send_time`. `next_send_time` stays
> NULL while the row is IDLE and is only written when the cron flips it to SENDING
> (`Alerts.php:1006`); the due-ness test the cron runs (`:996`) reduces to
> `first_send_time <= NOW()` when `cron_repeat_for = 0`.

### ✅ Rows A and B RESULT — 2026-08-27 — both passed

Driven with `REDCap::saveData()`, which fires the same `saveRecordAction` the UI does
(`Records.php:7489`), so alert evaluation is identical to a form save.

**Row A — negative control (anchor `2026-08-20`+... i.e. `randomization_date = 2026-08-21`, today−6):**

| | |
|---|---|
| QUEUED | alert **02**, `first_send_time = 2026-08-28 00:00:00` (= anchor + 7d), status IDLE → **not due** |
| SENT | **empty** ✅ |
| MailHog | 0 messages ✅ |
| alert 03 | **did not queue at all** — the SMS condition is false with Email-only, so the channel split already holds in this direction |

**Row B — `randomization_date = 2026-08-20` (today−7):**

Queue cleared first (`reevaluate_send_time = 0` means an existing row never
reschedules), then re-saved → `first_send_time = 2026-08-27 00:00:00` → **DUE NOW**.

| | |
|---|---|
| SENT | alert **02** only, EMAIL, `2026-08-27 12:06:52` |
| Recipient | `ihab.zeedia@stanford.edu` (from `redcap_outgoing_email_sms_log`) |
| Subject | *"Your MICA session is waiting for you"* |
| Body piping | *"Hi **Ihab**,"* — `[first_name]` resolved ✅ |
| Queue after send | **empty** (`cron_repeat_for = 0`, sent once) ✅ |
| Test 1 evidence | alert 5649 @ event 1004 **still intact** ✅ |

**Test 3's payload also verified** — the body carried exactly one link,
`http://redcap.local/surveys/?s=Skrmq2fcygCiNbuA`, which resolves to:

| hash → | value |
|---|---|
| `form_name` | **`mica_ed_session`** ✅ |
| `event_id` | **1008** (arm 2 Day 1) ✅ |
| `record` | **3** ✅ |

Fetched it: **HTTP 200**, page title *"MICA ED session"*, `#pagecontainer` hidden and
the survey login dialog rendered asking for **`phonen`** — so the login change is
live and finds a value at 1008. No `survey_589` ("no data for the required fields")
error. See [`../SURVEY_LOGIN.md`](../SURVEY_LOGIN.md).

### ✅ Rows C, D, E RESULT — 2026-08-27 — all passed. Tests 2 and 3 COMPLETE.

| Row | `choice_fup_delivery` | Queued | Sent | Delivered to |
|---|---|---|---|---|
| **C** | SMS only | **03 only** | **03** SMS `13:04:56` | +1 650-555-0123 |
| **D** | **both** ticked | **03 only** | **03** SMS `13:05:50` | +1 650-555-0123 |
| **E** | **neither** ticked | **03 only** | **03** SMS `13:05:56` | +1 650-555-0123 |

In every row alert **02 never even queued** — so the email was excluded by the
*condition*, not merely suppressed. **SMS wins when both are ticked; SMS is the
fallback when neither is.** These are the two cases ASPIRE's equivalent logic got
wrong.

**Decisive cross-check — MailHog still holds exactly 1 message** (row B's email).
Zero emails leaked across C/D/E, so the split is genuinely exclusive rather than
merely ordered. Full delivery log for PID 257, all five sends and nothing else:

| id | type | time | to |
|---|---|---|---|
| 9816 | SMS | 11:13:47 | +1 650-555-0123 — Test 1 passcode |
| 9817 | EMAIL | 12:06:53 | ihab.zeedia@stanford.edu — row B |
| 9818 | SMS | 13:04:56 | +1 650-555-0123 — row C |
| 9819 | SMS | 13:05:51 | +1 650-555-0123 — row D |
| 9820 | SMS | 13:05:57 | +1 650-555-0123 — row E |

SMS body as delivered, with `[first_name]` piped and the same survey hash as the
email:

```
Hi Ihab, it's the MICA Team. Your MICA session is ready (about 20 min):
http://redcap.local/surveys/?s=Skrmq2fcygCiNbuA  Reply STOP to opt out.
```

Requirement 2 (channel split) and Requirement 3 (+1 week reminder, with a working
link) are both **verified end-to-end**.

---

### 🔧 Harness note: the 60-second cron gate will fake a failure

Row D initially showed **queued and DUE, but nothing sent** — which looks exactly
like the invisible-failure case (`redcap_alerts_sent` written only on success,
`redcap_outgoing_email_sms_log` row deleted on failure). It was neither.

`AlertsNotificationsSender` has `cron_frequency = 60`. Forcing `cron.php` twice
inside that window means the **second run skips the task entirely**. The tell is the
queue row still sitting at `status = 'IDLE', times_sent = 0` with an empty
`redcap_twilio_error_log` — a real send failure would have moved the status.

Don't wait it out; clear the gate so each row is deterministic:

```bash
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
UPDATE redcap_crons SET cron_last_run_start = DATE_SUB(NOW(), INTERVAL 1 HOUR),
                        cron_last_run_end   = DATE_SUB(NOW(), INTERVAL 1 HOUR)
 WHERE cron_name IN ('AlertsNotificationsSender','AlertsNotificationsDatediffChecker2');"
docker exec redcap_2023_1_cron wget -q -O- http://web/cron.php
```

### How these rows were driven

`REDCap::saveData()` rather than UI typing, because `Records.php:7489` calls the
**same** `$eta->saveRecordAction()` the UI does — alert evaluation is identical. The
data-entry path itself was already proven in Test 1.

Each row is: reset → save → inspect queue → clear cron gate → cron → inspect sent.
**The reset must clear alert 02's `redcap_alerts_sent` row too.** Otherwise "02 did
not send" is the unique-key suppression talking, not the condition, and the test
proves nothing.

**Row B also carries Test 3's real payload:** open the email in
<http://127.0.0.1:8025/mailhog/> and **click the `mica_ed_session` link**. It must
open the ED session for record 3 at the **arm-2** Day 1 event. A link that 404s, or
opens the wrong record, fails Test 3 even though the send succeeded.

Rows **A** and **E** are the two the plan's earlier version under-tested, and
they're where ASPIRE's equivalent logic was wrong.

---

## Test 4 — the self-cancelling ladder ⭐ most important test

This is the one thing that, if broken, makes the feature worse than nothing —
participants who finished would still be chased.

**Reuse record 3 @ event 1008 from Tests 2+3 — no new setup needed.** Just reset it
(scoped **R1**, `@rec='3', @ev=1008`) and change the anchor to today−14.

1. Activate `02 %`, `03 %` **and** `04 %` (the CRC escalation).
2. Set `randomization_date` to **today−14**. Re-save. **Do not force the cron yet.**
3. Run **W1** — you should see rows under **QUEUED** for the +7 and +14 alerts.
4. Now **complete `mica_ed_session`** (status Complete).
5. Force the cron. Run **W1** again.

**Pass:** the queued rows are **gone** and **nothing sent**. `ensure_logic_still_true=1`
makes the cron re-evaluate at send time and delete the queued recurrence
(`Alerts.php:1051-1058`).

**Fail:** an email/SMS goes out to someone who already finished, and the CRC is
told to call them. If you see that, stop and tell me.

### ✅ Test 4 RESULT — 2026-08-27 — PASSED, with the counterfactual proven

Record 3 @ 1008, anchor `randomization_date = 2026-08-13` (today−14), Email-only
preference so any escape would land in MailHog rather than on a phone.

**Both rungs queued and DUE** before the session was completed — i.e. the harmful
state was genuinely reached, not merely simulated:

| aq_id | alert | rung | `first_send_time` | |
|---|---|---|---|---|
| 19 | **02** ED reminder (email) | +7 | `2026-08-20 00:00` | **DUE** |
| 20 | **04** CRC "please call" | +14 | `2026-08-27 00:00` | **DUE** |

*(Alert 03 correctly absent — Email-only.)*

**Cancellation is a cron-time action, not a save-time one.** After saving
`mica_ed_session_complete = '2'`, **both rows were still queued** — `IDLE`, same
`first_send_time`. Nothing is cleared on save even though
`do_not_clear_recurrences = 0`. Only on the next cron run did
`ensure_logic_still_true = 1` re-evaluate and delete them (`Alerts.php:1051-1058`).

That matters operationally: **between a participant finishing and the next cron
tick, the send is still queued.** With a 60 s cron that window is small, but it is
not zero.

Result after the cron:

| | |
|---|---|
| Queue rows for 3@1008 | **0** ✅ |
| Sent rows for 3@1008 | **0** ✅ |
| New deliveries | **none** — latest was still row E at 13:05:57 ✅ |
| MailHog | still **1** message ✅ |
| Test 1 evidence @1004 | intact ✅ |

#### The counterfactual — proving completion is what caused it

"The queue emptied" on its own doesn't establish causality. Re-run with **only**
`mica_ed_session_complete` changed back to `'0'`, everything else identical:

| Session status | Alert 02 | Alert 04 |
|---|---|---|
| `'2'` Complete | not sent, queue deleted | not sent, queue deleted |
| `'0'` Incomplete | **SENT** 13:12:17 | **SENT** 13:12:17 |

So the ladder self-cancels **because** the session was completed. Requirement 3's
"if still not completed" clause is genuinely conditional.

---

### ✅ Test 5 RESULT — 2026-08-27 — PASSED (covered by the counterfactual above)

Alert **04** delivered to `micastudy@stanford.edu` at 13:12:17.

Subject: *"MICA **3**: ED session not completed after 2 weeks - please call"* —
`[record-name]` piped.

Body, as delivered:

```
Open record 3
Study group: MICA · Randomized: 2026-08-13
The participant has not completed the MICA ED session 14 days after randomization.
An automated reminder was sent on day 7.
Action: phone the participant. Contact: (650) 555-0123 / ihab.zeedia@stanford.edu
```

| Requirement | Result |
|---|---|
| CRC can actually place the call | ✅ **both phone and email piped** — `prevent_piping_identifiers = 0` is working as designed (it is `1` on the other 18 alerts) |
| `[form-link:admin:…]` resolves | ✅ `DataEntry/index.php?pid=257&page=admin&id=3&event_id=1008&instance=1` — correct pid, form, record **and event** (1008 = arm 2). URL structure verified; not opened, as it requires a logged-in session. |
| `study_group` / `randomization_date` piped | ✅ "MICA" / 2026-08-13 |

---

## Test 5 — Requirement 4: escalation to the CRC

1. Activate `04 %` only.
2. Apply **T1** with `@days=14`, leave the session **incomplete**, re-save, cron.

**Pass:** one email to `micastudy@stanford.edu`* with subject
*"MICA <record>: ED session not completed after 2 weeks - please call"*. Body must
show the **actual phone number and email** (this alert deliberately has
`prevent_piping_identifiers=0` so the CRC can place the call) and a working
`[form-link:admin:…]` link that opens the record.

*\* placeholder address — see the open items in [`README.md`](README.md).*

---

## Test 6 — the booster ladder (Month 3)

Same shape, different anchor offsets, and **duplicated per arm** (alerts 05–09 for
arm 2, 10–14 for arm 3).

| Rung | `@days` in **T1** |
|---|---|
| booster reminder | 92 |
| booster +1 week | 98 |
| CRC escalation | 105 |

Use a record in arm 2 for alerts 05–09 and a **separate** record in arm 3 for
10–14. Complete `mica_booster_session` at the **Month 3** event to test cancellation.

**Pass:** the arm-2 record only triggers 05–09; the arm-3 record only 10–14. Cross-firing
means the `[day_1_ed_arm_N]` event prefixes are wrong.

Also confirm `[form-link:close:…]` resolves in alert 09/14 — those fire at Month 3,
where `admin` is **not** designated, which is why they use `close` instead.

### ✅ Test 6 RESULT — 2026-08-27 — PASSED. No cross-arm firing.

Two records, one per arm. Record 4 created in **arm 3** for this test.

| Record | Arm | Day 1 event (anchor + contact) | Month 3 event (trigger) |
|---|---|---|---|
| 3 | 2 | 1008 | **1009** |
| 4 | 3 | 1012 | **1014** |

Booster alerts fire at the **Month 3** event and read the anchor and contact fields
**cross-event** with a literal prefix (`[day_1_ed_arm_2][randomization_date]`), which
is why the ladder is duplicated per arm. To make REDCap evaluate them, a save must
happen **at the Month 3 event** — that is what `mica_booster_session_complete` was
written for here.

**Pass 1 — anchor today−92. Rung staging and arm isolation:**

| Record | Event | Queued | Rung | `first_send_time` | |
|---|---|---|---|---|---|
| 3 | 1009 (M3 arm2) | **05** | 92 | 2026-08-27 | **DUE** |
| 3 | 1009 | 07 | 98 | 2026-09-02 | not due |
| 3 | 1009 | 09 | 105 | 2026-09-09 | not due |
| 4 | 1014 (M3 arm3) | **10** | 92 | 2026-08-27 | **DUE** |
| 4 | 1014 | 12 | 98 | 2026-09-02 | not due |
| 4 | 1014 | 14 | 105 | 2026-09-09 | not due |

**Zero cross-firing** — record 3 queued only 05/07/09, record 4 only 10/12/14. The
`[day_1_ed_arm_N]` prefixes are correct. SMS rungs (06/08/11/13) correctly absent on
an Email-only preference. Cron sent exactly **05 and 10**; MailHog delta **2**.

**Pass 2 — anchor today−105, all three rungs due.** All six sent, still perfectly
split by arm; MailHog delta **6**:

| Record | Sent |
|---|---|
| 3 (arm 2) | 05 (92) · 07 (98) · **09 CRC** (105) |
| 4 (arm 3) | 10 (92) · 12 (98) · **14 CRC** (105) |

**`[form-link:close:…]` resolves at Month 3** — confirmed that `admin` is *not*
designated at 1009/1014 while `close` is, which is the reason these two alerts use
it:

| Alert | Link |
|---|---|
| 09 (arm 2) | `…DataEntry/index.php?pid=257&page=close&id=3&event_id=1009&instance=1` |
| 14 (arm 3) | `…DataEntry/index.php?pid=257&page=close&id=4&event_id=1014&instance=1` |

Cross-event piping all resolved, no leftover `[...]` in either body — and
`study_group` came through **differently per arm**, which independently confirms the
two records really are in different arms:

```
alert 09 → Study group: MICA · Randomized: 2026-05-14
alert 14 → Study group: MICA + Weekly SMS · Randomized: 2026-05-14
```

**Booster self-cancellation also confirmed** — with `mica_booster_session_complete='2'`
the ladder produced **0 queued, 0 sent, MailHog delta 0**. Note this exercised a
*different* protection than Test 4: here the condition was already false at
evaluation time so nothing ever queued, whereas Test 4 proved an *already-queued*
row is deleted when the condition later goes false. Together the two orderings are
covered.

---

## Test 7 — the ASPIRE ports

| Alert | Trigger | Watch for |
|---|---|---|
| `15` consent PDF | upload a file to `consent_pdf` on `person_obtaining_consent` | email to the **participant** with the PDF **attached** (`attachment_names` populated in **W1**) |
| `16` consented | `consent` → Complete | CRC email, once per record |
| `17` baseline complete | `close` → Complete at Day 1 | CRC email |
| `18/19/20` follow-up done | `close` → Complete at Month 3 / 6 / 12 | fires at the **right** event only |
| `21` opt-out | tick `admin.sms_stop` | CRC email, **and** every SMS alert now self-suppresses — re-run Test 3 and confirm silence |

Alert 21 also covers the arm-3 opt-out survey, but only for arm 3 (event 1013 is
arm-3-only), so don't expect that half to fire on an arm-2 record.

### ✅ Test 7 RESULT — 2026-08-27 — scoped to 3 checks (see O6), all PASSED

Run on record 3. Alert **21 was deliberately not tested** — it cannot fire (O5/O3).

| # | Sent to | Subject | Attachment |
|---|---|---|---|
| **15** | `ihab.zeedia@stanford.edu` (participant) | *Stanford Research Contact Information and Consent Form* | **`MICA_signed_consent_record3.pdf`** |
| **16** | `micastudy@stanford.edu` | *MICA 3: Consented* | — |
| **17** | `micastudy@stanford.edu` | *MICA 3: Baseline done* | — |
| **18** | `micastudy@stanford.edu` | *MICA 3: Month 3 follow-up done* | — |

**Alert 15's attachment verified on the wire, not just in the log.** A real 403-byte
PDF was uploaded through REDCap's own `Files::uploadFile()` (returning `doc_id 2469`,
storage option 5), then the delivered message was pulled apart in MailHog:

```
Content-Type: application/pdf
Content-Disposition: attachment; filename=MICA_signed_consent_record3.pdf
decoded 403 bytes, magic = b'%PDF-1.4'
```

Byte count matches the source and the magic number is a genuine PDF header — so the
attachment pipeline works end to end. This mattered because
`redcap_alerts_sent_log.attachment_names` being populated only proves REDCap
*intended* to attach something.

Alert **17** also confirmed still working after O4 moved alert 01 off `close`.

**Not covered:** alerts **19/20** (identical to 18 bar the event pinning, which Test 6
already proved) and **16**'s desirability, which is a CRC product question.

---

## 🔴 Two mechanism findings from Test 7 — both matter beyond testing

### 1. Form-triggered alerts NEVER fire on an API / data-import save

Discovered because driving alerts 15–18 with `REDCap::saveData()` produced **nothing
at all**, while the same conditions evaluated `TRUE` when checked directly.

`Alerts.php:193` forces the instrument to empty on an import:

```php
$viableAlerts = $this->getAlertsForInstrumentSave($project_id, $record, $event_id,
                    ($isDataImport ? "" : $instrument), $repeat_instance, $repeat_instrument);
```

and `getAlertsForInstrumentSave` (`:171-176`) then can only match:

```sql
(a.form_name = '<instrument>' and a.form_name != '' and ...)   -- '' != '' is always FALSE
or (a.alert_condition is not null and a.form_name is null)     -- logic-only alerts only
```

`Records::saveData` passes `$isDataImport = true` (`Records.php:7489`), and
`$fieldsModified` is **not** used to recover the instrument (it only feeds the
trigger-date check at `Alerts.php:220`). So on any API/import save, a form-triggered
alert is invisible.

**Which MICA alerts are affected:**

| Affected — skipped on API/import | Immune — logic-only, fire on any save |
|---|---|
| **01** passcode SMS (`baseline1`) | 02, 03, 04 (ED ladder) |
| **15** consent PDF (`person_obtaining_consent`) | 05–14 (booster ladders) |
| **16** consented (`consent`) | 21 opt-out |
| **17** baseline done (`close`) | |
| **18, 19, 20** follow-up done (`close`) | |

**The production risk is 18/19/20.** They trigger on `close` at Month 3/6/12 — and
bulk-importing follow-up data is a completely routine study workflow. Import the
Month 6 follow-ups and the CRC is simply never told. Same class of problem for alert
**01** if `baseline1` data ever arrives via API: no passcode SMS, and it is
`ONCE`/`RECORD`-scoped so it never retries.

Mitigation if imports are ever used for these forms: convert the affected alerts to
**logic-only** (clear `form_name`, keep the condition). The conditions already pin
the event and require the `_complete = '2'` status, so they are self-sufficient — the
form trigger is redundant belt-and-braces that costs import compatibility.

### 2. `send-on: NOW` alerts send synchronously — the cron is not involved

The test plan above says "wait ~70 s or force the cron". That is true only for
`TIME_LAG` alerts. All four of 15–18 had **already been delivered** the instant the
form-save trigger returned, with **no recurrence row ever created** and no cron run
in between.

| `cron_send_email_on` | Path | MICA alerts |
|---|---|---|
| `now` | **sent synchronously inside `saveRecordAction`** | 01, 15–21 |
| `time_lag` | queued in `redcap_alerts_recurrence`, sent by the 60 s cron | 02–14 |

This also retro-explains Test 1: alert 01's send is logged at `11:13:46`, which is the
moment the user saved `baseline1` — not a later cron tick.

Practical consequence: **an empty queue is not evidence a `NOW` alert failed.** Check
`redcap_alerts_sent` first. It also means the O4 concern about a participant reaching
`check_code` before the passcode SMS arrives is smaller than stated — the send is
initiated during the save itself, with only Twilio latency after that.

### How 15–18 were driven

`REDCap::saveData()` cannot trigger them (finding 1). Instead the identical entry
point a real UI save uses was invoked directly, with the same arguments
`DataEntry.php:6790` passes:

```php
$eta->saveRecordAction(257, '3', $form, $event_id, 1, null, null, true, true, false, []);
//                                                                          ^^^^^ isDataImport = FALSE
```

The record data itself was written normally beforehand. **This exercises the real
alert-evaluation path; it does not exercise form rendering or client-side validation.**

---

## Between runs — reset

`redcap_alerts_sent` has a unique key per (alert, record, event, instrument,
instance), so a `RECORD`-scoped alert will **never re-send** while its row exists.
That looks like a broken alert on your second attempt. Clear it with helper **R1**,
which empties the queue, the sent tables and the per-alert sent flags.

Then **Step 0b** again (deactivate all) before the next test.

---

## When you're done

Leave every alert **deactivated**. `micastudy@stanford.edu` is still a placeholder,
and prod promotion is a separate exercise — [`PROD_PROMOTION.md`](PROD_PROMOTION.md).
