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

    private MICA $module;
    private string $from;

    public function __construct(MICA $module, string $from = '')
    {
        $this->module = $module;
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
        $failed = [];

        foreach ($recipients as $address) {
            if (!\REDCap::email($address, $from, $subject, nl2br(htmlspecialchars($body, ENT_QUOTES)))) {
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
