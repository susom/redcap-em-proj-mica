<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ScanResultStoreInterface.php";
require_once __DIR__ . "/TranscriptException.php";

/**
 * Turns verified model findings into repeating `mica_safety_finding` instances.
 *
 * These instances are the **review working copy**, not the record of truth: the authoritative,
 * immutable copy is the verbatim `model_output_json` on the insert-only `mica_scan_run` row
 * (02-data-model.md §1). That is what makes the failure mode here tolerable - if instances cannot be
 * written, nothing is lost, and the attempt fails to manual review with the model output preserved.
 *
 * Two rules the writer enforces rather than trusts:
 *
 *   **All or nothing.** `saveData`'s errors are inspected and any error fails the whole attempt.
 *   Three findings written out of four is worse than none: the RA queue would show a session as
 *   reviewed when a quarter of its findings never arrived, and there is nothing in the queue to
 *   suggest otherwise.
 *
 *   **Model fields are written once.** Everything this class writes is `@READONLY` on the form and
 *   never touched again; the dashboard writes only `review_*` and `action_*`. Drift from
 *   `model_output_json` is therefore both detectable and auditable.
 *
 * The `scan_failure` placeholder is the same mechanism used for the opposite purpose: when a scan
 * gives up, one instance is written with `finding_concern_type = scan_failure` so the failed session
 * appears in the RA queue and gets a disposition like any other. A failed scan that produced no row
 * at all would be a session that silently left the pipeline.
 */
class FindingWriter
{
    /** App-level concern type, not in the model output enum - see the class comment. */
    public const SCAN_FAILURE = 'scan_failure';

    private ScanResultStoreInterface $store;
    /** @var callable(): string */
    private $uuidFactory;

    /** @param callable(): string|null $uuidFactory injected so tests can force a collision */
    public function __construct(ScanResultStoreInterface $store, ?callable $uuidFactory = null)
    {
        $this->store = $store;
        $this->uuidFactory = $uuidFactory ?? [self::class, 'uuidv4'];
    }

    /**
     * @param list<array<string,mixed>> $findings from the validated, quote-verified model output
     * @return int how many instances were written
     * @throws TranscriptException when the instrument is absent or the write is not complete
     */
    public function write(
        string $projectId,
        string $record,
        int $eventId,
        int $scanRunId,
        array $findings
    ): int {
        if ($findings === []) {
            // A clean scan writes nothing. It is still visible: the job reaches ready_for_review and
            // the scan_run row records `no_supported_concern`, so the session appears in history
            // rather than vanishing (02-data-model.md §3.2, "zero-finding sessions").
            return 0;
        }

        $this->assertInstrumentExists($projectId, count($findings));

        $existing = $this->store->existingFindingIds($projectId, $record);
        $instances = [];

        foreach ($findings as $finding) {
            $instances[] = $this->instance($finding, $scanRunId, $existing);
        }

        $first = $this->store->nextFindingInstance($projectId, $record, $eventId);
        $this->store->writeFindingInstances($projectId, $record, $eventId, $first, $instances);

        return count($instances);
    }

    /**
     * The placeholder for a scan that gave up, so the session is reviewable rather than absent.
     *
     * @throws TranscriptException when the instrument is absent
     */
    public function writeScanFailure(
        string $projectId,
        string $record,
        int $eventId,
        int $scanRunId,
        string $failureClass,
        int $attempts,
        ?string $detail = null
    ): int {
        $this->assertInstrumentExists($projectId, 1);

        $summary = sprintf(
            'The automated safety scan did not complete (%s) after %d attempt(s). This session has '
            . 'NOT been screened - it needs a human read. %s',
            $failureClass,
            $attempts,
            $detail === null ? '' : 'Detail: ' . $detail
        );

        $instance = [
            'finding_id'           => ($this->uuidFactory)(),
            'finding_scan_run'     => (string) $scanRunId,
            'finding_index'        => '1',
            'finding_concern_type' => self::SCAN_FAILURE,
            // High rather than critical: it is an unscreened session, not a known critical finding,
            // and inflating it to critical would erode the meaning of the urgency an RA sorts by.
            'finding_urgency'      => 'high',
            'finding_summary'      => $this->truncateSummary($summary),
            'review_status'        => 'pending',
            'review_lock_version'  => '0',
        ];

        $first = $this->store->nextFindingInstance($projectId, $record, $eventId);
        $this->store->writeFindingInstances($projectId, $record, $eventId, $first, [$instance]);

        return 1;
    }

