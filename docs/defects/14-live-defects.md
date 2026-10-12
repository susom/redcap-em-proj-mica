# 14 — Live defects found during the SecureChatAI audit (2026-08-18)

**Status:** FINDINGS — most verified by code reading. **Fixed and verified so far:
D1, D2, D3, D4, D6, D7, D8, D10, D11, D13, D16, D22, D23, D24, D25.** Still open: D5, D9, D12,
D14, D15, D17-D21
**Scope:** defects in the code as it stands on `mica-phase-3` @ `ab81cca`.
These are distinct from the SOW migration work in
`07-chatbot-cleanup-securechatai.md` (removed 2026-10-12):
they are broken now and do not need the phase-3 architecture to be fixed.

> **Process note (project standard):** every item below must be reproduced in a
> Playwright E2E run as an end user *before* it is fixed, and the failing test
> kept. Except where a defect is explicitly marked **Observed**, the evidence is
> static analysis with line-level citations — treat those symptoms as predicted,
> not observed. **D1, D6, D7, D9, D16 and D22 were reproduced through the real
> participant path with Playwright on 2026-08-19**, and each fix re-verified the
> same way. D7's gate message is the one partial: the server half is asserted
> against real output, the client half against a simulated payload, because PID
> 257 cannot raise that gate (see D7). The rest are still static findings.

Refs are `MICA.php` unless another file is named. SPA paths are relative to
`mica-chatbot/`. `SCA/` = `modules-local/secure_chat_ai_v9.9.9/`.

---

## Parts

- [14a — Live defects: chat path and security (D1–D5, D22)](14a-chat-and-security.md): D1 unregistered model, D22 participant cannot send, D2 no ownership binding, D3 raw `$_POST` in filterLogic, D4 any user could close any session, D5 PHI in debug logs.
- [14b — Live defects: SafetyScan (D23–D25)](14b-safetyscan.md): D23 the provider rejected the SafetyScan schema, D24 one project's scan pass took every project's jobs, D25 MICA's own escaping failed correct quotes.
- [14c — Live defects: functional (D6–D9, D11, D16)](14c-functional.md): D6 restore lost model context, D7 send button hangs on the completed gate, D8 only the last system context reached the model, D16 End Session fatal, D11 saveData failed open, D9 dead mount contract.

## Status at a glance

