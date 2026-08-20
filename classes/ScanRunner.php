<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ArtifactRegistry.php";
require_once __DIR__ . "/CanonicalJson.php";
require_once __DIR__ . "/FindingWriter.php";
require_once __DIR__ . "/QuoteVerifier.php";
require_once __DIR__ . "/SafetyScanCallerInterface.php";
require_once __DIR__ . "/ScanOutcome.php";
require_once __DIR__ . "/ScanResultStoreInterface.php";
require_once __DIR__ . "/SchemaValidator.php";
require_once __DIR__ . "/TranscriptStoreInterface.php";

/**
 * One scan attempt, end to end: read the transcript, ask the model, prove the answer, record it.
 *
 * ## Every attempt leaves a row
 *
 * A `mica_scan_run` row is inserted for every attempt including every failure, before anything is
 * released. That is the audit trail the handoff asks for ("record the exact resolved deployment for
 * every turn") and it is also the thing that makes failing safe: the verbatim model output is
 * already preserved, so refusing to release findings costs nothing.
 *
 * ## Four ways a schema-valid answer is still not a clean screen
 *
 * The failure taxonomy is not only about transport. A perfectly well-formed response can still be
 * unreleasable, and each of these is a distinct branch:
 *
 *   `unable_to_assess`   The model says so itself. It is a *valid* value of `scan_result` with an
 *                        empty findings list - byte-identical in shape to a clean screen. Treating
 *                        it as `ok` is the single easiest way to build a silent negative screen into
 *                        this pipeline, so it is mapped to `refusal` and goes to a human.
 *   `citation_mismatch`  A quote that is not literally in the transcript. Any miss fails the whole
 *                        scan, not just that finding: releasing the verified subset would publish a
 *                        partial picture as a complete one.
 *   `schema_invalid`     Structured output that does not satisfy the pinned schema.
 *   no review target     Findings exist but the review instrument does not, so no RA would ever see
 *                        them. Fails to manual review with the output preserved, and is marked
 *                        terminal so the model is not re-called for a fault that cannot resolve.
 *
 * ## `run_status` and job status answer different questions
 *
 * `mica_scan_run.run_status` describes **the model call**: did it answer, was the output schema-valid,
 * did the quotes verify. Whether anything was **released** is the job's status, with `last_error` for
 * the reason. So an `ok` run row sitting under a job in `manual_review_required` is not a
 * contradiction - it is a scan that worked and a release that did not, which is exactly the state a
 * missing review instrument produces. Worth stating because it is easy to read one field and think
 * it answered the other.
 *
 * ## The transcript is verified before it is scanned
 *
 * The stored payload is re-hashed and compared to the hash recorded at finalization. A mismatch
 * means the bytes changed after they were pinned; scanning them anyway would produce findings about
 * a conversation nobody vouched for, and quote verification against a corrupt transcript would fail
 * for a reason that looks like a model fault.
 */
