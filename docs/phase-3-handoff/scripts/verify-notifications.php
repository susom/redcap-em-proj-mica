<?php

/**
 * Verify the notification path against a live REDCap.
 *
 *   docker exec <web> php /var/www/html/temp/mica/verify-notifications.php
 *
 * Exit 0 = the RA-first gate holds on real data, a confirmed finding delivers, the trail is written,
 * and the database itself refuses a duplicate send. Exit 1 = at least one check failed.
 *
 * ## What this reaches that the unit suite deliberately cannot
 *
 *   - whether `redcap_entity_mica_notification` accepts the row NotificationService builds, including
 *     `project_id` as the `project` entity type and `sent_at` as `date` (which rejects a numeric
 *     string), from a CLI request with no logged-in user;
 *   - whether the UNIQUE index on `dedupe_key` actually enforces send-once, and whether MySQL really
 *     permits the repeated NULLs that let a failed attempt retry - the whole mechanism rests on that
 *     and no fake can prove it;
 *   - whether `RedcapActionFieldWriter`'s `normal` save semantics accumulate checkbox choices instead
 *     of blanking the ones this payload does not mention;
 *   - whether the gate refuses when the *store* says pending, on a finding that really exists.
 *
 * ## Email
 *
 * By default this sends none. Delivery goes through a capturing channel, so the run proves the
 * decision, the trail and the bookkeeping - not SMTP. A verifier that mailed a study's care team every
 * time somebody ran it would not get run.
 *
 * Set `MICA_REAL_EMAIL=you@example.org` to also deliver every message through the real
 * RedcapEmailChannel, with **all recipients redirected to that one address** - so the care-team and
 * PI lists configured on the project are never touched. The checks are unaffected either way: the
 * capture is still what they assert against. Use it to prove the mailer handoff end to end, and to
 * read how the bodies actually render.
 *
 * Creates its own finding instance, role, and settings, and removes all of them.
 */

define('NOAUTH', true);

// Project context, before redcap_connect runs. `REDCap::getRecordIdField()` and `saveData()` both
// refuse without PROJECT_ID defined, and the finding writer needs both - so a CLI verifier has to
// establish the project the same way a page request would, rather than passing the pid as an
// argument to something that reads a constant.
$_GET['pid'] = (string) (getenv('MICA_PID') ?: 257);

require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string
{
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) {
        return "$env/redcap_connect.php";
    }
    foreach (['/var/www/html'] as $guess) {
        if (is_file("$guess/redcap_connect.php")) {
            return "$guess/redcap_connect.php";
        }
    }
    for ($d = __DIR__, $i = 0; $i < 8; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) {
            return "$d/redcap_connect.php";
        }
        if ($d === '/') {
            break;
        }
    }
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT=/path/to/redcap web root.\n");
    exit(1);
}

// CaptureChannel below implements a MICA interface, and a class declaration is resolved at parse
// time - before getModuleInstance() would have loaded MICA.php and its requires. So the interface has
// to be on disk-loadable before this file's body runs.
require_once mica_module_dir() . '/classes/NotificationChannelInterface.php';

/** The module directory, whether this script runs from the repo or from a copy under temp/. */
function mica_module_dir(): string
{
    if (($env = getenv('MICA_MODULE_DIR')) && is_dir("$env/classes")) {
        return $env;
    }

    // Repo path: docs/phase-3-handoff/scripts/ -> three levels up.
    $repo = dirname(__DIR__, 3);
    if (is_dir("$repo/classes")) {
        return $repo;
    }

    // A copy under temp/: fall back to whatever version REDCap has enabled.
    foreach (glob(dirname(APP_PATH_DOCROOT) . '/modules*/proj_mica_v*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_dir("$dir/classes")) {
            return $dir;
        }
    }

    fwrite(STDERR, "Could not locate the MICA module directory. Set MICA_MODULE_DIR.\n");
    exit(1);
}

use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\AuditLogger;
use Stanford\MICA\DispositionService;
use Stanford\MICA\FindingWriter;
use Stanford\MICA\NotificationChannelInterface;
use Stanford\MICA\NotificationPolicy;
use Stanford\MICA\NotificationResult;
use Stanford\MICA\NotificationService;
use Stanford\MICA\RedcapActionFieldWriter;
use Stanford\MICA\RedcapAuditStore;
use Stanford\MICA\RedcapEmailChannel;
use Stanford\MICA\RedcapFindingReviewStore;
use Stanford\MICA\RedcapNotificationStore;
use Stanford\MICA\RedcapRecipientDirectory;
use Stanford\MICA\RedcapReviewQueryStore;
use Stanford\MICA\RedcapScanResultStore;
use Stanford\MICA\RoleService;
use Stanford\MICA\SchemaValidator;

