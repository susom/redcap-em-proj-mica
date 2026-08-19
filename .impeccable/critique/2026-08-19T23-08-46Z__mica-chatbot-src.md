---
target: the chatbot design
total_score: 12
max_score: 40
na_heuristics: 
p0_count: 3
p1_count: 2
timestamp: 2026-08-19T23-08-46Z
slug: mica-chatbot-src
---
⚠️ DEGRADED: single-context (global CLAUDE.md forbids subagents without explicit permission — standing pre-emptive decline)

**Target:** `mica-chatbot/src` · **Mode:** Operate · **Rendered from:** the shipped `mica-chatbot/dist` bundle with the REDCap `window.mica_jsmo_module` / `window.mica_bootstrap` contract stubbed · Chromium 1440×900 + 390×844 @2x, 8 scenarios, 4 DOM probes, keyboard-order and 200%-zoom passes, plus a two-branch E2E reproduction of the gate state.

**Not observed (state honestly):** REDCap's own CSS cascade on a real survey page, real iOS keyboard/viewport behavior, a real `callAI` latency distribution, and live server responses on the ajax paths (the transport is stubbed; the two PHP handlers were read, not executed). Everything below was rendered from the artifact that actually ships.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 1 | Send → nothing. No typing indicator, no pending bubble, input stays enabled. Only feedback is a 20px `#ccc` rotating arrow at 1.6:1 contrast in the footer corner. No session progress, no phase, no time remaining. |
| 2 | Match System / Real World | 2 | Gate copy "Return in 12 day(s) for your next session!" arrives in the counselor's own voice, avatar and bubble — system state impersonating the therapist. "End Session" is study-ops language for what the participant experiences as "I'm done, and this is how I get paid." |
| 3 | User Control and Freedom | 1 | No undo on either destructive action. No cancel on an in-flight turn. No confirm on End Session, which is irreversible and tied to compensation. |
| 4 | Consistency and Standards | 2 | `PostSession` uses inline styles in a divergent visual language and is unreachable. `dt`/`dd` bubbles at 85%/90% break chat convention. Gate messages, error messages and counselor speech are visually identical. |
| 5 | Error Prevention | 0 | Two unlabeled destructive controls, no confirm, no undo, the session-wiping eraser sits adjacent to the input in tab order. A gated session presents a live composer, and for a returning participant the gate is bypassable from the UI. |
| 6 | Recognition Rather Than Recall | 2 | Three icon-only controls, zero labels, zero tooltips. Participant must recall what the eraser and the red X do. |
| 7 | Flexibility and Efficiency | 1 | Enter sends (good). No Shift+Enter newline, no edit, no retry, no scroll-to-latest, no keyboard path to anything but the three controls. |
| 8 | Aesthetic and Minimalist Design | 2 | Clean mid-conversation on mobile. Undermined by a full-width `hr` after every turn, ~1200px of dead void in the empty state, and a 158-char measure on desktop. |
| 9 | Error Recovery | 1 | `callAI` failure path only `console.log`s — the participant sees their message posted and no reply, forever. Session-finalization failure appends a message in MICA's voice. |
| 10 | Help and Documentation | 0 | No help, no "what is this", no "this is an AI", no privacy statement, no crisis resource, no study contact. Anywhere. |
| **Total** | | **12/40** | **Poor — major UX overhaul required** |

## Design Specificity Verdict

**LLM assessment: category-interchangeable.** Remove the avatar PNG and the `MICA AI Chatbot` string and this is an off-the-shelf 2019 support widget: `#2C2C2C` canvas, `#2846E4` right bubbles, `#E9EEF3` left bubbles, white composer, grey paper-plane. Nothing in the composition, motion, or copy knows it is a motivational-interviewing counselor talking to an adolescent in an emergency department about substance use. No Stanford identity, no study identity, no care register, no calm. The one authored decision — the cartoon avatar — pulls toward consumer-assistant cute, the wrong register for what these transcripts carry. `src/assets/images/` still ships `chatGPT_logo.png`, `cappy.png`, `redcap_logo.png`, `stanford_home.webp`, `top_level_drill_down.webp`: sediment from the Cappy pilot.

