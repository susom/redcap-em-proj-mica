# The chatbot would not open: a duplicate DOM id, shared with another module

*2026-08-25. Reproduced E2E on `redcap.local` PID 257, fixed, and re-verified with a live turn on
desktop and mobile.*

## Symptom

A valid booster-session survey link —
`http://redcap.local/surveys/?s=gLIWyAqCs9SwpxJV#/home` (PID 257, record 1,
`mica_booster_session`, Month 3 / arm 2) — passed Survey Login and then showed:

> **This chat could not be opened because the session did not load.** Please reload the page, and
> contact the study team if this keeps happening.

Nothing in the network tab explained it: every MICA asset returned 200, there were no failed
requests, no 4xx/5xx, and no JavaScript errors. The server had built a perfectly good bootstrap.

## Cause

`#chatbot_ui_container` is **not unique on the page.**

The REDCap Chatbot module (`redcap_chatbot`, "Cappy") is **enabled system-wide on this instance**
(`redcap_external_module_settings`, `project_id IS NULL`, `enabled = true`), and it emits its own
mount point from `redcap_every_page_top` → `injectIntegrationUI()`:

```php
// redcap_chatbot_v9.9.9/REDCapChatBot.php:127
echo '<div id="chatbot_ui_container"></div>';
```

Being an every-page hook, that div is on the **login page too** — before MICA has run at all.

In the rendered survey page, that div lands at byte offset **78,001**. MICA's own container —
the one that actually carries the session — lands at **171,456**:

| offset | emitted by | has `data-bootstrap` |
|---|---|---|
| 78,001 | `redcap_chatbot` | no |
| 171,456 | `proj_mica` (`MICA.php:639`) | **yes** |

`document.getElementById()` returns the **first** match in document order. So MICA's inline
bootstrap read Cappy's empty div:

```js
var root = document.getElementById('chatbot_ui_container');   // → Cappy's div
try { b = JSON.parse(root.dataset.bootstrap || '{}'); } catch(e){ b = {}; }
window.mica_bootstrap = b;                                     // → {}
```

`useAuth.jsx:21` then correctly concluded there was no participant and blocked the session. The
error message was accurate; the thing it described had happened two modules away.

The same mistake sat on the SPA side, and it was the worse half: `main.jsx` self-mounts at import
time with the same `getElementById`, so React was rendering MICA's whole UI **inside Cappy's
container, on top of Cappy's already-mounted React root**.

## Fix

Select by the attribute only MICA sets, on both sides. The id stays `chatbot_ui_container` — it is
load-bearing in `index.css`, `App.css`, `confirmSheet.css` and about twenty selectors in
`e2e/full-path.js`, so renaming it would have been a much larger and riskier change for no extra
safety.

| File | Change |
|---|---|
| `MICA.php:651` | `getElementById('chatbot_ui_container')` → `querySelector('#chatbot_ui_container[data-bootstrap]')` |
| `MICA.php:678` | same selector passed to `renderMicaApp()` |
| `mica-chatbot/src/main.jsx:21` | same selector for `createRoot()` |
| `mica-chatbot/index.html:22` | `data-bootstrap="{}"` added so `npm run dev` still mounts standalone |
| `mica-chatbot/dist/` | rebuilt (`npm run build`) — `index-CKDLvWNf.js` → `index-CC2csyFH.js`; `generateAssetFiles()` scans the directory, so the new hash is picked up with no code change |
| `e2e/full-path.js` | all 21 selector uses and 3 `getElementById` lookups scoped the same way |

The harness change was not cosmetic. Because Cappy's div is present **before** login, a bare
`#chatbot_ui_container` made `A1b chat NOT reachable before login` count 1 instead of 0 — the suite
would have failed on a page where nothing was wrong, and the container reads at B1/D/F1 were
measuring Cappy's empty div rather than MICA's UI.

The attribute selector also steps around a **second** duplicate of this id that lives inside the app
itself: `views/PostSession/postsession.jsx:8` renders `<div id="chatbot_ui_container">`. It has no
`data-bootstrap` either, so it can no longer be mistaken for the mount point.

## Verification

Reproduced and re-verified with Playwright against the real link (`e2e/`-style harness, desktop
1400×950 and iPhone 13):

| | before | after |
|---|---|---|
| `window.mica_bootstrap` | `{}` | `{participant_id: "1", current_session: "booster", …}` |
| SPA state | blocked, "session did not load" | greeting, launch banner, live composer |
| round-trip turn | impossible | sent and answered, desktop **and** mobile |
| JS errors | 0 | 0 |

The smoke-test turns written during verification were deleted from the EM log afterwards, so
record 1's transcript is unchanged.

The full suite then ran against a fresh fixture and passed clean:

```
php docs/phase-3-handoff/scripts/manual-test-auth.php 257 setup
NODE_PATH=$PWD/node_modules node e2e/full-path.js <edLink> <baseline1Link>
  ===== 45 passed, 0 failed =====
php docs/phase-3-handoff/scripts/manual-test-auth.php 257 teardown
```

`composer test` (phpcs + 1021 PHPUnit tests) is green; it does not cover this, since the defect and
its fix are both in browser-side code.

## Two things this uncovered, deliberately left alone

**1. `window.renderMicaApp` does not exist.** `MICA.php:677` polls for it every 100 ms, up to 100
times, and `tryMount()` can therefore never return true. It is defined nowhere in `mica-chatbot/src`
or in the built bundle — the app self-mounts from `main.jsx` instead. Consequences: `unmask()` never
runs, so the `#mica-hide-native` style block that hides REDCap's survey title, instructions and
footer is never removed, and a ~10 s interval spins for nothing on every load. Currently inert —
the hidden chrome is the desired end state anyway — but the intended mount handshake is dead code,
and nobody has ever exercised it. Repairing it is its own change.

**2. There is no system context for the `booster` session.** The captured bootstrap for this link
was:

```
"You are MICA, a supportive motivational-interviewing counselor. DEV PLACEHOLDER.\n\n"
```

Note the trailing `\n\n` with nothing after it. `getSystemContextForRecord()` resolved
`current_session` to `booster`, so `initSystemContexts()` looked up
`chatbot_system_context_booster` — and PID 257 has only `general`, `baseline` and
`session_2`…`session_7`. The key does not exist, so the session-specific half of the counselor
prompt is an empty string and every booster session runs on the general context alone. That is a
configuration/mapping gap independent of the mount bug, and it survives this fix. It needs a
decision: either add the `booster` setting, or map `mica_booster_session` onto one of the numbered
session keys.

## Note for other REDCap sites

Any project running MICA alongside REDCap Chatbot hits this, and enabling Cappy system-wide is the
normal deployment. If a third module ever adopts the same id, the `[data-bootstrap]` discriminator
still holds — but the durable fix at that point is a module-prefixed id.