$PID = (int) (getenv('MICA_PID') ?: 257);
$RECORD = (string) (getenv('MICA_RECORD') ?: '1');
$EVENT = (int) (getenv('MICA_EVENT') ?: 0);

$failures = 0;

function check(string $label, $actual, $expected): void
{
    global $failures;
    $ok = (string) $actual === (string) $expected;
    if (!$ok) {
        $failures++;
    }
    printf(
        "  [%s] %-54s got: %-26s want: %s\n",
        $ok ? 'ok' : 'FAIL',
        $label,
        (string) $actual,
        (string) $expected
    );
}

function note(string $label, $value): void
{
    printf("  ...  %-54s %s\n", $label, (string) $value);
}

/**
 * Captures instead of mailing, and optionally also mails for real. See the header on why.
 *
 * When MICA_REAL_EMAIL is set, every message additionally goes through the genuine
 * RedcapEmailChannel - but with the recipient list replaced by that one address. Redirecting rather
 * than passing the resolved recipients through is the whole point: the project's real care-team and PI
 * lists must not receive a probe, and a verifier that could mail them would be one nobody dares run.
 *
 * The capture is what the checks assert against either way, so turning real delivery on cannot change
 * a single result - it can only add a way for the send itself to fail.
 */
final class CaptureChannel implements NotificationChannelInterface
{
    public array $sent = [];
    public ?string $throwOn = null;

    /** @var list<string> real-delivery failures, so they are reported rather than swallowed */
    public array $deliveryErrors = [];

    public function __construct(
        private ?NotificationChannelInterface $real = null,
        private ?string $redirectTo = null
    ) {
    }

    public function send(string $channel, array $recipients, string $subject, string $body): void
    {
        if ($this->throwOn !== null) {
            throw new \RuntimeException($this->throwOn);
        }

        $this->sent[] = compact('channel', 'recipients', 'subject', 'body');

        if ($this->real === null || $this->redirectTo === null) {
            return;
        }

        try {
            $this->real->send($channel, [$this->redirectTo], $subject, $body);
        } catch (\Throwable $e) {
            // Collected, not thrown: a mailer failure must not rewrite the outcome of a check about
            // the gate. It is reported on its own line at the end.
            $this->deliveryErrors[] = $subject . ' -> ' . $e->getMessage();
        }
    }

    public function supports(string $channel): bool
    {
        return in_array($channel, ['secure_email', 'dashboard'], true);
    }

    /** @return array{channel:string,recipients:list<string>,subject:string,body:string} */
    public function last(): array
    {
        if ($this->sent === []) {
            throw new \RuntimeException('Nothing was sent.');
        }

        return $this->sent[count($this->sent) - 1];
    }
}

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

if ($EVENT === 0) {
    // The first event where the finding instrument is BOTH designated and repeating. Not
    // MIN(event_id): a project's lowest event id is usually one the instrument is not on at all, and
    // saveData's refusal in that case reads as a repeating-instrument misconfiguration rather than as
    // "you picked the wrong event", which costs an hour to work out.
    $row = $module->query(
        'SELECT ef.event_id FROM redcap_events_forms ef '
        . 'JOIN redcap_events_repeat er ON er.event_id = ef.event_id AND er.form_name = ef.form_name '
        . 'JOIN redcap_events_metadata em ON em.event_id = ef.event_id '
        . 'JOIN redcap_events_arms ea ON ea.arm_id = em.arm_id '
        . 'WHERE ea.project_id = ? AND ef.form_name = ? ORDER BY ef.event_id LIMIT 1',
        [$PID, RedcapScanResultStore::INSTRUMENT]
    )->fetch_assoc();

    if (!$row) {
        fwrite(STDERR, sprintf(
            "No event on pid %d has %s designated as a repeating instrument. Apply the instrument "
            . "first: docs/phase-3-handoff/scripts/apply-safety-finding-instrument.php\n",
            $PID,
            RedcapScanResultStore::INSTRUMENT
        ));
        exit(1);
    }

    $EVENT = (int) $row['event_id'];
}

// And a record that actually exists in that event's arm, unless one was named. Writing to a record
// that is not in the arm fails the same way, and just as opaquely.
if (getenv('MICA_RECORD') === false) {
    $row = $module->query(
        'SELECT record FROM ' . \Records::getDataTable($PID)
        . ' WHERE project_id = ? AND event_id = ? ORDER BY record LIMIT 1',
        [$PID, $EVENT]
    )->fetch_assoc();

    if ($row) {
        $RECORD = (string) $row['record'];
    }
}

