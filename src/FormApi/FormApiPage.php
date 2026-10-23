<?php

namespace App\FormApi;

/**
 * One page of GET /submissions, as returned by the API.
 *
 * Submissions are kept as raw arrays: cleaning them is the importer's job, not the client's.
 */
final class FormApiPage
{
    /**
     * @param list<array<string, mixed>> $submissions
     */
    public function __construct(
        public readonly string $formId,
        public readonly string $formTitle,
        public readonly array $submissions,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages,
    ) {
    }

    /**
     * The API returns an empty list past the last page.
     */
    public function isEmpty(): bool
    {
        return [] === $this->submissions;
    }
}
