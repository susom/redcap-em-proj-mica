<?php

namespace Stanford\MICA;

/**
 * Where a participant lands at the end of the arm-1 chain when there is no session to send them to.
 *
 * ## Why this page exists
 *
 * The handoff survey's *Redirect to a URL* is `[ed_session_url]`, and REDCap's redirect is
 * all-or-nothing per survey: the guard at `Surveys/index.php:1833` tests the template **before**
 * piping, so a template that pipes to an empty string still reaches `redirect('')`. Measured in a
 * browser: `302` with `Location:` empty and a **completely blank page** - body length zero, no
 * acknowledgement text, nothing. So the field has to be non-empty for every record that finishes the
 * chain, and the records with no session need somewhere real to go. This is that somewhere.
 *
 * ## Why it says so little, and never mentions the arm
 *
 * `state` distinguishes two situations, and neither message may reveal an allocation. Telling a
 * participant "you have no MICA session" tells them they are in the control arm, which is
 * unblinding - the study's own analysis is entitled to that not happening by accident on a page
 * nobody reviewed. So `done` is the generic completion sentence the survey's acknowledgement would
 * have shown, and `pending` says a person will be with them. A participant editing `state` by hand
 * swaps one of those two sentences for the other and learns nothing either way, which is why this
 * page takes no record identifier at all.
 *
 * Declared in `no-auth-pages`: the participant is not a REDCap user, and by this point they have
 * usually just left an unauthenticated survey.
 */

/** @var \Stanford\MICA\MICA $module */

$state = ($_GET['state'] ?? '') === 'pending' ? 'pending' : 'done';

/*
 * Study-overridable for `pending` only. That is the sentence a site may want to make specific -
 * naming the CRC, or the ED's own wording. `done` stays built-in on purpose: there is nothing
 * study-specific to say, and any customisation there is a chance to accidentally disclose the arm.
 */
$configured = trim((string) $module->getProjectSetting('session-handoff-text'));

$message = $state === 'pending'
    ? ($configured !== ''
        ? $configured
        : 'Thank you. Someone from the study team will be with you shortly to continue.')
    : 'Thank you. You have finished this part of the study.';

$module->emDebug("session handoff page shown (state=$state)");

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MICA study</title>
    <style>
        /* Self-contained: this page must render identically for a participant who has just been
           redirected out of a survey, with no REDCap chrome and no build step. */
        :root { color-scheme: light; }
        html, body { margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            box-sizing: border-box;
            background: #f5f6f8;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #23272b;
        }
        .card {
            background: #fff;
            border: 1px solid #e3e5e8;
            border-radius: 12px;
            padding: 32px 28px;
            max-width: 30rem;
            width: 100%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
            text-align: center;
        }
        .mark {
            width: 44px; height: 44px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: #eef3fb;
            color: #2a5db0;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; line-height: 1;
        }
        p {
            margin: 0;
            /* 1.25rem, not px: a participant who has scaled their text up is exactly the participant
               most likely to be reading this on a phone in an ED. */
            font-size: 1.0625rem;
            line-height: 1.55;
        }
    </style>
</head>
<body>
    <div class="card" role="status">
        <div class="mark" aria-hidden="true"><?= $state === 'pending' ? '&#8987;' : '&#10003;' ?></div>
        <p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </div>
</body>
</html>
