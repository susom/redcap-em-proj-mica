<?php

namespace Stanford\MICA;

require_once __DIR__ . "/TranscriptException.php";

/**
 * The only session identifier that is ever sent to a model.
 *
 * The handoff's counselor-request rule is "send only minimum necessary pseudonymous state" and "do
 * not intentionally send direct identifiers". A REDCap record id is a direct identifier in this
 * study - it is the key the study team joins on - so it never leaves the application. This does the
 * one-way mapping.
 *
 * `sha256(salt | project_id | record | session_type | instance)`, first 32 hex characters
 * (02-data-model.md §2). Deterministic, so the same session produces the same pseudo id on a
 * refinalize and across the counselor and scan paths; irreversible without the salt.
 */
class SessionPseudoId
{
    public const SALT_SETTING = 'session-pseudo-id-salt';

    /** 128 bits of the digest. Long enough that collisions are not a concern at study scale. */
    private const LENGTH = 32;

    /**
     * Generated once and never rotated. Regenerating it silently re-pseudonymises every
     * previously issued id, which breaks the link between a finding and the session it came from
     * for every record already scanned - so the salt is *asserted* to exist rather than
     * lazily created on first use. A missing salt is a setup step that was skipped, not a
     * condition to recover from mid-session.
     */
    private const MIN_SALT_BYTES = 32;

    /**
     * Instance is included, which 02-data-model.md §2 does not mention because it predates the
     * instruments becoming repeating (§3, note of 2026-08-17). Without it two sessions in one window
     * share a pseudo id, and a "pseudonymous *session* id" that names two sessions is not one.
     */
    public static function derive(
        string $salt,
        string $projectId,
        string $record,
        string $sessionType,
        int $instance
    ): string {
        self::assertSalt($salt);

        // "|" as separator with no escaping is safe here only because none of the four inputs can
        // contain it: project id and instance are integers, session_type is an enum, and REDCap
        // record ids cannot contain a pipe. Asserted rather than trusted.
        foreach (['project_id' => $projectId, 'record' => $record, 'session_type' => $sessionType] as $name => $part) {
            if ($part === '' || str_contains($part, '|')) {
                throw new TranscriptException(
                    "Cannot derive a session pseudo id: $name is empty or contains '|' ('$part'), "
                    . 'which would make two different sessions hash to the same value.'
                );
            }
        }

        $digest = hash('sha256', implode('|', [$salt, $projectId, $record, $sessionType, $instance]));

        return substr($digest, 0, self::LENGTH);
    }

    /** A fresh salt, for the one-time setup step. */
    public static function generateSalt(): string
    {
        return bin2hex(random_bytes(self::MIN_SALT_BYTES));
    }

    private static function assertSalt(string $salt): void
    {
        if (strlen($salt) < self::MIN_SALT_BYTES) {
            throw new TranscriptException(sprintf(
                'The session pseudo-id salt is missing or too short (%d chars; at least %d '
                . 'required). It is a one-time system setting (%s) - generate it once and never '
                . 'rotate it: changing it re-pseudonymises every id already issued and breaks the '
                . 'link between existing findings and the sessions they came from.',
                strlen($salt),
                self::MIN_SALT_BYTES,
                self::SALT_SETTING
            ));
        }
    }
}
