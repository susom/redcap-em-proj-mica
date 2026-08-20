<?php

namespace Stanford\MICA;

require_once __DIR__ . "/AuditLogger.php";
require_once __DIR__ . "/FindingReviewStoreInterface.php";
require_once __DIR__ . "/NotificationChannelInterface.php";
require_once __DIR__ . "/NotificationPolicy.php";
require_once __DIR__ . "/NotificationResult.php";
require_once __DIR__ . "/NotificationStoreInterface.php";
require_once __DIR__ . "/RecipientDirectoryInterface.php";

/**
 * Everything that leaves the module addressed to a human, and the one rule that governs it.
 *
 * ## The rule
 *
 * Nothing that reaches a care team, a PI, a protocol lead, or a formal privacy or model-quality
 * review may be sent for a finding a human has not confirmed. The handoff states it as: "Only
 * confirmed findings expose approved care-team, PI, protocol, privacy, or model-quality actions."
 *
 * That rule is enforced **here, at the exit**, and nowhere else is allowed to be the only place it
 * holds. `submitAction` asserts it too, but as defence in depth - if the two ever disagree, this one
 * wins, because this is the class that actually hands bytes to a transport.
 *
 * ## Why this class re-reads the finding
 *
 * `deliverActions()` takes a record locator, not a finding array. Taking the caller's copy of
 * `review_status` would make the gate decorative: a stale read from before a concurrent dismissal, or
 * a caller that assembles `['review_status' => 'confirmed']` itself, would sail through - and a test
 * double would hand over exactly the shape the code expected, so it would pass green. So the
 * authoritative read happens inside, and the `review_lock_version` observed at that moment is
 * recorded on the notification row. An email cannot be recalled; the honest artifact is evidence that
 * the finding was confirmed at version N when the notice went out.
 *
 * ## Why some actions are not gated
 *
 * Two of the seven are internal to the review workflow. `second_reviewer` is a *pre-confirmation*
 * step by definition - `needs_second_review` is a disposition - so gating it on confirmation would
 * make it unreachable. `document_no_action` is bookkeeping and delivers to nobody.
 *
 * This split matters most for `scan_failure` placeholders. Those rows exist because a scan did not
 * complete and a human must read the session manually; they are not model findings and are not
 * confirmed as such. Requiring confirmation uniformly would strand `document_no_action` on exactly
 * the rows where staff most need to record that they handled it.
 *
 * ## What is not in a notification body
 *
 * No participant quotes, no reviewer rationale, no reviewer notes. A notice carries *that* there is
 * a confirmed concern, its type and urgency, which record, and where to see the rest - the detail
 * stays behind REDCap's user rights on the finding form. A study that needs more in the body is
 * asking for a policy change, and should have to make it as one.
 */
class NotificationService
{
    // Notification types. Also the second half of every idempotency key.
    public const REVIEWERS_READY = 'reviewers_ready';
    public const PRE_REVIEW      = 'pre_review';
    public const ACTION_DELIVERY = 'action_delivery';
    public const DIGEST          = 'digest';
    public const ACK_OVERDUE     = 'ack_overdue';

    /**
     * Review statuses a gated action may be delivered from.
     *
     * A list rather than an inline `=== 'confirmed'` so that the day someone adds an `escalated`
     * transition there is one place to revisit. `escalated` and `resolved` appear in the workflow
     * schema but `DispositionService` cannot write them, so they are unreachable today; if that
     * changes, whether an escalation implies confirmation is a decision to make deliberately here
     * rather than to inherit from a widened comparison somewhere else.
     */
    public const DELIVERABLE_STATUSES = ['confirmed'];

    /** Actions that reach someone outside the review team, or open a formal review. */
    public const GATED_ACTIONS = [
        'alert_care_team',
        'alert_pi',
        'alert_protocol_lead',
        'privacy_review',
        'model_quality_review',
    ];

    /** Actions internal to the review workflow. See the class comment. */
    public const UNGATED_ACTIONS = [
        'second_reviewer',
        'document_no_action',
    ];

    /** Which policy role hears about each action. `document_no_action` deliberately has none. */
    private const RECIPIENTS_FOR_ACTION = [
        'alert_care_team'      => ['care_team'],
        'alert_pi'             => ['principal_investigator'],
        'alert_protocol_lead'  => ['protocol_lead'],
        'privacy_review'       => ['protocol_lead'],
        'model_quality_review' => ['protocol_lead'],
        'second_reviewer'      => ['__reviewers__'],
        'document_no_action'   => [],
    ];

    /** The pseudo-role that resolves through the reviewer role rather than the policy. */
    private const REVIEWERS = '__reviewers__';

    /** The only keys a digest may carry, and they are all counts. */
    public const DIGEST_BUCKETS = [
        'sessions_scanned',
        'findings_total',
        'pending_review',
        'confirmed',
        'dismissed',
        'needs_second_review',
        'urgency_critical',
        'urgency_high',
        'urgency_moderate',
        'urgency_quality',
        'scan_failures',
        'overdue_acknowledgment',
    ];

