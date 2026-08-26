# Phase 3 — Incorporating the MICA R01 Stanford IT Handoff

**Branch:** `mica-phase-3` · **Status:** PLAN (pending review — no implementation yet)
**Handoff source:** `~/Downloads/MICA_Stanford_IT_Handoff_Package` (manifest dated 2026-07-25)
**Plan written:** 2026-08-05

## What the handoff delivers

The research team's handoff finalizes an architecture decision: **MICA becomes a
single-model-call-per-turn counselor with *no* live safety routing, and all
safety detection moves to a post-session service (SafetyScan) with a human
(RA) validation workflow.** Two workstreams:

| | Workstream A — Counselor v2 | Workstream B — SafetyScan (new subsystem) |
|---|---|---|
| Prompt | Frozen file `MICA_Prompt_R01_v2_postsession_safety.txt`, SHA-256 pinned | Frozen file `MICA_SafetyScan_PostSession_prompt.txt` v1.1, SHA-256 pinned |
| Model | GPT-5.6 Luna (Azure, pinned deployment) · evaluated fallback GPT-5.4 | Gemini 3.5 Flash |
| Contract | Request: `wrapper_schema_v2` · Response: `counselor_output_schema_v2` (`assistant_text`, `next_phase`, `response_strategy`, `end_session`; **rejects** `safety_flag`/`escalation`) | Input/output schemas + review-workflow + notification-policy schemas |
| App gates | Schema validity · ≤1 question · 90/140-word limits · legal MI phase transitions · no silent release of bad output | Exact-quote verification · idempotent queue · every failure ⇒ `manual_review_required` (never a negative screen) |
| Validation status | v2 prompt passed frozen 67-context regression on GPT-5.4; Luna passed all gates | v1.1 passed 120-case suite (100% critical detection, 100% quote traceability) |

## Decisions made (2026-08-05, with Ihab)

