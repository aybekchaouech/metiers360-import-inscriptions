<?php

namespace App\Tests\Controller;

use App\Import\InscriptionImporter;
use App\Tests\Support\FakeFormApi;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/sessions/{id}/inscriptions, on the data of the kit (v1 then v2 imported).
 */
final class SessionInscriptionsControllerTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $container = self::getContainer();
        $this->resetDatabase($container->get(EntityManagerInterface::class));

        $api = $container->get(FakeFormApi::class);
        $importer = $container->get(InscriptionImporter::class);
        $api->useDataset('v1');
        $importer->import();
        $api->useDataset('v2');
        $importer->import();
    }

    /**
     * Belley has 49 active registrations for 40 places, but 3 people registered twice:
     * 46 people, so 6 on the waiting list (not 9).
     */
    public function testOverbookedSessionHasAWaitingList(): void
    {
        $body = $this->get('/api/sessions/ses_03/inscriptions');
        $summary = $body['summary'];

        self::assertSame('Belley — 24 novembre', $body['session']['label']);
        self::assertSame(40, $summary['capacity']);
        self::assertSame(49, $summary['registrations']['submitted']);
        self::assertSame(3, $summary['registrations']['same_session_duplicates']);
        self::assertSame(46, $summary['people_registered']);
        self::assertSame(40, $summary['confirmed']);
        self::assertSame(6, $summary['waiting_list']);
        self::assertSame(6, $summary['overbooked_by']);
        self::assertSame(0, $summary['remaining_places']);
    }

    public function testPlacesFollowTheRegistrationOrder(): void
    {
        $inscriptions = $this->get('/api/sessions/ses_03/inscriptions')['inscriptions'];

        $people = array_values(array_filter($inscriptions, static fn (array $i): bool => \in_array($i['place'], ['confirmed', 'waiting_list'], true)));
        $places = array_column($people, 'place');

        self::assertSame(array_fill(0, 40, 'confirmed'), \array_slice($places, 0, 40));
        self::assertSame(array_fill(0, 6, 'waiting_list'), \array_slice($places, 40));
        $dates = array_column($people, 'registered_at');
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates);
    }

    public function testDuplicateTakesNoPlaceAndPointsToTheKeptRegistration(): void
    {
        $inscriptions = $this->get('/api/sessions/ses_03/inscriptions')['inscriptions'];
        $byId = array_column($inscriptions, null, 'id');

        // Emma Mercier registered twice to Belley.
        self::assertSame('duplicate', $byId['sub_fy6dms9s36']['place']);
        self::assertSame('sub_msaei5w8k7', $byId['sub_fy6dms9s36']['duplicates']['duplicate_of']);
        self::assertNotSame('duplicate', $byId['sub_msaei5w8k7']['place']);
    }

    public function testCancelledAndRemovedAreListedWithoutAPlace(): void
    {
        $body = $this->get('/api/sessions/ses_01/inscriptions');
        $byId = array_column($body['inscriptions'], null, 'id');

        // Rayan Laurent disappeared from the API between v1 and v2.
        self::assertSame('removed', $byId['sub_5m3rfhnkfb']['status']);
        self::assertNull($byId['sub_5m3rfhnkfb']['place']);
        self::assertSame(1, $body['summary']['registrations']['removed_from_source']);
        self::assertSame(5, $body['summary']['registrations']['cancelled']);
    }

    public function testPersonRegisteredToTwoSessionsIsFlagged(): void
    {
        $byId = array_column($this->get('/api/sessions/ses_01/inscriptions')['inscriptions'], null, 'id');

        // Théo Robin: Bourg-en-Bresse and Oyonnax.
        self::assertContains('multiple_sessions', $byId['sub_tyb4hh5nvc']['duplicates']['flags']);
        self::assertSame(['sub_eezq45rehe'], $byId['sub_tyb4hh5nvc']['duplicates']['related']);
    }

    public function testAnomaliesAreExposed(): void
    {
        $byId = array_column($this->get('/api/sessions/ses_02/inscriptions')['inscriptions'], null, 'id');

        // Born on February 30th.
        self::assertNull($byId['sub_e9iwdau4pc']['birth_date']);
        self::assertSame(['birth_date_unreadable'], $byId['sub_e9iwdau4pc']['anomalies']);
    }

    public function testTellsWhenTheDataWasLastImported(): void
    {
        $freshness = $this->get('/api/sessions/ses_02/inscriptions')['data_freshness'];

        self::assertSame('success', $freshness['last_import_status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $freshness['last_successful_import_at']);
    }

    public function testUnknownSessionIsA404(): void
    {
        $this->browser->request('GET', '/api/sessions/ses_99/inscriptions');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('session_not_found', json_decode((string) $this->browser->getResponse()->getContent(), true)['error']);
    }

    public function testResponseIsUtf8Json(): void
    {
        $this->browser->request('GET', '/api/sessions/ses_01/inscriptions');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> */
        return json_decode((string) $this->browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
