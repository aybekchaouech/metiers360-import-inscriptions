<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for the network in the test environment ("mock_response_factory" in
 * config/packages/http_client.yaml). Everything above it is the real code: scoped client,
 * retries, FormApiClient, importer.
 *
 * It serves the kit's data files the way the mock API does (25 per page) and can be told
 * to fail on given pages.
 */
final class FakeFormApi
{
    public const PER_PAGE = 25;

    /** @var list<array<string, mixed>> */
    private array $submissions = [];

    /** @var array<int, list<MockResponse>> responses to serve before the normal one, per page */
    private array $failuresBefore = [];

    /** @var array<int, true> pages that never answer properly */
    private array $brokenPages = [];

    /** @var array<int, int> total to announce on a given page instead of the real one */
    private array $totalOverrides = [];

    /** @var array<int, int> */
    private array $callsPerPage = [];

    private int $badRequests = 0;

    /**
     * Loads "mock-api/data/submissions-v1.json" or "-v2.json".
     */
    public function useDataset(string $version): void
    {
        $file = \dirname(__DIR__, 2).'/mock-api/data/submissions-'.$version.'.json';
        /** @var array{submissions: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        $this->submissions = $data['submissions'];
    }

    public function failOnceWith(int $page, MockResponse $response): void
    {
        $this->failuresBefore[$page][] = $response;
    }

    public function breakPage(int $page): void
    {
        $this->brokenPages[$page] = true;
    }

    public function announceTotalOnPage(int $page, int $total): void
    {
        $this->totalOverrides[$page] = $total;
    }

    public function callsTo(int $page): int
    {
        return $this->callsPerPage[$page] ?? 0;
    }

    /**
     * Requests answered with a 400 (invalid "page" parameter).
     */
    public function badRequests(): int
    {
        return $this->badRequests;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $raw = $query['page'] ?? '1';
        if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1) {
            ++$this->badRequests;

            return self::json(['error' => 'invalid_parameter', 'message' => 'page must be a positive integer'], 400);
        }
        $page = (int) $raw;
        $this->callsPerPage[$page] = ($this->callsPerPage[$page] ?? 0) + 1;

        if ([] !== ($this->failuresBefore[$page] ?? [])) {
            return array_shift($this->failuresBefore[$page]);
        }
        if (isset($this->brokenPages[$page])) {
            return self::json(['error' => 'internal_error', 'message' => 'An unexpected error occurred.'], 500);
        }

        $total = \count($this->submissions);

        return self::json([
            'form' => ['id' => 'frm_7Hq2xL', 'title' => 'Découvrir les métiers en réalité virtuelle — Ain, novembre 2026'],
            'data' => \array_slice($this->submissions, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'meta' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $this->totalOverrides[$page] ?? $total,
                'total_pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
