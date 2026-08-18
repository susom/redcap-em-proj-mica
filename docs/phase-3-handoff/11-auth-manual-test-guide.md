# Manual Test Guide — MICA Participant Authentication (PID 257)

Tests the configuration recorded in
[`10-auth-implementation-pid257.md`](10-auth-implementation-pid257.md). Everything below was
executed once already; this is the procedure to reproduce it by hand.

**What you are testing:** REDCap-native Survey Login on the two MICA chat surveys — a participant
must confirm their last name before any chat content renders, with lockout after 5 failures in a
30-minute sliding window.

**What you are *not* testing yet** (not built — see `10 §6`): link expiry, SMS delivery, and the
chat itself (the `proj_mica` module is not enabled on PID 257, so the surveys render their
placeholder mount field rather than the chat UI). "Chat content rendered" below therefore means
*the mount point rendered* — i.e. auth let you through.

---

## 1. Set up a test participant

The fiddly part is getting a **valid** session link: the credential must exist at the
pre-randomization event **and** the record must exist in a randomized arm, or
`getSurveyLink()` returns null (`10 §4.1`). The helper does both.

```bash
CONTAINER=redcap_2023_1_web
S=/var/www/html/temp/scripts        # where the scripts land inside the container

# copy the whole directory (avoid `cp dir/. dest/` - it can silently under-copy)
docker cp docs/phase-3-handoff/scripts $CONTAINER:/var/www/html/temp/

docker exec $CONTAINER php $S/manual-test-auth.php 257 setup
```

It creates record **`MICATEST01`** (last name **`Testerson`**, randomized into arm 2) and prints
three links: the **ED session**, the **booster session**, and **`baseline1`** as a control.

Other actions: `links` (reprint), `reset-lockout` (clear failed attempts — you will need this),
`teardown` (remove everything).

### Getting the link through the UI instead

If you would rather not use the helper: *Record Status Dashboard → select the arm → open
`MICATEST01` → Record Home Page → click the survey icon in the row for* `MICA ED session`, or
*Survey Distribution Tools → Participant List → choose the survey and event → copy that
participant's link*. Note the arm selector — the MICA surveys only exist in arms 2 and 3, so a
record sitting only in arm 1 will not show them.

---

## 2. Test matrix

Open the **ED session link** in a browser. Expected results, all verified:

| # | Do this | Expect |
|---|---|---|
| 1 | Open the link (fresh browser / private window) | A "Survey Login" dialog with a **masked** last-name field, a "Show value" checkbox, and REDCap's stock wording. **No chat content behind it.** ⏳ The dialog opens from a 500 ms JS timer — if you script this, wait for the field to be *visible*, not merely present in the DOM |
| 2 | Enter `Testerson` | Through to the session — the survey renders "Loading your MICA session…" |
| 3 | Reload the link in the same browser | Straight through, no prompt (30-minute session cookie) |
| 4 | Close the **whole browser**, reopen the link | Prompt again — the second cookie is a session cookie that dies with the browser |
| 5 | Fresh window → submit an **empty** field | Rejected. ⚠️ This is the case that dictated the whole design — see §4 |
| 6 | Fresh window → `Wrong` | Rejected, prompt redisplayed **with a red ERROR box containing the study's help text** about exact spelling / extra spaces / "Show value". That message is configured in `survey_auth_custom_message` and only ever appears in this failed state — never on the first prompt (`10 §1.3a`) |
| 7 | Get it wrong **5 times** in 30 minutes | *"Access denied"* — the prompt disappears entirely |
| 8 | While locked out, enter the **correct** name | Still refused. Denial happens before verification |
| 9 | Run `manual-test-auth.php 257 reset-lockout`, retry correct name | Through again |
| 10 | Open the **booster session link**, enter `Testerson` | Same behaviour — both host surveys are gated |
| 11 | Open the **`baseline1` control link** | **No login prompt** (renders as "Enrollment"). This proves the gate is scoped to the MICA surveys and has not been applied to the other 18 surveys |
| 12 | **Submit** the session survey, then reopen the **same** link | Login prompt again, then back into the session. The link is permanent and is **not** consumed by submission — see below |

### Does a submitted survey need a new link?

