<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\QuoteVerifier;

/**
 * The release gate. Every near-miss class a model actually produces has to fail, because a finding
 * whose quote is not literally in the transcript is one an RA cannot check - and an unverifiable
 * finding about self-harm is worse than no finding, since it consumes the attention a real one needs.
 */
#[CoversClass(QuoteVerifier::class)]
final class QuoteVerifierTest extends TestCase
{
    private const SAID = 'I have been drinking a lot more since my brother died, and some nights '
                       . "I think about not waking up.\nIt's been hard.";

    private function transcript(): array
    {
        return [
            'messages' => [
                ['message_id' => 'L1', 'sequence' => 1, 'speaker_role' => 'participant',
                 'content' => self::SAID],
                ['message_id' => 'L2', 'sequence' => 2, 'speaker_role' => 'mica',
                 'content' => 'That sounds heavy. Tell me more.'],
                ['message_id' => 'L3', 'sequence' => 3, 'speaker_role' => 'participant',
                 'content' => 'caña 日本語 🙂 “curly” already'],
            ],
        ];
    }

    private function scanOutput(array ...$evidence): array
    {
        return [
            'scan_result'      => 'findings_present',
            'overall_urgency'  => 'critical',
            'review_summary'   => 'x',
            'model_confidence' => 0.9,
            'findings'         => [[
                'finding_index'                    => 1,
                'source_role'                      => 'participant',
                'concern_type'                     => 'self_harm',
                'urgency'                          => 'critical',
                'finding_summary'                  => 'passive suicidal ideation',
                'evidence'                         => $evidence,
                'recommended_actions'              => ['ra_review'],
                'recommended_notification_targets' => ['research_assistant'],
                'confidence'                       => 0.9,
            ]],
        ];
    }

    private function evidence(string $quote, string $id = 'L1', string $role = 'participant'): array
    {
        return ['message_id' => $id, 'speaker_role' => $role, 'exact_quote' => $quote];
    }

    public function testAByteExactQuoteVerifies(): void
    {
        $problems = (new QuoteVerifier())->verify(
            $this->scanOutput($this->evidence('some nights I think about not waking up')),
            $this->transcript()
        );

        $this->assertSame([], $problems);
    }

    public function testTheWholeMessageAsAQuoteVerifies(): void
    {
        $this->assertSame(
            [],
            (new QuoteVerifier())->verify($this->scanOutput($this->evidence(self::SAID)), $this->transcript())
        );
    }

    public function testAQuoteSpanningANewlineVerifies(): void
    {
        // Real transcripts contain newlines, and a verifier that quietly normalised them would
        // accept reflowed text everywhere else too.
        $this->assertSame(
            [],
            (new QuoteVerifier())->verify(
                $this->scanOutput($this->evidence("not waking up.\nIt's been hard.")),
                $this->transcript()
            )
        );
    }

    public function testMultiByteTextVerifiesByBytes(): void
    {
        $this->assertSame(
            [],
            (new QuoteVerifier())->verify(
                $this->scanOutput($this->evidence('caña 日本語 🙂', 'L3')),
                $this->transcript()
            )
        );
    }

    #[DataProvider('nearMisses')]
    public function testEveryNearMissFails(string $label, string $quote, string $expectDiagnosis): void
    {
        $problems = (new QuoteVerifier())->verify(
            $this->scanOutput($this->evidence($quote)),
            $this->transcript()
        );

        $this->assertCount(1, $problems, "$label should have failed verification");
        $this->assertMatchesRegularExpression($expectDiagnosis, $problems[0], "$label diagnosis");
    }

    public static function nearMisses(): array
    {
        return [
            'case drift'        => ['case', 'Some Nights I Think About Not Waking Up', '/case is ignored/'],
            'collapsed newline' => ['whitespace', "not waking up. It's been hard.", '/whitespace is collapsed/'],
            'extra spaces'      => ['whitespace', 'some  nights  I  think', '/whitespace is collapsed/'],
            'curly apostrophe'  => ['punctuation', "It\u{2019}s been hard.", '/typographic punctuation/'],
            'ellipsis'          => ['abridgement', 'some nights I think ... not waking up', '/ellipsis/'],
            'paraphrase'        => ['paraphrase', 'some nights I consider ending my life',
                                    '/continued in its own words/'],
            'fabrication'       => ['invented', 'I own a firearm and keep it loaded', '/No substantial part/'],
            'empty'             => ['empty quote', '', '/empty exact_quote/'],
        ];
    }

