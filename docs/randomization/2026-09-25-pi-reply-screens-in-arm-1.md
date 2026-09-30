# Reply to the PI: why screens are logged under an arm

Drafted 2026-09-25, in answer to:

> "If people are not randomly assigned to an arm until after completing screen, consent, contact
> info, SMS check and baseline assessments, then why are screens logged under a specific arm?"

> **Provenance, before sending.** Checked against the PI's export
> `NEWTESTMICAR01_2026-09-25_1646.REDCap.xml` (metadata only) and on local copies:
> **PID 271** (module on), **272** (built from the 10:58 export) and **273** (built from the 16:46
> export). Prod 35968 was not inspected. In particular, whether the module's "automatically add the
> record to its randomized arm" setting is on for 35968 is unconfirmed.

---

## Draft

Hi [PI name],

Good question. REDCap files every record under an arm from the moment it is created, and in this
project Arm 1 does two jobs.

**REDCap has no "not yet assigned" arm.** In a project with several arms, a record exists in
whichever arm its first answer is saved in. The screening survey starts in Arm 1, so everyone who
is screened is filed under Arm 1, before anyone knows their group.

**Arm 1 is both the enrollment path and the Standard Care arm.** Screening, consent, contact info,
the SMS check and the baseline surveys all run in Arm 1; the eligibility page and consent exist only
there. Randomization happens when the baseline is finished (TSR). REDCap then writes the group to
`study_group`, and our module adds MICA and MICA + SMS participants to Arm 2 or Arm 3, where their
session and follow-ups are. Standard Care participants simply stay in Arm 1.

So being listed under Arm 1 means "screened", not "assigned to Standard Care". For counts and
analysis the group is always `study_group`, never the arm. A count of Arm 1 is a count of screenings
and enrollments.

What I suggest:

1. **Rename Arm 1 to "Screening + Standard Care (SC)".** It's only a display name. Event names,
   branching, alerts, randomization and data are all unaffected, because REDCap builds event names
   from the arm's number, not its name. This removes the impression that every screened person was
   put in Standard Care.
2. **Take the screening and baseline surveys off Arms 2 and 3.** They are attached there today but
   never used there, and that has a side effect: REDCap offers a separate public screening link for
   each arm. I tried the Arm 2 link on test copies of both of today's versions. An eligible person
   went straight from the screen to contact info, with no eligibility page and no consent, and was
   filed under MICA without being randomized. Until the surveys are removed, please use only the Arm
   1 link.

Happy to make either change with you.

---

## Also found in the 16:46 export (send with the reply, or separately)

These come from the changes made today, and they matter more than the arm label:

- **Minors, people over 65 and people in prison can reach consent.** The new consent condition checks
  AUDIT-C, phone and pregnancy, but not age or prison; the deleted `calc_screen_result` checked
  both. The age question only shows a warning below 18 or above 65, which staff can dismiss, and
  nothing after it checks age. On a copy of the export, a 17-year-old, a 70-year-old and someone
  flagged as being in prison each got "Congratulations! You are eligible" and the consent form.
- **The stop actions don't stop the survey.** In this REDCap version a stop action ends only the
  current survey, and the next one still opens automatically. On the copy, patients marked "not
  English", "not interested" or "in prison" each continued into AUDIT-C and reached consent. The fix
  is one condition on the pre-screen survey's auto-continue setting (below).
- **Never-drinkers see no message.** Someone who never drinks sees neither "not eligible" nor the
  cannabis-study message, even if they use cannabis daily. They have no AUDIT-C score, so the
  "AUDIT-C negative" test in both messages is false for them. Brackets alone don't fix it; both
  messages would need `OR [days_dr] = '0'` in that test. Restoring the calc fixes it too, because
  it already treats never-drinkers as not eligible.
- **`randomization_date` was deleted, but other parts of the project still use it.** On the copy,
  any logic that mentions it now evaluates to false, so alerts 02–04 (the ED-session reminders) can
  never fire. The 3-, 6- and 12-month dates, the weekly-SMS start (`first_monday`, `weekday`) and
  `calc_esms_valid` point at a field that no longer exists, and the module stops recording the date.

**Recommended:**
- Restore `calc_screen_result` with the tested equation, and base the consent condition and the
  messages on it.
- Restore `randomization_date`.
- Set the pre-screen survey's auto-continue condition. This version (no `md_assent`) was checked
  on the copy: an eligible man continues, while "in prison", "not English" and "not interested"
  stop at pre-screen.
  ```
  [english] = '1' AND [goodcand] = '1' AND [s_interest] = '1' AND [prison] = '0'
  ```
  Don't use the earlier version that includes `[md_assent] = '1'`: that field is gone, and on this
  version it stops everyone at pre-screen.

What's tested: the equation (1,011 combinations), and this condition on today's version. What isn't
yet: the restored calc together with today's new layout ("Congratulations" on the consent page, the
cannabis question for never-drinkers).

---

## Evidence

### Where screening is stored, and how records move between arms

