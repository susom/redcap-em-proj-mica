<?php

namespace Stanford\MICA;

/**
 * The entity store is not usable: the redcap_entity dependency is missing or disabled, a table
 * could not be built, or an index migration failed.
 *
 * Always a deployment/configuration fault, never a participant-visible one and never retriable
 * against the model. Callers surface it to staff (or the technical fallback on the participant
 * path) and stop - a half-built schema must not be scanned or written to.
 */
class EntitySchemaException extends \RuntimeException
{
}
