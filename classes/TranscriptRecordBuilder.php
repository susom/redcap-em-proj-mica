<?php

namespace Stanford\MICA;

/**
 * Turns one session's finalized transcripts and Safety Scan findings into the rows of the repeating
 * `mica_transcript` instrument: one instance per statement, so a REDCap report exports one row per
 * utterance for coding (docs/topics/safetyscan/33-transcript-export-field.md).
 *
 * Pure: arrays in, rows out, no REDCap. The I/O is RedcapTranscriptRecordSync.
 *
 * ## One slot, several transcripts
 *
 * A session slot (record + event) can hold more than one `mica_transcript` log row:
 *
 *   - a **continuation**: the participant came back after End Session, so finalize() wrote only the
 *     messages after the previous high-water mark (a new `supersedes_boundary`);
 *   - a **correction**: refinalize() re-read from the SAME boundary as the version it replaces.
 *
 * The review page shows only the latest of them, which is why a resumed session pasted from there
 * loses its first half. Here every row's messages are combined and deduped by `message_id`
 * (`L<log_id>`, the same in a continuation and a correction), then ordered by that log id. The set
 * only grows - message rows are append-only - so a statement's instance number does not move.
 *
 * Findings are different: a correction's scan replaces the corrected version's, so only findings
 * from transcripts that no correction replaced are used (currentTranscriptIds()).
 *
 * ## A statement nobody answered
 *
 * A turn that fails (content filter, timeout, provider error) logs the participant's message and no
 * reply. The participant was shown an error or apology instead, which is not logged. A `no_reply`
 * row is inserted after any participant message not followed by a MICA message, so a coder sees the
 * gap instead of two participant messages that look like one turn. A finalized transcript is a
 * closed conversation, so a last participant message with no reply is treated the same way.
 *
 * ## A record ID used twice
 *
 * Deleting a record leaves its EM log rows and scan jobs behind, and REDCap's auto-numbering can give
 * the same ID to the next record created. The finalizer then treats the new record's first session
 * as a continuation of the deleted record's, so without a cut-off the new record's transcript would
 * open with somebody else's conversation. The sync passes the record's latest deletion time as
 * `$notBefore`, and every message logged before it is left out.
 */
final class TranscriptRecordBuilder
{
    public const FORM = 'mica_transcript';
    public const COMPLETE_FIELD = 'mica_transcript_complete';

    /** Every field this class writes, in instrument order. The sync reads and compares exactly these. */
    public const FIELDS = [
        'tx_session_type',
        'tx_seq',
        'tx_message_id',
        'tx_speaker',
        'tx_timestamp',
        'tx_text',
        'tx_flagged',
        'tx_flag_quote',
        'tx_flag_concern',
        'tx_flag_urgency',
        'tx_flag_review',
        'tx_flag_finding_ids',
        'tx_transcript_ref',
        self::COMPLETE_FIELD,
    ];

    public const SPEAKER_PARTICIPANT = 'participant';
    public const SPEAKER_MICA = 'mica';
    public const SPEAKER_NO_REPLY = 'no_reply';

    /**
     * Only the fact. What the participant saw instead depends on how the turn failed: a provider
     * failure returns SecureChatAI's canned apology to the chat (MICA.php, `provider_error`), other
     * failures may show something else, and none of it is logged.
     */
    public const NO_REPLY_TEXT = '[No MICA reply was logged for the message above.]';

    /** Placeholder findings for a scan that did not complete: they cite no statement. */
    private const SCAN_FAILURE = 'scan_failure';

    /** Several findings on one statement are joined with this, in the same order in every column. */
    private const JOIN = ' | ';

    private \DateTimeZone $timezone;

    /** Messages the last build() left out because they predate the record's deletion. */
    private int $droppedBeforeCutoff = 0;

    public function __construct(?\DateTimeZone $timezone = null)
    {
        // The timestamps are stored RFC 3339 with an offset; REDCap's datetime validation wants a
        // bare local Y-m-d H:i:s, so they are converted to the server's zone.
        $this->timezone = $timezone ?? new \DateTimeZone(date_default_timezone_get());
    }

