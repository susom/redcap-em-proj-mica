<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\FindingWriter;
use Stanford\MICA\Tests\Support\FakeScanResultStore;
use Stanford\MICA\TranscriptException;

#[CoversClass(FindingWriter::class)]
final class FindingWriterTest extends TestCase
{
    private FakeScanResultStore $store;

    protected function setUp(): void
    {
        $this->store = new FakeScanResultStore();
    }

    private function finding(array $overrides = []): array
    {
        return $overrides + [
            'finding_index'                    => 1,
            'source_role'                      => 'participant',
            'concern_type'                     => 'self_harm',
            'urgency'                          => 'critical',
            'finding_summary'                  => 'passive ideation',
            'evidence'                         => [[
                'message_id'   => 'L1',
                'speaker_role' => 'participant',
                'exact_quote'  => 'not waking up',
            ]],
            'recommended_actions'              => ['ra_review'],
            'recommended_notification_targets' => ['research_assistant'],
            'confidence'                       => 0.9,
        ];
    }

    public function testACleanScanWritesNothingAndThatIsNotAFailure(): void
    {
        // Zero-finding sessions stay visible through the job and the scan_run row; they do not need
        // an instance, and requiring the instrument for them would fail every clean session on a
        // project that has not built it yet.
        $this->store->instrumentExists = false;

        $this->assertSame(0, (new FindingWriter($this->store))->write('257', '2', 1008, 100, []));
        $this->assertSame([], $this->store->findingWrites);
    }

    public function testModelFieldsAreWrittenOnceAndReviewFieldsStartPending(): void
    {
        (new FindingWriter($this->store))->write('257', '2', 1008, 100, [$this->finding()]);

        $fields = $this->store->findingWrites[0]['fields'];

        $this->assertSame('100', $fields['finding_scan_run'], 'provenance back to the run row');
        $this->assertSame('pending', $fields['review_status']);
        $this->assertSame('0', $fields['review_lock_version'], 'optimistic locking starts at 0');
        $this->assertArrayNotHasKey('review_reviewer', $fields, 'nobody has reviewed it');
        $this->assertArrayNotHasKey('review_rationale', $fields);
    }

    public function testNoActionFieldsAreWrittenAtCreation(): void
    {
        // Action fields are only meaningful once an RA confirms. Pre-populating any of them would
        // let the dashboard show a delivery state for something nobody decided.
        (new FindingWriter($this->store))->write('257', '2', 1008, 100, [$this->finding()]);

        foreach (array_keys($this->store->findingWrites[0]['fields']) as $field) {
            $this->assertStringStartsNotWith('action_', $field);
        }
    }

