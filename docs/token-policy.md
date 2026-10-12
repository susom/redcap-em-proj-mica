# AI token policy: standard and high-threshold models

SecureChatAI now has two AI Hub tokens, each serving its own set of model deployments: **standard** and
**high-threshold** (SecureChatAI `docs/token-policy.md`, PR susom/secureChatAI#3). MICA can move its AI
calls to the high-threshold set with one switch.

## What a super user sees

External Modules → Project MICA → **Configure**:

| Setting | Who sees it | What it does |
| --- | --- | --- |
| **Use high-threshold AI models** (`use-high-threshold-models`) | super users only | Off by default. On moves **both** the counselor turn and the SafetyScan to the high-threshold set. |
| **LLM Model** (`llm-model`) / **SafetyScan model alias** (`safetyscan-model-alias`) | shown while the switch is off | Unchanged: the standard models. |
| **LLM Model (high-threshold)** (`llm-model-high`) / **SafetyScan model alias (high-threshold)** (`safetyscan-model-alias-high`) | shown while the switch is on | The high-threshold models. |

- **Ticking the switch swaps the dropdowns live**, and unticking swaps them back. Each dropdown keeps
  its own saved choice, so turning the switch off restores the standard models exactly.
- **What the high-threshold dropdowns list:**
  - first, AI Hub's high-threshold deployments, from `config.json` (2026-10-12):

    | Value | Family | Notes |
    | --- | --- | --- |
    | `gpt-5-4-high-threshold-policy` | gpt-5-4 | chat |
    | `gpt-5-5-high-threshold-policy` | gpt-5-5 | reasoning-class: temperature / top-p settings are ignored |
    | `gpt-5-2-high-threshold-policy` | gpt-5-2 | chat |
    | `gpt-5-4-mini-high-threshold-policy` | gpt-5-4-mini | chat |
    | `gpt-5-4-nano-high-threshold-policy` | gpt-5-4-nano | chat |
    | `gpt-4-1-mini-high-threshold-policy` | gpt-4-1-mini | chat |

    All six were probed live and return schema-valid structured output, so all six are offered for
    the SafetyScan too. AI Hub's `gpt-4-1-mini-low-threshold-policy` isn't high-threshold and isn't
    listed.
  - then any other alias SecureChatAI has registered under high-threshold on this server, read when
    the dialog opens (the SafetyScan list only takes families with enforced structured output).
  - An old `placeholder-high-model-*` value still saved on a project (from before the list existed)
    is refused on every call, and Launch readiness names it as a placeholder.
- **Designers who aren't super users** never see the switch. REDCap leaves a super-users-only field out
  of the dialog, and a show/hide rule that points at a missing field hides its target. So the module
  removes the inactive pair on the server side and shows designers the active pair only. A field that
  isn't shown isn't saved, so a designer's Save keeps the switch and the other pair as they were.

## The rule: no fallback

It's a data rule, as in SecureChatAI. With the switch on, nothing is ever sent to a standard model:

| Situation | Counselor turn | SafetyScan |
| --- | --- | --- |
| Switch on, registered high-threshold alias | sent with `token_policy: high` | sent with `token_policy: high` |
| Switch on, alias blank | refused before sending | refused; no `gemini-2.5-flash` fallback |
| Switch on, an old placeholder alias still saved | refused before sending | refused before sending |
| Switch on, alias not registered under high-threshold | refused before sending | refused before sending |
| Switch on, SecureChatAI without token-policy support | refused before sending (an old SecureChatAI would ignore `token_policy` and use the standard set) | refused before sending |
| Switch off, SecureChatAI's Minimum Token Policy for the project is high | refused before sending | refused before sending |
| Switch off | unchanged: no `token_policy` is sent, so SecureChatAI's own project minimum still decides | unchanged |

- **What a participant sees on a refused turn:** the same message as an unknown model ("The configured
  AI model … is not available. Please contact your administrator."). The reason goes to the EM log for
  staff.
- **What happens to a refused scan:** it's a `service_error`, so it retries and then lands in
  **manual review**. It's never a clean screen, and its run row says "refused under the AI token
  policy" and why.

Code: `classes/ModelSelection.php` (the switch, the keys, the dialog shaping) and
`MICA::tokenPolicyRefusal()` (every refusal above). Both call paths use those. The models gate in
**Launch readiness** reports the same problems before a participant meets one.

## Model alias names and families

SecureChatAI shapes each request by model **family**: whether `json_schema` is forwarded, whether the
reasoning-model parameter set applies, and the token budget. A `-high-threshold-policy` (or
`-low-threshold-policy`) suffix is stripped for those lookups, so `gpt-5-4-high-threshold-policy` is a
`gpt-5-4` (SecureChatAI `TokenPolicy::capabilityAlias()`). MICA does the same in
`ModelSelection::family()`, for its copy of the structured-output list
(`SecureChatSafetyScanCaller::OPENAI_SCHEMA_MODELS`, which the SafetyScan depends on) and for the
dialog's SafetyScan list.

## Adding a high-threshold model

1. In SecureChatAI, add the row (Token Policy = high-threshold) and run
   `tests/probe-model-capabilities.php <alias> high` there. If the family is new to SecureChatAI, add it
   to `SCHEMA_CAPABLE_MODELS`, and to the reasoning lists if it refuses temperature. Results go in
   SecureChatAI's `docs/token-policy.md`.
2. Add the family to `SecureChatSafetyScanCaller::OPENAI_SCHEMA_MODELS` (`SchemaModelMirrorTest` fails
   if it drifts from SecureChatAI's list). If it's reasoning-class, also add it to the
   `$reasoningModels` list in `capture-llm-payload.php` (`ReasoningModelMirrorTest`).
3. Add it to both high-threshold dropdowns in `config.json`, and to
   `ModelSelectionTest::AI_HUB_HIGH`.

## Production rollout

Production is configured through the REDCap UI. Order matters: SecureChatAI first.

1. **SecureChatAI:** deploy the token-policy version and add the high-threshold token and model rows.
   Follow SecureChatAI `docs/token-policy.md`, "Production rollout".
2. **Deploy this MICA version.** With the switch off nothing changes: no `token_policy` is sent.
3. In the MICA project, go to **External Modules** → **Project MICA** → **Configure**.
4. As a super user, tick **Use high-threshold AI models**. The two model dropdowns swap to their
   high-threshold versions.
5. In **SafetyScan model alias (high-threshold)**, pick the high-threshold model that will screen
   sessions. `gpt-5-5-high-threshold-policy` is the one verified end to end locally (reasoning-class,
   like the standard `gpt-5-6-sol`).
6. Scroll down to **LLM Model (high-threshold)** and pick the counselor model.
   `gpt-5-4-high-threshold-policy` is the one verified end to end locally.
7. Click **Save**.
8. Open the review dashboard → **Launch readiness**. **Model aliases resolve** must be green and end
   with "registered under the high-threshold policy".
9. Run one test session end to end (the runbook's test session) and confirm in SecureChatAI's log
   that the turns and the scan show `token_policy: high`.

**Rollback:** untick **Use high-threshold AI models** and click **Save**. The standard models come back
exactly as they were saved.

## Testing

Two layers, both local. The mock suite uses SecureChatAI's mock AI Hub with fake keys, so nothing
reaches AI Hub and every routing claim is read off the wire. The real-model run uses the actual
high-threshold deployments, with synthetic, mild text.

`$MICA` is this module's folder name: `proj_mica_v9.9.9` normally, or a worktree folder beside it (the
branch was built in `proj_mica_v9.9.8`). A worktree has no `node_modules` or `tools/vendor` of its own:
copy `tools/vendor` from the main folder (same `tools/composer.lock`) and run node with
`NODE_PATH=../proj_mica_v9.9.9/node_modules`.

```bash
W=/var/www/html/modules-local; MICA=proj_mica_v9.9.9; S=$W/$MICA/docs/phase-3-handoff/scripts
docker exec -w $W/$MICA redcap_2023_1_web php tools/vendor/bin/phpunit -c phpunit.xml   # 1134 tests OK
docker exec -w $W/$MICA redcap_2023_1_web php tools/vendor/bin/phpcs                    # exit 0
docker exec redcap_2023_1_web php $W/secure_chat_ai_v9.9.9/tests/e2e/fixture.php setup
docker exec -d redcap_2023_1_web php -S 127.0.0.1:8099 $W/secure_chat_ai_v9.9.9/tests/e2e/mock_aihub.php
docker exec redcap_2023_1_web php $S/e2e-token-policy-fixture.php 271 setup
docker exec redcap_2023_1_web php $S/verify-token-policy.php 271                        # 32/32
docker exec redcap_2023_1_web php $S/e2e-module-config-user.php 271 setup
node e2e/token-policy.js config     # 39/39, desktop + iPhone 13
node e2e/token-policy.js session    # 9/9, a real participant turn and its SafetyScan
# then: reset-test-record.php 271 <record> and delete the record; e2e-module-config-user.php 271 teardown;
#       e2e-token-policy-fixture.php 271 teardown; secure_chat_ai fixture.php teardown; stop the mock
```

Against the real high-threshold models (SecureChatAI's six high-threshold rows registered with the
high-threshold token, no mock):

```bash
docker exec redcap_2023_1_web php $S/e2e-token-policy-fixture.php 271 setup-real \
    gpt-5-4-high-threshold-policy gpt-5-5-high-threshold-policy
node e2e/token-policy.js session-real   # 8/8 on 2026-10-12
# then: reset-test-record.php 271 <record>, delete the record, e2e-token-policy-fixture.php 271 teardown
```

`session-real` checks that a participant's turn is answered on screen (`gpt-5.4-2026-03-05`), that the
session's SafetyScan returned a schema-valid scan on `gpt-5-5-high-threshold-policy`
(`gpt-5.5-2026-04-24`, job `ready_for_review`), and that every SecureChatAI log row for the session reads
`token_policy: high`. SecureChatAI writes two rows per turn by design: its audit row and its
conversation row.

- **What the E2E fixture does:**
  - it makes the folder it lives in the active MICA version (for a worktree such as
    `proj_mica_v9.9.8`), and puts the old version back on teardown;
  - it refuses to run unless SecureChatAI's fixture is active, so calls only ever go to the mock.
- **`verify-token-policy.php`** reads the mock's request log to prove which model set and which key
  each call reached. It covers standard and high, the counselor and the scan, every refusal (nothing
  sent), SecureChatAI's project minimum, and the Launch readiness gate.
- **`e2e/token-policy.js session`** checks a real participant session:
  - the counselor's answer on screen and the session's SafetyScan both came from `/high/gpt-5-4` on
    the high-threshold key;
  - nothing used the standard key;
  - `token_policy` never went upstream.
- **On a phone,** REDCap's own settings dialog is 850px wide on a 390px screen, whether the switch is
  on or off. That's REDCap core, not these settings, and the new rows stay inside the dialog.
