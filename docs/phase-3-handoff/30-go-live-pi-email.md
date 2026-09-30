Subject: MICA: what's left before we move to production

Hi Brian,

Short version: randomization and the weekly texts are working. Before we move to production there are eight things to fix. Most are settings on our side, but two need input: the production allocation table from the statistician, and a decision on how quickly a critical safety finding must be acknowledged.

1. Eligibility. The consent step doesn't check age or prison, so a 17- or 70-year-old, or someone in prison, can reach consent. We'd bring back the eligibility calculation and use it for the consent step and the eligibility messages.

2. Stop actions. They end the current survey but not the chain: "not interested", "not English" and "in prison" still carry on into the AUDIT-C. One condition on the pre-screen survey fixes it.

3. Randomization date. Half of the recently randomized records have no randomization date, so their weekly texts, reminders and follow-up dates never start. We'll turn on the module's automatic date and remove @TODAY from that field.

4. Alerts. All 22 are switched off, including the phone-verification passcode. Please tell us which ones the protocol needs, and we'll re-test them.

5. Production allocation table. We need the statistician's table, with the columns redcap_randomization_number, redcap_randomization_group and rand_strata, rows for both strata, and enough slots in each stratum for all 750 participants. It has to be uploaded before the move; afterwards only a REDCap admin can do it.

6. Randomization check. Randomization should also require eligibility and a completed consent, not just a completed TSR.

7. Safety review launch settings. We need your decision on how quickly a critical finding must be acknowledged. Until that and the other launch checks are set, sessions are refused once we're in production.

8. Test data. All test records have to go at the move, together with the saved texting conversations and session history, so real participants don't inherit test data.

Also before the move, because these lock afterwards: take the screening surveys off Arms 2 and 3 (their public links skip eligibility and consent), and rename Arm 1 "Screening + Standard Care".

Thanks,
[Name]
