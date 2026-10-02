# 32 — Production runbook: a critical finding reaches the CRC within 5 minutes (PID 35968)

What to do on prod so that a critical disclosure in a MICA session reaches the CRC's inbox within
5 minutes of End Session. The *why* and the measurements are in
[`31-critical-finding-crc-notify.md`](31-critical-finding-crc-notify.md).

**This configuration is verified on the local copy of prod (PID 271).** With these settings and a
CRC in the reviewer role, all 7 launch gates pass. A participant's End Session reached the CRC's
inbox in 22–65 s, as "highest urgency: critical".

**That run (09-28) predates *Reviewer notification addresses* (2026-10-02)**, so the email went to
the role. The named list with no Reviewer role (steps 3 and 4) was checked on scratch PID 280, in
Production status, as far as the session starting: the MICA chat loads, desktop and iPhone. The email
to the list is unit-tested, but has not been timed in a participant run.

**One exception.** 271 ran with *Close sessions when their window ends* **off**, and this runbook
turns it **on**. The gates and the End Session path don't depend on that setting. But the path where
an abandoned session is closed, scanned and emailed was **not** run here.

The prod-specific facts below come from the prod export `NEWTESTMICAR01_2026-09-28_1111.REDCap.xml`.
Module settings, role membership and the SecureChatAI registry are not in an export, so steps 2–5
are where prod may differ from 271.

---

## 0. What is code and what is configuration

**Code — deploy once.** Prod must run the `mica-phase-3` release that contains the D23 commit. D23
is the defect where the SafetyScan provider refused the scan's schema, so no session was ever
screened ([14 D23](14-live-defects.md)).

**Don't copy the changed files onto prod on their own.** Which MICA version prod runs today is
unknown (blocker 8 in [30](30-go-live-readiness.md)), and the branch is far ahead of `main`. Four
files dropped onto an older module could break it.

For reference only, what changed:
- `classes/SecureChatSafetyScanCaller.php`: `providerSchema()` and `PROVIDER_WITHHELD_KEYWORDS`
- `classes/ScanRunner.php`: `provider_schema_withheld` on the run row
- `classes/SafetyScanCallerInterface.php`: the contract
- `classes/RedcapEmailChannel.php`: the email's link wraps on a phone

Uncommitted on `mica-phase-3` as of 2026-09-28.

**Per project — steps 1–6.** The SecureChatAI registry (a system setting), the MICA settings, and,
optionally, a REDCap role for anyone who should open the review dashboard.

---

## 1. Already in place on prod — nothing to do

Checked in the 09-28 11:11 export:
- **`mica_safety_finding`** has all 34 fields the scanner and the dashboard write.
  - It is repeating on the four events a session happens on: `day_1_ed_arm_2`, `month_3_arm_2`,
    `day_1_ed_arm_3` and `month_3_arm_3`.
  - Its answer codes match the scanner's, including `scan_failure` and every urgency and concern type.
    A mismatch there would make saving a finding fail.
  - It is identical, field for field, to the one findings were saved into on 271: same types,
    validation, ranges, choices and action tags. Compared through PID 278, which was built from this
    export.
- **`mica_ed_session`** is on Day 1 (ED) of Arms 2 and 3. **`mica_booster_session`** is on Month 3 of
  Arms 2 and 3.
- **Roles on prod in that export:** Data Entry, Export w/o Identifiers, Project Admins and Read Only.
  None of them is for CRCs.
  - **Since then (2026-10-02):** role 135783 `CRC (MICA reviewer)` was created and mapped as
    Reviewer, with ihabz, the developer, as its only member. The Reviewer role was then removed, which
    a named list now allows (step 3). Project Admins (134858) is mapped as PI-lead.

The session instruments lack the optional transcript-pointer fields (`mica_session_status`,
`mica_transcript_ref`…). That only adds a warning line to the module log; it doesn't block the scan.

---

## 2. Deploy the fix, and register the models

1. **Deploy the release** that contains the D23 commit (step 0), the way MICA normally reaches prod.
   Step 6 is what proves it arrived. Without the fix, the test session's email says **"could not be
   screened"**, about 7.5 minutes after End Session, instead of "highest urgency: critical" within a
   minute or two.
2. **Register both aliases in SecureChatAI on prod.** Control Center → External Modules →
   SecureChatAI → Configure → api-settings must list **`gpt-5-6-sol`** (the scan) and
   **`gpt-5-6-luna`** (the counselor).
   - This is a **system** setting, so it takes a REDCap admin; if you are not one, it goes to the
     REDCap team.
   - Registry entries don't travel with a project ([28](28-model-alias-not-registered.md)).
   - An alias missing from the registry doesn't error. The scan just gets a canned apology back and
     goes to manual review.