    /** Values allowed to be interpolated into a subject line. See enumOr(). */
    private const SUBJECT_ENUMS = [
        'urgency'      => ['quality', 'moderate', 'high', 'critical', 'none'],
        'session_type' => ['baseline', 'followup', 'r01', 'unknown'],
        'cadence'      => ['daily', 'weekly'],
    ];

    /** Substituted when a value is not a recognised enum member. */
    public const UNSPECIFIED = 'unspecified';

    private NotificationPolicy $policy;
    private NotificationChannelInterface $channel;
    private NotificationStoreInterface $store;
    private RecipientDirectoryInterface $directory;
    private FindingReviewStoreInterface $findings;
    private AuditLogger $audit;
    private string $projectId;
    private string $dashboardUrl;
    /** @var callable(): int */
    private $clock;

    /** @param callable(): int|null $clock */
    public function __construct(
        NotificationPolicy $policy,
        NotificationChannelInterface $channel,
        NotificationStoreInterface $store,
        RecipientDirectoryInterface $directory,
        FindingReviewStoreInterface $findings,
        AuditLogger $audit,
        string $projectId,
        string $dashboardUrl = '',
        ?callable $clock = null
    ) {
        $this->policy = $policy;
        $this->channel = $channel;
        $this->store = $store;
        $this->directory = $directory;
        $this->findings = $findings;
        $this->audit = $audit;
        $this->projectId = $projectId;
        $this->dashboardUrl = $dashboardUrl;
        $this->clock = $clock ?? static fn(): int => time();
    }

    // ------------------------------------------------------------------ 1. findings are ready

    /**
     * Tell the reviewer role that a session has findings waiting.
     *
     * The only notice that fires without any human involvement, and the reason that is acceptable is
     * that its audience is the people whose job is to review: telling an RA there is something in
     * their queue is not a disclosure, it is the queue working.
     */
    public function notifyReviewersReady(
        int $jobId,
        string $record,
        int $eventId,
        int $instance,
        string $sessionType,
        int $findingCount,
        string $topUrgency,
        bool $manualReviewRequired = false
    ): NotificationResult {
        $type = self::REVIEWERS_READY;

        if (!$this->policy->notifyReviewersWhenReady()) {
            return NotificationResult::skipped($type, 'The policy does not notify reviewers when ready.');
        }

        // Keyed on the job, so a transient scan failure that retries and lands in ready_for_review a
        // second time does not send a second email about the same session.
        $key = $this->idempotencyKey($type, (string) $jobId);

        if ($this->store->alreadySent($key)) {
            return NotificationResult::skipped($type, "Already sent for job $jobId.");
        }

        $recipients = $this->directory->reviewerAddresses();

        $subject = $manualReviewRequired
            ? sprintf(
                '[MICA] A session could not be screened and needs manual review (%s)',
                $this->enumOr($sessionType, 'session_type')
            )
            : sprintf(
                '[MICA] %d SafetyScan finding(s) ready for review - highest urgency: %s',
                max(0, $findingCount),
                $this->enumOr($topUrgency, 'urgency')
            );

        $body = $manualReviewRequired
            ? $this->manualReviewBody($record, $sessionType)
            : $this->reviewersReadyBody($record, $sessionType, $findingCount, $topUrgency);

        return $this->deliver($type, $key, 'secure_email', $recipients, $subject, $body, [
            'job_id'   => $jobId,
            'record'   => $record,
            'event_id' => $eventId,
            'instance' => $instance,
        ]);
    }

    // ------------------------------------------------------------------ 2. pre-review (normally off)