**Deterministic scan.** CLI scan of `mica-chatbot/src` (and of `postsession.jsx` alone): **0 findings, exit 0.** The detector's URL mode is unavailable (`puppeteer is required for URL scanning`), so the in-page detector was injected directly via Playwright from `live-server.mjs` (`/detect.js`, port 8400, now stopped). Mutable-injection preflight passed in both viewports.

- **Desktop 1440×900: 7 findings, all `line-length` — "~158 chars/line (aim for <80)"** on seven elements.
- **Mobile 390×844: 0 findings.**

That is a precise, independent confirmation of the desktop-measure problem, with a harder number than I had. It also says something sharper: the layout is not responsive, it is *mobile-only by accident* — it passes at 390px because nothing constrains width, and fails at 1440px for the same reason.

**Detector blind spots worth naming.** Everything with real consequence here is invisible to it: zero `aria-live`, zero landmarks, unnamed destructive buttons, sub-44px targets, the gated-session state, the never-published config copy, and the missing crisis affordance. A clean CLI scan on this codebase means "no string-level anti-patterns," not "no problems."

**Visual overlays.** In-page overlay nodes were confirmed present at desktop width; at mobile width the detector reported no anti-patterns so no overlay nodes were created. Both runs were headless — **no user-visible overlay was presented in a browser**, and the live server has been stopped. Findings above are read from the injected detector's console output.

## Overall Impression

The middle of the conversation is genuinely decent. Markdown renders, MI reflections read well in the light bubble, and on a phone mid-session it looks like a competent chat app. Everything *around* that — the first 10 seconds, the wait after each send, every non-happy path, and the exit — is either missing or actively contradicts itself.

The single biggest opportunity: **this interface does not know what it is for.** It is a generic chat widget hosting a clinical intervention. It never says it is an AI, never says who reads the transcript, never says how long this takes, never offers a way to get real help, and gives a 16-year-old a one-tap unlabeled red X that deletes the very turn SafetyScan is supposed to read. The handoff docs record that live safety routing was deliberately removed in favor of post-session SafetyScan with RA review — that is a defensible architecture decision, but it makes the *interface* the only thing standing between a participant in distress and nothing, and right now the interface offers `End Session`.

## What's Working

1. **The counselor's voice has room to breathe.** `#E9EEF3` bubble, `#000` text, 90% width, `ReactMarkdown` with paragraph breaks — MI reflections with a blank line before the evocative question land exactly as written. Contrast is 6.8:1 on the participant bubble and near-maximal on MICA's. The reading experience of the *reply itself* is the strongest thing in the build.
2. **The failure copy that exists is genuinely good.** "Your session could not be finalized. Please contact the study team." and "Sorry — this chat session could not be started. Please reload the page, and contact the study team if this keeps happening." are plain-language, actionable, and non-blaming. Someone cared. The problem is placement, not wording.
3. **Restraint in the control set.** Three controls total. Compared to the commented-out thumbs/token-usage/popover apparatus still sitting in `messages.jsx:72-86`, choosing not to show a research participant their token cost was the right call.

## Priority Issues

### [P0] The session gate is expressed only as a chat message, so it does not gate
**What:** With `mica_bootstrap.error` set (the "Session already completed" / "Return in N day(s)" gates), the app renders the greeting *"Hi there. I'm MICA. What is your name?"*, then the gate message **as a MICA chat bubble with the avatar and the `MICA AI` label**, then *"Please click 'End Session' to ensure compensation for your participation."* — and leaves the composer fully live. Reproduced E2E in two branches:

- **Fresh participant** (`mobile-gate-typed.png`): types `Maya` → their message posts as a blue bubble and MICA "replies" with **"Return in 12 day(s) for your next session!"** — the same sentence, twice, answering a name with a scheduling notice. (`Chat.jsx:157-165` falls back to `window.mica_bootstrap.error`, which is still set.) Pressing `End Session` then finds no cached identity and `handleSignOut()` bounces them to `login_url`.
- **Returning participant** (`mobile-gate-returning.png`) — the one that matters: `user_info.current_user` persists in IndexedDB from any earlier visit, so `callAjax`'s readiness check **passes**, a real turn is sent, and **MICA answers normally.** The gate is bypassed entirely. `End Session` then ran `completeSession` and routed to the post-session survey link.

