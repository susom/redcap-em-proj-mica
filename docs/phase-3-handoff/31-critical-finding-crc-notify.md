# 31 — A critical finding reaches the CRC within 5 minutes

**Requirement (the PI, 2026-09-28):**
- "We need to have an AI identify critical safety findings ideally within 5 min of session completion."
- "For critical finding also within 5 minutes CRC needs to be notified."

**Status:**
- **Met on the local replica** (PID 271), measured as a participant: End Session → the CRC's email in
  **22 s, 42 s and 65 s** over three runs.
- **Not yet on prod** (35968). Prod needs two things:
  1. The D23 fix deployed. It is in the working tree, not committed.
  2. (2026-10-02) The CRCs' and Brian's addresses in *Reviewer notification addresses* (below). With
     that list filled in, the Reviewer role is optional.

Until the fix is deployed, **no session on prod is ever screened**. Every session reaches the
reviewers as "could not be screened", about 7.5 minutes after it ends.

## The chain, and where the time goes

No new notification code was needed; the existing "findings ready" notice already fires with no human
in the loop. What was broken was the scan in front of it ([14 D23](14-live-defects.md)).

| # | Step | Time |
|---|---|---|
| 1 | Participant clicks **End Session**. The transcript is finalized and the scan job queued, due immediately, in the same request | 0 |
| 2 | `mica_scan_worker` (every 60 s) claims it | wait for the next minute: 0–60 s |
| 3 | SafetyScan call, `gpt-5-6-sol` | 5.6–10.4 s measured |
| 4 | The answer is checked against the pinned schema, and every quote against the transcript. Findings are written to `mica_safety_finding` | < 1 s |
| 5 | **Same cron pass:** the `reviewers_ready` email goes to the addresses in *Reviewer notification addresses*, or, when that is blank, to every user in the REDCap role mapped as MICA **Reviewer** (2026-10-02; the runs below predate the list and used the role) | < 1 s + mail delivery |

The email ([screenshot](../../e2e/shots/critical-notify-email-mobile.png), gitignored):
- **Subject:** `[MICA] 2 SafetyScan finding(s) ready for review - highest urgency: critical`
- **Body:** the record, session type, count, highest urgency and the review-dashboard link.
- **Never in it:** the participant's words.
- **Layout:** fits a phone screen. The dashboard link used to push it 4 px wider than the screen on
  this host, and wider on prod's longer host. It now wraps (`RedcapEmailChannel::bodyToHtml`).

### Measured (e2e/critical-notify.js, PID 271, 2026-09-28)

| Record | Code | Timeline after End Session | CRC email |
|---|---|---|---|
| `SCAN5MIN01` | before the fix | job queued +6 s; attempts at +33 s, +156 s and +456 s, each `service_error`; then `manual_review_required` | "A session could not be screened…", **+457 s**. Nothing was flagged: only a `scan_failure` placeholder |
| `SCAN5MIN02` | after | queued +6 s, claimed +38 s, `ok` +44 s | "…highest urgency: critical", **+42 s** by the mailbox's receipt clock |
| `SCAN5MIN03` | after | queued +6 s, claimed +60 s, `ok` +65 s | "…highest urgency: critical", **+65 s** |
| `SCAN5MIN04` | after | queued +6 s, claimed +12 s, `ok` +22 s (10.4 s at the provider) | "…highest urgency: critical", **+22 s** |

The disclosure was the same in each run: a bottle of vodka a day, and a saved bottle of sleeping pills
to be taken tonight. After the fix every record got two findings:
- `self_harm`, **critical**: "current suicidal desire, a specific overdose plan for tonight"
- `dangerous_alcohol_use`, high

**Worst case with a healthy scan: about 1.5 minutes.** That is 60 s of cron wait, the scan time, and
delivery.

### The runbook's six-message test (doc 32, step 6), 2026-09-28

This found a second problem, and it is **not** in MICA's code.

**Azure's content filter refuses MICA's reply to "Honestly I don't see the point of anything
anymore."**
- **What happened:** the counselor call came back HTTP 400, 988 bytes, `code: content_filter`, with
  **`self_harm` rated medium**. The filter blocks at medium.
- **What the participant sees:** SecureChatAI turns the refusal into "I apologize, but I'm experiencing
  network difficulties. Please wait a moment and try again." ([screenshot](../../e2e/shots/critical-notify-session.png),
  gitignored).
- **Prod has it too.** The same 400 and 988 bytes are in prod 35968's SecureChatAI log at 14:47:24,
  for the same message.
- **So does the development key.** This is the AI Hub's filter policy, not a prod setting.
- **Which sentences it blocks:** probed one at a time on `gpt-5-6-luna` and `gpt-5-6-sol`, only this
  one of the six. More explicit ones pass, rated low: "I do not want to be alive anymore…", "I'm going
  to take all of them tonight".
- **It depends on context.** In one full local session the filter let the same sentence through; in
  the next it didn't. The verdict turns on the wording and on the prompt around it.

**Locally, the scan is not blocked and the CRC is still told. On prod it wasn't.** Every failure
showed the same generic "rewrote as an assistant message" error. In every case the scan asked for a
model that prod's SecureChatAI doesn't have.

