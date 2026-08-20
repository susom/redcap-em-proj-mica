<?php

namespace Stanford\MICA;

/**
 * Transport. Given addresses, a subject and a body, deliver it - or throw.
 *
 * Deliberately knows nothing about findings, policy or review status. Everything that decides
 * *whether* a notice may go out lives in NotificationService; this only decides how bytes travel.
 * Keeping the split sharp is what lets the gate be tested without a mail server and lets the mail
 * path be swapped for secure messaging without touching a single clinical rule.
 */
interface NotificationChannelInterface
{
    /**
     * @param list<string> $recipients
     * @throws \RuntimeException when delivery could not be attempted or was rejected
     */
    public function send(string $channel, array $recipients, string $subject, string $body): void;

    /** Channels this transport can actually deliver on, from the policy's channel enum. */
    public function supports(string $channel): bool;
}
