<?php

namespace Stanford\MICA;

/**
 * Raised whenever a hash-pinned handoff artifact cannot be vouched for.
 *
 * Callers must treat this as fatal for the operation in hand: the participant path surfaces the
 * approved technical-fallback message, the scan path lands on `manual_review_required`. Never
 * continue with an unverified prompt or schema, and never fall back to a bundled default - the
 * point of pinning is that a changed prompt is a change to the intervention.
 */
class ArtifactIntegrityException extends \RuntimeException
{
}
