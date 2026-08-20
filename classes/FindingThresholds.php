<?php

namespace Stanford\MICA;

/**
 * Which of the model's findings reach the review queue.
 *
 * A post-scan filter, deliberately: the model is still asked about everything and its full answer is
 * still stored verbatim on the insert-only scan-run row. This only decides what becomes a finding
 * instance for an RA to work through. Narrowing the *prompt* instead would be cheaper and is the
 * wrong trade for a safety instrument - a concern the model was never asked about is one nobody can
 * later discover was there, and there would be nothing to audit. Here, every decision is reversible
 * by changing a setting, and what was held back is recoverable from the run row.
 *
 * ## Two things a study cannot switch off
 *
 * **`critical` always reaches the queue.** An urgency floor above critical is not a preference, it is
 * a way of not being told, and the entire pipeline exists for exactly these.
 *
 * **Four concern types can never be excluded**: self-harm, violence, medical emergency, and abuse or
 * environmental danger. A missed signal in any of those is a life-safety event rather than queue
 * noise. Everything else - model-quality, protocol, privacy, the softer clinical categories - is the
 * study's call, because a queue full of findings nobody acts on is its own failure mode: it teaches
 * reviewers to skim, and then the one that mattered gets skimmed too.
 *
 * ## The list is a denylist, not an allowlist
 *
 * A study names what it does *not* want. An allowlist would mean that adding a concern type to the
 * taxonomy later silently hides it on every project already configured - a new category of harm
 * arriving switched off. A denylist fails the other way: a new type shows up until somebody decides
 * otherwise.
 */
class FindingThresholds
{
    /** Urgencies the model can assign, weakest first. */
    public const URGENCIES = ['quality', 'moderate', 'high', 'critical'];

    /**
     * Concern types no study may exclude. See the class comment.
     *
     * A constant rather than a setting, so changing it is a code review rather than a checkbox.
     */
    public const NEVER_EXCLUDABLE = [
        'self_harm',
        'violence',
        'medical_emergency',
        'abuse_or_environmental_danger',
    ];

    /** Never filtered by anything: it means the session was not screened at all. */
    public const SCAN_FAILURE = 'scan_failure';

    private string $minimumUrgency;

    /** @var list<string> */
    private array $excluded;

    /**
     * @param string       $minimumUrgency one of URGENCIES; blank or unknown means no floor
     * @param list<string> $excludedConcernTypes
     */
    public function __construct(string $minimumUrgency = '', array $excludedConcernTypes = [])
    {
        $this->minimumUrgency = in_array($minimumUrgency, self::URGENCIES, true) ? $minimumUrgency : '';

        // The guardrail is applied on the way IN, so an excluded-by-mistake life-safety type cannot
        // even be represented in this object - rather than being filtered out later by a check
        // somebody could forget to call.
        $this->excluded = array_values(array_diff(
            array_unique(array_filter(array_map('strval', $excludedConcernTypes))),
            self::NEVER_EXCLUDABLE
        ));
    }

    /**
     * Read the two project settings.
     *
     * Nothing configured means nothing filtered. A study that has not thought about this must see
     * everything the model reported: silence has to be the safe default, because the failure mode of
     * a wrong default here is a finding nobody ever sees.
     */
    public static function fromModule(MICA $module, ?int $projectId = null): self
    {
        $get = static fn(string $key) => $projectId === null
            ? $module->getProjectSetting($key)
            : $module->getProjectSetting($key, $projectId);

        $excluded = $get('finding-excluded-concern-types');

        return new self(
            trim((string) $get('finding-minimum-urgency')),
            is_array($excluded) ? $excluded : ($excluded ? [$excluded] : [])
        );
    }

    /** True when this project filters nothing, which is the shipped state. */
    public function passesEverything(): bool
    {
        return $this->minimumUrgency === '' && $this->excluded === [];
    }

    public function minimumUrgency(): string
    {
        return $this->minimumUrgency;
    }

    /** @return list<string> */
    public function excludedConcernTypes(): array
    {
        return $this->excluded;
    }

    /**
     * Should this finding reach the review queue?
     *
     * @param array<string,mixed> $finding one entry from the model's `findings`
     */
    public function admits(array $finding): bool
    {
        return $this->rejectionReason($finding) === null;
    }

    /**
     * Why a finding was held back, or null if it was not.
     *
     * Returns a reason rather than a bool so the run row can record *what* was filtered and on which
     * rule. A filter whose effects cannot be read back is indistinguishable from a scanner that found
     * nothing, which is the one confusion this pipeline is built to prevent.
     *
     * @param array<string,mixed> $finding
     */
    public function rejectionReason(array $finding): ?string
    {
        $concern = (string) ($finding['concern_type'] ?? '');
        $urgency = (string) ($finding['urgency'] ?? '');

        // Not a model finding at all - it is the placeholder saying the session was never screened,
        // and "not screened" is the last thing a filter should be able to hide.
        if ($concern === self::SCAN_FAILURE) {
            return null;
        }

        if (in_array($concern, self::NEVER_EXCLUDABLE, true)) {
            return null;
        }

        if ($urgency === 'critical') {
            return null;
        }

        if (in_array($concern, $this->excluded, true)) {
            return sprintf('concern type "%s" is excluded on this project', $concern);
        }

        // The floor is only comparable against an urgency the taxonomy knows. An unrecognised one is
        // a bug somewhere upstream, and the safe response to a bug is to show a human rather than to
        // hide - so it skips the floor and is admitted. It does NOT skip the exclusion above, because
        // that was an explicit decision about the category, not about the severity.
        if ($this->minimumUrgency === '' || !in_array($urgency, self::URGENCIES, true)) {
            return null;
        }

        $floor = (int) array_search($this->minimumUrgency, self::URGENCIES, true);
        $here = (int) array_search($urgency, self::URGENCIES, true);

        if ($here < $floor) {
            return sprintf(
                'urgency "%s" is below this project\'s "%s" floor',
                $urgency,
                $this->minimumUrgency
            );
        }

        return null;
    }

    /**
     * Split the model's findings into what reaches the queue and what did not.
     *
     * @param list<array<string,mixed>> $findings
     * @return array{admitted:list<array<string,mixed>>,filtered:list<array<string,mixed>>}
     *         each filtered entry carries `finding_index`, `concern_type`, `urgency` and `reason` -
     *         enough to know what was held back, without copying the participant's words into a
     *         second place.
     */
    public function apply(array $findings): array
    {
        $admitted = [];
        $filtered = [];

        foreach ($findings as $finding) {
            $reason = $this->rejectionReason($finding);

            if ($reason === null) {
                $admitted[] = $finding;
                continue;
            }

            $filtered[] = [
                'finding_index' => $finding['finding_index'] ?? null,
                'concern_type'  => (string) ($finding['concern_type'] ?? ''),
                'urgency'       => (string) ($finding['urgency'] ?? ''),
                'reason'        => $reason,
            ];
        }

        return ['admitted' => $admitted, 'filtered' => $filtered];
    }

    /** @return array<string,mixed> for the scan-run row and the dashboard */
    public function toArray(): array
    {
        return [
            'minimum_urgency'         => $this->minimumUrgency,
            'excluded_concern_types'  => $this->excluded,
            'passes_everything'       => $this->passesEverything(),
        ];
    }
}
