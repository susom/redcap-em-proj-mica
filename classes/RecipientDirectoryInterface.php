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
     * Addresses for the "findings ready" / "could not be screened" and overdue-acknowledgement notices.
     *
     * Everyone in the mapped MICA reviewer role, unless the study listed named reviewer addresses
     * (`notify-reviewer-emails`), which then replace the role as the audience. The reviewer role IS
     * the assignment for dashboard access either way - see LaunchReadiness::reviewersGate() on why
     * there is no per-finding assignee field.
     *
     * @return list<string>
     */
    public function reviewerAddresses(): array;

    /**
     * Everyone in the mapped MICA reviewer role, ignoring any named list.
     *
     * For notices whose recipient must act in the dashboard, such as a second-review request.
     *
     * @return list<string>
     */
    public function roleReviewerAddresses(): array;
}
