Subject: MICA: critical safety findings to the CRC within 5 minutes

Hi Brian,

Thanks. The eligibility rules are confirmed, and I'll make that change.

On safety alerts: testing your 5-minute requirement turned up a bug. The AI safety check was failing on every session, so a critical disclosure would only have reached the CRC as "this session could not be screened", about 7.5 minutes later. That is fixed. In testing, a session where the participant described a plan to overdose that night was flagged critical, and the CRC's email arrived 22 to 65 seconds after the participant ended the session. The fix has to be deployed to production before we go live.

Three things I need from you:

1. Who are the CRCs? Each needs a REDCap account on the project, and I'll add them to the role that receives these emails.

2. If a participant closes the chat without tapping End Session, the session isn't checked until it times out, 24 hours after it started. Is that acceptable, or should we shorten it (for example to 2 hours)? The trade-off is that once it closes, the participant can't return to it.

3. The launch check needs one more number: how many minutes after each safety email the system sends a single reminder (for example 30). It sends that reminder whether or not someone has already looked, because the system doesn't record who has reviewed a finding.

Thanks,
[Name]