    /**
     * Notify before a human has looked - only if the protocol has approved it.
     *
     * Off in the shipped default and expected to stay off. The infrastructure exists because a
     * protocol could approve it for critical urgencies, not because it is expected to be used, and
     * every body it produces carries the pinned unverified-finding label. See preReviewBody().
     */
    public function notifyPreReview(
        string $record,
        int $eventId,
        int $instance,
        string $findingId,
        string $concernType,
        string $urgency
    ): NotificationResult {
        $type = self::PRE_REVIEW;

        if (!$this->policy->preReviewEnabled()) {
            return NotificationResult::skipped(
                $type,
                'Pre-review notification is disabled by policy. Nothing reaches a care team or PI '
                . 'before a human has confirmed the finding.'
            );
        }

        if (!in_array($urgency, $this->policy->preReviewUrgencies(), true)) {
            return NotificationResult::skipped(
                $type,
                sprintf('Urgency "%s" is not eligible for pre-review notification.', $urgency)
            );
        }

        $roles = $this->policy->preReviewRecipientRoles();
        $recipients = $this->resolveRoles($roles);

        if ($recipients === []) {
            // Recorded as a failure, not a skip: the policy said to tell someone and there was
            // nobody to tell. That is a configuration fault with clinical weight.
            return $this->recordFailure(
                $type,
                $this->idempotencyKey($type, "$record:$instance"),
                sprintf(
                    'Pre-review notification is enabled for %s but no address resolves for role(s) %s.',
                    $urgency,
                    $roles === [] ? '(none configured)' : implode(', ', $roles)
                ),
                ['record' => $record, 'event_id' => $eventId, 'instance' => $instance]
            );
        }

        $key = $this->idempotencyKey($type, "$record:$instance:$findingId");

        if ($this->store->alreadySent($key)) {
            return NotificationResult::skipped($type, 'Already sent for this finding.');
        }

        return $this->deliver(
            $type,
            $key,
            'secure_email',
            $recipients,
            sprintf('[MICA] UNVERIFIED %s finding pending review', $this->enumOr($urgency, 'urgency')),
            $this->preReviewBody($record, $concernType, $urgency),
            ['record' => $record, 'event_id' => $eventId, 'instance' => $instance, 'urgency' => $urgency]
        );
    }

    // ------------------------------------------------------------------ 3. the gate

