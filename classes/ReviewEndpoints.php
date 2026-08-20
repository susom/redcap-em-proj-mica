<?php

namespace Stanford\MICA;

require_once __DIR__ . "/AuditLogger.php";
require_once __DIR__ . "/DispositionService.php";
require_once __DIR__ . "/ReviewAccessException.php";
require_once __DIR__ . "/ReviewConflictException.php";
require_once __DIR__ . "/ReviewQueue.php";
require_once __DIR__ . "/ReviewQueryStoreInterface.php";
require_once __DIR__ . "/RoleService.php";

/**
 * The review dashboard's AJAX surface: one method per action, and the same three steps every time.
 *
 *     check the role  ->  do the work  ->  record that it happened
 *
 * Kept out of `MICA.php` so the module's switch stays a dispatch table and this stays reviewable as
 * a unit. Everything here is behind `auth-ajax-actions`, so REDCap has already established who the
 * caller is before any of it runs - but that only proves they are *logged in*, which is not the same
 * as entitled, so every method still asks RoleService.
 *
 * ## Two invariants worth stating
 *
 * **A denied request is audited.** An access-control system that records only what it allowed
 * answers the wrong question: "who tried" is what an auditor actually needs.
 *
 * **The auditor's view is enforced here, not chosen by the client.** `reviewHistory` returns
 * aggregates when `isDeidentifiedOnly()` says so; a client asking for detail is refused it rather
 * than trusted. Otherwise the de-identification is a request parameter.
 */
class ReviewEndpoints
{
    private ReviewQueryStoreInterface $store;
    private DispositionService $dispositions;
    private RoleService $roles;
    private AuditLogger $audit;
    private string $projectId;

    public function __construct(
        ReviewQueryStoreInterface $store,
        DispositionService $dispositions,
        RoleService $roles,
        AuditLogger $audit,
        string $projectId
    ) {
        $this->store = $store;
        $this->dispositions = $dispositions;
        $this->roles = $roles;
        $this->audit = $audit;
        $this->projectId = $projectId;
    }

    /**
     * Dispatch one action. Returns the response body as an array; the caller JSON-encodes it.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle(string $action, ?string $username, array $payload): array
    {
        try {
            $this->roles->requireRole($username, $action);
        } catch (ReviewAccessException $e) {
            // Audited, then refused. "Who tried" is what an auditor needs.
            $this->audit->record($username, AuditLogger::ACCESS_DENIED, 'action', $action, [
                'denied_action' => $action,
            ]);

            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 403];
        }

        try {
            return ['ok' => true] + match ($action) {
                'reviewQueue'       => $this->reviewQueue($username, $payload),
                'reviewSession'     => $this->reviewSession($username, $payload),
                'submitDisposition' => $this->submitDisposition($username, $payload),
                'reviewHistory'     => $this->reviewHistory($username, $payload),
                'auditTrail'        => $this->auditTrail($username, $payload),
                // Declared in the matrix and not yet implemented. Saying so beats a generic error
                // that reads as a permissions problem.
                'submitAction',
                'getPolicy',
                'savePolicy',
                'launchReadiness'   => throw new \RuntimeException(
                    "\"$action\" is not implemented yet (Stage 6)."
                ),
                default             => throw new \RuntimeException("Unknown action \"$action\"."),
            };
        } catch (ReviewConflictException $e) {
            // 409 and the current version, so the SPA can reload to the right thing without a
            // second round trip at the exact moment the reviewer is already annoyed.
            return [
                'ok'              => false,
                'error'           => $e->getMessage(),
                'status'          => 409,
                'current_version' => $e->currentVersion,
            ];
        } catch (ReviewAccessException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 403];
        } catch (TranscriptException | \InvalidArgumentException $e) {
            // Expected, caller-facing problems: a bad disposition, an unparseable filter date, a
            // finding that no longer exists. The message is written for the reviewer.
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 400];
        }
    }

    /** @return array<string,mixed> */
    private function reviewQueue(?string $username, array $payload): array
    {
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

        $rows = ReviewQueue::sort(ReviewQueue::filter($this->store->queueRows($this->projectId), $filters));

        $this->audit->record($username, AuditLogger::QUEUE_VIEW, 'project', $this->projectId, [
            'result_count' => count($rows),
            'filters'      => array_keys(array_filter($filters, static fn($v): bool => $v !== '' && $v !== [])),
        ]);

        return [
            'queue'   => $rows,
            'summary' => ReviewQueue::summarise($rows),
        ];
    }

    /** @return array<string,mixed> */
    private function reviewSession(?string $username, array $payload): array
    {
        $record = (string) ($payload['record'] ?? '');
        $eventId = (int) ($payload['event_id'] ?? 0);
        $instance = (int) ($payload['instance'] ?? 1);

        if ($record === '' || $eventId === 0) {
            throw new TranscriptException('A session needs a record and an event to be read.');
        }

        $session = $this->store->session($this->projectId, $record, $eventId, $instance);

        if ($session === null) {
            throw new TranscriptException(
                "There is no MICA session for record $record at that event and instance."
            );
        }

        // Reading a transcript is the most sensitive thing this dashboard does, so it is audited
        // before the payload leaves - and by transcript ref, so the audit row says which version.
        $this->audit->record($username, AuditLogger::SESSION_VIEW, 'session', $record, [
            'record'         => $record,
            'event_id'       => $eventId,
            'instance'       => $instance,
            'transcript_ref' => (string) ($session['transcript_ref'] ?? ''),
            'finding_count'  => count($session['findings'] ?? []),
        ]);

        return ['session' => $session];
    }

    /** @return array<string,mixed> */
    private function submitDisposition(?string $username, array $payload): array
    {
        // DispositionService re-checks the role itself. That is not redundant: it is the entry point
        // a future caller might reach directly, and the guarantee belongs with the write.
        return ['disposition' => $this->dispositions->submit(
            $this->projectId,
            (string) ($payload['record'] ?? ''),
            (int) ($payload['event_id'] ?? 0),
            (int) ($payload['instance'] ?? 1),
            $username,
            is_array($payload['review'] ?? null) ? $payload['review'] : []
        )];
    }

    /** @return array<string,mixed> */
    private function reviewHistory(?string $username, array $payload): array
    {
        $deidentified = $this->roles->isDeidentifiedOnly($username);
        $rows = $this->store->historyRows($this->projectId);

        $this->audit->record($username, AuditLogger::HISTORY_VIEW, 'project', $this->projectId, [
            'result_count' => count($rows),
            'deidentified' => $deidentified,
        ]);

        if ($deidentified) {
            // Enforced here rather than requested by the client - otherwise de-identification is a
            // request parameter, which is not a control at all.
            return [
                'deidentified' => true,
                'summary'      => ReviewQueue::summarise($rows),
                'history'      => [],
            ];
        }

        return ['deidentified' => false, 'history' => $rows, 'summary' => ReviewQueue::summarise($rows)];
    }

    /** @return array<string,mixed> */
    private function auditTrail(?string $username, array $payload): array
    {
        $limit = max(1, min(500, (int) ($payload['limit'] ?? 200)));
        $events = $this->store->auditEvents($this->projectId, $limit);

        // Reading the audit trail is itself audited. Otherwise the one action with no oversight is
        // the oversight.
        $this->audit->record($username, AuditLogger::AUDIT_VIEW, 'project', $this->projectId, [
            'result_count' => count($events),
        ]);

        return ['events' => $events];
    }
}
