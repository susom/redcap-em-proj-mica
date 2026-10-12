# 2026-10-05 15:42: survey queue and e-Consent on prod, reviewed

This reviews the 15:42 prod export (`MICAEDR01_2026-10-05_1542.REDCap.xml`). Since 12:45 you:

- switched auto-continue off on 16 surveys and replaced it with a **survey queue** (104 entries,
  every one auto-start, queue hidden);
- designated Screen Eligibility on Day 1 (ED) Arm 1;
- fixed `cann_elig` for a blank AUDIT-C;
- turned on the **e-Consent Framework** on Consent;
- added a PDF Snapshot "POC Signed Consent" into a new instrument **Consent Copy**
  (`participant_consent_copy`);
- changed alert 15 to email a link to that survey instead of an attachment.

> **This is live on prod now.** Until the fix below is in, someone in prison, under 18 or over 65,
> without a phone, or pregnant can reach Consent. The export also shows alert 15 has already sent
> at least once, so a participant may have the Consent Copy page with "Remove file" (see below).
> Before anything else, check prod for records created since the switch that reached Consent
> while failing a criterion (`prison`, `sc_age`, `phone`, `preg`, `s_interest`).

## Prod steps: keep the survey queue (PI's decision, 2026-10-05)

Tested on 282 in exactly this form: prod's 15:42 setup, auto-continue off everywhere, the CSV below
uploaded through the same menu, and the two branching changes.

**1. Back up the current queue.** Online Designer → **Survey Queue ▾ → Download Survey Queue Setup
(CSV)**. Keep the file; uploading it again is the rollback. If the queue has changed since the 15:42
export, stop and send me the file: the upload below was built from 15:42.

**2. Upload the fix.** **Survey Queue ▾ → Upload Survey Queue Setup (CSV) → Choose and upload CSV
file** → `MICA-35968-SurveyQueue-FIX-20261005.csv` (in Downloads, and in
[`survey-queue/`](survey-queue/)). It takes effect immediately, Production included. REDCap lists
the 104 entries it updated.

The file changes 83 of the 104 entries and adds nothing:
- **Gates, Day 1 (ED) Arm 1:**
  - AUDITC opens only when the pre-screen passed (English, not in prison, good candidate,
    interested);
  - Consent opens only for eligible people (AUDIT-C positive, phone, not pregnant, 18–65);
  - Screen Eligibility opens for AUDIT-C-negative people and for AUDIT-C-positive people who fail
    phone, pregnancy or age.
- **"Later timepoint not started" guard on every Day 1, Month 3 and Month 6 entry,** for example
  `[month_6_arm_2][auditc_complete] <> '2' AND [month_12_arm_2][auditc_complete] <> '2'` on Month-3
  entries. An unfinished earlier survey then stops being offered once the next timepoint's AUDIT-C
  is done.
- **Arm 3 Day 1 BSCQ** waits on SIP-2R, not on itself.

From this step on, nobody who fails a criterion reaches Consent. Until step 3 is approved, two
displays are still off: a never-drinker with weekly cannabis also sees box #1, and a 17- or
70-year-old sees an empty Screen Eligibility page.

**3. Draft Mode** (Online Designer → Enter Draft Mode), then Submit:
- `desc_ineligible` branching:
  `(([s_sex] = '2' AND [audit_c_score] < 3) OR ([s_sex] = '1' AND [audit_c_score] < 4) OR [days_dr] = '0') AND [cann] <> '4' AND [cann] <> '5'`
- `desc_ineligible_hazard` branching:
  `(([s_sex] = '2' AND [audit_c_score] >= 3) OR ([s_sex] = '1' AND [audit_c_score] >= 4)) AND ([phone] = '0' OR ([s_sex] = '2' AND [preg] = '1') OR [sc_age] < 18 OR [sc_age] > 65)`
- `participant_consent_copy` (Consent Copy): Action Tags `@READONLY`. This removes "Upload new
  version" and "Remove file"; the download stays.

