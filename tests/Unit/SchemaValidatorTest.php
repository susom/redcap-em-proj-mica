<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactIntegrityException;
use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\SchemaValidationResult;
use Stanford\MICA\SchemaValidator;

#[CoversClass(SchemaValidator::class)]
#[CoversClass(SchemaValidationResult::class)]
final class SchemaValidatorTest extends TestCase
{
    private const HANDOFF  = __DIR__ . '/../../handoff';
    private const FIXTURES = __DIR__ . '/../fixtures';

    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator(new ArtifactRegistry(self::HANDOFF));
    }

    // ------------------------------------------------------------------- the fixtures as vendored

    #[DataProvider('validFixtures')]
    public function testValidFixturePasses(string $fixture, string $schema): void
    {
        $result = $this->validator->validate($this->fixture($fixture), $schema);
        $this->assertTrue($result->isValid(), $result->summary());
        $this->assertSame([], $result->errors());
    }

    public static function validFixtures(): array
    {
        return [
            'counselor output' => ['counselor_output.valid.json', 'counselor_output_schema'],
            'wrapper'          => ['wrapper.valid.json', 'wrapper_schema'],
            'safetyscan input' => ['safetyscan_input.valid.json', 'safetyscan_input_schema'],
        ];
    }

    /**
     * The default notification policy shipped in the handoff must satisfy the notification-policy
     * schema shipped alongside it. If it does not, one of the two was re-vendored without the other.
     */
    public function testTheShippedDefaultPolicyMatchesItsOwnSchema(): void
    {
        $registry = new ArtifactRegistry(self::HANDOFF);
        $result   = $this->validator->validate(
            $registry->getJson('notification_policy_default'),
            'notification_policy_schema'
        );
        $this->assertTrue($result->isValid(), $result->summary());
    }

    /**
     * The two protocol positions the vendored default encodes. Both are launch-relevant, and both
     * are the kind of thing a re-vendoring could quietly flip:
     *   - `critical_acknowledgment_minutes` is null on purpose (open question #1); Stage 6 refuses
     *     production while it stays null, so a non-null default would erase the blocker.
     *   - pre-review notifications ship disabled; enabling them is a protocol decision, not an
     *     engineering one (05-open-questions, "explicitly out of scope").
     */
    public function testTheDefaultPolicyStillEncodesTheLaunchBlockers(): void
    {
        $policy = (new ArtifactRegistry(self::HANDOFF))->getJson('notification_policy_default');

        $this->assertArrayHasKey('critical_acknowledgment_minutes', $policy['ra_review_policy']);
        $this->assertNull(
            $policy['ra_review_policy']['critical_acknowledgment_minutes'],
            'a non-null default would remove the launch blocker Stage 6 depends on'
        );
        $this->assertFalse(
            $policy['pre_review_notifications']['enabled'],
            'pre-review notifications must ship disabled'
        );
    }

    // ------------------------------------------------ the counselor contract, which must stay v2

    /**
     * The single most important assertion in this phase: the R01 architecture removes live safety
     * routing, and the schema is what enforces it. A model (or a future prompt) that tries to
     * reintroduce a triage field must be rejected, not quietly accepted and logged.
     */
    #[DataProvider('resurgentSafetyFields')]
    public function testCounselorOutputRejectsLiveSafetyRouting(string $field, mixed $value): void
    {
        $payload = $this->fixture('counselor_output.valid.json');
        $payload[$field] = $value;

        $result = $this->validator->validate($payload, 'counselor_output_schema');

        $this->assertFalse($result->isValid(), "'$field' was accepted into a counselor response");
        $this->assertStringContainsString($field, $result->summary());
        $this->assertStringContainsString('additionalProperties', $result->summary());
    }

    public static function resurgentSafetyFields(): array
    {
        return [
            'safety_flag'      => ['safety_flag', 'critical'],
            'escalation'       => ['escalation', true],
            'risk_level'       => ['risk_level', 3],
            'alert_care_team'  => ['alert_care_team', true],
            'staff_notified'   => ['staff_notified', 'yes'],
        ];
    }

    #[DataProvider('invalidCounselorOutputs')]
    public function testInvalidCounselorOutputIsRejected(array $payload, string $expectKeyword): void
    {
        $result = $this->validator->validate($payload, 'counselor_output_schema');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString($expectKeyword, $result->summary());
    }

    public static function invalidCounselorOutputs(): array
    {
        $ok = json_decode((string) file_get_contents(self::FIXTURES . '/counselor_output.valid.json'), true);

        return [
            'missing assistant_text' => [array_diff_key($ok, ['assistant_text' => null]), 'required'],
            'missing end_session'    => [array_diff_key($ok, ['end_session' => null]), 'required'],
            'empty assistant_text'   => [['assistant_text' => ''] + $ok, 'minLength'],
            'over 1200 chars'        => [['assistant_text' => str_repeat('a', 1201)] + $ok, 'maxLength'],
            'unknown phase'          => [['next_phase' => 'triage'] + $ok, 'enum'],
            'unknown strategy'       => [['response_strategy' => 'escalate'] + $ok, 'enum'],
            'end_session as string'  => [['end_session' => 'true'] + $ok, 'boolean'],
        ];
    }

    // -------------------------------------------------------------------- draft 2020-12 behaviour

    public function testConstIsAsserted(): void
    {
        $wrapper = $this->fixture('wrapper.valid.json');
        $wrapper['schema_version'] = '1.0';

        $result = $this->validator->validate($wrapper, 'wrapper_schema');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('const', $result->summary());
    }

    public function testNullableUnionTypesAreAccepted(): void
    {
        // `"type": ["number", "null"]` - a 2020-12 union, not the OpenAPI `nullable` keyword.
        $wrapper = $this->fixture('wrapper.valid.json');
        $wrapper['session_context']['readiness_score']  = null;
        $wrapper['session_context']['confidence_score'] = null;
        $wrapper['session_context']['alcohol_summary']   = null;

        $result = $this->validator->validate($wrapper, 'wrapper_schema');
        $this->assertTrue($result->isValid(), $result->summary());
    }

    /**
     * Draft 2020-12 makes `format` annotation-only by default; opis asserts it. We rely on that
     * (SafetyScan timestamps), so pin it - an opis upgrade that stops asserting must fail here
     * rather than silently accept junk timestamps into the scan input.
     */
    public function testDateTimeFormatIsAsserted(): void
    {
        $input = $this->fixture('safetyscan_input.valid.json');
        $input['session_started_at'] = 'definitely-not-a-date';

        $result = $this->validator->validate($input, 'safetyscan_input_schema');
        $this->assertFalse($result->isValid(), 'format: date-time is no longer being asserted');
        $this->assertStringContainsString('format', $result->summary());
    }

    public function testIntegerVersusNumberIsDistinguished(): void
    {
        $input = $this->fixture('safetyscan_input.valid.json');
        $input['messages'][0]['sequence'] = 1.5;      // schema says integer

        $result = $this->validator->validate($input, 'safetyscan_input_schema');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('integer', $result->summary());
    }

    public function testMinItemsIsAsserted(): void
    {
        $input = $this->fixture('safetyscan_input.valid.json');
        $input['messages'] = [];

        $result = $this->validator->validate($input, 'safetyscan_input_schema');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('minItems', $result->summary());
    }

    // ----------------------------------------------------- the two traps this class exists to fix

    /**
     * `Helper::getJsonType()` returns null for an associative PHP array, so without conversion an
     * ordinary REDCap/json_decode array fails `{"type":"object"}`. Every caller in this codebase
     * passes associative arrays, so this is the default path, not an edge case.
     */
    public function testAssociativePhpArraysValidateAsObjects(): void
    {
        $payload = [
            'assistant_text'    => 'Thanks for sharing that.',
            'next_phase'        => 'engage',
            'response_strategy' => 'reflect',
            'end_session'       => false,
        ];
        $this->assertTrue($this->validator->isValid($payload, 'counselor_output_schema'));
    }

    /** Nested associative arrays too - the wrapper is three levels deep. */
    public function testNestedAssociativeArraysValidate(): void
    {
        $this->assertTrue(
            $this->validator->isValid($this->fixture('wrapper.valid.json'), 'wrapper_schema')
        );
    }

    /**
     * Regression test for a wrong configuration, not for a library bug. With opis's
     * `stopAtFirstError` set to false, its evaluated-property bookkeeping breaks and
     * `additionalProperties` reports *declared* properties as disallowed. Those error strings are
     * fed back to the model as a corrective nudge, so a payload with one bad number would tell the
     * model to delete `schema_version`, `patient_message` and `session_context`.
     */
    public function testErrorsNeverBlameValidDeclaredProperties(): void
    {
        $wrapper = $this->fixture('wrapper.valid.json');
        $wrapper['schema_version'] = '1.0';                        // fault 1
        $wrapper['session_context']['minutes_remaining'] = 99;      // fault 2

        $result = $this->validator->validate($wrapper, 'wrapper_schema');
        $this->assertFalse($result->isValid());

        // Both real faults reported...
        $this->assertStringContainsString('/schema_version', $result->summary());
        $this->assertStringContainsString('/session_context/minutes_remaining', $result->summary());

        // ...and nothing claiming a required property is not allowed.
        foreach ($result->errors() as $error) {
            if (!str_contains($error, 'additionalProperties')) {
                continue;
            }
            foreach (['schema_version', 'patient_message', 'session_context', 'session_type'] as $declared) {
                $this->assertStringNotContainsString(
                    $declared,
                    $error,
                    "the validator is blaming the declared property '$declared'"
                );
            }
        }
    }

    /**
     * The wrapper schema carries `$defs.model_response.$ref` pointing at an absolute
     * `https://mica.example.org/...` id - a host that does not exist. Nothing in the validation
     * tree reaches `$defs`, so this asserts the weaker but still necessary property: a dangling
     * `$ref` parked in `$defs` must not make the schema unusable.
     */
    public function testADanglingRefInDefsDoesNotBreakTheSchema(): void
    {
        $this->assertTrue(
            $this->validator->isValid($this->fixture('wrapper.valid.json'), 'wrapper_schema'),
            'the wrapper schema could not be used at all'
        );
    }

    /**
     * The registration that makes `$defs.model_response` usable when Stage 1 starts validating
     * responses by reference. Exercised for real here - against a schema whose *validation tree*
     * contains the cross-file `$ref` - because the wrapper fixture above passes either way, so on
     * its own it would let the registration rot.
     */
    public function testCrossFileRefResolvesFromPinnedFilesWithoutNetwork(): void
    {
        $counselor  = (string) file_get_contents(self::HANDOFF . '/MICA_counselor_output_schema_v2.json');
        $referencing = json_encode([
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            '$id'        => 'https://mica.example.org/schemas/referencing.json',
            'type'       => 'object',
            'required'   => ['model_response'],
            'properties' => [
                // The absolute id of the counselor output schema, on a host that does not exist.
                'model_response' => ['$ref' => 'https://mica.example.org/schemas/r01-counselor-output-v2.json'],
            ],
        ], JSON_THROW_ON_ERROR);

        $dir = $this->tempHandoff([
            'MICA_counselor_output_schema_v2.json' => $counselor,
            'referencing.json'                     => $referencing,
        ], [
            'counselor_output_schema' => 'MICA_counselor_output_schema_v2.json',
            'referencing'             => 'referencing.json',
        ]);

        $validator = new SchemaValidator(new ArtifactRegistry($dir));

        $good = ['model_response' => $this->fixture('counselor_output.valid.json')];
        $this->assertTrue(
            $validator->isValid($good, 'referencing'),
            'a cross-file $ref did not resolve from the pinned files'
        );

        // And the referenced schema is really being applied, not merely resolved to something inert.
        $bad = ['model_response' => ['safety_flag' => 'critical'] + $this->fixture('counselor_output.valid.json')];
        $result = $validator->validate($bad, 'referencing');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('safety_flag', $result->summary());
    }

    public function testAnUnresolvableRefFailsClosedRatherThanFetching(): void
    {
        // A $ref that cannot be satisfied from the pinned files must raise, not attempt a request
        // and not quietly pass. This one sits in the validation tree, so it is actually reached.
        $schema = json_encode([
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            '$id'        => 'https://mica.example.org/schemas/dangling.json',
            'type'       => 'object',
            'properties' => ['x' => ['$ref' => 'https://mica.example.org/schemas/does-not-exist.json']],
        ], JSON_THROW_ON_ERROR);

        $dir       = $this->tempHandoff(['dangling.json' => $schema], ['dangling' => 'dangling.json']);
        $validator = new SchemaValidator(new ArtifactRegistry($dir));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not be used to validate/');
        $validator->validate(['x' => 1], 'dangling');
    }

    // -------------------------------------------------------------------- error vs invalid-result

    public function testInvalidDataNeverThrows(): void
    {
        // Junk of every shape must come back as a result, because both pipelines branch on it.
        foreach ([null, 42, 'a string', [], [[]], ['x' => new \stdClass()]] as $junk) {
            $result = $this->validator->validate($junk, 'counselor_output_schema');
            $this->assertFalse($result->isValid());
            $this->assertNotSame([], $result->errors(), 'an invalid result must state a reason');
        }
    }

    public function testAnUnpinnedSchemaThrows(): void
    {
        $this->expectException(ArtifactIntegrityException::class);
        $this->validator->validate([], 'counselor_output_schema_v3');
    }

    public function testAskingAPromptToActAsASchemaIsACallerBug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->validate([], 'counselor_prompt');
    }

    // ------------------------------------------------------------------------------------ helpers

    /** @var string[] temp handoff directories to remove after each test */
    private array $temps = [];

    protected function tearDown(): void
    {
        foreach ($this->temps as $dir) {
            foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
                unlink("$dir/$f");
            }
            rmdir($dir);
        }
        $this->temps = [];
    }

    private function fixture(string $name): array
    {
        $path = self::FIXTURES . '/' . $name;
        $this->assertFileExists($path);
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A throwaway pinned handoff directory, so schema-resolution behaviour can be exercised on
     * schemas the real handoff does not contain.
     *
     * @param array<string,string> $files    filename => contents
     * @param array<string,string> $pins     logical name => filename
     */
    private function tempHandoff(array $files, array $pins): string
    {
        $dir = sys_get_temp_dir() . '/mica-schema-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->temps[] = $dir;

        foreach ($files as $name => $contents) {
            file_put_contents("$dir/$name", $contents);
        }

        $artifacts = [];
        foreach ($pins as $logical => $file) {
            $artifacts[$logical] = [
                'file'   => $file,
                'type'   => 'json',
                'sha256' => hash('sha256', $files[$file]),
            ];
        }
        file_put_contents("$dir/manifest.json", json_encode(['artifacts' => $artifacts], JSON_THROW_ON_ERROR));

        return $dir;
    }
}
