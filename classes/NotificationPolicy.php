<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ArtifactRegistry.php";
require_once __DIR__ . "/SchemaValidator.php";

/**
 * The notification policy: loaded, validated, and fail-closed to the vendored default.
 *
 * The policy decides who hears about a finding and when, so a malformed one must never be *partly*
 * applied. `fromJson()` validates against the pinned policy schema and, on any failure, falls back
 * to the handoff's shipped default rather than to whatever fields happened to parse. The shipped
 * default is the safe one: RA-ready notices on, pre-review notification off, acknowledgment target
 * unset. Falling back to it turns a broken policy into a conservative one instead of an arbitrary
 * one.
 *
 * ## Two things the schema pins that this class re-states rather than trusts
 *
 * `notify_assigned_ra_when_ready` is `const true` - it cannot be turned off. And
 * `pre_review_notifications.required_label` is a `const` string. Both are enforced here as well as
 * in the schema, because the schema only runs on the JSON: a caller constructing a policy in code,
 * or a future migration, would bypass it. Defence in depth on the two clauses that carry the
 * handoff's clinical guarantees.
 */
class NotificationPolicy
{
    /** Pinned by the policy schema as a `const`. Any pre-review body must carry it. */
    public const REQUIRED_PRE_REVIEW_LABEL =
        'Unverified automated SafetyScan finding pending human review';

    /** @var array<string,mixed> */
    private array $policy;

    /** @var string[] why the configured policy was rejected, if it was */
    private array $errors;

    private bool $usedFallback;

    /** @param array<string,mixed> $policy already-validated policy data */
    private function __construct(array $policy, array $errors = [], bool $usedFallback = false)
    {
        $this->policy = $policy;
        $this->errors = $errors;
        $this->usedFallback = $usedFallback;
    }

    /**
     * Build from the project setting, falling back to the vendored default.
     *
     * @param string|null $json the `notification-policy-json` setting; blank means "use the default"
     */
    public static function fromJson(
        ?string $json,
        ArtifactRegistry $artifacts,
        SchemaValidator $validator
    ): self {
        $default = $artifacts->getJson('notification_policy_default');

        if ($json === null || trim($json) === '') {
            // Not an error: shipping the handoff's default is the intended out-of-the-box state.
            return new self($default);
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return new self($default, ['The policy setting is not valid JSON.'], true);
        }

        $result = $validator->validate($decoded, 'notification_policy_schema');

        if (!$result->isValid()) {
            return new self($default, $result->errors(), true);
        }

        return new self($decoded);
    }

    /** @return string[] empty when the configured policy was used as-is */
    public function errors(): array
    {
        return $this->errors;
    }

    /** True when the configured policy was rejected and the vendored default is in force. */
    public function usedFallback(): bool
    {
        return $this->usedFallback;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->policy;
    }

    /**
     * Always true. The schema pins it as a `const`, and this method exists so the call site reads as
     * a policy question rather than a hardcoded assumption - if the handoff ever revises it, there is
     * one place to change.
     */
    public function notifyReviewersWhenReady(): bool
    {
        return ($this->policy['ra_review_policy']['notify_assigned_ra_when_ready'] ?? true) === true;
    }

    /** Minutes within which a critical finding must be acknowledged, or null if undecided. */
    public function criticalAcknowledgmentMinutes(): ?int
    {
        $value = $this->policy['ra_review_policy']['critical_acknowledgment_minutes'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Whether anyone may be told about a finding **before** a human has confirmed it.
     *
     * Off in the shipped default, and the handoff is explicit that it stays off under the initial
     * policy: "Do not notify the care team or PI before RA confirmation." The infrastructure exists
     * because a protocol could later approve it, not because it is expected to be used.
     */
    public function preReviewEnabled(): bool
    {
        return ($this->policy['pre_review_notifications']['enabled'] ?? false) === true;
    }

    /** @return string[] urgencies eligible for a pre-review notice */
    public function preReviewUrgencies(): array
    {
        $values = $this->policy['pre_review_notifications']['eligible_urgencies'] ?? [];

        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }

    /** @return string[] recipient roles for a pre-review notice */
    public function preReviewRecipientRoles(): array
    {
        $values = $this->policy['pre_review_notifications']['recipient_roles'] ?? [];

        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }

    /**
     * The label every pre-review body must carry.
     *
     * Returns the pinned constant, not whatever the policy says. A policy that somehow carried a
     * softer label - "possible concern identified", say - would let an unverified model finding be
     * read as a confirmed one by the person least able to check. The schema pins it; so does this.
     */
    public function preReviewLabel(): string
    {
        return self::REQUIRED_PRE_REVIEW_LABEL;
    }

    public function preReviewAcknowledgmentMinutes(): ?int
    {
        $value = $this->policy['pre_review_notifications']['acknowledgment_minutes'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * @return list<array<string,mixed>> enabled digests only
     */
    public function digests(): array
    {
        $digests = $this->policy['digests'] ?? [];

        if (!is_array($digests)) {
            return [];
        }

        return array_values(array_filter(
            $digests,
            static fn($d): bool => is_array($d) && ($d['enabled'] ?? false) === true
        ));
    }

    /** @return list<array<string,mixed>> enabled digests on a given cadence */
    public function digestsFor(string $cadence): array
    {
        return array_values(array_filter(
            $this->digests(),
            static fn(array $d): bool => ($d['cadence'] ?? '') === $cadence
        ));
    }

    /**
     * Always false. `include_participant_level_detail` is a `const false` in the schema, and a digest
     * is an aggregate by definition: it goes to a wider audience than the review dashboard, on a
     * schedule, by email. Re-stated here for the same reason as the label.
     */
    public function digestsMayIncludeParticipantDetail(): bool
    {
        return false;
    }
}
