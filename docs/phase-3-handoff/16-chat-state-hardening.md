# 16 — Chat state hardening (participant UI)

**Branch:** `design/harden-chat-states` · **Date:** 2026-08-19 · **Scope:** `mica-chatbot/` only
**Origin:** the three P0s in `.impeccable/critique/2026-08-19T23-08-46Z__mica-chatbot-src.md` (score 12/40)
**Command:** `/impeccable harden`

No PHP, no config, no schema, no server behaviour was touched. Everything here is
client-side, in the React SPA the participant actually sees.

---

## 1. The session gate is a terminal state, not a chat message

### What was broken

`getSystemContextForRecord()` raises the study's gates as exceptions —
`"Study already completed. Thank you."`, `"Session already completed. Thank you."`,
`"Session already completed. Return in N day(s) for your next session!"`
(`MICA.php:1042,1064,1071`) — and `redcap_survey_page()` forwards the message as
`mica_bootstrap.error`.

`useAuth` then pushed that string into the transcript **as an assistant message**. The
result, reproduced E2E in both branches:

| | Before |
|---|---|
| Fresh participant | Greeted with *"Hi there. I'm MICA. What is your name?"*, then the gate text in MICA's bubble under MICA's avatar and the `MICA AI` label, then *"Please click 'End Session' to ensure compensation"*, over a live composer. Typing `Maya` echoed the gate sentence back as MICA's **reply** — `callAjax` fell back to `mica_bootstrap.error`, which was still set. Pressing End Session found no cached identity and bounced them to the login URL with no explanation. |
| Returning participant | `user_info.current_user` persists in IndexedDB from any earlier visit, so `callAjax`'s readiness check **passed**, a real turn was sent, and **MICA answered normally**. The gate was bypassed entirely from the UI. End Session then ran `completeSession` and redirected to the post-session survey. |

### What it does now

A `sessionState` of `'blocked'` in `ChatContext`, set by `useAuth` from
`mica_bootstrap.error`. In that state:

- `Messages` renders **only** `<SessionNotice>` — the server's sentence verbatim as the
  headline, with an icon and *"If you think this is a mistake, contact the study team."*
  No avatar, no speaker label, no bubble geometry, nothing shared with the transcript.
- The intro greeting is suppressed.
- The end-session/compensation reminder is suppressed.
- `Footer` returns `null`, so there is no composer to type into.
- The `End Session` control is removed from the header, because there is no session to end.

`blockSession()` is also the destination for a **missing bootstrap** — previously a silent
`return` that left a greeting and a live composer which could never send. Note this also
means `npm run dev` with no `window.mica_bootstrap` now shows the notice instead of a chat;
that is correct, not a bug. If `$record` can ever be empty on a legitimate first load of a
chat-host survey, this turns a bad-but-recoverable state into a dead end — worth confirming
against a real survey link.

A `completeSession` failure is **not** routed here; see §4a.

**The rule this encodes: system state and error state never speak as the counselor.**

### Still open (not a UI fix)

The gate is *presented* correctly now, but it is still not *enforced* server-side.
`redcap_module_ajax`'s `case "callAI"` (`MICA.php:633-667`) never calls
`getSystemContextForRecord()`, and `completeSession()` (`MICA.php:1215+`) does not
re-check it either — it resolves session info and saves with
`overwriteBehavior: 'overwrite'` on `raw_chat_logs` / `session_info_complete`. A client
that skips this UI is unaffected by anything in this branch. **Re-derive the gate on both
ajax paths.** (Read from the handlers, not executed against a live project.)

---

## 2. The per-message delete is gone

A 16×16px red `XCircleFill` sat on the corner of every participant bubble and removed
that turn from the transcript, with no label, no confirm, no undo and no server round-trip.

The transcript is the research data and the SafetyScan input, so a participant could
silently drop the exact turn carrying a risk disclosure — by accident, at 16px, while
scrolling. Removed outright, along with `deleteInteraction` from `ChatContext` and
`.delete-icon` from `messages.css`.

If retraction is a real requirement it needs a product decision, a confirm, an undo
window and a server-side audit trail — not a corner icon.

---

## 3. Both remaining destructive actions confirm, and say what they actually do

A shared `ConfirmSheet` — bottom-anchored on a phone so the primary button lands in the
thumb zone, centered from 640px up. `role="dialog"`, `aria-modal`, labelled by its title,
focus moved to the confirm button, focus **restored** on close, `Esc` to dismiss, Tab
trapped inside, 48px minimum buttons.