**4. Survey Settings → Consent Copy** (live, no draft needed): replace the instructions "Please
complete the survey below. Thank you!" with, for example, "Download a copy of your signed consent
form below. Please keep it for your records."

**5. Alerts & Notifications → alert 16:** remove "and upload the signed PDF". The snapshot does it.

**6. Check prod for people who reached Consent since the switch** (Data Exports, Reports → new
report, filter):
```
[day_1_ed_arm_1][consent_complete] = '2' and ([day_1_ed_arm_1][prison] = '1' or [day_1_ed_arm_1][english] = '0' or [day_1_ed_arm_1][goodcand] = '0' or [day_1_ed_arm_1][s_interest] = '0' or [day_1_ed_arm_1][phone] = '0' or [day_1_ed_arm_1][preg] = '1' or [day_1_ed_arm_1][sc_age] < 18 or [day_1_ed_arm_1][sc_age] > 65)
```
Any record it lists consented while ineligible and needs the study team's decision.

**7. Check, after step 2:** walk the arm-1 public link once with "in prison" (it must end on the
pre-screen), and once eligible (it must reach Consent). Or send me a fresh XML export, and I'll
rebuild it and re-run every walk.

### Verified on 282 with these steps

- **Screening:** 14 cases, 3 also on iPhone 13, all correct. Prison, not interested and not English
  end at the pre-screen. Cannabis cases see box #2 only. No phone, pregnant, 70 and 17 see box #3.
  Eligible people reach Consent. No horizontal overflow.
- **Follow-ups, arm-2 record 990:**
  - Day-1 PostSession → Close;
  - Month-3 AUDIT-C → **Month-3** DDQ, not the Day-1 SMS Code Check;
  - Month-3 DDQ left unfinished, then Month-6 AUDIT-C → **Month-6** DDQ.
- **Full Day 1, record 964:** public link → … → e-Consent → Person Obtaining Consent → … → TSR →
  randomized to **arm 3**. Close handed the participant to the arm-3 MICA ED session login. The
  consent copy filled, and alerts 15 and 16 sent.

### What these steps don't change (open questions)

- The booster → Close entry still can't run (question 5). The module needs a redirect URL there,
  which hasn't been tested yet.
- SMS Code Check is still never offered at Day 1 (question 2). The guard makes its entry harmless.
- e-Consent version, type and name fields (question 3).
- Consent copy by link vs attachment (question 1). These steps keep your link.

Every finding below was reproduced on **scratch PID 282**, built from this export, by walking the
arm-1 public link in Chromium. Phone checks used an iPhone 13 viewport. **Nothing was changed on
prod.**

## How the queue behaves in 17.2.3 (what the findings rest on)

- After a survey is submitted, REDCap first applies that survey's **auto-continue**, then its
  **redirect URL**. Only if neither applies does it show the acknowledgement. The queue's
  auto-start runs from there (`Surveys/index.php:2789-2831`, `Survey.php:470`).
- Auto-start goes to the **first** incomplete survey in queue order whose condition is met. Queue
  order is event order, then instrument order (`Survey.php:472-488`). An entry that becomes
  active and is never completed therefore captures **every later** auto-start for that record,
  including Month-3 follow-ups.
- A stop action ("End Survey") completes the response, so it satisfies "when survey X is
  completed".
- A survey completed on the data-entry form counts as completed only if the record already has a
  survey response for it (`Survey.php:230-240`).
- The MICA module continues a chat session through the host survey's **redirect URL** or
  **auto-continue** only. It does not read the queue (`MICA.php` `sessionContinuationUrl()`).

## Critical: eligibility gates were lost in the switch

They lived in the auto-continue conditions, which are now switched off. Walked on 282 **as
exported**:

