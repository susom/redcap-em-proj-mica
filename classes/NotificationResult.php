<?php

namespace Stanford\MICA;

/**
 * What one notification attempt did - including deciding not to.
 *
 * `skipped` is a first-class outcome rather than a silent `return`, because the two reasons a notice
 * is not sent are things somebody will need to explain later: the policy said not to, or it had
 * already gone out. A caller that cannot tell those apart from "sent" cannot report the truth on the
 * dashboard.
 *
 * `refused` is separate again: it means a gate stopped it. That is not a skip, it is the system
 * working, and it must be visible as such.
 */
class NotificationResult
{
    public const SENT    = 'sent';
    public const SKIPPED = 'skipped';
    public const REFUSED = 'refused';
    public const FAILED  = 'failed';

    private function __construct(
        public readonly string $outcome,
        public readonly string $notificationType,
        public readonly string $reason = '',
        public readonly int $recipientCount = 0,
        /** @var list<string> */
        public readonly array $channels = [],
        /** @var array<string,string> finding fields the caller should persist, if any */
        public readonly array $actionFields = [],
        public readonly ?int $logId = null
    ) {
    }

    /** @param list<string> $channels */
    public static function sent(
        string $type,
        int $recipientCount,
        array $channels,
        array $actionFields = [],
        ?int $logId = null
    ): self {
        return new self(self::SENT, $type, '', $recipientCount, $channels, $actionFields, $logId);
    }

    public static function skipped(string $type, string $reason): self
    {
        return new self(self::SKIPPED, $type, $reason);
    }

    public static function refused(string $type, string $reason): self
    {
        return new self(self::REFUSED, $type, $reason);
    }

    /**
     * A failure still carries the fields the caller should persist.
     *
     * A delivery that was attempted and failed has to leave a trace on the finding - otherwise the
     * form says nothing was ever tried, which is the one reading that is definitely wrong.
     *
     * @param array<string,string> $actionFields
     */
    public static function failed(
        string $type,
        string $reason,
        ?int $logId = null,
        array $actionFields = []
    ): self {
        return new self(self::FAILED, $type, $reason, 0, [], $actionFields, $logId);
    }

    public function wasSent(): bool
    {
        return $this->outcome === self::SENT;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'outcome'         => $this->outcome,
            'type'            => $this->notificationType,
            'reason'          => $this->reason,
            'recipient_count' => $this->recipientCount,
            'channels'        => $this->channels,
        ];
    }
}
