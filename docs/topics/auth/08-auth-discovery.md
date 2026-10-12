# Phase 3 Discovery — 2FA Replacement for MICA 3.0

**SOW item:** Discovery → "Identify a 2FA Replacement"
**Status:** DISCOVERY / RECOMMENDATION — awaiting PI + security sign-off. No code changed.
**Written:** 2026-08-17 · **Revised:** 2026-08-17 against the as-built PID 257 · **Branch:** `mica-phase-3`
**Verified against:** local REDCap **17.2.3** (`www/redcap_v17.2.3`, running instance, PID 257 `MICA_R01`)

> **Revision note.** The researcher-provided structure for PID 257 landed after the first
> draft. It was audited field-by-field — see
> [`09-pid-257-structure-audit.md`](../../archive/09-pid-257-structure-audit.md) — and this memo revised
> against it. **The options analysis (§4) and ranking (§5) did not change**; everything
> load-bearing in the recommendation held. Three things did: the chat has no host instrument
> yet (§6.1), no date-of-birth field exists so the credential choice changed (§6.1), and the
> study has its own `check_code` passcode pattern that must be reconciled rather than
> duplicated (§6.1a).

## Parts

- [08a — 2FA replacement: current state, requirements and options (§1–5)](08a-auth-options-analysis.md): §1 current state, §2 requirements, §3 what REDCap provides natively (Survey Login semantics, delivery, link expiry), §4 options, §5 decision matrix.
- [08b — 2FA replacement: recommended design and rollout (§6–11)](08b-auth-recommended-design.md): §6 target design (incl. the one-credential-slot multi-arm constraint), §7 Track A remediation, §8 session binding, §9 session-window routing, §10 plan impact, §11 feasibility spike.


## SOW obligations and where they are answered

| SOW clause | Section |
|---|---|
| Research & evaluate replacements for the current 2FA "without sacrificing system security" | §1 current state, §4 options, §5 matrix |
| Reliable record mapping (each participant resolves to a single REDCap record) | §2 R2, §4 (column "Record mapping"), §6 |
| Participant experience | §2 R5, §4, §6.3 |
| Compatibility with existing session-window routing | §9 |
| Notify the PI of findings | §12 (PI-facing summary, written to be pasted into email) |
| "If approved, built in Development. Otherwise authentication remains as-is." | §7 — why "as-is" is not a safe fallback, and what ships regardless |

---

## 0. TL;DR

1. **The current 2FA is weaker than its design implies.** `verifyEmail()` resolves the
   participant **by code alone** and never binds to the identity that requested the
   code; the stored timestamp is never checked, so codes never expire; codes are never
   cleared after use, so the set of permanently-valid codes grows with enrollment; and
   there is no attempt limit. See §1. This makes "authentication remains as-is" an
   unsafe fallback, independent of any PI decision.
2. **The front door is not the only problem.** After OTP the participant is redirected
   to a persistent, record-bound survey link that bypasses OTP on every later visit,
   and the module's AJAX endpoints take `participant_id` from the **client payload**.
   Replacing the OTP without fixing those two leaves the actual access path unchanged. See §8.
3. **REDCap 17.2.3 already implements, in core, every security-critical piece we
   currently hand-roll** — participant-scoped login on a single instrument, N-of-M
   credential matching, failed-attempt lockout, audit logging, an idle-timeout session,
   time-limited record-bound links, and SMS delivery. All of it executes **before** the
   MICA chat UI renders. Verified in source (§3) **and executed end-to-end against the
   local instance** (§11): unauthenticated access rendered the login form with no survey
   content, lockout fired exactly at the configured limit, link expiry denied an expired
   link, and every attempt landed in REDCap's own audit log. The spike also corrected one
   assumption: link expiry does not fire unless `link_expiration` is set, so the module must
   write it at issuance (§3.3) — which in turn makes expiry work on every delivery path.
4. **Recommendation (§6): retire the custom OTP entirely** and authenticate the chat
   with (a) a record-bound, time-limited participant survey link delivered over the
   participant's preferred channel (email or SMS — Twilio is approved), plus (b) native
   **Survey Login** scoped to the two MICA chat surveys. This deletes ~120 lines of
   custom auth, removes the no-auth login page, and makes record mapping deterministic
   *by construction* instead of by name+email string matching.
5. **The open policy question does not block the build.** Whether we require one factor
   or two is a REDCap **project setting** (`survey_auth_min_fields`, or Survey Login
   off), not an architecture. Engineering cost is identical across the three tiers in
   §6.2, so implementation can proceed while Stanford security/privacy and the IRB
   decide the tier.

---

## 12. PI-facing summary

