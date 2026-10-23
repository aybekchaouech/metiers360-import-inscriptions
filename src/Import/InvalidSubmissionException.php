<?php

namespace App\Import;

/**
 * The submission cannot be imported at all: without an id there is no idempotency key,
 * without a session it cannot be attached to anything. Every other problem is an Anomaly.
 */
final class InvalidSubmissionException extends \RuntimeException
{
}