**End Session** now confirms, and the copy states the consequence and what comes next
(*"You will not be able to add to it afterwards. Next you will be taken to a short
survey."*), with a variant for the case where nothing has been written yet.

**Clear conversation** now confirms, and the copy is honest about what clearing does *not*
do:

> The messages will disappear from your screen and MICA will start over from the beginning.
>
> This does not delete anything. Your session is still recorded, and the study team keeps
> the transcript.

That second paragraph matters: `clearMessages()` only resets local state. The server-side
log is untouched and `raw_chat_logs` is still written at session close, so the old
unlabelled eraser implied an erasure it never performed. **Consider removing this control
entirely** — it has no participant value and its honest description is "makes MICA forget
what you told it."

---

## 4. A turn in flight is visible, and a failed turn is recoverable

### Before
`callAI`'s error path only `console.log`'d. The participant's message sat in the transcript
with no reply, no error and no retry, indefinitely. The only sign a request was in flight
was a 20px `#ccc` rotating icon at 1.6:1 contrast in the footer corner, and the composer
stayed enabled throughout, so a second tap produced interleaved turns.

### After
- A pending row inside MICA's bubble — animated dots plus *"MICA is replying…"* — with
  `role="status"`. It keeps MICA's avatar and label, because it genuinely is MICA about to
  speak.
- The composer, the send button and `End Session` are all disabled while pending, and
  `pendingRef` guards `callAjax` re-entry, so double-send is impossible.
- On failure: an inline error row beneath the turn with a **Try again** button.
  `dispatchTurn(index)` is split out of `callAjax` so a retry re-sends the existing turn
  without duplicating the participant's message in the transcript or in the model context
  (verified: `duplicateUserMessages: 1` after retry).
- The error row **drops** the avatar and the `MICA AI` label
  (`.mica-turn-error::before { content: none }`) — it is the system reporting a transport
  problem.

`readErrorMessage()` handles the shape the transport actually produces. `MICA.php` answers
HTTP 200 with `{"error": ..., "success": false}` on a caught exception, and
`assets/jsmo.js:21-35` hands that body to `errorCallback` as an **unparsed JSON string**.
So an error arrives as a string that may or may not be JSON, an `Error`, or a bare
message. All of those reduce to one actionable sentence, and anything that looks like a
payload rather than prose falls back to *"That didn't reach MICA. Check your connection and
try again."* A participant is never shown a raw JSON blob.

### 4a. A finalization failure is reported, not terminal

A `completeSession` failure is a different animal from a gate: the session existed and the
messages exist, only the save failed. Routing it through `blockSession()` would hide the
transcript and remove the `End Session` button — which is precisely the "no way to retry"
half of what docs 14 D16 set out to fix. So it gets its own non-terminal state,
`sessionError`, rendered as a `role="alert"` system row after the transcript, in the same
non-counselor treatment as a failed turn.

Verified: on failure the transcript stays (6 message rows), the composer stays, `End
Session` stays present **and enabled**, the confirm dialog closes, no session notice
appears, the row carries no `MICA AI` label — and pressing `End Session` again completes
and reaches the survey link.

---

## 5. Composer and controls

- `<input>` → auto-growing `<textarea>` (max 132px, then scrolls). **Enter** sends,
  **Shift+Enter** starts a new line; previously a multi-paragraph answer was impossible and
  anything longer than the field was invisible while typing.
- `maxLength=2000`.
- A visually-hidden `<label for>`; the field had no accessible name at all.
- Accessible names and `title`s on the clear and send buttons; they announced as "button".
- Every target ≥44×44 (was: clear 27×29, send 32×29, End Session 100×**27**, delete 16×16).
- Send is a filled accent button; the clear icon moved from `#ccc` (**1.6:1**) to `#5F6673`
  (**5.8:1**). Placeholder `#6A7180` (4.9:1) instead of the browser default.
- `:focus-visible` rings everywhere. `.footer .user_input:focus { outline: none }` had
  removed the ring from the app's primary control with nothing in its place.
- Focus returns to the composer when a turn completes.
- `role="log" aria-live="polite"` on the transcript. There were **zero** live regions, so a
  screen-reader user was never told a reply had arrived.

---

## 6. Tokens and browser surfaces

`src/index.css` gains a narrow token set naming colours the stylesheets already used as
literals, plus the two the app lacked: a focus ring (`#8AB4FF` on dark, 6.6:1; `#1B41C7` on
white, 8.0:1) and a danger tone that is not pure red (pure `#FF0000` on the `#2846E4`
bubble measured **1.7:1**). Text selection, caret and the transcript scrollbar are themed
and scoped to `#chatbot_ui_container` so nothing leaks into the host REDCap survey page.

A full token pass belongs to `/impeccable extract`.

---

## Verification

Rendered from the **built** `mica-chatbot/dist` bundle with the REDCap
`window.mica_jsmo_module` / `window.mica_bootstrap` contract stubbed. Chromium,
1440×900 and 390×844 @2x, both viewports for every state.

| Check | Result |
|---|---|
| `npm run lint` | **passes** (baseline was 43 problems) |
| `npm run build` | clean |
| Per-message delete icons in DOM | **0** |
| Touch targets | clear 44×44, send 44×44, End Session 115×45 |
| Composer | `TEXTAREA`, labelled, grows to 132px then scrolls |
| Live regions / `role="log"` | 1 / 1 |
| In flight | pending row shown; composer, send and End Session all disabled |
| Pending row speaker label | `"MICA AI"` (correct — MICA is speaking) |
| Error row speaker label | `none` (correct — the system is speaking) |
| Turn failure | module error envelope parsed to a sentence; message kept; retry offered |
| After retry | error cleared, reply arrived, `duplicateUserMessages: 1` |
| Gate, fresh participant | notice shown; intro, nag, composer and End Session all gone; 0 bubbles |
| Gate, returning participant (stale cached identity) | same — **the bypass is closed in the UI** |
| Confirm dialogs | `aria-modal`, labelled, focus on confirm, Esc closes and restores focus |
| Finalization failure | transcript kept, composer kept, End Session present **and enabled**, `role="alert"`, no MICA label, retry completes |
| Heading structure when blocked | `h1` "MICA AI Chatbot" → `h2` the notice sentence |
| Page errors | none |

**Not verified:** REDCap's own CSS cascade on a real survey page, real iOS keyboard and
viewport behaviour, and live server responses on the ajax paths (the transport is stubbed,
so failures were induced at the JSMO boundary in exactly the shapes `MICA.php` and
`jsmo.js` produce, rather than by a real server).

---

## Deliberately left alone

These are real, and they belong to other passes rather than this one:

- **The 158-char desktop measure.** The detector's only complaint at 1440px, on 7 elements.
  → `/impeccable layout`
- **200% zoom still collapses** outside the components added here. → `/impeccable audit`
- **`Visitor`** is both the wrong register and invisible — the `dt::before` label is clipped
  at 100% and only appears at 200%, overlapping the bubble. → `/impeccable clarify` + layout
- **`intro_text` / `end_session_text` never reach the JSMO**, so the study team's
  `required: true` copy silently never ships and the hardcoded fallbacks always show.
  Needs a `MICA.php` change, which this branch deliberately does not make. → `/impeccable clarify`
- **No crisis affordance.** The other P0. → `/impeccable onboard`
- **The first-run void.** → `/impeccable onboard`
- **`main.jsx` still imports the dead `App` component.** It is load-bearing: that import is
  what pulls `App.css` and `global.css` into the bundle, *after* the component stylesheets
  `App.jsx` imports first, and that order is what makes `App.css`'s
  `.messages { overflow-y: scroll }` win over `messages.css`'s `overflow: hidden`.
  Replacing it with direct CSS imports silently stops the transcript from scrolling.
  Untangle it in a layout pass, with a render to prove it. Marked with an
  `eslint-disable-next-line` and that explanation.
- **`PostSession` is still unreachable** (`endSession` uses `window.location.href`), and the
  compensation reminder still sits under a failed turn.

## Branch note

This branch was cut while a concurrent session was committing on `mica-phase-3`, and that
session's stage-0 commit `c939851` ("feat(stage-0): schema validation over the pinned
handoff schemas") landed **on this branch** while local `mica-phase-3` stayed at `845d1bd`.
`c939851` contains no `mica-chatbot/` files, so the two changes never overlapped.

**Resolved 2026-08-19:** local `mica-phase-3` was fast-forwarded `845d1bd → c939851`
(`git branch -f`, no checkout, so `vendor/` and `tools/` were never removed from the working
tree). The stage-0 commit now sits on the branch it belongs to, and this branch's delta over
`mica-phase-3` is exactly the two harden commits.

Re-basing this branch onto `845d1bd` was deliberately not done, and `git branch -f` was used
in preference to a checkout for the same reason: checking `845d1bd` out would delete
`vendor/` and `tools/` from the working tree, and `MICA.php` now hard-requires
`vendor/autoload.php`.

**Still outstanding:** `origin/mica-phase-3` is at `fd6b3e6`, four commits behind local —
`34144f5`, `cd01f24`, `845d1bd`, `c939851`, all of them stage-0 workstream. Until those are
pushed, a PR opened against the *remote* `mica-phase-3` will still show them alongside the
harden work. Pushing them is the stage-0 author's call, not this branch's.

`.impeccable/critique/` is committed (the critique this branch answers, so the reasoning
travels with the code). `.impeccable/config.local.json` is deliberately left untracked.

## Lint config

Two changes in `mica-chatbot/.eslintrc.cjs`, both to make `npm run lint` mean something:

- `globals`: `mica_jsmo_module`, `mica_bootstrap`, `ExternalModules` — injected by the
  module, so `no-undef` on them was a false positive.
- `react/prop-types: off` — no component in this app has ever declared propTypes,
  including the two providers that predate this branch, so the rule only produced noise
  and kept lint permanently red. Adopting `prop-types` project-wide is a reasonable
  alternative; it just has to be an actual decision.

The remaining baseline errors were stale unused imports and two `no-async-promise-executor`
violations in `useAuth`'s login helpers; those executors are now synchronous with the async
work inside, so a throw rejects instead of becoming an unhandled rejection.
