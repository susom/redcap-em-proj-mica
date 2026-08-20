<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\SchemaValidator;
use Stanford\MICA\TranscriptBuilder;
use Stanford\MICA\TranscriptException;

/**
 * These are the tests that decide whether the scanner sees the conversation that happened.
 *
 * Every fixture below is the *real* shape MICA writes, read out of the running instance rather than
 * from the data model - the two differ, and the difference is the whole reason this class exists
 * (see the class comment on TranscriptBuilder).
 */
#[CoversClass(TranscriptBuilder::class)]
final class TranscriptBuilderTest extends TestCase
{
    private const TZ = 'America/Los_Angeles';

    private function builder(): TranscriptBuilder
    {
        return new TranscriptBuilder(new \DateTimeZone(self::TZ));
    }

    /** A standalone participant row, exactly as MICA.php logs it before the model call. */
    private function participantRow(int $logId, string $content, string $ts = '2026-08-19 17:00:00'): array
    {
        return [
            'log_id'    => $logId,
            'timestamp' => $ts,
            'message'   => json_encode(['role' => 'user', 'content' => $content]),
        ];
    }

    /** A turn row, exactly as MICA.php logs it after formatResponse(). */
    private function turnRow(
        int $logId,
        string $query,
        ?string $response,
        string $ts = '2026-08-19 17:00:05'
    ): array {
        return [
            'log_id'    => $logId,
            'timestamp' => $ts,
            'message'   => json_encode([
                'response' => ['role' => 'assistant', 'content' => $response],
                'id'       => null,
                'model'    => 'claude-opus-4-7',
                'usage'    => ['prompt_tokens' => 18, 'completion_tokens' => 27],
                'user_id'  => '1',
                'query'    => ['role' => 'user', 'content' => $query],
            ]),
        ];
    }

    private function build(array $rows): array
    {
        return $this->builder()->build($rows, str_repeat('a', 32), 'baseline', 'emergency_department');
    }

    public function testOneTurnBecomesTwoMessagesInOrder(): void
    {
        $payload = $this->build([
            $this->participantRow(1727, 'hello i am ihab'),
            $this->turnRow(1729, 'hello i am ihab', 'Hello Ihab! Nice to meet you.'),
        ]);

        $this->assertCount(2, $payload['messages']);

        $this->assertSame('L1727', $payload['messages'][0]['message_id']);
        $this->assertSame('participant', $payload['messages'][0]['speaker_role']);
        $this->assertSame('hello i am ihab', $payload['messages'][0]['content']);
        $this->assertSame(1, $payload['messages'][0]['sequence']);

        $this->assertSame('L1729', $payload['messages'][1]['message_id']);
        $this->assertSame('mica', $payload['messages'][1]['speaker_role']);
        $this->assertSame('Hello Ihab! Nice to meet you.', $payload['messages'][1]['content']);
        $this->assertSame(2, $payload['messages'][1]['sequence']);
    }

    public function testTheParticipantMessageIsNotCountedTwice(): void
    {
        // The turn row carries `query.content` as well, which is the same words. Taking it from
        // both rows would double every participant utterance in the transcript.
        $payload = $this->build([
            $this->participantRow(1, 'I have been drinking more lately'),
            $this->turnRow(2, 'I have been drinking more lately', 'Thank you for telling me.'),
        ]);

        $contents = array_column($payload['messages'], 'content');

        $this->assertSame(
            1,
            count(array_keys($contents, 'I have been drinking more lately', true)),
            'the participant said it once'
        );
    }

    public function testAParticipantMessageSurvivesAFailedTurn(): void
    {
        // The single most important behaviour in this class. MICA logs the participant's message
        // BEFORE calling the model, so a timeout/refusal/exception leaves the standalone row and no
        // turn row. MICAQuery::getLogsFor() skips rows with no assistant content, so reusing it
        // would drop the message entirely - and a disclosure that got no reply is exactly what the
        // post-session scan exists to catch.
        $payload = $this->build([
            $this->participantRow(10, 'I have been thinking about hurting myself'),
            // no turn row at all - the model call threw
        ]);

        $this->assertCount(1, $payload['messages']);
        $this->assertSame('participant', $payload['messages'][0]['speaker_role']);
        $this->assertStringContainsString('hurting myself', $payload['messages'][0]['content']);
    }