    public function testEachFindingGetsAUniqueId(): void
    {
        $writer = new FindingWriter($this->store);
        $writer->write('257', '2', 1008, 100, [
            $this->finding(),
            $this->finding(['finding_index' => 2]),
            $this->finding(['finding_index' => 3]),
        ]);

        $ids = array_column(array_column($this->store->findingWrites, 'fields'), 'finding_id');

        $this->assertCount(3, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id,
                'a v4 UUID, per the spec requirement'
            );
        }
    }

    public function testAnIdThatAlreadyExistsIsNotReused(): void
    {
        $taken = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->store->existingIds = [$taken];

        $sequence = [$taken, $taken, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];
        $writer = new FindingWriter($this->store, static function () use (&$sequence): string {
            return array_shift($sequence);
        });

        $writer->write('257', '2', 1008, 100, [$this->finding()]);

        $this->assertSame(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            $this->store->findingWrites[0]['fields']['finding_id'],
            'reusing an id would make one finding_id name two findings'
        );
    }

    public function testABrokenIdGeneratorFailsRatherThanLoopingOrColliding(): void
    {
        $this->store->existingIds = ['always-the-same'];
        $writer = new FindingWriter($this->store, static fn(): string => 'always-the-same');

        $this->expectException(TranscriptException::class);
        $this->expectExceptionMessageMatches('/not random/');

        $writer->write('257', '2', 1008, 100, [$this->finding()]);
    }

    public function testTwoFindingsInOneScanCannotShareAnId(): void
    {
        // The collision check has to consider ids minted in this same call, not just ones already
        // in the database.
        $sequence = ['dup', 'dup', 'unique-2'];
        $writer = new FindingWriter($this->store, static function () use (&$sequence): string {
            return array_shift($sequence) ?? 'exhausted';
        });

        $writer->write('257', '2', 1008, 100, [$this->finding(), $this->finding(['finding_index' => 2])]);

        $ids = array_column(array_column($this->store->findingWrites, 'fields'), 'finding_id');
        $this->assertSame(['dup', 'unique-2'], $ids);
    }

    public function testFindingsWithNoInstrumentFailClosed(): void
    {
        $this->store->instrumentExists = false;

        try {
            (new FindingWriter($this->store))->write('257', '2', 1008, 100, [$this->finding()]);
            $this->fail('findings with nowhere to be reviewed must not be reported as written');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('mica_safety_finding', $e->getMessage());
            $this->assertStringContainsString('audit G5', $e->getMessage(), 'point at the setup task');
            $this->assertStringContainsString('preserved on the scan run', $e->getMessage());
        }

        $this->assertSame([], $this->store->findingWrites);
    }

    public function testAPartialSaveIsTreatedAsNoSave(): void
    {
        // Three findings written out of four is worse than none: the queue would show the session as
        // reviewed with a quarter of its findings missing and nothing to suggest otherwise.
        $this->store->saveError = 'saveData rejected instance 2';

        $this->expectException(TranscriptException::class);

        (new FindingWriter($this->store))->write('257', '2', 1008, 100, [
            $this->finding(),
            $this->finding(['finding_index' => 2]),
        ]);
    }

    public function testInstancesStartAtTheNextFreeSlot(): void
    {
        $this->store->nextInstance = 12;

        (new FindingWriter($this->store))->write('257', '2', 1008, 100, [
            $this->finding(),
            $this->finding(['finding_index' => 2]),
        ]);

        $this->assertSame([12, 13], array_column($this->store->findingWrites, 'instance'));
    }

    public function testAnOversizeSummaryIsTruncatedRatherThanRejected(): void
    {
        // Unlike transcript content, a summary is the model's own prose about evidence stored
        // elsewhere - nothing is verified against it, so a length cap is a field constraint rather
        // than an integrity one.
        (new FindingWriter($this->store))->write('257', '2', 1008, 100, [
            $this->finding(['finding_summary' => str_repeat('x', 1200)]),
        ]);

        $summary = $this->store->findingWrites[0]['fields']['finding_summary'];

        $this->assertSame(800, mb_strlen($summary));
        $this->assertStringEndsWith('...', $summary, 'visibly truncated, not silently clipped');
    }

    public function testEvidenceAndRecommendationsKeepTheirUnicode(): void
    {
        (new FindingWriter($this->store))->write('257', '2', 1008, 100, [
            $this->finding(['evidence' => [[
                'message_id'   => 'L3',
                'speaker_role' => 'participant',
                'exact_quote'  => 'caña 日本語 🙂',
            ]]]),
        ]);

        $json = $this->store->findingWrites[0]['fields']['finding_evidence_json'];

        // Unescaped, because the dashboard computes highlight offsets from this string against the
        // transcript, and \u-escapes would not line up.
        $this->assertStringContainsString('caña 日本語 🙂', $json);
        $this->assertStringNotContainsString('\\u', $json);
    }

    // ---------------------------------------------------------- the scan_failure placeholder

    public function testAScanFailurePlaceholderIsReviewable(): void
    {
        // A failed scan that produced no row at all would be a session that silently left the
        // pipeline. This is how it stays in the RA queue.
        $written = (new FindingWriter($this->store))
            ->writeScanFailure('257', '2', 1008, 100, 'content_filter', 3, 'blocked by the provider');

        $this->assertSame(1, $written);

        $fields = $this->store->findingWrites[0]['fields'];
        $this->assertSame(FindingWriter::SCAN_FAILURE, $fields['finding_concern_type']);
        $this->assertSame('pending', $fields['review_status'], 'it needs a disposition like any other');
        $this->assertSame('100', $fields['finding_scan_run']);
    }

    public function testThePlaceholderSaysTheSessionWasNotScreened(): void
    {
        (new FindingWriter($this->store))->writeScanFailure('257', '2', 1008, 100, 'timeout', 3);

        $summary = $this->store->findingWrites[0]['fields']['finding_summary'];

        $this->assertStringContainsString('NOT been screened', $summary);
        $this->assertStringContainsString('timeout', $summary, 'name the failure class');
        $this->assertStringContainsString('3 attempt', $summary);
    }

    public function testThePlaceholderIsHighNotCritical(): void
    {
        // It is an unscreened session, not a known critical finding. Inflating it to critical would
        // erode the meaning of the urgency an RA sorts the queue by.
        (new FindingWriter($this->store))->writeScanFailure('257', '2', 1008, 100, 'timeout', 3);

        $this->assertSame('high', $this->store->findingWrites[0]['fields']['finding_urgency']);
    }

    public function testThePlaceholderCarriesNoEvidenceFields(): void
    {
        (new FindingWriter($this->store))->writeScanFailure('257', '2', 1008, 100, 'timeout', 3);

        $fields = $this->store->findingWrites[0]['fields'];

        $this->assertArrayNotHasKey('finding_evidence_json', $fields, 'there is no evidence to show');
        $this->assertArrayNotHasKey('finding_rec_actions_json', $fields);
    }

    public function testThePlaceholderAlsoNeedsTheInstrument(): void
    {
        $this->store->instrumentExists = false;

        $this->expectException(TranscriptException::class);

        (new FindingWriter($this->store))->writeScanFailure('257', '2', 1008, 100, 'timeout', 3);
    }
}
