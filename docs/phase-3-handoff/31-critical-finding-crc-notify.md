# 31 — A critical finding reaches the CRC within 5 minutes

**Requirement (the PI, 2026-09-28):**
- "We need to have an AI identify critical safety findings ideally within 5 min of session completion."
- "For critical finding also within 5 minutes CRC needs to be notified."

**Status:**
- **Met on the local replica** (PID 271), measured as a participant: End Session → the CRC's email in
  **22 s, 42 s and 65 s** over three runs.
- **Not yet on prod** (35968). Prod needs two things:
  1. The D23 fix deployed. It is in the working tree, not committed.
  2. The CRCs placed in the reviewer role.

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
| 5 | **Same cron pass:** the `reviewers_ready` email goes to every user in the REDCap role mapped as MICA **Reviewer** | < 1 s + mail delivery |

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

## What prod needs before go-live

Step by step, with every MICA setting by its dialog label: [32](32-prod-runbook-crc-notify.md).

1. **Deploy the D23 fix.** It is part of blocker 8 in [30](30-go-live-readiness.md): prod runs
   whatever MICA code it was given.
2. **Put every CRC in the reviewer role.** MICA → Configure → *Reviewer* names one or more REDCap
   roles, and the notice goes to everyone holding one of them:
   - The CRC must be **assigned the role**. Custom rights don't count.
   - The account must **not be suspended**.
   - The account must have an **email address**.

   The Launch readiness tab's "Reviewers configured" gate shows the head count. A mapped role with
   nobody in it is exactly how the 09-22 notice on 271 went nowhere: "No recipient addresses were
   given".
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
  a tracked deadline.
- **Only the reviewer role hears first.** The notice goes to that role for any finding, with the
  urgency in the subject. Care team, PI and protocol lead are told only after a reviewer confirms the
  finding and chooses to alert them. The "pre-review" path that would tell them sooner is disabled by
  policy, and nothing calls it.

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