| # | Defect | Status | Where |
|---|---|---|---|
| D1 | `llm-model = gpt-4o` is unregistered: every chat turn fails silently | fixed | [14a](14a-chat-and-security.md#d1--llm-model--gpt-4o-is-unregistered-every-chat-turn-fails-silently) |
| D2 | No ownership binding on the no-auth AJAX surface | fixed | [14a](14a-chat-and-security.md#d2--no-ownership-binding-on-the-no-auth-ajax-surface) |
| D3 | Raw `$_POST` interpolated into `filterLogic` on a no-auth page | fixed | [14a](14a-chat-and-security.md#d3--raw-_post-interpolated-into-filterlogic-on-a-no-auth-page) |
| D4 | Any project user could close any participant's session | fixed | [14a](14a-chat-and-security.md#d4--any-project-user-could-close-any-participants-session) |
| D5 | PHI in module debug logs | **open** | [14a](14a-chat-and-security.md#d5--phi-in-module-debug-logs) |
| D6 | Restoring a session does not restore the model's context | fixed | [14c](14c-functional.md#d6--restoring-a-session-does-not-restore-the-models-context) |
| D7 | Send button hangs permanently on the "session already completed" gate | fixed | [14c](14c-functional.md#d7--send-button-hangs-permanently-on-the-session-already-completed-gate) |
| D8 | Only the *last* system-context element ever reaches the model | fixed | [14c](14c-functional.md#d8--only-the-last-system-context-element-ever-reaches-the-model) |
| D9 | `renderMicaApp` does not exist: dead mount contract | **open** | [14c](14c-functional.md#d9--rendermicaapp-does-not-exist-dead-mount-contract) |
| D10 | `$event_id` undefined when `emDebug`'d for sessions 2-6 | fixed | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D11 | `saveData` results: the OTP path failed *open* | fixed | [14c](14c-functional.md#d11--savedata-results-the-otp-path-failed-open) |
| D12 | `summarizeCatchUp` builds `session_0_arm_1` when `$i === 0`; `getEventIdFromUniqueEvent` returns null and `'events' => [null]` reaches `getData` | **open** | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D13 | `fetchSavedQueries` compares `$check['record_id']` instead of `$primary_field` | fixed | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D14 | `handleUserInput` reads `$data['user_id']` unconditionally though only `role`/`content` are validated ⇒ undefined-key warning and `user_id: null` on every assistant turn | **open** | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D15 | `$messages[sizeof($messages)-1]` becomes `$messages[-1]` when `handleUserInput` returns `[]` (non-array payload) | **open** | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D16 | End Session fatals and silently discards the session | fixed | [14c](14c-functional.md#d16--end-session-fatals-and-silently-discards-the-session) |
| D17 | `Sanitizer` HTML-escapes prompt text **before** it reaches the model and before storage, so apostrophes persist as `&#039;` in transcripts | **open** | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D18 | `get_magic_quotes_gpc` branch is dead under PHP 8 | **open** | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D19 | `pages/chatbot.php:68` hardcodes `ui_hosting_instrument` in `getSurveyLink()`, so the new `chat_host_instruments` setting does not reach the OTP path | **open** | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D20 | `messages.jsx:95` references an undefined `intro_text` identifier (unreachable branch) | **open** | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D21 | `PROJECT_ID` vs `$this->getProjectId()` used inconsistently | **open** | [C](#c-correctness-defects-low-severity-fix-while-in-the-file) |
| D22 | On PID 257 the participant cannot send a message at all, silently | fixed | [14a](14a-chat-and-security.md#d22--on-pid-257-the-participant-cannot-send-a-message-at-all-silently) |
| D23 | The provider rejects the SafetyScan schema: **no post-session scan can ever succeed** on `gpt-5-6-sol` | fixed | [14b](14b-safetyscan.md#d23--the-provider-rejects-the-safetyscan-schema-no-post-session-scan-can-ever-succeed-on-gpt-5-6-sol) |
| D24 | Any project's scan pass took every project's jobs, and scanned and announced them as its own | fixed | [14b](14b-safetyscan.md#d24--any-projects-scan-pass-took-every-projects-jobs-and-scanned-and-announced-them-as-its-own) |
| D25 | MICA's own input escaping made a correct quote fail, and threw the whole scan away | fixed | [14b](14b-safetyscan.md#d25--micas-own-input-escaping-made-a-correct-quote-fail-and-threw-the-whole-scan-away) |


## Full-path E2E run (2026-08-19)

Playwright suite over the real participant path on PID 257 — Survey Login gate
plus chatbot — desktop (1400×950) and iPhone 13. **23 checks, all passing.**

| Group | Result |
|---|---|
| **Survey Login** | Gate present on the ED-session link; chat unreachable before login; **wrong credential rejected**; the control survey (`baseline1`) correctly does **not** prompt — scoping holds |
| **Chatbot load** | Header, intro message, End Session control, composer all render; exactly **one** JS + one CSS bundle loaded (no stale-asset double-load); zero failed requests |
| **Conversation** | Two turns; message echoed; real model replies; **context retained across turns** ("You asked me what 2+2 equals."); all four `proj_mica` AJAX calls HTTP 200; zero MICA JS errors |
| **Mobile** | Renders; **no horizontal overflow** (390/390); composer inside the viewport; turn works |

Two attribution notes, so nobody chases the wrong thing:

- **The `offsetHeight` TypeError on survey pages is REDCap core, not MICA.** It
  fires on the control survey too — a page MICA's hooks never touch — and the
  stack lands in `redcap_v17.2.3/Resources/webpack/js/bundle.js`. Excluded from
  the module's error budget.
- **An earlier "auth scoping broken" reading was a false positive in the test,
  not a real finding.** `baseline1` is the Enrollment instrument and contains
  `last_name` as a genuine data-entry field, so keying on
  `input[name=last_name]` matches it. Discriminate on
  `input[name=survey-auth-submit]` + a "Log In" button instead. The repo's own
  `verify-auth-config.php` passes all checks, including "non-MICA surveys
  gated: none".

Three gaps observed in the same run, all downstream of the PID 257 structure
mismatch rather than of the chat code. Two have since been fixed: reload now
restores both the transcript and the model's context (D6), and End Session fails
honestly instead of fataling (D16 — though finalization still cannot *succeed* on
PID 257 until the session engine is reworked). The remaining one is D22's known
gap: no counselor persona, because `initial_system_context` is still `[]`.

## C. Correctness defects (low severity, fix while in the file)

| # | Defect | Ref |
|---|---|---|
| D10 | ~~`$event_id` undefined when `emDebug`'d for sessions 2-6~~ **FIXED 2026-08-19** with D16 | `:934` |
| D11 | ~~`REDCap::saveData()` return subscripted without a type check~~ **FIXED 2026-08-19 — all call sites.** See "D11 — saveData results" in section B | `:641`, `:960` |
| D12 | `summarizeCatchUp` builds `session_0_arm_1` when `$i === 0`; `getEventIdFromUniqueEvent` returns null and `'events' => [null]` reaches `getData` | `:768-769` |
| D13 | ~~`fetchSavedQueries` compares `$check['record_id']` instead of `$primary_field`~~ **FIXED 2026-08-19** with D6 | `:533` |
| D14 | `handleUserInput` reads `$data['user_id']` unconditionally though only `role`/`content` are validated ⇒ undefined-key warning and `user_id: null` on every assistant turn | `:109` |
| D15 | `$messages[sizeof($messages)-1]` becomes `$messages[-1]` when `handleUserInput` returns `[]` (non-array payload) | `:389` |
| D16 | **Promoted to section B** — reproduced as a fatal that loses the session. See "D16 — End Session fatals" above | `:887-889` |
| D17 | `Sanitizer` HTML-escapes prompt text **before** it reaches the model and before storage, so apostrophes persist as `&#039;` in transcripts | `classes/Sanitizer.php:16` |
| D18 | `get_magic_quotes_gpc` branch is dead under PHP 8 | `classes/Sanitizer.php:13` |
| D19 | `pages/chatbot.php:68` hardcodes `ui_hosting_instrument` in `getSurveyLink()`, so the new `chat_host_instruments` setting does not reach the OTP path | `pages/chatbot.php:68` |
| D20 | `messages.jsx:95` references an undefined `intro_text` identifier (unreachable branch) | `src/components/messages/messages.jsx:95` |
| D21 | `PROJECT_ID` vs `$this->getProjectId()` used inconsistently | `:407`, `:534`, `:894`, `:943` vs `:695`, `:718`, `:772`, `:832` |

---

## Suggested sequencing

1. ~~**D1 alone, first**~~ — **done in dev 2026-08-19** (config choices + PID 257
   value + an alias guard). Production value still to be confirmed.
2. ~~**D22 next**~~ — **done 2026-08-19**, and it carried D8 plus D7's crash with
   it. A participant can now send and receive a real reply on PID 257, desktop
   and mobile.
3. **Record the Playwright baseline** (login → chat turn → end session, desktop +
   mobile). Now unblocked. Note the baseline will show a reply with **no
   counselor persona** until the R01 session context exists (D22's "known
   remaining gap"), so record it as a send-path baseline, not a prompt baseline.
4. **D2 + D3 done 2026-08-19**; **D4 still open** — `sessionSelector.php` runs
   `completeSession` before `validatePermissions()`, with no CSRF token. It is a
   page POST rather than part of the AJAX surface, so it was not covered by the
   D2 fix.
5. ~~**D6, D7, D8**~~ — **done 2026-08-19**, all three E2E-verified (D8's
   multi-entry case still needs a project that produces more than one context
   entry).
6. **D5** with the Stage 0 §0.6 logging cleanup.
7. **D9-D21** folded into Stage 0 §0.6 as behavior-preserving cleanup.
