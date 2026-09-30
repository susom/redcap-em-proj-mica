Subject: Weekly SMS: why two texts arrived out of order

Hi Brian,

Thanks for flagging the order. Twilio's log shows it sent the two texts in the right order, one second apart. The feedback message was split into 3 pieces and the question was 1 piece. A phone only shows a split text once all its pieces arrive, so the short question got there first.

Two things make a text split:
1. More than 160 characters. Anything longer is sent in pieces.
2. "Smart" punctuation. A curly apostrophe (’) or a long dash (—), which Word and Google Docs insert automatically, switches the whole text to a format where the limit drops to 70 characters. That's what split the feedback into 3.

Could you keep each SMS text to 160 characters or fewer, in plain keyboard characters: a straight apostrophe (') instead of ’, and a regular hyphen (-) or a comma instead of —.

These sunday texts need a change:

Replace the curly apostrophe (’) with a straight one ('):
- bd_1: "This week’s drinking was in the range..."
- gg_3: "You’ve already taken an important step..."
- gg_9: "You’ve made a thoughtful decision..."
- gg_12: "Well done. Deciding ahead of time how you’ll..."

Replace the long dash (—) with a hyphen or comma:
- sd_11: "You met a goal that many people find challenging—..."
- gg_11: "Great job committing to a goal..." (also shorten it, 176 characters)

Shorten to 160 characters or fewer:
- gg_1 (189 characters): "Great decision! Setting a drinking limit..."
- bd_11 (170): "Drinking above lower-risk levels..."
- bd_3 (169): "Sometimes drinking patterns move us closer..."
- gp_6 (164): "You may not be ready to set a limit..."
- bd_2 (163): "Drinking above recommended limits..."

With every text in one piece, this shouldn't happen again. It also lowers the texting cost, since Twilio bills each piece separately.

Thanks,
[Name]
