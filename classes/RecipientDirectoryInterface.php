<?php

namespace Stanford\MICA;

/**
 * Who a policy role actually resolves to.
 *
 * The policy names roles - `principal_investigator`, `care_team` - and the study configures the
 * addresses. Kept behind an interface because "who is the care team" is the single most
 * study-specific thing in the whole pipeline, and because a role that resolves to nobody has to be
 * detectable as such: NotificationService treats an empty resolution as a failure worth recording,
 * not as a successful send to zero people.
 */
interface RecipientDirectoryInterface
{
    /**
     * @return list<string> addresses for a policy role name; empty when the role is unconfigured
     */
    public function addressesForRole(string $policyRole): array;

    /**
     * Addresses for everyone in the mapped MICA reviewer role.
     *
     * The reviewer role IS the assignment - see LaunchReadiness::reviewersGate() on why there is no
     * per-finding assignee field.
     *
     * @return list<string>
     */
    public function reviewerAddresses(): array;
}