| Case | Ends at |
|---|---|
| in prison ("End Survey" on Pre-screen) | **Consent**, "Congratulations! You are eligible" |
| not interested ("End Survey" on Pre-screen) | **Consent**, "Congratulations" |
| no phone / pregnant ("End Survey" on Screen2) | **Consent** |
| 70 years old / 17 years old | **Consent** |
| never drinks + weekly cannabis | box #1 "not eligible" **and** box #2 together |
| AUDIT-C-negative drinker + weekly cannabis | box #2 only (your fix works) |
| eligible man | Consent (correct) |

Box #3 (the resources message) reaches nobody: Screen Eligibility is queued only for AUDIT-C-negative
people.

### Fix: three queue conditions, two branching changes (tested)

Survey Queue (Online Designer → Survey Queue). Each change is on **Day 1 (ED) (Arm 1)**; keep the
"survey is completed" part of each entry as it is.

| Entry | "… AND the following logic is true" |
|---|---|
| **AUDITC** (after Pre-screen) | `[english] = '1' AND [prison] = '0' AND [goodcand] = '1' AND [s_interest] = '1'` |
| **Screen Eligibility** (after AUDITC) | `(([s_sex] = '2' AND [audit_c_score] < 3) OR ([s_sex] = '1' AND [audit_c_score] < 4) OR [days_dr] = '0') OR ([screen2_complete] = '2' AND (([s_sex] = '2' AND [audit_c_score] >= 3) OR ([s_sex] = '1' AND [audit_c_score] >= 4)) AND ([phone] = '0' OR ([s_sex] = '2' AND [preg] = '1') OR [sc_age] < 18 OR [sc_age] > 65))` |
| **Consent** (after Screen2) | `(([s_sex] = '2' AND [audit_c_score] >= 3) OR ([s_sex] = '1' AND [audit_c_score] >= 4)) AND [phone] = '1' AND ([s_sex] = '1' OR [preg] = '0') AND [sc_age] >= 18 AND [sc_age] <= 65` |

Branching on Screen Eligibility (Draft Mode):