    /**
     * @param array<string,mixed> $finding
     * @param string[]            $existing
     * @return array<string,string>
     */
    private function instance(array $finding, int $scanRunId, array &$existing): array
    {
        $findingId = $this->uniqueFindingId($existing);
        $existing[] = $findingId;

        return [
            'finding_id'                 => $findingId,
            'finding_scan_run'           => (string) $scanRunId,
            'finding_index'              => (string) ($finding['finding_index'] ?? 1),
            'finding_source_role'        => (string) ($finding['source_role'] ?? ''),
            'finding_concern_type'       => (string) ($finding['concern_type'] ?? 'other'),
            'finding_urgency'            => (string) ($finding['urgency'] ?? 'moderate'),
            'finding_summary'            => $this->truncateSummary((string) ($finding['finding_summary'] ?? '')),
            // Stored as JSON and rendered by the dashboard, which computes highlight offsets from
            // exact_quote within the cited message. Kept whole rather than flattened: the RA needs
            // the message_id and speaker_role to jump to the evidence.
            'finding_evidence_json'      => $this->encode($finding['evidence'] ?? []),
            // Recommendations, never acted on automatically. This is the value the notification
            // policy reads *after* an RA confirms - the model proposes, a human disposes.
            'finding_rec_actions_json'   => $this->encode($finding['recommended_actions'] ?? []),
            'finding_rec_targets_json'   => $this->encode($finding['recommended_notification_targets'] ?? []),
            'finding_confidence'         => (string) ($finding['confidence'] ?? ''),
            'review_status'              => 'pending',
            'review_lock_version'        => '0',
        ];
    }

    /**
     * @param string[] $existing
     * @throws TranscriptException after too many collisions
     */
    private function uniqueFindingId(array $existing): string
    {
        // A v4 UUID colliding is not a thing that happens; a broken uuid factory is. Bounded rather
        // than looped forever, and loud rather than silently reusing an id that already names
        // another finding.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = ($this->uuidFactory)();
            if (!in_array($candidate, $existing, true)) {
                return $candidate;
            }
        }

        throw new TranscriptException(
            'Could not generate a unique finding_id after 5 attempts, which means the id generator '
            . 'is not random. No findings were written.'
        );
    }

    private function assertInstrumentExists(string $projectId, int $count): void
    {
        if ($this->store->findingInstrumentExists($projectId)) {
            return;
        }

        // Fail-closed, and the reason is worth stating: the job must NOT reach ready_for_review
        // with findings that exist only inside a JSON blob on an entity row no reviewer looks at.
        // The model output is preserved on mica_scan_run, so nothing is lost by refusing.
        throw new TranscriptException(sprintf(
            'The scan produced %d finding(s) but the repeating "mica_safety_finding" instrument does '
            . 'not exist on project %s, so there is nowhere for an RA to review them. The verbatim '
            . 'model output is preserved on the scan run and this attempt is failed to manual review '
            . 'rather than reported as complete. Build the instrument per 02-data-model.md §3.2 '
            . '(audit G5) and re-run the scan.',
            $count,
            $projectId
        ));
    }

    /** finding_summary is capped at 800 chars by the output schema; the field matches. */
    private function truncateSummary(string $summary): string
    {
        return mb_strlen($summary) <= 800 ? $summary : mb_substr($summary, 0, 797) . '...';
    }

    private function encode($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    public static function uuidv4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