1. **Record 130, job 20, 14:48** (`scripts/diagnose-scan-notify.sql`, query 2): all three attempts ran
   on **`gemini-2.5-flash`**, in 76–85 ms. That is MICA's fallback for a blank alias, and prod no longer
   registers it. Why the alias was blank is not settled. Either 35968's own alias was blank then, or
   another MICA project's pass claimed the job
   ([14 D24](14-live-defects.md), real and now fixed, but unconfirmed on prod).
2. **After the fixes were deployed:** the alias had been typed **`gpt-5.6-sol`**, which is the model's
   version name, instead of `gpt-5-6-sol`. SecureChatAI refuses it ("Unsupported model: gpt-5.6-sol");
   reproduced here, failing in 20 ms with the same generic error. Found 09-28.

**How to avoid it:**
- **Use the name as registered.** The alias must match SecureChatAI's registry exactly.
- **Check the Launch readiness gate.** The *Model aliases resolve* gate turns red on a wrong alias,
  but Development status only shows a banner.
- **The real reason is in SecureChatAI's log.** View Logs says "Unsupported model: …".

The only scans that worked on prod were record 1's, 5's and 6's in August, on
`google/gemini-2.5-flash` while it was still registered.

**Fixed along the way:** D24 (scan claims are now scoped to their project), and D25 (escaped
apostrophes that failed the quote check), found on the prod copy, PID 279.

What happened locally:
- **The scan passes the filter.** The same conversation, wrapped as a transcript under the scan
  prompt, is rated self_harm low.
- **Measured with this sentence in the transcript** (records `SCAN6TURN02` and `SCAN6TURN03`):
  - the scan returned `ok` on the first attempt, with a critical `self_harm` finding
  - the CRC email "highest urgency: critical" arrived **65 s and 71 s** after End Session

  So the safety net holds even when the counselor's reply is refused.

**Escaped apostrophes** (`Sanitizer`, `htmlspecialchars(ENT_QUOTES)`):
- **Where they end up:** every participant message reaches the model, and the scan transcript, as
  "don&#039;t".
- **Why it passed here:** the scanner copied the entity into its quotes as it appears, so the
  byte-exact quote check passed.
- **The risk:** a model that decodes it would fail the whole scan as `citation_mismatch`. That failure
  isn't retried; it goes to manual review.
- **What reviewers see:** "&#039;" in the evidence. Not changed yet.

To see whether a server's AI Hub key refuses self-harm text, and why, run
[`scripts/probe-content-filter.php`](scripts/README.md). On the development key it prints the 988-byte
`content_filter` refusal for this sentence.

## What prod needs before go-live

Step by step, with every MICA setting by its dialog label: [32](32-prod-runbook-crc-notify.md).

1. **Deploy the D23 fix.** It is part of blocker 8 in [30](30-go-live-readiness.md): prod runs
   whatever MICA code it was given.
2. **Name who is emailed; the Reviewer role is optional (2026-10-02).** The PI asked for the results
   to go to a list of emails, not a group. In MICA → Configure:
   - **Reviewer notification addresses**: when it has any entry, the "findings ready" email, the
     "could not be screened" email and the overdue reminder go to these addresses **instead of** the
     role. With at least one valid address on it, no Reviewer role is needed. Blank keeps the role.
     Comma, semicolon or newline separated; `Name <address>` (an Outlook paste) is accepted.
   - **Reviewer role(s)**, directly above it, is optional with a list. If a role is mapped and has
     members, they can open the findings, and second-review requests go to them; otherwise those
     requests go to the list.

   On prod, in this order:
   1. Deploy the release (step 1). The setting exists on prod only after it.
   2. Fill in *Reviewer notification addresses* with the CRCs' and Brian's addresses.
   3. Optionally remove the Reviewer role (135783 `CRC (MICA reviewer)`, whose only member was ihabz,
      the developer), or keep it. Not before step 2: with the list blank and no role, the gate fails.
   4. Open the Launch readiness tab straight away and confirm *Reviewers configured* and *Recipient
      lists are valid addresses* pass. On a Production project a red gate refuses every new session.
      That happened on prod on 2026-10-02, when the Reviewer role was removed. Reproduced on scratch
      PID 280 (Production status): before this change, the participant saw "This session cannot start
      right now"; after it, the MICA chat loads, desktop and iPhone.

   **Who can open the findings is unchanged:** members of the Reviewer or PI-lead role. On prod,
   Project Admins (134858) is PI-lead: 8 users, including Brian. With the Reviewer role removed, only
   they can open and confirm findings; everyone else on the list just gets the emails. A CRC needs a
   role only if they should open the dashboard. Then:
   - The CRC must be **assigned the role**. Custom rights don't count.
   - The account must **not be suspended**.
   - Its REDCap **profile email** should be the address on the list. The gate matches the two,
     ignoring case, so someone in the role under a different profile email can open the dashboard but
     is still listed as unable to.
   - Don't map Project Admins as a Reviewer role: its 8 users can already open findings as PI-lead,
     and every second-review request would go to all of them.

   With a list, the "Reviewers configured" gate passes on the list alone and shows how many addresses
   are on it. Anyone named who is not in the Reviewer or PI-lead role is listed as "emailed but unable
   to open the review dashboard": a note, not a failure (`verify-settings.php` prints the same). It
   still fails:
   - with a list that has no valid address. The role is **not** a fallback, so the notices would reach
     nobody;
   - with the list blank, when no role is mapped as Reviewer, or the mapped role has nobody in it.

   **Removing a CRC** means taking them off the list, and out of the role if they are in one.

   A mapped role with nobody in it, and the list blank, is how the 09-22 notice on 271 went nowhere:
   "No recipient addresses were given". A list with no valid address now ends the same way.
