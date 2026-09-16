# Reply to the statistician — randomization and arm placement

Drafted 2026-09-15. Points the statistician at the **prod project, PID 35968**, per instruction.

> **Provenance, before sending.** Every mechanism described below was verified on the **local** copy
> (PID 268), not on 35968 — that project is not on this instance and could not be inspected. Full
> evidence and the two open findings: [README.md](README.md). Confirm on 35968 first:
> randomization enabled with `study_group` as target field, the same trigger instrument/logic, the
> module's "automatically add the record to its randomized arm" setting on, and an allocation table
> loaded. See the checklist at the end of this file.

---

Hi [Name],

Thanks — useful list. One correction, two agreements.

**Arm assignment is automatic.** In PID 35968 each arm is one study group (1 = Standard Care,
2 = MICA, 3 = MICA + Weekly SMS), and REDCap's Randomization module is configured to fire on consent
for eligible participants: it draws the next allocation and writes it to `study_group`. Because that
field's coded values *are* the arm numbers, our MICA module then places the record into the matching
arm in the same save. Nobody assigns or moves anyone, and there's no second record — a REDCap record
spans arms under one `record_id`. Only the assigned arm is touched, so a Standard Care participant
never gets a MICA session link.

**You're right that randomization only populates a field** — REDCap has no concept of arms. The arm
placement is custom code for this study; REDCap still owns the allocation sequence, the audit trail
and the allocation table.

**You're right on timing too, and it's movable.** It currently fires at consent, before the baseline
battery. Moving the trigger to TSR (the last baseline instrument) still precedes the ED session,
which is the only hard constraint. Your call with Brian.

Related, and worth deciding at the same time: the trigger fires on eligibility alone, so a
participant who submits the consent survey *without actually consenting* would still be randomized —
REDCap saves survey data before it enforces required fields, so the signature being required does
not prevent it. I'd add `AND [consent_complete] = '2'`.

For analysis, take study group from `study_group`, not from the arm: arm 1 is both the enrollment
path and Standard Care, so everyone appears in it.

Before we go live I need three things from you: trigger timing; whether you want stratification
(none is set up, and it can't be added later without redoing the setup); and the production
allocation schedule.

Happy to walk through PID 35968 on a call.

Best,
Ihab

---

## Confirm on PID 35968 before sending

| Check | Expected |
|---|---|
| `redcap_projects.randomization` | `1` |
| `redcap_randomization.target_field` / `target_event` | `study_group` / arm 1's Day-1 (ED) event |
| `trigger_option` | `2` — option 1 is skipped on survey pages (`Randomization.php:3112`) |
| `trigger_instrument` / `trigger_logic` | `consent` / eligibility calc = 1 |
| `study_group` coded values | `1`, `2`, `3` — must equal the arm numbers |
| Module setting `materialize-assigned-arm` | enabled |
| `redcap_randomization_allocation` | non-empty for that rid, at the project's current status |

If any of these differ, the first two paragraphs above need adjusting before the email goes out.