$REVIEWER = getenv('MICA_REVIEWER') ?: 'ihabz';

// ---------------------------------------------------------------- fixtures

$originalRa = $module->getProjectSetting('role-ra-reviewer', $PID);
$originalCare = $module->getProjectSetting('notify-care-team-emails', $PID);
$originalPolicy = $module->getProjectSetting('notification-policy-json', $PID);

$module->query('DELETE FROM redcap_user_roles WHERE project_id = ? AND role_name = ?', [$PID, 'Verify MICA Notify']);
$module->query(
    'INSERT INTO redcap_user_roles (project_id, role_name, unique_role_name, data_export_tool) '
    . 'VALUES (?, ?, ?, 1)',
    [$PID, 'Verify MICA Notify', 'U-VERIFYNOTIFY']
);
$verifyRoleId = (int) $module->query(
    'SELECT role_id FROM redcap_user_roles WHERE project_id = ? AND role_name = ?',
    [$PID, 'Verify MICA Notify']
)->fetch_assoc()['role_id'];

$originalUserRole = $module->query(
    'SELECT role_id FROM redcap_user_rights WHERE project_id = ? AND username = ?',
    [$PID, $REVIEWER]
)->fetch_assoc()['role_id'] ?? null;

$module->query(
    'UPDATE redcap_user_rights SET role_id = ? WHERE project_id = ? AND username = ?',
    [$verifyRoleId, $PID, $REVIEWER]
);
$module->setProjectSetting('role-ra-reviewer', [(string) $verifyRoleId], $PID);
$module->setProjectSetting('notify-care-team-emails', 'care-team@example.org, not an address', $PID);

$results = new RedcapScanResultStore($module);
$reviewStore = new RedcapFindingReviewStore($module);
$roles = RoleService::fromModule($module, $PID);
$audit = new AuditLogger(new RedcapAuditStore($module), $roles);
$artifacts = new ArtifactRegistry();
$directory = new RedcapRecipientDirectory($module, $roles, $PID);
$notifStore = new RedcapNotificationStore($module, new RedcapReviewQueryStore($module));
$REAL_EMAIL = getenv('MICA_REAL_EMAIL') ?: null;

if ($REAL_EMAIL !== null && !filter_var($REAL_EMAIL, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "MICA_REAL_EMAIL is not a valid address: $REAL_EMAIL\n");
    exit(1);
}

$channel = new CaptureChannel(
    $REAL_EMAIL === null ? null : new RedcapEmailChannel(),
    $REAL_EMAIL
);
$actionWriter = new RedcapActionFieldWriter($module);

$now = time();

$notifications = new NotificationService(
    NotificationPolicy::fromJson(null, $artifacts, new SchemaValidator($artifacts)),
    $channel,
    $notifStore,
    $directory,
    $reviewStore,
    $audit,
    (string) $PID,
    'https://redcap.example.org/review',
    static fn(): int => $now
);

echo "Notification path against live REDCap (pid $PID, record $RECORD, event $EVENT)\n";
echo $REAL_EMAIL === null
    ? "Email: captured only. Set MICA_REAL_EMAIL to also deliver for real.\n\n"
    : "Email: ALSO DELIVERING FOR REAL, every recipient redirected to $REAL_EMAIL\n\n";

$instance = null;

