<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\CanonicalJson;
use Stanford\MICA\FindingThresholds;
use Stanford\MICA\FindingWriter;
use Stanford\MICA\SchemaValidator;
use Stanford\MICA\ScanRunner;
use Stanford\MICA\Tests\Support\FakeScanResultStore;
use Stanford\MICA\Tests\Support\FakeTranscriptStore;
use Stanford\MICA\Tests\Support\StubSafetyScanCaller;

/**
 * Stage 4's central claim, tested from as many angles as it has: **no failure of any kind may come
 * out of here looking like a clean screen.**
 *
 * The subtle ones are the schema-VALID failures - `unable_to_assess` and `citation_mismatch` - since
 * a transport error is obvious and those two arrive shaped exactly like a negative result.
 */
#[CoversClass(ScanRunner::class)]
final class ScanRunnerTest extends TestCase
{
    private const HANDOFF = __DIR__ . '/../../handoff';
    private const SAID = 'I have been drinking more and some nights I think about not waking up.';

    private FakeTranscriptStore $transcripts;
    private FakeScanResultStore $results;
    /** @var string[] */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->transcripts = new FakeTranscriptStore();
        $this->results = new FakeScanResultStore();
        $this->logs = [];
    }

    /** A finalized transcript row, stored exactly as TranscriptFinalizer stores one. */
    private function storeTranscript(?string $tamperedHash = null): int
    {
        $payload = [
            'schema_version'          => '1.0',
            'session_id_pseudonymous' => str_repeat('a', 32),
            'session_type'            => 'baseline',
            'setting'                 => 'emergency_department',
            'transcript_finalized'    => true,
            'session_started_at'      => null,
            'session_ended_at'        => null,
            'messages'                => [
                ['message_id' => 'L1', 'sequence' => 1, 'speaker_role' => 'participant', 'content' => self::SAID],
                ['message_id' => 'L2', 'sequence' => 2, 'speaker_role' => 'mica', 'content' => 'Tell me more.'],
            ],
        ];

        $canonical = CanonicalJson::encode($payload);

        return $this->transcripts->writeTranscript(
            CanonicalJson::asLogParameters(CanonicalJson::chunk($canonical)) + [
                'record'            => '2',
                'session_type'      => 'baseline',
                'instance'          => 1,
                'version'           => 1,
                'transcript_sha256' => $tamperedHash ?? CanonicalJson::hash($canonical),
            ]
        );
    }

    private function job(int $transcriptLogId, int $attempts = 0): array
    {
        return [
            'id'             => 7,
            'project_id'     => '257',
            'record'         => '2',
            'instance'       => 1,
            'event_id'       => 1008,
            'session_type'   => 'baseline',
            'transcript_ref' => $transcriptLogId,
            'attempts'       => $attempts,
            'status'         => 'scanning',
        ];
    }

    private function runner(StubSafetyScanCaller $caller, ?FindingThresholds $thresholds = null): ScanRunner
    {
        $registry = new ArtifactRegistry(self::HANDOFF);

        return new ScanRunner(
            $registry,
            new SchemaValidator($registry),
            $this->transcripts,
            $caller,
            $this->results,
            new FindingWriter($this->results),
            'gemini-2.5-flash',
            '9.9.9+abc1234',
            null,
            function (string $m): void {
                $this->logs[] = $m;
            },
            $thresholds
        );
    }

    /** @param array<string,mixed> $overrides */
    private function finding(array $overrides = []): array
    {
        return $overrides + [
            'finding_index'                    => 1,
            'source_role'                      => 'participant',
            'concern_type'                     => 'self_harm',
            'urgency'                          => 'critical',
            'finding_summary'                  => 'passive suicidal ideation',
            'evidence'                         => [[
                'message_id'   => 'L1',
                'speaker_role' => 'participant',
                'exact_quote'  => 'some nights I think about not waking up',
            ]],
            'recommended_actions'              => ['ra_review', 'alert_pi_or_protocol_lead'],
            'recommended_notification_targets' => ['research_assistant'],
            'confidence'                       => 0.88,
        ];
    }

    private function scanOutput(string $result, string $urgency, array $findings = []): array
    {
        return [
            'scan_result'      => $result,
            'overall_urgency'  => $urgency,
            'findings'         => $findings,
            'review_summary'   => 'summary text',
            'model_confidence' => 0.9,
        ];
    }

    // ---------------------------------------------------------------- happy paths

    public function testACleanScanIsOkWithNoFindings(): void
    {
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('no_supported_concern', 'none'))
        );

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertTrue($outcome->isOk());
        $this->assertTrue($outcome->isClean(), 'the model said no_supported_concern in as many words');
        $this->assertSame(0, $outcome->findingsWritten);
        $this->assertSame([], $this->results->findingWrites, 'nothing to write, and that is fine');
    }

    public function testAFindingIsWrittenAsAReviewInstance(): void
    {
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$this->finding()]))
        );

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertTrue($outcome->isOk());
        $this->assertFalse($outcome->isClean(), 'findings_present is not a clean screen');
        $this->assertSame(1, $outcome->findingsWritten);

        $written = $this->results->findingWrites[0]['fields'];
        $this->assertSame('self_harm', $written['finding_concern_type']);
        $this->assertSame('critical', $written['finding_urgency']);
        $this->assertSame('pending', $written['review_status'], 'a model finding is not a decision');
        $this->assertSame('0', $written['review_lock_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $written['finding_id']);
    }

    public function testTheRecommendedNotificationIsStoredButNotActedOn(): void
    {
        // The heart of the design the study asked about: the model emits a recommendation, and it is
        // recorded for an RA to act on. Nothing here notifies anyone, and review_status stays
        // pending - the model proposes, a human disposes.
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$this->finding()]))
        );

        $this->runner($caller)->run($this->job($logId));

        $written = $this->results->findingWrites[0]['fields'];
        $this->assertSame('["ra_review","alert_pi_or_protocol_lead"]', $written['finding_rec_actions_json']);
        $this->assertSame('["research_assistant"]', $written['finding_rec_targets_json']);
        $this->assertSame('pending', $written['review_status']);
        $this->assertArrayNotHasKey('action_delivery_status', $written, 'no action is initiated here');
    }

    public function testEvidenceIsStoredWholeSoTheDashboardCanHighlightIt(): void
    {
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'high', [$this->finding()]))
        );

        $this->runner($caller)->run($this->job($logId));

        $evidence = json_decode($this->results->findingWrites[0]['fields']['finding_evidence_json'], true);

        $this->assertSame('L1', $evidence[0]['message_id'], 'needed to jump to the evidence');
        $this->assertSame('participant', $evidence[0]['speaker_role']);
        $this->assertStringContainsString('not waking up', $evidence[0]['exact_quote']);
    }

    public function testTheEventFromTheJobIsWhereFindingsAreWritten(): void
    {
        // The assertion whose absence let a real bug ship: ScanRunner reads $job['event_id'], and
        // mica_scan_job had no such property, so every finding would have gone to event 0.
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'high', [$this->finding()]))
        );

        $this->runner($caller)->run($this->job($logId));

        $this->assertSame(1008, $this->results->findingWrites[0]['event_id']);
    }

    public function testMultipleFindingsGetConsecutiveInstancesAndDistinctIds(): void
    {
        $logId = $this->storeTranscript();
        $this->results->nextInstance = 5;

        $caller = new StubSafetyScanCaller(StubSafetyScanCaller::ok($this->scanOutput(
            'findings_present',
            'critical',
            [$this->finding(), $this->finding(['finding_index' => 2, 'concern_type' => 'dangerous_alcohol_use'])]
        )));

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertSame(2, $outcome->findingsWritten);
        $this->assertSame([5, 6], array_column($this->results->findingWrites, 'instance'));

        $ids = array_column(array_column($this->results->findingWrites, 'fields'), 'finding_id');
        $this->assertCount(2, array_unique($ids));
    }

    // ------------------------------------------------------- the schema-valid failures

    public function testUnableToAssessIsNotACleanScreen(): void
    {
        // The most dangerous branch in Stage 4. `unable_to_assess` is a VALID scan_result with an
        // empty findings list - byte-identical in shape to no_supported_concern. Treating it as ok
        // is the easiest way to build a silent negative screen into this pipeline.
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('unable_to_assess', 'none'))
        );

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertFalse($outcome->isOk());
        $this->assertFalse($outcome->isClean());
        $this->assertSame('refusal', $outcome->runStatus, 'terminal - retrying does not fix it');
        $this->assertStringContainsString('NOT a negative screen', $outcome->error);
        $this->assertStringContainsString('needs a human read', $outcome->error);
        $this->assertSame([], $this->results->findingWrites);
    }

    public function testAnUnverifiableQuoteFailsTheWholeScan(): void
    {
        $logId = $this->storeTranscript();
        $bad = $this->finding(['evidence' => [[
            'message_id'   => 'L1',
            'speaker_role' => 'participant',
            'exact_quote'  => 'I have a gun in the house',
        ]]]);

        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$bad]))
        );

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertSame('citation_mismatch', $outcome->runStatus);
        $this->assertNotSame([], $outcome->problems, 'the reviewer needs to know which quote');
        $this->assertSame([], $this->results->findingWrites, 'nothing released');
    }

    public function testOneUnverifiableQuoteBlocksTheVerifiedFindingsToo(): void
    {
        // Releasing the verified subset would publish a partial picture as a complete one.
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(StubSafetyScanCaller::ok($this->scanOutput(
            'findings_present',
            'critical',
            [
                $this->finding(),
                $this->finding(['finding_index' => 2, 'evidence' => [[
                    'message_id'   => 'L1',
                    'speaker_role' => 'participant',
                    'exact_quote'  => 'entirely invented',
                ]]]),
            ]
        )));

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertSame('citation_mismatch', $outcome->runStatus);
        $this->assertSame([], $this->results->findingWrites);
    }

    public function testSchemaInvalidOutputIsRefused(): void
    {
        $logId = $this->storeTranscript();
        $broken = $this->scanOutput('findings_present', 'critical', [
            $this->finding(['urgency' => 'catastrophic']),   // not in the enum
        ]);

        $outcome = $this->runner(new StubSafetyScanCaller(StubSafetyScanCaller::ok($broken)))
            ->run($this->job($logId));

        $this->assertSame('schema_invalid', $outcome->runStatus);
        $this->assertStringContainsString('pinned output schema', $outcome->error);
        $this->assertSame([], $this->results->findingWrites);
    }

    public function testFindingsWithNoReviewInstrumentFailRatherThanRelease(): void
    {
        // PID 257 today (audit G5). The job must NOT reach review with findings that live only in a
        // JSON blob on an entity row no reviewer looks at.
        $logId = $this->storeTranscript();
        $this->results->instrumentExists = false;

        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$this->finding()]))
        );

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertFalse($outcome->isOk());
        $this->assertStringContainsString('mica_safety_finding', $outcome->error);
        $this->assertStringContainsString('nowhere for an RA to review', $outcome->error);

        // And the output is still preserved, which is what makes refusing safe.
        $this->assertNotSame([], $this->results->runs);
        $this->assertStringContainsString('self_harm', $this->results->lastRun()['model_output_json']);
    }

    public function testACleanScanStillSucceedsWithoutTheReviewInstrument(): void
    {
        // Nothing to write, so the missing instrument cannot cost anything. A study whose every
        // clean session failed for a table it does not need would learn to ignore the failures.
        $logId = $this->storeTranscript();
        $this->results->instrumentExists = false;

        $outcome = $this->runner(new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('no_supported_concern', 'none'))
        ))->run($this->job($logId));

        $this->assertTrue($outcome->isClean());
    }

    // ----------------------------------------------------------- transport failures

    #[DataProvider('transportFailures')]
    public function testEveryTransportFailureIsRecordedAndReleasesNothing(string $runStatus): void
    {
        $logId = $this->storeTranscript();

        $outcome = $this->runner(new StubSafetyScanCaller(StubSafetyScanCaller::failed($runStatus)))
            ->run($this->job($logId));

        $this->assertSame($runStatus, $outcome->runStatus);
        $this->assertFalse($outcome->isClean());
        $this->assertSame([], $outcome->findings, 'a failed attempt has no findings, ever');
        $this->assertSame([], $this->results->findingWrites);
        $this->assertCount(1, $this->results->runs, 'every attempt leaves a row');
    }

    public static function transportFailures(): array
    {
        return array_map(
            static fn(string $s): array => [$s],
            ['timeout', 'refusal', 'invalid_json', 'content_filter', 'service_error']
        );
    }

    // ------------------------------------------------------------ transcript integrity

    public function testATamperedTranscriptIsNotScanned(): void
    {
        // Scanning it would produce findings about a conversation nobody vouched for, and quote
        // verification against corrupt text would fail in a way that looks like a model fault.
        $logId = $this->storeTranscript(str_repeat('f', 64));
        $caller = new StubSafetyScanCaller(StubSafetyScanCaller::ok($this->scanOutput('no_supported_concern', 'none')));

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertSame('service_error', $outcome->runStatus);
        $this->assertStringContainsString('does not match the hash', $outcome->error);
        $this->assertSame([], $caller->calls, 'the model must never see it');
    }

    public function testAMissingTranscriptIsNotScanned(): void
    {
        $caller = new StubSafetyScanCaller(StubSafetyScanCaller::ok($this->scanOutput('no_supported_concern', 'none')));

        $outcome = $this->runner($caller)->run($this->job(999999));

        $this->assertSame('service_error', $outcome->runStatus);
        $this->assertStringContainsString('could not be read', $outcome->error);
        $this->assertSame([], $caller->calls);
    }

    // --------------------------------------------------------------- the run row

    public function testTheRunRowRecordsWhatActuallyProducedTheResult(): void
    {
        $logId = $this->storeTranscript();
        $registry = new ArtifactRegistry(self::HANDOFF);

        $this->runner(new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('no_supported_concern', 'none'))
        ))->run($this->job($logId, 2));

        $run = $this->results->lastRun();

        $this->assertSame(7, $run['job_id']);
        $this->assertSame(3, $run['attempt'], 'attempts + 1');
        $this->assertSame('gemini-2.5-flash', $run['model_alias']);
        $this->assertSame('gemini-2.5-flash-002', $run['resolved_model'], 'the exact deployment');
        $this->assertSame('9.9.9+abc1234', $run['app_version']);
        $this->assertSame(1234, $run['latency_ms']);
        $this->assertSame('ok', $run['run_status']);

        // Hashes of what was used, from the registry - not copied from the manifest.
        $this->assertSame($registry->getHash('safetyscan_prompt'), $run['prompt_sha256']);
        $this->assertSame($registry->getHash('safetyscan_input_schema'), $run['input_schema_sha256']);
        $this->assertSame($registry->getHash('safetyscan_output_schema'), $run['output_schema_sha256']);
    }

    public function testAFailedRunRowStillCarriesWhateverTheModelSaid(): void
    {
        // A citation_mismatch row with no output cannot be reviewed - the RA needs to see what was
        // claimed before deciding what to do about it.
        $logId = $this->storeTranscript();
        $bad = $this->finding(['evidence' => [[
            'message_id'   => 'L1',
            'speaker_role' => 'participant',
            'exact_quote'  => 'invented quote',
        ]]]);

        $this->runner(new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$bad]))
        ))->run($this->job($logId));

        $payload = json_decode($this->results->lastRun()['model_output_json'], true);

        $this->assertSame('citation_mismatch', $this->results->lastRun()['run_status']);
        $this->assertSame('findings_present', $payload['model_output']['scan_result']);
        $this->assertNotNull($payload['error']);
    }

    public function testTheRunRowSaysWhetherStructuredOutputWasActuallySent(): void
    {
        // Without this, an invalid_json failure caused by SecureChatAI dropping json_schema for a
        // non-OpenAI alias is indistinguishable from a model that simply answered badly.
        $logId = $this->storeTranscript();

        $this->runner(new StubSafetyScanCaller(
            StubSafetyScanCaller::failed('invalid_json', 'prose, not JSON', false)
        ))->run($this->job($logId));

        $payload = json_decode($this->results->lastRun()['model_output_json'], true);

        $this->assertFalse($payload['schema_was_sent']);
    }

    // --------------------------------------------------------------- the request

    public function testThePinnedPromptAndSchemaAreWhatGetSent(): void
    {
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(StubSafetyScanCaller::ok($this->scanOutput('no_supported_concern', 'none')));

        $this->runner($caller)->run($this->job($logId));

        $registry = new ArtifactRegistry(self::HANDOFF);

        $this->assertSame($registry->getText('safetyscan_prompt'), $caller->calls[0]['systemPrompt']);
        $this->assertSame($registry->getJson('safetyscan_output_schema'), $caller->calls[0]['schema']);
        $this->assertSame('gemini-2.5-flash', $caller->calls[0]['modelAlias']);
    }

    public function testTheTranscriptSentIsTheOneThatWasStored(): void
    {
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(StubSafetyScanCaller::ok($this->scanOutput('no_supported_concern', 'none')));

        $this->runner($caller)->run($this->job($logId));

        $sent = json_decode($caller->calls[0]['transcript'], true);

        $this->assertSame(self::SAID, $sent['messages'][0]['content'], 'verbatim, for quote checks');
        $this->assertTrue($sent['transcript_finalized']);
    }

    public function testNoRecordIdIsSentToTheModel(): void
    {
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(StubSafetyScanCaller::ok($this->scanOutput('no_supported_concern', 'none')));

        $this->runner($caller)->run($this->job($logId));

        $this->assertStringNotContainsString('"record"', $caller->calls[0]['transcript']);
        $this->assertStringContainsString('session_id_pseudonymous', $caller->calls[0]['transcript']);
    }

    public function testNoParticipantTextInTheLogLines(): void
    {
        $logId = $this->storeTranscript();

        $this->runner(new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$this->finding()]))
        ))->run($this->job($logId));

        foreach ($this->logs as $line) {
            $this->assertStringNotContainsString('waking up', $line);
            $this->assertStringNotContainsString('drinking', $line);
        }
    }

    // ---------------------------------------------------------------- post-scan thresholds

    /**
     * The filter decides what an RA works through, never what the model was asked or what is stored.
     *
     * These are the integration half: FindingThresholdsTest covers the decision itself exhaustively.
     */
    public function testAFilteredFindingIsNotWrittenButIsStillOnTheRunRow(): void
    {
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'moderate', [
                $this->finding(['finding_index' => 1, 'concern_type' => 'privacy', 'urgency' => 'moderate']),
            ]))
        );

        $outcome = $this->runner($caller, new FindingThresholds('high'))->run($this->job($logId));

        $this->assertSame('ok', $outcome->runStatus);
        $this->assertSame(0, $outcome->findingsWritten, 'a filtered finding reached the queue');

        // The model's full answer is still there, so a filtered finding is recoverable in full.
        $payload = json_decode($this->results->runs[0]['model_output_json'], true);
        $this->assertCount(1, $payload['model_output']['findings']);
        $this->assertSame('privacy', $payload['model_output']['findings'][0]['concern_type']);

        // And the row says what was held back, and under which rule.
        $this->assertSame('high', $payload['thresholds']['minimum_urgency']);
        $this->assertCount(1, $payload['filtered']);
        $this->assertStringContainsString('below', $payload['filtered'][0]['reason']);
    }

    public function testAnUnconfiguredProjectLeavesTheRunRowUntouched(): void
    {
        // Only written when something was actually filtered, so diffing two runs stays meaningful.
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$this->finding()]))
        );

        $this->runner($caller)->run($this->job($logId));

        $payload = json_decode($this->results->runs[0]['model_output_json'], true);
        $this->assertArrayNotHasKey('thresholds', $payload);
        $this->assertArrayNotHasKey('filtered', $payload);
    }

    public function testTheFilterCannotHoldBackALifeSafetyFinding(): void
    {
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'quality', [
                $this->finding(['concern_type' => 'self_harm', 'urgency' => 'quality']),
            ]))
        );

        // Everything a study could set, pointed at it.
        $outcome = $this->runner($caller, new FindingThresholds('high', ['self_harm']))
            ->run($this->job($logId));

        $this->assertSame(1, $outcome->findingsWritten);
    }

    public function testAScanWhoseOnlyFindingsWereFilteredDoesNotNeedTheInstrument(): void
    {
        // The look-ahead uses the ADMITTED list: a scan with nothing to release must not fail for
        // want of an instrument it was never going to write to.
        $logId = $this->storeTranscript();
        $this->results->instrumentExists = false;
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'moderate', [
                $this->finding(['concern_type' => 'privacy', 'urgency' => 'moderate']),
            ]))
        );

        $outcome = $this->runner($caller, new FindingThresholds('high'))->run($this->job($logId));

        $this->assertSame('ok', $outcome->runStatus, $outcome->error ?? '');
        $this->assertSame(0, $outcome->findingsWritten);
    }

    public function testFilteringIsLoggedSoAShortQueueIsExplainable(): void
    {
        $logId = $this->storeTranscript();
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'moderate', [
                $this->finding(['finding_index' => 1, 'concern_type' => 'privacy', 'urgency' => 'moderate']),
                $this->finding(['finding_index' => 2, 'concern_type' => 'self_harm', 'urgency' => 'critical']),
            ]))
        );

        $this->runner($caller, new FindingThresholds('high'))->run($this->job($logId));

        $this->assertNotEmpty(array_filter(
            $this->logs,
            static fn(string $m): bool => str_contains($m, '1 of 2 finding(s) held back')
        ));
    }

    // ---------------------------------------------------------------- duplicate release

    /**
     * A settled job re-run by hand must not release a second set of findings.
     *
     * Nothing in the write path dedupes - FindingWriter appends at nextFindingInstance() - so before
     * this guard a re-queued job doubled the queue: every finding twice, under a different
     * finding_scan_run, and an RA dispositioning the same disclosure twice.
     */
    public function testAJobThatAlreadyReleasedFindingsDoesNotReleaseThemAgain(): void
    {
        $logId = $this->storeTranscript();
        $this->results->releasedJobs = [7];
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$this->finding()]))
        );

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertSame(0, $outcome->findingsWritten, 'a second set was released');
        $this->assertSame([], $this->results->findingWrites, 'nothing should reach the instrument');
    }

    public function testItStaysOkSoNoScanFailurePlaceholderIsWritten(): void
    {
        // The scan succeeded; it is the RELEASE that was refused. Calling it a failure would write a
        // placeholder saying the session was never screened, which is false - and would move the job
        // to manual_review_required when it already has findings waiting.
        $logId = $this->storeTranscript();
        $this->results->releasedJobs = [7];
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$this->finding()]))
        );

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertSame('ok', $outcome->runStatus);
        $this->assertFalse($outcome->terminal);
    }

    public function testItSaysWhyRatherThanLookingLikeACleanScreen(): void
    {
        // `ok` with zero findings is the exact shape of a clean screen, so the reason has to be
        // readable from both the job row and the run row.
        $logId = $this->storeTranscript();
        $this->results->releasedJobs = [7];
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'high', [$this->finding()]))
        );

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertStringContainsString('already released', (string) $outcome->error);
        $this->assertStringContainsString('first release', (string) $outcome->error);

        $payload = json_decode($this->results->runs[0]['model_output_json'], true);
        $this->assertTrue($payload['duplicate_release_prevented']);
        // The model's answer is still preserved verbatim, so the second opinion is not lost.
        $this->assertSame('findings_present', $payload['model_output']['scan_result']);

        $this->assertNotEmpty(array_filter(
            $this->logs,
            static fn(string $m): bool => str_contains($m, 'already released findings')
        ));
    }

    public function testAFirstRunIsUnaffected(): void
    {
        // The guard must not touch the normal path, which is every run the cron actually makes.
        $logId = $this->storeTranscript();
        $this->results->releasedJobs = [];
        $caller = new StubSafetyScanCaller(
            StubSafetyScanCaller::ok($this->scanOutput('findings_present', 'critical', [$this->finding()]))
        );

        $outcome = $this->runner($caller)->run($this->job($logId));

        $this->assertSame(1, $outcome->findingsWritten);
        $payload = json_decode($this->results->runs[0]['model_output_json'], true);
        $this->assertArrayNotHasKey('duplicate_release_prevented', $payload);
    }
}
