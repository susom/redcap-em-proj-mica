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
| [`14-live-defects.md`](14-live-defects.md) | **Defects broken today**, independent of phase 3 — unregistered `llm-model` silently answering every turn with a provider apology (blocking), no ownership binding on the no-auth ajax surface (cross-participant PHI), restore-loses-model-context, permanent send-button hang on the completed-session gate, plus 15 correctness items. Sequenced, with the reproduce-E2E-first obligation stated |
| [`scripts/`](scripts/README.md) | Idempotent apply script, independent verifier, and the manual-test participant helper |

## Non-goals of this phase

- Satisfying the human/protocol launch gates (clinical review, IRB approval of
  no-live-alert design, RA acknowledgment target) — those are study-leadership
  actions; the software must *enforce* the blockers, not resolve them.
- Qualifying the actual Azure Luna / Vertex Gemini 3.5 deployments (Stanford IT).
- Re-running the frozen 67-context regression suite (research team owns it; the
  suite itself is not in the handoff package — see open questions).