    public function testAnEmptyAssistantReplyDropsOnlyTheCounselorHalf(): void
    {
        $payload = $this->build([
            $this->participantRow(10, 'something important'),
            $this->turnRow(11, 'something important', ''),
        ]);

        $this->assertCount(1, $payload['messages'], 'content has minLength 1; an empty reply is not a message');
        $this->assertSame('participant', $payload['messages'][0]['speaker_role']);
        $this->assertArrayHasKey('11', $this->builderSkips([
            $this->participantRow(10, 'something important'),
            $this->turnRow(11, 'something important', ''),
        ]));
    }

    public function testANullAssistantReplyIsAlsoDropped(): void
    {
        $payload = $this->build([
            $this->participantRow(10, 'something important'),
            $this->turnRow(11, 'something important', null),
        ]);

        $this->assertCount(1, $payload['messages']);
    }

    public function testNonMessageRowsAreSkippedAndRecorded(): void
    {
        // `record added to its randomized arm` is a real row in this project's log.
        $rows = [
            ['log_id' => 1708, 'timestamp' => '2026-08-19 15:00:00', 'message' => 'record added to its randomized arm'],
            $this->participantRow(1727, 'hi'),
        ];

        $builder = $this->builder();
        $payload = $builder->build($rows, str_repeat('a', 32), 'baseline', 'emergency_department');

        $this->assertCount(1, $payload['messages']);
        $this->assertArrayHasKey('1708', $builder->skippedRows());
        $this->assertStringContainsString('not a JSON object', $builder->skippedRows()['1708']);
    }

    public function testASystemContextRowIsNotGuessedIntoTheTranscript(): void
    {
        $rows = [
            [
                'log_id'    => 5,
                'timestamp' => '2026-08-19 16:00:00',
                'message'   => json_encode(['role' => 'system', 'content' => 'You are MICA...']),
            ],
            $this->participantRow(6, 'hello'),
        ];

        $builder = $this->builder();
        $payload = $builder->build($rows, str_repeat('a', 32), 'baseline', 'emergency_department');

        $this->assertCount(1, $payload['messages'], 'the prompt is not something the participant said');
        $this->assertStringContainsString('unrecognised row shape', $builder->skippedRows()['5']);
    }

    public function testNoMessagesAtAllIsAnExceptionNotAnEmptyTranscript(): void
    {
        // minItems: 1. Queueing a scan of nothing would produce a "no supported concern" result
        // about a conversation that was never read - a negative screen by accident.
        try {
            $this->build([
                ['log_id' => 1, 'timestamp' => '2026-08-19 16:00:00', 'message' => 'not json'],
            ]);
            $this->fail('an empty transcript must not be finalized');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('No messages could be read', $e->getMessage());
            $this->assertStringContainsString('no scan is queued', $e->getMessage());
            $this->assertStringContainsString('1', $e->getMessage(), 'name the rows examined');
        }
    }

    public function testContentIsCarriedVerbatim(): void
    {
        // Stage 4 verifies every evidence quote as a byte-exact substring of this string. Trimming,
        // entity-decoding or smart-quote conversion here becomes a citation_mismatch there, which
        // reads as a model fault.
        $raw = "  leading and trailing  \n\ttabs & <b>markup</b> and \"curly\" 'quotes' — em dash  ";

        $payload = $this->build([$this->participantRow(1, $raw)]);

        $this->assertSame($raw, $payload['messages'][0]['content']);
    }

    public function testOversizeContentFailsRatherThanTruncating(): void
    {
        $tooLong = str_repeat('x', TranscriptBuilder::MAX_CONTENT + 1);

        try {
            $this->build([$this->participantRow(42, $tooLong)]);
            $this->fail('a silent trim could cut mid-disclosure');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('L42', $e->getMessage(), 'say which message');
            $this->assertStringContainsString('exact quotes', $e->getMessage());
            $this->assertStringContainsString('human decision', $e->getMessage());
        }
    }

    public function testContentAtExactlyTheLimitIsAccepted(): void
    {
        $payload = $this->build([$this->participantRow(1, str_repeat('x', TranscriptBuilder::MAX_CONTENT))]);

        $this->assertCount(1, $payload['messages']);
    }

    public function testTimestampsAreRfc3339WithAnOffset(): void
    {
        // opis asserts `format: date-time` rather than treating it as an annotation, and RFC 3339
        // requires an offset - a bare "2026-08-19T17:00:28" is rejected.
        $payload = $this->build([$this->participantRow(1, 'hi', '2026-08-19 17:00:28')]);

        $this->assertSame('2026-08-19T17:00:28-07:00', $payload['messages'][0]['timestamp']);
    }