**Why it matters:** The only thing enforcing "you already completed this session" is a string in a chat bubble and a client-side IndexedDB row. `redcap_module_ajax`'s `case "callAI"` (`MICA.php:633-667`) never calls `getSystemContextForRecord()`, so the gate that produced the message is not re-evaluated on the turn path; `completeSession` (`MICA.php:1215+`) does not re-check it either, and finalizes with `overwriteBehavior: 'overwrite'` on `raw_chat_logs` / `session_info_complete`. For the fresh case, a participant is told they'll be compensated, follows the instruction, and is silently returned to a login page. *(Boundary: my harness stubs the transport, so I read those two handlers rather than exercising them against a live project. Whether a live REDCap response differs is the first thing to check — but nothing in the client stops the request.)*

**Fix:** Make the gate a first-class terminal state rather than a chat message. Suppress the intro bubble and the compensation reminder, remove or disable the composer, and give it its own visual treatment — a system notice, never the counselor's voice, avatar, or bubble — carrying the real next step ("Your next session opens on <date>" / "You've completed this session, thank you") and a study contact. The generalizable rule: **system state and error state must never be able to wear MICA's face.** Then re-derive the gate server-side on the turn and finalize paths so the UI is not the enforcement point.
**Suggested command:** `/impeccable harden`

### [P0] No crisis affordance, in an intervention that removed live safety routing
**What:** The only control in the header is `End Session`. There is no help path, no "if you need help right now," no crisis line, no way to reach a human, and no statement that a human reviews this later.
**Why it matters:** Per `docs/phase-3-handoff/README.md`, live safety detection was intentionally removed from the app layer; SafetyScan runs *post-session* with RA validation. The population is adolescents recruited in an emergency department, disclosing in real time. Between disclosure and RA review there is a window in which the interface is the entire safety surface, and it currently contains one button that ends the session. This is a design gap created by a correct architecture decision, and design has to close it.
**Fix:** A persistent, always-reachable help affordance that is not styled as chat: a header control that opens crisis resources (988, local ED contact, study contact) with no session cost — it must not end the session, lose the draft, or navigate away. Add a one-line standing footnote near the composer, not buried in an intro bubble. Decide deliberately whether MICA's own replies should carry a resource line on distress topics now that the model can no longer escalate.
**Suggested command:** `/impeccable harden`

### [P0] Two unlabeled, unconfirmed destructive controls — one on every message
**What:** A **16×16px** red `XCircleFill` sits on the top-right corner of every participant bubble and deletes that turn. A **27×29px** `#ccc` eraser at **1.6:1 contrast** on the white composer bar wipes the entire session. Neither has a label, `aria-label`, `title`, confirmation, or undo. The eraser is a tab stop adjacent to the input; the red X measures 1.7:1 against the blue bubble and overlaps the bubble's rounded corner and its text on mobile. Verified: `probe-mobile.json`, `mobile-session.png`.
**Why it matters:** The transcript *is* the research data and the SafetyScan input. A participant can delete the exact turn containing a risk disclosure, one mis-tap, silently, with no server round-trip and no record that anything was removed. Beyond data integrity: putting a red X on a 16-year-old's own words in a counseling conversation is a hostile gesture — it reads as "retract that." And at 16px on a phone it will be hit by accident while scrolling.
**Fix:** Delete the per-message delete. If retraction is a real requirement, it needs an explicit product decision, a confirm, an undo window, and a server-side audit trail — not a corner icon. Give the clear-session control a real name, a ≥44×44px target, ≥3:1 contrast, a confirm dialog, and move it out of adjacency with the send button.
**Suggested command:** `/impeccable harden`

