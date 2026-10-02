<?php

namespace Stanford\MICA;

require_once __DIR__ . "/RecipientDirectoryInterface.php";
require_once __DIR__ . "/RoleService.php";

/**
 * Policy role -> addresses, resolved through REDCap wherever REDCap knows the answer.
 *
 * ## Two kinds of role, resolved two ways
 *
 * `research_assistant` and `principal_investigator` are REDCap roles on this project, so they are
 * resolved from `redcap_user_rights` joined to `redcap_user_information` - the same mapping that
 * governs dashboard access. Nobody has to maintain a parallel list of who the PI is, and somebody
 * removed from the project stops receiving notices the moment their rights are removed. A separate
 * address list would keep emailing them, and that is the failure nobody notices.
 *
 * The one exception is deliberate: the PI asked (2026-10-02) for the "findings ready" notice to go to
 * named people rather than to a group, so `notify-reviewer-emails` can replace the reviewer role as
 * that notice's audience - and then no reviewer role is needed at all. That list IS the parallel list
 * warned about above, so it is made visible instead: namedReviewerList() reports every named address
 * that is not an active reviewer or PI in REDCap (emailed, but unable to open the dashboard), and the
 * launch gate shows them. The roles still govern dashboard access.
 *
 * `care_team`, `on_call_research_staff`, `protocol_lead` and `data_safety_reviewer` are not REDCap
 * users at all - a care team is a clinical service, and giving it a REDCap account to receive an
 * email would be worse than a settings field. Those come from explicit address settings.
 *
 * ## Why an unresolvable role returns empty rather than falling back
 *
 * There is no "if the care team is unconfigured, tell the PI instead". A notice reaching the wrong
 * audience is a disclosure, and a silent substitution is exactly how one happens. Empty here makes
 * NotificationService record a failure, which is visible.
 */
class RedcapRecipientDirectory implements RecipientDirectoryInterface
{
    /** Policy roles resolved from a REDCap role mapping rather than an address list. */
    private const FROM_REDCAP_ROLE = [
        'research_assistant'     => RoleService::RA,
        'principal_investigator' => RoleService::PI,
    ];

    /** Policy roles resolved from an explicit address setting. */
    public const ADDRESS_SETTINGS = [
        'care_team'              => 'notify-care-team-emails',
        'on_call_research_staff' => 'notify-on-call-emails',
        'protocol_lead'          => 'notify-protocol-lead-emails',
        'data_safety_reviewer'   => 'notify-data-safety-emails',
    ];

    /** Named reviewer addresses that replace the reviewer role as the notice audience when set. */
    public const REVIEWER_ADDRESS_SETTING = 'notify-reviewer-emails';

    private MICA $module;
    private RoleService $roles;
    private int $projectId;

    /** @var array<string,list<string>> memoised, because one send resolves the same role repeatedly */
    private array $cache = [];

    public function __construct(MICA $module, RoleService $roles, int $projectId)
    {
        $this->module = $module;
        $this->roles = $roles;
        $this->projectId = $projectId;
    }

    public function addressesForRole(string $policyRole): array
    {
        if (isset($this->cache[$policyRole])) {
            return $this->cache[$policyRole];
        }

        if (isset(self::FROM_REDCAP_ROLE[$policyRole])) {
            return $this->cache[$policyRole] = $this->addressesForMicaRole(self::FROM_REDCAP_ROLE[$policyRole]);
        }

        $setting = self::ADDRESS_SETTINGS[$policyRole] ?? null;

        if ($setting === null) {
            return $this->cache[$policyRole] = [];
        }

        return $this->cache[$policyRole] = self::parseAddresses(
            (string) $this->module->getProjectSetting($setting, $this->projectId)
        );
    }

    /**
     * Who hears that findings are ready, that a session could not be screened, and about overdue
     * acknowledgements.
     *
     * The members of the mapped reviewer role, unless the study has listed named addresses in
     * `notify-reviewer-emails` - then those addresses, **instead of** the role (the PI asked for the
     * results to go to named people rather than to a group, 2026-10-02). The role still decides who
     * can open the review dashboard; this only changes who is emailed.
     */
    public function reviewerAddresses(): array
    {
        return self::chooseReviewerAddresses(
            $this->namedReviewerRaw(),
            fn(): array => $this->addressesForMicaRole(RoleService::RA)
        );
    }

    /**
     * The reviewer role's members only, ignoring the named list.
     *
     * For notices whose recipient has to act in the dashboard - a second-review request is a request to
     * open the finding, so it goes to people who can, not to whoever was named for the results email.
     */
    public function roleReviewerAddresses(): array
    {
        return $this->addressesForMicaRole(RoleService::RA);
    }

    /**
     * The named reviewer list as the launch gate needs to see it.
     *
     * `configured` follows the same test chooseReviewerAddresses() uses (any entry at all), so the gate
     * and the send can never disagree about who the audience is. `unmatched` is every valid named
     * address that is not the email of an active member of the reviewer or PI role: those people are
     * emailed but cannot open the findings, and a departed colleague would be one of them.
     *
     * @return array{configured: bool, addresses: list<string>, unmatched: list<string>}
     */
    public function namedReviewerList(): array
    {
        $raw = $this->namedReviewerRaw();
        $addresses = self::parseAddresses($raw);

        return [
            'configured' => self::split($raw) !== [],
            'addresses'  => $addresses,
            'unmatched'  => $addresses === [] ? [] : self::unmatchedAddresses(
                $addresses,
                array_merge(
                    $this->addressesForMicaRole(RoleService::RA),
                    $this->addressesForMicaRole(RoleService::PI)
                )
            ),
        ];
    }