    public function testAnUnparseableTimestampDoesNotCostTheMessage(): void
    {
        $payload = $this->build([$this->participantRow(1, 'hi', 'not a date')]);

        $this->assertCount(1, $payload['messages'], 'the words matter more than the clock');
        $this->assertNull($payload['messages'][0]['timestamp'], 'the schema allows null');
    }

    public function testSessionBoundsComeFromTheFirstAndLastMessage(): void
    {
        $payload = $this->build([
            $this->participantRow(1, 'first', '2026-08-19 17:00:00'),
            $this->turnRow(2, 'first', 'reply', '2026-08-19 17:00:04'),
            $this->participantRow(3, 'second', '2026-08-19 17:12:31'),
        ]);

        $this->assertSame('2026-08-19T17:00:00-07:00', $payload['session_started_at']);
        $this->assertSame('2026-08-19T17:12:31-07:00', $payload['session_ended_at']);
    }

    public function testSequenceIsContiguousEvenWhenRowsAreSkipped(): void
    {
        // A gap in `sequence` would be read as a missing message.
        $payload = $this->build([
            $this->participantRow(1, 'a'),
            ['log_id' => 2, 'timestamp' => '2026-08-19 17:00:01', 'message' => 'junk'],
            $this->turnRow(3, 'a', 'b'),
            $this->participantRow(4, 'c'),
        ]);

        $this->assertSame([1, 2, 3], array_column($payload['messages'], 'sequence'));
    }

    public function testTranscriptFinalizedIsAlwaysTrue(): void
    {
        // The schema pins it to const true - it is what makes a transcript scannable.
        $payload = $this->build([$this->participantRow(1, 'hi')]);

        $this->assertTrue($payload['transcript_finalized']);
        $this->assertSame('1.0', $payload['schema_version']);
    }

    public function testMaxLogIdIsTheSessionBoundaryForTheNextSession(): void
    {
        $rows = [$this->participantRow(1727, 'a'), $this->turnRow(1729, 'a', 'b')];

        $this->assertSame(1729, TranscriptBuilder::maxLogId($rows));
        $this->assertSame(0, TranscriptBuilder::maxLogId([]), 'no rows means start from the beginning');
    }

    public function testARetriedTurnRepeatsTheParticipantMessageUnderDistinctIds(): void
    {
        // Documented consequence rather than a bug: a corrective retry re-logs the participant
        // message. Quote verification resolves by message_id, so both remain individually citable.
        $payload = $this->build([
            $this->participantRow(1, 'same words'),
            $this->turnRow(2, 'same words', ''),
            $this->participantRow(3, 'same words'),
            $this->turnRow(4, 'same words', 'ok'),
        ]);

        $ids = array_column($payload['messages'], 'message_id');

        $this->assertSame(['L1', 'L3', 'L4'], $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'message_id must stay unique');
    }

    public function testTheBuiltPayloadValidatesAgainstThePinnedInputSchema(): void
    {
        // The end-to-end contract: whatever this class builds must satisfy the hash-pinned schema
        // from the handoff package, not just our reading of it.
        $payload = $this->build([
            $this->participantRow(1, 'I drank more than I meant to on Friday'),
            $this->turnRow(2, 'I drank more than I meant to on Friday', 'That sounds hard. Tell me more.'),
            $this->participantRow(3, 'caña 日本語 🙂 and a "quote"'),
        ]);

        $validator = new SchemaValidator(new ArtifactRegistry(__DIR__ . '/../../handoff'));
        $result = $validator->validate($payload, 'safetyscan_input_schema');

        $this->assertTrue($result->isValid(), 'schema errors: ' . implode('; ', $result->errors()));
    }

    public function testAWrongSpeakerRoleWouldBeCaughtBySchemaValidation(): void
    {
        // Guards the test above from passing vacuously: prove the validator bites on this payload.
        $payload = $this->build([$this->participantRow(1, 'hi')]);
        $payload['messages'][0]['speaker_role'] = 'assistant';   // not in the enum; `mica` is

        $validator = new SchemaValidator(new ArtifactRegistry(__DIR__ . '/../../handoff'));

        $this->assertFalse($validator->validate($payload, 'safetyscan_input_schema')->isValid());
    }

    /** @return array<string,string> */
    private function builderSkips(array $rows): array
    {
        $builder = $this->builder();
        $builder->build($rows, str_repeat('a', 32), 'baseline', 'emergency_department');

        return $builder->skippedRows();
    }
}
