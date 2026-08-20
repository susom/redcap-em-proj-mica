<?php

namespace Stanford\MICA;

require_once __DIR__ . "/TranscriptException.php";

/**
 * Turns MICA's EM-log message rows into a SafetyScan input-schema-v1 payload.
 *
 * Pure: rows in, payload out, no REDCap. Everything that could go wrong about *which* words end up
 * in front of the scanner is decided here, where the suite can reach it.
 *
 * ## How MICA's message store is actually shaped
 *
 * Read from the real thing rather than from the data model, because they differ. `MICA.php`'s
 * `callAI` writes **two** log rows per turn:
 *
 *   1. before the model call, the participant's message alone: `{"role":"user","content":"..."}`
 *   2. after it returns, the whole turn: `{"query":{...},"response":{...},"model":...,"usage":...}`
 *
 * So the participant's words appear twice - once in row 1, once inside row 2's `query`. This class
 * takes participant messages **only from row 1** and MICA messages **only from row 2's
 * `response.content`**, which gets three things at once:
 *
 *   - every message maps to exactly one `log_id`, so `message_id = "L<log_id>"` stays unique and
 *     unambiguous, as 02-data-model.md §2 specifies and as Stage 4's quote verification needs;
 *   - no message is counted twice;
 *   - **a participant message whose turn failed is still in the transcript.** Row 1 is written
 *     before the model call, so it survives a timeout, a refusal or an exception. Reusing
 *     `MICAQuery::getLogsFor()` would have dropped it - that method skips any row with no assistant
 *     content - and a disclosure that got no reply is precisely what a post-session safety scan
 *     exists to catch.
 *
 * Known consequence, not a bug: a corrective retry re-logs the participant's message as a new row,
 * so a retried turn can appear as the same participant text under two `message_id`s. Quote
 * verification resolves by `message_id`, so it stays correct; a reader of the RA dashboard sees the
 * repetition, which is honest about what happened.
 */
class TranscriptBuilder
{
    public const SCHEMA_VERSION = '1.0';

    /** messages[].content maxLength in the pinned input schema. */
    public const MAX_CONTENT = 12000;

    private \DateTimeZone $timezone;

    /** @var array<string,string> log_id => reason, for rows that produced no message */
    private array $skipped = [];

    public function __construct(?\DateTimeZone $timezone = null)
    {
        // Injected so the suite is not at the mercy of the host's php.ini. The value matters
        // because it lands in the payload and therefore in the transcript hash.
        $this->timezone = $timezone ?? new \DateTimeZone(date_default_timezone_get());
    }

    /**
     * @param list<array{log_id:int|string,timestamp:?string,message:?string}> $rows
     *        MICAQuery log rows for one session, ascending by log_id.
     * @return array the input-schema payload, ready for SchemaValidator and CanonicalJson
     * @throws TranscriptException
     */
    public function build(array $rows, string $pseudoId, string $sessionType, string $setting): array
    {
        $this->skipped = [];

        $messages = [];
        foreach ($rows as $row) {
            foreach ($this->messagesFromRow($row) as $message) {
                $message['sequence'] = count($messages) + 1;
                $messages[] = $message;
            }
        }

        if ($messages === []) {
            // minItems: 1. A session with no messages is not a transcript, and enqueueing a scan of
            // one would produce a "no supported concern" finding about nothing at all.
            throw new TranscriptException(sprintf(
                'No messages could be read for this session (%d log row(s) examined). Nothing is '
                . 'finalized and no scan is queued. Skipped rows: %s',
                count($rows),
                $this->skipped === [] ? 'none' : json_encode($this->skipped)
            ));
        }

        $timestamps = array_values(array_filter(array_column($messages, 'timestamp')));

        return [
            'schema_version'          => self::SCHEMA_VERSION,
            'session_id_pseudonymous' => $pseudoId,
            'session_type'            => $sessionType,
            'setting'                 => $setting,
            'session_started_at'      => $timestamps === [] ? null : $timestamps[0],
            'session_ended_at'        => $timestamps === [] ? null : end($timestamps),
            'transcript_finalized'    => true,
            'messages'                => $messages,
        ];
    }

    /** @return array<string,string> log_id => why it produced no message, from the last build() */
    public function skippedRows(): array
    {
        return $this->skipped;
    }