    /**
     * @param list<array<string,mixed>> $transcripts
     *        Every transcript row of ONE slot: log_id, version, supersedes_boundary, and messages
     *        (the hash-verified payload's messages).
     * @param array<int,list<array<string,mixed>>> $findingsByTranscript
     *        transcript log_id => findings of that transcript's latest scan run. Each finding:
     *        finding_id, concern_type, urgency, review_status, corrected_concern_type,
     *        corrected_urgency, evidence (list of {message_id, exact_quote}).
     * @param \DateTimeImmutable|null $notBefore
     *        The record's latest deletion, if its ID was used before: messages logged earlier belong
     *        to the deleted record and are left out.
     * @return list<array<string,string>> index 0 is instance 1
     */
    public function build(
        string $sessionType,
        array $transcripts,
        array $findingsByTranscript,
        ?\DateTimeImmutable $notBefore = null
    ): array {
        $messages = $this->mergedMessages($transcripts);
        $this->droppedBeforeCutoff = 0;

        if ($notBefore !== null) {
            $kept = [];
            foreach ($messages as $m) {
                $at = $this->parse($m['timestamp'] ?? null);
                // A message with no readable time cannot be shown to be the deleted record's, so it
                // stays. MICA always logs one; this is for the schema's nullable timestamp.
                if ($at !== null && $at < $notBefore) {
                    $this->droppedBeforeCutoff++;
                    continue;
                }
                $kept[] = $m;
            }
            $messages = $kept;
        }
        $flags = $this->flagsByMessage($transcripts, $findingsByTranscript);

        $rows = [];
        foreach ($messages as $i => $message) {
            $id = (string) $message['message_id'];
            $speaker = $message['speaker_role'] === 'participant'
                ? self::SPEAKER_PARTICIPANT
                : self::SPEAKER_MICA;
            $flag = $flags[$id] ?? null;

            $rows[] = $this->row($sessionType, count($rows) + 1, [
                'tx_message_id'       => $id,
                'tx_speaker'          => $speaker,
                'tx_timestamp'        => $this->localTime($message['timestamp'] ?? null),
                'tx_text'             => (string) $message['content'],
                'tx_flagged'          => $flag === null ? '0' : '1',
                'tx_flag_quote'       => $flag === null ? '' : implode(self::JOIN, $flag['quotes']),
                'tx_flag_concern'     => $flag === null ? '' : implode(self::JOIN, $flag['concerns']),
                'tx_flag_urgency'     => $flag === null ? '' : implode(self::JOIN, $flag['urgencies']),
                'tx_flag_review'      => $flag === null ? '' : implode(self::JOIN, $flag['reviews']),
                'tx_flag_finding_ids' => $flag === null ? '' : implode(self::JOIN, $flag['ids']),
                'tx_transcript_ref'   => 'T' . $message['__transcript'],
            ]);

            $next = $messages[$i + 1] ?? null;
            $answered = $next !== null && $next['speaker_role'] !== 'participant';

            if ($speaker === self::SPEAKER_PARTICIPANT && !$answered) {
                $rows[] = $this->row($sessionType, count($rows) + 1, [
                    'tx_message_id'     => '',
                    'tx_speaker'        => self::SPEAKER_NO_REPLY,
                    'tx_timestamp'      => '',
                    'tx_text'           => self::NO_REPLY_TEXT,
                    'tx_flagged'        => '0',
                    'tx_transcript_ref' => 'T' . $message['__transcript'],
                ]);
            }
        }

        return $rows;
    }

    public function droppedBeforeCutoff(): int
    {
        return $this->droppedBeforeCutoff;
    }

