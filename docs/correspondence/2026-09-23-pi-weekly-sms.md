**Subject:** MICA weekly SMS check-in — set up on production

Hi [PI name],

The Arm 3 weekly SMS check-in is now configured on the production MICA project.

**What it does:** every Sunday at noon for 12 weeks, Arm 3 participants get a short text
conversation: drinks in the past week, tailored feedback, and a goal for the coming week (4 or fewer
drinks for men, 3 for women). It starts the first Sunday after randomization and stops automatically
if the participant withdraws or texts STOP.

**Enhanced SMS Conversation module settings.** The module turns a REDCap survey into a two-way text
conversation. It texts the questions one at a time, saves each reply to the record, and picks the
next question based on the answer.
- Phone field: `phonen` (Day 1, Arm 1); Twilio number: [number]
- Reminder after 60 min without a reply; the conversation expires after 3 hours
- Opt-out: STOP/OPTOUT ticks `sms_stop`, START reverses it; withdrawn participants get no texts
- Incoming replies enabled. Tested today: a text to the study number got the automatic reply.

**Before it goes live:**
1. The weekly invitation is built but **switched off**.
2. Randomization on production needs fixing first, since texts only go to Arm 3.
3. Decision needed: should the randomization date be stamped automatically, or entered by the CRC?
   The schedule is calculated from it.
4. Test records that share phone numbers need cleaning up, or replies can land on the wrong record.

The updated test manual is attached. I suggest one live test on a single test record before we
switch it on.

Best,
Ihab
