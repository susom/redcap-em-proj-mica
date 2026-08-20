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

        return $this->cache[$policyRole] = $this->parseAddresses(
            (string) $this->module->getProjectSetting($setting, $this->projectId)
        );
    }

    public function reviewerAddresses(): array
    {
        return $this->addressesForMicaRole(RoleService::RA);
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

        foreach (self::ADDRESS_SETTINGS as $policyRole => $setting) {
            $raw = (string) $this->module->getProjectSetting($setting, $this->projectId);
            $rejected = $this->rejected($raw);

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
    private function parseAddresses(string $raw): array
    {
        $addresses = [];

        foreach ($this->split($raw) as $part) {
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
    private function rejected(string $raw): array
    {
        return array_values(array_filter(
            $this->split($raw),
            static fn(string $part): bool => !filter_var($part, FILTER_VALIDATE_EMAIL)
        ));
    }

    /** @return list<string> non-empty trimmed parts */
    private function split(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[,;\r\n]+/', $raw) ?: []),
            static fn(string $part): bool => $part !== ''
        ));
    }
}
