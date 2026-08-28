# Testing the chatbot + safety review (PID 257, localhost)

**Rewritten 2026-08-28** for the revised project structure. The previous version
described a shortcut (create the record straight into arm 2, fill two fields) that no
longer matches how the project works — keep it only if you want to bypass
screening/randomisation entirely; it is recorded at the bottom.

Verified end-to-end before publishing — see §Verification.

---

## 🔴 One prerequisite before you start

**Alert 01 (`01 Phone check - passcode by SMS`) is currently DEACTIVATED.** The flow
below asks the participant to verify their phone by entering a passcode, and that
passcode arrives *only* from alert 01. With it off there is nothing to enter.

```bash
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
UPDATE redcap_alerts SET email_deleted=0
 WHERE project_id=257 AND alert_title LIKE '01 %';"
```

⚠️ **Twilio is live** — this sends a real text to whatever goes in `phonen`. Use your
own mobile. Turn it back off when you're done.

*Alternative if you'd rather not send an SMS:* the passcode is also displayed on the
`sms_code_check` survey, which `check_code` auto-continues into. See the note in
§Findings.

---

## The steps

### 1. Open the public survey link

*Survey Distribution Tools → **Public Survey Link*** (the link belongs to
`pre_screen`). On localhost this is currently:

```
http://redcap.local/surveys/?s=THC3WH49DTXDHY9E
```

### 2. Work through the chain

REDCap auto-continues, so this is one continuous run. The resolved order (verified
via `Survey::getAutoContinueSurveyUrl()`):

```
pre_screen → screen → screen_eligibility → consent
   → person_obtaining_consent → baseline1 → check_code → sms_code_check
```

Two things to know:

- **`screen_eligibility` gates on `calc_eligible = '1'`.** Answer `screen` so you
  come out eligible — age 18–65, has a cell phone, not military, not in prison, not
  pregnant — or the chain stops there and never reaches consent.
  ([`../ELIGIBILITY_GATE.md`](../ELIGIBILITY_GATE.md))
- ⚠️ **`person_obtaining_consent` has auto-continue OFF**, so the chain **stops
  there** even though the next survey resolves to `baseline1`. That form is the
  staff consent-witness signature. In the real study a CRC is present at the ED and
  moves things along; when testing alone you'll need to open the `baseline1` link
  yourself from the Record Home Page to carry on.

Put **your own mobile** in `baseline1.phonen`. Submitting `baseline1` is what fires
the passcode SMS.

### 3. Complete `check_code`, then close the survey link

Enter the passcode from the text. `calc_code_check` should compute **1** and
`desc_code_pass` should display. Then close the tab — the participant-facing part is
done.

### 4. Randomise, on the **Arm 1** `admin` form

Open the record in the REDCap UI, **arm 1**, `admin`, and fill:

| Field | |
|---|---|
| `randomization_date` | the anchor for **every** reminder ladder (alerts 02–14) |
| `randomize_trigger` | `1, Randomize` |
| `study_group` | `2, MICA` or `3, MICA + Weekly SMS` |

Saving this makes the record appear in the chosen arm.

**It must be a UI save.** The materialisation runs from the module's
`redcap_save_record` hook, and REDCap calls that hook from exactly one place —
`DataEntry.php:6735`, the form/survey save path. **An API or data-import write of
`study_group` will not materialise the arm** (verified: `REDCap::saveData()` left the
arm-2 event absent).

Precisely what happens, since "copy the record" is a slightly misleading mental
model — `MICA.php:715`:

> *"A REDCap record spans arms under the same `record_id` — there is nothing to
> 'copy'. A record simply appears in an arm once it has at least one saved value in
> an event of that arm, which is also what `REDCap::getSurveyLink()` checks before it
> will mint a link."*

So the module writes `study_group` into the first event of the assigned arm. Only the
**assigned** arm — deliberately, so a Standard Care participant can never be handed a
`mica_ed_session` link.

`study_group` alone drives this. `randomize_trigger` is **not read by the module** (no
branching logic on it either) — it is a staff/workflow field, so set it for realism,
not because the arm copy needs it.

### 5. Open the session survey link on arm 2 / 3

Record Home Page → switch to the assigned arm → the survey icon on the
**`MICA ED session`** row. Log in with the **phone number**.

