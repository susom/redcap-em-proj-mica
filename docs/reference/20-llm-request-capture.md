# The request MICA actually sends to the LLM

*2026-08-25. Verified end to end against PID 257 and the live Stanford AI Hub deployment.*

Two questions this answers: **what JSON body reaches the provider**, and **how do I replay it as a
curl**. They need separate answers, because the body is not written down anywhere you can read it —
not in the MICA source, not in the EM log — and the derivation is not obvious.

---

## 1. The short answer for PID 257

`llm-model` on PID 257 is **`gpt-5-6-sol`**, and that single fact determines the whole envelope. The
body on the wire is four keys:

```json
{
    "model": "gpt-5-6-sol",
    "messages": [
        { "role": "system",    "content": "<counselor system prompt + baseline context>" },
        { "role": "user",      "content": "<participant turn 1>" },
        { "role": "assistant", "content": "<counselor turn 1>" },
        { "role": "user",      "content": "<participant turn N>" }
    ],
    "max_completion_tokens": 16384,
    "reasoning_effort": "medium"
}
```

POSTed to the deployment URL from SecureChatAI's `api-settings` row for that alias:

```
POST https://aihubapi.stanfordhealthcare.org/azure-openai/deployments/gpt-5-6-sol/chat/completions?api-version=2025-04-01-preview
Content-Type: application/json
Accept: application/json
api-key: <the api-token for this alias>
```

That body is a **capture of the real bytes**, not a reading of the source — see §4.

## 2. Why you cannot read this off MICA.php

MICA builds a *request*; SecureChatAI builds the *body*. Four transforms sit in between, and three of
them delete things.

| Step | Where | Effect on PID 257 |
|---|---|---|
| MICA assembles `messages` | `MICA.php:892-906` — `handleUserInput()` keeps only `role`/`content`, then `appendSystemContext()` folds the baseline into the **existing** system message rather than adding a second one | one system message, not two |
| MICA adds parameters | `MICA.php:379-406` `setModelParameters()` — six project settings, blank ones skipped | only `reasoning_effort: medium` is set on this project |
| MICA adds `session_id` | `MICA.php:919-927` | **stripped before the request** — it is SecureChatAI's audit key, not an API field |
| SecureChatAI filters | `SecureChatAI.php:380-430` `filterDefaultParamsForModel()` | see below |
| The model class encodes | `classes/Models/GenericModelRequest.php` | overwrites `model` with the deployment's `model-id`, rewrites `json_schema` → `response_format` |

The filter is the step that surprises people. `gpt-5-6-sol` is in the **reasoning** list
(`SecureChatAI.php:406`), and for that list the function does not filter — it *replaces*:

```php
$strict = [
    'model'                 => $model,
    'messages'              => $merged['messages'] ?? [],
    'max_completion_tokens' => $merged['max_completion_tokens'] ?? ($merged['max_tokens'] ?? 32000),
];
```

So `temperature`, `top_p`, `frequency_penalty`, `presence_penalty` and `stop` — all of which
SecureChatAI merged in from its own system defaults a few lines earlier, and any of which MICA's
project settings could have overridden — **never leave the box** on this project. Setting
`gpt-temperature` on PID 257 today does nothing at all. Change `llm-model` to a non-reasoning alias
and they all reappear; the two aliases registered on this instance
(`claude-opus-4-7`, `gpt-5-6-sol`) take completely different envelopes.

`max_completion_tokens: 16384` comes from SecureChatAI's `gpt-max-tokens` **system** default
(`SecureChatAI.php:58`), because MICA's project-level `gpt-max-tokens` is blank.

### A bug worth knowing about, since it is visible in the numbers

