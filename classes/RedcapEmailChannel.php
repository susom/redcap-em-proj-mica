<?php

namespace Stanford\MICA;

require_once __DIR__ . "/NotificationChannelInterface.php";

/**
 * Delivery through REDCap's own mailer.
 *
 * ## Which channels this deployment can honestly claim
 *
 * The policy's channel enum includes `secure_messaging` and `pager_or_on_call_system`. This class
 * does **not** claim them. Reporting an unsupported channel as delivered is the failure mode that
 * matters here: a study configures a pager for critical findings, the notice goes out as ordinary
 * email or nowhere at all, and the trail says "sent". `supports()` returning false makes
 * NotificationService record a failure instead, which is a configuration problem someone can see.
 *
 * `dashboard` is supported by doing nothing, which is correct rather than lazy: a dashboard notice
 * *is* the finding appearing in the review queue, and the queue is written before any of this runs.
 * The row in the notification trail is the delivery.
 */
class RedcapEmailChannel implements NotificationChannelInterface
{
    /** Channels this deployment can actually honour. Anything else is refused, loudly. */
    private const SUPPORTED = ['secure_email', 'dashboard'];

    private string $from;

    /**
     * Takes no module.
     *
     * It used to, and never used it: the From: address is passed in by the caller (which is where the
     * project setting is read) and everything else goes through `\REDCap::email()` and REDCap's
     * globals. An unused constructor dependency is not free - it made the body-to-HTML conversion,
     * which is where every rendering defect in this class lived, impossible to test without a REDCap.
     *
     * @param string $from blank to fall back to REDCap's configured sender
     */
    public function __construct(string $from = '')
    {
        $this->from = $from;
    }

    public function supports(string $channel): bool
    {
        return in_array($channel, self::SUPPORTED, true);
    }

    public function send(string $channel, array $recipients, string $subject, string $body): void
    {
        if ($channel === 'dashboard') {
            // Already delivered: the finding is in the review queue. Nothing to transmit.
            return;
        }

        if (!$this->supports($channel)) {
            throw new \RuntimeException(
                "This deployment cannot deliver on \"$channel\". Configure the policy to use a "
                . 'channel it can honour rather than one it would silently drop.'
            );
        }

        if ($recipients === []) {
            throw new \RuntimeException('No recipient addresses were given.');
        }

        $from = $this->from !== '' ? $this->from : $this->defaultFrom();

        // One message per recipient rather than one with everyone in To:. Staff addresses are not
        // secret, but a study's care team learning who else is on the distribution list is a
        // disclosure nobody asked for, and it is free to avoid.
        $html = self::bodyToHtml($body);
        $failed = [];

        foreach ($recipients as $address) {
            if (!\REDCap::email($address, $from, $subject, $html)) {
                $failed[] = $address;
            }
        }

        if ($failed !== []) {
            throw new \RuntimeException(sprintf(
                'REDCap::email() refused %d of %d recipient(s): %s.',
                count($failed),
                count($recipients),
                implode(', ', $failed)
            ));
        }
    }

    /**
     * Turn a plain-text notification body into the HTML REDCap will send.
     *
     * REDCap always sends multipart/alternative and derives the text/plain part itself, with
     * `Message::formatPlainTextBody()`: `strip_tags(br2nl($body))`. It does **not** decode HTML
     * entities on the way. That one omission decides everything this method does.
     *
     * **Only `<` and `>` are escaped, deliberately - not `&`.** `htmlspecialchars()` would turn the
     * dashboard URL's `?prefix=x&page=y&pid=257` into `&amp;`, which survives strip_tags and lands in
     * the text/plain part verbatim: a reader on a plain-text client copies a broken link, and the link
     * is the only actionable thing in a minimum-necessary body. It did the same to apostrophes
     * ("the reviewer&#039;s rationale"), which is merely ugly. Escaping the two characters that could
     * begin a tag is what actually matters here; the bodies are module-authored and their only
     * variable parts are enum values, integer counts, a record id, a username and timestamps.
     *
     * **URLs become real anchors** so the HTML part is clickable, and because REDCap's plain-text
     * conversion rewrites `<a href="X">Y</a>` to `Y (X)` - which is exactly the right shape for a
     * text reader. The anchor text is the full URL rather than a friendly label, so the text
     * alternative reads `URL (URL)`. That repetition is accepted on purpose: a clinical notice whose
     * visible link text hides where it goes is phishing-shaped, and a reviewer should be able to see
     * that they are being sent to their own REDCap host before they click.
     *
     * **Only a URL alone on its own line is linked.** Every body written by NotificationService puts
     * the dashboard link on a line of its own, so this covers all of them - and it is what stops a
     * URL *inside* another value from becoming clickable. Linking any URL anywhere meant that a record
     * id of `<a href="http://evil.example">click</a>` still produced a live link to evil.example: the
     * injected tag was safely escaped, but the linkifier then found the URL in the escaped text and
     * anchored it. A live link to somewhere else, inside a genuine MICA safety notification, is worth
     * more to an attacker than the tag they could not inject. Record ids cannot contain a newline, so
     * nothing user-supplied can reach a line of its own.
     *
     * Static and pure, so the conversion can be tested without a REDCap or a mail server - every
     * constraint above is a silent-rendering failure otherwise.
     */
    public static function bodyToHtml(string $body): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        foreach ($lines as $i => $line) {
            $escaped = str_replace(['<', '>'], ['&lt;', '&gt;'], $line);
            $url = trim($line);

            $lines[$i] = preg_match('~^https?://[^\s<>"\']+$~', $url) === 1
                ? '<a href="' . $url . '">' . $url . '</a>'
                : $escaped;
        }

        // Newlines are *replaced* by `<br>`, not accompanied by one. `nl2br()` emits "<br>\n", and
        // REDCap's `br2nl` then turns that `<br>` into a second newline - so every line of the
        // text/plain alternative came out double-spaced. Harmless in the HTML part, where a stray
        // newline is just whitespace, and ugly in the one a plain-text reader actually sees.
        return implode('<br>', $lines);
    }

    /**
     * The From: address.
     *
     * REDCap's configured `from_email` is the right default: it is already SPF-aligned for the
     * institution, which a made-up module address would not be, and a notification that lands in a
     * spam folder is a notification that did not happen.
     */
    private function defaultFrom(): string
    {
        global $project_contact_email, $from_email;

        foreach ([$from_email ?? null, $project_contact_email ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'noreply@' . (defined('SERVER_NAME') ? SERVER_NAME : 'localhost');
    }
}
