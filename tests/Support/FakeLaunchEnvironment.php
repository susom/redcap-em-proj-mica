<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\LaunchEnvironmentInterface;
use Stanford\MICA\RoleService;

/**
 * A launch environment that starts fully READY, so every test names exactly the one thing it broke.
 *
 * The other way round - start empty and configure what you need - makes each test's setup longer
 * than its assertion, and a gate that passes because the test forgot to break something reads as a
 * passing gate.
 */
final class FakeLaunchEnvironment implements LaunchEnvironmentInterface
{
    public bool $production = true;
    public array $policyData;
    public array $policyErrors = [];
    public ?\Throwable $artifactError = null;
    public array $available = ['gpt-5-4', 'gemini-2.5-flash'];
    public ?string $counselor = 'gpt-5-4';
    public ?string $safetyScan = 'gemini-2.5-flash';
    public bool $mockMode = false;
    public array $reviewers = ['ra_alice'];
    public string $fallbackText = 'The study team has been notified.';
    public array $roleMapping = [RoleService::RA => ['660']];
    public array $recipientProblems = [];

    public function __construct()
    {
        $this->policyData = [
            'schema_version'    => '1.0',
            'ra_review_policy'  => [
                'critical_acknowledgment_minutes' => 60,
                'notify_assigned_ra_when_ready'   => true,
            ],
            'pre_review_notifications' => [
                'enabled'          => false,
                'eligible_urgencies' => ['critical'],
                'recipient_roles'  => [],
                'delivery_channels' => ['dashboard'],
                'required_label'   => 'Unverified automated SafetyScan finding pending human review',
                'acknowledgment_minutes' => null,
            ],
            'digests' => [],
        ];
    }

    public function isProductionProject(): bool
    {
        return $this->production;
    }

    public function policy(): array
    {
        return $this->policyData;
    }

    public function policyValidationErrors(): array
    {
        return $this->policyErrors;
    }

    public function verifyArtifacts(): void
    {
        if ($this->artifactError !== null) {
            throw $this->artifactError;
        }
    }

    public function availableModelAliases(): array
    {
        return $this->available;
    }

    public function counselorAlias(): ?string
    {
        return $this->counselor;
    }

    public function safetyScanAlias(): ?string
    {
        return $this->safetyScan;
    }

    public function isScanMockMode(): bool
    {
        return $this->mockMode;
    }

    public function roles(): RoleService
    {
        return new RoleService($this->roleMapping, ['ra_alice' => '660']);
    }

    public function reviewerUsernames(): array
    {
        return $this->reviewers;
    }

    public function recipientProblems(): array
    {
        return $this->recipientProblems;
    }

    public function technicalFallbackText(): string
    {
        return $this->fallbackText;
    }
}