`callLLMOnce()` computes a dynamic token cap and assigns it at `SecureChatAI.php:1173` — and then
line 1199, inside the generic branch, recomputes `$filteredParams` from scratch and discards it. So
for every model routed through `GenericModelRequest`, which is every OpenAI-compatible alias
including this one, the dynamic cap is calculated and thrown away; the flat 16384 is what ships.
(The Claude / embedding / whisper / tts branches do keep it.) Line 1172 also reads an undefined
`$fullPrompt`, so the estimate only ever sees `messages[0]['content']`. Not MICA's code, and not
currently harmful — noted here so nobody "fixes" the observed body to match the source.

## 3. What is *not* in the request

- No `session_id`, no `agent_mode`, no `project_id` — internal keys, stripped at `SecureChatAI.php:427`
  (and absent from the `$strict` shape anyway).
- No `user_id` on individual messages. The client used to send one; it was dropped both as an
  identity source and as a forwarded field (`MICA.php:288-295`).
- No `response_format`. The counselor turn sends no `json_schema`; only the SafetyScan path does, and
  even there `filterDefaultParamsForModel()` will drop it for an alias outside the schema list — see
  `classes/SecureChatSafetyScanCaller.php`.
- No tools. Agent mode is enabled system-wide on this instance, but MICA never requests it, so
  `callAI()` takes the single-call path (`SecureChatAI.php:452`).

## 4. Capturing the real bytes

`docs/phase-3-handoff/scripts/capture-llm-payload.php`. Off by default; arming is two deliberate acts
and undoing **either** disarms it.

```bash
CONTAINER=redcap_2023_1_web
S=/var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts

docker exec $CONTAINER php $S/capture-llm-payload.php shape    # derive the envelope, no PHI, no network
docker exec $CONTAINER php $S/capture-llm-payload.php on       # arm
#   ... now make one chat turn happen in the SPA ...
docker exec $CONTAINER php $S/capture-llm-payload.php last     # print the body + the curl
docker exec $CONTAINER php $S/capture-llm-payload.php off      # disarm
```

