# "The configured AI model … is not available"

**Hit 2026-09-22 on MICA. Fixed the same day.** The chat refused to start with:

> The configured AI model "gpt-5-6-luna" is not available. Please contact your administrator.

| | |
|---|---|
| Thrown by | `MICA.php:511`, from `assertModelIsRegistered()` at `:497` |
| Root cause | `gpt-5-6-luna` was never registered in **SecureChatAI's** model registry. PIDs 268 and 271 were both configured to use it. |
| Not the cause | The deployment itself. It is real, live, and answers 200 — see the probe below. |
| Fix | [`scripts/register-securechat-model.php`](scripts/register-securechat-model.php) — `php register-securechat-model.php gpt-5-6-luna --apply` |

---

## Why a working model is "not available"

SecureChatAI has **no built-in model list**. `api-settings` is a repeatable *system* sub_setting an
admin fills in, stored as eight parallel JSON arrays (the parent flag plus its seven sub-keys). A
consumer module such as MICA only offers alias strings in a dropdown — **the option is inert until
someone adds the registry row**. So MICA can be configured with a perfectly good deployment name
and still refuse, because it checks `getAvailableModels()` before calling out.

That check is correct and worth keeping: without it the same mistake surfaces as a 404 buried
inside the canned "network difficulties" apology, because `executeAPICall()` omits response bodies
for PHI safety.

What was actually configured:

| Project | `llm-model` | Registered? |
|---|---|---|
| 257 | `gpt-5-6-sol` | ✅ |
| **268** | **`gpt-5-6-luna`** | ❌ |
| **271** | **`gpt-5-6-luna`** | ❌ |

Registry before the fix: `claude-opus-4-7`, `gpt-5-6-sol`. That is all.

## The deployment was fine — measured, not assumed

Before changing anything, all three GPT-5.6 deployments were probed directly with the registry's
own token and a non-PHI prompt:

| Alias | HTTP | Reply |
|---|---|---|
| `gpt-5-6-sol` | 200 | `ok` |
| **`gpt-5-6-luna`** | **200** | `ok` — `gpt-5.6-luna-2026-07-09` |
| `gpt-5-6-terra` | 200 | `ok` |

This mattered. The cheap "fix" would have been to point 271 at `gpt-5-6-sol` — the alias that was
already registered. That would have worked, and it would have been **wrong**: it silently changes
which model the study runs on, and 271 exists to mimic prod. The probe showed `luna` was reachable,
so the right repair was to register what was configured rather than reconfigure to what was
registered.

> One trap in the probe itself: the first call to `sol` returned **HTTP 000** with no response
> body, which reads exactly like "deployment does not exist". It was TLS/DNS warm-up — the retry
> returned 200. Do not conclude anything from a single `000`.

## The fix, as applied

```bash
php register-securechat-model.php gpt-5-6-luna              # dry run: prints the registry + probe
php register-securechat-model.php gpt-5-6-luna --apply
```

The script clones an existing entry (default `gpt-5-6-sol`) so the token, key variable and input
variable stay consistent with a deployment already known to work, and swaps the alias inside the
endpoint URL — **the alias is also the path segment**:

```
…/azure-openai/deployments/gpt-5-6-sol/chat/completions?api-version=2025-04-01-preview
…/azure-openai/deployments/gpt-5-6-luna/chat/completions?api-version=2025-04-01-preview
```

Guards, all of which exist because the failure modes are silent:

- **Probes the endpoint and refuses on anything but 200.** Registering an alias that does not
  resolve only moves the failure from a clear message to a buried 404.
- **Refuses if the parallel arrays are ragged** — eight arrays of unequal length corrupt the
  repeatable setting.
- **Never sets `default-model`**, so it cannot steal the default from an existing entry.
- **Only clone within a provider.** Copying the Bedrock entry to register an Azure deployment would
  carry the wrong URL shape; the script refuses if the source alias does not appear in its own URL.
- Idempotent: a second run reports "already registered".
- Writes `rollback-securechat-registry-<timestamp>.json` — the whole registry, before the change.

### Verified

```
getAvailableModels() -> claude-opus-4-7, gpt-5-6-sol, gpt-5-6-luna

pid 257  llm-model=gpt-5-6-sol      OK
pid 268  llm-model=gpt-5-6-luna     OK
pid 271  llm-model=gpt-5-6-luna     OK
```

and a live call through SecureChatAI's own client, not just the raw endpoint:

```
$sc->callAI('gpt-5-6-luna', ['messages' => [...]], 271)
-> {"role":"assistant","content":"ok","model":"gpt-5.6-luna-2026-07-09",
    "usage":{"prompt_tokens":13,"completion_tokens":4,"total_tokens":17}}
```

That last step is the one that proves the *cloned* fields are right. `getAvailableModels()` only
proves the alias is listed.

## Notes for next time

- **AI Hub deployment names are not derivable from the version.** GPT-5.6 ships as `sol`, `luna`
  and `terra`; there is no bare `gpt-5-6` and no `-nano`, even though 4.1 and 5.4 both have
  base+nano pairs. Read the enum in the service spec YAML rather than extrapolating.
- **The 5.6 line is reasoning-class.** It rejects `max_tokens` (wants `max_completion_tokens`) and
  rejects non-default `temperature`/`top_p`/penalties. SecureChatAI strips those itself for a
  reasoning alias, so MICA's `gpt-temperature` / `gpt-top-p` project settings are inert on these
  models — changing them changes nothing on the wire.
- **Registry entries are system-level, so they do not travel with a project copy** — the same class
  of loss as `twilio_modules_enabled` (alerts prereq P1) and
  `redcap_randomization_allocation` ([`../randomization/ALLOCATION_TABLE_DOES_NOT_COPY.md`](../randomization/ALLOCATION_TABLE_DOES_NOT_COPY.md)).
  Any new instance needs its aliases registered before a single chat turn will run.
- `safetyscan-model-alias` is a **separate** setting and is `gpt-5-6-sol` on all three projects. It
  was unaffected, but it is the other place an unregistered alias can hide.