### [P1] Study-configured copy never reaches the screen
**What:** `messages.jsx:11` and `:15` read `window.mica_jsmo_module.end_session_text` and `.intro_text`. **Nothing in the codebase ever assigns those keys.** `MICA.php:34,38` define the getters for `chatbot_intro_text` / `chatbot_end_session_text`, and both are `required: true` in `config.json` — but they are never published onto the JSMO object. Every participant sees the hardcoded fallbacks: *"Hi there. I'm MICA. What is your name?"* and *"Please click 'End Session' to ensure compensation for your participation."*
**Why it matters:** The opening line of a clinical intervention and the compensation statement are IRB-reviewable copy. The study team configures them, sees them saved, and they silently do not ship. This is also the mechanism that would let the R01 arm differ from the pilot, and it is dead. Related: `messages.jsx:95` references a bare `intro_text` identifier that does not exist in scope — a `ReferenceError` waiting on an unreachable branch.
**Fix:** Publish both settings onto the JSMO in `MICA.php` alongside `initial_system_context`, and make the fallback visibly a fallback in dev rather than a plausible-looking default. Then treat the intro string as a real onboarding surface, not one bubble (see below).
**Suggested command:** `/impeccable clarify`

### [P1] Zero accessibility infrastructure, and the first run is a void
**What:** Measured, not inferred: `aria-live` regions **0** — a screen-reader user gets no announcement when MICA replies. Landmarks **0**. Headings: one `h1`. The composer input has **no label and no `aria-label`** (`label: 0, aria: null`) and `.footer .user_input:focus { outline: none }` removes the focus ring with no replacement. All three icon buttons have **no accessible name** — they announce as "button," and two of them are destructive. Every touch target is under 44px: 16×16, 27×29, 32×29, and `End Session` at 100×**27**. At 200% zoom the layout collapses: header text wraps into the logo, the `Visitor` and `MICA AI` labels overlap the bubbles, the footer covers the last message, the send icon clips off-screen (`mobile-zoom200.png`). Separately, the first-run state is one bubble asking for a name above ~1200px of black void (`mobile-empty.png`).
**Why it matters:** WCAG 2.4.7, 1.4.11, 2.5.8 and 1.4.4 all fail on a federally funded study instrument. A blind or low-vision participant cannot use the core loop at all. And the void is the peak-end opening beat: no orientation, no "this is an AI," no duration, no privacy statement, no crisis line — just a demand for a name.
**Fix:** `role="log"` + `aria-live="polite"` on the message list with a "MICA is typing" status; accessible names on all three buttons; a visible `:focus-visible` ring; ≥44px targets; a `max-width` measure and `rem`-based type so 200% zoom reflows instead of collapsing. Replace the empty state with a real first-run panel: what MICA is, that it's an AI, roughly how long, who reads the transcript, where to get help now — then the greeting.
**Suggested command:** `/impeccable audit`, then `/impeccable onboard`

## Persona Red Flags

**Casey (Distracted Mobile User)** — the primary persona; this is a phone handed over in an ED.
- `End Session` — the single most consequential action, tied to compensation — is a bare text button in the **top-right corner**, the furthest point from a thumb, at 27px tall, with no confirm.
- Every touch target fails 44×44: send 32×29, eraser 27×29, per-message delete **16×16**. The delete sits on the bubble's corner where a scroll gesture lands.
- Interrupted mid-turn: the draft lives only in Footer's local `input` state. Backgrounding Safari and returning to a reloaded page loses whatever they typed. The transcript survives (Dexie + server), the draft does not.
- After sending, the only sign anything is happening is a 20px `#ccc` arrow at 1.6:1 in the corner. On a slow ED connection Casey will assume it failed and send again — nothing disables the input.
- ~124px of dead band sits between the last message and the composer (`padding-bottom: 100px` over a 65px composer), and the compensation nag occupies it. During the wait, the last thing on screen is a reminder about payment.

**Jordan (Confused First-Timer)** — everyone is a first-timer; there is exactly one session before the Month-3 booster.
- Opens to one bubble and a void. Nothing states this is an AI, how long it takes, who reads it, or what happens after.
- Three icon-only controls, no labels, no tooltips. The red X and the eraser are unreadable by intent — Jordan's literal reading of a red X on their own message is "delete my answer," which is correct and alarming.
- `Visitor` was designed as their name tag — the wrong register for a therapeutic alliance. It also never renders at 100%, and only surfaces at 200% zoom where it overlaps the bubble (likely `.messages dl { overflow: hidden }` clipping the `top:-20px` pseudo-element, which stops clipping once the label scales). So the label is simultaneously wrong *and* invisible.
- "End Session" reads as "quit." Jordan will avoid it, which is the opposite of what the reminder is trying to achieve, and the reminder never explains that it is how you get paid.
- If `callAI` fails, the error path only `console.log`s. Jordan sees their message sitting there and no reply, indefinitely, with no error and no retry.

