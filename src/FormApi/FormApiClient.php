<?php

namespace App\FormApi;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the external registration form API.
 *
 * Timeouts and retries are configured on the "form_api.client" scoped client
 * (config/packages/http_client.yaml): by the time an error reaches this class,
 * the retries are already exhausted.
 */
class FormApiClient
{
    public function __construct(
        private readonly HttpClientInterface $formApiClient,
    ) {
    }

    /**
     * @throws FormApiException when the page cannot be fetched or its payload is not the documented one
     */
    public function fetchPage(int $page): FormApiPage
    {
        try {
            $payload = $this->formApiClient
                ->request('GET', '/submissions', ['query' => ['page' => $page]])
                ->toArray();
        } catch (HttpClientException $e) {
            throw new FormApiException(sprintf('Page %d of the form API could not be fetched: %s', $page, $e->getMessage()), 0, $e);
        }

        return $this->buildPage($page, $payload);
    }

    /**
     * Checks only the envelope (form, data, meta); each submission is validated by the importer
     * so that one malformed submission does not block a whole page.
     *
     * @param array<mixed> $payload
     */
    private function buildPage(int $page, array $payload): FormApiPage
    {
        $form = $payload['form'] ?? null;
        $data = $payload['data'] ?? null;
        $meta = $payload['meta'] ?? null;

        if (!\is_array($form) || !\is_string($form['id'] ?? null) || !\is_string($form['title'] ?? null)) {
            throw $this->invalidPayload($page, 'missing "form.id" or "form.title"');
        }
        if (!\is_array($data) || !array_is_list($data)) {
            throw $this->invalidPayload($page, '"data" is not a list');
        }
        if (!\is_array($meta)) {
            throw $this->invalidPayload($page, 'missing "meta"');
        }
        foreach (['page', 'per_page', 'total', 'total_pages'] as $key) {
            if (!\is_int($meta[$key] ?? null)) {
                throw $this->invalidPayload($page, sprintf('"meta.%s" is not an integer', $key));
            }
        }

        $submissions = [];
        foreach ($data as $index => $submission) {
            if (!\is_array($submission)) {
                throw $this->invalidPayload($page, sprintf('"data[%d]" is not an object', $index));
            }
            /** @var array<string, mixed> $submission */
            $submissions[] = $submission;
        }

        return new FormApiPage(
            formId: $form['id'],
            formTitle: $form['title'],
            submissions: $submissions,
            page: $meta['page'],
            perPage: $meta['per_page'],
            total: $meta['total'],
            totalPages: $meta['total_pages'],
        );
    }

    private function invalidPayload(int $page, string $reason): FormApiException
    {
        return new FormApiException(sprintf('Page %d of the form API has an unexpected format: %s', $page, $reason));
    }
}
