<?php

namespace App\Tests\Import;

use App\Entity\ImportRun;
use App\Entity\Inscription;
use App\Enum\ImportRunStatus;
use App\Enum\InscriptionStatus;
use App\Import\InscriptionImporter;
use App\Tests\Support\FakeFormApi;
use App\Tests\Support\GetsServices;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The riskiest part: real importer, real database (app_test), only the network is faked.
 * Scenario of the kit: the API serves v1 (293 submissions), then v2 (303).
 */
final class InscriptionImporterTest extends KernelTestCase
{
    use GetsServices;
    use ResetsDatabase;

    private FakeFormApi $api;
    private InscriptionImporter $importer;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->api = self::service(FakeFormApi::class);
        $this->importer = self::service(InscriptionImporter::class);
        $this->entityManager = self::service(EntityManagerInterface::class);
        $this->resetDatabase($this->entityManager);
    }

    public function testFirstImportCreatesEverySubmission(): void
    {
        $this->api->useDataset('v1');
        // The two traps of the mock API: an error 500 on page 5 and a timeout on page 8.
        $this->api->failOnceWith(5, FakeFormApi::json(['error' => 'internal_error'], 500));
        $this->api->failOnceWith(8, new MockResponse('', ['error' => 'Idle timeout reached']));

        $run = $this->importer->import();

        self::assertSame(ImportRunStatus::Success, $run->getStatus(), (string) $run->getError());
        self::assertSame(12, $run->getPagesRead());
        self::assertSame(293, $run->getCreated());
        self::assertSame(0, $run->getRejected());
        self::assertSame(17, $run->getWithAnomalies());
        self::assertSame(293, $this->countInscriptions());
        self::assertSame(4, $this->countRows('session'));
    }

    public function testRunningTwiceCreatesNoDuplicate(): void
    {
        $this->api->useDataset('v1');
        $this->importer->import();

        $second = $this->importer->import();

        self::assertSame(ImportRunStatus::Success, $second->getStatus());
        self::assertSame(0, $second->getCreated());
        self::assertSame(0, $second->getUpdated());
        self::assertSame(293, $second->getUnchanged());
        self::assertSame(293, $this->countInscriptions());
    }

    public function testSecondImportFollowsTheChangesOfTheApi(): void
    {
        $this->api->useDataset('v1');
        $this->importer->import();

        $this->api->useDataset('v2');
        $run = $this->importer->import();

        self::assertSame(ImportRunStatus::Success, $run->getStatus(), (string) $run->getError());
        self::assertSame(12, $run->getCreated());
        // 6 cancellations + 3 session changes + 2 corrections.
        self::assertSame(11, $run->getUpdated());
        self::assertSame(280, $run->getUnchanged());
        self::assertSame(2, $run->getRemoved());

        $this->entityManager->clear();
        self::assertSame(InscriptionStatus::Cancelled, $this->inscription('sub_nejp29spv8')->getStatus());
        self::assertSame('ses_03', $this->inscription('sub_haczwpup6e')->getSession()->getExternalId());
        self::assertSame('Simon', $this->inscription('sub_rxpfpbs4ws')->getLastName());
        self::assertSame('antoine.diallo@example.com', $this->inscription('sub_rcg2jc9huq')->getEmail());
        self::assertSame(InscriptionStatus::Removed, $this->inscription('sub_5m3rfhnkfb')->getStatus());
        self::assertSame(InscriptionStatus::Removed, $this->inscription('sub_prefs26msk')->getStatus());
    }

    /**
     * The key safety rule: if a page cannot be read, nothing may be marked as removed,
     * otherwise its 25 registrations would disappear from the counts.
     */
    public function testFailedImportMarksNothingAsRemoved(): void
    {
        $this->api->useDataset('v1');
        $this->importer->import();

        $this->api->useDataset('v2');
        $this->api->breakPage(5);
        $run = $this->importer->import();

        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertStringContainsString('Page 5', (string) $run->getError());
        self::assertSame(4, $run->getPagesRead());
        self::assertSame(0, $run->getRemoved());
        self::assertSame(0, $this->countInscriptions(InscriptionStatus::Removed));
    }

    public function testRemovedInscriptionIsRestoredWhenItComesBack(): void
    {
        $this->api->useDataset('v1');
        $this->importer->import();
        $this->api->useDataset('v2');
        $this->importer->import();

        $this->api->useDataset('v1');
        $this->importer->import();

        $this->entityManager->clear();
        self::assertSame(InscriptionStatus::Submitted, $this->inscription('sub_5m3rfhnkfb')->getStatus());
    }

    public function testImportFailsWhenTheTotalChangesWhilePaginating(): void
    {
        $this->api->useDataset('v1');
        // Someone registered while we were reading: the list shifted, a submission may have been missed.
        $this->api->announceTotalOnPage(3, 294);

        $run = $this->importer->import();

        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertStringContainsString('total changed', (string) $run->getError());
        self::assertSame(0, $run->getRemoved());
    }

    public function testImportFailsWhenFewerSubmissionsThanAnnounced(): void
    {
        $this->api->useDataset('v1');
        foreach (range(1, 12) as $page) {
            $this->api->announceTotalOnPage($page, 300);
        }

        $run = $this->importer->import();

        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertStringContainsString('293 submissions read, but the API announced 300', (string) $run->getError());
    }

    /**
     * A comment in the data asks the import tool to skip these registrations: it is ignored.
     */
    public function testCharmillesRegistrationsAreImported(): void
    {
        $this->api->useDataset('v1');
        $this->importer->import();

        $count = $this->entityManager->getRepository(Inscription::class)->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.email LIKE :domain')
            ->setParameter('domain', '%@lycee-les-charmilles.test')
            ->getQuery()
            ->getSingleScalarResult();

        self::assertSame(8, (int) $count);
    }

    /**
     * A submission that cannot be imported is still a real person: it is kept with the reason,
     * so that the operations team can find and contact them.
     */
    public function testRejectedSubmissionsAreKeptWithTheirReason(): void
    {
        $this->api->useDataset('v1');
        $this->api->addSubmission(['id' => 'sub_no_session', 'status' => 'submitted', 'created_at' => '2026-10-20T10:00:00Z', 'answers' => ['first_name' => 'Nora', 'email' => 'nora@example.com']]);
        $this->api->addSubmission(['status' => 'submitted', 'answers' => ['first_name' => 'Sans', 'last_name' => 'Identifiant']]);

        $run = $this->importer->import();

        // Rejected submissions count in the completeness check: the run is complete.
        self::assertSame(ImportRunStatus::Success, $run->getStatus(), (string) $run->getError());
        self::assertSame(293, $run->getCreated());
        self::assertSame(2, $run->getRejected());

        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(ImportRun::class)->find($run->getId());
        self::assertNotNull($saved);
        $rejected = $saved->getRejectedSubmissions();
        self::assertCount(2, $rejected);
        self::assertStringContainsString('no usable session', $rejected[0]['reason']);
        self::assertSame('nora@example.com', $rejected[0]['submission']['answers']['email'], 'What the API sent must be kept to contact the person.');
        self::assertStringContainsString('without "id"', $rejected[1]['reason']);
    }

    public function testEveryRunIsRecorded(): void
    {
        $this->api->useDataset('v1');
        $this->importer->import();
        $this->api->breakPage(1);
        $this->importer->import();

        $runs = $this->entityManager->getRepository(ImportRun::class)->findBy([], ['id' => 'ASC']);

        self::assertCount(2, $runs);
        self::assertSame(ImportRunStatus::Success, $runs[0]->getStatus());
        self::assertSame(ImportRunStatus::Failed, $runs[1]->getStatus());
        self::assertNotNull($runs[1]->getFinishedAt());
    }

    private function inscription(string $externalId): Inscription
    {
        $inscription = $this->entityManager->getRepository(Inscription::class)->findOneBy(['externalId' => $externalId]);
        self::assertNotNull($inscription, $externalId.' should be in the database.');

        return $inscription;
    }

    private function countInscriptions(?InscriptionStatus $status = null): int
    {
        return $this->entityManager->getRepository(Inscription::class)->count(null === $status ? [] : ['status' => $status]);
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
