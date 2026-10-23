<?php

namespace App\Import;

/**
 * Session details as repeated inside each submission ("answers.session").
 */
final class SessionData
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $label,
        public readonly \DateTimeImmutable $date,
        public readonly string $location,
        public readonly int $capacity,
    ) {
    }
}
