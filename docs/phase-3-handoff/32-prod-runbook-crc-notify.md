# 32 — Production runbook: a critical finding reaches the CRC within 5 minutes (PID 35968)

What to do on prod so that a critical disclosure in a MICA session reaches the CRC's inbox within
5 minutes of End Session. The *why* and the measurements are in
[`31-critical-finding-crc-notify.md`](31-critical-finding-crc-notify.md).

**This configuration is verified on the local copy of prod (PID 271).** With these settings and a
CRC in the reviewer role, all 7 launch gates pass. A participant's End Session reached the CRC's
inbox in 22–65 s, as "highest urgency: critical".

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

**Per project — steps 1–6.** The SecureChatAI registry (a system setting), one REDCap role, and the
MICA settings.

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
- **Roles on prod today:** Data Entry, Export w/o Identifiers, Project Admins and Read Only. None of
  them is for CRCs.

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

## 3. Put the CRCs in a role

The "findings ready" email goes to **every user in the REDCap role(s) mapped as MICA Reviewer**. That
same role is what lets someone open the transcripts on the review dashboard. The recipient list is
worked out at send time from REDCap's own user rights, so adding or removing a CRC is only a role
change.

1. **User Rights → create a role**, for example `CRC (MICA reviewer)`. Give it the rights your CRCs
   already have; REDCap's copy-role option works.
2. **Assign each CRC to that role.**
   - A user on custom rights with **no role gets nothing**: no email and no dashboard.
   - Each CRC needs an **email address** in their REDCap profile.
   - The account must **not be suspended**; suspended users are skipped.
3. **Why a separate role:**
   - Don't map Project Admins. Everyone in it would get every findings email and could read
     transcripts, and that is how the local copy is set up.
   - If Brian should also get the emails, map his role as a **second** Reviewer role in step 4. A user
     can hold only one role.

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
- **SafetyScan model alias:** `gpt-5-6-sol`.
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
- **Reviewer role(s):** `CRC (MICA reviewer)`, plus Brian's role if he wants the emails.
- **PI / protocol-lead role(s):** the role Brian is in. It gives him the dashboard and the audit trail.
  It does **not** send him the findings email.
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
- **Reviewers configured** — shows how many users are in the Reviewer role. **Zero is how the 09-22
  notice on the local copy reached nobody** ("No recipient addresses were given").
- **Hash-pinned handoff artifacts** — red means the deployed module files were altered. Redeploy; don't
  edit.
- **Model aliases resolve** — both aliases must be in **SecureChatAI's registry** (Control Center, a
  REDCap admin). An alias missing from the registry doesn't error; the model just returns an apology.
- **Notification policy is valid** — red means the JSON didn't validate.
- **Recipient lists are valid addresses** — a typo in any address list.
- **Scan mock mode is off.**

In Development status a red gate only shows a banner. **In Production status any red gate stops
every session from starting**, so all 7 must be green before the move.

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
5. **Click End Session** and note the time.
6. **Within 5 minutes** each CRC should receive
   `[MICA] N SafetyScan finding(s) ready for review - highest urgency: critical`.
7. **Open the link in the email as a CRC.** The session should be in the queue with a critical
   `self_harm` finding and the quoted evidence.

**If something else happens:**
- **"A session could not be screened and needs manual review"** — the fix isn't deployed (step 2),
  or the SafetyScan alias doesn't work (the *Model aliases* gate).
- **No email at all:**
  - the *Reviewers* gate count
  - the CRC's profile email, and their spam or quarantine folder
  - that *Finalize transcripts and queue safety scans* is on

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
- who the CRCs are