Each captured call writes two files to `/var/www/html/temp/mica/llm-capture`
(host: `www/temp/mica/llm-capture`, outside the module's git tree):

- `<stamp>-<alias>.body.json` — the exact bytes handed to `CURLOPT_POSTFIELDS`
- `<stamp>-<alias>.curl.sh` — a runnable replay, `--data-binary @` the body file

The curl **never contains the API key**. Both the URL and the auth header have the literal key
swapped for `${AIHUB_KEY}`, which you export before replaying:

```bash
export AIHUB_KEY='<api-token for this alias>'
sh /var/www/html/temp/mica/llm-capture/<stamp>-gpt-5-6-sol.curl.sh
```

Verified: the emitted script was replayed against the live AI Hub deployment and returned a normal
`chat.completion`.

## 4b. The other view: the whole payload in the emLogger file (dev servers only)

When the question is just *what does the payload look like*, the per-call file pairs are more
ceremony than the answer needs. `BaseModelRequest::logFullRequest()` appends one JSON line per call
to the emLogger stream instead — same point in the code, same "before `curl_exec`" position, so a
request the provider rejects is logged exactly like one it accepts:

```bash
tail -f <redcap-docker-compose>/logs/secure_chat_ai.json     # host path - no docker exec needed
```

`logs/` on the host **is** the container's `/var/log/apache2` (a bind mount), so read it from the host:
that view is authoritative, and container-side reads of it went briefly stale during this work.

Each line carries `model_alias`, `model_id`, `method`, the full URL, every header, and the body
**nested as real JSON** rather than an escaped string (emLogger decodes a JSON argument on the way
in), plus emLogger's own `pid`, `username` and `sourceIP` columns. The API key is masked to
`${API_KEY}` in both the URL and the header, so a line is safe to paste into a ticket.

Coverage is the same six-of-seven as §4, for the same reason — one shared call site. **A
text-to-speech payload will never appear here**: `GPT4oMiniTTSModelRequest` runs its own `curl_init`
and never reaches this method, so tailing the file while a TTS call happens shows nothing and that is
not a bug. Everything MICA does is covered.

Read it as a table with:

```bash
python3 -c "
import json
for i, ln in enumerate(open('<redcap-docker-compose>/logs/secure_chat_ai.json'), 1):
    e = json.loads(ln); p = e['args'][1]['value']; b = p['body']
    roles = '/'.join(m['role'][0] for m in b.get('messages', []))
    print(f\"{i}  {e['date']}  pid={e['pid']:>3}  {p['model_alias']:<12} msgs={roles:<12} keys={','.join(k for k in b if k!='messages')}\")
"
```

which on the verification run printed the whole session at a glance — four counselor turns, then the
three SafetyScan attempts the cron made ninety seconds later:

```
1  17:37:56  pid=257  gpt-5-6-sol  msgs=s/u          keys=model,max_completion_tokens,reasoning_effort
2  17:38:21  pid=257  gpt-5-6-sol  msgs=s/u/a/u      keys=model,max_completion_tokens,reasoning_effort
3  17:38:46  pid=257  gpt-5-6-sol  msgs=s/u/a/u/a/u  keys=model,max_completion_tokens,reasoning_effort
4  17:39:22  pid=257  gpt-5-6-sol  msgs=s/u          keys=model,max_completion_tokens,reasoning_effort   <- mobile, fresh session
5  17:40:00  pid=  -  gpt-5-6-sol  msgs=s/u          keys=…,response_format                              \
6  17:40:01  pid=  -  gpt-5-6-sol  msgs=s/u          keys=…,response_format                               >  D23, three times
7  17:40:01  pid=  -  gpt-5-6-sol  msgs=s/u          keys=…,response_format                              /
```

Both halves of the picture in seven lines: the transcript growing turn over turn, and the scan's
distinct envelope failing three times with no `pid` because the cron has no `$_GET['pid']`.

### Three decisions in it worth keeping

**Gated on `is_development_server`, not on a module setting.** REDCap's own flag (`redcap_config`,
`1` on `redcap.local`, `0` on the Stanford server), read as `$GLOBALS['is_development_server']`. The
body is the prompt, so on a production instance the method returns before touching anything — there
is no setting to remember to switch off, and nothing an operator can switch on by mistake.

Verified in both directions, with a real `callAI()` either side of the flag:

```
is_development_server=0  lines 2 -> 2   delta 0   PASS (nothing logged)
is_development_server=1  lines 2 -> 3   delta 1   PASS (logged)
```

Read that gate precisely, because it is **not** a hostname check and not "localhost only":

- It is **per REDCap instance**, so on a dev instance *every* project and *every* module that calls
  `callAI()` logs — `redcap_chatbot`, `redcap_rag_em`, `redcap_aimi`, not just MICA.
- It **fails closed**: a `0`, missing or empty row means no logging, so deploying this copy of
  SecureChatAI to production carries the code without the behaviour.
- Two ways it can still arm itself: an admin flipping a production instance to "development" to test
  something, and a **staging** instance that holds real participant data — both count as development.

A tighter gate would AND the flag with `$_SERVER['HTTP_HOST']`, but `HTTP_HOST` is empty under cron,
which would drop exactly the SafetyScan lines that exposed D23. Deliberately not done: one condition
that matches the flag REDCap itself uses to decide what is safe beats three conditions with a
carve-out.

**`emLog()`, not `emDebug()`.** `emDebug` is gated behind `enable-system-debug-logging`, and turning
that on to see one payload also turns on every other `emDebug` in SecureChatAI — the payload then
arrives buried in hundreds of unrelated lines. This way that flag stays `false` and the file holds
nothing but requests.

**Permissions, or the web never writes to it.** emLogger appends to
`<base-server-path>/<prefix>.json` — here `/var/log/apache2/secure_chat_ai.json`. Create it from a
CLI run as root and it is `root:root`; Apache runs as `www-data` (uid 501) and the cron container as
`cron-www`, so real turns then silently fail to log while a CLI test "works". Three different writers
means ownership is the wrong tool — make it world-writable, which is defensible precisely because
this only ever exists on a dev box:

