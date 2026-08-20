<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\RecipientDirectoryInterface;

/**
 * Policy role -> addresses, configurable per test.
 *
 * Starts populated for every role the policy schema allows, so a test that wants to prove "a
 * confirmed finding with nowhere to go is recorded as a failure" has to empty one deliberately -
 * rather than passing because the fixture never had addresses in the first place.
 */
final class FakeRecipientDirectory implements RecipientDirectoryInterface
{
    /** @var array<string,list<string>> */
    public array $byRole = [
        'principal_investigator' => ['pi@example.org'],
        'protocol_lead'          => ['protocol@example.org'],
        'care_team'              => ['care@example.org'],
        'on_call_research_staff' => ['oncall@example.org'],
        'research_assistant'     => ['ra@example.org'],
        'data_safety_reviewer'   => ['dsmb@example.org'],
    ];

    /** @var list<string> */
    public array $reviewers = ['ra@example.org', 'ra2@example.org'];

    public function addressesForRole(string $policyRole): array
    {
        return $this->byRole[$policyRole] ?? [];
    }

    public function reviewerAddresses(): array
    {
        return $this->reviewers;
    }
}
