<?php

namespace Stanford\MICA;

require_once __DIR__ . "/CanonicalJson.php";
require_once __DIR__ . "/FinalizeResult.php";
require_once __DIR__ . "/ScanQueue.php";
require_once __DIR__ . "/SchemaValidator.php";
require_once __DIR__ . "/SessionPseudoId.php";
require_once __DIR__ . "/TranscriptBuilder.php";
require_once __DIR__ . "/TranscriptException.php";
require_once __DIR__ . "/TranscriptStoreInterface.php";

/**
 * Turns a finished conversation into an immutable, hash-pinned, schema-valid transcript and queues
 * it for scanning.
 *
 * The A→B bridge of the architecture: everything before this is the counselor talking to a
 * participant, everything after is the safety pipeline reading what was said. It is the one place
 * where the study's only safety net either gets the conversation or does not.
 *
 * ## Ordering, and why it is this way
 *
 *   1. read the session's messages
 *   2. build + validate the payload
 *   3. canonicalize + hash
 *   4. write the transcript row (append-only; this is now the immutable record)
 *   5. enqueue the scan job, keyed to that hash
 *   6. write back to the session form  <-- last, and non-fatal
 *
 * The scan is queued *before* the session form is touched, because steps 1-5 are the safety path and
 * step 6 is staff visibility. A missing dictionary field must not be able to stop a transcript
 * being scanned.
 *
 * ## The session boundary
 *
 * There is no `mica_session_start_ts` in PID 257 (audit G4) and no `consent_date` at all, so the
 * boundary is derived instead of read: **this session is every message row after the
 * `max_message_log_id` recorded on the previous finalized transcript for this slot.** log_id is
 * monotonic and assigned by the database, which makes the boundary immune to clock skew, to a
 * participant's device clock, and to two sessions starting in the same second. The first session for
 * a slot has no previous transcript, so it takes every row - which is correct.
 */
class TranscriptFinalizer
{
    public const LOG_TYPE = 'mica_transcript';

