<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\TranscriptRecordBuilder;
use Stanford\MICA\TranscriptRecordDiff;

/**
 * The rows a coder receives: every statement once, in order, with the Safety Scan's highlights on
 * the statements they cite, across resumed and corrected sessions.
 */
#[CoversClass(TranscriptRecordBuilder::class)]
#[CoversClass(TranscriptRecordDiff::class)]
final class TranscriptRecordBuilderTest extends TestCase
{
    private function builder(): TranscriptRecordBuilder
    {
        return new TranscriptRecordBuilder(new \DateTimeZone('America/Los_Angeles'));
    }

    /** @return array<string,mixed> */
    private function msg(int $logId, string $role, string $content): array
    {
        return [
            'message_id'   => 'L' . $logId,
            'speaker_role' => $role,
            'content'      => $content,
            'timestamp'    => '2026-11-03T22:02:' . sprintf('%02d', $logId % 60) . '+00:00',
        ];
    }

    /** @return array<string,mixed> */
    private function at(string $id, string $role, string $content, string $timestamp): array
    {
        return ['message_id' => $id, 'speaker_role' => $role, 'content' => $content, 'timestamp' => $timestamp];
    }

    /** @return array<string,mixed> */
    private function transcript(int $logId, int $version, int $boundary, array $messages): array
    {
        return ['log_id' => $logId, 'version' => $version, 'supersedes_boundary' => $boundary, 'messages' => $messages];
    }

    /** @return array<string,mixed> */
    private function finding(string $id, string $messageId, string $quote, array $extra = []): array
    {
        return $extra + [
            'finding_id'             => $id,
            'concern_type'           => 'self_harm',
            'urgency'                => 'critical',
            'review_status'          => 'pending',
            'corrected_concern_type' => '',
            'corrected_urgency'      => '',
            'evidence'               => [['message_id' => $messageId, 'exact_quote' => $quote]],
        ];
    }

    /** @param list<array<string,string>> $rows */
    private function column(array $rows, string $field): array
    {
        return array_column($rows, $field);
    }

    public function testOneSessionIsOneRowPerStatementInOrder(): void
    {
        $rows = $this->builder()->build('ed', [
            $this->transcript(900, 1, 0, [
                $this->msg(103, 'mica', 'How are you?'),
                $this->msg(101, 'participant', 'Hi'),
                $this->msg(102, 'mica', 'Hello'),
                $this->msg(104, 'participant', 'Fine'),
                $this->msg(105, 'mica', 'Good'),
            ]),
        ], []);

        self::assertSame(['L101', 'L102', 'L103', 'L104', 'L105'], $this->column($rows, 'tx_message_id'));
        self::assertSame(['1', '2', '3', '4', '5'], $this->column($rows, 'tx_seq'));
        self::assertSame('participant', $rows[0]['tx_speaker']);
        self::assertSame('mica', $rows[1]['tx_speaker']);
        self::assertSame('ed', $rows[0]['tx_session_type']);
        self::assertSame('T900', $rows[0]['tx_transcript_ref']);
        self::assertSame('2', $rows[0]['mica_transcript_complete']);
        self::assertSame('0', $rows[0]['tx_flagged']);
        // 22:02:41 UTC is 14:02:41 in Los Angeles: REDCap's datetime fields are local and offset-free.
        self::assertSame('2026-11-03 14:02:41', $rows[0]['tx_timestamp']);
        self::assertSame(TranscriptRecordBuilder::FIELDS, array_keys($rows[0]));
    }

    public function testAResumedSessionKeepsBothParts(): void
    {
        // finalize() after a return: v2 holds only the messages after v1's high-water mark. The review
        // page shows only v2; the copy must hold both.
        $rows = $this->builder()->build('ed', [
            $this->transcript(900, 1, 0, [$this->msg(101, 'participant', 'a'), $this->msg(102, 'mica', 'b')]),
            $this->transcript(950, 2, 102, [$this->msg(201, 'participant', 'c'), $this->msg(202, 'mica', 'd')]),
        ], []);

        self::assertSame(['L101', 'L102', 'L201', 'L202'], $this->column($rows, 'tx_message_id'));
        self::assertSame(['T900', 'T900', 'T950', 'T950'], $this->column($rows, 'tx_transcript_ref'));
    }

