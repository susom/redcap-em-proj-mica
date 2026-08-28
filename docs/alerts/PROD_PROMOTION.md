# Promoting the MICA alert build from localhost (PID 257) to production

**Status:** 2026-08-27 — the Data Dictionary export was rejected by prod; both
errors are fixed and a corrected file is ready. **Nothing has been imported to
production yet.**

---

## The two DD upload errors — both pre-existing defects, neither caused by the alert build

| REDCap error | Field | Cause |
|---|---|---|
| *"Each 'calc' field must have an equation in column F: **F224**"* | `calc_esms_valid` (`admin`) | `element_type='calc'` with a **NULL equation**. ASPIRE has a real formula here; the MICA clone lost it. REDCap tolerates it in the DB but the DD importer rejects the whole file. |
| *"Only 'text' field types may have validation ... **H255**"* | `finding_rec_targets_json` (`mica_safety_finding`) | **Real data corruption in the DB.** A past DD import split the field note on its embedded comma and shifted the tail one column left. |

The `finding_rec_targets_json` corruption, exactly as stored on localhost:

```
element_note            = 'As above: a recommendation'
element_validation_type = ' not a notification.'      <-- prose in the validation column
```

The export was faithful; the database was wrong. Confirmed to be the **only**
instance — every other non-null `element_validation_type` in the project is a real
validation name. (The em/en-dashes in neighbouring notes are clean UTF-8; they only
looked mangled through a non-utf8mb4 mysql client.)

Both fixed on localhost as migration steps **P9** and **P10**, so future exports
are clean. `calc_esms_valid`'s restored equation passes `LogicTester::isValid()`,
and all 30-odd calc fields in the project now validate.

## Corrected file

```
~/Downloads/MICAR01_DataDictionary_2026-08-27_FIXED.csv
```

Pre-flight against REDCap's own DD rules: **0 blocking errors**, 5 non-blocking
warnings — and those 5 are precisely the ones REDCap already listed as *allowable*
(missing labels at E26/E28/E65/E137, long name at A259), which is what confirms the
pre-flight is checking the same rules.

---

## ⚠️ The Data Dictionary does NOT carry the whole build

Five items are **not metadata** and will not travel in the DD. Apply
each separately or the alerts will import and silently never fire.

| | Change | Carried by the DD? | How to apply on prod |
|---|---|---|---|
| P1 | `twilio_modules_enabled` → `SURVEYS_ALERTS` | ❌ **No** — project setting | Project Setup → enable Twilio → set the SMS/Alerts scope. **All 6 SMS alerts are undeliverable without this.** |
| P6 | ~~`sms_code_check` designated on events 1004/1008/1012~~ | ❌ **No** — event-form designation | **SKIP — not needed.** See below. |
| — | **the 21 alerts** | ❌ **No** | `MICA_Alerts_UPLOAD_TO_PROD.csv` → Alerts & Notifications → Upload. **Do not re-export from localhost** — see below. |
| — | **consent gated on `calc_eligible=1`** | ❌ **No** — lives in `redcap_surveys` | Designer → `screen_eligibility` → Survey settings → Survey Termination Options. Full steps: [`../ELIGIBILITY_GATE.md`](../ELIGIBILITY_GATE.md) |
| — | **session survey login: `last_name` → `phonen`** | ❌ **No** — lives in `redcap_projects` | Project Setup → Survey Login. ⚠️ Has two unresolved caveats (arm 3, phone formatting). Full steps: [`../SURVEY_LOGIN.md`](../SURVEY_LOGIN.md) |
| P2 | `calcrnd` formula → `[rnd]` | ✅ yes | |
| P3 | new `baseline1.dummy_email` | ✅ yes | |
| P4 | `desc_sms_code_check` text | ✅ yes | |
| P5 | `desc_sms_optout` text | ✅ yes | |
| P7 | `phonen` validation → `phone` | ✅ yes | |
| P8 | `close` descriptives rebranched | ✅ yes | |
| P9 | `calc_esms_valid` equation | ✅ yes | |
| P10 | `finding_rec_targets_json` note/validation | ✅ yes | |

### P6 was aimed at the wrong instrument — drop it from the prod run

P6 designated **`sms_code_check`** at the Day 1 events. That was based on the name;
it is not the form the passcode flow uses. The two are different things:

