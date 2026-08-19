<?php

namespace Stanford\MICA;

/**
 * Outcome of validating one payload against one pinned schema.
 *
 * Named `SchemaValidationResult` rather than the plan's `ValidationResult` because
 * `Opis\JsonSchema\ValidationResult` already exists and the two would be a single import away from
 * being confused in classes that touch both.
 *
 * Invalid is a normal outcome, not an error: the turn pipeline branches on it and feeds `errors()`
 * back to the model as a corrective nudge, and the scan pipeline routes on it. That is why nothing
 * here throws.
 */
class SchemaValidationResult
{
    /** @param string[] $errors */
    private function __construct(private bool $valid, private array $errors)
    {
    }

    public static function valid(): self
    {
        return new self(true, []);
    }

    /** @param string[] $errors */
    public static function invalid(array $errors): self
    {
        // An invalid result with no stated reason is unusable in a log or a retry nudge.
        return new self(false, $errors ?: ['schema validation failed, but the validator reported no error detail']);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Human-readable violations, each prefixed with the JSON pointer of the offending value and
     * suffixed with the keyword that rejected it - e.g.
     * `/session_context/minutes_remaining: The number must be lower than or equal to 15 [maximum]`.
     *
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** One-line summary for logs and exception messages. */
    public function summary(): string
    {
        return $this->valid ? 'valid' : implode('; ', $this->errors);
    }
}
