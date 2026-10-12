# Reference

How MICA works today.

| Doc | What it is |
| --- | --- |
| [01-architecture.md](01-architecture.md) | The building blocks: services under `classes/`, the no-auth participant chat, the authenticated review dashboard, SecureChatAI for every LLM call. |
| [02-data-model.md](02-data-model.md) | Where data lives: REDCap fields, the EM log and entity tables (revised 2026-08-11). |
| [13-securechatai-current-state-delta.md](13-securechatai-current-state-delta.md) | SecureChatAI's consumer contract as verified on 2026-08-18: agent loop, hooks, response shape. |
| [20-llm-request-capture.md](20-llm-request-capture.md) | The exact JSON body MICA sends to the LLM, and how to replay it with curl. |
| [ALERT_EVENT_PREFIX_BUG.md](ALERT_EVENT_PREFIX_BUG.md) | Why a REDCap alert silently never fires when it reads data at the wrong event, and the fix applied to alerts 02–14. |
| [23-safetyscan-payload.md](23-safetyscan-payload.md) | The SafetyScan request body with the pinned prompt and a study addendum, generated from live code. |
