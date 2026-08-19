<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ArtifactRegistry.php";
require_once __DIR__ . "/SchemaValidationResult.php";

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Validator;

/**
 * Validates payloads against the hash-pinned handoff schemas (draft 2020-12).
 *
 * Two things this class exists to get right, beyond calling a library:
 *
 * 1. **PHP arrays are not JSON objects.** `Helper::getJsonType()` returns null for an associative
 *    array, so validating `['a' => 1]` against `{"type":"object"}` fails with a confusing error
 *    rather than passing. Everything is converted through `Helper::convertAssocArrayToObject()`
 *    first, so callers can pass ordinary PHP arrays - which is what `REDCap::getData()`,
 *    `json_decode($x, true)` and SecureChatAI's `structured_output` all hand you.
 *
 * 2. **`$ref` must resolve offline.** `MICA_wrapper_schema_v2.json` contains
 *    `$defs.model_response.$ref` pointing at the counselor output schema's absolute `$id`
 *    (`https://mica.example.org/...`) - a host that does not exist. Every vendored schema is
 *    registered with the resolver by its own `$id`, so cross-references resolve from the pinned
 *    files. Nothing is ever fetched: an unregistered `$ref` fails closed instead of reaching the
 *    network, which is asserted in the tests.
 *
 * Invalid *data* never throws - that is a normal branch for both pipelines. Only an unusable
 * *schema* throws.
 */
class SchemaValidator
{
    private ArtifactRegistry $artifacts;
    private ?Validator $validator = null;

    /**
     * @param int $maxErrors Violations to collect before giving up. The default reports all of a
     *                       response's problems rather than the first: a gate failure that says
     *                       only "one of these four fields is wrong" is much harder to act on,
     *                       both in a log and in a corrective retry.
     */
    public function __construct(ArtifactRegistry $artifacts, private int $maxErrors = 25)
    {
        $this->artifacts = $artifacts;
    }

    /**
     * @param mixed  $data           payload to check; PHP arrays are fine (see class docblock)
     * @param string $schemaArtifact logical artifact name, e.g. `wrapper_schema`
     *
     * @throws ArtifactIntegrityException if the schema is not pinned, or fails its hash
     * @throws \RuntimeException          if the schema is pinned but not usable as a schema
     */
    public function validate(mixed $data, string $schemaArtifact): SchemaValidationResult
    {
        $schema = Helper::convertAssocArrayToObject($this->artifacts->getJson($schemaArtifact));

        try {
            $result = $this->validator()->validate(Helper::convertAssocArrayToObject($data), $schema);
        } catch (\Throwable $e) {
            // Distinguishes "the payload is wrong" (a result) from "the schema is wrong" (an
            // exception). The pipelines treat these very differently: the first is a retry, the
            // second is a configuration fault that must not be retried against the model.
            throw new \RuntimeException(
                "Schema '$schemaArtifact' could not be used to validate: " . $e->getMessage(),
                0,
                $e
            );
        }

        if ($result->isValid()) {
            return SchemaValidationResult::valid();
        }

        return SchemaValidationResult::invalid($this->describe($result->error()));
    }

    /** Convenience for the common `validate(...)->isValid()` call. */
    public function isValid(mixed $data, string $schemaArtifact): bool
    {
        return $this->validate($data, $schemaArtifact)->isValid();
    }

    /**
     * A validator whose resolver knows every pinned schema by `$id`, built once per instance.
     *
     * @throws ArtifactIntegrityException
     */
    private function validator(): Validator
    {
        if ($this->validator !== null) {
            return $this->validator;
        }

        $resolver = new SchemaResolver();
        foreach ($this->artifacts->names() as $name) {
            $decoded = $this->schemaIfAny($name);
            // registerRaw() takes the id from the schema's own $id when none is passed.
            if ($decoded !== null && isset($decoded->{'$id'})) {
                $resolver->registerRaw($decoded);
            }
        }

        // `stopAtFirstError` must stay TRUE, despite wanting several errors - it does not mean
        // "report one error", it means "stop descending a subschema once it has failed". With it
        // false, opis's evaluated-property bookkeeping breaks and `additionalProperties` reports
        // *declared, valid* properties as disallowed: a wrapper with a bad `minutes_remaining`
        // comes back also claiming `schema_version, patient_message, session_context` are not
        // allowed. Those strings are fed to the model as a corrective nudge, so believing them
        // would make it delete the very fields the schema requires. True + maxErrors > 1 still
        // yields one accurate error per failing location, which is what we actually wanted.
        //
        // Deliberately no protocol handlers on the resolver either: there is no code path here
        // that can fetch a schema over the network.
        return $this->validator = new Validator(
            new SchemaLoader(new SchemaParser(), $resolver, true),
            $this->maxErrors,
            true
        );
    }

    /** The decoded schema for $name, or null if that artifact is not a JSON schema. */
    private function schemaIfAny(string $name): ?object
    {
        try {
            $decoded = $this->artifacts->getJson($name);
        } catch (\InvalidArgumentException) {
            return null;            // a prompt file, not a schema
        }

        // The default notification policy is data that a schema validates, not a schema itself.
        if (!isset($decoded['$schema'])) {
            return null;
        }

        $object = Helper::convertAssocArrayToObject($decoded);
        return is_object($object) ? $object : null;
    }

    /**
     * Flatten opis's error tree into "pointer: message [keyword]" lines.
     *
     * @return string[]
     */
    private function describe(?ValidationError $error): array
    {
        if ($error === null) {
            return [];
        }

        $formatter = new ErrorFormatter();
        $keyed = $formatter->formatKeyed(
            $error,
            static fn(ValidationError $e): string => $formatter->formatErrorMessage($e) . ' [' . $e->keyword() . ']'
        );

        $lines = [];
        foreach ($keyed as $pointer => $messages) {
            foreach ($messages as $message) {
                $lines[] = ($pointer === '' ? '/' : $pointer) . ': ' . $message;
            }
        }

        return $lines;
    }
}
