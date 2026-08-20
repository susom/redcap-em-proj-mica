<?php

namespace Stanford\MICA;

/**
 * What a finalize did, in enough detail that the caller never has to guess.
 *
 * `warnings` is the part worth explaining. Everything safety-critical either succeeds or throws -
 * there is no partial finalize. But the session-form write-back is *staff visibility*, not the
 * safety path: the scanner reads the EM-log transcript row, not the REDCap form. So a missing
 * dictionary field degrades to a named warning rather than blocking the scan, because refusing to
 * scan a participant's conversation over a missing display field would be strictly worse for the
 * participant than showing staff one fewer column.
 *
 * The warning is loud - logged, returned, and surfaced by the Stage 6 launch-readiness gate - so it
 * is a visible gap rather than a silent one.
 */
class FinalizeResult
{
    /**
     * @param string[] $warnings
     * @param array<string,string> $skippedRows log_id => why that row produced no message
     */
    public function __construct(
        public readonly int $transcriptLogId,
        public readonly string $transcriptRef,
        public readonly string $transcriptSha256,
        public readonly int $version,
        public readonly int $messageCount,
        public readonly int $maxMessageLogId,
        public readonly int $jobId,
        public readonly bool $jobCreated,
        public readonly array $warnings = [],
        public readonly array $skippedRows = []
    ) {
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /** For the AJAX response: no transcript content, no participant text. */
    public function toArray(): array
    {
        return [
            'transcript_ref'   => $this->transcriptRef,
            'transcript_hash'  => $this->transcriptSha256,
            'version'          => $this->version,
            'message_count'    => $this->messageCount,
            'scan_job_id'      => $this->jobId,
            'scan_job_created' => $this->jobCreated,
            'warnings'         => $this->warnings,
        ];
    }
}