    public function testACorrectionDoesNotDuplicateAndOnlyItsFindingsCount(): void
    {
        // refinalize(): v2 re-reads from v1's boundary, so it holds v1's messages again (plus any new).
        $transcripts = [
            $this->transcript(900, 1, 0, [$this->msg(101, 'participant', 'a'), $this->msg(102, 'mica', 'b')]),
            $this->transcript(960, 2, 0, [
                $this->msg(101, 'participant', 'a'),
                $this->msg(102, 'mica', 'b'),
                $this->msg(103, 'participant', 'c'),
                $this->msg(104, 'mica', 'd'),
            ]),
        ];

        self::assertSame([960], TranscriptRecordBuilder::currentTranscriptIds($transcripts));

        $rows = $this->builder()->build('ed', $transcripts, [
            900 => [$this->finding('old', 'L101', 'a')],
            960 => [$this->finding('new', 'L103', 'c')],
        ]);

        self::assertSame(['L101', 'L102', 'L103', 'L104'], $this->column($rows, 'tx_message_id'));
        self::assertSame(['0', '0', '1', '0'], $this->column($rows, 'tx_flagged'));
        self::assertSame('new', $rows[2]['tx_flag_finding_ids']);
        // Attributed to the transcript it first appeared in.
        self::assertSame('T900', $rows[0]['tx_transcript_ref']);
        self::assertSame('T960', $rows[2]['tx_transcript_ref']);
    }

    public function testACorrectedResumedSessionKeepsThePartsAndTheCurrentFindings(): void
    {
        $transcripts = [
            $this->transcript(900, 1, 0, [$this->msg(101, 'participant', 'a'), $this->msg(102, 'mica', 'b')]),
            $this->transcript(950, 2, 102, [$this->msg(201, 'participant', 'c'), $this->msg(202, 'mica', 'd')]),
            // A correction of v2: same boundary as v2.
            $this->transcript(990, 3, 102, [$this->msg(201, 'participant', 'c'), $this->msg(202, 'mica', 'd')]),
        ];

        self::assertSame([900, 990], TranscriptRecordBuilder::currentTranscriptIds($transcripts));

        $rows = $this->builder()->build('ed', $transcripts, [
            900 => [$this->finding('f1', 'L101', 'a')],
            950 => [$this->finding('stale', 'L201', 'c')],
            990 => [$this->finding('f3', 'L202', 'd')],
        ]);

        self::assertSame(['L101', 'L102', 'L201', 'L202'], $this->column($rows, 'tx_message_id'));
        self::assertSame(['f1', '', '', 'f3'], $this->column($rows, 'tx_flag_finding_ids'));
    }

    public function testAReusedRecordIdDoesNotInheritTheDeletedRecordsSession(): void
    {
        // Record 1 chatted, was deleted, and a new record got ID 1. The finalizer stored the new
        // session as a continuation (boundary after the old one), so both transcripts are in the slot.
        $old = $this->transcript(900, 1, 0, [
            $this->at('L101', 'participant', 'old', '2026-09-22T18:05:27+00:00'),
            $this->at('L102', 'mica', 'old reply', '2026-09-22T18:05:40+00:00'),
        ]);
        $new = $this->transcript(950, 2, 102, [
            $this->at('L201', 'participant', 'new', '2026-11-10T19:15:24+00:00'),
            $this->at('L202', 'mica', 'new reply', '2026-11-10T19:15:30+00:00'),
        ]);
        $deleted = new \DateTimeImmutable('2026-11-10 11:14:02', new \DateTimeZone('America/Los_Angeles'));

        $builder = $this->builder();
        $rows = $builder->build('baseline', [$old, $new], [], $deleted);

        self::assertSame(['L201', 'L202'], $this->column($rows, 'tx_message_id'));
        self::assertSame(['1', '2'], $this->column($rows, 'tx_seq'));
        self::assertSame(2, $builder->droppedBeforeCutoff());

        // No deletion on record: nothing is cut.
        self::assertCount(4, $builder->build('baseline', [$old, $new], []));
        self::assertSame(0, $builder->droppedBeforeCutoff());
    }

