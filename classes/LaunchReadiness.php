<?php

namespace Stanford\MICA;

require_once __DIR__ . "/GateResult.php";
require_once __DIR__ . "/LaunchEnvironmentInterface.php";
require_once __DIR__ . "/RoleService.php";

/**
 * The gates that must pass before MICA runs a real participant session.
 *
 * This is the class whose whole purpose is to say **no**. `stage-6 §6.3`: the module refuses to
 * operate in production until leadership resolves the deliberate blockers.
 *
 * ## The deliberate blocker
 *
 * `critical_acknowledgment_minutes` ships as `null` on purpose. It is not a missing default - the
 * handoff lists it as a launch blocker precisely so that going live requires study leadership to
 * decide, in writing, how quickly a critical finding must be acknowledged. A sensible-looking
 * default here would quietly answer a clinical governance question on their behalf, which is the one
 * thing this gate exists to prevent. `GateResult::$deliberate` marks it so the UI can say "waiting
 * on a decision" rather than "something is broken".
 *
 * ## What is and is not blocked
 *
 * Failing gates block **starting a session**. They never block scanning, reviewing, or notifying
 * about sessions that already happened: a study that discovers a misconfiguration mid-enrolment must
 * still be able to read the transcripts it already has. Refusing to review would turn a
 * configuration problem into a safety one.
 *
 * ## Production status, not the production-mode checkbox
 *
 * Enforcement keys on **REDCap's own project status**. Keying it on the module's `production-mode`
 * setting would mean a study that never ticked that box is never gated at all - which is exactly the
 * study most likely to have skipped the rest of the setup too. `production-mode` is the *claim* that
 * the gates were reviewed; project status is the fact that participants are real.
 */
class LaunchReadiness
{
    private LaunchEnvironmentInterface $env;

    /** @var list<GateResult>|null memoised for the life of this instance - see evaluate() */
    private ?array $evaluated = null;

    public function __construct(LaunchEnvironmentInterface $env)
    {
        $this->env = $env;
    }

    /**
     * Every gate's verdict.
     *
     * Memoised, because a single page load asks more than once: the chat page asks
     * `mayStartSession()` and then `developmentBanner()`, and the dashboard's checklist asks
     * `isReady()` and `evaluate()`. Without this, each of those re-hashes every pinned artifact and
     * re-runs the user-rights join. One instance is built per request, so the cache cannot go stale
     * within its own lifetime.
     *
     * @return list<GateResult>
     */
    public function evaluate(): array
    {
        return $this->evaluated ??= [
            $this->acknowledgmentGate(),
            $this->reviewersGate(),
            $this->artifactsGate(),
            $this->modelsGate(),
            $this->policyGate(),
            $this->recipientsGate(),
            $this->mockModeGate(),
        ];
    }

    public function isReady(): bool
    {
        return $this->blockers() === [];
    }

    /** @return list<GateResult> only the gates that failed */
    public function blockers(): array
    {
        return array_values(array_filter($this->evaluate(), static fn(GateResult $g): bool => !$g->passed));
    }

    /**
     * Whether a participant session may start.
     *
     * Development status always allows it - that is what a development project is for - but the
     * chatbot shows a banner so nobody mistakes an unready configuration for a ready one.
     */
    public function mayStartSession(): bool
    {
        return !$this->env->isProductionProject() || $this->isReady();
    }

    /**
     * The sentence a participant sees when a session is refused.
     *
     * Deliberately says nothing about gates. A participant is not the audience for a configuration
     * report, and listing what is unconfigured would be both useless and a small information leak.
     * They get the study's approved technical-fallback wording; the detail goes to staff.
     */
    public function participantRefusal(): string
    {
        $configured = trim($this->env->technicalFallbackText());

        return $configured !== ''
            ? $configured
            : 'This session cannot start right now. Please let the study team know - they have been '
            . 'notified and will follow up with you.';
    }

    /**
     * The banner a development project shows above the chat, or null when there is nothing to say.
     *
     * ## Titles and a count. Never `detail`, never `howToFix`.
     *
     * `pages/chatbot.php` is in `no-auth-pages`, so whatever this returns is readable by anyone with
     * the survey link. Gate titles are generic ("Model aliases resolve"); the details are not - the
     * models gate enumerates the SecureChatAI registry, and the recipients gate quotes configured
     * email addresses. Those belong on the dashboard, behind authentication and a role.
     *
     * ## Only on a development project, and only when something fails
     *
     * A production project with failing gates does not get a banner - it gets a refusal, which is a
     * stronger statement in the same place. And a development project that passes everything gets
     * nothing: a permanent "this is dev" badge is noise that teaches people to ignore the banner,
     * which is the one thing it cannot afford.
     *
     * @return array{headline:string,count:int,titles:list<string>,awaiting_decision:list<string>}|null
     */
    public function developmentBanner(): ?array
    {
        if ($this->env->isProductionProject()) {
            return null;
        }

        $blockers = $this->blockers();

        if ($blockers === []) {
            return null;
        }

        return [
            'headline' => 'Launch gates unmet - development only',
            'count'    => count($blockers),
            'titles'   => array_values(array_map(static fn(GateResult $g): string => $g->title, $blockers)),
            // Separated so the banner can say "waiting on a decision" rather than "broken" for the
            // acknowledgment target. Telling a study administrator to go and fix something that is
            // actually waiting on their PI wastes the one read they will give this.
            'awaiting_decision' => array_values(array_map(
                static fn(GateResult $g): string => $g->title,
                array_filter($blockers, static fn(GateResult $g): bool => $g->deliberate)
            )),
        ];
    }