```bash
docker exec redcap_2023_1_web sh -c ': > /var/log/apache2/secure_chat_ai.json \
  && chmod 666 /var/log/apache2/secure_chat_ai.json'
```

Verified afterwards that the cron container can also append.

One trap in the settings, since it looks like a blocker and is not: `em_logger`'s system row reads
`enabled = false`, yet `getEnabledModules()` reports it enabled and the module is writing files.
Don't spend time "fixing" that row — verify by looking for the file, not by reading the setting.

### Lines can go missing, and it is the mount, not the code

Two containers append to that one file: the web container as `www-data` through
`/var/log/apache2`, and the cron container as `cron-www` through `/logs-dir`. Same host directory,
two mounts, and cross-container appends to a macOS bind mount are **not atomic**. Measured directly —
40 lines from each container concurrently produced 79, not 80:

```
total lines: 79  (expect 80)     web lines: 40   cron lines: 39
```

That number is measured; the size of the loss under real load is not. Four browser-driven lines did
disappear during this work, around the time the cron wrote three large SafetyScan lines — but an
editor with the file open writing its buffer back would produce the same gap, so treat the cause of
*that* incident as unattributed. Only the 79-of-80 is evidence.

Either way the practical rule is the same: **treat the file as a live view, not an archive.** Tail it
while you click, and when you need a durable record of one specific call, the §4 file capture is the
half that cannot be clobbered — one file per call, written once. Nothing here is fixable from
`logFullRequest()`; the collision is per-container and emLogger's filename is per-module.

### Where the capture lives, and why there

One line in `BaseModelRequest::executeAPICall()`
(`modules-local/secure_chat_ai_v9.9.9/classes/Models/BaseModelRequest.php`), immediately before
`curl_exec`, where it sees the body *after* every transform.

Coverage, checked rather than assumed — of the seven model classes, six route through
`executeAPICall` and are captured:

| Class | HTTP path | Captured |
|---|---|---|
| `GenericModelRequest` (OpenAI-compatible; **the counselor turn**) | `executeAPICall` | yes |
| `ClaudeModelRequest` | `executeAPICall` | yes |
| `GeminiModelRequest` (**the SafetyScan model candidate**) | `executeApiCall` | yes |
| `GPTModelRequest` (embeddings) | `executeApiCall` | yes |
| `MetaModelRequest` (llama) | `executeApiCall` | yes |
| `WhisperModelRequest` (speech→text) | `executeApiCall` | yes — as a JSON rendering of the multipart fields, flagged in the emitted script as not replayable |
| `GPT4oMiniTTSModelRequest` (text→speech) | **its own `curl_init`**, bypasses the base class | **no** |