    private TranscriptStoreInterface $store;
    private TranscriptBuilder $builder;
    private SchemaValidator $validator;
    private ScanQueue $queue;
    private string $salt;
    /** @var callable(): int */
    private $clock;
    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(): int|null        $clock
     * @param callable(string): void|null $logger
     */
    public function __construct(
        TranscriptStoreInterface $store,
        TranscriptBuilder $builder,
        SchemaValidator $validator,
        ScanQueue $queue,
        string $salt,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->store = $store;
        $this->builder = $builder;
        $this->validator = $validator;
        $this->queue = $queue;
        $this->salt = $salt;
        $this->clock = $clock ?? static fn(): int => time();
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    /**
     * Finalize the active session for a participant.
     *
     * @throws TranscriptException on anything that would produce an incomplete transcript
     */
    public function finalize(
        string $projectId,
        string $record,
        string $participantId,
        int $instance,
        int $eventId,
        string $sessionType,
        string $setting
    ): FinalizeResult {
        $previous = $this->store->latestTranscript($projectId, $record, $sessionType, $instance);
        $after = $previous === null ? 0 : (int) $previous['max_message_log_id'];
        $version = $previous === null ? 1 : ((int) $previous['version']) + 1;

        // Already finalized and nothing new since? Then this is a repeat call - a double-clicked
        // End Session, a retried request, a participant reloading - and it has to be an idempotent
        // success. Building a new version would raise "no messages could be read", which is true
        // but useless: the session *is* finalized, and telling the participant it failed would send
        // them round the loop again.
        //
        // It also recovers the one genuinely broken state: a finalize that wrote the transcript row
        // and then died before queueing (which is exactly what an unloaded redcap_entity did on the
        // first live run of this code). enqueue() is idempotent, so calling it here either creates
        // the missing job or confirms the existing one. Without this, that transcript could never be
        // scanned: the boundary had already advanced past every message it contained, so every
        // retry would report an empty session.
        if ($previous !== null && $this->store->messageRows($projectId, $participantId, $after) === []) {
            $job = $this->queue->enqueue(
                $projectId,
                $record,
                $instance,
                $sessionType,
                (int) $previous['log_id'],
                (string) $previous['transcript_sha256'],
                (int) $previous['version']
            );

            if ($job['created']) {
                $this->log(sprintf(
                    'recovered T%d: it was finalized but had no scan job, so one was queued now',
                    $previous['log_id']
                ));
            }

            return new FinalizeResult(
                transcriptLogId: (int) $previous['log_id'],
                transcriptRef: 'T' . $previous['log_id'],
                transcriptSha256: (string) $previous['transcript_sha256'],
                version: (int) $previous['version'],
                messageCount: (int) ($previous['message_count'] ?? 0),
                maxMessageLogId: $after,
                jobId: $job['jobId'],
                jobCreated: $job['created'],
                warnings: $job['created']
                    ? ['This session was already finalized but had no scan queued; one was queued now.']
                    : []
            );
        }

        return $this->write(
            $projectId,
            $record,
            $participantId,
            $instance,
            $eventId,
            $sessionType,
            $setting,
            $after,
            $version,
            $previous === null ? null : (int) $previous['log_id']
        );
    }

    /**
     * Re-finalize an already-finalized session: a new version of the same conversation, with a new
     * scan job. The original row is never touched - log rows have no framework UPDATE path, so
     * immutability here is structural rather than a promise.
     *
     * Reads from the *same* boundary as the version it supersedes rather than from that version's
     * own high-water mark, otherwise a correction would re-read only the messages that arrived
     * after the transcript it is correcting, and produce a transcript of almost nothing.
     */
    public function refinalize(
        string $projectId,
        string $record,
        string $participantId,
        int $instance,
        int $eventId,
        string $sessionType,
        string $setting,
        string $adminUser
    ): FinalizeResult {
        $current = $this->store->latestTranscript($projectId, $record, $sessionType, $instance);

        if ($current === null) {
            throw new TranscriptException(
                "There is no finalized transcript for record $record ($sessionType, instance "
                . "$instance) to refinalize. Finalize it first."
            );
        }

        $this->log("refinalize requested by $adminUser for record $record ($sessionType)");

        return $this->write(
            $projectId,
            $record,
            $participantId,
            $instance,
            $eventId,
            $sessionType,
            $setting,
            (int) ($current['supersedes_boundary'] ?? 0),
            ((int) $current['version']) + 1,
            (int) $current['log_id'],
            $adminUser
        );
    }

    private function write(
        string $projectId,
        string $record,
        string $participantId,
        int $instance,
        int $eventId,
        string $sessionType,
        string $setting,
        int $afterLogId,
        int $version,
        ?int $supersedesLogId,
        ?string $adminUser = null
    ): FinalizeResult {
        $rows = $this->store->messageRows($projectId, $participantId, $afterLogId);

        $pseudoId = SessionPseudoId::derive($this->salt, $projectId, $record, $sessionType, $instance);

        // Throws when there is nothing to scan, when a message is oversize, or when the text is not
        // encodable - never trims, never proceeds with a partial conversation.
        $payload = $this->builder->build($rows, $pseudoId, $sessionType, $setting);

        $result = $this->validator->validate($payload, 'safetyscan_input_schema');
        if (!$result->isValid()) {
            // Our bug, not the participant's and not the model's: the payload we build must satisfy
            // the pinned schema. Sending it anyway would fail inside the scanner with a less
            // legible error, so it stops here.
            throw new TranscriptException(sprintf(
                'The transcript payload does not satisfy the pinned SafetyScan input schema, so '
                . 'nothing was finalized and no scan was queued. This is an application fault, not '
                . 'a data problem. Schema errors: %s',
                implode('; ', $result->errors())
            ));
        }

        $canonical = CanonicalJson::encode($payload);
        $sha256 = CanonicalJson::hash($canonical);
        $chunks = CanonicalJson::chunk($canonical);
        $maxLogId = TranscriptBuilder::maxLogId($rows);
        $messageCount = count($payload['messages']);

        $params = CanonicalJson::asLogParameters($chunks) + [
            // Passed explicitly rather than left to the log's context detection: a cron or CLI
            // caller has no project context, and a transcript row with a NULL project_id is
            // invisible to every read that looks for it.
            'project_id'         => (int) $projectId,
            'record'             => $record,
            'instance'           => $instance,
            'session_type'       => $sessionType,
            'setting'            => $setting,
            'session_pseudo_id'  => $pseudoId,
            'version'            => $version,
            'supersedes_log_id'  => $supersedesLogId,
            'started_at'         => $payload['session_started_at'],
            'ended_at'           => $payload['session_ended_at'],
            'message_count'      => $messageCount,
            'transcript_sha256'  => $sha256,
            // Where the next session for this slot begins. Recorded on the row so the boundary
            // travels with the transcript rather than being recomputed from a field that may change.
            'max_message_log_id' => $maxLogId,
            // Carried forward so a refinalize reads from the same starting point as the version it
            // supersedes, instead of from that version's high-water mark.
            'supersedes_boundary' => $afterLogId,
            'chunk_count'        => count($chunks),
            'finalized_by'       => $adminUser,
        ];

        $logId = $this->store->writeTranscript($params);

        if ($logId <= 0) {
            throw new TranscriptException(
                'The transcript row could not be written to the EM log, so nothing is finalized '
                . 'and no scan is queued. Nothing partial was left behind.'
            );
        }

        $ref = 'T' . $logId;
        $this->log(sprintf(
            '%s v%d written for record %s (%s): %d message(s), %d chunk(s), sha %s',
            $ref,
            $version,
            $record,
            $sessionType,
            $messageCount,
            count($chunks),
            substr($sha256, 0, 12)
        ));

        // Before the session-form write, deliberately: the scan is the safety path.
        $job = $this->queue->enqueue(
            $projectId,
            $record,
            $instance,
            $sessionType,
            $logId,
            $sha256,
            $version
        );

        $warnings = $this->writeBackSessionForm(
            $projectId,
            $record,
            $eventId,
            $instance,
            $ref,
            $sha256
        );

        return new FinalizeResult(
            transcriptLogId: $logId,
            transcriptRef: $ref,
            transcriptSha256: $sha256,
            version: $version,
            messageCount: $messageCount,
            maxMessageLogId: $maxLogId,
            jobId: $job['jobId'],
            jobCreated: $job['created'],
            warnings: $warnings,
            skippedRows: $this->builder->skippedRows()
        );
    }

    /**
     * Staff-visible fields on the session instrument. Non-fatal by design - see FinalizeResult.
     *
     * @return string[] warnings
     */
    private function writeBackSessionForm(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        string $ref,
        string $sha256
    ): array {
        $wanted = [
            'mica_session_status'  => 'finalized',
            'mica_session_end_ts'  => date('Y-m-d H:i:s', ($this->clock)()),
            'mica_transcript_ref'  => $ref,
            'mica_transcript_hash' => $sha256,
        ];

        $present = $this->store->existingFields($projectId, array_keys($wanted));
        $missing = array_values(array_diff(array_keys($wanted), $present));
        $warnings = [];

        if ($missing !== []) {
            // Named, so the fix is obvious. These are the audit-G4 fields that PID 257 does not
            // have yet; Stage 2 adds them and the Stage 6 launch gate refuses production without
            // them.
            $warnings[] = sprintf(
                'The transcript is finalized and the scan is queued, but these session-form fields '
                . 'do not exist on this project so staff will not see the transcript pointer: %s. '
                . 'Add them to the session instrument (02-data-model.md §3.1).',
                implode(', ', $missing)
            );
            $this->log('session-form write-back incomplete; missing: ' . implode(', ', $missing));
        }

        $writable = array_intersect_key($wanted, array_flip($present));

        if ($writable === []) {
            return $warnings;
        }

        try {
            $this->store->writeSessionFields($projectId, $record, $eventId, $instance, $writable);
        } catch (\Throwable $e) {
            // Also non-fatal, and for the same reason: the transcript row and the scan job are
            // already written, and they are what protects the participant. Losing them to roll back
            // a display field would be the wrong trade. Loud, though.
            $warnings[] = 'The transcript is finalized and the scan is queued, but writing the '
                        . 'session form failed: ' . $e->getMessage();
            $this->log('session-form write-back failed: ' . $e->getMessage());
        }

        return $warnings;
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