    public function testHighlightedStatementsCarryTheQuoteAndTheReviewersDecision(): void
    {
        $rows = $this->builder()->build('booster', [
            $this->transcript(900, 1, 0, [
                $this->msg(101, 'participant', 'I am going to take all of them tonight'),
                $this->msg(102, 'mica', 'I am worried about you'),
            ]),
        ], [
            900 => [
                $this->finding('f1', 'L101', 'take all of them tonight', [
                    'review_status'     => 'confirmed',
                    'corrected_urgency' => 'high',
                ]),
                $this->finding('f2', 'L101', 'tonight', ['concern_type' => 'other', 'urgency' => 'moderate']),
            ],
        ]);

        self::assertSame('1', $rows[0]['tx_flagged']);
        self::assertSame('take all of them tonight | tonight', $rows[0]['tx_flag_quote']);
        self::assertSame('self_harm | other', $rows[0]['tx_flag_concern']);
        // The reviewer's corrected urgency wins over the model's.
        self::assertSame('high | moderate', $rows[0]['tx_flag_urgency']);
        self::assertSame('confirmed | pending', $rows[0]['tx_flag_review']);
        self::assertSame('f1 | f2', $rows[0]['tx_flag_finding_ids']);
        self::assertSame('0', $rows[1]['tx_flagged']);
    }

    public function testAScanFailurePlaceholderFlagsNothing(): void
    {
        $rows = $this->builder()->build('ed', [
            $this->transcript(900, 1, 0, [$this->msg(101, 'participant', 'a'), $this->msg(102, 'mica', 'b')]),
        ], [900 => [$this->finding('fail', 'L101', 'a', ['concern_type' => 'scan_failure'])]]);

        self::assertSame(['0', '0'], $this->column($rows, 'tx_flagged'));
    }

    public function testAnUnansweredMessageGetsANoReplyRow(): void
    {
        // Turn L101 failed (content filter): the participant saw the fallback, MICA logged nothing.
        // They retried as L103, which was answered. The session then ended on an unanswered message.
        $rows = $this->builder()->build('ed', [
            $this->transcript(900, 1, 0, [
                $this->msg(101, 'participant', 'x'),
                $this->msg(103, 'participant', 'x'),
                $this->msg(104, 'mica', 'y'),
                $this->msg(105, 'participant', 'z'),
            ]),
        ], []);

        self::assertSame(
            ['participant', 'no_reply', 'participant', 'mica', 'participant', 'no_reply'],
            $this->column($rows, 'tx_speaker')
        );
        self::assertSame(['1', '2', '3', '4', '5', '6'], $this->column($rows, 'tx_seq'));
        self::assertSame('', $rows[1]['tx_message_id']);
        self::assertSame(TranscriptRecordBuilder::NO_REPLY_TEXT, $rows[1]['tx_text']);
    }

    public function testTextIsVerbatim(): void
    {
        $text = "I'm \"fine\" — really\nsecond line <b>";
        $rows = $this->builder()->build('ed', [
            $this->transcript(900, 1, 0, [$this->msg(101, 'participant', $text), $this->msg(102, 'mica', 'ok')]),
        ], []);

        self::assertSame($text, $rows[0]['tx_text']);
    }

    public function testDiffWritesNothingWhenUnchanged(): void
    {
        $rows = $this->builder()->build('ed', [
            $this->transcript(900, 1, 0, [$this->msg(101, 'participant', "a\nb"), $this->msg(102, 'mica', 'c')]),
        ], []);

        $existing = [1 => $rows[0], 2 => $rows[1]];
        // A notes value saved through a form comes back with CRLF.
        $existing[1]['tx_text'] = "a\r\nb";

        self::assertSame([], TranscriptRecordDiff::changes($rows, $existing));
    }

    public function testDiffWritesChangedAndNewInstancesAndClearsExtras(): void
    {
        $rows = $this->builder()->build('ed', [
            $this->transcript(900, 1, 0, [$this->msg(101, 'participant', 'a'), $this->msg(102, 'mica', 'b')]),
        ], [900 => [$this->finding('f1', 'L101', 'a')]]);

        $existing = [
            1 => array_merge($rows[0], ['tx_flagged' => '0', 'tx_flag_finding_ids' => '']),
            3 => $rows[1],
        ];

        $changes = TranscriptRecordDiff::changes($rows, $existing);

        self::assertSame([1, 2, 3], array_keys($changes));
        self::assertSame($rows[0], $changes[1]);
        self::assertSame($rows[1], $changes[2]);
        self::assertSame('', implode('', $changes[3]));
        self::assertSame(TranscriptRecordBuilder::FIELDS, array_keys($changes[3]));
    }
}
