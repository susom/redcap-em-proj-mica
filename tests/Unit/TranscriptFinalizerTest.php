<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\CanonicalJson;
use Stanford\MICA\ScanJobStateMachine as SM;
use Stanford\MICA\ScanQueue;
use Stanford\MICA\SchemaValidator;
use Stanford\MICA\Tests\Support\FakeScanQueueStore;
use Stanford\MICA\Tests\Support\FakeTranscriptStore;
use Stanford\MICA\TranscriptBuilder;
use Stanford\MICA\TranscriptException;
use Stanford\MICA\TranscriptFinalizer;

/**
 * The A→B bridge. These tests are about two things only: does the scanner get the whole
 * conversation, and can anything leave a half-finalized session behind.
 */
#[CoversClass(TranscriptFinalizer::class)]
final class TranscriptFinalizerTest extends TestCase
{
    private const SALT = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const NOW = 1_700_000_000;

    private FakeTranscriptStore $store;
    private FakeScanQueueStore $jobs;
    /** @var string[] */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->store = new FakeTranscriptStore();
        $this->jobs = new FakeScanQueueStore();
        $this->logs = [];
    }

    private function finalizer(): TranscriptFinalizer
    {
        $registry = new ArtifactRegistry(__DIR__ . '/../../handoff');

        return new TranscriptFinalizer(
            $this->store,
            new TranscriptBuilder(new \DateTimeZone('America/Los_Angeles')),
            new SchemaValidator($registry),
            new ScanQueue($this->jobs, new SM(3), static fn(): int => self::NOW),
            self::SALT,
            static fn(): int => self::NOW,
            function (string $m): void {
                $this->logs[] = $m;
            }
        );
    }

    private function finalize(): \Stanford\MICA\FinalizeResult
    {
        return $this->finalizer()->finalize(
            '257',
            '2',
            '2',
            1,
            1008,
            'baseline',
            'emergency_department'
        );
    }

    private function aConversation(): void
    {
        $this->store->addParticipantMessage('I drank more than I meant to', '2026-08-19 17:00:00');
        $this->store->addTurn('I drank more than I meant to', 'Tell me about that.', '2026-08-19 17:00:06');
        $this->store->addParticipantMessage('It was a rough week', '2026-08-19 17:01:00');
        $this->store->addTurn('It was a rough week', 'What made it rough?', '2026-08-19 17:01:07');
    }

    public function testFinalizeWritesATranscriptAndQueuesExactlyOneScan(): void
    {
        $this->aConversation();

        $result = $this->finalize();

        $this->assertCount(1, $this->store->transcripts);
        $this->assertCount(1, $this->jobs->jobs);
        $this->assertTrue($result->jobCreated);
        $this->assertSame(4, $result->messageCount);
        $this->assertSame(1, $result->version);
        $this->assertStringStartsWith('T', $result->transcriptRef);
        $this->assertSame([], $result->warnings);
    }

    public function testTheQueuedJobPointsAtTheTranscriptItWasBuiltFrom(): void
    {
        $this->aConversation();
        $result = $this->finalize();

        $job = $this->jobs->findJob($result->jobId);

        $this->assertSame($result->transcriptLogId, (int) $job['transcript_ref']);
        $this->assertSame(
            ScanQueue::idempotencyKey('257', '2', 'baseline', 1, 1, $result->transcriptSha256),
            $job['idempotency_key'],
            'the job is keyed to the exact bytes that were stored'
        );
    }

    public function testTheStoredPayloadRehashesToTheRecordedHash(): void
    {
        // The end-to-end integrity claim: what is in the log re-hashes to what the job was keyed on.
        $this->aConversation();
        $result = $this->finalize();

        $row = $this->store->transcripts[0];
        $rejoined = CanonicalJson::fromLogParameters($row);

        $this->assertSame($result->transcriptSha256, CanonicalJson::hash($rejoined));
        $this->assertSame($result->transcriptSha256, $row['transcript_sha256']);
    }

    public function testTheStoredPayloadIsStillSchemaValidAfterTheRoundTrip(): void
    {
        $this->aConversation();
        $this->finalize();

        $decoded = json_decode(CanonicalJson::fromLogParameters($this->store->transcripts[0]), true);
        $validator = new SchemaValidator(new ArtifactRegistry(__DIR__ . '/../../handoff'));

        $this->assertTrue($validator->validate($decoded, 'safetyscan_input_schema')->isValid());
    }

    public function testNoRecordIdReachesTheScannerPayload(): void
    {
        // The handoff's rule: do not intentionally send direct identifiers. The record id is the
        // key the study team joins on, so it must not be anywhere in the payload.
        $this->store->addParticipantMessage('hello');
        $this->finalizer()->finalize('257', '104729', '104729', 1, 1008, 'baseline', 'emergency_department');

        $stored = CanonicalJson::fromLogParameters($this->store->transcripts[0]);

        $this->assertStringNotContainsString('104729', $stored);
    }

    public function testFinalizingTwiceQueuesOneScanButRecordsTwoVersions(): void
    {
        // Not the double-click case (that is the same version) - this is finalize called again after
        // more messages arrived, which is a genuinely new session for the slot.
        $this->aConversation();
        $first = $this->finalize();

        $this->store->addParticipantMessage('a later message', '2026-08-19 18:00:00');
        $second = $this->finalize();

        $this->assertSame(2, $second->version);
        $this->assertNotSame($first->transcriptSha256, $second->transcriptSha256);
        $this->assertCount(2, $this->jobs->jobs, 'a second session is a second scan');
    }

    public function testTheSecondSessionOnlyContainsMessagesAfterTheFirst(): void
    {
        // The session boundary, which is the whole reason max_message_log_id is on the row. Without
        // it every session would re-scan the entire history and every finding would be reported
        // again.
        $this->aConversation();
        $this->finalize();

        $this->store->addParticipantMessage('only this one is new', '2026-08-19 18:00:00');
        $second = $this->finalize();

        $this->assertSame(1, $second->messageCount);

        $decoded = json_decode(CanonicalJson::fromLogParameters($this->store->transcripts[1]), true);
        $this->assertSame('only this one is new', $decoded['messages'][0]['content']);
    }

    public function testTheBoundaryIsRecordedOnTheRow(): void
    {
        $this->aConversation();
        $result = $this->finalize();

        $this->assertSame(
            $result->maxMessageLogId,
            (int) $this->store->transcripts[0]['max_message_log_id'],
            'the next session needs to read this off the row, not recompute it'
        );
    }

    public function testARepeatFinalizeIsAnIdempotentSuccess(): void
    {
        // The double-clicked End Session. Raising "no messages could be read" here would be true
        // and useless - the session IS finalized - and telling the participant it failed sends them
        // round the loop again.
        $this->aConversation();
        $first = $this->finalize();

        $second = $this->finalize();

        $this->assertSame($first->transcriptLogId, $second->transcriptLogId, 'the same transcript');
        $this->assertSame($first->version, $second->version, 'no new version');
        $this->assertSame($first->jobId, $second->jobId);
        $this->assertFalse($second->jobCreated);
        $this->assertCount(1, $this->store->transcripts, 'one session, one transcript');
        $this->assertCount(1, $this->jobs->jobs, 'one session, one scan');
        $this->assertSame([], $second->warnings, 'a plain repeat is not a warning');
    }

    public function testARepeatFinalizeRecoversATranscriptThatWasNeverQueued(): void
    {
        // The state a crash leaves behind: transcript row written, scan job not. This actually
        // happened on the first live run (redcap_entity was not loaded on the survey request), and
        // it is unrecoverable without this path - the boundary has already advanced past every
        // message the transcript contains, so every retry would report an empty session and the
        // participant's conversation would never be scanned.
        $this->aConversation();
        $first = $this->finalize();

        $this->jobs->jobs = [];   // the job was lost / never written

        $recovered = $this->finalize();

        $this->assertSame($first->transcriptLogId, $recovered->transcriptLogId);
        $this->assertTrue($recovered->jobCreated, 'the missing scan must be queued');
        $this->assertCount(1, $this->jobs->jobs);
        $this->assertCount(1, $this->store->transcripts, 'and no duplicate transcript');
        $this->assertStringContainsString('had no scan queued', $recovered->warnings[0]);
        $this->assertStringContainsString('recovered', implode("\n", $this->logs));
    }

    public function testFinalizingASessionWithNoMessagesAtAllIsRefused(): void
    {
        // Genuinely nothing to finalize - no transcript and no messages. Distinct from the repeat
        // case above: there is nothing to be idempotent about, and queueing a scan of nothing would
        // produce a "no supported concern" result about a conversation that never happened.
        $this->expectException(TranscriptException::class);
        $this->expectExceptionMessageMatches('/No messages could be read/');

        $this->finalize();
    }

    public function testNothingIsQueuedWhenTheTranscriptCannotBeBuilt(): void
    {
        // Fail-closed: a partial finalize is worse than none. Nothing written, nothing queued.
        $this->store->addParticipantMessage(str_repeat('x', TranscriptBuilder::MAX_CONTENT + 1));

        try {
            $this->finalize();
            $this->fail('an oversize message must stop the finalize');
        } catch (TranscriptException) {
            // expected
        }

        $this->assertSame([], $this->store->transcripts);
        $this->assertSame([], $this->jobs->jobs, 'no scan may be queued for a transcript that failed');
        $this->assertSame([], $this->store->sessionWrites, 'and the form must not say finalized');
    }

    public function testAFailedTranscriptWriteQueuesNothing(): void
    {
        $this->aConversation();
        $this->store->transcriptWriteFails = true;

        try {
            $this->finalize();
            $this->fail('a scan job pointing at a transcript that was never written is unusable');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('nothing partial', strtolower($e->getMessage()));
        }

        $this->assertSame([], $this->jobs->jobs);
    }

    public function testTheScanIsQueuedBeforeTheSessionFormIsTouched(): void
    {
        // Ordering matters: the scanner reads the EM-log row, not the form. A dictionary problem
        // must not be able to stop a transcript being scanned.
        $this->aConversation();
        $this->store->dictionary = [];

        $result = $this->finalize();

        $this->assertCount(1, $this->jobs->jobs);
        $this->assertTrue($result->hasWarnings());
        $this->assertStringContainsString('mica_transcript_ref', $result->warnings[0]);
        $this->assertStringContainsString('scan is queued', $result->warnings[0]);
    }

    public function testMissingFieldsAreNamedIndividually(): void
    {
        $this->aConversation();
        // Today's PID 257: the transcript fields do not exist yet (audit G4), but suppose status did.
        $this->store->dictionary = ['mica_session_status'];

        $result = $this->finalize();

        $this->assertStringContainsString('mica_transcript_ref', $result->warnings[0]);
        $this->assertStringContainsString('mica_transcript_hash', $result->warnings[0]);
        $this->assertStringNotContainsString('mica_session_status,', $result->warnings[0]);

        // The fields that DO exist are still written - a partial dictionary is not a reason to
        // write nothing.
        $this->assertCount(1, $this->store->sessionWrites);
        $this->assertSame(['mica_session_status' => 'finalized'], $this->store->sessionWrites[0]['fields']);
    }

    public function testASessionFormWriteFailureIsAWarningNotALostScan(): void
    {
        $this->aConversation();
        $this->store->sessionWriteError = 'saveData rejected the record';

        $result = $this->finalize();

        $this->assertCount(1, $this->jobs->jobs, 'the transcript is scanned regardless');
        $this->assertTrue($result->hasWarnings());
        $this->assertStringContainsString('saveData rejected', $result->warnings[0]);
    }

    public function testSessionFormWriteBackCarriesTheRefAndHash(): void
    {
        $this->aConversation();
        $result = $this->finalize();

        $written = $this->store->sessionWrites[0]['fields'];

        $this->assertSame('finalized', $written['mica_session_status']);
        $this->assertSame($result->transcriptRef, $written['mica_transcript_ref']);
        $this->assertSame($result->transcriptSha256, $written['mica_transcript_hash']);
        $this->assertSame(1, $this->store->sessionWrites[0]['instance'], 'repeating instruments');
    }

    public function testRefinalizeCreatesANewVersionAndANewJob(): void
    {
        $this->aConversation();
        $original = $this->finalize();

        $result = $this->finalizer()->refinalize(
            '257',
            '2',
            '2',
            1,
            1008,
            'baseline',
            'emergency_department',
            'admin_user'
        );

        $this->assertSame(2, $result->version);
        $this->assertCount(2, $this->jobs->jobs, 'a correction must be rescanned');
        $this->assertSame(
            $original->transcriptLogId,
            (int) $this->store->transcripts[1]['supersedes_log_id'],
            'the chain has to point back'
        );
    }

    public function testRefinalizeRereadsTheWholeSessionNotJustWhatCameAfter(): void
    {
        // The bug this guards: reading from the superseded version's own high-water mark would
        // produce a "correction" containing almost nothing.
        $this->aConversation();
        $original = $this->finalize();

        $result = $this->finalizer()->refinalize(
            '257',
            '2',
            '2',
            1,
            1008,
            'baseline',
            'emergency_department',
            'admin_user'
        );

        $this->assertSame(
            $original->messageCount,
            $result->messageCount,
            'a refinalize of the same conversation must contain the same messages'
        );
        $this->assertSame($original->transcriptSha256, $result->transcriptSha256);
    }

    public function testARefinalizeWithIdenticalContentStillQueuesAScan(): void
    {
        // Identical content means an identical hash, so only `version` keeps this from being
        // swallowed as a duplicate. Without it the admin sees the correction accepted and no rescan.
        $this->aConversation();
        $this->finalize();

        $result = $this->finalizer()->refinalize(
            '257',
            '2',
            '2',
            1,
            1008,
            'baseline',
            'emergency_department',
            'admin'
        );

        $this->assertTrue($result->jobCreated);
    }

    public function testRefinalizeRecordsWhoAskedForIt(): void
    {
        $this->aConversation();
        $this->finalize();

        $this->finalizer()->refinalize('257', '2', '2', 1, 1008, 'baseline', 'emergency_department', 'ihabz');

        $this->assertSame('ihabz', $this->store->transcripts[1]['finalized_by']);
        $this->assertStringContainsString('ihabz', implode("\n", $this->logs));
    }

    public function testRefinalizeWithNothingToCorrectIsRefused(): void
    {
        $this->aConversation();

        $this->expectException(TranscriptException::class);
        $this->expectExceptionMessageMatches('/no finalized transcript/');

        $this->finalizer()->refinalize('257', '2', '2', 1, 1008, 'baseline', 'emergency_department', 'admin');
    }

    public function testSkippedRowsAreReportedNotHidden(): void
    {
        $this->store->addParticipantMessage('hello');
        $this->store->rows[] = ['log_id' => 9999, 'timestamp' => null, 'message' => 'not json at all'];

        $result = $this->finalize();

        $this->assertArrayHasKey('9999', $result->skippedRows);
    }

    public function testNoParticipantTextInTheLogLines(): void
    {
        $this->aConversation();
        $this->finalize();

        foreach ($this->logs as $line) {
            $this->assertStringNotContainsString('drank', $line);
            $this->assertStringNotContainsString('rough week', $line);
        }
    }

    public function testTheResultForTheWireCarriesNoTranscriptContent(): void
    {
        $this->aConversation();
        $wire = json_encode($this->finalize()->toArray());

        $this->assertStringNotContainsString('drank', $wire);
        $this->assertStringNotContainsString('messages', $wire);
        $this->assertStringContainsString('transcript_ref', $wire);
    }
}