> **MICA 3.0 — login and verification: findings and recommendation**
>
> As part of Phase 3 discovery we reviewed how participants currently get into the MICA
> chat and evaluated alternatives.
>
> **What we found.** The current two-step login (name + email, then a code emailed to the
> participant) does not verify the code against the person who requested it, the codes
> never expire or get used up, and there is no limit on guessing attempts. Separately, the
> web address a participant lands on after entering their code keeps working indefinitely,
> so a shared or bookmarked link re-enters the chat without a code. We also found that a
> participant is matched to their study record by comparing the first name and email they
> type against what is stored — so a typo, a name change, or a shared family email address
> can block a participant or, in the case of duplicates, stop the login entirely.
>
> **What we recommend.** Retire the custom code-by-email step and use REDCap's own
> built-in participant login instead. The participant gets a private link to their own
> session — by text message (preferred in the ED, since it arrives on the phone in their
> hand) or by email — and REDCap then asks them to confirm one or two details we already
> hold in their record, such as date of birth, before the chat opens. The link expires
> after the visit, and the session times out after 30 minutes of inactivity.
>
> **Why this is better.** Each participant's link points to exactly one study record, so
> the "which record is this?" problem disappears by design rather than being solved by
> name matching. Every login attempt, successful or failed, is recorded in REDCap's own
> audit log, and repeated failed attempts lock the session automatically. It also removes
> a large amount of custom security code that we would otherwise have to maintain and
> defend — the protections come from REDCap itself.
>
> **What it costs the participant.** One extra detail to confirm at login, and they will
> need to log in again if they return the next day rather than the link simply working.
> A participant who cannot give the detail exactly as it is recorded will be locked out
> after a few tries; the lock clears itself after a short waiting period, and we will give
> the CRC a documented way to help (correct the stored value, or hand over an access code).
> We tested this behaviour on a scratch project, so these are measured behaviours rather
> than expectations.
>
> **What we need from you.**
> 1. Approval to replace the emailed-code step with REDCap's built-in participant login.
> 2. A decision, with Stanford security/privacy and the IRB, on whether one factor (the
>    private expiring link) is sufficient or whether a second detail must be required.
>    This is a configuration setting either way, so it does not delay the build.
> 3. **Which detail to ask for.** The study database does not record date of birth, which
>    would be the usual choice, so the options are:
>    **(a) last name** — already collected, nothing new to add, and our recommendation;
>    **(b) a short passcode printed on a card and handed to the participant in the ED** —
>    the strongest option, because it is not sent to the same phone that receives the link,
>    but it adds a step for the CRC; **(c) begin collecting date of birth** — your call,
>    since not collecting it appears to have been deliberate.
> 4. Agreement that we may text participants their session link. (Every enrolled
>    participant has a texting-capable phone, since that is an eligibility requirement, so
>    text is the most reliable channel in the ED.)
> 5. Note that some fixes to the current login are being scheduled regardless of this
>    decision, because leaving it exactly as-is is not a safe option.
>
> **Two separate items for the study team, found while reviewing the new database
> structure** (details in the audit document, not blocking the above):
> the passcode feature on the `check_code` form refers to a passcode field that does not
> exist yet, so it cannot currently succeed; and some participant-facing text still names
> the **ASPIRE** and **TRAM** studies rather than this one.

---

## Appendix — evidence index

| Claim | Evidence |
|---|---|
| Auth gates precede module rendering | `Surveys/index.php` 1021 / 1097 / 1367 vs hooks at 3455 / 3847 |
| Survey Login scoping | `redcap_projects.survey_auth_apply_all_surveys`, `redcap_surveys.survey_auth_enabled_single`; `Surveys/index.php:1367`, `Classes/DataEntry.php:3549` |
| N-of-M credential fields | `redcap_projects.survey_auth_field1..3`, `survey_auth_event_id1..3`, `survey_auth_min_fields`; `Classes/Survey.php:2227` |
| Lockout | `redcap_projects.survey_auth_fail_limit/fail_window`; `redcap_surveys_login`; `Surveys/index.php:1420-1435`, `:1523-1531` |
| Audit events | `Surveys/index.php:1498`, `:1516` (`Survey Login Success` / `Failure`) |
| Two-cookie session + idle timeout | `Surveys/index.php:1509-1512`, `:1368-1375`; `Classes/Survey.php:2263-2267` |
| Per-participant link expiry | `Surveys/index.php:1097`; `Classes/Survey.php:3172-3186`; `redcap_surveys.survey_time_limit_*`, `redcap_surveys_participants.link_expiration` |
| Delivery preference / SMS | `redcap_surveys_participants.delivery_preference` enum, `participant_phone`; `redcap_projects.twilio_*` |
| Access / short codes | `redcap_surveys_participants.access_code`, `access_code_numeral`; `redcap_surveys_short_codes`; `Classes/Survey.php:2273+`; `Surveys/get_access_code.php` |
| QR links | `Surveys/survey_link_qrcode.php`, `survey_link_qrcode_svg.php` |
| Participant + response row creation | `Classes/Survey.php:1687` (`getFollowupSurveyParticipantIdHash`), `:1755`; empirically §11 S1 (participant 2105 / response 2238) |
| Link expiry not enforced for uninvited links | `Classes/Survey.php:3180`; empirically §11 S6 (`link_expiration` NULL) |
| Gate order (login form, no survey content, pre-auth) | §11, unauthenticated GET of a participant link |
| Lockout thresholds and non-recording of the post-lockout attempt | §11 S5 (`redcap_surveys_login`, 3 rows for 4 attempts) |
| Cookie is server-side verifiable per record | §11 S3b (recomputed `hash(sha512, "pid\|record\|salt\|salt2")` == issued cookie) |
| Audit events land in REDCap's own log | §11, `redcap_log_event15`, `Survey Login Success` / `Failure` on `pk=SPIKE1` |
| JSMO blocked on no-auth pages | commit `9b69d2c` message; current `pages/chatbot.php` is server-rendered PHP |
| No pilot data locally | `redcap_projects`: only `MICA_R01` PID 257, status 0, 0 records |
| Spike isolation | scratch PID 254 only; config captured and restored; `SPIKE1` and its participant/response rows deleted; PID 257 untouched |