class ScanRunner
{
    private ArtifactRegistry $artifacts;
    private SchemaValidator $validator;
    private TranscriptStoreInterface $transcripts;
    private SafetyScanCallerInterface $caller;
    private ScanResultStoreInterface $results;
    private FindingWriter $findings;
    private QuoteVerifier $quotes;
    private string $modelAlias;
    private string $appVersion;
    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void|null $logger */
    public function __construct(
        ArtifactRegistry $artifacts,
        SchemaValidator $validator,
        TranscriptStoreInterface $transcripts,
        SafetyScanCallerInterface $caller,
        ScanResultStoreInterface $results,
        FindingWriter $findings,
        string $modelAlias,
        string $appVersion,
        ?QuoteVerifier $quotes = null,
        ?callable $logger = null
    ) {
        $this->artifacts = $artifacts;
        $this->validator = $validator;
        $this->transcripts = $transcripts;
        $this->caller = $caller;
        $this->results = $results;
        $this->findings = $findings;
        $this->modelAlias = $modelAlias;
        $this->appVersion = $appVersion;
        $this->quotes = $quotes ?? new QuoteVerifier();
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    /**
     * @param array<string,mixed> $job a claimed mica_scan_job row
     */
    public function run(array $job): ScanOutcome
    {
        $attempt = ((int) ($job['attempts'] ?? 0)) + 1;
        $projectId = (string) $job['project_id'];
        $record = (string) $job['record'];
        $eventId = (int) ($job['event_id'] ?? 0);

        $transcript = null;

        try {
            $transcript = $this->readTranscript($job);
        } catch (\Throwable $e) {
            // A corrupt or unreadable transcript is not the model's fault and must not be sent to
            // it. `service_error` is transient, so a transient read problem retries; a genuinely
            // corrupt payload exhausts and lands in manual review, which is right.
            return $this->recordFailure($job, $attempt, 'service_error', $e->getMessage());
        }

        $result = $this->caller->scan(
            $this->modelAlias,
            $this->artifacts->getText('safetyscan_prompt'),
            CanonicalJson::encode($transcript),
            $this->artifacts->getJson('safetyscan_output_schema')
        );

        if ($result['runStatus'] !== 'ok' || !is_array($result['output'])) {
            return $this->recordFailure($job, $attempt, $result['runStatus'], $result['error'], $result);
        }

        $output = $result['output'];

        $validation = $this->validator->validate($output, 'safetyscan_output_schema');
        if (!$validation->isValid()) {
            return $this->recordFailure(
                $job,
                $attempt,
                'schema_invalid',
                'The model output does not satisfy the pinned output schema: '
                . implode('; ', $validation->errors()),
                $result,
                $output
            );
        }

        // The model telling us it could not assess. Schema-valid, empty findings, and shaped exactly
        // like a clean screen - which is why it is checked explicitly rather than inferred.
        if (($output['scan_result'] ?? null) === 'unable_to_assess') {
            return $this->recordFailure(
                $job,
                $attempt,
                'refusal',
                'The scanner reported "unable_to_assess", which is NOT a negative screen: the '
                . 'session has not been screened and needs a human read. Model note: '
                . ($output['review_summary'] ?? '(none)'),
                $result,
                $output
            );
        }

        $problems = $this->quotes->verify($output, $transcript);
        if ($problems !== []) {
            return $this->recordFailure(
                $job,
                $attempt,
                'citation_mismatch',
                'Evidence could not be verified against the transcript, so no findings were '
                . 'released: ' . implode(' | ', $problems),
                $result,
                $output,
                $problems
            );
        }

        // Every attempt leaves a row, and this one is written before findings so the authoritative
        // copy of the output exists no matter what the write-back does.
        $runId = $this->insertRun($job, $attempt, 'ok', $result, $output, null);

        try {
            $written = $this->findings->write(
                $projectId,
                $record,
                $eventId,
                $runId,
                $output['findings'] ?? []
            );
        } catch (\Throwable $e) {
            // Findings exist but could not be recorded where a reviewer would see them. The run row
            // above already holds the verbatim output, so failing here loses nothing and refuses to
            // call the session reviewed.
            $this->log("job {$job['id']} produced findings that could not be written: " . $e->getMessage());

            return new ScanOutcome(
                runStatus: 'service_error',
                scanRunId: $runId,
                scanResult: $output['scan_result'] ?? null,
                overallUrgency: $output['overall_urgency'] ?? null,
                error: $e->getMessage(),
                // Terminal: the model already answered and verified, so retrying re-pays for the
                // same call against a configuration fault that cannot fix itself between attempts.
                terminal: true
            );
        }

        $outcome = new ScanOutcome(
            runStatus: 'ok',
            scanRunId: $runId,
            scanResult: $output['scan_result'] ?? null,
            overallUrgency: $output['overall_urgency'] ?? null,
            findings: $output['findings'] ?? [],
            findingsWritten: $written
        );

        $this->log("job {$job['id']} attempt $attempt: " . $outcome->summary());

        return $outcome;
    }

    /**
     * Re-join the stored payload and prove it is the one that was pinned.
     *
     * @return array<string,mixed> the transcript payload
     * @throws TranscriptException
     */
    private function readTranscript(array $job): array
    {
        $logId = (int) $job['transcript_ref'];
        $row = $this->transcripts->readTranscript((string) $job['project_id'], $logId);

        if ($row === null) {
            throw new TranscriptException(
                "Transcript T$logId, which scan job {$job['id']} points at, could not be read. "
                . 'Nothing is scanned rather than scanning a different transcript.'
            );
        }

        $canonical = CanonicalJson::fromLogParameters($row);
        $actual = CanonicalJson::hash($canonical);
        $expected = (string) ($row['transcript_sha256'] ?? '');

        if (!hash_equals($expected, $actual)) {
            throw new TranscriptException(sprintf(
                'Transcript T%d does not match the hash recorded when it was finalized (expected '
                . '%s, got %s). The stored bytes changed after they were pinned, so this is not the '
                . 'conversation the study vouched for and it is not scanned. Quote verification '
                . 'against a corrupt transcript would also fail in a way that looks like a model '
                . 'fault.',
                $logId,
                substr($expected, 0, 12),
                substr($actual, 0, 12)
            ));
        }

        $decoded = json_decode($canonical, true);
        if (!is_array($decoded)) {
            throw new TranscriptException("Transcript T$logId re-joined but did not decode as JSON.");
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed>|null $result the caller's report, when there was one
     * @param array<string,mixed>|null $output the model output, when there was one
     * @param list<string>             $problems
     */
    private function recordFailure(
        array $job,
        int $attempt,
        string $runStatus,
        ?string $error,
        ?array $result = null,
        ?array $output = null,
        array $problems = []
    ): ScanOutcome {
        $runId = $this->insertRun($job, $attempt, $runStatus, $result, $output, $error);

        $this->log("job {$job['id']} attempt $attempt failed as $runStatus: " . ($error ?? 'no detail'));

        return new ScanOutcome(
            runStatus: $runStatus,
            scanRunId: $runId,
            // Carried through even on a failure, because a reviewer looking at a citation_mismatch
            // wants to know what the model *claimed* before deciding what to do about it. It is
            // reference material on a failed row, not a released result: ScanOutcome only exposes
            // findings when runStatus is ok.
            scanResult: $output['scan_result'] ?? null,
            overallUrgency: $output['overall_urgency'] ?? null,
            error: $error,
            problems: $problems
        );
    }

    /** @return int the new mica_scan_run row id */
    private function insertRun(
        array $job,
        int $attempt,
        string $runStatus,
        ?array $result,
        ?array $output,
        ?string $error
    ): int {
        // Hashes of what was actually used, from the registry rather than from the manifest, so the
        // row describes reality (ArtifactRegistry::getHash).
        $data = [
            'job_id'               => (int) $job['id'],
            'attempt'              => $attempt,
            'model_alias'          => $this->modelAlias,
            'resolved_model'       => $result['resolvedModel'] ?? null,
            'prompt_sha256'        => $this->artifacts->getHash('safetyscan_prompt'),
            'input_schema_sha256'  => $this->artifacts->getHash('safetyscan_input_schema'),
            'output_schema_sha256' => $this->artifacts->getHash('safetyscan_output_schema'),
            'app_version'          => $this->appVersion,
            'latency_ms'           => $result['latencyMs'] ?? null,
            'prompt_tokens'        => $result['promptTokens'] ?? null,
            'completion_tokens'    => $result['completionTokens'] ?? null,
            'run_status'           => $runStatus,
            // Verbatim, and present even on a failure when there was any output at all: it is the
            // authoritative record, and a schema_invalid or citation_mismatch row without the
            // output it is about cannot be reviewed.
            'model_output_json'    => $this->runPayload($result, $output, $error),
        ];

        return $this->results->insertRun($data);
    }

    /**
     * The `model_output_json` column: the model's output verbatim when there was one, wrapped with
     * the attempt's diagnostics so a failed row is self-describing.
     */
    private function runPayload(?array $result, ?array $output, ?string $error): string
    {
        $payload = [
            'model_output'    => $output,
            'error'           => $error,
            'schema_was_sent' => $result['schemaWasSent'] ?? null,
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
