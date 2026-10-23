<?php

namespace App\Tests\FormApi;

use App\FormApi\FormApiClient;
use App\FormApi\FormApiException;
use App\Tests\Support\FakeFormApi;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Goes through the real "form_api.client" configuration (base URI, retries):
 * only the network is faked.
 */
final class FormApiClientTest extends KernelTestCase
{
    private FakeFormApi $api;
    private FormApiClient $client;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->api = self::getContainer()->get(FakeFormApi::class);
        $this->api->useDataset('v1');
        $this->client = self::getContainer()->get(FormApiClient::class);
    }

    public function testReadsAPage(): void
    {
        $page = $this->client->fetchPage(1);

        self::assertSame('frm_7Hq2xL', $page->formId);
        self::assertCount(25, $page->submissions);
        self::assertSame(293, $page->total);
        self::assertSame(12, $page->totalPages);
        self::assertFalse($page->isEmpty());
    }

    public function testPagePastTheEndIsEmpty(): void
    {
        self::assertTrue($this->client->fetchPage(13)->isEmpty());
    }

    /**
     * Page 5 of the mock API answers 500 on the first call.
     */
    public function testServerErrorIsRetried(): void
    {
        $this->api->failOnceWith(5, FakeFormApi::json(['error' => 'internal_error'], 500));

        $page = $this->client->fetchPage(5);

        self::assertCount(25, $page->submissions);
        self::assertSame(2, $this->api->callsTo(5));
    }

    /**
     * Page 8 of the mock API sometimes takes 20 s: the client gives up and tries again.
     */
    public function testTimeoutIsRetried(): void
    {
        $this->api->failOnceWith(8, new MockResponse('', ['error' => 'Idle timeout reached for "http://form-api:8090/submissions?page=8".']));

        $page = $this->client->fetchPage(8);

        self::assertCount(25, $page->submissions);
        self::assertSame(2, $this->api->callsTo(8));
    }

    public function testBadRequestIsNotRetried(): void
    {
        try {
            $this->client->fetchPage(0);
            self::fail('A 400 must raise an exception.');
        } catch (FormApiException $e) {
            self::assertStringContainsString('400', $e->getMessage());
        }
        self::assertSame(1, $this->api->badRequests(), 'A 400 means the request is wrong: retrying would change nothing.');
    }

    public function testGivesUpAfterThreeRetries(): void
    {
        $this->api->breakPage(3);

        try {
            $this->client->fetchPage(3);
            self::fail('A page that keeps failing must raise an exception.');
        } catch (FormApiException $e) {
            self::assertStringContainsString('Page 3', $e->getMessage());
        }
        self::assertSame(4, $this->api->callsTo(3), '1 call + 3 retries.');
    }

    public function testUnexpectedPayloadIsRejected(): void
    {
        $this->api->failOnceWith(1, FakeFormApi::json(['data' => 'not a list']));

        $this->expectException(FormApiException::class);
        $this->expectExceptionMessage('unexpected format');
        $this->client->fetchPage(1);
    }
}