(The lowercase-`p` spellings are the source's; PHP method names are case-insensitive.) So both of
MICA's callers are covered — the counselor turn (`MICA.php:930`) and the SafetyScan
(`SecureChatSafetyScanCaller.php:81`) — because both go through `callAI()` and neither can route to
TTS. The gap is text-to-speech only, which MICA does not use. `SecureChatAI` also carries a second,
Guzzle-based HTTP stack (`getGuzzleClient()`), but no model class uses it.

Both writers sit at the same point and share that coverage: `captureRequest()` (files, §4) and
`logFullRequest()` (emLogger, §4b), called one after the other before `curl_init`. They are
independent — either can be on without the other — and both swallow every error, so neither can break
a call.

**This edits a module outside this repository.** `secure_chat_ai_v9.9.9` is shared with the other AI
modules on this instance (`redcap_chatbot`, `redcap_rag_em`, `redcap_aimi`, …). The file capture is
inert unless armed and the emLogger line is inert on any production instance, and every failure
inside both is swallowed so neither can break a call — but they are foreign-module edits and need to
be carried across when that module is updated. Both live in
`classes/Models/BaseModelRequest.php`; grep that file for `captureRequest` and `logFullRequest`.

### Why it is off by default

**The request body is the PHI.** It is the prompt: the participant's own words, plus their baseline
data. SecureChatAI is deliberately built so this never reaches a log — `callAI()`'s catch block says
*"Never log `$params` directly — it holds the prompt messages (PHI)"*, the HTTP-error path omits the
response body, and `callLLMOnce()` logs response *metadata* only. A capture that wrote to `emDebug`
or the EM log tables would quietly undo all three.

So it does none of that. It writes to a filesystem directory that somebody has to create, gated on:

1. `SECURECHAT_CAPTURE_DIR` (env) **or** the `securechat-capture-dir` system setting — a key
   deliberately absent from `config.json`, so it cannot be toggled from the module's settings screen;
2. that directory existing and being writable.

Delete the directory or run `off`; either alone is enough. **Captured files are prompt text on disk —
delete them when you are done**, and never arm this against a live study without the study's
agreement.

## 5. Appendix: the captured payload on `redcap.local`, PID 257, no participant turn

Real bytes, captured 2026-08-25 at the start of a `baseline` session — everything the model receives
before the participant has typed anything:

```json
{"model":"gpt-5-6-sol","messages":[{"role":"system","content":"You are MICA, a supportive motivational-interviewing counselor. DEV PLACEHOLDER.\n\nBaseline session. DEV PLACEHOLDER."}],"max_completion_tokens":16384,"reasoning_effort":"medium"}
```

Three things this shows that the derivation in §1 does not:

- **The local counselor prompt is a stub.** `chatbot_system_context_general` is 80 characters of
  `DEV PLACEHOLDER` and each of the seven per-session settings is a one-liner. Anything you conclude
  from a local run about tone, MI adherence or refusal behaviour is a conclusion about that stub, not
  about MICA.
- **One system message, not two.** `initSystemContexts()` folds the general context and the session
  context into a single `system` turn joined by `\n\n` (`appendSystemContext()`, `MICA.php:348`). The
  `session_2`…`session_7` keys differ only in that second paragraph, plus a catch-up summary that
  `initSystemContexts()` appends for numbered sessions.
- **No baseline data is injected.** `chatbot_redcap_inject` is unset on PID 257, so
  `getFormattedBaselineData()` returns null and the `## <Instrument> Data` block never appears. Set
  that field and the participant's decoded instrument values are appended to this same system
  message — which is where a real body starts carrying PHI.

To replay it, export the key for this alias and run the emitted script:

```bash
docker exec redcap_2023_1_db mysql -uroot -proot redcap -N -e \
  "SELECT value FROM redcap_external_module_settings
    WHERE external_module_id=39 AND \`key\`='api-token'"      # JSON array, positional per api-settings row
export AIHUB_KEY='<the entry matching gpt-5-6-sol>'
sh <stamp>-gpt-5-6-sol.curl.sh
```

## 6. What the EM log already gives you, and why it is not enough

`SecureChatAI::logInteraction()` writes one row per call with `project_id`, `session_id`, `username`,
the resolved `model`, and the **last** user message — not the full `messages` array, and none of the
parameters. Useful for "which model answered this turn"; useless for "why did the provider reject the
body". MICA's own `emDebug` at `MICA.php:906` logs the chatml array, but it fires *before*
`setModelParameters()` and before every SecureChatAI transform, so it is the request, not the body.

## 7. Re-verification, 2026-08-25 — a multi-turn capture from the participant's seat

Re-armed and driven through the SPA rather than through a script, so the bodies below are what a real
participant's turns produce: `manual-test-auth.php 257 setup`, then `node e2e/full-path.js <ed>
<baseline1>` (45 passed, 0 failed), then teardown. Four calls captured, all `gpt-5-6-sol` — three
desktop turns and the mobile turn.

The point of a multi-turn capture is the growth pattern, which a single body cannot show. **The whole
transcript is re-sent every turn**, one system message and then the full alternation, and nothing is
summarised or dropped:

| Capture | Body | `messages` |
|---|---|---|
| `…170112-722` | 312 B | system, user |
| `…170137-764` | 420 B | system, user, assistant, user |
| `…170202-818` | 547 B | system, user, assistant, user, assistant, user |
| `…170238-411` | 279 B | system, user — the mobile context, a fresh session |

The envelope is exactly the four keys §1 derives — `model`, `messages`, `max_completion_tokens:
16384`, `reasoning_effort: medium` — on all four. So the cost of a turn is quadratic in session
length, and a session long enough to matter is bounded by `max_completion_tokens` on the way out but
by nothing on the way in.

The emitted curl was replayed against the live deployment and returned a normal `chat.completion`
(`"content": "OK."`, `model: gpt-5.6-sol-2026-07-09`, 6 completion tokens) — so the script is
faithful, not just readable. The alias is **positional index 1** in this instance's `api-token` array
(`model-alias` = `["claude-opus-4-7","gpt-5-6-sol"]`), which makes the §5 recipe a one-liner that
never prints the key:

```bash
KEY=$(docker exec redcap_2023_1_db mysql -uroot -proot redcap -N -e \
  "SELECT JSON_UNQUOTE(JSON_EXTRACT(value,'\$[1]')) FROM redcap_external_module_settings
    WHERE external_module_id=39 AND \`key\`='api-token'" | tr -d '\r\n')
docker exec -e AIHUB_KEY="$KEY" redcap_2023_1_web \
  sh /var/www/html/temp/mica/llm-capture/<stamp>-gpt-5-6-sol.curl.sh
```

Two things worth restating from this run:

- **`securechat-capture-dir` is a *system* setting**, so arming is instance-wide: every AI module on
  this instance (`redcap_chatbot`, `redcap_rag_em`, `redcap_aimi`) writes its bodies to the same
  directory while it is on, not just MICA. Locally, with the `DEV PLACEHOLDER` prompts, that is
  harmless; on a shared instance it is not.
- **The captured files are prompt text on disk.** `off` disarms but does not delete. Clear the
  directory when you are done.

### The SafetyScan turn, and the blocking defect it exposed

Ending the session queued a scan, and the cron picked it up ~90s later, so three more captures
appeared *after* the SPA run finished — a useful reminder that the capture directory keeps filling
while it is armed:

```
20260825-170400-950  8777 B   \
20260825-170401-509  8777 B    >  byte-identical, one second apart
20260825-170402-144  8777 B   /
```

That envelope is not the counselor's. It carries a fifth key:

```
model, messages (system 4507 B + user 1417 B), max_completion_tokens: 16384,
reasoning_effort: medium, response_format: {"type":"json_schema", …}
```

So §3's caveat can be settled: **`json_schema` does survive the filter for `gpt-5-6-sol`** — it is in
SecureChatAI's schema list, and `GenericModelRequest` rewrites it into `response_format` as expected.

Three identical bodies is `callAI()` exhausting its two retries (`SecureChatAI.php:435`). Replaying
one returned an HTTP 400: the pinned scan schema uses `uniqueItems`, which Azure OpenAI structured
outputs rejects, so **every post-session SafetyScan on this instance fails before the model sees the
transcript** — nine rejected requests per session, then `manual_review_required`. Written up as
**D23** in `14-live-defects.md`.

This is the case the capture exists for. The 400's message is the only place the cause is stated, and
`executeAPICall` discards the response body on the error path on purpose (it can echo PHI), so no log
at any verbosity could have told you `uniqueItems`. The scan tables record a failure; the reason lives
for the length of one function call and is then thrown away.