3. **REDCap's cron runs every minute.** This is standard on the Stanford server, and the 60 s step
   above depends on it.
4. **Prove it on prod once.** Run one synthetic critical session on a test record, click End Session,
   and time the CRC's email. Then remove the record with the rest of the test data (doc 30,
   blocker 7).
   - Make it a realistic length: every run here was two turns.
   - Check prod's SafetyScan alias and `close-expired-sessions` first ([30](30-go-live-readiness.md),
     "Safety review: settings to check on prod"). Both were measured here on 271, not on prod.

   The preflight script is not evidence: it said PASS on 271 throughout, while no scan could succeed
   and nobody was in the reviewer role.

## What "within 5 minutes" does not cover today

- **MICA's reply to some self-harm statements.** Azure's content filter refuses it, and the
  participant is told "network difficulties" (above). Locally the scan and the CRC's email were
  unaffected; on prod's test they both failed, cause still open. The fix for the filter is the AI Hub
  team's content-filter configuration for MICA's deployments.
- **Sessions the participant never ends.** Closing the tab does not end the session. It is scanned
  only when the hourly closer closes it:
  - `close-expired-sessions` must be on. It is **off on 271**, so there an abandoned session is never
    scanned. Check prod.
  - The window is `ed-session-window-hours` (default 24) from the first message.

  So a disclosure followed by a closed tab is seen **about a day later**. Shortening the window is a
  study decision, because a participant cannot come back after it closes.
- **A scan that fails and retries.** Retries come 60 s and then 240 s later:
  - The first retry succeeding lands at about 2–3 minutes.
  - The second retry succeeding lands at about 6–7 minutes, past the target.
  - Three failures mean a "could not be screened" email at about 7.5 minutes.
- **An answer the module can't verify.** A quote that isn't in the transcript word for word
  (`citation_mismatch`), or the model saying it can't assess (`refusal`), is **not retried**. It goes
  straight to manual review:
  - The CRC is still emailed inside 5 minutes, but as "could not be screened", without the critical
    label.
  - Every run here was a two-turn transcript. A long real session is where a quote miss is likelier,
    and that is not measured yet.
- **More than 5 sessions ending in the same minute.** The worker runs up to 5 scans per project per
  pass, and the sixth waits a minute. That is far above ED pace.
- **Acknowledgement.** The launch gate `critical_acknowledgment_minutes` feeds the ack monitor
  (every 5 min), which sends **one** "past the N-minute acknowledgment window" email per notice.
  **Nothing in the module ever records an acknowledgement**, though: `RedcapNotificationStore::acknowledge()`
  is called only by a verifier script, and no dashboard action calls it. So once N is set, every
  `reviewers_ready` notice gets that reminder N minutes later, whatever its urgency and whether or not
  someone has already reviewed it. What the PI is choosing is **when that one reminder goes out**, not
  a tracked deadline. The reminder goes to the same people as the findings email: the named list, or
  the role when the list is blank (2026-10-02).
- **Only the reviewers hear first.** The notice goes to *Reviewer notification addresses*, or to the
  Reviewer role when that is blank (2026-10-02), for any finding, with the urgency in the subject.
  Anyone else (care team, protocol lead, and the PI unless his address is on the list) is told only
  after a reviewer confirms the finding and chooses to alert them. The "pre-review" path that would
  tell them sooner is disabled by policy, and nothing calls it.

## Reproduce

```bash
docker run -d --rm --name mica_mailpit --network redcap_2023_1_redcap_network \
    --network-alias mailhog -p 18025:8025 axllent/mailpit:latest       # local mail goes to mailhog:1025
S=modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts
docker exec -w /var/www/html redcap_2023_1_web php $S/e2e-crc-reviewer.php 271 setup
docker exec -w /var/www/html redcap_2023_1_web php $S/seed-rand-test.php 271 CRIT01
node e2e/critical-notify.js CRIT01 "<tsr survey link for CRIT01>"   # stores last_name for Survey Login
docker exec -w /var/www/html redcap_2023_1_web php $S/e2e-crc-reviewer.php 271 teardown
docker stop mica_mailpit
```

- **Standard Care records:** a record allocated Standard Care has no session. The script says so;
  seed another record.
- **Without the mail sink:** `REDCap::email()` fails and the notice row reads `failed`. That looks
  like a module bug, and it isn't one.
- **Don't run `verify-safetyscan.php` alongside:** it deletes every scan row on the instance.
