# Stage 6 — Notifications, digests, launch gates, hardening

**Goal:** policy-driven notifications and digests, acknowledgment tracking,
the launch-readiness validator wired to `production-mode`, and the final
security/regression pass. This stage makes the module *refuse* to operate in
production until leadership resolves the deliberate blockers.

**Depends on:** Stage 5.

## Tasks

### 6.1 `NotificationService` (`classes/NotificationService.php`)

- Channel abstraction (`EmailChannel` via `\REDCap::email`; SMS via existing
  Twilio system settings later if approved — interface only, no
  implementation now).
- Policy = `notification-policy-json` project setting, validated against the
  vendored policy schema on save (`savePolicy`, Stage 5) **and** on read
  (fail closed to the vendored default). Ships as the handoff default:
  RA-ready on, pre-review **off**, `critical_acknowledgment_minutes: null`.
- Triggers:
  - `ready_for_review` / `manual_review_required` ⇒ RA "findings ready"
    notice (replaces Stage-3/4 stubs). Subject/body: counts + dashboard deep
    link — **no participant-level content, no PHI in subjects**.
  - Confirmed-finding actions (`submitAction`) ⇒ render protocol-approved
    template (open question #4) with minimum-necessary fields per recipient
    class + deep link.
  - Pre-review path (if ever enabled by protocol): body force-prefixed with
    the "Unverified automated SafetyScan finding pending human review" label
    from the policy schema; never changes finding state.
- Every delivery attempt ⇒ `mica_notification` EM-log row
  (`02-data-model.md §1.3`); failures visible in the dashboard and
  retriable; `action_delivery_status` on the finding instance updated
  `pending→sent/failed` (never silently complete).

### 6.2 Digest + acknowledgment crons

- `config.json` crons: `mica_digest_daily`, `mica_digest_weekly`,
  `mica_ack_monitor` (every 15 min).
- Digests: aggregate counts only (scanned, awaiting review, overdue,
  confirmed/dismissed rates, scanner failures, quality trends) + dashboard
  link; recipients from policy.
- Ack monitor: when `critical_acknowledgment_minutes` is configured,
  unacknowledged critical actions past the deadline ⇒ `ack_overdue`
  notification + audit event; idempotent (one nag per overdue window).

### 6.3 `LaunchReadiness` (`classes/LaunchReadiness.php`)

- `evaluate(): GateResult[]` — pass/fail per gate:
  1. `critical_acknowledgment_minutes` non-null (**the deliberate blocker**)
  2. notification recipients + RA role assignments present
  3. artifact hashes verified against manifest
  4. counselor + scan model aliases resolve in SecureChatAI
  5. notification policy schema-valid
- Enforcement: `production-mode` setting cannot be enabled while any gate
  fails (validated in `redcap_module_save_configuration` or the settings
  endpoint); REDCap project in Production status with `production-mode` off
  or gates failing ⇒ **session start refused** with staff-facing
  explanation; Development status ⇒ visible "launch gates unmet —
  development only" banner in the chatbot. Scanning/review never blocked.
- Launch-readiness card on the dashboard Settings view (Stage 5 placeholder
  wired to `evaluate()`).

### 6.4 Security & compliance pass (`redcap-external-module` skill, Workflow C)

- Run the skill's review workflow: walk `references/security.md` and
  `references/compliance.md` checklists over everything new: `$this->escape()` on
  all output of user-controlled values; parameterized SQL only (claim query,
  index migration checks); CSRF via framework AJAX; every AJAX/API action
  allowlisted with throwing `default`; no-auth surface (`callAI`, `login`,
  `verifyEmail`, `completeSession`) — session-token binding after OTP +
  `throttle()` rate limiting; `getSafePath()`/whitelist on any file access.
- Compliance sweep: no field values in `emDebug` logs on the new paths; no
  PHI in email subjects/URLs; digests aggregate-only; model calls
  pseudonymous-only (assert against built payloads).
- Run Control Center → Module Security Scanning (Psalm) and resolve every
  finding; `composer audit` + `npm audit` on both SPAs.

### 6.5 Full regression + release

- Entire Playwright suite (participant + RA paths, desktop + mobile),
  PHPUnit, vitest, lint — all green via `composer test` / `npm test` (no CI; see stage 0.5).
- Update module `README.md` (architecture summary, settings reference,
  Entity/SecureChatAI dependencies + minimum versions, ops runbook: cron
  cadence, Entity DB manager, refinalize procedure), `CHANGELOG.md`.
- Version bump + versioned directory deployment note
  (`proj_mica_v9.9.10`+); pilot stays on `pilot-final`.

## Tests

- **Unit:** policy validation (default passes; malformed rejected;
  pre-review label forced; null ack minutes ⇒ gate 1 fails); each gate
  independently togglable in fixtures; ack-monitor idempotency; digest
  aggregation math; template rendering minimum-necessary assertion (no
  transcript text in any outbound body).
- **Integration:** delivery failure ⇒ EM-log row `failed` + dashboard
  visibility + retry path; `production-mode` save rejected while a gate
  fails.
- **E2E:** gate scenario — ack minutes null ⇒ production-mode blocked +
  session start refused (staff explanation shown) in Production status;
  set minutes + roles + recipients ⇒ gates pass ⇒ session start allowed;
  digest email captured with aggregate-only content; overdue critical ack ⇒
  nag captured.

## ✅ Implementation record — 6.1 / 6.2 / 6.3 server side (2026-08-20)

Built: `NotificationPolicy`, `NotificationService`, `NotificationResult`,
`LaunchReadiness`, `GateResult`, the four seams
(`NotificationChannelInterface`, `NotificationStoreInterface`,
`RecipientDirectoryInterface`, `ActionFieldWriterInterface`,
`LaunchEnvironmentInterface`) and their REDCap implementations
(`RedcapEmailChannel`, `RedcapNotificationStore`, `RedcapRecipientDirectory`,
`RedcapActionFieldWriter`, `RedcapLaunchEnvironment`). `submitAction` and
`launchReadiness` are live in `ReviewEndpoints`. 893 unit tests green; six
verify scripts PASS against live REDCap on PID 257.

### The gate is at the exit, and it re-reads

`NotificationService::deliverActions()` takes a **record locator**, not a
finding array, and re-reads the finding itself before deciding. Taking the
caller's `review_status` would have made the rule decorative — a stale read
from before a concurrent dismissal, or a caller assembling
`['review_status' => 'confirmed']`, would sail through, and a test double
would supply exactly the shape the code expected so it would pass green. Same
failure shape as `RedcapScanQueueStore` hand-listing SELECT columns while its
fake returned the whole row.

`submitAction` asserts the rule too, but as defence in depth. If the two ever
disagree the service wins, because the service is what hands bytes to a
transport.

`DELIVERABLE_STATUSES = ['confirmed']` is a named constant rather than an
inline `!== 'confirmed'`, so the day someone adds an `escalated` transition
there is one place to revisit. `escalated` and `resolved` are in the workflow
schema but `DispositionService` cannot write them, so they are unreachable
today — and `NotificationServiceTest` has a row asserting each is refused,
which is what will fail loudly if that changes.

### Two of the seven actions are deliberately NOT gated

The plan's sentence — "Only confirmed findings expose approved care-team, PI,
protocol, privacy, or model-quality actions" — enumerates five action types.
The other two are internal to the review workflow:

| action | gated? | why |
|---|---|---|
| `alert_care_team`, `alert_pi`, `alert_protocol_lead` | yes | reaches someone outside the review team |
| `privacy_review`, `model_quality_review` | yes | opens a formal review; named in the handoff sentence |
| `second_reviewer` | **no** | `needs_second_review` *is* a disposition, so gating it on confirmation makes it unreachable at the moment it is meant to be used |
| `document_no_action` | **no** | bookkeeping; delivers to nobody |

This matters most for `scan_failure` placeholders. Those rows exist because a
scan did not complete and a human must read the session manually; they are not
model findings and are never confirmed as such. Requiring confirmation
uniformly would have stranded `document_no_action` on exactly the rows where
staff most need to record that they handled it.

**So the instrument's branching logic is unchanged.**
`action_types` still shows on `[review_status] = 'confirmed' or
[finding_concern_type] = 'scan_failure'`. Branching logic is *display*; the
gate is server-side. Ticking a checkbox on the form records a decision, it does
not send anything — and `action_payload_min` / `action_delivery_status` are
`@READONLY`, so the form cannot fake a delivery.
`NotificationServiceTest::testTheGatedAndUngatedSetsTogetherCoverEveryActionTheInstrumentOffers`
reads the applied CSV and fails if a choice is added without being classified.

### Departure: enforcement keys on REDCap's project status, not `production-mode`

The plan wires enforcement to a `production-mode` setting that cannot be
enabled while a gate fails. **That setting was not built, and the check does
not use one.** A study that never ticked the box would never be gated at all —
and that is precisely the study most likely to have skipped the rest of the
setup. `production-mode` is the *claim* that the gates were reviewed; project
status is the *fact* that participants are real.

`LaunchReadiness::mayStartSession()` is
`!isProductionProject() || isReady()`, and
`RedcapLaunchEnvironment::isProductionProject()` returns **true** if the
`redcap_projects.status` read fails for any reason. A development project
wrongly gated is reported within a minute; a production project wrongly
ungated is invisible.

Consequence for the plan: the test "`production-mode` save rejected while a
gate fails" is moot, and `redcap_module_save_configuration` is not hooked.

### Seven gates, not five

Added two the plan did not list:

- **`scan_mock_mode`** — its own gate rather than folded into the model gate,
  because "the scanner is replaying fixtures" and "the alias is not
  registered" are different problems that send someone to different screens.
  Mock mode in production means every session is screened by a canned file.
- **`recipients`** — the care-team and on-call lists are free text, and a
  malformed address is *skipped* at send time rather than failing the whole
  message (correct at send time: one typo must not suppress the notice to
  everyone else). Without this gate the symptom would be a care team that
  never heard about a confirmed critical finding, months later, with nothing
  in the trail saying anyone was left out.

Passing gates return an empty `howToFix`. Advice on a green line is what makes
people stop reading a checklist, and this checklist has to stay readable.

### Departure: `mica_notification` is an Entity type, not an EM-log row

`02-data-model.md §1.3` calls for an EM-log row. It is
`redcap_entity_mica_notification` instead, because `alreadySent()` is an
indexed lookup on every attempt and — more importantly — because a UNIQUE
index is the only thing that makes send-once hold when two crons race.
`queryLogs()` cannot express either.

**At-least-once, not at-most-once.** The order is check → send → record, which
is deliberately the weaker guarantee: a duplicate email about a confirmed
critical finding is an annoyance, a dropped one is a harm. Claiming the row
before sending would invert that.

**The `dedupe_key` NULL trick.** `dedupe_key` carries the idempotency key on a
`sent` row and NULL on every other, with a UNIQUE index on it.
`idempotency_key` is on *every* row for correlation. So a genuinely concurrent
race is rejected at insert and re-recorded as a flagged duplicate (the mail is
out either way; what the index buys is knowing about it), while a run of failed
attempts can repeat freely — only a second *successful* send is a duplicate.
This rests on `Entity::setData()` converting `''` to `null`
(`redcap_entity_v9.9.9/classes/Entity.php:75`) and on MySQL permitting
unlimited NULLs in a UNIQUE index. `verify-notifications.php` step 7 proves
both against real MySQL, because no fake can.

`EntityTypes::SCHEMA_VERSION` is now `3`. Verified: the new table, all 22
columns and all three indexes applied cleanly to PID 257.

### The body is not stored; the subject cannot carry free text

The trail holds `body_sha256` and `body_bytes`, not the body. A
minimum-necessary body still names a record and this table has no field-level
access control; the auditable verbatim copy lives on the finding form in
`action_payload_min`, where REDCap's own user rights govern who reads it.
Tamper-evidence here, verbatim record there.

Subjects are assembled from fixed strings, integer counts and enum members
only — `enumOr()` substitutes `unspecified` for anything unrecognised. A
subject travels through mail logs and phone lock screens, so a field that
happened to be a string must not be able to reach one.
`testASubjectCanOnlyCarryCountsAndEnumMembers` passes a session type of
`'MRN 12345678 / Jane Doe'` and asserts it does not appear.

Notification bodies carry no participant quotes, no reviewer rationale and no
reviewer notes — asserted in both the unit suite and step 5 of the verifier.

### Departure: crons are `mica_digest` (hourly) + `mica_ack_monitor` (5 min)

Not `mica_digest_daily` / `mica_digest_weekly` / 15-minute ack.

- **`mica_digest` runs hourly and works out for itself whether a window has
  closed.** The idempotency key is `digest_id:since:until`, so every run
  inside the same window resolves to the same key and only the first sends.
  A digest is therefore not lost because the server was busy at 07:00.
- **`mica_ack_monitor` runs every 5 minutes** because
  `critical_acknowledgment_minutes` can be as low as 1, and a monitor that
  runs less often than the deadline it enforces cannot enforce it. Its
  send-once is keyed on the overdue *notice*, never on the cutoff — see the
  bug note below.

Both share `notificationCronPass()`, which puts each project in its own
try/catch: one study with a broken policy or an unreachable mail server must
not stop the others being notified.

Not honoured yet: the policy's per-digest `timezone`. Windows use the server's
timezone. A study spanning timezones would need it.

### The channel refuses what it cannot honour

`RedcapEmailChannel::supports()` returns true only for `secure_email` and
`dashboard`. It does **not** claim `secure_messaging` or
`pager_or_on_call_system`, both of which the policy schema allows. Reporting
an unsupported channel as delivered is the failure that matters: a study
configures a pager for critical findings, the notice goes nowhere, and the
trail says "sent". `dashboard` is supported by doing nothing, which is correct
rather than lazy — a dashboard notice *is* the finding appearing in the queue,
and the queue is written before any of this runs.

One message per recipient rather than one with everyone in `To:`. Staff
addresses are not secret, but a care team learning who else is on the
distribution list is a disclosure nobody asked for, and it is free to avoid.

### `action_*` writes are a separate seam from `review_*` writes

`RedcapActionFieldWriter` uses `normal` save semantics;
`RedcapFindingReviewStore` uses `overwrite`. Opposite choices for opposite
reasons: a withdrawn correction must be clearable (overwrite), and a
previously delivered action must not be erased by a later one that does not
mention it (normal). Each refuses the other's field set outright, which is
what makes each refusal absolute rather than conventional. In particular the
delivery path cannot write `review_status` — otherwise it could confirm a
finding on its way to notifying about it, which is the loop the whole rule
exists to prevent.

Verified on live REDCap: a second action leaves the first checkbox set.

### Bugs found by testing and review

**`NotificationResult::failed()` carried no `actionFields`**, so a delivery
that was *attempted and failed* returned nothing for the caller to persist —
`action_delivery_status = 'failed'` would never have reached the form, and the
finding would have read as though nothing was ever tried. Fixed;
`testATransportFailureIsRecordedRatherThanThrown` covers it.

**The ack monitor would have emailed every reviewer every five minutes,
forever.** Its send-once key was
`idempotencyKey(ACK_OVERDUE, 'cutoff:' . (now - minutes*60))` — a value derived
from the clock, so every cron run computed a different key and `alreadySent()`
could never match. It would have arrived the moment a study set
`critical_acknowledgment_minutes`, which is the first thing they do when going
live, about a list that only grows until somebody acknowledges something.

A frozen test clock hid it perfectly: two calls compute identical keys and the
test passes. The key is now the *notice* (`notice:<notification_id>`), which is
the only stable thing available, so each overdue notice is chased exactly once
ever; nags are batched into one message because twenty overdue findings should
not be twenty emails, and one trail row is written per notice chased.
`unacknowledged()` therefore has to return `notification_id`, and a row without
one is a recorded failure rather than a nag — a store that cannot identify its
own rows cannot support send-once. `NotificationServiceTest` now drives an
**advancing** clock.

**The weekly digest window was a week stale.**
`strtotime('last monday midnight')` excludes today in PHP, so on a Monday it
returned the *previous* Monday: the digest reported the wrong week, then sent
again on Tuesday for the right one. `monday this week 00:00` gives one stable
window per calendar week, covering the completed week, on every day including
Monday and Sunday.

**A terminal-but-retryable scan sent no notice.** The runner's "do not retry"
veto lived in `ScanQueue::finishAttempt()` alone, so the queue persisted
`manual_review_required` while the scan worker — re-deriving the status from
the same inputs to decide whether to write a placeholder and whether to notify
— saw `queued` and told nobody. The session went to a human and no human was
told. The veto moved into `ScanJobStateMachine::afterAttempt()` as a fourth
parameter (defaulting off, so existing callers are unchanged), so both call
sites now agree by construction instead of by matching comments.

**Every notice sent from cron carried a dashboard link with no project on
it.** `getUrl()` derives the project from `PROJECT_ID`, which is undefined in
cron — and the digest, the acknowledgment monitor and the scan worker's
"findings ready" notice all run there. The link is the only actionable thing in
a minimum-necessary body, so this made the whole message decorative, quietly:
`notifyReviewersIfSettled()` catches and `emError`s, so nothing else would have
complained.

Every live check up to that point had run inside a project context — the
verifier sets `$_GET['pid']` before `redcap_connect` — so the cron path had
never executed. Proven by running it with no project request at all;
`MICA::reviewDashboardUrl()` now sets the pid explicitly, overwriting rather
than appending so a cron iterating projects cannot inherit whichever project a
surrounding request was in. The verifier asserts the pid appears exactly once,
in-project, where `PROJECT_ID` *is* defined.

Checked at the same time and **not** a problem: `mica_notification.project_id`
is the `project` entity type, which validates via `RedCapDB::getProject($value)`
— against the value, not `PROJECT_ID`. Unlike the `record` and `user` types,
which is why `mica_audit_event.actor` and `mica_notification.record` are `text`.
A row writes cleanly from cron.

**The digest's `overdue_acknowledgment` bucket counted the wrong thing.**
`RedcapNotificationStore` filled it, but "overdue" depends on the policy's
window and a store has no policy — so it was counting *every* unacknowledged
notice, a much larger number, on the one line a PI is most likely to act on.
`NotificationService` fills it now.

Two verifier path assumptions also fixed: `verify-transcript-store.php` and
`apply-safety-finding-instrument.php` both computed paths with
`dirname(__DIR__, 3)`, which silently pointed at `/var/www/handoff` when the
script was run from a copy under `temp/`.

And one *test* bug the live verifier caught that the unit suite could not: the
first version of the ack-monitor check counted emails, so it read a correct new
nag — an earlier `action_delivery` notice legitimately crossing its window as
the clock advanced — as a duplicate. It counts trail rows for the specific
notice now.

Two verifier path assumptions also fixed: `verify-transcript-store.php` and
`apply-safety-finding-instrument.php` both computed paths with
`dirname(__DIR__, 3)`, which silently pointed at `/var/www/handoff` when the
script was run from a copy under `temp/`.

### Verified against live REDCap (PID 257)

`docs/phase-3-handoff/scripts/verify-notifications.php` — 46 checks, PASS:

```
docker exec -e MICA_MODULE_DIR=/var/www/html/modules-local/proj_mica_v9.9.9 \
  redcap_2023_1_web php /var/www/html/temp/mica/verify-notifications.php
```

By default it does **not** send email — delivery goes through a capturing
channel, so it proves the decision, the trail and the bookkeeping, not SMTP. A
verifier that mailed a study's care team every time somebody ran it would not
get run.

`MICA_REAL_EMAIL=you@example.org` additionally delivers every message through
the genuine `RedcapEmailChannel`, with **all recipients redirected to that one
address** so the project's configured care-team and PI lists are never touched.
The capture is still what the checks assert against, so turning it on cannot
change a result — only add a way for the send itself to fail.

### What real delivery found (2026-08-20)

Six messages — one per body type — delivered through `REDCap::email()` to a
local SMTP capture. Reading them turned up four rendering defects that no
amount of reasoning about the code would have surfaced, because they all live in
REDCap's own derivation of the text/plain alternative:
`Message::formatPlainTextBody()` is `strip_tags(br2nl($body))` and it **never
decodes HTML entities**.

1. **`htmlspecialchars()` broke the dashboard link.** `?prefix=x&page=y&pid=257`
   became `&amp;`, which survives `strip_tags` verbatim — so a reader on a
   plain-text client copied a broken URL. In a minimum-necessary body the link
   *is* the actionable part. Now only `<` and `>` are escaped, which are the two
   characters that could begin a tag; `&` is left alone deliberately.
2. **Apostrophes arrived as `&#039;`** ("the reviewer&#039;s rationale") — same
   cause, merely ugly rather than harmful.
3. **Every line was double-spaced** in the text part, because `nl2br()` emits
   `"<br>\n"` and `br2nl` then turns that `<br>` into a *second* newline.
   Newlines are now replaced by `<br>` rather than accompanied by one.
4. **The digest's space-padded columns rendered ragged.** HTML collapses runs of
   spaces, and `white-space:pre-wrap` is not dependable (Outlook's Word engine
   ignores it), so `sprintf('%-24s %d')` became `Label: n`.

URLs are now real anchors, which also gets REDCap's own `<a href="X">Y</a>` →
`Y (X)` rewrite working for us. Two decisions inside that:

- **Anchor text is the full URL, not a friendly label.** The text alternative
  therefore reads `URL (URL)`. Accepted on purpose: a clinical notice whose
  visible link text hides where it goes is phishing-shaped, and a reviewer
  should see they are being sent to their own REDCap host before clicking.
- **Only a URL alone on its own line is linked.** Found while writing the
  tests: linking any URL anywhere meant a record id of
  `<a href="http://evil.example">click</a>` still produced a live link to
  evil.example — the injected tag was safely escaped, and the linkifier then
  found the URL *in the escaped text* and anchored it. A live link to somewhere
  else inside a genuine MICA safety notification is worth more to an attacker
  than the tag they could not inject. Every real body puts the dashboard link on
  its own line, and record ids cannot contain a newline.

`RedcapEmailChannel` also lost its `MICA $module` constructor argument, which it
never used — the From: address is passed in by the caller and everything else
goes through `\REDCap::email()` and REDCap's globals. That unused dependency was
what made the body conversion, where every one of these defects lived,
untestable without a REDCap. It is now a pure static (`bodyToHtml()`) with 15
tests, including a transcription of REDCap's plain-text derivation so assertions
can be made against the part a text reader actually sees.

### Delivery to a real mailbox is not proven, and cannot be from here

`smtp.stanford.edu:587` is reachable from the container and offers
`AUTH PLAIN LOGIN GSSAPI`, but unauthenticated relay is refused:

```
MAIL FROM:<redcap-server-message@stanford.edu>   250 2.1.0 Ok
RCPT TO:<ihabz@stanford.edu>                     554 5.7.1 Access denied
```

So genuine delivery needs SMTP credentials. The stack's own relay
(`/etc/msmtprc` → `mailhog:1025`) is commented out of `rdc/docker-compose.yml`,
which is why the capture was stood up as a container on the existing network
alias — no config change, nothing left behind. Everything up to and including
the SMTP conversation is verified; the hop from a real relay to an inbox is not,
and is a deployment concern rather than a module one.

All six verify scripts PASS: entity-schema, transcript-chunking,
transcript-store, safetyscan, disposition, notifications.

### Still open

- **6.1 remainder:** `getPolicy` / `savePolicy` endpoints. The policy is
  edited in the module configuration for now, and validated on read — which
  is the half that actually protects a participant.
- **6.3 frontend:** the launch-readiness card on the dashboard Settings view,
  and the "launch gates unmet — development only" banner in the chatbot. The
  `launchReadiness` endpoint that feeds both is live.
- **6.4:** the security/compliance pass — Psalm via Control Center module
  scanning, `composer audit`, `npm audit`, and the
  `references/security.md` / `references/compliance.md` walk.
- **6.5:** full Playwright regression (participant + RA, desktop + mobile),
  `README.md` settings reference + ops runbook, `CHANGELOG.md`, version bump.
- Acknowledgment *recording* — `RedcapNotificationStore::acknowledge()`
  exists and the monitor reads `acknowledged_at`, but nothing in the UI sets
  it yet, so every sent notice is eventually overdue once a target is
  configured. The dashboard card is where that lands.

## Acceptance checklist

- [x] Gates enforced on session start, keyed on REDCap project status;
      staff explanation logged; participant sees approved fallback wording
- [ ] Dev banner + dashboard launch-readiness card (endpoint live, UI pending)
- [x] ~~production-mode un-enableable while failing~~ — superseded: no such
      setting; see the departure note above
- [x] Notifications policy-driven; every attempt logged including refusals;
      failures recorded and retriable
- [x] Pre-review notices labeled and state-inert (even though shipped off)
- [ ] Psalm/security scan + audits clean; checklist findings resolved
- [ ] Full regression green; docs + changelog updated; release tagged