1. **Scope:** both workstreams, staged — counselor v2 first (it produces the finalized transcript SafetyScan consumes), then SafetyScan + RA dashboard.
2. **Placement:** everything inside the existing `proj_mica` EM. *(Storage revised 2026-08-11, with Ihab:)* **findings + human review + actions live in REDCap fields** (repeating `mica_safety_finding` instrument); **transcripts + notifications in the EM log store**; **queue / scan-run / turn / audit rows in REDCap Entity tables** (`redcap_entity` EM dependency) — no module-owned DDL. See `02-data-model.md §1`.
3. **Session model:** rework the session engine from the pilot structure (baseline + sessions 2–7 biweekly) to the **R01 model** — ED baseline (Day 1) + Month-3 booster — targeting the rebuilt PID 257 structure (see `docs/project-structure/`).
4. **Models for dev:** build against **`gpt-5-4`** (the evaluated fallback) and **`gemini-2.5-flash`** as SafetyScan stand-in; all aliases config-driven so Luna / Gemini 3.5 Flash become registry entries when Stanford IT provisions them. SecureChatAI gets small, backward-compatible extensions. *(Revised 2026-08-18: `gpt-5-4` is json_schema-capable — verified `SecureChatAI.php:399` — but it is **not** registered in the dev instance, whose registry holds only `claude-haiku-4-5`, and it is **not** in the provider's `reasoning_effort` allowlist. See [`13-securechatai-current-state-delta.md`](13-securechatai-current-state-delta.md) §5, §8.)*
5. **RA dashboard:** second React SPA (`mica-review/`), mounted from an authenticated EM page.
6. **Process:** plan docs first (this folder) → review → staged implementation.

## Current system vs target

| Area | Today | Target (this phase) |
|---|---|---|
| Turn contract | Free-text chat; prompts in project-settings textareas | Hash-pinned prompt file + JSON-schema'd request/response + app-side gates + retry/fallback policy |
| Session engine | Hardcoded pilot events (`baseline_arm_1`, `session_2..7_arm_1`, `month3_fu`) | R01: `session_type baseline\|booster`, `location ed\|remote_followup`, MI phase state, `minutes_remaining` |
| Turn logging | Message JSON blobs in EM log | + `mica_turn` Entity table: resolved model, prompt/schema/app hashes, params, latency, tokens, status, phase transitions — no transcript duplication |
| Session close | `raw_chat_logs` blob saved to a REDCap field | + Immutable canonical transcript snapshot, SHA-256, stable message IDs, idempotent `session_closed` → scan job |
| Safety | None in app layer (prompts may contain live-triage text — v2 prompt removes it) | Post-session SafetyScan pipeline + RA review dashboard + policy-driven notifications + launch-readiness validator |
| Models | `llm-model` dropdown (gpt-4o default) | Config-driven counselor alias + ordered fallback; scan alias; per-turn resolved-model recording |

## Plan documents

| Doc | Contents |
|---|---|
| [`01-architecture.md`](01-architecture.md) | Turn pipeline, session engine, transcript finalization, scan pipeline, dashboard, notifications, SecureChatAI changes |
| [`02-data-model.md`](02-data-model.md) | Storage strategy (REDCap fields / EM log / Entity tables), REDCap field additions for PID 257, message-ID/hash scheme, config settings |
| [`03-implementation-stages.md`](03-implementation-stages.md) | Stages 0–6 with tasks and acceptance criteria |
| [`04-test-plan.md`](04-test-plan.md) | Unit / integration / Playwright E2E strategy, fixtures, regression obligations |
| [`05-open-questions-and-risks.md`](05-open-questions-and-risks.md) | Study-team decisions needed, launch blockers, technical risks |
| [`06-implementation-plan/`](06-implementation-plan/README.md) | Detailed work breakdown per stage: tasks, file paths, code contracts, tests, acceptance checklists |
| [`07-chatbot-cleanup-securechatai.md`](07-chatbot-cleanup-securechatai.md) | SOW "Chatbot Cleanup & SecureChatAI Integration": MICA-vs-Cappy comparison, cleanup inventory, conformance tasks folded into Stages 0/1. **Revised 2026-08-18** against verified provider code |
| [`08-auth-discovery.md`](08-auth-discovery.md) | SOW Discovery "Identify a 2FA Replacement": current-state findings on the OTP flow, native REDCap 17.2.3 capabilities, options matrix, recommendation, and the PI-facing decision summary. Answers open question #7 |
| [`09-pid-257-structure-audit.md`](09-pid-257-structure-audit.md) | As-built audit of the researcher-provided PID 257 structure (262 fields / 27 instruments): what matches the plan, the gaps that block phase-3 work, and 48 unresolved field/event references. Basis for the 2026-08-17 revision of `08` |
| [`10-auth-implementation-pid257.md`](10-auth-implementation-pid257.md) | **Implementation record** — Option B's REDCap layer applied to PID 257 (two host surveys + scoped Survey Login), the verification run, the blank-credential security finding that changed the design, rollback SQL, and the module-side work still outstanding |
| [`11-auth-manual-test-guide.md`](11-auth-manual-test-guide.md) | How to hand-test the auth gate: test-participant setup, an 11-case matrix with expected results, case/whitespace behaviour, mobile checks, audit-trail verification, teardown |
| [`12-auth-engineering-review.md`](12-auth-engineering-review.md) | **Peer-review request** — self-contained engineering summary of the auth mechanism, the four counterintuitive decisions that need checking, what is deliberately not done yet, and a labelled verification-status table. Circulate to engineers before the PI memo |
| [`13-securechatai-current-state-delta.md`](13-securechatai-current-state-delta.md) | **Verified provider contract (2026-08-18)** — `callAI()` signature, which `$params` are honored/filtered/ignored, the four response envelopes, the agent loop's three gates, the tool framework, the tool-use-only hook system; the reasoned decision **not** to put the counselor turn in agent mode; a table of now-stale claims in these plan docs; revised SecureChatAI PR #1 scope; the MICA-as-is compatibility verdict |
| [`14-live-defects.md`](14-live-defects.md) | **Defects broken today**, independent of phase 3 — unregistered `llm-model` silently answering every turn with a provider apology (blocking), no ownership binding on the no-auth ajax surface (cross-participant PHI), restore-loses-model-context, permanent send-button hang on the completed-session gate, plus 15 correctness items. Sequenced, with the reproduce-E2E-first obligation stated. **D23 (2026-08-25, open, blocking): the provider rejects the pinned SafetyScan output schema over `uniqueItems`, so every post-session safety scan fails — nine rejected requests per session, then `manual_review_required`** |
| [`15-arm-materialization.md`](15-arm-materialization.md) | **Automatic arm placement** — a record is added to the arm its `study_group` value names, so a CRC never creates it inside the arm by hand. Covers why only the *assigned* arm is touched (an SC participant must not gain a chatbot link), the new `study_group` field and its scripts, the hook's coverage limit (`redcap_save_record` fires only on UI saves), and the multi-arm delete caveat |
| [`18-sow-status-review.md`](18-sow-status-review.md) | **SOW status review (2026-08-21)** — every approved SOW clause graded Done / Partial / Not done against measured evidence (code `file:line`, live SQL on PID 257, executed test suite), deliberately *not* against this folder's larger plan. Names the two clauses with positive evidence of absence (Twilio/notifications/alerts; cross-arm scan assurance) and the dead-but-shipping OTP surface. **§10 records the study's answers of 2026-08-21** — PID 257 confirmed as the SOW project, "stored within REDCap" defined as findings-in-an-instrument (so V‑3's storage clause is met), Twilio requirements owned by the PI, and **counselor-v2 + the R01 session engine dropped from scope — which leaves three enforcement gates (session window, withdrawal/opt-out, repeat sessions) unowned** |
| [`19-admin-form-logic-errors.md`](19-admin-form-logic-errors.md) | **The `admin` form's "syntactical errors" banner on PID 257 (2026-08-25)** — why 16 fields are flagged: five tokens (`[baseline_arm_1]`, `[consent_date]`, `[group]`, `[dummy_email]`, `[time_diff]`) that came from PID 192 / ASPIRE when the form was copied and were never remapped. Splits them into *mechanical* (4 stale event prefixes — **fixed and browser-verified**, including the load-bearing `calc_month_3` Month-3 window), *already-dead* (5 fields self-labelled `DELETE:` / `NOT_USED:` / debug), and *needs-researcher-sign-off* (7). Explains why the repair is a bare reference rather than a substituted event name |
| [`20-llm-request-capture.md`](20-llm-request-capture.md) | **The JSON body that actually reaches the provider (2026-08-25)** — why it cannot be read off `MICA.php`, the four transforms between MICA's request and the wire, and the surprise on PID 257: `gpt-5-6-sol` is a *reasoning* alias, so SecureChatAI **replaces** the merged parameters with a strict four-key body and `temperature` / `top_p` / the penalties / `stop` never leave the box. Ships a default-off capture that writes the exact bytes plus a runnable, key-free curl — verified by replaying it against the live AI Hub deployment. Notes the discarded dynamic-token-cap assignment at `SecureChatAI.php:1173`. **§7 (2026-08-25) re-verifies it from the participant's seat and, in doing so, found D23: the pinned SafetyScan schema uses `uniqueItems`, which the provider rejects, so no post-session scan has ever completed on this instance.** §4b adds the low-ceremony view for local work — the whole payload, one JSON line per call, tailed from `/var/log/apache2/secure_chat_ai.json`, gated on REDCap's `is_development_server` so it cannot follow the code to production |
| [`21-chatbot-mount-collision.md`](21-chatbot-mount-collision.md) | **"This chat could not be opened because the session did not load" on a valid link (2026-08-25) — fixed.** `#chatbot_ui_container` is not unique: the REDCap Chatbot module is enabled system-wide and emits an identically-id'd empty div ~93KB earlier in the page, so `getElementById()` read the bootstrap off *its* div (`{}`) and mounted MICA's React root inside it. Fixed by selecting on `[data-bootstrap]` in both `MICA.php` and `main.jsx` rather than renaming a load-bearing id. Re-verified with a live turn on desktop and mobile. Also records two things it uncovered and left alone: `window.renderMicaApp` does not exist (so `unmask()` is dead code), and **no `chatbot_system_context_booster` setting exists on PID 257**, so every booster session runs on the general prompt alone |
| [`scripts/`](scripts/README.md) | Idempotent apply script, independent verifier, and the manual-test participant helper |

## Non-goals of this phase

- Satisfying the human/protocol launch gates (clinical review, IRB approval of
  no-live-alert design, RA acknowledgment target) — those are study-leadership
  actions; the software must *enforce* the blockers, not resolve them.
- Qualifying the actual Azure Luna / Vertex Gemini 3.5 deployments (Stanford IT).
- Re-running the frozen 67-context regression suite (research team owns it; the
  suite itself is not in the handoff package — see open questions).
