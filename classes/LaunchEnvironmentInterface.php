<?php

namespace Stanford\MICA;

/**
 * Everything the launch gates need to ask about.
 *
 * Drawn at "facts about this installation" so LaunchReadiness holds all the judgement and none of
 * the plumbing - which matters more here than usual, because the gates are the thing an IRB reviewer
 * would want to read, and a class interleaved with SQL is not readable in that way.
 */
interface LaunchEnvironmentInterface
{
    /** REDCap's own project status, not the module's production-mode claim. */
    public function isProductionProject(): bool;

    /** @return array<string,mixed> the effective notification policy */
    public function policy(): array;

    /** @return string[] schema-validation errors on the policy; empty means valid */
    public function policyValidationErrors(): array;

    /** @throws \Throwable when a pinned artifact does not match its manifest hash */
    public function verifyArtifacts(): void;

    /** @return string[] aliases the SecureChatAI registry actually knows */
    public function availableModelAliases(): array;

    public function counselorAlias(): ?string;

    public function safetyScanAlias(): ?string;

    public function isScanMockMode(): bool;

    public function roles(): RoleService;

    /** @return string[] usernames in a REDCap role mapped as a MICA reviewer */
    public function reviewerUsernames(): array;

    /**
     * @return string[] problems in the configured recipient lists; empty means clean
     *
     * A malformed address is dropped at send time rather than failing the whole message, so without
     * this it would surface as a care team that never heard about a confirmed critical finding. Here
     * it surfaces while somebody is still setting the study up.
     */
    public function recipientProblems(): array;

    /** The study's approved technical-fallback wording, shown to a participant on refusal. */
    public function technicalFallbackText(): string;
}
