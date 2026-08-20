<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\NotificationChannelInterface;

/**
 * Records what would have been sent, so a test can read the actual bytes.
 *
 * Keeps the subject and body verbatim rather than a summary, because the guarantees being tested are
 * about their *contents* - that no pre-review body can lack its label, and that no subject can carry
 * a record id. A fake that stored only "one email to two people" would let both regress silently.
 */
final class FakeNotificationChannel implements NotificationChannelInterface
{
    /** @var list<array{channel:string,recipients:list<string>,subject:string,body:string}> */
    public array $sent = [];

    /** @var list<string> channels this transport claims to handle */
    public array $supported = ['secure_email', 'dashboard', 'secure_messaging'];

    public ?string $throwOn = null;

    public function send(string $channel, array $recipients, string $subject, string $body): void
    {
        if ($this->throwOn !== null) {
            throw new \RuntimeException($this->throwOn);
        }

        $this->sent[] = [
            'channel'    => $channel,
            'recipients' => $recipients,
            'subject'    => $subject,
            'body'       => $body,
        ];
    }

    public function supports(string $channel): bool
    {
        return in_array($channel, $this->supported, true);
    }

    /** @return array{channel:string,recipients:list<string>,subject:string,body:string} */
    public function last(): array
    {
        if ($this->sent === []) {
            throw new \RuntimeException('Nothing was sent.');
        }

        return $this->sent[count($this->sent) - 1];
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