    /**
     * The highest log_id seen, which the transcript row records so the *next* session knows where
     * it starts. Monotonic, immune to clock skew, and needs no field that PID 257 does not have.
     *
     * @param list<array{log_id:int|string,timestamp:?string,message:?string}> $rows
     */
    public static function maxLogId(array $rows): int
    {
        $ids = array_map(static fn(array $row): int => (int) $row['log_id'], $rows);

        return $ids === [] ? 0 : max($ids);
    }

    /**
     * @param array{log_id:int|string,timestamp:?string,message:?string} $row
     * @return list<array<string,mixed>> zero or one message
     */
    private function messagesFromRow(array $row): array
    {
        $logId = (string) $row['log_id'];
        $decoded = json_decode((string) ($row['message'] ?? ''), true);

        if (!is_array($decoded)) {
            // Not every MICA log row is a message - `record added to its randomized arm` is a plain
            // string, for one. Recorded rather than ignored so a finalize that quietly saw fewer
            // messages than expected leaves evidence.
            $this->skipped[$logId] = 'message is not a JSON object';
            return [];
        }

        // A turn row. Take the counselor's reply; its `query` is deliberately ignored (see above).
        if (isset($decoded['response'])) {
            $content = $decoded['response']['content'] ?? null;

            if (!is_string($content) || $content === '') {
                // content has minLength: 1. This is a turn that produced no usable text - a
                // provider error or a refusal. Drop the empty counselor message; the participant's
                // message from the preceding row stays, which is the half that matters.
                $this->skipped[$logId] = 'turn row has no assistant content (failed or refused turn)';
                return [];
            }

            return [$this->message($logId, 'mica', $content, $row['timestamp'] ?? null)];
        }

        // A standalone participant row.
        if (($decoded['role'] ?? null) === 'user') {
            $content = $decoded['content'] ?? null;

            if (!is_string($content) || $content === '') {
                $this->skipped[$logId] = 'participant row has empty content';
                return [];
            }

            return [$this->message($logId, 'participant', $content, $row['timestamp'] ?? null)];
        }

        // A system-context row, or any other shape. `other` exists in the schema's speaker_role
        // enum, but guessing that an unrecognised row is a conversational message is worse than
        // recording that it was not understood.
        $this->skipped[$logId] = 'unrecognised row shape (role=' . json_encode($decoded['role'] ?? null) . ')';

        return [];
    }

    /** @return array<string,mixed> */
    private function message(string $logId, string $role, string $content, ?string $timestamp): array
    {
        if (strlen($content) > self::MAX_CONTENT) {
            // Never a silent trim (06-implementation-plan/README.md, cross-cutting decision 3). A
            // truncated message could cut mid-disclosure, and Stage 4's quote verification requires
            // byte-exact substrings of what is stored here - so a trim would also make every quote
            // from the tail of that message fail as a citation mismatch.
            throw new TranscriptException(sprintf(
                'Message L%s is %d bytes, over the input schema\'s %d-byte limit. The transcript '
                . 'is not finalized and no scan is queued: truncating could cut mid-disclosure, and '
                . 'the scanner requires exact quotes of the stored text. This needs a human '
                . 'decision, not an automatic trim.',
                $logId,
                strlen($content),
                self::MAX_CONTENT
            ));
        }

        return [
            'message_id'   => 'L' . $logId,
            'speaker_role' => $role,
            // Verbatim. Not trimmed, not entity-decoded, not normalised: Stage 4 verifies each
            // evidence quote as a byte-exact substring of this string, so any tidying here shows up
            // there as a citation mismatch that looks like a model fault.
            'content'      => $content,
            'timestamp'    => $this->iso8601($timestamp),
        ];
    }

    /**
     * The log row's `timestamp` column is a MySQL datetime in server-local time; the schema wants
     * an RFC 3339 date-time, and opis *asserts* `format: date-time` rather than treating it as an
     * annotation, so an offset is required - a bare "2026-08-19T17:00:28" is rejected.
     */
    private function iso8601(?string $timestamp): ?string
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($timestamp, $this->timezone))
                ->format(\DateTimeInterface::RFC3339);
        } catch (\Exception) {
            // The schema allows a null timestamp, and an unparseable one is genuinely unknown.
            // Losing a timestamp must not cost the message it belongs to.
            return null;
        }
    }
}
