<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ArtifactRegistry.php";
require_once __DIR__ . "/LaunchEnvironmentInterface.php";
require_once __DIR__ . "/NotificationPolicy.php";
require_once __DIR__ . "/RedcapRecipientDirectory.php";
require_once __DIR__ . "/RoleService.php";
require_once __DIR__ . "/SchemaValidator.php";

/**
 * The launch gates' view of a real REDCap installation.
 *
 * All plumbing, no judgement - the judgement is in LaunchReadiness, so that the class an IRB reviewer
 * would want to read is not interleaved with SQL.
 *
 * ## Production status comes from REDCap, and getting it wrong is a one-way error
 *
 * `isProductionProject()` reads `redcap_projects.status`. If that read fails for any reason this
 * returns **true**, i.e. "treat it as production and apply the gates". The alternative default would
 * mean a database hiccup silently disables every safety gate on a live study, which is the wrong
 * direction to fail in: a development project wrongly gated is an inconvenience someone reports
 * within a minute, while a production project wrongly ungated is invisible.
 */
class RedcapLaunchEnvironment implements LaunchEnvironmentInterface
{
    /** REDCap's project status codes. 0 = development; 1 = production; 2+ = analysis/completed. */
    private const STATUS_DEVELOPMENT = 0;

    private MICA $module;
    private int $projectId;
    private ArtifactRegistry $artifacts;
    private SchemaValidator $validator;
    private RoleService $roles;
    private RedcapRecipientDirectory $directory;

    private ?NotificationPolicy $policy = null;
    /** @var list<string>|null */
    private ?array $models = null;

    public function __construct(
        MICA $module,
        int $projectId,
        ArtifactRegistry $artifacts,
        SchemaValidator $validator,
        RoleService $roles,
        RedcapRecipientDirectory $directory
    ) {
        $this->module = $module;
        $this->projectId = $projectId;
        $this->artifacts = $artifacts;
        $this->validator = $validator;
        $this->roles = $roles;
        $this->directory = $directory;
    }

    public function isProductionProject(): bool
    {
        try {
            $result = $this->module->query(
                'SELECT status FROM redcap_projects WHERE project_id = ?',
                [$this->projectId]
            );
            $row = $result->fetch_assoc();

            if ($row === null || $row === false) {
                return true;
            }

            return (int) $row['status'] !== self::STATUS_DEVELOPMENT;
        } catch (\Throwable $e) {
            // See the class comment: unknown means gated.
            return true;
        }
    }

    /** The effective policy - configured if valid, vendored default if not. */
    public function notificationPolicy(): NotificationPolicy
    {
        if ($this->policy === null) {
            $this->policy = NotificationPolicy::fromJson(
                (string) $this->module->getProjectSetting('notification-policy-json', $this->projectId),
                $this->artifacts,
                $this->validator
            );
        }

        return $this->policy;
    }

    public function policy(): array
    {
        return $this->notificationPolicy()->toArray();
    }

    public function policyValidationErrors(): array
    {
        return $this->notificationPolicy()->errors();
    }

    public function verifyArtifacts(): void
    {
        $this->artifacts->verifyAll();
    }

    public function availableModelAliases(): array
    {
        if ($this->models !== null) {
            return $this->models;
        }

        try {
            $available = $this->module->getSecureChatInstance()->getAvailableModels();
        } catch (\Throwable $e) {
            $available = [];
        }

        return $this->models = is_array($available)
            ? array_values(array_map('strval', $available))
            : [];
    }

    public function counselorAlias(): ?string
    {
        return $this->setting('llm-model');
    }

    public function safetyScanAlias(): ?string
    {
        return $this->setting('safetyscan-model-alias');
    }

    public function isScanMockMode(): bool
    {
        return (bool) $this->module->getProjectSetting('scan-mock-mode', $this->projectId);
    }

    public function roles(): RoleService
    {
        return $this->roles;
    }

    public function reviewerUsernames(): array
    {
        $roleIds = $this->roles->mappedRedcapRoles(RoleService::RA);

        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));

        $result = $this->module->query(
            'SELECT username FROM redcap_user_rights WHERE project_id = ? AND role_id IN ('
            . $placeholders . ')',
            array_merge([$this->projectId], array_map('intval', $roleIds))
        );

        $usernames = [];

        while ($row = $result->fetch_assoc()) {
            $usernames[] = (string) $row['username'];
        }

        return $usernames;
    }

    public function recipientProblems(): array
    {
        return $this->directory->configurationProblems();
    }

    public function technicalFallbackText(): string
    {
        return (string) ($this->setting('technical-fallback-text') ?? '');
    }

    private function setting(string $key): ?string
    {
        $value = trim((string) $this->module->getProjectSetting($key, $this->projectId));

        return $value === '' ? null : $value;
    }
}
