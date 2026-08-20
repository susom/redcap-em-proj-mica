<?php

namespace Stanford\MICA;

/**
 * What one scan attempt did.
 *
 * Deliberately shaped so a caller cannot mistake a failure for a clean result: `findings` is only
 * ever non-empty when `runStatus` is `ok`, and `isClean()` requires the model to have said
 * `no_supported_concern` in as many words rather than inferring it from an empty findings list.
 * "The scanner found nothing" and "the scanner did not manage to look" have to stay distinguishable
 * all the way out of this class.
 */
class ScanOutcome
{
    /**
     * @param list<array<string,mixed>> $findings
     * @param list<string>              $problems quote-verification failures, when that is why it failed
     */
    public function __construct(
        public readonly string $runStatus,
        public readonly int $scanRunId,
        public readonly ?string $scanResult = null,
        public readonly ?string $overallUrgency = null,
        public readonly array $findings = [],
        public readonly ?string $error = null,
        public readonly array $problems = [],
        public readonly int $findingsWritten = 0,
        /**
         * Set when retrying cannot possibly help, even though `runStatus` falls in a class the state
         * machine would normally retry.
         *
         * The case it exists for: the model answered, the output validated, the quotes verified -
         * and then the findings could not be written because the review instrument does not exist.
         * That is a configuration fault, and the pinned failure taxonomy has no value for one, so
         * `service_error` is the honest classification. But `service_error` is *transient*, which
         * would mean two more attempts, each re-calling the model and paying for it, for a fault
         * that cannot resolve between attempts. The flag lets the worker skip straight to a human
         * without loosening the taxonomy.
         */
        public readonly bool $terminal = false
    ) {
    }

    public function isOk(): bool
    {
        return $this->runStatus === 'ok';
    }

    /**
     * A genuine negative screen: the model looked and supported no concern.
     *
     * Not `findings === []`. `unable_to_assess` and a zero-finding `findings_present` both have an
     * empty list, and neither is a clean screen.
     */
    public function isClean(): bool
    {
        return $this->isOk() && $this->scanResult === 'no_supported_concern';
    }

    /** For a cron log line: counts and statuses only, never finding text. */
    public function summary(): string
    {
        if (!$this->isOk()) {
            return sprintf('%s%s', $this->runStatus, $this->error === null ? '' : ' - ' . $this->error);
        }

        return sprintf(
            '%s (urgency %s, %d finding(s), %d written)',
            $this->scanResult ?? 'unknown',
            $this->overallUrgency ?? 'unknown',
            count($this->findings),
            $this->findingsWritten
        );
    }
}
