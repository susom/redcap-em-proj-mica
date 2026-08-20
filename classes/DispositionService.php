<?php

namespace Stanford\MICA;

require_once __DIR__ . "/AuditLogger.php";
require_once __DIR__ . "/ReviewAccessException.php";
require_once __DIR__ . "/RoleService.php";
require_once __DIR__ . "/TranscriptException.php";

/**
 * An RA's decision about one finding: confirm, dismiss, or send it for a second review.
 *
 * This is the point the whole handoff turns on. Nothing reaches a care team, a PI or a protocol
 * lead until a human has confirmed a finding here - "Do not notify the care team or PI before RA
 * confirmation under the initial policy". So the guarantees are narrow and enforced rather than
 * conventional:
 *
 * **Model fields are never written.** The write set is computed from an allowlist, and a `finding_*`
 * key reaching it is a thrown programming error, not a filtered-out mistake. The authoritative copy
 * of the model output lives on the insert-only scan-run row, so drift here would be detectable -
 * but detectable-after-the-fact is not the same as impossible.
 *
 * **A rationale is required to confirm or dismiss.** Server-side, because the form cannot enforce
 * it and because a confirmed critical finding with no stated reason is not reviewable by the next
 * person - including the same reviewer in three months.
 *
 * **Optimistic locking, not last-write-wins.** Two RAs on the same queue is the normal case, not an
 * edge case, and silently overwriting a colleague's disposition is the one failure nobody would
 * ever notice: the finding still looks reviewed.
 */
class DispositionService
{
    public const PENDING             = 'pending';
    public const CONFIRMED           = 'confirmed';
    public const DISMISSED           = 'dismissed';
    public const NEEDS_SECOND_REVIEW = 'needs_second_review';

    /** Dispositions that end the review, and therefore require a stated reason. */
    public const REQUIRES_RATIONALE = [self::CONFIRMED, self::DISMISSED];

    /** The only fields this service may write. Everything else is somebody else's business. */
    public const WRITABLE = [
        'review_status',
        'review_reviewer',
        'review_corrected_concern_type',
        'review_corrected_urgency',
        'review_rationale',
        'review_notes',
        'review_reviewed_at',
        'review_lock_version',
    ];

    private FindingReviewStoreInterface $store;
    private RoleService $roles;
    private AuditLogger $audit;
    /** @var callable(): int */
    private $clock;

