# Emailing the signed consent form to the participant

**Asked 2026-09-23 by the PI, about PID 271.** Diagnosis from the database and REDCap 17.2.3 source.
Nothing has been changed or tested yet.

## What exists today (271 and prod are the same)

The intended sender is **alert 15, "Participant - signed consent PDF"**:

| | |
|---|---|
| Trigger form | `person_obtaining_consent` |
| Condition | `[consent_pdf]<>'' and [email]<>''` (ensure logic still true) |
| To | `[email]` |
| Attachment | the file in `[consent_pdf]` |
| State | **deactivated** (`email_deleted = 1`, which `Alerts.php:83` reads as "deactivated"). Same in the 2026-09-23 prod export. |

It cannot send in the normal flow, for two reasons:

1. **Nothing fills `consent_pdf`.** It is a plain file-upload field on `person_obtaining_consent`
   labelled "Upload consent here:". There is no e-Consent framework (`redcap_econsent` has no rows
   for 271) and no PDF Snapshot. On 271, 0 records have a value.
2. **The email arrives after the trigger.** Instrument order: `consent` (48) →
   `person_obtaining_consent` (53) → `contact_info` with `email` (56). The alert is only evaluated
   when `person_obtaining_consent` is saved, and at that moment `email` is still blank, so the
   condition is false and never re-checked.

## Recommended setup (UI only)

1. **Online Designer → PDF Snapshots → add a snapshot.**
   - Trigger: when the **`person_obtaining_consent`** survey is completed, at Day 1 (ED) (Arm 1).
     Using this form means the PDF carries both the participant's signature and the staff
     co-signature.
   - Instruments: `consent` + `person_obtaining_consent`.
   - Save to field: **`[consent_pdf]`** at Day 1 (ED) (Arm 1). Also save to the File Repository.
   - `redcap_pdf_snapshots` supports this in 17.2.3 (`pdf_save_to_field`, `pdf_save_to_event_id`).
2. **Alert 15: change the trigger form to `contact_info`.** That is where the email is entered.
   Keep the condition and the attachment. Activate it.
3. **Test on 271 in a browser:** consent → person obtaining consent → contact info with a test
   email address. Then check that `consent_pdf` holds a PDF, and that the email arrives with it
   attached.

Optional: turning on the **e-Consent framework** for `consent` adds REDCap's certification page
and version stamping, but it is not needed just to email the PDF.