| Instrument | Order | Contents | Role |
|---|---|---|---|
| **`check_code`** (survey 1287) | 66 | `passcode`, `calc_code_check`, pass/fail messages | **the form the participant types the code into** — this is the one that matters |
| `sms_code_check` (survey 1298) | 144 | one descriptive: *"Your passcode for the MICA study is `[calcrnd]`."* | an **alternative delivery route** — shows the code on screen instead of texting it |

`check_code` was **already** designated at 1004–1016 before P6 ran, so nothing was
needed. And `sms_code_check` is redundant once the native Twilio SMS (alert 01) does
the delivery — worse, it *displays the participant's own passcode*, which defeats
the point of the phone check: a participant who opens that link can read the code
without ever receiving the text, then type it into `check_code` and "pass".

It is not currently reachable by accident (no survey queue rows, no auto-continue
into it, so it needs a direct link), so this is noise rather than an active hole —
but do **not** promote the designation to prod, and consider disabling that survey.

On localhost the designation can be reverted with:
```sql
DELETE FROM redcap_events_forms
 WHERE form_name='sms_code_check' AND event_id IN (1004,1008,1012);
```
*(Leave event 1013 — that one is arm-3 original, not something P6 added.)*

---

## ⚠️ A DD import replaces ALL metadata — check the diff for deletions

This is the real risk, and it is much wider than the ten changes above. A Data
Dictionary import is not a patch; it is a **full replacement** of the project's
field definitions. So this file will also:

- push **any unrelated localhost drift** into production, and
- **delete any field that exists in prod but not in this file.**

Localhost has 271 fields. If prod has fields localhost doesn't — anything added
directly in prod, or added after the localhost copy was taken — **they will be
dropped, along with their data.**

Two safeguards, in order of preference:

1. **Diff first.** Export prod's Data Dictionary and compare before importing.
   Hand me the prod export and I'll produce a field-level diff — added, removed,
   and changed — so you can see exactly what the import would do.
2. **Read REDCap's own diff screen.** If prod is production-status the import goes
   into **Draft Mode** and needs admin approval, and REDCap shows a field-by-field
   summary first. Scrutinise the *deletions* section specifically; additions and
   modifications are the expected part.

---

## The alerts upload error — wrong file

REDCap rejected `MICAR01_Alerts_2026-08-27.csv` with *"One or more components are
missing in your input data"*. That file was a **re-export from localhost**, and it
is the wrong artifact to promote for two independent reasons:

1. **It is stale.** It holds the **old 23-alert** set — including
   `02 MICA ED session reminder (email)` and `03 … (SMS)`, the +1-day rung that was
   dropped on 2026-08-27, and the numbering gap where alert 17 was appended last.
   The current build is 21 alerts.
2. **It carries `alert-unique-id` values `A-5626` … `A-5648`.** Those are
   *localhost* `redcap_alerts.alert_id` values. A populated `alert-unique-id` tells
   REDCap *"update the existing alert with this id"* — on prod those ids either
   don't exist or belong to entirely unrelated alerts.

**Upload this instead** — 21 current alerts, `alert-unique-id` blank on every row
(= create new), `alert-deactivated=Y` on every row:

```
~/Downloads/MICA_Alerts_UPLOAD_TO_PROD.csv        (= docs/alerts/MICA_257_alerts_import.csv)
```

### ROOT CAUSE — the CSV delimiter preference (prod confirmed on 17.2.3)

Prod runs the same 17.2.3, so the code is identical and the file is valid. The
difference is **which delimiter each importer parses with** — and REDCap is
inconsistent about it:

| Importer | Delimiter source | Code |
|---|---|---|
| **Data Dictionary** | **hardcoded `,`** | `MetaData.php:450` — `$delimiter=','` |
| **Alerts & Notifications** | **the logged-in user's preference** | `Alerts.php:5370` calls `csvToArray($csv)` with no delimiter → `init_functions.php:5399` → `User::getCsvDelimiter()` |

`User::getCsvDelimiter()` (`User.php:952`) returns the user's
`redcap_user_information.csv_delimiter`, falling back to the system
`default_csv_delimiter`, then to `,`. The allowed values are
`,` `;` `TAB` `SPACE` `|` `^` (`getCsvDelimiterOptions()`, `User.php:945`).

