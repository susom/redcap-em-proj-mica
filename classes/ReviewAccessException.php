<?php

namespace Stanford\MICA;

/**
 * The caller may not do this.
 *
 * A distinct type because the handler treats it differently from every other failure: it becomes a
 * 403 with the message shown to the user, whereas an unexpected exception becomes a generic error
 * with the detail kept in the log. Getting that backwards either tells an unauthorised caller about
 * the system's internals, or tells an authorised one nothing about why they were refused.
 */
class ReviewAccessException extends \RuntimeException
{
}
