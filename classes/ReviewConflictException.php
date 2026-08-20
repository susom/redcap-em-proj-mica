<?php

namespace Stanford\MICA;

/**
 * Somebody else saved this finding first.
 *
 * Carries the current lock version so the handler can return it with the 409: the SPA needs to know
 * what to reload to, and a conflict response without it forces a second round trip at the exact
 * moment the reviewer is already annoyed.
 *
 * A distinct type because the client's response is distinct - reload and re-apply, not "try again"
 * and not "you cannot do this".
 */
class ReviewConflictException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $currentVersion)
    {
        parent::__construct($message);
    }
}