    /** The staff-facing explanation, for the log and the dashboard. */
    public function staffExplanation(): string
    {
        $blockers = $this->blockers();

        if ($blockers === []) {
            return 'All launch gates pass.';
        }

        $lines = array_map(
            static fn(GateResult $g): string => sprintf('- %s: %s %s', $g->title, $g->detail, $g->howToFix),
            $blockers
        );

        return sprintf(
            "MICA is refusing to start sessions on this project because %d launch gate(s) fail:\n%s",
            count($blockers),
            implode("\n", $lines)
        );
    }

    // ------------------------------------------------------------------ the gates

    /** Gate 1 - the deliberate blocker. */
    private function acknowledgmentGate(): GateResult
    {
        $minutes = $this->env->policy()['ra_review_policy']['critical_acknowledgment_minutes'] ?? null;
        $passed = $minutes !== null;

        return new GateResult(
            'critical_acknowledgment_minutes',
            'Critical-finding acknowledgment target',
            $passed,
            $passed
                ? "Set to $minutes minutes."
                : 'Not set. The handoff ships this as null on purpose: how quickly a critical finding '
                . 'must be acknowledged is a clinical governance decision, and a default here would '
                . 'answer it on the study\'s behalf.',
            // Instructions only when there is something to do. Advice on a green line is what makes
            // people stop reading the checklist, and this checklist has to stay readable.
            $passed
                ? ''
                : 'Study leadership decides the target, then set '
                . '`ra_review_policy.critical_acknowledgment_minutes` in the notification policy.',
            true
        );
    }

    /**
     * Gate 2 - somebody can actually review, and somebody can actually be told.
     *
     * "The assigned RA" the policy's `notify_assigned_ra_when_ready` refers to is **everyone in the
     * mapped reviewer role**. There is no per-finding assignee field and there should not be one: an
     * assignment that can go stale is a finding that can end up assigned to somebody who left. The
     * role IS the assignment, and it is managed where study access is managed.
     */
    private function reviewersGate(): GateResult
    {
        $mappedRoles = $this->env->roles()->mappedRedcapRoles(RoleService::RA);
        $reviewers = $this->env->reviewerUsernames();

        if ($mappedRoles === []) {
            return new GateResult(
                'reviewers',
                'Reviewers configured',
                false,
                'No REDCap user role is mapped as a MICA reviewer, so no finding could ever be '
                . 'reviewed and no "findings ready" notice would have a recipient.',
                'Map a REDCap role under External Modules > MICA > Configure, in the Reviewer setting.'
            );
        }

        if ($reviewers === []) {
            return new GateResult(
                'reviewers',
                'Reviewers configured',
                false,
                sprintf(
                    'A reviewer role is mapped (%s) but no user is in it, so findings would reach '
                    . 'a queue nobody is notified about.',
                    implode(', ', $mappedRoles)
                ),
                'Assign at least one user to that REDCap role under User Rights.'
            );
        }

        return new GateResult(
            'reviewers',
            'Reviewers configured',
            true,
            sprintf('%d user(s) in the mapped reviewer role(s).', count($reviewers)),
        );
    }

    /** Gate 3 - the prompt and schemas are the validated ones. */
    private function artifactsGate(): GateResult
    {
        try {
            $this->env->verifyArtifacts();
        } catch (\Throwable $e) {
            return new GateResult(
                'artifacts',
                'Hash-pinned handoff artifacts',
                false,
                'Verification failed: ' . $e->getMessage(),
                'Restore handoff/ from the repository. Do NOT edit an artifact in place - the hash is '
                . 'what ties a scan result to the prompt the research team validated.'
            );
        }

        return new GateResult(
            'artifacts',
            'Hash-pinned handoff artifacts',
            true,
            'Every pinned artifact matches its manifest hash, and nothing unpinned is present.'
        );
    }