### 6. Chat, then End Session

Say something a safety screen should catch, or you'll get a clean screen with nothing
to review. The four concern types no study can exclude are `self_harm`, `violence`,
`medical_emergency`, `abuse_or_environmental_danger`
(`FindingThresholds::NEVER_EXCLUDABLE`); urgency is `quality` / `moderate` / `high` /
`critical`. A self-harm mention is the most reliable trigger.

End Session signs you out on this project — there is no `posttest` survey to land on.
Expected, not a fault.

### 7. Review

Wait ≤60 s for `mica_scan_worker`, then *project menu → **MICA Safety Review***.

```bash
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
SELECT id, record, status, attempts, LEFT(COALESCE(last_error,''),60) AS err
  FROM redcap_entity_mica_scan_job WHERE project_id=257 ORDER BY id DESC LIMIT 3;"
```

---

## Findings from re-verifying this flow

### 🔴 The survey-login event was wrong for this flow — fixed

On 2026-08-27 the login credential was changed to `phonen` at event **1008** (arm-2
Day 1). Under this flow that is **broken**: the participant fills `baseline1` through
the public-link chain at event **1004** (arm-1 Day 1), and materialisation writes
*only* `study_group` into the arm-2 event. `phonen` is never at 1008, so the login
would find an empty credential.

**Corrected to `phonen` @ event 1004.** Verified with record 6, whose arm-2 event
contains only `study_group`: the login prompt appeared, the phone number was
accepted, and the chat mounted.

This also **resolves the arm-3 problem** flagged in
[`../SURVEY_LOGIN.md`](../SURVEY_LOGIN.md): 1004 is the pre-randomisation event that
*every* record passes through regardless of assigned arm, so one credential slot now
covers arms 2 and 3. That is why the original design pointed here.

### `sms_code_check` is reachable after all — correcting an earlier note

`SURVEY_LOGIN.md` / alerts `README.md` O5 said `sms_code_check` needed a direct link
and so was "not reachable by accident". Wrong: **`check_code` auto-continues straight
into it**, and it displays *"Your passcode for the MICA study is `[calcrnd]`."*

- **Useful when testing** — it's how you get the passcode without sending an SMS.
- **Worth a decision for production** — someone holding the `check_code` link can
  submit anything, auto-continue, read their own code, and go back and enter it. The
  phone check is then self-certifying.

---

## Verification

| Check | Result |
|---|---|
| Public link resolves | `?s=THC3WH49DTXDHY9E` → page titled **Prescreen** |
| Auto-continue chain | resolved via `Survey::getAutoContinueSurveyUrl()` — see §2 |
| `person_obtaining_consent` breaks the chain | `end_survey_redirect_next_survey = 0` on survey 1300 |
| `randomize_trigger` read by the module? | **no** — grep finds it only in a docs comment; no branching logic |
| Arm materialisation on API save | **does not happen** — arm-2 event absent after `saveData()` |
| Arm materialisation on UI save | `ensureRecordInAssignedArm()` → `materialized`; arm-2 event created with `study_group` |
| Session link after materialisation | minted (not null) |
| Login with `phonen` @ 1004 | accepted; dialog gone; **"Loading your MICA session…"**; no launch-gate refusal |
| SafetyScan pipeline | `preflight-safetyscan.php 257` → **PASS** end to end |

**Record 6 is left in place**, shaped like a real participant (`phonen`
`(650) 555-0199` at 1004 only, `study_group = 2`, arm 2 materialised) — use it to
skip straight to step 5.

PID 257 is development status, so a session runs even if a launch gate fails, with a
banner saying so. That banner is not a failure here.

---

<details>
<summary>Previous shortcut path (bypasses screening and randomisation)</summary>

Still works, and is the fastest way to reach the chat if you don't care about the
participant journey:

1. *Add / Edit Records → arm 2 → Add new record → Day 1 (ED)*.
2. Fill `baseline1` in the UI: `first_name` + `phonen`. Save.
3. Open the `MICA ED session` link, log in with the phone number.

⚠️ **This no longer works with the corrected login event.** The credential is now
read at event **1004**, so a record created directly in arm 2 has no `phonen` there.
Either add `phonen` at the arm-1 Day 1 event too, or use the full flow above.
Record 5 is an example of this now-broken shape.
</details>