**Sam (Accessibility-Dependent User)** — cannot complete the core loop.
- Sends a message; **nothing is announced**. `aria-live` count is 0. Sam has no way to know a reply arrived short of manually re-navigating the list.
- Zero landmarks, one heading. No way to jump between the transcript and the composer.
- The composer input has no programmatic label. Screen reader announces an unlabeled text field.
- All three buttons announce as "button." Two of them destroy data.
- Focus ring removed on the primary input; the scroll container is itself a tab stop with no name.
- At 200% zoom the interface overlaps itself and the send control leaves the viewport.

## Minor Observations

- **Dead mount contract.** `renderMicaApp` appears **0 times** in the shipped bundle, but `MICA.php:430-447` polls for it every 100ms up to 100 times. The app mounts anyway (`main.jsx` auto-mounts), so nobody noticed — but `unmask()` never runs, and `blockSubmit()` is re-registered on every tick, stacking ~100 submit and ~100 document-level keydown listeners over 10 seconds.
- **`App.jsx` is orphaned.** `main.jsx:3` imports it and never renders it; `AppRouter` is rendered directly. `App.css` still ships through that dead import (which is the only reason `#chatbot_ui_container` gets its `position: fixed`). Verified exactly one header and one footer render — no duplication — but the file that looks like the app shell isn't one.
- **`PostSession` is unreachable.** `endSession` uses `window.location.href`, never routes to `/postsession`. The view also re-declares the `chatbot_ui_container` id and styles itself with inline `#ffffff`/`#007BFF` in a visual language that exists nowhere else. There is no designed end state.
- **Lost affordances still imported.** `header.jsx:3` imports `Archive`, `ChatDots`, `BoxArrowRight` — history, new chat, sign out. Designed, removed from the UI, left in the code.
- **A full-width `hr.divider` after every turn** chops the conversation into slabs and fights the bubble grouping that already separates speakers. Delete it.
- **No type scale.** Three sizes, all percentage-derived from a 16px base: 19.52px `h1`, 16px body, 12.32px labels, 12px italic centered reminder. No `line-height` and no `max-width` declared anywhere.
- **Conflicting `overflow` on `.messages`** between `App.css` (`scroll`) and `messages.css` (`hidden`); resolution depends on import order. `padding-bottom: 100px` over a 65px composer.
- **`.messages .empty { color: cyan }`** — leftover on an unreachable branch that would `ReferenceError` anyway.
- **`Visitor` never renders at 100% zoom** — the `dt::before` label is clipped and only appears at 200%, overlapping the bubble. A designed label nobody has ever seen.
- **`dist/index.html` still titled "Vite + React."**
- **Vote/rating apparatus is commented out** in `messages.jsx:72-86` while `updateVote`, `showRatingPO`, the `Overlay`/`Popover` and `.votes`/`.vote`/`.token_usage` CSS all remain live.
- **`user-scalable=no`** in the viewport meta — an accessibility anti-pattern, and moot since the layout breaks at 200% anyway.

## Questions to Consider

- **What is the safety surface between a disclosure and the RA who reads it tomorrow?** Right now it is one button labeled "End Session." If the answer is "the interface," it needs to be designed as such.
- **Why can a participant delete their own words at all?** If nobody can name the requirement, the red X is a 16px liability with no owner.
- **What would this look like if it were unmistakably a Stanford clinical instrument rather than a chat widget?** Not more chrome — a different register: type, pace, restraint, one authored accent instead of `#2846E4`.
- **Should MICA say it is an AI?** It currently opens with "Hi there. I'm MICA. What is your name?" and never clarifies. For adolescents and for consent, that ambiguity is a choice being made by omission.
- **What is the last thing a participant should see?** Today it is a redirect. The session has a beginning that is a void and an end that is a page change — the two moments the peak-end rule says matter most are the two you haven't designed.
- **Is `End Session` the right frame at all?** It's study-ops language for what the participant experiences as "I'm done talking, and this is how I get paid." Those are different sentences.
