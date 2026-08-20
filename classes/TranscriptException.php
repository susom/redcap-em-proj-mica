<?php

namespace Stanford\MICA;

/**
 * A transcript could not be finalized.
 *
 * Every path that raises this is one where the alternative would be a *quietly incomplete*
 * transcript - a trimmed message, a dropped row, a session with nothing in it - and an incomplete
 * transcript is worse than a failed finalize. The post-session scan is the study's only safety net
 * (01-architecture.md); a scan of 90% of a conversation that reports "no supported concern" is a
 * negative screen nobody asked for.
 *
 * So this is thrown, surfaced to staff, and nothing is enqueued. Never caught and trimmed.
 */
class TranscriptException extends \RuntimeException
{
}