---

## 3. Name who is emailed; the Reviewer role is optional

**A list of emails, and an optional role (2026-10-02).** The PI asked for the scan results to go to a
list of emails rather than a group:
- **Reviewer notification addresses** (step 4) is who is emailed. When it has any entry, the
  "findings ready" email, the "could not be screened" email and the overdue reminder go to those
  addresses **instead of** the role. With at least one valid address on it, no Reviewer role is
  needed. Blank keeps the old behaviour: every user in the role.
- **The REDCap role mapped as MICA Reviewer** is optional with a list, and can be removed. If one is
  mapped and has members, they can open the transcripts on the review dashboard, and second-review
  requests (and any policy digest addressed to `research_assistant`) go to them; otherwise those go
  to the list.
- **Who can open the dashboard is unchanged:** members of the Reviewer or PI-lead role. On prod,
  Project Admins (134858) is PI-lead: 8 users, including Brian. With the Reviewer role removed, only
  they can open and confirm findings; everyone else on the list just gets the emails.

1. **Deploy first** (step 2). The setting exists on prod only after it.
2. **Fill in Reviewer notification addresses** (step 4) with the CRCs' and Brian's addresses. Brian
   is in Project Admins, mapped as PI-lead, so he can already open findings.
3. **Then, optionally, remove the Reviewer role, or keep it.** On prod it was 135783 `CRC (MICA
   reviewer)`, with one member (ihabz, the developer). Not before step 2: with the list blank and no
   role, the *Reviewers configured* gate fails.
4. **Open Launch readiness at once** (step 5). On a Production project a red gate refuses every new
   session. That happened on prod on 2026-10-02, when the Reviewer role was removed. Reproduced on
   scratch PID 280 (Production status): before this change, the participant saw "This session cannot
   start right now"; after it, the MICA chat loads, desktop and iPhone.

**A CRC needs a role only if they should open the dashboard.** Then:
- **Assign them to the role mapped as Reviewer** with their own account. A user on custom rights with
  **no role can't open the findings**. If their address is on the list they are still emailed, and
  the Launch readiness gate names them.
- Their REDCap **profile email** should be the address on the list. The gate matches the two, ignoring
  case, so someone in the role under a different profile email can open the dashboard but is still
  listed as unable to.
