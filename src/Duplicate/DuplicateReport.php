<?php

namespace App\Duplicate;

/**
 * Result of DuplicateDetector, keyed by the inscriptions' API ids.
 */
final class DuplicateReport
{
    /**
     * @param array<string, list<DuplicateFlag>> $flags       flags per inscription
     * @param array<string, string>              $duplicateOf inscription => the registration kept for that person and session
     * @param array<string, list<string>>        $related     inscription => other inscriptions involved
     */
    public function __construct(
        private readonly array $flags,
        private readonly array $duplicateOf,
        private readonly array $related,
    ) {
    }

    /** @return list<DuplicateFlag> */
    public function flagsFor(string $externalId): array
    {
        return $this->flags[$externalId] ?? [];
    }

    /**
     * The registration kept when this one is a second registration of the same person to the same session.
     */
    public function duplicateOf(string $externalId): ?string
    {
        return $this->duplicateOf[$externalId] ?? null;
    }

    /** @return list<string> */
    public function relatedTo(string $externalId): array
    {
        return $this->related[$externalId] ?? [];
    }

    /**
     * Number of inscriptions flagged with this flag.
     */
    public function count(DuplicateFlag $flag): int
    {
        return \count(array_filter($this->flags, static fn (array $flags): bool => \in_array($flag, $flags, true)));
    }
}
