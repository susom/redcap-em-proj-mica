# Scan fixtures (`scan-mock-mode`)

Replayed by `classes/FixtureSafetyScanCaller.php` instead of calling a model, so the whole
pipeline — queue, claim, validate, verify quotes, write findings, RA queue — is exercisable
with no network and no provider spend.

Select one by putting `[[scan-fixture:<name>]]` anywhere in a transcript's message text; the
default is `no_supported_concern`.

| Fixture | What it exercises |
|---|---|
| `no_supported_concern` | A genuine clean screen: `ok`, zero findings, job → `ready_for_review` |
| `self_harm_critical` | The release path: one critical finding whose quote is byte-exact in the seeded transcript |
| `fabricated_quote` | The regression guard — a quote that appears in no transcript, so `citation_mismatch` must reject the whole scan |
| `unable_to_assess` | Schema-valid, empty findings, shaped exactly like a clean screen. Must NOT be released |
| `content_filter` | Terminal transport failure: straight to `manual_review_required`, no retries |
| `timeout` | Transient transport failure: retry with backoff, then give up visibly |

A fixture with a `__runStatus` key scripts a transport failure rather than a model answer, so the
retry and give-up paths are reachable without breaking a provider on purpose.

## `message_id` placeholders

Evidence cites `"message_id": "#1"`, meaning **the message whose `sequence` is 1**, and the caller
resolves it against the transcript it was handed.

A fixture cannot hardcode a real id: those are `L<log_id>` from the database, so a fixture written
against `L1` cites a message that is not in the transcript and *every* scan fails as
`citation_mismatch` — which is exactly what happened the first time this ran, making the release
path untestable in mock mode.

The placeholder resolves an identifier and nothing else. `exact_quote` is never rewritten, so a
quote still has to match the stored text byte for byte.

**Fixtures do not bypass verification.** Each one goes through the same schema validation and the
same byte-exact quote check as a real response — which is what makes `fabricated_quote` a real
test rather than a comment. `self_harm_critical`'s quote must match the transcript the verification
script seeds; if that text changes, this fixture has to change with it.

Mock mode proves the plumbing, not the model. The Stage 6 launch gate refuses production while
`scan-mock-mode` is on.