    public function testAQuoteFromADifferentMessageFails(): void
    {
        // Correct text, wrong citation. The RA would look in L1 and not find it.
        $problems = (new QuoteVerifier())->verify(
            $this->scanOutput($this->evidence('That sounds heavy', 'L1', 'participant')),
            $this->transcript()
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('does not appear byte-for-byte in L1', $problems[0]);
    }

    public function testACitedMessageThatIsNotInTheTranscriptFails(): void
    {
        $problems = (new QuoteVerifier())->verify(
            $this->scanOutput($this->evidence('anything', 'L999')),
            $this->transcript()
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('L999', $problems[0]);
        $this->assertStringContainsString('not in this transcript', $problems[0]);
        $this->assertStringContainsString('L1, L2, L3', $problems[0], 'say what IS there');
    }

    public function testTheWrongSpeakerFailsEvenWithAPerfectQuote(): void
    {
        // "The participant said they thought about not waking up" and "MICA said it" are not the
        // same finding, and only one of them is a safety event.
        $problems = (new QuoteVerifier())->verify(
            $this->scanOutput($this->evidence('not waking up', 'L1', 'mica')),
            $this->transcript()
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('different clinical claim', $problems[0]);
        $this->assertStringContainsString('from "participant"', $problems[0]);
    }

    public function testOneBadQuoteAmongGoodOnesFailsTheScan(): void
    {
        // stage-4 §4.2.3: any miss fails the WHOLE scan to manual review. Releasing the verified
        // findings and dropping the unverified one would publish a partial picture as a complete one.
        $problems = (new QuoteVerifier())->verify(
            $this->scanOutput(
                $this->evidence('drinking a lot more'),
                $this->evidence('I own a firearm'),
                $this->evidence('not waking up')
            ),
            $this->transcript()
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('evidence 2', $problems[0], 'name which one');
    }

    public function testEveryFailureAcrossEveryFindingIsReported(): void
    {
        $output = $this->scanOutput($this->evidence('bad one'));
        $output['findings'][1] = $output['findings'][0];
        $output['findings'][1]['finding_index'] = 2;
        $output['findings'][1]['evidence'] = [$this->evidence('also bad')];

        $problems = (new QuoteVerifier())->verify($output, $this->transcript());

        $this->assertCount(2, $problems, 'a reviewer needs the whole list, not the first item');
        $this->assertStringContainsString('finding 1', $problems[0]);
        $this->assertStringContainsString('finding 2', $problems[1]);
    }

    public function testAZeroFindingScanHasNothingToVerify(): void
    {
        $clean = [
            'scan_result'      => 'no_supported_concern',
            'overall_urgency'  => 'none',
            'findings'         => [],
            'review_summary'   => 'nothing supported',
            'model_confidence' => 0.95,
        ];

        $this->assertSame([], (new QuoteVerifier())->verify($clean, $this->transcript()));
    }

    public function testTextAlreadyContainingCurlyQuotesVerifiesLiterally(): void
    {
        // The folding in the diagnostics must not leak into the decision: L3 really does contain
        // curly quotes, and quoting them exactly has to pass.
        $this->assertSame(
            [],
            (new QuoteVerifier())->verify(
                $this->scanOutput($this->evidence('“curly” already', 'L3')),
                $this->transcript()
            )
        );
    }

    public function testStraightQuotesDoNotMatchCurlyOnes(): void
    {
        $problems = (new QuoteVerifier())->verify(
            $this->scanOutput($this->evidence('"curly" already', 'L3')),
            $this->transcript()
        );

        $this->assertCount(1, $problems, 'the substitution goes both ways and neither is exact');
        $this->assertStringContainsString('typographic punctuation', $problems[0]);
    }

    public function testAnEmptyTranscriptFailsEveryCitation(): void
    {
        $problems = (new QuoteVerifier())->verify(
            $this->scanOutput($this->evidence('anything')),
            ['messages' => []]
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('0 message(s)', $problems[0]);
    }

    public function testALongTranscriptSummarisesItsIdsRatherThanDumpingThem(): void
    {
        $messages = [];
        for ($i = 1; $i <= 40; $i++) {
            $messages[] = [
                'message_id'   => "L$i",
                'sequence'     => $i,
                'speaker_role' => 'participant',
                'content'      => "message $i",
            ];
        }

        $problems = (new QuoteVerifier())->verify(
            $this->scanOutput($this->evidence('x', 'L999')),
            ['messages' => $messages]
        );

        $this->assertStringContainsString('…', $problems[0]);
        $this->assertLessThan(400, strlen($problems[0]), 'an unreadable error is an ignored error');
    }
}
