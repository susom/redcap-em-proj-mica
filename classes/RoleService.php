<?php

namespace Stanford\MICA;

/**
 * Who may do what in the review dashboard, derived from **REDCap's own user roles**.
 *
 * ## Why roles and not a list of usernames
 *
 * An earlier version of this class held three module settings listing usernames. That works and it
 * is wrong: the study team already manages who is on the study through REDCap user roles, and a
 * second roster inside module settings is a second thing to keep in sync. The failure mode is
 * specific and bad — somebody leaves the study, their REDCap access is removed, and they keep a MICA
 * reviewer role in a setting nobody thought to open. Access to participant transcripts should be
 * governed by the same thing that governs access to the project.
 *
 * So the module configures a *mapping*, not a roster: which REDCap role counts as a reviewer, which
 * as PI, which as auditor. Adding a person is assigning them a REDCap role, which the study team
 * already does and REDCap already logs.
 *
 * **A user with no REDCap role has no MICA role.** REDCap allows per-user rights with no role
 * assigned, and those users get nothing here. That is the honest consequence of role-based access
 * rather than an oversight, and the page says so in as many words so the fix is obvious.
 *
 * ## The permission matrix
 *
 * Data, in one place, and every endpoint asks this before doing anything. Hiding a button is not
 * access control (`stage-5-ra-dashboard.md` §5.2); the check that matters is the one on the server,
 * and a matrix a reviewer can read in one screen is far likelier to be right than the same rules
 * spread across seven handlers.
 *
 * ## Four MICA roles, and one that is deliberately weak
 *
 *   `ra`        reviews findings. Under the RA-first policy this is the role that decides things.
 *   `pi`        everything the RA can do, plus the audit trail.
 *   `auditor`   read-only and de-identified: history aggregates and the audit trail, never a
 *               transcript and never a disposition.
 *   `sysadmin`  a REDCap super user. Configures the notification policy and **cannot submit a
 *               clinical decision** - not because a super user could not technically do it, but
 *               because a disposition carries the weight of the reviewer's clinical judgment, and
 *               one submitted by whoever held admin rights would be indistinguishable from one an RA
 *               made. The handoff's RA-first workflow depends on that difference. It is the one role
 *               that is NOT a project role, because super-user status is not one either.
 *
 * Care-team members are **not** a role here. They receive notifications and get no dashboard access,
 * which is why the notification path carries minimum-necessary content rather than a deep link.
 */
class RoleService
{
    public const RA       = 'ra';
    public const PI       = 'pi';
    public const AUDITOR  = 'auditor';
    public const SYSADMIN = 'sysadmin';

    /** Project settings holding the REDCap role ids that map to each MICA role. */
    public const SETTINGS = [
        self::RA      => 'role-ra-reviewer',
        self::PI      => 'role-pi-lead',
        self::AUDITOR => 'role-auditor',
    ];

    /**
     * Every dashboard action, and which roles may perform it.
     *
     * Exhaustive on purpose: `can()` denies anything not listed, so a new endpoint that forgets to
     * add itself is refused rather than silently open. That is the failure direction to choose - an
     * endpoint nobody can reach gets reported on the first test, whereas one everybody can reach
     * gets reported by an auditor.
     */
    public const MATRIX = [
        // Reading the queue or a session means reading participant-level clinical content, so it is
        // the two decision-making roles only.
        'reviewQueue'       => [self::RA, self::PI],
        'reviewSession'     => [self::RA, self::PI],

        // Clinical decisions. Deliberately NOT sysadmin - see the class comment.
        'submitDisposition' => [self::RA, self::PI],
        'submitAction'      => [self::RA, self::PI],

        // History includes zero-finding and failed sessions. The auditor's view is de-identified
        // aggregates - enforced by the handler, which asks isDeidentifiedOnly().
        'reviewHistory'     => [self::RA, self::PI, self::AUDITOR],

        // Who looked at what. An RA is excluded from their own oversight record.
        'auditTrail'        => [self::PI, self::AUDITOR],

        // Configuration, which is the sysadmin's whole remit here.
        'getPolicy'         => [self::SYSADMIN],
        'savePolicy'        => [self::SYSADMIN],
        'launchReadiness'   => [self::SYSADMIN, self::PI],
    ];

    /** @var array<string,string[]> MICA role => REDCap role ids mapped to it */
    private array $mapping;

    /** @var array<string,?string> lower-cased username => their REDCap role id on this project */
    private array $roster;

    private bool $isSuperUser;

    /**
     * @param array<string,string[]>  $mapping MICA role => REDCap role ids
     * @param array<string,?string>   $roster  username => REDCap role id (null when they have none)
     */
    public function __construct(array $mapping, array $roster = [], bool $isSuperUser = false)
    {
        $this->mapping = [];
        foreach (self::SETTINGS as $role => $_setting) {
            // Role ids are numeric in REDCap but arrive from settings as strings; compared as
            // strings throughout so 663 and "663" cannot disagree.
            $this->mapping[$role] = array_values(array_unique(array_map(
                'strval',
                array_filter(
                    $mapping[$role] ?? [],
                    static fn($id): bool => is_scalar($id) && trim((string) $id) !== ''
                )
            )));
        }

        $this->roster = [];
        foreach ($roster as $username => $roleId) {
            // Usernames are compared case-insensitively: REDCap treats them that way, and access
            // that silently does not apply because of capitalisation is worse than none.
            $this->roster[strtolower(trim((string) $username))] =
                ($roleId === null || $roleId === '') ? null : (string) $roleId;
        }

        $this->isSuperUser = $isSuperUser;
    }