| Field | Branching logic |
|---|---|
| `desc_ineligible` (box #1) | `(([s_sex] = '2' AND [audit_c_score] < 3) OR ([s_sex] = '1' AND [audit_c_score] < 4) OR [days_dr] = '0') AND [cann] <> '4' AND [cann] <> '5'` |
| `desc_ineligible_hazard` (box #3) | `(([s_sex] = '2' AND [audit_c_score] >= 3) OR ([s_sex] = '1' AND [audit_c_score] >= 4)) AND ([phone] = '0' OR ([s_sex] = '2' AND [preg] = '1') OR [sc_age] < 18 OR [sc_age] > 65)` |

`cann_elig` (box #2) stays as you have it.

Why Screen Eligibility's condition has two halves: an AUDIT-C-negative person goes there straight
after AUDIT-C; an AUDIT-C-positive person who fails phone, pregnancy or age goes there after
Screen2. A survey can have only one queue entry per event, so both paths share one condition.

**Walked on 282 after the fix:** 14 cases on desktop, 3 of them also on iPhone 13. All end where
they should, with no horizontal overflow.

| Case | Pages | Sees | Ends |
|---|---|---|---|
| prison / not interested / not English | pre_screen | "End Survey" | pre_screen's thank-you |
| negative drinker + weekly cannabis; never drinks + weekly / daily (iPhone too) | pre_screen > auditc > eligibility | box #2 only | thank-you |
| never drinks, no cannabis; negative, cannabis < weekly | pre_screen > auditc > eligibility | box #1 | thank-you |
| no phone, pregnant (stop action), 70, 17 (iPhone too) | pre_screen > auditc > screen2 > eligibility | box #3 | thank-you |
| eligible man (iPhone too), eligible woman | pre_screen > auditc > screen2 > consent | "Congratulations" | Consent |

A side benefit of the queue: AUDIT-C-negative people never see Screen2, so the empty Screen2 page
from the 12:45 review is gone, and `screen2_continue` isn't needed.

## Critical: the queue sends follow-ups back to earlier, unfinished surveys

This applies to every follow-up, not only the SMS Code Check case below. The queue's next survey
is the first unfinished one **anywhere in the record**, in event order, and auto-continue never
did that. Reproduced on 282, record 900: with its Month-3 DDQ left unfinished, the participant
submitted their **Month-6** AUDIT-C, and the next page was the **Month-3 DDQ**. Deleting the SMS
Code Check entries didn't change this.

So any participant who stops partway through a follow-up battery is taken back to it at the next
timepoint. The same goes for a half-finished Month-3 booster: at Month 6, they'd be sent back into
the Month-3 chat.

### Recommended: the queue only for Day-1 screening, auto-continue everywhere else (tested)

Auto-continue is set per instrument, wins over the queue, and only looks at the current event.

| Survey | Setting |
|---|---|
| Consent, Person Obtaining Consent, Contact Info, Check Code, Enrollment (baseline1), DDQ, AUDIT, SIP-2R, BSCQ, NM-ASSIST (drug_use), TSR, CEMI (postsession) | auto-continue **on**, no condition (as at 12:45) |
| AUDITC ("Screen") | auto-continue **on**, condition `[event-name] <> 'day_1_ed_arm_1'` |
| Pre-screen, Screen2, Screen Eligibility | auto-continue **off** (the queue routes them) |
| Survey Queue | keep **only** the four Day 1 (ED) Arm 1 entries — AUDITC, Screen Eligibility, Screen2, Consent — with the conditions in the table above; **delete every other entry** (100 of 104) |

Walked on 282 with exactly this:
- all 14 screening cases, plus 3 on iPhone 13, end as in the table above;
- arm-2 record 951: Month-3 AUDIT-C → **Month-3** DDQ; with that DDQ left unfinished, Month-6
  AUDIT-C → **Month-6** DDQ;
- the full Day-1 chain for an eligible participant: see "Core path" below.

The four queue entries that remain can only capture someone who stopped during screening or
before consent. That person was never enrolled and gets no follow-ups.

### Core path, walked under the hybrid

On 282 with MICA enabled (279's settings) and a 30-row dev allocation table, two eligible
participants walked the whole Day 1 from the public link:
- Pre-screen → AUDITC → Screen2 → Consent (with the e-Consent certification page) → Person
  Obtaining Consent → Contact Info → Check Code → Enrollment → DDQ → AUDIT → SIP-2R → BSCQ →
  NM-ASSIST → TSR → Close.
- **Record 952:** randomized to Standard Care; Close → "Thank you. You have finished this part of
  the study."
- **Record 953:** randomized to arm 2; the module added the record to arm 2, and Close redirected to
  the **MICA ED session (arm 2) login page**.
- Both: `participant_consent_copy` filled at the Person Obtaining Consent submit; alert 15 reached
  the participant at the Contact Info submit; alert 16 reached the CRC.

**Not walked:** the chat itself, and anything after it, under your current all-queue setup. I tested
the hybrid, not prod's present state, for this path.

## Critical: the arm-2 Month-3 follow-up lands on a Day-1 page

Arm 2 Day 1 has the queue entry **Close → SMS Code Check**. Close carries the redirect URL
`[ed_session_url]`, which always wins over the queue, so SMS Code Check is never offered at
Day 1. It stays active and incomplete. When the participant later finishes **Month-3 AUDIT-C**,
the queue sends them to that Day-1 page.

Reproduced on 282, record 900 (arm 2):
1. Day-1 PostSession → the queue opened Close → Close submitted.
2. The participant's Month-3 AUDIT-C link → submitted.
3. Next page: **"Your passcode for the MICA study is ______."** That is the Day-1 SMS Code Check,
   with the passcode blank because it's an arm-1 field. The Month-3 DDQ comes only after it.

The Month-3 entry (Close → SMS Code Check, `month_3_arm_2`) would do the same to the Month-6
follow-up once Month-3 Close is ever completed.

**Fix:** included in the hybrid, which deletes every entry outside Day-1 screening. If SMS Code
Check has a purpose, it needs another route (question 2).

## High: Arm 3 Day 1 BSCQ waits on itself (moot under the hybrid, which deletes the entry)

`bscq` @ `day_1_ed_arm_3` is queued "when **BSCQ** is completed". It can never start, so an arm-3
Day-1 participant stops after SIP-2R. Arm 2 has it right: "when **SIP-2R** is completed". Change
the condition survey to SIP-2R. It only matters if the arm-3 Day-1 public link is used (question 6).

## High: Consent Copy page (alert 15's link)

The chain works. Record 901:
- e-Consent certification page → PDF in the File Repository marked as a completed e-Consent;
- Person Obtaining Consent submit → "POC Signed Consent" wrote `participant_consent_copy`
  (Consent plus Person Obtaining Consent, both signatures, 6 pages);
- Contact Info submit, 41 s later → alert 15 sent to the participant, with no failed attempt while
  the email was still blank.

What the participant sees when they open the link (desktop and iPhone):

- **"Upload new version" and "Remove file"** next to their consent. "Remove file" asks "Are you
  sure…" and then says "File deleted". The file is not actually deleted, because the page has no
  Submit button (the row is hidden), but the participant is told it was.
- "Please complete the survey below. Thank you!", and "page 1 of 1".
- No login: anyone holding the link can download the signed form.

With `@READONLY` on `participant_consent_copy` (tested on 282), Upload/Remove disappear and the
download still works. Change the instructions to something like "Download a copy of your signed
consent form below." Whether to use a link at all is question 1.

## Medium

- **e-Consent settings are empty:** no version, type, name or signature fields. The e-Consent PDF's
  footer then carries only the date. Most IRBs want the consent version on it. See question 3.
- **Snapshot file name** `pid[project-id]_form[instrument-label]_id[record-name]` reaches the
  participant as `pid…_formPersonObtainingConsent_id…pdf`. Suggest
  `MICA_signed_consent_[record-name]`.
- **Alert 16** still tells the CRC to "upload the signed PDF". The snapshot does that now.
- **Booster → Close (Month 3, arms 2/3) can never run.** The booster ends inside the module, which
  follows only a redirect URL or auto-continue, and the booster has neither. It had neither at
  12:45 either. So Month-3 Close (the $30 message) and alert 18 never happen for arms 2/3. A
  redirect URL `[survey-url:close]` on the booster would route it, but that hasn't been tested
  here. And it makes the Month-3 SMS Code Check entry above a live trap, so delete that entry
  first. See question 5.

## Questions

0. **Hybrid or queue everywhere?** The hybrid is the only tested design that doesn't send
   follow-ups back to earlier unfinished surveys. If you want to keep the queue everywhere, that
   behaviour comes with it.

1. Consent copy by **link** (as you built it) or by **attachment** (12:45 design, tested on 281)?
   If link: `@READONLY`, new instructions text, and accept that the link has no login.
2. What is **SMS Code Check** for in arm 2? Can both of its queue entries go?
3. **e-Consent:** what version label (e.g. the IRB-approved consent date)? Use
   `cf_adult_consent_name` for the name and `cf_hipaa_adult_sig` as the signature field?
4. How do CRCs sign **Person Obtaining Consent**: on the tablet, or from the record? From the
   record, the queue moves on only if the survey page was opened first.
5. Month 3, arms 2/3: is **PostSession before the booster** intended? Should Close follow the booster?
6. Are the **arm-2/3 Day-1 public links** in use? Their queue goes screening → Contact Info with no
   consent (doc 33, C2).

## Scratch artifacts

- PID 282 holds the fix and test records 1–28, 900, 901.
- `e2e/eligibility-calc.js` gained `MICA_MOBILE=1` for `walk`, plus an overflow check on the
  result page.
- Local file storage was switched to a scratch folder for the consent test and restored
  afterwards (`edoc_storage_option` 5).