try {
    $module->entitySchemaManager()->ensureSchema();

    echo "1. Fixtures\n";
    check('the review instrument exists', $results->findingInstrumentExists((string) $PID) ? 'yes' : 'no', 'yes');
    check('the reviewer resolves as an RA', implode(',', $roles->rolesFor($REVIEWER)), 'ra');
    note('reviewer addresses', implode(', ', $directory->reviewerAddresses()) ?: '(none)');
    check(
        'the care-team list drops the malformed entry',
        implode(',', $directory->addressesForRole('care_team')),
        'care-team@example.org'
    );
    check(
        'and reports it as a launch problem rather than silently',
        count($directory->configurationProblems()),
        1
    );

    $instance = $results->nextFindingInstance((string) $PID, $RECORD, $EVENT);
    (new FindingWriter($results))->write((string) $PID, $RECORD, $EVENT, 999, [[
        'finding_index'                    => 1,
        'source_role'                      => 'participant',
        'concern_type'                     => 'self_harm',
        'urgency'                          => 'critical',
        'finding_summary'                  => 'verify-notifications probe',
        'evidence'                         => [[
            'message_id'   => 'L1',
            'speaker_role' => 'participant',
            'exact_quote'  => 'probe quote that must never reach a notification body',
        ]],
        'recommended_actions'              => ['ra_review'],
        'recommended_notification_targets' => ['research_assistant'],
        'confidence'                       => 0.9,
    ]]);
    note('finding instance', (string) $instance);

    // ------------------------------------------------------------ 2. the gate

    echo "\n2. The RA-first gate, against what the store really says\n";
    $out = $notifications->deliverActions($RECORD, $EVENT, $instance, ['alert_care_team'], $REVIEWER);
    check('a gated action on a pending finding', $out['result']->outcome, NotificationResult::REFUSED);
    check('nothing was handed to a transport', count($channel->sent), 0);
    check(
        'the refusal names the rule',
        str_contains($out['result']->reason, 'until a human has confirmed') ? 'yes' : 'no',
        'yes'
    );

    $refusedRows = (int) $module->query(
        'SELECT COUNT(*) AS c FROM redcap_entity_mica_notification '
        . 'WHERE project_id = ? AND record = ? AND status = ?',
        [$PID, $RECORD, NotificationResult::REFUSED]
    )->fetch_assoc()['c'];
    check('a refusal row is on the record', $refusedRows >= 1 ? 'yes' : 'no', 'yes');

    echo "\n3. Ungated actions still work on an unconfirmed finding\n";
    // A scan_failure placeholder can never be a confirmed model finding; staff must still be able to
    // record that they handled it.
    $out = $notifications->deliverActions($RECORD, $EVENT, $instance, ['document_no_action'], $REVIEWER);
    check('document_no_action is not refused', $out['result']->outcome, NotificationResult::SENT);
    check('and delivers to nobody', count($channel->sent), 0);
    check(
        'while recording the decision',
        $out['result']->actionFields['action_types___document_no_action'] ?? 'MISSING',
        '1'
    );

    // ------------------------------------------------------------ 4. confirmed delivery

    echo "\n4. A confirmed finding delivers, and the trail records the version it saw\n";
    (new DispositionService($reviewStore, $roles, $audit))->submit(
        (string) $PID,
        $RECORD,
        $EVENT,
        $instance,
        $REVIEWER,
        [
            'review_status'       => DispositionService::CONFIRMED,
            'review_rationale'    => 'verify-notifications probe: confirming to exercise the send path.',
            'review_lock_version' => '0',
        ]
    );

    $confirmed = $reviewStore->readFinding((string) $PID, $RECORD, $EVENT, $instance);
    check('the finding is confirmed', $confirmed['review_status'], DispositionService::CONFIRMED);
    note('lock version after disposition', $confirmed['review_lock_version']);

    $out = $notifications->deliverActions($RECORD, $EVENT, $instance, ['alert_care_team'], $REVIEWER);
    check('now it delivers', $out['result']->outcome, NotificationResult::SENT);
    check('to the care-team address', implode(',', $channel->sent[0]['recipients'] ?? []), 'care-team@example.org');

    $sentRow = $module->query(
        'SELECT * FROM redcap_entity_mica_notification WHERE project_id = ? AND record = ? '
        . 'AND status = ? AND action = ? ORDER BY id DESC LIMIT 1',
        [$PID, $RECORD, NotificationResult::SENT, 'alert_care_team']
    )->fetch_assoc();
    check('a sent row exists', $sentRow ? 'yes' : 'no', 'yes');
    check('carrying the lock version seen at send', $sentRow['lock_version'] ?? 'NONE', $confirmed['review_lock_version']);
    check('and the dedupe key', ($sentRow['dedupe_key'] ?? '') === '' ? 'blank' : 'set', 'set');
    check(
        'the body is hashed, not stored',
        $sentRow['body_sha256'] === hash('sha256', $channel->sent[0]['body']) ? 'yes' : 'no',
        'yes'
    );

    echo "\n5. Minimum necessary: the probe quote must not be in the body or the subject\n";
    $body = $channel->sent[0]['body'];
    check('the participant\'s words', str_contains($body, 'probe quote') ? 'LEAKED' : 'absent', 'absent');
    check('the reviewer\'s rationale', str_contains($body, 'exercise the send path') ? 'LEAKED' : 'absent', 'absent');
    check('the record id is present (staff need it)', str_contains($body, "Record: $RECORD") ? 'yes' : 'no', 'yes');

    // The link is the only actionable thing in the body, and it is assembled by the module rather
    // than by this script. getUrl() derives the project from PROJECT_ID, which is undefined in cron -
    // so MICA::reviewDashboardUrl() sets the pid explicitly. Exactly once: appending rather than
    // overwriting would give `pid=257&pid=257` here, where PROJECT_ID *is* defined.
    $realUrl = (function () use ($module, $PID): string {
        $m = new \ReflectionMethod($module, 'reviewDashboardUrl');
        $m->setAccessible(true);

        return (string) $m->invoke($module, $PID);
    })();
    note('dashboard link', $realUrl);
    check('the link names this project', substr_count($realUrl, 'pid=' . $PID), 1);
    check('and names no other', substr_count($realUrl, 'pid='), 1);
    check(
        'the subject holds no record id',
        preg_match('/\b' . preg_quote($RECORD, '/') . '\b/', $channel->sent[0]['subject']) ? 'LEAKED' : 'absent',
        'absent'
    );

    echo "\n6. Bookkeeping lands on the instrument, and accumulates\n";
    $actionWriter->writeActionFields((string) $PID, $RECORD, $EVENT, $instance, $out['result']->actionFields);
    $afterActions = $reviewStore->readFinding((string) $PID, $RECORD, $EVENT, $instance);
    check('delivery status', $afterActions['action_delivery_status'] ?? 'NONE', 'sent');
    check('the model output is untouched', $afterActions['finding_urgency'] ?? 'NONE', 'critical');
    check('the review decision is untouched', $afterActions['review_status'] ?? 'NONE', 'confirmed');

    /**
     * Checked choices, read the way REDCap actually stores them.
     *
     * A checkbox is imported as `action_types___alert_care_team = '1'` but *stored* as one
     * `field_name = 'action_types'` row per checked choice, with the choice code as the value. So
     * readFinding(), which builds a flat field=>value map, collapses them - the last row wins. That is
     * harmless for the review path (DispositionService never reads a checkbox) but it means a check
     * written against `action_types___x` would report NONE no matter what was saved, which is a
     * verifier that always fails rather than one that catches anything.
     */
    $checked = static function () use ($module, $PID, $RECORD, $EVENT, $instance): array {
        $result = $module->query(
            'SELECT value FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? AND record = ? '
            . 'AND event_id = ? AND field_name = ? AND '
            . ($instance <= 1 ? '(instance IS NULL OR instance = 1)' : 'instance = ?'),
            $instance <= 1
                ? [$PID, $RECORD, $EVENT, 'action_types']
                : [$PID, $RECORD, $EVENT, 'action_types', $instance]
        );
        $values = [];
        while ($row = $result->fetch_assoc()) {
            $values[] = (string) $row['value'];
        }
        sort($values);

        return $values;
    };

    check('the care-team choice is checked', implode(',', $checked()), 'alert_care_team');

    // A second, different action must not blank the first one's checkbox - `normal`, not `overwrite`.
    $actionWriter->writeActionFields((string) $PID, $RECORD, $EVENT, $instance, [
        'action_types___alert_pi' => '1',
        'action_delivery_status'  => 'sent',
    ]);
    check('both choices survive a later action', implode(',', $checked()), 'alert_care_team,alert_pi');

    echo "\n  The writer refuses anything outside the action set\n";
    try {
        $actionWriter->writeActionFields((string) $PID, $RECORD, $EVENT, $instance, ['review_status' => 'confirmed']);
        check('writing review_status through the action writer', 'allowed', 'refused');
    } catch (\LogicException $e) {
        check('writing review_status through the action writer', 'refused', 'refused');
    }

    // ------------------------------------------------------------ 7. idempotency, enforced by the DB

    echo "\n7. Send-once\n";
    $before = count($channel->sent);
    $again = $notifications->deliverActions($RECORD, $EVENT, $instance, ['alert_care_team'], $REVIEWER);
    check('a repeat at the same version is skipped', $again['per_action']['alert_care_team']->outcome, NotificationResult::SKIPPED);
    check('and nothing more was transmitted', count($channel->sent), $before);

    // Now prove the database, not just the read. Two `sent` rows with one dedupe key must be
    // impossible - this is what makes the guard hold when two crons race.
    $key = $notifications->idempotencyKey(NotificationService::ACTION_DELIVERY, 'probe-race');
    $duplicateRejected = 'no';
    try {
        for ($i = 0; $i < 2; $i++) {
            $module->query(
                'INSERT INTO redcap_entity_mica_notification '
                . '(created, updated, notification_type, idempotency_key, dedupe_key, project_id, '
                . 'record, status, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$now, $now, NotificationService::ACTION_DELIVERY, $key, $key, $PID, $RECORD, 'sent', $now]
            );
        }
    } catch (\Throwable $e) {
        $duplicateRejected = 'yes';
    }
    check('the UNIQUE index rejects a second sent row', $duplicateRejected, 'yes');

    // And the repeated NULLs that let a failed attempt retry really are permitted.
    $failedTwice = 'no';
    try {
        for ($i = 0; $i < 2; $i++) {
            $module->query(
                'INSERT INTO redcap_entity_mica_notification '
                . '(created, updated, notification_type, idempotency_key, project_id, record, status, '
                . 'sent_at, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$now, $now, NotificationService::ACTION_DELIVERY, $key, $PID, $RECORD, 'failed', $now, 'probe']
            );
        }
        $failedTwice = 'yes';
    } catch (\Throwable $e) {
        $failedTwice = 'no: ' . $e->getMessage();
    }
    check('but two failed attempts with one key are allowed', $failedTwice, 'yes');

    echo "\n8. The real channel refuses what it cannot honour\n";
    $realChannel = new RedcapEmailChannel();
    check('secure_email', $realChannel->supports('secure_email') ? 'yes' : 'no', 'yes');
    check('dashboard', $realChannel->supports('dashboard') ? 'yes' : 'no', 'yes');
    // Claiming a pager it cannot reach would put "sent" in the trail for a notice nobody got.
    check('pager_or_on_call_system', $realChannel->supports('pager_or_on_call_system') ? 'yes' : 'no', 'no');
    check('secure_messaging', $realChannel->supports('secure_messaging') ? 'yes' : 'no', 'no');

    // ------------------------------------------------------------ 9. digests and gates

    echo "\n9. Digest aggregates carry counts and nothing else\n";
    $counts = $notifStore->digestCounts((string) $PID, $now - 31_536_000, $now + 3600);
    $unknown = array_diff(array_keys($counts), NotificationService::DIGEST_BUCKETS);
    check('no key outside the bucket list', implode(',', $unknown) ?: 'none', 'none');
    $nonInt = array_filter($counts, static fn($v): bool => !is_int($v));
    check('every value is an int', implode(',', array_keys($nonInt)) ?: 'none', 'none');
    // Zero is expected and correct here: digestCounts() counts findings that have a scan job, and
    // this probe's finding was written directly. The check is on the SHAPE - that a store cannot put
    // anything but counts in known buckets into a digest, which is the guarantee the class makes.
    note('findings in the window', (string) $counts['findings_total'] . ' (probe findings have no job)');
    note('confirmed in the window', (string) $counts['confirmed']);

    echo "\n10. The acknowledgment monitor nags once per notice, not once per cron run\n";

    // The real store, the real UNIQUE index, and a clock that advances the way five-minute cron runs
    // do. Keying send-once on the cutoff instead of the notice would flood every reviewer, and a
    // frozen clock cannot show it: two calls would compute the same key and look correct.
    $ackClock = $now;
    $ackService = new NotificationService(
        NotificationPolicy::fromJson(
            json_encode(array_replace_recursive(
                $artifacts->getJson('notification_policy_default'),
                ['ra_review_policy' => ['critical_acknowledgment_minutes' => 1]]
            ), JSON_THROW_ON_ERROR),
            $artifacts,
            new SchemaValidator($artifacts)
        ),
        $channel,
        $notifStore,
        $directory,
        $reviewStore,
        $audit,
        (string) $PID,
        'https://redcap.example.org/review',
        static function () use (&$ackClock): int {
            return $ackClock;
        }
    );

    // A sent reviewers-ready notice, two hours old and never acknowledged.
    $module->query(
        'INSERT INTO redcap_entity_mica_notification '
        . '(created, updated, notification_type, idempotency_key, dedupe_key, project_id, record, '
        . 'event_id, instance, status, channel, subject, sent_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $now, $now, NotificationService::REVIEWERS_READY, 'probe-ack-' . $now,
            'probe-ack-' . $now, $PID, $RECORD, $EVENT, $instance, 'sent', 'secure_email',
            '[MICA] 1 SafetyScan finding(s) ready for review - highest urgency: critical',
            $now - 7200,
        ]
    );

    $overdue = $notifStore->unacknowledged((string) $PID, $now - 60);
    check('the overdue read returns a notification_id', ($overdue[0]['notification_id'] ?? 0) > 0 ? 'yes' : 'no', 'yes');
    check('and the urgency read back off the subject', $overdue[0]['urgency'] ?? 'NONE', 'critical');

    $probeId = (int) $overdue[0]['notification_id'];
    $probeKey = $ackService->idempotencyKey(NotificationService::ACK_OVERDUE, 'notice:' . $probeId);

    /**
     * How many times THIS notice has been chased.
     *
     * Counted by idempotency key rather than by emails sent, because earlier steps left other `sent`
     * notices on this record and each of those legitimately earns its own first nag as the clock
     * advances past its window. A raw email count would therefore have read a *correct* new nag as a
     * duplicate - which is what the first version of this check did.
     */
    $nagsForProbe = static fn(): int => (int) $module->query(
        'SELECT COUNT(*) AS c FROM redcap_entity_mica_notification '
        . 'WHERE idempotency_key = ? AND status = ?',
        [$probeKey, NotificationResult::SENT]
    )->fetch_assoc()['c'];

    $first = $ackService->notifyOverdueAcknowledgments();
    check('the first run nags', $first[0]->outcome, NotificationResult::SENT);
    check('once for this notice', $nagsForProbe(), 1);

    foreach ([300, 600, 900, 86_400] as $elapsed) {
        $ackClock = $now + $elapsed;
        $ackService->notifyOverdueAcknowledgments();
    }
    // The point: the cutoff moved four times and this notice was still chased exactly once. Keying
    // send-once on the cutoff would have made this 5.
    check('and still once after four later cron runs', $nagsForProbe(), 1);

    // Acknowledge it, and it leaves the overdue list.
    $notifStore->acknowledge($probeId, $REVIEWER, $now);
    $stillOverdue = array_column(
        $notifStore->unacknowledged((string) $PID, $now + 90_000),
        'notification_id'
    );
    check(
        'an acknowledged notice is no longer overdue',
        in_array($probeId, $stillOverdue, true) ? 'still listed' : 'gone',
        'gone'
    );

    echo "\n11. The remaining body types, so every one is exercised\n";

    // reviewers_ready (both variants) and a digest are not reached by the steps above, and when
    // MICA_REAL_EMAIL is set these are the messages worth reading: they are the ones a reviewer sees
    // most often. Driven through the same service, so the assertions apply to real output.
    $digestService = new NotificationService(
        NotificationPolicy::fromJson(
            json_encode(array_replace_recursive(
                $artifacts->getJson('notification_policy_default'),
                ['digests' => [[
                    'digest_id'                        => 'probe_daily',
                    'enabled'                          => true,
                    'cadence'                          => 'daily',
                    'recipient_roles'                  => ['research_assistant'],
                    'delivery_channel'                 => 'secure_email',
                    'include_participant_level_detail' => false,
                ]]]
            ), JSON_THROW_ON_ERROR),
            $artifacts,
            new SchemaValidator($artifacts)
        ),
        $channel,
        $notifStore,
        $directory,
        $reviewStore,
        $audit,
        (string) $PID,
        $realUrl,
        static fn(): int => $now
    );

    $ready = $digestService->notifyReviewersReady(999_001, $RECORD, $EVENT, $instance, 'baseline', 2, 'critical');
    check('a findings-ready notice sends', $ready->outcome, NotificationResult::SENT);
    check(
        'and states the RA-first rule to the RA',
        str_contains($channel->last()['body'], 'nothing will be until you confirm') ? 'yes' : 'no',
        'yes'
    );

    $failed = $digestService->notifyReviewersReady(999_002, $RECORD, $EVENT, $instance, 'baseline', 0, 'none', true);
    check('a not-screened notice sends', $failed->outcome, NotificationResult::SENT);
    check(
        'and is not mistakable for an all-clear',
        str_contains($channel->last()['body'], 'not an all-clear') ? 'yes' : 'no',
        'yes'
    );

    $digests = $digestService->sendDigests('daily', $now - 86400, $now);
    check('a digest sends', $digests[0]->outcome, NotificationResult::SENT);
    // Space-padded columns collapse in HTML, so the body must not rely on them for alignment.
    check(
        'the digest uses label: value, not padded columns',
        preg_match('/\n[A-Z][a-z ]+:  +\d/', $channel->last()['body']) ? 'padded' : 'plain',
        'plain'
    );

    /**
     * Every body that carries the dashboard link must still produce exactly one anchor.
     *
     * Only a URL alone on its own line is linked - that is what stops a URL smuggled inside a record
     * id from becoming clickable. The cost is that a body which ever concatenates the link onto a
     * sentence would silently render as unlinked text, and the link is the only actionable thing in a
     * minimum-necessary body. By this point every body type has been sent, so this checks all of them
     * at once, against the same conversion the mailer uses.
     */
    echo "\n  Every body type still renders its link as a link\n";

    $withLink = 0;
    $unlinked = [];

    foreach ($channel->sent as $sent) {
        if (!str_contains($sent['body'], 'http')) {
            continue;
        }

        $withLink++;
        $anchors = substr_count(RedcapEmailChannel::bodyToHtml($sent['body']), '<a href=');

        if ($anchors !== 1) {
            $unlinked[] = sprintf('%s (%d anchors)', $sent['subject'], $anchors);
        }
    }

    note('bodies carrying a link', (string) $withLink);
    check('every one produced exactly one anchor', implode('; ', $unlinked) ?: 'yes', 'yes');

    echo "\n12. Launch readiness on this project\n";
    $gates = $module->launchReadinessFor($PID);
    foreach ($gates->evaluate() as $gate) {
        printf(
            "  %-4s %-38s %s%s\n",
            $gate->passed ? '[ok]' : '[--]',
            $gate->id,
            $gate->passed ? 'pass' : 'BLOCKS LAUNCH',
            $gate->deliberate && !$gate->passed ? ' (deliberate)' : ''
        );
    }
    note('may start a session', $gates->mayStartSession() ? 'yes' : 'no');
    note('participant refusal text', $gates->participantRefusal());
    // The checklist reports every gate, which is what makes it a checklist.
    check('every gate reported', count($gates->evaluate()), 7);
} catch (\Throwable $e) {
    $failures++;
    echo "\n  [FAIL] threw: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

// ---------------------------------------------------------------- cleanup

echo "\n13. Cleanup\n";

if ($instance !== null) {
    $fields = $module->query(
        'SELECT field_name FROM redcap_metadata WHERE project_id = ? AND form_name = ?',
        [$PID, RedcapScanResultStore::INSTRUMENT]
    );
    $names = [];
    while ($row = $fields->fetch_assoc()) {
        $names[] = $row['field_name'];
    }

    if ($names !== []) {
        $module->query(
            'DELETE FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? AND record = ? '
            . 'AND field_name IN (' . implode(',', array_fill(0, count($names), '?')) . ')',
            array_merge([$PID, $RECORD], $names)
        );
    }

    check(
        'finding instances left behind',
        (int) $module->query(
            'SELECT COUNT(*) AS c FROM ' . \Records::getDataTable($PID)
            . ' WHERE project_id = ? AND record = ? AND field_name = ?',
            [$PID, $RECORD, 'finding_id']
        )->fetch_assoc()['c'],
        0
    );
}

$module->query(
    'DELETE FROM redcap_entity_mica_notification WHERE project_id = ? AND (record = ? OR job_id IN (999001, 999002))',
    [$PID, $RECORD]
);
check(
    'notification rows left behind',
    (int) $module->query(
        'SELECT COUNT(*) AS c FROM redcap_entity_mica_notification WHERE project_id = ? AND record = ?',
        [$PID, $RECORD]
    )->fetch_assoc()['c'],
    0
);

if ($originalUserRole === null) {
    $module->query('UPDATE redcap_user_rights SET role_id = NULL WHERE project_id = ? AND username = ?', [$PID, $REVIEWER]);
} else {
    $module->query(
        'UPDATE redcap_user_rights SET role_id = ? WHERE project_id = ? AND username = ?',
        [$originalUserRole, $PID, $REVIEWER]
    );
}
$module->query('DELETE FROM redcap_user_roles WHERE project_id = ? AND role_name = ?', [$PID, 'Verify MICA Notify']);
$module->setProjectSetting('role-ra-reviewer', $originalRa, $PID);
$module->setProjectSetting('notify-care-team-emails', $originalCare, $PID);
$module->setProjectSetting('notification-policy-json', $originalPolicy, $PID);
echo "  ...  role, rights and settings restored\n";

if ($REAL_EMAIL !== null) {
    echo "\n14. Real delivery\n";
    note('messages handed to the mailer', (string) count($channel->sent));
    check('mailer failures', count($channel->deliveryErrors), 0);

    foreach ($channel->deliveryErrors as $error) {
        echo "  [FAIL] $error\n";
    }
}

echo "\n" . ($failures === 0
    ? "PASS - the gate holds, the trail is written, and the database enforces send-once\n"
    : "FAIL - $failures check(s) failed\n");

exit($failures === 0 ? 0 : 1);