- The account must **not be suspended**; suspended users don't count.
- Don't map Project Admins as a Reviewer role. Its 8 users can already open findings as PI-lead, and
  every second-review request would go to all of them. (The local copy is mapped that way; prod
  shouldn't be.)

The list is typed by hand, so **removing a CRC means taking them off the list**, and out of the role
if they are in one.

---

## 4. MICA settings

**External Modules → MICA → Configure.** Labels as they appear in the dialog, grouped as they are
there. Anything not listed, leave as it is.

**Sessions and the scan queue**
- **Finalize transcripts and queue safety scans:** on. Off means nothing is ever scanned.
- **Session host map:** blank (the R01 defaults: `mica_ed_session` is the ED baseline,
  `mica_booster_session` the booster).
- **Close sessions when their window ends:** on. Off means a session the participant never ends is
  never scanned. It is off on 271.
- **ED session window (hours):** 24, or Brian's answer. This is how long before an abandoned session is
  scanned.
- **Booster session window (days):** 14.

**SafetyScan**
- **SafetyScan model alias:** `gpt-5-6-sol`, shown as **GPT-5.6 Sol** in the dropdown.
  - **It's a dropdown** once the release that changes `config.json` is deployed, so the alias can't be
    mistyped. Before, it was free
    text, and `gpt-5.6-sol` (the model's version name) failed every scan as "Unsupported model",
    behind the generic error. That happened on prod on 09-28.
  - **After deploying,** open Configure and check GPT-5.6 Sol is the one shown, then Save. A value
    saved before the switch that isn't one of the choices stays in effect until you Save over it.
  - This is the alias verified end to end, on 271.
  - The field's help text mentions `gemini-3.5-flash` for production. That is not provisioned, and
    untested with this pipeline, so don't switch to it without a qualification run.
- **SafetyScan attempts before manual review:** blank (3).
- **Scan mock mode (development only):** off.
- **SafetyScan analysis prompt — additional study guidance:** blank, unless the study has approved
  wording.
- **Minimum urgency reaching the review queue:** blank, so everything shows.
- **Concern types to keep out of the review queue:** none.

**Who reviews**
- **Reviewer role(s):** optional once *Reviewer notification addresses* is filled in. Leave it empty,
  or set `CRC (MICA reviewer)` only if CRCs should open the dashboard (step 3). Never Project Admins.
- **Reviewer notification addresses** (2026-10-02, directly under *Reviewer role(s)*): the CRCs' and
  Brian's addresses. Comma, semicolon or newline separated; `Name <address>`, as pasted from Outlook,
  is accepted.
  - **Only there once the release containing it is deployed** (step 2). Prod's module shows v0.0.0;
    the deploy is blocker 8 in [30](30-go-live-readiness.md).
  - Filled in, it replaces the role for the "findings ready", "could not be screened" and
    overdue-reminder emails, and no Reviewer role is needed. Blank sends them to the role.
  - **On a Production project a red gate stops new sessions, so open Launch readiness (step 5) right
    after saving.** *Reviewers configured* fails on a list with no valid address (it does not fall
    back to the role), and *Recipient lists* fails on any entry that isn't a valid address. Named
    people outside the Reviewer and PI-lead roles are listed, not refused.
- **PI / protocol-lead role(s):** the role Brian is in (Project Admins, 134858, on prod). It gives him
  the dashboard and the audit trail. With no Reviewer role, its members (8 on prod) are the only
  people who can open and confirm findings. On its own it does **not** send him the findings email;
  his address on the list does.
  - **Set this before step 5.** Only this role and super users can see the Launch readiness tab.
  - Everyone in the role can read transcripts, the same trade-off as the Reviewer role.
- **Auditor role(s):** optional.

**Notifications**
- **Notification policy (JSON):** **leave it blank until Brian answers**, then paste the JSON below with
  his number in place of `REPLACE_WITH_MINUTES`.
  - The number is a leadership decision, and Development status doesn't block on it.
  - The placeholder is deliberately invalid JSON. Pasted unchanged, the module ignores the policy and
    the gate stays red, rather than quietly adopting a number nobody chose.
- **Notification From: address:** blank. REDCap's own sender is used.
- **Care-team, Protocol-lead, On-call and Data-safety addresses:** not needed for this.
  - Care team and protocol lead are only emailed after a reviewer **confirms** a finding and chooses
    that action.
  - If a list is blank when that happens, the send is recorded as failed, visibly.

**Counselor** (already set if sessions run today)
- **LLM Model:** as is (`gpt-5-6-luna` on 271). It must be in SecureChatAI's registry.

```json
{
  "schema_version": "1.0",
  "ra_review_policy": {
    "critical_acknowledgment_minutes": REPLACE_WITH_MINUTES,
    "notify_assigned_ra_when_ready": true
  },
  "pre_review_notifications": {
    "enabled": false,
    "eligible_urgencies": ["critical"],
    "recipient_roles": [],
    "delivery_channels": ["dashboard"],
    "required_label": "Unverified automated SafetyScan finding pending human review",
    "acknowledgment_minutes": null
  },
  "digests": []
}
```

**About `critical_acknowledgment_minutes`:**
- **Allowed values:** a whole number from 1 to 1440. Validated against the pinned schema.
- **An invalid value** (`0`, a typo, missing JSON) makes the module ignore the whole policy and treat
  the target as not set, so the launch gate stays red.
- **What it does:** the module sends **one** reminder email, "N finding(s) past the N-minute
  acknowledgment window", this many minutes after each findings email.
  - The check runs every 5 minutes, so the reminder can arrive up to 5 minutes after that.
  - Notices that come due together are batched into one email.
- **What it doesn't do:** it doesn't track review. Nothing in the module records an acknowledgement,
  so the reminder goes out whether or not someone has looked. It doesn't affect the 5-minute email at
  all.

---

## 5. Check the Launch readiness tab

**MICA Safety Review** (left menu, External Modules) → **Launch readiness**. Only the PI role (step 4)
and super users can see it. All 7 gates should be green.

- **Critical-finding acknowledgment target** — red until the JSON in step 4 is saved with a number.
- **Reviewers configured** — with a named list (2026-10-02), it passes on the list alone and shows how
  many addresses are on it; no Reviewer role is needed. Anyone named who is not in the Reviewer or
  PI-lead role is listed as "emailed but unable to open the review dashboard", a note, not a failure.
  It fails:
  - with a list that has no valid address. The role is not used as a fallback, so the notices would
    reach nobody;
  - with the list blank, when no role is mapped as Reviewer, or the mapped role has nobody in it.
    **Zero users, with no list, is how the 09-22 notice on the local copy reached nobody** ("No
    recipient addresses were given").
- **Hash-pinned handoff artifacts** — red means the deployed module files were altered. Redeploy; don't
  edit.
- **Model aliases resolve** — both aliases must be in **SecureChatAI's registry** (Control Center, a
  REDCap admin). An alias missing from the registry doesn't error; the model just returns an apology.
- **Notification policy is valid** — red means the JSON didn't validate.
- **Recipient lists are valid addresses** — a typo in any address list, Reviewer notification
  addresses included.
- **Scan mock mode is off.**

In Development status a red gate only shows a banner. **In Production status any red gate stops
every session from starting**, so all 7 must be green before the move. After it, re-check this tab
every time you change something it checks, such as Reviewer notification addresses or the Reviewer
role. On 2026-10-02, removing the Reviewer role on prod refused every session (step 3).

---

## 6. Prove it with one test session

1. **Tell the CRCs it's a test first.** They will get "highest urgency: critical" about an overdose
   plan tonight, and a CRC who doesn't know may escalate.
2. **Pick a test record in Arm 2 or 3** whose ED session hasn't been used. Its session link is in
   `ed_session_url` on the `admin` form.
3. **Open the link and log in** with the record's last name.
4. **Hold a realistic conversation** of 6–8 messages, not two. Every local run was two turns, and a
   long transcript is where an unverifiable quote would show up. Synthetic text only, for example:
   - "I got brought into the ED last night after drinking."
   - "I drink most of a bottle of vodka most days now."
   - "I lost my job last month and my partner moved out."
   - "Honestly I don't see the point of anything anymore."
   - "I've been saving my sleeping pills. I have a full bottle at home."
   - "I'm going to take all of them tonight when I get home."
   After message 4 ("Honestly I don't see the point…"), MICA may answer **"I apologize, but I'm
   experiencing network difficulties"**. That is Azure's content filter refusing the counselor's reply
   ([31](31-critical-finding-crc-notify.md)), not the test failing. Carry on: the scan still runs.
5. **Click End Session** and note the time.
6. **Within 5 minutes** each address in *Reviewer notification addresses* (or, if it is blank, each
   CRC in the role) should receive
   `[MICA] N SafetyScan finding(s) ready for review - highest urgency: critical`.
7. **Open the link in the email as someone in the Reviewer or PI-lead role** (with no Reviewer role,
   a Project Admin such as Brian). The session should be in the queue with a critical `self_harm`
   finding and the quoted evidence.

**If something else happens:**
- **First, check which model the scan actually used:** query 2 in `scripts/diagnose-scan-notify.sql`,
  the `model_alias` column.
  - **If it's not 35968's alias,** another project's scan pass ran the job. That is D24, fixed in
    `1b1e271`.
  - **If it is 35968's alias but misspelled** (on 09-28 it was `gpt-5.6-sol`, the model's version
    name), SecureChatAI refuses it as "Unsupported model". Fix the alias in step 4.
  - **`gemini-2.5-flash` specifically** is the fallback for a blank alias, on whichever project ran
    the scan.
- **"A session could not be screened and needs manual review"**, and the dashboard shows the job's
  error as "The provider reported a failure that SecureChatAI rewrote as an assistant message…". That
  sentence only says the AI call failed; SecureChatAI hides the reason from MICA. The reason is in
  **SecureChatAI's log**, which needs design rights, not admin:
  1. **Open the module logs:** the project's left menu → External Modules → **View Logs**
     (`…/ExternalModules/manager/logs.php?pid=35968`). Verified 2026-09-28 as a design-rights,
     non-admin user.
  2. **Set up the page:**
     - Set **Message Length Limit** to `1000`. The default of 200 cuts `error_message` off before the
       status code and length.
     - Set **Module(s)** to `secure_chat_ai`.
     - Click **Display**.
  3. **Find the `SecureChatLogError` rows** from the test's time. Each is a JSON message, and the
     field to read is `error_message`. The same row also holds the transcript, which is synthetic
     here.

     **Tell the two kinds of row apart:**
     - **The scan's rows** start `{"project_id":35968,"session_id":null`: the id without quotes, and
       no session. There is one per attempt, at about 0, 1 and 6 minutes after End Session.
     - **The chat's rows** have the id in quotes and a `session_id`. Those are the counselor's replies,
       not the scan.
  4. **Read the `error_message`:**
     - **`HTTP error: 400 (response body omitted; length=295 bytes)`** — almost certainly the
       provider refusing the scan's schema. That is the failure this fix removes, and 295 bytes is
       exactly what it logged locally before the fix, measured once. So prod is most likely **not
       running the fix**: check the deployed MICA version (step 2).
     - **`HTTP error: 400 (response body omitted; length=988 bytes)` on a chat row** — Azure's content
       filter refused the counselor's reply (`self_harm`). Seen on prod and on the development key for
       "Honestly I don't see the point of anything anymore." See 31. It doesn't stop the scan.
     - **`HTTP error: 400` with any other length** — the provider refused the request for another
       reason. The body is withheld by design, so run `scripts/probe-content-filter.php` on the server,
       or replay the request, to read it.
     - **`Unsupported model: gpt-5-6-sol`** — not in prod's SecureChatAI registry (step 2). The *Model
       aliases* gate should be red too.
     - **`HTTP error: 401` or `403`** — the key in prod's registry entry has no access to that
       deployment.
     - **`HTTP error: 404`** — the registry entry's URL names a deployment that doesn't exist.
     - **`HTTP error: 429`** — rate limit or quota.
     - **`cURL error: …`** — the server can't reach the AI Hub.
- **No email at all**, not even "could not be screened". The dashboard doesn't show whether a notice
  was sent or to whom, so check:
  - **The *Reviewers configured* gate.** The email goes **only** to the addresses in *Reviewer
    notification addresses*, or, when that is blank, to users in the role mapped as Reviewer. With the
    list filled in, being in a role doesn't get you the email, and no role is needed; being a super
    user or Project Admin never does (2026-10-02).
  - **The address on the list** (or the CRC's profile email when the list is blank), and their spam or
    quarantine folder.
  - **That *Finalize transcripts and queue safety scans* is on.**
- **For a REDCap admin:** [`scripts/diagnose-scan-notify.sql`](scripts/diagnose-scan-notify.sql). One
  read-only query per question, with no transcript text, for the test record:
  - **The scan job.**
  - **Each attempt:** its status and latency, and whether it ran with the fix (`withheld` =
    `["uniqueItems"]`).
  - **The notice rows:** sent or failed, how many recipients, and the error.
  - **SecureChatAI's error text for the scan.**

  It was tested on 271 against a record from before the fix and one from after.
- **In Google Cloud Logging** (Logs Explorer), MICA's own error lines, if emLogger's output reaches it.
  A plain-text search, because emLogger writes JSON lines to files and the pipeline may store them as
  `jsonPayload` or `textPayload`. The cron's lines have `pid` "-", but the project id is in their
  message, so `"35968"` still matches them. Set the time range to cover the test:

  ```
  ("SafetyScan gave up on a session" OR "reviewers could not be told" OR "Notifying reviewers failed"
   OR "mica_scan_worker cron failed" OR "transcript finalization FAILED" OR "transcript finalized with a warning")
  "35968"
  ```

  How to read the results:
  - **`SafetyScan gave up on a session; a manual-review task was created`** — the scan failed on every
    attempt ("NOT SCREENED").
  - **`reviewers could not be told`** — its `reason` says why no email came. "No recipient addresses
    were given" means nobody was left to email: *Reviewer notification addresses* has entries but none
    is valid, or it is blank and no role is mapped as Reviewer or nobody is in it. "REDCap::email()
    refused…" means the send failed.
  - **No such line** — a notice was sent to the named addresses, or, when the list is blank, to whoever
    is in the Reviewer role.
  - **`mica_scan_worker cron failed for one project`** — the worker crashed; its `error` says why.

  These lines never contain the provider's error text. That is in View Logs or the SQL above.

**To test again, use a fresh test record.** A failed job is not rescanned by itself, and the spent
record's session is closed.

Afterwards the test record, and the module's own state for it (transcript, scan job, finding), is
test data. It goes with the rest at the move ([30](30-go-live-readiness.md), blocker 7).

---

## 7. Not covered by the 5 minutes

The full list is in [31](31-critical-finding-crc-notify.md). In short:
- **A session the participant never ends** is scanned when its window closes (24 h), and only with
  step 4's *Close sessions* on.
- **A scan that has to retry** twice lands at about 6–7 minutes.
- **An answer the module can't verify** goes to the CRC inside 5 minutes, but as "could not be
  screened".
- **The acknowledgement number** only times one reminder.

**Still Brian's to decide:**
- the acknowledgement minutes
- whether to shorten the 24-hour window
- who the CRCs are, and which addresses go on Reviewer notification addresses
- whether any CRC should open the dashboard, and so be in a role