    /**
     * Read the mapping and the project's roster off REDCap.
     *
     * One query for the roster rather than a lookup per username: a project has tens of users, and
     * holding the whole map keeps rolesFor() a pure function that any caller can ask about any user
     * - which the audit logger needs, since it records the role somebody was acting as.
     */
    public static function fromModule(MICA $module, ?int $projectId = null): self
    {
        $pid = $projectId ?? (defined('PROJECT_ID') ? (int) PROJECT_ID : null);

        $mapping = [];
        foreach (self::SETTINGS as $role => $setting) {
            $value = $pid === null
                ? $module->getProjectSetting($setting)
                : $module->getProjectSetting($setting, $pid);

            $mapping[$role] = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        }

        $roster = [];
        if ($pid !== null) {
            $result = $module->query(
                'SELECT username, role_id FROM redcap_user_rights WHERE project_id = ?',
                [$pid]
            );
            while ($row = $result->fetch_assoc()) {
                $roster[(string) $row['username']] = $row['role_id'] === null ? null : (string) $row['role_id'];
            }
        }

        return new self($mapping, $roster, \ExternalModules\ExternalModules::isSuperUser());
    }

    /** The REDCap role id this user holds on the project, or null. */
    public function redcapRoleFor(?string $username): ?string
    {
        return $this->roster[strtolower(trim((string) $username))] ?? null;
    }

    /** True when the user is on the project at all, role or not - for a precise refusal message. */
    public function isOnProject(?string $username): bool
    {
        return array_key_exists(strtolower(trim((string) $username)), $this->roster);
    }

    /** @return string[] every MICA role this user holds, in a stable order */
    public function rolesFor(?string $username): array
    {
        $roles = [];
        $redcapRole = $this->redcapRoleFor($username);

        // A blank username is nobody, and so is a user with no REDCap role. Both simply hold no
        // MICA role rather than being an error, which keeps every check uniform.
        if ($redcapRole !== null && trim((string) $username) !== '') {
            foreach ([self::RA, self::PI, self::AUDITOR] as $role) {
                if (in_array($redcapRole, $this->mapping[$role], true)) {
                    $roles[] = $role;
                }
            }
        }

        // A super user is a sysadmin regardless of any project role - they can edit the module
        // config anyway, so pretending otherwise would only make the matrix a lie. It grants nothing
        // clinical.
        if ($this->isSuperUser) {
            $roles[] = self::SYSADMIN;
        }

        return $roles;
    }

    public function can(?string $username, string $action): bool
    {
        // Unlisted action => denied. See MATRIX's comment on the failure direction.
        $allowed = self::MATRIX[$action] ?? [];

        return array_intersect($this->rolesFor($username), $allowed) !== [];
    }

    /**
     * @throws ReviewAccessException with a message safe to return to the client
     */
    public function requireRole(?string $username, string $action): void
    {
        if ($this->can($username, $action)) {
            return;
        }

        if (!isset(self::MATRIX[$action])) {
            // A caller bug, not a permissions problem, and worth saying so: otherwise a typo'd
            // action name looks to a developer exactly like a misconfigured role.
            throw new ReviewAccessException(
                "\"$action\" is not a known dashboard action, so it is denied. This is a "
                . 'programming error rather than a permissions problem.'
            );
        }

        throw new ReviewAccessException(sprintf(
            'You do not have permission to %s. That action is for: %s. Your roles: %s.',
            $action,
            implode(', ', self::MATRIX[$action]),
            $this->rolesFor($username) === [] ? 'none' : implode(', ', $this->rolesFor($username))
        ));
    }

    /** Any role at all - what the page itself checks before rendering anything. */
    public function hasAnyRole(?string $username): bool
    {
        return $this->rolesFor($username) !== [];
    }

    /**
     * An auditor sees de-identified aggregates only. True when the user's *only* relevant role is
     * auditor: someone who is also an RA is an RA, because removing existing access by adding an
     * oversight role would be a surprising way to lose a clinical view.
     */
    public function isDeidentifiedOnly(?string $username): bool
    {
        $roles = $this->rolesFor($username);

        return in_array(self::AUDITOR, $roles, true)
            && !in_array(self::RA, $roles, true)
            && !in_array(self::PI, $roles, true);
    }

    /** The role recorded on an audit event, for "who did this, acting as what". */
    public function primaryRoleFor(?string $username): ?string
    {
        // Most-privileged-first, so an audit row says `pi` for someone who is both PI and RA rather
        // than understating what they were entitled to do.
        foreach ([self::PI, self::RA, self::AUDITOR, self::SYSADMIN] as $role) {
            if (in_array($role, $this->rolesFor($username), true)) {
                return $role;
            }
        }

        return null;
    }

    /** @return string[] REDCap role ids mapped to a MICA role - for the launch-readiness gate */
    public function mappedRedcapRoles(string $role): array
    {
        return $this->mapping[$role] ?? [];
    }

    /** True when no REDCap role is mapped to any MICA role - i.e. nobody can review anything. */
    public function isUnconfigured(): bool
    {
        foreach (self::SETTINGS as $role => $_setting) {
            if ($this->mapping[$role] !== []) {
                return false;
            }
        }

        return true;
    }
}
