# Open questions & risks

## Decisions needed from study team / leadership (launch-gated items marked ⛔)

1. ⛔ **`critical_acknowledgment_minutes`** — intentionally null in the
   default policy; software blocks production until leadership sets it
   (with staffing coverage, recipients, after-hours procedure).
2. ⛔ **IRB/protocol approval of no live safety alert** — the v2 architecture
   removes all live triage; enrollment requires explicit approval.
3. ⛔ **Human clinical review package** — 30 representative outputs, judge
   disagreements, scanner v1.0 misses, retrospective findings (research team
   owns; software unaffected).
4. **Action templates** — protocol-approved wording for care-team / PI /
   protocol-lead alerts (minimum-necessary fields per recipient class).
5. **MI phase-transition matrix** — sign off the proposed default
   (`02-data-model.md §4`) or supply corrections.
6. **Approved-resources list** — content per setting (ED vs remote).
7. **ED baseline launch flow** — is Day-1 ED chat opened by staff on a study
   device (skip OTP?) or does the participant use their own device with the
   OTP email flow? Affects login UX, not architecture.
8. **PID 257 field design** — sign off the draft fields for
   `mica_ed_session` / `mica_booster_session` + auth fields + the repeating
   `mica_safety_finding` instrument (`02-data-model.md §3`), and confirm the
   `alcohol_summary` field mapping. Includes two flagged sub-decisions: one
   action bundle per finding (vs. a separate actions instrument) and the
   `scan_failure` placeholder-instance convention.
9. **Frozen regression suites** — request the 67-context counselor suite +
   judge harness and the 120-case SafetyScan suite from the research team;
   required by the lifecycle policy for any future model/prompt change
   (successor qualification before Luna's 2027-07-09 retirement).
10. **Booster `location`** — can boosters occur in the ED (walk-in) or is
    `remote_followup` fixed? Drives the field default.

## Stanford IT dependencies

11. **Azure GPT-5.6 Luna deployment** — provision + qualify (latency,
    capacity, content filters, structured output, retirement policy); until
    then dev/eval runs on the evaluated fallback `gpt-5-4` (already in
    SecureChatAI).
12. **Vertex `gemini-3.5-flash`** — provision + approve a safety-filter
    policy for SafetyScan. ⚠️ The scanner's *job* is reading self-harm /
    violence content; default Vertex filters can block exactly those scans.
    Recommend `BLOCK_NONE` for this pinned deployment (needs security/privacy
    sign-off); blocked scans fail visibly to `manual_review_required` either
    way.
13. **SecureChatAI PRs** — capability flags + Gemini structured
    output/safety-settings changes land in a shared module used by other
    projects; must be additive and reviewed by its owners (Irvin/Jordan).

## Technical risks & mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Gemini/Vertex content filter blocks scans of the riskiest transcripts | Missed-detection window | `content_filter` taxonomy ⇒ `manual_review_required` (never negative screen); explicit filter policy per #12; live smoke test includes a risk transcript |
| Luna behaves differently from gpt-5-4 dev baseline | Gate failures at cutover | Contract enforcement is model-agnostic (schema + gates + fallback); cutover requires the qualification run anyway (memo) |
| Frozen regression suite unavailable to engineering | Can't self-serve prompt/model changes | Hash-freeze artifacts; config-only changes until #9 resolves |
| REDCap Entity EM dependency (third-party CTS-IT module; low release cadence) | Table lifecycle breaks on a REDCap upgrade | Module pins a minimum Entity version, verifies it at system-enable (fail-loud), and touches entity tables only via documented APIs + plain SQL on our own tables; worst-case fallback is reinstating module-owned DDL for the same four tables |
| Findings live in REDCap fields (mutable by anyone with instrument edit rights) | Tampered/edited model findings | Instrument read-only via user rights; model fields `@READONLY`; every edit in REDCap's data audit log; authoritative verbatim output immutable on `mica_scan_run.model_output_json` — drift is detectable and reviewable |
| Transcript payload exceeds the 64 KB EM-log parameter cap | Truncated/corrupt canonical payload | Finalizer chunks at 60 KB (`payload_json_2..n`), hashes the re-joined string, and fails loudly (no silent truncation); chunk round-trip unit-tested |
| `additionalProperties:false` + provider strict-mode quirks (e.g., Azure structured-output nuances) | Valid turns rejected | Integration smoke on the exact deployment during qualification; retry/fallback absorbs transient shape errors |
| Draft 2020-12 schemas vs PHP validators | Silent mis-validation | `opis/json-schema` (2020-12 native); schema-fixture unit tests lock behavior |
| No-auth `callAI` surface abuse | Cost/PHI exposure | Session-token binding after OTP, rate limiting, minimum-necessary payloads; Stage 6 security pass |
| Pilot project regression from shared module | Live-study breakage | `pilot-final` tag; versioned module directories; per-project enablement |
| EM-log message rows retention/growth | Transcript integrity | Transcripts snapshot into an immutable `mica_transcript` EM-log row at finalization (`T<log_id>`); message + transcript log rows retained per retention policy decision (#14) — any purge job must exclude `mica_transcript` rows |

14. **Retention/export/deletion rules** for transcripts, findings, audit —
    define with privacy/IRB (dashboard spec requires it; affects nothing in
    the build except an eventual purge job).

## Explicitly out of scope (per handoff)

- Any live safety classifier, alert, or escalation in the participant path —
  the output schema rejects those fields by design; do not re-add under any
  feature name.
- Real-time SafetyScan wrapping of counselor turns.
- Automatic care-team/PI notification before RA confirmation (config exists
  but ships disabled; enabling is a protocol decision, not an engineering one).
