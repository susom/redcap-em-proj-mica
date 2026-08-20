<?php

namespace Stanford\MICA;

/**
 * Proves every piece of evidence in a scan result is really in the transcript.
 *
 * The handoff's release condition for SafetyScan v1.1 is **100% exact-quote traceability**. What
 * that buys is specific: an RA reading a finding can select the quoted sentence in the transcript
 * and see it. A model that paraphrases, tidies punctuation, or attributes a participant's words to
 * MICA has produced a finding a human cannot check - and an unverifiable finding about self-harm is
 * worse than no finding, because it consumes the attention that a real one needs.
 *
 * So: **byte-exact substring, no normalization at all.** Not case-insensitive, not
 * whitespace-collapsed, not Unicode-normalized, not curly-quote-folded. Each of those would let a
 * quote through that does not literally appear, and each of them is a thing language models do
 * routinely.
 *
 * This is also why TranscriptBuilder stores message content verbatim. The two halves are one
 * contract: whatever tidying happened on the way in would surface here as a citation mismatch that
 * reads like a model fault.
 *
 * `speaker_role` is checked as well as the quote. A correct quote attributed to the wrong speaker is
 * a different clinical claim - "the participant said they wanted to hurt themselves" and "MICA said
 * it" are not the same finding, and only one of them is a safety event.
 */
class QuoteVerifier
{
    /**
     * @param array<string,mixed> $modelOutput decoded, already schema-valid
     * @param array<string,mixed> $transcript  the input-schema payload that was scanned
     * @return list<string> one human-readable problem per failure; empty means every quote verified
     */
    public function verify(array $modelOutput, array $transcript): array
    {
        $messages = $this->indexMessages($transcript);
        $problems = [];

        foreach ($modelOutput['findings'] ?? [] as $i => $finding) {
            $label = 'finding ' . ($finding['finding_index'] ?? $i + 1);

            foreach ($finding['evidence'] ?? [] as $j => $evidence) {
                $problem = $this->verifyOne($evidence, $messages, "$label evidence " . ($j + 1));
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
        }

        return $problems;
    }

    /**
     * @param array<string,array{content:string,speaker_role:string}> $messages
     */
    private function verifyOne(array $evidence, array $messages, string $label): ?string
    {
        $messageId = (string) ($evidence['message_id'] ?? '');
        $quote = (string) ($evidence['exact_quote'] ?? '');
        $role = (string) ($evidence['speaker_role'] ?? '');

        if (!isset($messages[$messageId])) {
            // A cited message that is not in the transcript. Either the model invented an id or it
            // is quoting a conversation it was not given - both mean the finding cannot be checked.
            return sprintf(
                '%s cites message_id "%s", which is not in this transcript (it has %d message(s): %s)',
                $label,
                $messageId,
                count($messages),
                $this->summariseIds(array_keys($messages))
            );
        }

        $message = $messages[$messageId];

        if ($message['speaker_role'] !== $role) {
            return sprintf(
                '%s attributes %s to "%s", but that message is from "%s". A correct quote against '
                . 'the wrong speaker is a different clinical claim, not a formatting slip.',
                $label,
                $messageId,
                $role,
                $message['speaker_role']
            );
        }

        // Checked before the substring test, because `str_contains($anything, '')` is TRUE in PHP -
        // so an empty quote would verify against every message in the transcript. The output schema
        // says minLength 1 and validation runs first, but this is the release gate: it does not get
        // to assume an earlier check happened.
        if ($quote === '') {
            return sprintf('%s has an empty exact_quote, which cannot be evidence of anything.', $label);
        }

        // The whole point. A substring test, not any comparison that normalises.
        if (!str_contains($message['content'], $quote)) {
            return sprintf(
                '%s quotes text that does not appear byte-for-byte in %s. %s',
                $label,
                $messageId,
                $this->diagnose($message['content'], $quote)
            );
        }

        return null;
    }

    /**
     * Say *how* a near-miss missed. Not for the model's benefit - for the person reading the
     * manual-review task, who otherwise sees "citation mismatch" and cannot tell a paraphrase from
     * a smart-quote substitution from a genuinely fabricated quote. The three call for very
     * different responses.
     */
    private function diagnose(string $content, string $quote): string
    {
        if (stripos($content, $quote) !== false) {
            return 'It matches only if case is ignored - the transcript is stored verbatim, so this '
                 . 'is the model re-casing what was said.';
        }

        $collapse = static fn(string $s): string => preg_replace('/\s+/u', ' ', trim($s)) ?? $s;
        if (str_contains($collapse($content), $collapse($quote))) {
            return 'It matches only if whitespace is collapsed - the model reflowed the text.';
        }

        // Curly quotes, en/em dashes and the ellipsis character are the substitutions that show up
        // most; naming them saves a reviewer from hunting for an invisible difference.
        $fold = static fn(string $s): string => str_replace(
            ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2026}"],
            ["'", "'", '"', '"', '-', '-', '...'],
            $s
        );
        if (str_contains($fold($content), $fold($quote))) {
            return 'It matches only after folding typographic punctuation (curly quotes, dashes or '
                 . 'an ellipsis character) - the model rewrote the punctuation.';
        }

        if (str_contains($quote, '...') || str_contains($quote, "\u{2026}")) {
            return 'The quote contains an ellipsis, so it is an abridgement rather than a quotation.';
        }

        // How much of the quote's *start* really appears in the message, which separates "the model
        // began quoting and then drifted into its own words" from "the model made this up".
        //
        // Deliberately not a character-by-character comparison of the two strings from index 0: a
        // quote is a substring from somewhere in the middle of a message, so aligning at byte 0
        // diverges immediately and reported every paraphrase as a fabrication. Found by testing.
        $shared = $this->longestMatchingPrefix($content, $quote);

        return $shared > 8
            ? sprintf(
                'Its first %d bytes appear in that message and the rest does not - the model began '
                . 'quoting and then continued in its own words.',
                $shared
            )
            : 'No substantial part of it appears in that message.';
    }

    /**
     * Length of the longest prefix of $quote that appears anywhere in $haystack.
     *
     * Binary search rather than a linear walk: a prefix of length n appearing implies every shorter
     * prefix appears, so the predicate is monotonic. Quotes cap at 800 bytes and messages at 12,000,
     * so this is ~10 substring searches instead of up to 800 - and this runs inside a cron pass that
     * has a wall-clock budget.
     */
    private function longestMatchingPrefix(string $haystack, string $quote): int
    {
        $low = 0;
        $high = strlen($quote);

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if (str_contains($haystack, substr($quote, 0, $mid))) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    /**
     * @param array<string,mixed> $transcript
     * @return array<string,array{content:string,speaker_role:string}>
     */
    private function indexMessages(array $transcript): array
    {
        $indexed = [];

        foreach ($transcript['messages'] ?? [] as $message) {
            $id = (string) ($message['message_id'] ?? '');
            if ($id === '') {
                continue;
            }

            // First occurrence wins. TranscriptBuilder guarantees unique ids (one per log_id), and
            // silently preferring a later duplicate would make verification depend on ordering.
            $indexed[$id] ??= [
                'content'      => (string) ($message['content'] ?? ''),
                'speaker_role' => (string) ($message['speaker_role'] ?? ''),
            ];
        }

        return $indexed;
    }

    /** @param string[] $ids */
    private function summariseIds(array $ids): string
    {
        if (count($ids) <= 6) {
            return implode(', ', $ids);
        }

        return implode(', ', array_slice($ids, 0, 3)) . ' … ' . implode(', ', array_slice($ids, -2));
    }
}
