<?php

namespace Stanford\MICA;

/**
 * Who may do what in the review dashboard.
 *
 * The permission matrix is data, in one place, and every endpoint asks this before doing anything.
 * Hiding a button is not access control (`stage-5-ra-dashboard.md` §5.2); the check that matters is
 * the one on the server, and a matrix a reviewer can read in one screen is far likelier to be right
 * than the same rules spread across seven handlers.
 *
 * ## Four roles, and one that is deliberately weak
 *
 *   `ra`        the research assistant who reviews findings. The RA-first policy means this is the
 *               role that actually decides things.
 *   `pi`        PI or protocol lead. Everything the RA can do, plus the audit trail.
 *   `auditor`   read-only, and de-identified: history aggregates and the audit trail, never a
 *               transcript and never a disposition.
 *   `sysadmin`  a REDCap super user. Configures the notification policy and **cannot submit a
 *               clinical decision** - not because a super user could not technically do it, but
 *               because a disposition carries the weight of the reviewer's clinical judgment, and
 *               one submitted by whoever happened to hold admin rights would be indistinguishable
 *               from one an RA made. The handoff's RA-first workflow depends on that difference.
 *
 * Care-team members are **not** a role here. They receive notifications; they get no dashboard
 * access at all, which is why the notification path carries minimum-necessary content rather than
 * a deep link into the transcript.
 *
 * ## A user may hold more than one role
 *
 * Small studies overlap: the PI is often also a reviewer. So membership is a set, and a permission
 * is granted if *any* held role grants it. Deny is never inherited - there is no "sysadmin cannot
 * disposition, therefore an RA who is also a sysadmin cannot" - because that would make adding an
 * admin right silently remove a clinical one.
 */
class RoleService
{
    public const RA       = 'ra';
    public const PI       = 'pi';
    public const AUDITOR  = 'auditor';
    public const SYSADMIN = 'sysadmin';

    /** Project settings holding the user lists. */
    public const SETTINGS = [
        self::RA      => 'role-ra-reviewer',
        self::PI      => 'role-pi-lead',
        self::AUDITOR => 'role-auditor',
    ];

    /**
     * Every dashboard action, and which roles may perform it.
     *
     * Kept exhaustive on purpose: `can()` denies anything not listed, so a new endpoint that forgets
     * to add itself here is refused rather than silently open. That is the failure direction to
     * choose - an endpoint nobody can reach gets reported on the first test, whereas one everybody
     * can reach gets reported by an auditor.
     */
    public const MATRIX = [
        // Reading the queue and a session means reading participant-level clinical content, so it
        // is the two decision-making roles only.
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

    /** @var array<string,string[]> role => usernames, lower-cased */
    private array $members;

    private bool $isSuperUser;

    /**
     * @param array<string,string[]> $members role => usernames, from the project settings
     */
    public function __construct(array $members, bool $isSuperUser = false)
    {
        $this->members = [];
        foreach (self::SETTINGS as $role => $_setting) {
            $this->members[$role] = array_values(array_unique(array_map(
                // Usernames are compared case-insensitively: REDCap treats them that way, and a
                // role that silently does not apply because of capitalisation is worse than no role.
                static fn(string $u): string => strtolower(trim($u)),
                array_filter($members[$role] ?? [], static fn($u): bool => is_string($u) && trim($u) !== '')
            )));
        }

        $this->isSuperUser = $isSuperUser;
    }

    /**
     * Read the role lists off the module's project settings.
     *
     * Repeatable settings come back as arrays; a single-value setting comes back as a scalar. Both
     * shapes are normalised here rather than at each call site.
     */
    public static function fromModule(MICA $module, ?int $projectId = null, ?string $username = null): self
    {
        $members = [];
        foreach (self::SETTINGS as $role => $setting) {
            $value = $projectId === null
                ? $module->getProjectSetting($setting)
                : $module->getProjectSetting($setting, $projectId);

            $members[$role] = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        }

        return new self($members, \ExternalModules\ExternalModules::isSuperUser());
    }

    /** @return string[] every role this user holds, in a stable order */
    public function rolesFor(?string $username): array
    {
        $roles = [];

        // A blank username is nobody. It happens on a no-auth request, and treating it as a user
        // with no roles rather than as an error keeps the check uniform: they simply cannot do
        // anything.
        $normalised = strtolower(trim((string) $username));

        if ($normalised !== '') {
            foreach ([self::RA, self::PI, self::AUDITOR] as $role) {
                if (in_array($normalised, $this->members[$role], true)) {
                    $roles[] = $role;
                }
            }
        }

        // A super user is a sysadmin regardless of the lists - they can edit the module config
        // anyway, so pretending otherwise would only make the matrix a lie. It grants nothing
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
     * auditor: someone who is also an RA is an RA, because removing their existing access by adding
     * an oversight role would be a surprising way to lose a clinical view.
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

    /** @return string[] configured usernames for a role - for the launch-readiness gate */
    public function membersOf(string $role): array
    {
        return $this->members[$role] ?? [];
    }
}
