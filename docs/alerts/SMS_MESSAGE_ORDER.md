# Weekly SMS: messages arriving out of order

**Reported 2026-09-28 by the PI** (Test 6B, record 89). After he answered "15", the phone showed
"Are you planning on drinking alcohol this week?" **before** "This week's drinking was in the range
considered unhealthy…". The feedback must come first. He asked for "a slight delay to ensure correct
order".

## Verified: the module sends them in the right order

- **Reproduced with the module's own code.** `FormManager` ran on the prod replica (PID 277, same
  dictionary), with record 89's week-1 `dquant` = 15 and the same two calls `handleReply()` makes:
  ```
  next_field after dquant: ar_1
    send #1: This week’s drinking was in the range considered unhealthy. …
    send #2: Are you planning on drinking alcohol this week?
  ```
- **How it assembles the batch.** `FormManager::buildContext()` walks the form in field order, adds
  every descriptive message whose branching is true, stops at the next question, and returns
  `[messages…, question]`.
- **It already waits.** `TwilioManager::sendBulkTwilioMessages()` sends them one at a time with a
  **300 ms** pause after each ("to prevent messages from arriving out of order").

So the swap happened **after the module handed both texts to Twilio**.

## Confirmed by Twilio (2026-09-28): the longer message arrived last

Twilio's messaging log for the PI's number:

| Created (PDT) | Message | Segments | Arrived on the phone |
|---|---|---|---|
| 17:02:23 | `bd_1` feedback | **3** | **second** |
| 17:02:24 | `drink_fut` question | **1** | **first** |

Twilio created them in the right order, one second apart. The 3-segment message was shown after the
1-segment one. A multi-segment SMS travels as separate pieces and the phone shows it only when all of
them have arrived, so a one-piece text sent a second later can overtake it. Twilio doesn't guarantee
delivery order across separate messages. The same log shows a **2-segment** outbound at 17:03:38,
which carries the same risk.

## Complete fix without code: every text one segment

Besides the six Unicode texts below, six feedback texts are **over 160 characters**, so they're 2
segments even in plain characters. Each is followed by a question in its batch:

| Field | Characters (after the quote/dash fix) |
|---|---|
| `bd_2` | 163 |
| `bd_3` | 169 |
| `bd_11` | 170 |
| `gp_6` | 164 |
| `gg_1` | 189 |
| `gg_11` | 176 |

Shortening these to 160 characters or fewer, plus the six character fixes, makes every `sunday`
message a single segment. That removes the cause seen here. It is still not a strict guarantee, which
only the one-text change below gives. No question text is over 160 characters. Lengths are from the
raw labels. Piped values like `[calc_gset_threshold]` are shorter once filled in, but a long first
name in the greeting adds characters.

## The original analysis (before the Twilio check)

`bd_1` contains a curly apostrophe (`week’s`). That makes it a Unicode (UCS-2) SMS of **3 parts**
(145 characters; 67 per part). The question is 47 characters and **1 part**. A phone shows a
multi-part SMS only once all of its parts arrive, so a 1-part text sent 300 ms later can be shown
first. This fits the screenshot: the only pair that reversed is the one led by a multi-part message.
But it's one observation, and delivery order isn't under the module's control.

**To confirm on prod:** Twilio console → Monitor → Logs → Messaging, the PI's number, 2026-09-27
around 5:01 PM.

| Twilio shows | Meaning |
|---|---|
| `bd_1` created first, about 0.3 s before the question, with **3 segments** | The module and Twilio sent in order. The reversal happened in delivery (carrier or handset). |
| The question created **first** | This analysis is wrong. Investigate the module. |

The module's **View Logs** (outgoing `MessageHistory` entries) show the same send order.

## The only fix that guarantees the order: send them as one text

Twilio does not guarantee delivery order for separate messages, so a longer delay only makes a swap
less likely. The module already sends a single combined text for invalid replies:

```php
// EnhancedSMSConversation.php:616-618 (invalid reply: warning + question in one SMS)
$outbound_sms = implode("\n", array_filter([$invalid_response, $FM->getQuestionLabel()]));
$TM->sendTwilioMessage($cell_number, $outbound_sms);
```

A normal reply goes through `sendBulkTwilioMessages()` as separate texts, at line 135 (start of the
conversation) and line 585 (after a valid answer). Joining those batches the same way
(`implode("\n\n", array_filter($FM->getArrayOfMessagesAndQuestion()))` → `sendTwilioMessage()`)
makes the feedback and the question one bubble, in order, every time.

This change is in **Stanford's shared Enhanced SMS Conversation module**
(`susom/enhanced_sms_conversation`), not in MICA. It needs a PR there and a prod deploy. It changes
what every project using the module sees, so it's better behind a project setting.

## Considered and not pursued (2026-09-28)

- **A configurable delay** (Delay Between Messages, default 300 ms) was built on a local branch of the
  module, tested, then **reverted at the study team's request**. It only lowers the odds.
- **Waiting for Twilio's "delivered" report before the next text** would need a new public
  status-callback endpoint and a per-conversation queue. A late or missing receipt would stall the
  conversation until the module's once-a-minute cron.
- **Requiring the participant to reply before the next text** is poor UX. It means more replies,
  more drop-off before the question, and it changes the designed flow.

**Decision:** keep every `sunday` text to one segment (below). If a strict guarantee is ever needed,
the one-text change above is the clean route.

## Still worth doing, but not a guarantee: plain characters in six texts

Six `sunday` texts are Unicode only because of `’` or `—`. Replacing them with `'` and ` - ` makes
them 1-part (`gg_11`: 2), which removes the likely cause for those messages and cuts their cost
(Twilio bills per part):

| Field | Change | Parts now → after |
|---|---|---|
| `bd_1` | This week**'**s drinking was in the range considered unhealthy. … | 3 → 1 |
| `gg_3` | You**'**ve already taken an important step by choosing a limit. … | 3 → 1 |
| `gg_9` | You**'**ve made a thoughtful decision. … | 2 → 1 |
| `gg_12` | … how you**'**ll respond if offered another drink … | 3 → 1 |
| `gg_11` | … more than planned **-** such as carrying only enough money for your intended drinks **-** can support your success. | 3 → 2 |
| `sd_11` | … many people find challenging **-** enjoying alcohol while keeping it within boundaries … | 2 → 1 |

The module's own texts (reminder, expiry, no-open-conversation) are already plain.