**So if your prod account's delimiter is not `,`**, `fgetcsv` parses the whole
comma-separated header as a **single field**. `$data[0]` then has one key, so all
41 required attributes are reported missing — which is exactly the error, listing
the complete set.

This explains every observation at once:
- The **Data Dictionary** upload parsed fine on prod and returned real
  field-level errors (F224 / H255) — it always uses a comma.
- The **Alerts** upload failed with all 41 attributes "missing" — it used your
  delimiter preference.
- Both files parse cleanly on localhost, where every user and the system default
  are `,`.

**Fix — pick one:**

1. **Set the delimiter back to comma (recommended, permanent).**
   *My Profile* → CSV delimiter → `,` (`Profile/user_profile.php`). An admin can
   set the instance-wide default in *Control Center → User Settings*
   (`default_csv_delimiter`). This fixes every future alert/report import too.
2. **Upload a file that matches your delimiter.** Pre-generated and round-trip
   verified (21 rows × 41 columns each):
   ```
   ~/Downloads/MICA_Alerts_UPLOAD_TO_PROD.csv             (comma)
   ~/Downloads/MICA_Alerts_UPLOAD_TO_PROD_semicolon.csv   (;)
   ~/Downloads/MICA_Alerts_UPLOAD_TO_PROD_TAB.csv         (tab)
   ~/Downloads/MICA_Alerts_UPLOAD_TO_PROD_pipe.csv        (|)
   ~/Downloads/MICA_Alerts_UPLOAD_TO_PROD_caret.csv       (^)
   ```

**To find out which delimiter prod is using**, without digging in settings: on
prod, *Alerts & Notifications → Download alerts as CSV*. `arrayToCsv()`
(`init_functions.php:4962`) uses the **same** `User::getCsvDelimiter()`, so
whatever separates the columns in that download is the delimiter your upload must
match.

### Earlier caveat, now superseded

**The stale file was not reproducibly invalid.** Run through prod's exact
validation path on localhost — `fixUTF8()` → `removeBOM()` → `csvToArray()` →
`getAlertsCSVAttributes()` → the `isset($data[0][$attr])` loop — it parses to 41
keys with **0 missing attributes** on a longitudinal project. No BOM, consistent
line endings, all 23 rows at 41 columns. The sendgrid JSON columns are nullable and
only validated for `SENDGRID_TEMPLATE` alerts, so their emptiness is not the cause.

So the "components are missing" text does **not** in itself point at a bad column
list here. Note that `Alerts::validateCSVContent()` emits that *same* message when
`$data` parses **empty** (`if (empty($data))` at the top of the function), which
makes "the upload never reached the parser" as likely as "a column is missing".

Prod being on 17.2.3 is what ruled the version theory out and pointed at the
delimiter instead — see the root cause above. The remaining fallback, if the
delimiter turns out to be `,` after all, is the paste-CSV form: the Alerts page has
two upload forms, `importAlertForm` (multipart file) and `importAlertForm2` (posts
`csv_content` as text). If paste succeeds where the file picker fails, the problem
is the upload path (`upload_max_filesize` / `post_max_size`, or a proxy stripping
the multipart body) rather than the CSV.

---

## Order of operations on prod

1. Diff prod's DD against the fixed file. Resolve any unexpected deletions.
2. Import `MICAR01_DataDictionary_2026-08-27_FIXED.csv` (→ Draft Mode → approve).
3. Apply by hand: **P1** (Twilio scope), the **`calc_eligible` consent gate**
   ([`../ELIGIBILITY_GATE.md`](../ELIGIBILITY_GATE.md)), and the **session survey
   login field** ([`../SURVEY_LOGIN.md`](../SURVEY_LOGIN.md)).
   **P6 is dropped** — it targeted the wrong instrument; nothing to do. Instead,
   confirm **`check_code`** is designated at the Day 1 events (it should already be).
4. Import `~/Downloads/MICA_Alerts_UPLOAD_TO_PROD.csv` — all 21 arrive **deactivated**.
5. Replace the `micastudy@stanford.edu` placeholder (9 alerts).
6. Run **Data Quality rule H** ("Incorrect values for calculated fields") → Fix all.
   P2 and P9 change *formulas*; stored values are not recomputed, and existing
   records still hold the old 8-digit `calcrnd`.
7. Activate alerts **one at a time**, confirming each send. Prod Twilio will send
   to real participants.