    /**
     * Deliver the actions an RA selected for one finding.
     *
     * Re-reads the finding to decide, then refuses the **whole** call if any gated action was asked
     * for on an unconfirmed finding. Partial delivery would leave the reviewer with a screen that
     * says some of what they clicked happened, and no clear statement of which - a worse outcome than
     * a plain refusal they can act on.
     *
     * @param list<string> $actionTypes
     * @return array{result:NotificationResult,per_action:array<string,NotificationResult>}
     * @throws \InvalidArgumentException on an unknown action type - a programming error
     */
    public function deliverActions(
        string $record,
        int $eventId,
        int $instance,
        array $actionTypes,
        ?string $actor
    ): array {
        $type = self::ACTION_DELIVERY;
        $actionTypes = array_values(array_unique(array_map('strval', $actionTypes)));

        $known = array_merge(self::GATED_ACTIONS, self::UNGATED_ACTIONS);
        $unknown = array_diff($actionTypes, $known);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown action type(s): %s. Known: %s.',
                implode(', ', $unknown),
                implode(', ', $known)
            ));
        }

        if ($actionTypes === []) {
            return $this->single(NotificationResult::skipped($type, 'No action was selected.'));
        }

        // The authoritative read. Never the caller's copy - see the class comment.
        $finding = $this->findings->readFinding($this->projectId, $record, $eventId, $instance);

        if ($finding === null) {
            return $this->single(NotificationResult::refused(
                $type,
                "There is no finding at instance $instance for record $record. It may have been "
                . 'deleted; reload the queue.'
            ));
        }

        $status = (string) ($finding['review_status'] ?? '');
        $lockVersion = (int) ($finding['review_lock_version'] ?? 0);
        $gated = array_values(array_intersect($actionTypes, self::GATED_ACTIONS));

        if ($gated !== [] && !in_array($status, self::DELIVERABLE_STATUSES, true)) {
            $reason = sprintf(
                'This finding is "%s", not confirmed, so %s cannot be sent. Nothing reaches a care '
                . 'team, PI, protocol lead, or a formal privacy or model-quality review until a human '
                . 'has confirmed the finding.',
                $status === '' ? 'not yet reviewed' : $status,
                implode(', ', $gated)
            );

            // Recorded, because a gate that fires silently cannot be shown to have fired.
            $this->store->recordNotification([
                'notification_type' => $type,
                'idempotency_key'   => $this->idempotencyKey($type, "$record:$instance:refused:$status"),
                'project_id'        => $this->projectId,
                'record'            => $record,
                'event_id'          => $eventId,
                'instance'          => $instance,
                'lock_version'      => $lockVersion,
                'status'            => NotificationResult::REFUSED,
                'channel'           => '',
                'recipient_count'   => 0,
                'subject'           => '',
                'body_sha256'       => '',
                'error'             => $reason,
                'actor'             => (string) $actor,
                'sent_at'           => ($this->clock)(),
            ]);

            $this->audit->record($actor, AuditLogger::ACCESS_DENIED, 'finding', $record, [
                'denied_action' => 'submitAction',
                'review_status' => $status,
                'action_types'  => $gated,
                'instance'      => $instance,
            ]);

            return $this->single(NotificationResult::refused($type, $reason));
        }

        $perAction = [];
        $fields = [];
        $anySent = false;
        $anyFailed = false;

        foreach ($actionTypes as $action) {
            $result = $this->deliverOneAction(
                $action,
                $record,
                $eventId,
                $instance,
                $finding,
                $lockVersion,
                $actor
            );

            $perAction[$action] = $result;
            $anySent = $anySent || $result->wasSent();
            $anyFailed = $anyFailed || $result->outcome === NotificationResult::FAILED;

            // The checkbox is ticked for every action the reviewer decided on, delivered or not. It
            // records the decision; action_delivery_status records what became of it.
            $fields["action_types___$action"] = '1';
        }

        $fields['action_initiated_by'] = (string) $actor;
        $fields['action_delivery_status'] = $anyFailed
            ? 'failed'
            : ($anySent ? 'sent' : 'not_applicable');
        $fields['action_payload_min'] = $this->payloadSummary($perAction);
        $fields['action_completed_at'] = date('Y-m-d H:i:s', ($this->clock)());

        $this->audit->record($actor, AuditLogger::ACTION_SENT, 'finding', $record, [
            'action_types'    => $actionTypes,
            'instance'        => $instance,
            'event_id'        => $eventId,
            'review_status'   => $status,
            'lock_version'    => $lockVersion,
            'recipient_count' => array_sum(array_map(
                static fn(NotificationResult $r): int => $r->recipientCount,
                $perAction
            )),
        ]);

        $overall = $anyFailed
            ? NotificationResult::failed(
                $type,
                'At least one action could not be delivered.',
                null,
                // The fields go back even on failure: action_delivery_status is how the finding form
                // shows that a delivery was attempted and did not land.
                $fields
            )
            : NotificationResult::sent(
                $type,
                array_sum(array_map(
                    static fn(NotificationResult $r): int => $r->recipientCount,
                    $perAction
                )),
                ['secure_email'],
                $fields
            );

        return ['result' => $overall, 'per_action' => $perAction];
    }

    /** @param array<string,string> $finding */
    private function deliverOneAction(
        string $action,
        string $record,
        int $eventId,
        int $instance,
        array $finding,
        int $lockVersion,
        ?string $actor
    ): NotificationResult {
        $type = self::ACTION_DELIVERY;
        $roles = self::RECIPIENTS_FOR_ACTION[$action] ?? [];

        if ($roles === []) {
            // document_no_action: a real decision, with nothing to deliver.
            return NotificationResult::skipped($type, "\"$action\" records a decision and sends nothing.");
        }

        $recipients = $this->resolveRoles($roles);

        // The lock version is in the key on purpose: an RA who corrects a finding and re-confirms it
        // has produced a genuinely different disposition, and the care team should hear the corrected
        // one rather than have it suppressed as a duplicate.
        $key = $this->idempotencyKey($type, "$record:$instance:$action:v$lockVersion");

        if ($this->store->alreadySent($key)) {
            return NotificationResult::skipped($type, "\"$action\" already sent at version $lockVersion.");
        }

        if ($recipients === []) {
            return $this->recordFailure(
                $type,
                $key,
                sprintf(
                    'No address resolves for role(s) %s, so "%s" could not be delivered. Configure the '
                    . 'recipient directory - a confirmed finding with nowhere to go is a silent failure.',
                    implode(', ', $roles),
                    $action
                ),
                ['record' => $record, 'event_id' => $eventId, 'instance' => $instance, 'actor' => $actor]
            );
        }

        $urgency = $this->effectiveUrgency($finding);

        return $this->deliver(
            $type,
            $key,
            'secure_email',
            $recipients,
            sprintf(
                '[MICA] Confirmed %s finding requires attention (%s)',
                $this->enumOr($urgency, 'urgency'),
                $this->actionLabel($action)
            ),
            $this->actionBody($action, $record, $finding, $actor),
            [
                'record'       => $record,
                'event_id'     => $eventId,
                'instance'     => $instance,
                'lock_version' => $lockVersion,
                'action'       => $action,
                'actor'        => $actor,
            ]
        );
    }

    // ------------------------------------------------------------------ 4. digests

    /**
     * Send the enabled digests for a cadence.
     *
     * Aggregates only. `digestCounts()` can return nothing else, and the shape is re-validated here
     * because an interface is a promise rather than a guarantee.
     *
     * @return list<NotificationResult>
     */
    public function sendDigests(string $cadence, int $sinceTs, ?int $untilTs = null): array
    {
        $untilTs ??= ($this->clock)();
        $digests = $this->policy->digestsFor($cadence);

        if ($digests === []) {
            return [NotificationResult::skipped(self::DIGEST, "No enabled $cadence digest.")];
        }

        $counts = $this->safeCounts($this->store->digestCounts($this->projectId, $sinceTs, $untilTs));

        // Overwritten here, not taken from the store: "overdue" means "past the policy's window", and
        // the store has no policy to measure that against. Left to the store it would have counted
        // every unacknowledged notice, which is a different and much larger number - and reporting it
        // under this label would overstate the problem on the one line a PI is most likely to act on.
        $counts['overdue_acknowledgment'] = $this->overdueCount() ?? 0;

        $results = [];

        foreach ($digests as $digest) {
            $digestId = (string) ($digest['digest_id'] ?? 'digest');
            $roles = is_array($digest['recipient_roles'] ?? null) ? $digest['recipient_roles'] : [];
            $channel = (string) ($digest['delivery_channel'] ?? 'secure_email');
            $recipients = $this->resolveRoles(array_map('strval', $roles));
            $key = $this->idempotencyKey(self::DIGEST, "$digestId:$sinceTs:$untilTs");

            if ($this->store->alreadySent($key)) {
                $results[] = NotificationResult::skipped(self::DIGEST, "\"$digestId\" already sent.");
                continue;
            }

            if ($recipients === []) {
                $results[] = $this->recordFailure(
                    self::DIGEST,
                    $key,
                    sprintf('Digest "%s" is enabled but no address resolves for its roles.', $digestId),
                    ['digest_id' => $digestId]
                );
                continue;
            }

            $results[] = $this->deliver(
                self::DIGEST,
                $key,
                $channel,
                $recipients,
                sprintf(
                    '[MICA] %s SafetyScan summary - %d finding(s) awaiting review',
                    ucfirst($this->enumOr($cadence, 'cadence')),
                    $counts['pending_review'] ?? 0
                ),
                $this->digestBody($cadence, $counts, $sinceTs, $untilTs),
                ['digest_id' => $digestId]
            );
        }

        return $results;
    }

    // ------------------------------------------------------------------ 5. acknowledgment monitor

    /**
     * Chase findings nobody has acknowledged inside the policy's window.
     *
     * Returns a skip when the window is unset, which is the shipped state - and is also
     * `LaunchReadiness`'s deliberate blocker. A study that has not decided its acknowledgment target
     * gets no monitoring, and the launch gate is what makes that visible rather than silent.
     *
     * @return list<NotificationResult>
     */
    public function notifyOverdueAcknowledgments(): array
    {
        $minutes = $this->policy->criticalAcknowledgmentMinutes();

        if ($minutes === null) {
            return [NotificationResult::skipped(
                self::ACK_OVERDUE,
                'No critical-finding acknowledgment target is set, so nothing can be overdue. This is '
                . 'the launch blocker the handoff ships on purpose: study leadership decides the target.'
            )];
        }

        $cutoff = ($this->clock)() - ($minutes * 60);
        $overdue = $this->store->unacknowledged($this->projectId, $cutoff);

        if ($overdue === []) {
            return [NotificationResult::skipped(self::ACK_OVERDUE, 'Nothing is overdue.')];
        }

        /**
         * Send-once is keyed on each overdue *notice*, never on the cutoff.
         *
         * The cutoff is `now - minutes`, so it moves every second: a key built from it would be
         * different on every five-minute cron run, `alreadySent()` would never match, and every
         * reviewer would be emailed every five minutes for as long as anything was unacknowledged -
         * about a list that only grows until somebody acknowledges something. That is precisely the
         * alert fatigue the "only notify when a job has stopped moving" rule exists to avoid, and it
         * would arrive the moment a study set its acknowledgment target, which is the first thing they
         * do when going live.
         *
         * So each notice is chased exactly once, ever. The nags are batched into one message rather
         * than one per notice, because twenty overdue findings should not be twenty emails.
         */
        $fresh = [];

        foreach ($overdue as $row) {
            $id = (int) ($row['notification_id'] ?? 0);

            if ($id === 0) {
                // A store that cannot identify its own rows cannot support send-once. Better to say
                // so than to nag forever.
                return [NotificationResult::failed(
                    self::ACK_OVERDUE,
                    'The notification store returned an overdue row with no notification_id, so this '
                    . 'nag could not be made send-once. Refusing rather than emailing every reviewer '
                    . 'on every cron run.'
                )];
            }

            $key = $this->idempotencyKey(self::ACK_OVERDUE, 'notice:' . $id);

            if (!$this->store->alreadySent($key)) {
                $fresh[] = $row + ['__key' => $key];
            }
        }

        if ($fresh === []) {
            return [NotificationResult::skipped(
                self::ACK_OVERDUE,
                sprintf('%d notice(s) are overdue and every one has already been chased.', count($overdue))
            )];
        }

        $recipients = $this->directory->reviewerAddresses();

        if ($recipients === []) {
            return [$this->recordFailure(
                self::ACK_OVERDUE,
                $fresh[0]['__key'],
                sprintf(
                    '%d notice(s) are past the %d-minute window and no reviewer address resolves.',
                    count($fresh),
                    $minutes
                ),
                []
            )];
        }

        $subject = sprintf(
            '[MICA] %d finding(s) past the %d-minute acknowledgment window',
            count($fresh),
            $minutes
        );
        $body = $this->overdueBody($fresh, $minutes);

        if (!$this->channel->supports('secure_email')) {
            return [$this->recordFailure(
                self::ACK_OVERDUE,
                $fresh[0]['__key'],
                'This deployment cannot deliver secure_email, so overdue findings cannot be chased.',
                []
            )];
        }

        try {
            $this->channel->send('secure_email', $recipients, $subject, $body);
        } catch (\Throwable $e) {
            // Recorded per notice without a dedupe key, so the next cron run retries all of them.
            $results = [];
            foreach ($fresh as $row) {
                $results[] = $this->recordFailure(
                    self::ACK_OVERDUE,
                    $row['__key'],
                    $e->getMessage(),
                    ['record' => (string) ($row['record'] ?? ''), 'channel' => 'secure_email']
                );
            }

            return $results;
        }

        // One row per notice chased - that is what makes each one send-once - for a single message.
        $results = [];

        foreach ($fresh as $row) {
            $logId = $this->store->recordNotification($this->row(
                self::ACK_OVERDUE,
                $row['__key'],
                NotificationResult::SENT,
                'secure_email',
                count($recipients),
                $subject,
                $body,
                '',
                [
                    'record'   => (string) ($row['record'] ?? ''),
                    'event_id' => (int) ($row['event_id'] ?? 0),
                    'instance' => (int) ($row['instance'] ?? 0),
                ]
            ));

            $results[] = NotificationResult::sent(
                self::ACK_OVERDUE,
                count($recipients),
                ['secure_email'],
                [],
                $logId
            );
        }

        return $results;
    }

    /**
     * How many notices are overdue right now, or null when no target is set.
     *
     * Lives here rather than in the store because "overdue" is a policy question and the store has no
     * policy. The digest's `overdue_acknowledgment` bucket is filled from this.
     */
    public function overdueCount(): ?int
    {
        $minutes = $this->policy->criticalAcknowledgmentMinutes();

        if ($minutes === null) {
            return null;
        }

        return count($this->store->unacknowledged($this->projectId, ($this->clock)() - ($minutes * 60)));
    }

    // ------------------------------------------------------------------ bodies

    private function reviewersReadyBody(
        string $record,
        string $sessionType,
        int $findingCount,
        string $topUrgency
    ): string {
        return implode("\n", [
            'A MICA session has been screened and has findings waiting for review.',
            '',
            "Record: $record",
            'Session type: ' . $this->enumOr($sessionType, 'session_type'),
            'Findings: ' . max(0, $findingCount),
            'Highest urgency: ' . $this->enumOr($topUrgency, 'urgency'),
            '',
            'Open the review dashboard to read the transcript and disposition each finding:',
            $this->dashboardUrl,
            '',
            'These are automated findings. Nothing has been sent to a care team, PI or protocol lead, '
            . 'and nothing will be until you confirm a finding.',
        ]);
    }

    private function manualReviewBody(string $record, string $sessionType): string
    {
        return implode("\n", [
            'A MICA session could NOT be screened automatically, so it has not been checked for '
            . 'safety concerns at all. It needs a manual read.',
            '',
            "Record: $record",
            'Session type: ' . $this->enumOr($sessionType, 'session_type'),
            '',
            'Open the review dashboard, read the transcript, and record what you found:',
            $this->dashboardUrl,
            '',
            'A failed scan is not an all-clear. Treat this session as unscreened.',
        ]);
    }

    /**
     * The one body that must carry the pinned label, on every path through it.
     *
     * Single method, label prepended unconditionally, and a test asserts no pre-review body can be
     * produced without it. A constant is only a guarantee if every path carries it - and the reader
     * here is the person least able to check whether a model was right.
     */
    private function preReviewBody(string $record, string $concernType, string $urgency): string
    {
        return implode("\n", [
            strtoupper($this->policy->preReviewLabel()),
            '',
            'A human has NOT yet reviewed this. It is raw automated output and may be wrong.',
            '',
            "Record: $record",
            'Concern type (unverified): ' . $concernType,
            'Urgency (unverified): ' . $urgency,
            '',
            'Your protocol has approved notifying you before review for this urgency. Confirm with '
            . 'the study team before acting on it clinically.',
            $this->dashboardUrl,
        ]);
    }

    /** @param array<string,string> $finding */
    private function actionBody(string $action, string $record, array $finding, ?string $actor): string
    {
        $lines = [
            'A research assistant has reviewed and CONFIRMED a MICA SafetyScan finding, and selected '
            . 'this action: ' . $this->actionLabel($action) . '.',
            '',
            "Record: $record",
            'Concern type: ' . $this->effectiveConcern($finding),
            'Urgency: ' . $this->effectiveUrgency($finding),
            'Confirmed by: ' . ($actor ?? 'unknown'),
            'Confirmed at: ' . (string) ($finding['review_reviewed_at'] ?? ''),
            '',
            'Details, including the session transcript and the reviewer\'s rationale, are on the '
            . 'finding in REDCap:',
            $this->dashboardUrl,
        ];

        // Deliberately absent: the participant's words, the reviewer's rationale, the reviewer's
        // notes. The notice says that there is a confirmed concern and where the detail lives; the
        // detail itself stays behind REDCap's user rights. Widening this is a policy change.
        return implode("\n", $lines);
    }

    /** @param array<string,int> $counts */
    private function digestBody(string $cadence, array $counts, int $sinceTs, int $untilTs): string
    {
        $lines = [
            sprintf(
                'MICA SafetyScan %s summary for %s to %s.',
                $this->enumOr($cadence, 'cadence'),
                date('Y-m-d H:i', $sinceTs),
                date('Y-m-d H:i', $untilTs)
            ),
            '',
        ];

        // `label: n`, not space-padded columns. HTML collapses runs of spaces, so a `%-24s` column
        // that lines up in a terminal renders ragged in every mail client - and `white-space:pre-wrap`
        // is not dependable enough (Outlook's Word engine ignores it) to be worth relying on for
        // alignment. This reads identically in both parts of the message.
        foreach (self::DIGEST_BUCKETS as $bucket) {
            $lines[] = sprintf('%s: %d', ucfirst(str_replace('_', ' ', $bucket)), $counts[$bucket] ?? 0);
        }

        $lines[] = '';
        $lines[] = 'Counts only. This digest carries no participant-level detail by design - open the '
            . 'review dashboard for anything specific:';
        $lines[] = $this->dashboardUrl;

        return implode("\n", $lines);
    }

    /** @param list<array<string,mixed>> $overdue */
    private function overdueBody(array $overdue, int $minutes): string
    {
        $lines = [
            sprintf(
                '%d finding(s) have been waiting longer than the %d-minute acknowledgment window your '
                . 'study set for critical findings.',
                count($overdue),
                $minutes
            ),
            '',
        ];

        foreach ($overdue as $row) {
            $lines[] = sprintf(
                '- record %s, instance %d, urgency %s, notified %s',
                (string) ($row['record'] ?? '?'),
                (int) ($row['instance'] ?? 0),
                $this->enumOr((string) ($row['urgency'] ?? ''), 'urgency'),
                isset($row['notified_at']) ? date('Y-m-d H:i', (int) $row['notified_at']) : 'unknown'
            );
        }

        $lines[] = '';
        $lines[] = $this->dashboardUrl;

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Send, record, and turn a transport failure into a recorded failure rather than an exception.
     *
     * A notification that could not be sent must not take down the path that triggered it - a scan
     * worker that dies because a mail server was down leaves the finding unqueued as well as
     * unannounced. The row is the durable evidence; the caller gets a result it can report.
     *
     * @param list<string>        $recipients
     * @param array<string,mixed> $context
     */
    private function deliver(
        string $type,
        string $key,
        string $channel,
        array $recipients,
        string $subject,
        string $body,
        array $context
    ): NotificationResult {
        if (!$this->channel->supports($channel)) {
            return $this->recordFailure(
                $type,
                $key,
                sprintf('The policy asks for channel "%s", which this deployment cannot deliver.', $channel),
                $context
            );
        }

        try {
            $this->channel->send($channel, $recipients, $subject, $body);
        } catch (\Throwable $e) {
            return $this->recordFailure($type, $key, $e->getMessage(), $context + ['channel' => $channel]);
        }

        $logId = $this->store->recordNotification($this->row(
            $type,
            $key,
            NotificationResult::SENT,
            $channel,
            count($recipients),
            $subject,
            $body,
            '',
            $context
        ));

        return NotificationResult::sent($type, count($recipients), [$channel], [], $logId);
    }

    /** @param array<string,mixed> $context */
    private function recordFailure(string $type, string $key, string $error, array $context): NotificationResult
    {
        $logId = $this->store->recordNotification($this->row(
            $type,
            $key,
            NotificationResult::FAILED,
            (string) ($context['channel'] ?? ''),
            0,
            '',
            '',
            $error,
            $context
        ));

        return NotificationResult::failed($type, $error, $logId);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function row(
        string $type,
        string $key,
        string $status,
        string $channel,
        int $recipientCount,
        string $subject,
        string $body,
        string $error,
        array $context
    ): array {
        return [
            'notification_type' => $type,
            'idempotency_key'   => $key,
            // Only a row that actually went out claims the unique key. A failed attempt keeps
            // idempotency_key for correlation but leaves this blank, so its own retry is not blocked
            // by the failure - and two concurrent workers cannot both succeed. See
            // EntityTypes::indexes()['uq_notif_dedupe'].
            'dedupe_key'        => $status === NotificationResult::SENT ? $key : '',
            'project_id'        => $this->projectId,
            'record'            => (string) ($context['record'] ?? ''),
            'event_id'          => (int) ($context['event_id'] ?? 0),
            'instance'          => (int) ($context['instance'] ?? 0),
            'job_id'            => (int) ($context['job_id'] ?? 0),
            'lock_version'      => (int) ($context['lock_version'] ?? 0),
            'action'            => (string) ($context['action'] ?? ''),
            'digest_id'         => (string) ($context['digest_id'] ?? ''),
            'status'            => $status,
            'channel'           => $channel,
            'recipient_count'   => $recipientCount,
            // Safe to store verbatim: subjects are assembled from fixed strings, counts and enum
            // members only. See enumOr().
            'subject'           => $subject,
            // The body is not stored. A minimum-necessary body still names a record, and the
            // auditable verbatim copy belongs on the finding form where user rights govern it -
            // action_payload_min. What lives here is tamper-evidence.
            'body_sha256'       => $body === '' ? '' : hash('sha256', $body),
            'body_bytes'        => strlen($body),
            'error'             => $error,
            'actor'             => (string) ($context['actor'] ?? ''),
            'sent_at'           => ($this->clock)(),
        ];
    }

    /**
     * The key that makes a notice send-once.
     *
     * Includes the project so two projects on one REDCap cannot collide, and the type so a digest and
     * an action delivery about the same session are independent.
     */
    public function idempotencyKey(string $type, string $scope): string
    {
        return hash('sha256', implode('|', ['mica_notification_v1', $this->projectId, $type, $scope]));
    }

    /**
     * Resolve policy role names to addresses.
     *
     * @param list<string> $roles
     * @return list<string> deduplicated
     */
    private function resolveRoles(array $roles): array
    {
        $addresses = [];

        foreach ($roles as $role) {
            $found = $role === self::REVIEWERS
                ? $this->directory->reviewerAddresses()
                : $this->directory->addressesForRole($role);

            foreach ($found as $address) {
                $address = trim((string) $address);

                if ($address !== '') {
                    $addresses[strtolower($address)] = $address;
                }
            }
        }

        return array_values($addresses);
    }

    /**
     * Reduce whatever the store returned to counts in known buckets.
     *
     * The interface says counts per enum bucket; this is what makes that true regardless. An
     * unexpected key is dropped rather than passed through, because the failure mode being guarded
     * is a record id reaching a digest, and a digest goes to a wider audience than the dashboard.
     *
     * @param array<string,mixed> $counts
     * @return array<string,int>
     */
    private function safeCounts(array $counts): array
    {
        $safe = [];

        foreach (self::DIGEST_BUCKETS as $bucket) {
            $value = $counts[$bucket] ?? 0;
            $safe[$bucket] = is_numeric($value) ? (int) $value : 0;
        }

        return $safe;
    }

    /**
     * Keep a subject line to values that cannot carry free text.
     *
     * A subject travels through mail logs, notification previews and phone lock screens, so it holds
     * counts and enum members and nothing else. Anything unrecognised becomes "unspecified" rather
     * than being interpolated, which is what stops a record id or a participant's words reaching a
     * lock screen by way of a field that happened to be a string.
     */
    private function enumOr(string $value, string $enum): string
    {
        $allowed = self::SUBJECT_ENUMS[$enum] ?? [];

        return in_array($value, $allowed, true) ? $value : self::UNSPECIFIED;
    }

    /** @param array<string,string> $finding */
    private function effectiveUrgency(array $finding): string
    {
        $corrected = trim((string) ($finding['review_corrected_urgency'] ?? ''));

        return $corrected !== '' ? $corrected : (string) ($finding['finding_urgency'] ?? '');
    }

    /** @param array<string,string> $finding */
    private function effectiveConcern(array $finding): string
    {
        $corrected = trim((string) ($finding['review_corrected_concern_type'] ?? ''));

        return $corrected !== '' ? $corrected : (string) ($finding['finding_concern_type'] ?? '');
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'alert_care_team'      => 'alert the care team',
            'alert_pi'             => 'alert the principal investigator',
            'alert_protocol_lead'  => 'alert the protocol lead',
            'privacy_review'       => 'open a privacy review',
            'model_quality_review' => 'open a model-quality review',
            'second_reviewer'      => 'request a second reviewer',
            'document_no_action'   => 'document that no action was needed',
            default                => $action,
        };
    }

    /**
     * The verbatim record of what each action did, for action_payload_min.
     *
     * @param array<string,NotificationResult> $perAction
     */
    private function payloadSummary(array $perAction): string
    {
        $lines = [];

        foreach ($perAction as $action => $result) {
            $lines[] = sprintf(
                '%s: %s%s (%d recipient(s))',
                $action,
                $result->outcome,
                $result->reason === '' ? '' : ' - ' . $result->reason,
                $result->recipientCount
            );
        }

        return implode("\n", $lines);
    }

    /** @return array{result:NotificationResult,per_action:array<string,NotificationResult>} */
    private function single(NotificationResult $result): array
    {
        return ['result' => $result, 'per_action' => []];
    }
}