| Project | Records | Arms (`redcap_record_list`) |
|---|---|---|
| 272, PI's structure, screened through the public link in a browser | 54 | **arm 1 only**. Screening answers are stored at "Day 1 (ED)", arm 1 "Standard Care (SC)" |
| 271, module on, randomized to MICA | 5 | arms **1 and 2** |
| 271, module on, randomized to MICA + SMS | 4 | arms **1 and 3** |
| 268, 2026-09-15, randomized to Standard Care | 1 | **arm 1 only** ([README.md](README.md)) |

No randomized record is in its assigned arm alone. Everyone stays in arm 1, where their screening
and baseline live.

How it happens:
- REDCap's randomization writes `study_group` at `day_1_ed_arm_1`. That's the target event in the
  16:46 export, and the trigger is `[day_1_ed_arm_1][tsr_complete]=2`, option 2.
- `MICA::ensureRecordInAssignedArm()` reads that value as an arm number (`MICA.php:1539-1540`) and
  saves `study_group` at that arm's first event, which adds the record to the arm.

### Which arm each Day-1 instrument is on (16:46 export)

| Instrument | Arm 1 | Arm 2 | Arm 3 |
|---|:---:|:---:|:---:|
| `pre_screen`, `auditc`, `screen2` | ✓ | ✓ | ✓ |
| `screen_eligibility`, `consent`, `person_obtaining_consent`, `check_code` | ✓ | · | · |
| `contact_info`, `baseline1`, `ddq`, `audit`, `sip2r`, `bscq`, `drug_use`, `tsr` | ✓ | ✓ | ✓ |
| `sms_code_check` | ✓ | ✓ | · |
| `mica_ed_session`, `postsession`, `mica_safety_finding` | · | ✓ | ✓ |
| `close`, `admin` | ✓ | ✓ | ✓ |

### The wrong-arm link, reproduced

Survey Distribution Tools builds one public link per arm (`Surveys/invite_participants.php:289`),
because `pre_screen` is on every arm's first event. Using arm 2's link (`e2e/eligibility-calc.js
wrongarm <pid> 2`), an eligible man went `pre_screen` → `auditc` → `screen2` → **`contact_info`**,
all at arm 2's Day 1. He never reached `screen_eligibility` or `consent`, and his record is in
**arm 2 only**, with no randomization. This happened on both copies: 272 (10:58 export, record 55)
and 273 (16:46 export, record 11).

### Renaming arm 1 is safe

- Unique event names are the event's own name plus the arm **number**
  (`Classes/Project.php:1284-1314`), so `day_1_ed_arm_1` doesn't change. Renaming an **event** would
  change it; don't.
- The module finds arms by number, never by name.
- Only the click-paths in [`../alerts/pi-review/build-sms-manual.js`](../alerts/pi-review/build-sms-manual.js)
  ("Arm 1: Standard Care (SC)") would need their wording updated.

### Taking the pre-randomization instruments off arms 2 and 3

Nothing in the 16:46 export references those instruments' fields at an arm-2 or arm-3 event (0
references), and the randomization trigger and target are both at `day_1_ed_arm_1`.

- **Remove from Day 1 of arms 2 and 3:** `pre_screen`, `auditc`, `screen2`, `contact_info`,
  `sms_code_check`, `baseline1`, `ddq`, `audit`, `sip2r`, `bscq`, `drug_use`, `tsr`.
- **Keep:** `admin` (the module writes `study_group` there), `close`, and the session instruments.

**Check prod first:** REDCap warns before removing an instrument that has data at an event. If it
warns, stop, because removing it would hide that data.

### The 16:46 eligibility logic, reproduced

Built from the 16:46 export as PID 273 and walked in a browser through the public link
(`e2e/eligibility-calc.js walk 273 …`):

| Participant | Result |
|---|---|
| eligible man (control) | "Congratulations" at the top of consent ✓ |
| man aged 70, AUDIT-C 8, phone | **consent** |
| woman aged 17, AUDIT-C 4, phone, not pregnant | **consent** |
| in prison (stop action on `pre_screen`), AUDIT-C 8, phone | continued, **consent** |
| not English (stop action), AUDIT-C 8, phone | continued, **consent** |
| not interested (stop action), AUDIT-C 8, phone | continued, **consent** |
| never drinks, cannabis daily | **no message**, thank-you page |
| never drinks, no cannabis | **no message**, thank-you page |
| AUDIT-C 2, cannabis weekly | cannabis message only ✓ |

Runtime checks on 273, using `REDCap::evaluateLogic`:
- The 16:46 `pre_screen` condition (no `md_assent`) lets the eligible man (record 3) continue, and
  stops "in prison" (6), "not English" (7) and "not interested" (12).
- The 10:58 condition (with `md_assent`) stops the eligible man too.
- `[day_1_ed_arm_1][randomization_date] <> ''` and `= ''` are **both false**, because logic that
  names a missing field is false as a whole.
- `LogicTester::isValid` accepts all of these. It checks syntax, not whether the fields exist, so
  it can't be used to catch a deleted field.

Details of the equation, the stop-action finding and its fix are in
[`../screening/ELIGIBILITY_CALC_PI_XML_2026-09-25.md`](../screening/ELIGIBILITY_CALC_PI_XML_2026-09-25.md).