    /**
     * The rule, kept free of REDCap so it can be unit tested.
     *
     * A setting that has *anything* in it is used as-is, even when every entry fails to parse: falling
     * back to the role would send a safety notice to people the study deliberately took off the list,
     * which is the silent substitution this class refuses everywhere else. An all-invalid list
     * resolves to nobody, NotificationService records that as a failure, and the launch gates
     * (configurationProblems, and the reviewers gate) say so.
     *
     * @param callable(): list<string> $roleAddresses
     * @return list<string>
     */
    public static function chooseReviewerAddresses(string $namedRaw, callable $roleAddresses): array
    {
        if (self::split($namedRaw) !== []) {
            return self::parseAddresses($namedRaw);
        }

        return $roleAddresses();
    }

    /**
     * Named addresses that match none of the given account emails, compared case-insensitively.
     *
     * @param list<string> $named
     * @param list<string> $accounts
     * @return list<string>
     */
    public static function unmatchedAddresses(array $named, array $accounts): array
    {
        $known = array_flip(array_map('strtolower', $accounts));

        return array_values(array_filter($named, static fn(string $a): bool => !isset($known[strtolower($a)])));
    }

    private function namedReviewerRaw(): string
    {
        return (string) $this->module->getProjectSetting(self::REVIEWER_ADDRESS_SETTING, $this->projectId);
    }

    /**
     * Everyone holding a REDCap role mapped to this MICA role, by email.
     *
     * @return list<string>
     */
    private function addressesForMicaRole(string $micaRole): array
    {
        $roleIds = $this->roles->mappedRedcapRoles($micaRole);

        if ($roleIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));

        $result = $this->module->query(
            'SELECT DISTINCT i.user_email FROM redcap_user_rights r '
            . 'JOIN redcap_user_information i ON i.username = r.username '
            . 'WHERE r.project_id = ? AND r.role_id IN (' . $placeholders . ') '
            // A suspended account still holds its rights row. Emailing a departed colleague about a
            // critical finding is worse than not sending: it looks delivered.
            . 'AND (i.user_suspended_time IS NULL) '
            . "AND i.user_email IS NOT NULL AND i.user_email != ''",
            array_merge([$this->projectId], array_map('intval', $roleIds))
        );

        $addresses = [];

        while ($row = $result->fetch_assoc()) {
            $addresses[strtolower(trim((string) $row['user_email']))] = trim((string) $row['user_email']);
        }

        return array_values($addresses);
    }

    /**
     * Every malformed address in the configured lists, so it can be a launch gate.
     *
     * `parseAddresses()` drops what it cannot validate, which is the right behaviour at send time -
     * one typo must not suppress the notice to everyone else on the list. But a silently dropped
     * recipient is only acceptable if somebody was told, and the moment to tell them is when they are
     * setting the study up, not during a critical finding. This is what makes that possible.
     *
     * @return list<string> human-readable problems, empty when every list is clean
     */
    public function configurationProblems(): array
    {
        $problems = [];

        $lists = self::ADDRESS_SETTINGS + ['reviewer_notification' => self::REVIEWER_ADDRESS_SETTING];

        foreach ($lists as $policyRole => $setting) {
            $raw = (string) $this->module->getProjectSetting($setting, $this->projectId);
            $rejected = self::rejected($raw);

            if ($rejected !== [] && $setting === self::REVIEWER_ADDRESS_SETTING && self::parseAddresses($raw) === []) {
                // Not "skipped": with no valid entry the whole reviewer notice reaches nobody.
                $problems[] = sprintf(
                    'The reviewer notification list has no valid address, so "findings ready" notices '
                    . 'would reach nobody: %s',
                    implode(', ', $rejected)
                );
                continue;
            }

            if ($rejected !== []) {
                $problems[] = sprintf(
                    'The %s list contains %d entry/entries that are not valid email addresses and '
                    . 'would be silently skipped: %s',
                    str_replace('_', ' ', $policyRole),
                    count($rejected),
                    implode(', ', $rejected)
                );
            }
        }

        return $problems;
    }

    /**
     * Split a settings blob into addresses.
     *
     * Accepts commas, semicolons and newlines, because a study administrator pasting a list from
     * anywhere will produce one of the three, and rejecting their input over a delimiter would just
     * mean the setting stays empty.
     *
     * @return list<string>
     */
    private static function parseAddresses(string $raw): array
    {
        $addresses = [];

        foreach (array_map([self::class, 'bare'], self::split($raw)) as $part) {
            // Validated rather than trusted: an unparseable address makes REDCap::email() fail for
            // the whole send, so one typo would suppress a notice to everyone else on the list.
            // configurationProblems() is what stops that being silent.
            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $addresses[strtolower($part)] = $part;
            }
        }

        return array_values($addresses);
    }

    /** @return list<string> the entries parseAddresses() would throw away */
    private static function rejected(string $raw): array
    {
        return array_values(array_filter(
            self::split($raw),
            static fn(string $part): bool => !filter_var(self::bare($part), FILTER_VALIDATE_EMAIL)
        ));
    }

    /**
     * `Brian Smith <brian@example.org>` -> `brian@example.org`.
     *
     * Address lists get pasted from a mail client, and on a Production project an all-invalid list is
     * a failed launch gate - which stops new sessions - so the common paste shape is accepted rather
     * than refused. Anything else is returned unchanged and validated as it is.
     */
    private static function bare(string $part): string
    {
        return preg_match('/<\s*([^<>\s]+@[^<>\s]+)\s*>\s*$/', $part, $m) ? $m[1] : $part;
    }

    /** @return list<string> non-empty trimmed parts */
    private static function split(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[,;\r\n]+/', $raw) ?: []),
            static fn(string $part): bool => $part !== ''
        ));
    }
}
