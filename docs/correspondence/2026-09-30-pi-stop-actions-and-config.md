Subject: Re: Pre-screen "End Survey" and the MICA settings

Hi Brian,

**1. Pre-screen (fixed on production).** "End Survey" only closes the current survey. The pre-screen always continued to the next one, so all four stop answers went on through AUDIT-C to consent. On the production project, the pre-screen now continues only when all four pass:

[english] = '1' AND [prison] = '0' AND [goodcand] = '1' AND [s_interest] = '1'

It's set under pre_screen > Survey settings > "Auto-continue to next survey". We tested every stop answer on our copy (MICA v3), on a computer and a phone.

A stopped pre-screen now lands on a blank page, because the pre-screen has no "Survey Completion Text". You may want to add one, e.g. "This patient does not meet the pre-screening criteria."

**2. screen2 isn't enabled as a survey, so it never shows up in the workflow.** The phone and pregnancy questions are never asked, and AUDIT-C goes straight to consent. On our copy, a never-drinker reached the consent form. Is that intended? If not: enable screen2 as a survey, and let AUDIT-C and screen2 continue only when AUDIT-C is positive, there's a phone, and the participant isn't pregnant. I can set it up and test it first.

**3. Settings cleanup.** I removed 10 MICA module settings that did nothing in this study: the pilot's Session 2–7 contexts, its session-length and session-memory settings, and the intro and end-session text boxes, which were never connected to the chat. Everything left is in use. It reaches production with the next module update. If by "Admin" you meant the admin instrument, send me the fields you have in mind.

Thanks,
[Name]
