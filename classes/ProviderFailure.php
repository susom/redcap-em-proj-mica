<?php

namespace Stanford\MICA;

/**
 * Did SecureChatAI hand us a failure dressed as an answer?
 *
 * `callAI()` never throws. Every failure path returns the output of `sanitizeOutputForUI()`, and on
 * the error branch that output carries **`role` and `content` only** - where `content` is one of
 * eight canned apologies ("...experiencing network difficulties..."). There is no `error`, no
 * `type`, no `model`, no `usage`. So a failure is shape-indistinguishable from a real answer, and
 * MICA's `formatResponse()` was treating it as one: the apology was returned to the SPA *and*
 * written into the participant's transcript as counselor speech.
 *
 * That last part is the defect this class exists to close. The transcript is what SafetyScan
 * analyses, so a provider outage was becoming words attributed to MICA in the clinical record - and
 * a scan of it would be reading, and possibly quoting, an apology the counselor never said.
 * (13-securechatai-current-state-delta.md §1.3, §6.1, §7 item 8b.)
 *
 * ## Why `model === null && usage === null` is safe *here*
 *
 * That test also matches a legitimate **agent-mode** turn, which returns `role`, `content`,
 * `tools_used` and nulls both fields (`SecureChatAI.php:1130-1134`). It is unambiguous for MICA only
 * because MICA never opts into agent mode: the pipeline is reached solely from `runAgentLoop()`,
 * itself reached solely when the caller sets `agent_mode`, and MICA never does - the system-wide
 * `enable_agent_mode` flag is irrelevant to a caller that does not ask (§7 item 7). If MICA ever
 * uses agent mode, this heuristic stops being sound and must be replaced by the explicit failure
 * flag that SecureChatAI PR #1 adds.
 *
 * The heuristic is deliberately **conservative**: both fields must be absent. Discarding a real
 * counselor turn is worse than storing one apology, so a response with a model but no usage
 * accounting is released as an answer.
 */
class ProviderFailure
{
    /**
     * `usage => []` reads as populated, not absent. `formatResponse()` can produce it, and an empty
     * accounting array is still evidence that a call reached a provider - so `?? null` rather than
     * `empty()`, which would throw away answers.
     *
     * @param array<string,mixed> $response the envelope returned by `callAI()`
     */
    public static function looksSanitized(array $response): bool
    {
        return ($response['model'] ?? null) === null && ($response['usage'] ?? null) === null;
    }

    /**
     * The copy of a failed turn that is safe to store.
     *
     * What the participant SEES and what the study STORES diverge here, deliberately:
     *
     *  - The caller keeps showing the apology. A failed turn that renders nothing leaves the
     *    participant looking at a dead composer, which is the exact shape of two defects already
     *    fixed on this branch (`f43695a`, `c98b161`).
     *  - The stored row keeps `response` - so it is still a *turn* row - but with **empty content**.
     *    `TranscriptBuilder` already drops a turn row with no assistant content as "a provider error
     *    or a refusal" (`TranscriptBuilder.php:139-146`), and `MICAQuery::getLogsFor()` already
     *    skips one on restore (`MICAQuery.php:101`). Both behaviours predate this change; this makes
     *    the failed turn take a path that was written for it.
     *  - The apology and the reason it was detected are preserved under `provider_error`, so the row
     *    is evidence of an attempted turn rather than a hole in the log. Nothing reads that key
     *    today; it is there for whoever debugs an outage, and for the day PR #1 replaces the
     *    heuristic with a real error type.
     *
     * Returns a new array - the caller's `$result` is left alone, because the caller still has to
     * return it to the client.
     *
     * @param array<string,mixed> $result the turn as `formatResponse()` built it
     * @param string $reason how the failure was detected, recorded rather than inferred later
     * @return array<string,mixed>
     */
    public static function redactCounselorTurn(array $result, string $reason): array
    {
        $shown = $result['response']['content'] ?? null;

        $result['response'] = [
            'role'    => $result['response']['role'] ?? 'assistant',
            'content' => '',
        ];

        $result['provider_error'] = [
            'detected_by'          => $reason,
            'shown_to_participant' => is_string($shown) ? $shown : null,
        ];

        return $result;
    }
}
