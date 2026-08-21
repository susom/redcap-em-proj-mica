<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ProviderFailure;
use Stanford\MICA\TranscriptBuilder;

/**
 * The defect being closed here (13-securechatai-current-state-delta.md §7 item 8b):
 *
 * SecureChatAI's failure envelope carries `role` + `content` only, where `content` is one of eight
 * canned apologies. It is shape-indistinguishable from a real answer, so `formatResponse()` treats
 * it as one and `logMICAQuery()` writes it into the participant's transcript **as something MICA
 * said**. That transcript is what SafetyScan later analyses, so a provider outage becomes counselor
 * text in the clinical record.
 *
 * Two claims are tested: the detection is right (and conservative - it never discards a real
 * answer), and a detected failure is stored in a shape `TranscriptBuilder` already drops.
 */
#[CoversClass(ProviderFailure::class)]
final class ProviderFailureTest extends TestCase
{
    /** One of the eight canned apologies, verbatim in shape (SecureChatAI.php:1619-1628). */
    private const APOLOGY = 'I apologize, but I am experiencing network difficulties. '
        . 'Please try again in a moment.';

    /**
     * The failure envelope, as verified against provider code: role + content, no model, no usage,
     * no `error`, no `type`.
     */
    public function testTheSanitizedFailureEnvelopeIsDetected(): void
    {
        $this->assertTrue(ProviderFailure::looksSanitized([
            'role'    => 'assistant',
            'content' => self::APOLOGY,
        ]));
    }

    public function testARealAnswerIsNotAFailure(): void
    {
        $this->assertFalse(ProviderFailure::looksSanitized([
            'role'    => 'assistant',
            'content' => 'Four.',
            'model'   => 'claude-opus-4-7',
            'usage'   => ['prompt_tokens' => 120, 'completion_tokens' => 3],
        ]));
    }

    /**
     * Conservative on purpose. The heuristic requires BOTH fields absent, so a provider that returns
     * a model but no usage accounting still gets its answer released. Discarding a real counselor
     * turn is worse than storing one apology: the participant said something and MICA answered it.
     *
     * @param array<string,mixed> $response
     */
    #[DataProvider('halfPopulatedResponses')]
    public function testOneFieldPresentIsNotEnoughToDiscardTheAnswer(array $response): void
    {
        $this->assertFalse(ProviderFailure::looksSanitized($response));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function halfPopulatedResponses(): array
    {
        return [
            'model but no usage' => [[
                'role' => 'assistant', 'content' => 'Four.', 'model' => 'claude-opus-4-7',
            ]],
            'usage but no model' => [[
                'role' => 'assistant', 'content' => 'Four.',
                'usage' => ['prompt_tokens' => 120],
            ]],
        ];
    }

    /**
     * `usage => []` is the shape `formatResponse()` can hand back, and an empty array is not null.
     * Treating it as "no usage" would discard answers, so it must read as populated.
     */
    public function testAnEmptyUsageArrayIsNotTreatedAsAbsent(): void
    {
        $this->assertFalse(ProviderFailure::looksSanitized([
            'role' => 'assistant', 'content' => 'Four.', 'usage' => [],
        ]));
    }

    // ---------------------------------------------------------------- redaction

    /**
     * The whole point: what we SHOW the participant and what we STORE diverge on a failed turn.
     * The apology still reaches the screen (they must not be left staring at nothing - `f43695a`,
     * `c98b161`), but it is not stored as counselor speech.
     */
    public function testRedactionEmptiesTheCounselorTextAndKeepsTheEvidence(): void
    {
        $redacted = ProviderFailure::redactCounselorTurn($this->turn(), 'no model and no usage');

        $this->assertSame('', $redacted['response']['content'], 'the apology must not be stored as MICA speech');
        $this->assertSame('assistant', $redacted['response']['role'], 'still a turn row, not a new shape');

        $this->assertSame(self::APOLOGY, $redacted['provider_error']['shown_to_participant']);
        $this->assertSame('no model and no usage', $redacted['provider_error']['detected_by']);
    }

    public function testRedactionPreservesTheParticipantsOwnMessageAndIdentity(): void
    {
        $redacted = ProviderFailure::redactCounselorTurn($this->turn(), 'no model and no usage');

        $this->assertSame('2+2?', $redacted['query']['content']);
        $this->assertSame('101', $redacted['user_id']);
    }

    public function testRedactionDoesNotMutateTheResultReturnedToTheClient(): void
    {
        $result = $this->turn();
        ProviderFailure::redactCounselorTurn($result, 'no model and no usage');

        $this->assertSame(self::APOLOGY, $result['response']['content'], 'the caller still shows the apology');
    }

    /**
     * The end-to-end claim, asserted against the real `TranscriptBuilder` rather than a comment:
     * a redacted failed turn contributes NO counselor message to the transcript SafetyScan reads,
     * and it says why it was skipped.
     */
    public function testTranscriptBuilderDropsARedactedTurn(): void
    {
        $redacted = ProviderFailure::redactCounselorTurn($this->turn(), 'no model and no usage');

        $builder = new TranscriptBuilder();
        $payload = $builder->build(
            [
                ['log_id' => 10, 'message' => json_encode(['role' => 'user', 'content' => '2+2?']),
                    'timestamp' => '2026-08-21 10:00:00'],
                ['log_id' => 11, 'message' => json_encode($redacted),
                    'timestamp' => '2026-08-21 10:00:01'],
            ],
            str_repeat('a', 32),
            'baseline',
            'emergency_department'
        );

        $roles = array_column($payload['messages'], 'speaker_role');
        $this->assertSame(['participant'], $roles, 'the participant is kept, the apology is not');
        $this->assertArrayHasKey('11', $builder->skippedRows());
    }

    /**
     * The regression guard for the defect as it exists today: an UNREDACTED apology sails straight
     * into the transcript as a counselor turn. If this ever starts passing with `['participant']`,
     * the redaction has been bypassed somewhere upstream.
     */
    public function testWithoutRedactionTheApologyWouldBecomeCounselorText(): void
    {
        $builder = new TranscriptBuilder();
        $payload = $builder->build(
            [
                ['log_id' => 10, 'message' => json_encode(['role' => 'user', 'content' => '2+2?']),
                    'timestamp' => '2026-08-21 10:00:00'],
                ['log_id' => 11, 'message' => json_encode($this->turn()),
                    'timestamp' => '2026-08-21 10:00:01'],
            ],
            str_repeat('a', 32),
            'baseline',
            'emergency_department'
        );

        $this->assertSame(
            ['participant', 'mica'],
            array_column($payload['messages'], 'speaker_role'),
            'this is the defect: the provider apology is indistinguishable from counselor speech'
        );
        $this->assertSame(self::APOLOGY, $payload['messages'][1]['content']);
    }

    /** A logged turn row exactly as the callAI action builds one. */
    private function turn(): array
    {
        return [
            'response' => ['role' => 'assistant', 'content' => self::APOLOGY],
            'id'       => null,
            'model'    => null,
            'usage'    => null,
            'user_id'  => '101',
            'query'    => ['role' => 'user', 'content' => '2+2?'],
        ];
    }
}