    /**
     * Gate 4 - both models the module needs are configured, and both exist.
     *
     * "Both", not "whichever happens to be set". This gate used to filter out unset aliases and then
     * check only the remainder, so a project with a counselor alias and **no SafetyScan alias** read
     * as `PASSING` under the heading "Model aliases resolve" - with a detail line naming the one model
     * it did find, which makes it look like the whole answer. The missing one is the safety scanner:
     * the exact reading this gate exists to prevent, on the exact setting where it matters most.
     * `stage-6 §6.3` asks for "counselor + scan model aliases resolve", and that is what this does.
     */
    private function modelsGate(): GateResult
    {
        $available = $this->env->availableModelAliases();
        $aliases = [
            'counselor'  => $this->env->counselorAlias(),
            'safetyscan' => $this->env->safetyScanAlias(),
        ];

        $unset = array_keys(array_filter(
            $aliases,
            static fn(?string $a): bool => $a === null || trim($a) === ''
        ));

        if ($unset !== []) {
            return new GateResult(
                'models',
                'Model aliases resolve',
                false,
                sprintf(
                    'No %s alias is configured, so the model is inherited from a literal in the '
                    . 'code rather than named by this project - and that literal is not checked '
                    . 'against the registry above.',
                    implode(' or ', $unset)
                ),
                // Not hypothetical: PID 257 had the SafetyScan alias unset and the code's fallback
                // was not in that server's registry at all. An unregistered alias does not fail
                // loudly - SecureChatAI returns the provider's canned apology, which is how docs 14
                // D1 happened - so the symptom would have been sessions that looked screened and
                // were not.
                'Set both the counselor (`llm-model`) and SafetyScan (`safetyscan-model-alias`) '
                . 'aliases to values in the SecureChatAI registry. The SafetyScan one is the more '
                . 'serious of the two: it decides which model screens a session for risk, and an '
                . 'alias that is not registered returns a canned apology rather than an error.'
            );
        }

        $configured = $aliases;
        $missing = [];
        foreach ($configured as $which => $alias) {
            if (!in_array($alias, $available, true)) {
                $missing[] = "$which alias \"$alias\"";
            }
        }

        if ($missing !== []) {
            return new GateResult(
                'models',
                'Model aliases resolve',
                false,
                sprintf(
                    '%s not in the SecureChatAI registry. Registered: %s.',
                    implode(' and ', $missing),
                    $available === [] ? '(none)' : implode(', ', $available)
                ),
                'Add the alias to SecureChatAI\'s api-settings, or point MICA at one that exists. An '
                . 'unregistered alias does not fail loudly at call time - it returns the provider\'s '
                . 'canned apology, which is how docs 14 D1 happened.'
            );
        }

        return new GateResult(
            'models',
            'Model aliases resolve',
            true,
            sprintf('%s registered.', implode(', ', $configured))
        );
    }

    /** Gate 5 - the policy is the shape the pinned schema requires. */
    private function policyGate(): GateResult
    {
        $errors = $this->env->policyValidationErrors();

        if ($errors !== []) {
            return new GateResult(
                'policy',
                'Notification policy is valid',
                false,
                'The policy does not satisfy its pinned schema: ' . implode('; ', $errors),
                'Fix the policy JSON, or clear the setting to fall back to the vendored default.'
            );
        }

        return new GateResult(
            'policy',
            'Notification policy is valid',
            true,
            'Validates against the pinned policy schema.'
        );
    }

    /**
     * Gate 6 - the configured recipient lists are addresses.
     *
     * Separate from the reviewers gate because it is a different failure: reviewers come from REDCap
     * roles and cannot be mistyped, whereas the care-team and on-call lists are free text. A malformed
     * entry is dropped at send time rather than failing the whole message - correct at send time, but
     * it means the symptom is a care team that never heard about a confirmed critical finding, months
     * later, with nothing in the trail saying anyone was left out. This is the gate that turns that
     * into a typo somebody fixes before launch.
     */
    private function recipientsGate(): GateResult
    {
        $problems = $this->env->recipientProblems();

        if ($problems !== []) {
            return new GateResult(
                'recipients',
                'Recipient lists are valid addresses',
                false,
                implode(' ', $problems),
                'Correct the address lists under External Modules > MICA > Configure. An entry that '
                . 'is not a valid address is skipped silently at send time.'
            );
        }

        return new GateResult(
            'recipients',
            'Recipient lists are valid addresses',
            true,
            'Every configured notification address parses.'
        );
    }

    /**
     * Gate 7 - mock mode is off.
     *
     * Its own gate rather than folded into the model gate, because "the scanner is replaying
     * fixtures" and "the alias is not registered" are completely different problems and reporting the
     * first as the second would send someone to the wrong screen. Mock mode in production would mean
     * every session was screened by a canned file.
     */
    private function mockModeGate(): GateResult
    {
        $on = $this->env->isScanMockMode();

        return new GateResult(
            'scan_mock_mode',
            'Scan mock mode is off',
            !$on,
            $on
                ? 'ON. The scanner is replaying fixtures from disk instead of calling a model, so no '
                . 'session would actually be screened.'
                : 'Off - scans call the configured model.',
            $on ? 'Untick "Scan mock mode" under External Modules > MICA > Configure.' : ''
        );
    }
}
