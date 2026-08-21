<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ArtifactRegistry.php";
require_once __DIR__ . "/CanonicalJson.php";
require_once __DIR__ . "/FindingThresholds.php";
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
 *                        them. Checked BEFORE the run row is written so its status describes the
 *                        whole attempt, and marked terminal so the model is not re-called for a
 *                        fault that cannot resolve between attempts.
 *
 * ## `run_status` describes the whole attempt, with one documented exception
 *
 * The predictable release failure - findings with no review instrument - is checked *before* the run
 * row is written, so the row says `service_error` rather than an `ok` that a reviewer would read as
 * a completed scan. Provenance forces run-row-before-findings (`finding_scan_run` points at its id),
 * so this has to be a look-ahead rather than a correction.
 *
 * The residual exception: the instrument exists and `saveData` still refuses. That leaves an `ok`
 * run row under a job in `manual_review_required` - a scan that worked and a release that did not.
 * Rare, and it is why Stage 5's session view must show the job's status and `last_error` beside
 * `run_status` rather than presenting the run row alone.
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
    private FindingThresholds $thresholds;
    private QuoteVerifier $quotes;
    private string $modelAlias;
    private string $appVersion;
    private ?string $promptAddendum;
    /** @var array{text:string,sha256:string,source:string,addendumSha256:?string}|null memoized */
    private ?array $resolvedPrompt = null;
    /** @var callable(string): void */
    private $logger;

    /** The prompt was the validated, hash-pinned artifact, unmodified. */
    public const PROMPT_PINNED = 'pinned_artifact';
    /** The pinned artifact plus the project's `safetyscan-prompt-addendum`. */
    public const PROMPT_PINNED_PLUS_ADDENDUM = 'pinned_plus_addendum';

    /**
     * The frame the addendum is wrapped in, and the reinstatement that follows it.
     *
     * Both matter, for the same reason. The pinned prompt's **last** line is "Return only the JSON
     * object required by the schema", and appending after it would make study text the last thing
     * the model reads - so the two contracts an addendum must not weaken are restated after it, and
     * precedence is stated explicitly. The delimiters also mean a stored prompt can be read later
     * and the study's own words picked out of it without guessing.
     */
    private const ADDENDUM_HEADER =
        "--- ADDITIONAL STUDY-SPECIFIC GUIDANCE (appended by the local REDCap configuration) ---";
    private const ADDENDUM_FOOTER = "--- END ADDITIONAL STUDY-SPECIFIC GUIDANCE ---";
    private const ADDENDUM_REINSTATEMENT =
        "The instructions above this block remain in force and take precedence over the additional "
        . "guidance where they conflict. In particular: return only the JSON object required by the "
        . "schema, and copy every evidence quote verbatim from the transcript.";

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
        ?callable $logger = null,
        // Last, and optional: every existing caller passes positionally, so inserting it
        // earlier would have silently shifted $modelAlias into $thresholds.
        ?FindingThresholds $thresholds = null,
        // Same reason - appended, not inserted. Null or blank means the pinned artifact alone.
        ?string $promptAddendum = null
    ) {
        $this->artifacts = $artifacts;
        $this->validator = $validator;
        $this->transcripts = $transcripts;
        $this->caller = $caller;
        $this->results = $results;
        $this->findings = $findings;
        // Null means filter nothing, which is the shipped state - see FindingThresholds.
        $this->thresholds = $thresholds ?? new FindingThresholds();
        $this->modelAlias = $modelAlias;
        $this->appVersion = $appVersion;
        $this->quotes = $quotes ?? new QuoteVerifier();
        // Trimmed here so "blank" is decided once. A REDCap textarea saved empty comes back as ''
        // rather than null, and '' is not guidance.
        $trimmed = $promptAddendum === null ? '' : trim($promptAddendum);
        $this->promptAddendum = $trimmed === '' ? null : $trimmed;
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    /**
     * The prompt to send, its hash, and where it came from - resolved **once**.
     *
     * The validated prompt is always sent in full. A project addendum is **appended** to it, never
     * substituted for it, so the two properties the 120-case validation established - output that
     * satisfies the pinned schema, and evidence quoted verbatim - are still instructed by the text
     * the research team wrote. An addendum can conflict with them, which is why the reinstatement
     * follows it and says which wins; it cannot remove them.
     *
     * All three values together on purpose. The text goes to the model and the hash goes on the run
     * row, and those used to be two independent registry calls: `getText()` at the call site and
     * `getHash()` when the row was written. Composing the prompt in the first alone would have left
     * every run row recording the *bare pinned* hash while the model was sent something longer - a
     * run row that names a prompt it did not use is worse than one that names none, because it is
     * the record a reviewer trusts when they ask which prompt produced a finding.
     *
     * `source` is stored beside the hash rather than left to be inferred from it. A hash alone
     * cannot say "this was the validated artifact" without something to compare against, and the
     * obvious comparison - recomposing from the setting - is against a value the study may since
     * have edited. `addendumSha256` is kept for the same reason at one level finer: it identifies
     * *which* addendum was in force without reading the setting back.
     *
     * @return array{text:string,sha256:string,source:string,addendumSha256:?string}
     */
    private function resolvePrompt(): array
    {
        // Memoized, so "resolved once" is literal rather than a convention the call sites keep. The
        // text and the hash on the run row are then the same resolution by construction, not by two
        // callers agreeing.
        if ($this->resolvedPrompt !== null) {
            return $this->resolvedPrompt;
        }

        // From the registry, which recomputes it, rather than from the manifest - so what follows
        // describes the bytes actually read (ArtifactRegistry::getHash).
        $pinned = $this->artifacts->getText('safetyscan_prompt');

        if ($this->promptAddendum === null) {
            return $this->resolvedPrompt = [
                'text'           => $pinned,
                'sha256'         => $this->artifacts->getHash('safetyscan_prompt'),
                'source'         => self::PROMPT_PINNED,
                'addendumSha256' => null,
            ];
        }

        $composed = $pinned . "\n\n"
            . self::ADDENDUM_HEADER . "\n"
            . $this->promptAddendum . "\n"
            . self::ADDENDUM_FOOTER . "\n\n"
            . self::ADDENDUM_REINSTATEMENT . "\n";

        return $this->resolvedPrompt = [
            'text'   => $composed,
            // The composed prompt, not the artifact and not the addendum: this is what was sent.
            'sha256' => hash('sha256', $composed),
            'source' => self::PROMPT_PINNED_PLUS_ADDENDUM,
            'addendumSha256' => hash('sha256', $this->promptAddendum),
        ];
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

        $prompt = $this->resolvePrompt();

        if ($prompt['source'] === self::PROMPT_PINNED_PLUS_ADDENDUM) {
            // Every scan, not once at startup: this is the line that explains a queue of
            // schema_invalid or citation_mismatch rows, and whoever is reading the log then is
            // reading it because scans are failing.
            ($this->logger)(sprintf(
                'the pinned prompt plus this project\'s addendum (addendum sha256 %s, composed %s)',
                substr((string) $prompt['addendumSha256'], 0, 12),
                substr($prompt['sha256'], 0, 12)
            ));
        }

        $result = $this->caller->scan(
            $this->modelAlias,
            $prompt['text'],
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

        // Checked BEFORE the run row is written, not after, so the row's status describes the whole
        // attempt rather than only the model call. The alternative - insert `ok`, then discover the
        // findings have nowhere to go - leaves an `ok` row under a job that released nothing, which
        // is exactly the shape a reviewer misreads. Provenance forces run-before-findings
        // (finding_scan_run points at the run id), so the check has to be a look-ahead.
        /**
         * The project's post-scan filter, applied here and nowhere else.
         *
         * After validation and quote verification, before the write-back. The model was asked about
         * everything and its full answer is already on its way to the run row below, so this only
         * decides what an RA has to work through - and what it held back is recorded there too, with
         * the rule that held it. A filter whose effects cannot be read back is indistinguishable from
         * a scanner that found nothing.
         *
         * The instrument look-ahead below deliberately uses the ADMITTED list: a scan whose only
         * findings were filtered out has nothing to release, so it must not fail for want of an
         * instrument it was never going to write to.
         */
        $split = $this->thresholds->apply($output['findings'] ?? []);
        $findings = $split['admitted'];

        if ($split['filtered'] !== []) {
            $this->log(sprintf(
                'job %s: %d of %d finding(s) held back by this project\'s thresholds (%s)',
                $job['id'],
                count($split['filtered']),
                count($output['findings'] ?? []),
                implode('; ', array_column($split['filtered'], 'reason'))
            ));
        }

        if ($findings !== [] && !$this->results->findingInstrumentExists($projectId)) {
            return $this->recordFailure(
                $job,
                $attempt,
                'service_error',
                sprintf(
                    'The scan produced %d finding(s) but the repeating "%s" instrument does not '
                    . 'exist on project %s, so there is nowhere for an RA to review them. The '
                    . 'verbatim model output is preserved on this run row. Build the instrument per '
                    . '02-data-model.md §3.2 (audit G5) and re-run the scan.',
                    count($findings),
                    'mica_safety_finding',
                    $projectId
                ),
                $result,
                $output,
                [],
                // Retrying re-pays for the same model call against a fault that cannot resolve.
                true
            );
        }

        /**
         * Has this job already released findings? Asked before anything is written.
         *
         * The cron only ever claims `queued`, so a settled job is re-run only when somebody resets it
         * by hand - which an operator recovering from a failure plausibly does. Nothing in the write
         * path dedupes: FindingWriter appends at `nextFindingInstance()`, so a second successful run
         * releases a second complete set. The queue silently doubles, every finding appears twice with
         * a different `finding_scan_run`, and an RA dispositions the same disclosure twice.
         *
         * Observed while re-testing job 275 on PID 257: nine instances where there should have been
         * five, four of them duplicates of the other four.
         */
        $alreadyReleased = $this->results->findingsReleasedForJob($projectId, $record, (int) $job['id']);

        // Every attempt leaves a row, and this one is written before findings so the authoritative
        // copy of the output exists no matter what the write-back does.
        $runId = $this->insertRun($job, $attempt, 'ok', $result, $output, null, $split, $alreadyReleased);

        if ($alreadyReleased) {
            $this->log(sprintf(
                'job %s already released findings, so this run wrote none. The model answered and its '
                . 'output is on run %d; the findings on the record are the ones released the first '
                . 'time.',
                $job['id'],
                $runId
            ));

            /**
             * `ok` with nothing written, deliberately.
             *
             * The scan itself succeeded - it is the release that was refused - so classifying it as a
             * failure would write a `scan_failure` placeholder saying the session was never screened,
             * which is false. `ok` returns the job to `ready_for_review`, which is where it belongs:
             * there ARE findings to review, from the first release. `findingsWritten: 0` also keeps
             * notifyReviewersIfSettled() quiet, so nobody is emailed twice about one session.
             *
             * It is not confusable with a clean screen: the run row carries the model's findings and
             * `duplicate_release_prevented`, and `error` below lands in the job's `last_error`.
             */
            return new ScanOutcome(
                runStatus: 'ok',
                scanRunId: $runId,
                scanResult: $output['scan_result'] ?? null,
                overallUrgency: $output['overall_urgency'] ?? null,
                findings: $output['findings'] ?? [],
                error: sprintf(
                    'This job had already released findings, so run %d wrote none - the findings on '
                    . 'the record are from the first release. Re-running a settled job does not '
                    . 'replace them.',
                    $runId
                ),
                findingsWritten: 0
            );
        }

        try {
            $written = $this->findings->write($projectId, $record, $eventId, $runId, $findings);
        } catch (\Throwable $e) {
            // The residual case the look-ahead above cannot cover: the instrument exists and
            // saveData still refused. Rare, and it does leave an `ok` run row under a job that
            // released nothing - the one place where run_status and job status genuinely diverge.
            // Stage 5's session view must show the job's status and last_error beside run_status
            // for exactly this reason.
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
        array $problems = [],
        bool $terminal = false
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
            problems: $problems,
            terminal: $terminal
        );
    }

    /** @return int the new mica_scan_run row id */
    private function insertRun(
        array $job,
        int $attempt,
        string $runStatus,
        ?array $result,
        ?array $output,
        ?string $error,
        ?array $split = null,
        bool $alreadyReleased = false
    ): int {
        // Hashes of what was actually used, from the registry rather than from the manifest, so the
        // row describes reality (ArtifactRegistry::getHash).
        $data = [
            'job_id'               => (int) $job['id'],
            'attempt'              => $attempt,
            'model_alias'          => $this->modelAlias,
            'resolved_model'       => $result['resolvedModel'] ?? null,
            'prompt_sha256'        => $this->resolvePrompt()['sha256'],
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
            'model_output_json'    => $this->runPayload($result, $output, $error, $split, $alreadyReleased),
        ];

        return $this->results->insertRun($data);
    }

    /**
     * The `model_output_json` column: the model's output verbatim when there was one, wrapped with
     * the attempt's diagnostics so a failed row is self-describing.
     */
    private function runPayload(
        ?array $result,
        ?array $output,
        ?string $error,
        ?array $split = null,
        bool $alreadyReleased = false
    ): string {
        $payload = [
            'model_output'    => $output,
            'error'           => $error,
            'schema_was_sent' => $result['schemaWasSent'] ?? null,
            // Only present when it is true, so an OpenAI-path row is unchanged. It says the shape
            // came from the prompt rather than from enforced structured output - which is a weaker
            // guarantee, and a reader comparing two findings needs to know which they have.
        ];

        if (($result['schemaInPrompt'] ?? false) === true) {
            $payload['schema_in_prompt'] = true;
        }

        /**
         * Which prompt produced this row.
         *
         * Only written when the pinned artifact was not sent alone, so every existing row and every
         * normal row is unchanged and its absence means "the validated prompt, unmodified" - the
         * same convention `schema_in_prompt` above uses. In the payload rather than a new column for
         * the reason given below for `filtered`: redcap_entity cannot ALTER an existing type
         * (docs 84234d0), and this payload is already the self-describing record of one attempt.
         *
         * `prompt_sha256` on the row is the hash of the composed prompt actually sent, and
         * `prompt_addendum_sha256` identifies the study text inside it. Together they answer "which
         * prompt, and what did this study add to it" without reading back a setting that may have
         * changed since.
         */
        $prompt = $this->resolvePrompt();
        if ($prompt['source'] === self::PROMPT_PINNED_PLUS_ADDENDUM) {
            $payload['prompt_source'] = self::PROMPT_PINNED_PLUS_ADDENDUM;
            $payload['prompt_addendum_sha256'] = $prompt['addendumSha256'];
        }

        /**
         * What the project's thresholds held back, recorded beside the output they applied to.
         *
         * Here rather than in a new column on purpose: this payload is already the
         * self-describing record of one attempt, and `model_output` above still holds every finding
         * the model reported - so a filtered finding is recoverable in full from this row. What the
         * filter adds is *which* were held back and under which rule, because otherwise a short
         * queue and a quiet session look identical.
         *
         * Only written when something was actually filtered, so a row from an unconfigured project
         * is unchanged and diffing two runs stays meaningful.
         */
        // So a reader can tell "this run released nothing because the job already had" from "this run
        // found nothing", which are the same shape otherwise.
        if ($alreadyReleased) {
            $payload['duplicate_release_prevented'] = true;
        }

        if ($split !== null && ($split['filtered'] ?? []) !== []) {
            $payload['thresholds'] = $this->thresholds->toArray();
            $payload['filtered'] = $split['filtered'];
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