**No.** The participant's link never changes — the hash is permanent per (record, survey, event,
instance). Submission does not consume it, *provided* `edit_completed_response` is on, which it
now is. If that setting is ever turned off, a completed response makes the link dead
(*"you have already completed this survey"*) with no way back into the participant's own session
— and because these host surveys have no real questions, a single page submit completes them.
`verify-auth-config.php` asserts this setting for exactly that reason. Details: `10 §1.2a`.

Two related things that *do* change behaviour:

- **A different session window means a different link.** The ED session and the Month-3 booster
  are separate surveys at separate events, so they have separate links. Finishing the ED session
  does not give access to the booster.
- **Re-entry always re-authenticates** if the 30-minute idle window has passed or the browser was
  closed. The link alone is never sufficient once the session cookie is gone.

### Credential matching — case and whitespace

Verified against a stored value of `Testerson`:

| Typed | Result |
|---|---|
| `Testerson`, `testerson`, `TESTERSON` | ✅ accepted — matching is case-insensitive |
| `" Testerson"` (leading space) | ❌ rejected |
| `"Testerson "` (trailing space) | ❌ rejected |

⚠️ **Worth knowing for the study runbook:** REDCap does **not** trim the submitted value
(`Surveys/index.php:1477` compares with `strtolower()` only), and the input is **masked**, so a
stray space is invisible to the participant. Mobile keyboards frequently append a space after an
autocomplete suggestion. There is a "show text" checkbox next to the field — worth pointing
participants at it. This is core REDCap behaviour; no configuration setting changes it.

---

## 3. Mobile check

The ED baseline runs on the participant's **own phone**, so check the prompt at a phone viewport
(e.g. 390×844) as well as desktop:

Checked at 390×844 (Chromium, Playwright) — results:

| Check | Result |
|---|---|
| Dialog fully visible, no horizontal overflow | ✅ `scrollWidth == innerWidth`; dialog centred |
| Credential field tappable | ✅ 227 × 24 px, "Show value" checkbox directly beneath it |
| Failed-login help text readable | ✅ renders in the red ERROR box, wraps cleanly, not truncated |
| Chat UI after authenticating | ✅ header + "End Session", MICA's greeting bubble, message input pinned to the bottom |
| Cosmetic nit | ⚠️ the credential label ("What is your last name?") wraps one word per line, because REDCap lays the login field out in a narrow `form_border` table cell. Legible but ugly. The label comes from `baseline1.last_name`, which is also used in the enrollment survey, so shortening it would change that survey too — left alone deliberately |

Still worth doing by hand: **iOS Safari**, which is the likely browser for this population and the
one whose keyboard/autocorrect behaviour drives the trailing-space failure mode above.

---

## 4. The one result that matters most

**Step 5 (empty submission) must be rejected.** REDCap treats a blank submitted credential as
matching a blank *stored* value, with no empty-value guard. That is why the configuration uses
**exactly one** credential slot: with a second slot, one of them necessarily points at an event
where the participant has no data, and an empty submission matches it — a login bypass.
Demonstrated on PID 257 and written up in `10 §3`.

So if step 5 ever **succeeds**, either a second credential slot has been added or the record's
`last_name` is blank at event 1004. Run the verifier, which asserts both:

```bash
docker exec $CONTAINER php $S/verify-auth-config.php 257 last_name 1004
# expect: *** ALL CHECKS PASSED ***   (it also reports any records with a blank credential)
```

---

## 5. Confirm the audit trail

Every attempt is written to REDCap's own log, outside module control. In the UI: *Logging →
filter to the record*. You should see `Survey Login Success` / `Survey Login Failure` entries
naming the field used.

Directly, if you prefer (note REDCap shards its log table — PID 257 uses `redcap_log_event15`):

```sql
select ts, pk, description, left(data_values, 80)
from redcap_log_event15
where project_id = 257 and description like '%Survey Login%'
order by log_event_id desc limit 20;
```

---

## 6. Tear down

```bash
docker exec $CONTAINER php $S/manual-test-auth.php 257 teardown
docker exec $CONTAINER rm -rf /var/www/html/temp/scripts
```

It reports the remaining data-row count, which should return to **0**. The `Survey Login`
entries stay in REDCap's log — logs are not deleted, which is correct. Re-running the verifier
afterwards should still report all checks passed.