    /** @param callable(): int|null $clock */
    public function __construct(
        FindingReviewStoreInterface $store,
        RoleService $roles,
        AuditLogger $audit,
        ?callable $clock = null
    ) {
        $this->store = $store;
        $this->roles = $roles;
        $this->audit = $audit;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @param array<string,mixed> $input from the client: status, rationale, notes, corrections,
     *                                   lock_version
     * @return array{finding_id:string,review_status:string,lock_version:int}
     * @throws ReviewAccessException      the caller may not do this
     * @throws ReviewConflictException    somebody else saved first
     * @throws TranscriptException        the request is not valid, or the write failed
     */
    public function submit(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        ?string $username,
        array $input
    ): array {
        $this->roles->requireRole($username, 'submitDisposition');

        $status = (string) ($input['review_status'] ?? '');
        if (!in_array($status, [self::CONFIRMED, self::DISMISSED, self::NEEDS_SECOND_REVIEW], true)) {
            throw new TranscriptException(sprintf(
                '"%s" is not a disposition. Choose %s.',
                $status,
                implode(', ', [self::CONFIRMED, self::DISMISSED, self::NEEDS_SECOND_REVIEW])
            ));
        }

        $existing = $this->store->readFinding($projectId, $record, $eventId, $instance);

        if ($existing === null) {
            throw new TranscriptException(
                "There is no finding at instance $instance for record $record, so there is nothing "
                . 'to disposition. It may have been deleted; reload the queue.'
            );
        }

        $this->assertLockIsCurrent($input, $existing);

        $rationale = trim((string) ($input['review_rationale'] ?? ''));
        if (in_array($status, self::REQUIRES_RATIONALE, true) && $rationale === '') {
            throw new TranscriptException(
                'A rationale is required to confirm or dismiss a finding. A decision with no stated '
                . 'reason is not reviewable by the next person - including you, in three months.'
            );
        }

        $currentVersion = (int) ($existing['review_lock_version'] ?? 0);

        $write = [
            'review_status'        => $status,
            // Server-derived. A client-supplied reviewer would make the audit trail worthless.
            'review_reviewer'      => (string) $username,
            'review_reviewed_at'   => date('Y-m-d H:i:s', ($this->clock)()),
            'review_lock_version'  => (string) ($currentVersion + 1),
        ];

        if ($rationale !== '') {
            $write['review_rationale'] = $rationale;
        }

        $notes = trim((string) ($input['review_notes'] ?? ''));
        if ($notes !== '') {
            $write['review_notes'] = $notes;
        }

        // Corrections are stored SEPARATELY from the model's classification, so the original stays
        // intact and the disagreement itself is the auditable fact.
        foreach (
            [
            'review_corrected_concern_type' => 'review_corrected_concern_type',
            'review_corrected_urgency'      => 'review_corrected_urgency',
            ] as $from => $to
        ) {
            $value = trim((string) ($input[$from] ?? ''));
            if ($value !== '') {
                $write[$to] = $value;
            }
        }

        $this->assertOnlyReviewFields($write);

        // Audited BEFORE the write, and strictly: if the record of the decision cannot be made, the
        // decision is not made. An undocumented change to a clinical judgment is not acceptable,
        // and this is the one path where that outranks getting the write done.
        $this->audit->strict(
            $username,
            AuditLogger::DISPOSITION,
            'finding',
            (string) ($existing['finding_id'] ?? "instance:$instance"),
            [
                'record'            => $record,
                'instance'          => $instance,
                'review_status'     => $status,
                'previous_status'   => (string) ($existing['review_status'] ?? self::PENDING),
                'concern_type'      => (string) ($existing['finding_concern_type'] ?? ''),
                'urgency'           => (string) ($existing['finding_urgency'] ?? ''),
                'corrected_concern' => $write['review_corrected_concern_type'] ?? '',
                'corrected_urgency' => $write['review_corrected_urgency'] ?? '',
                'rationale_length'  => mb_strlen($rationale),
                'lock_version'      => $currentVersion + 1,
            ]
        );

        $this->store->writeReviewFields($projectId, $record, $eventId, $instance, $write);

        return [
            'finding_id'    => (string) ($existing['finding_id'] ?? ''),
            'review_status' => $status,
            'lock_version'  => $currentVersion + 1,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $existing
     * @throws ReviewConflictException
     */
    private function assertLockIsCurrent(array $input, array $existing): void
    {
        $current = (int) ($existing['review_lock_version'] ?? 0);

        // Absent, not just mismatched: a client that omits the version is not "unversioned", it is
        // a client that has not read the record it is trying to overwrite.
        if (!array_key_exists('review_lock_version', $input) || $input['review_lock_version'] === '') {
            throw new ReviewConflictException(
                'This disposition did not carry a lock version, so it cannot be checked against '
                . 'what is currently saved. Reload the finding and try again.',
                $current
            );
        }

        $claimed = (int) $input['review_lock_version'];

        if ($claimed !== $current) {
            throw new ReviewConflictException(sprintf(
                'Somebody else saved this finding while you were reviewing it (you were looking at '
                . 'version %d; it is now at version %d). Your text has not been discarded - reload '
                . 'to see their decision, then re-apply yours if you still disagree.',
                $claimed,
                $current
            ), $current);
        }
    }

    /** @param array<string,string> $write */
    private function assertOnlyReviewFields(array $write): void
    {
        foreach (array_keys($write) as $field) {
            if (in_array($field, self::WRITABLE, true)) {
                continue;
            }

            // Thrown, not filtered. A filtered-out `finding_urgency` would mean a reviewer's
            // correction silently did nothing; a thrown one is a bug someone fixes.
            throw new \LogicException(sprintf(
                'DispositionService tried to write "%s", which is not a review field. Model-populated '
                . 'finding_* fields are written once at creation and never again - the authoritative '
                . 'copy is on the scan run. Writable: %s',
                $field,
                implode(', ', self::WRITABLE)
            ));
        }
    }
}