    /**
     * Transcripts whose findings still stand: every one that no later correction (same boundary,
     * higher version) replaced. A continuation has a different boundary, so it replaces nothing.
     *
     * @param list<array{log_id:int,version:int,supersedes_boundary:int}> $transcripts
     * @return list<int>
     */
    public static function currentTranscriptIds(array $transcripts): array
    {
        $current = [];

        foreach ($transcripts as $t) {
            $replaced = false;
            foreach ($transcripts as $other) {
                if (
                    (int) $other['supersedes_boundary'] === (int) $t['supersedes_boundary']
                    && (int) $other['version'] > (int) $t['version']
                ) {
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $current[] = (int) $t['log_id'];
            }
        }

        sort($current);

        return $current;
    }

    /**
     * @param list<array<string,mixed>> $transcripts
     * @return list<array<string,mixed>> messages ordered by log id, each tagged with `__transcript`
     */
    private function mergedMessages(array $transcripts): array
    {
        // Oldest transcript first, so a message is attributed to the transcript it first appeared in.
        usort($transcripts, static fn(array $a, array $b): int => (int) $a['log_id'] <=> (int) $b['log_id']);

        $byId = [];
        foreach ($transcripts as $t) {
            foreach ($t['messages'] as $m) {
                $id = (string) ($m['message_id'] ?? '');
                if ($id === '' || isset($byId[$id])) {
                    continue;
                }
                $byId[$id] = $m + ['__transcript' => (int) $t['log_id']];
            }
        }

        uasort($byId, static fn(array $a, array $b): int
            => self::logId((string) $a['message_id']) <=> self::logId((string) $b['message_id']));

        return array_values($byId);
    }

    /**
     * @param list<array<string,mixed>> $transcripts
     * @param array<int,list<array<string,mixed>>> $findingsByTranscript
     * @return array<string,array{ids:list<string>,quotes:list<string>,concerns:list<string>,urgencies:list<string>,reviews:list<string>}>
     */
    private function flagsByMessage(array $transcripts, array $findingsByTranscript): array
    {
        $flags = [];

        foreach (self::currentTranscriptIds($transcripts) as $logId) {
            foreach ($findingsByTranscript[$logId] ?? [] as $finding) {
                if (($finding['concern_type'] ?? '') === self::SCAN_FAILURE) {
                    continue;
                }

                $seen = [];
                foreach ((array) ($finding['evidence'] ?? []) as $evidence) {
                    $messageId = (string) ($evidence['message_id'] ?? '');
                    $quote = (string) ($evidence['exact_quote'] ?? '');
                    if ($messageId === '' || isset($seen[$messageId . "\0" . $quote])) {
                        continue;
                    }
                    $seen[$messageId . "\0" . $quote] = true;

                    // The reviewer's correction wins over the model's call: it is the decision.
                    $concern = trim((string) ($finding['corrected_concern_type'] ?? ''))
                        ?: (string) ($finding['concern_type'] ?? '');
                    $urgency = trim((string) ($finding['corrected_urgency'] ?? ''))
                        ?: (string) ($finding['urgency'] ?? '');

                    $flags[$messageId]['ids'][] = (string) ($finding['finding_id'] ?? '');
                    $flags[$messageId]['quotes'][] = $quote;
                    $flags[$messageId]['concerns'][] = $concern;
                    $flags[$messageId]['urgencies'][] = $urgency;
                    $flags[$messageId]['reviews'][] = (string) ($finding['review_status'] ?? '');
                }
            }
        }

        return $flags;
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private function row(string $sessionType, int $seq, array $values): array
    {
        $row = array_fill_keys(self::FIELDS, '');
        $row['tx_session_type'] = $sessionType;
        $row['tx_seq'] = (string) $seq;
        $row[self::COMPLETE_FIELD] = '2';

        return array_merge($row, $values);
    }

    private function localTime(?string $timestamp): string
    {
        $at = $this->parse($timestamp);

        return $at === null ? '' : $at->setTimezone($this->timezone)->format('Y-m-d H:i:s');
    }

    private function parse(?string $timestamp): ?\DateTimeImmutable
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return null;
        }
    }

    private static function logId(string $messageId): int
    {
        return (int) ltrim($messageId, 'L');
    }
}
