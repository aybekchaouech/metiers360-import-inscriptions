<?php

namespace App\Import;

use App\Enum\InscriptionStatus;

/**
 * A submission after cleaning, ready to be written to the database.
 */
final class NormalizedSubmission
{
    /**
     * @param list<Anomaly>        $anomalies
     * @param array<string, mixed> $rawData   the submission exactly as received
     */
    public function __construct(
        public readonly string $externalId,
        public readonly InscriptionStatus $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?\DateTimeImmutable $birthDate,
        public readonly ?string $structureType,
        public readonly ?string $structureName,
        public readonly ?string $comment,
        public readonly SessionData $session,
        public readonly array $anomalies,
        public readonly array $rawData,
    ) {
    }

    /** @return list<string> */
    public function anomalyCodes(): array
    {
        return array_map(static fn (Anomaly $a): string => $a->value, $this->anomalies);
    }
}
