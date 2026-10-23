<?php

namespace App\Import;

use App\Entity\ImportRun;
use App\Entity\Inscription;
use App\Entity\Operation;
use App\Entity\Session;
use App\Enum\InscriptionStatus;
use App\FormApi\FormApiClient;
use App\FormApi\FormApiException;
use App\FormApi\FormApiPage;
use App\Repository\InscriptionRepository;
use App\Repository\OperationRepository;
use App\Repository\SessionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports every submission of the form API into the database.
 *
 * Safe to run again (cron): submissions are upserted on their API id, and unchanged ones
 * are left untouched. Each page is written in its own transaction. The run is recorded
 * in ImportRun and only counts as a success when every page was read and the number of
 * distinct submissions matches the total announced by the API.
 */
class InscriptionImporter
{
    /** @var array<string, Operation> */
    private array $operations = [];

    /** @var array<string, Session> */
    private array $sessions = [];

    public function __construct(
        private readonly FormApiClient $formApi,
        private readonly SubmissionNormalizer $normalizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly OperationRepository $operationRepository,
        private readonly SessionRepository $sessionRepository,
        private readonly InscriptionRepository $inscriptionRepository,
    ) {
    }

    /**
     * @param (callable(FormApiPage): void)|null $onPage called after each page, for progress output
     */
    public function import(?callable $onPage = null): ImportRun
    {
        $this->operations = [];
        $this->sessions = [];
        // Start from the database, not from entities a previous run (or another process) left
        // in memory with possibly outdated values.
        $this->entityManager->clear();

        $run = new ImportRun($this->now());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        /** @var array<string, true> $seen */
        $seen = [];

        try {
            for ($pageNumber = 1; ; ++$pageNumber) {
                $page = $this->formApi->fetchPage($pageNumber);

                if (null === $run->getTotalAnnounced()) {
                    $run->setTotalAnnounced($page->total);
                } elseif ($run->getTotalAnnounced() !== $page->total) {
                    // Submissions were added or removed while we were paginating: some may have shifted
                    // to a page we already read. Better to fail and let the next run start over.
                    throw new ImportIncompleteException(sprintf('The API total changed during the import (%d, then %d on page %d).', $run->getTotalAnnounced(), $page->total, $pageNumber));
                }

                if ($page->isEmpty()) {
                    break;
                }

                $this->entityManager->wrapInTransaction(function () use ($page, $run, &$seen): void {
                    $this->importPage($page, $run, $seen);
                });
                $run->incrementPagesRead();

                if (null !== $onPage) {
                    $onPage($page);
                }
                if ($pageNumber >= $page->totalPages) {
                    break;
                }
            }

            $imported = \count($seen) + $run->getRejected();
            if ($imported !== $run->getTotalAnnounced()) {
                throw new ImportIncompleteException(sprintf('%d submissions read, but the API announced %d.', $imported, $run->getTotalAnnounced()));
            }

            // Only now do we know the API was read in full: anything not seen has been removed from it.
            // Changed through the ORM (not a bulk UPDATE) so that entities in memory stay in sync.
            $notSeen = $this->inscriptionRepository->findNotSeenBy(array_values($this->operations), $run);
            foreach ($notSeen as $inscription) {
                $inscription->setStatus(InscriptionStatus::Removed);
            }
            $run->setRemoved(\count($notSeen));
            $run->succeed($this->now());
        } catch (FormApiException|ImportIncompleteException $e) {
            // Pages already written stay in the database: the upsert makes that harmless.
            $run->fail($this->now(), $e->getMessage());
        }

        $this->entityManager->flush();

        return $run;
    }

    /**
     * @param array<string, true> $seen ids already imported during this run
     */
    private function importPage(FormApiPage $page, ImportRun $run, array &$seen): void
    {
        $operation = $this->operation($page->formId, $page->formTitle);

        foreach ($page->submissions as $raw) {
            try {
                $submission = $this->normalizer->normalize($raw);
            } catch (InvalidSubmissionException) {
                $run->incrementRejected();
                continue;
            }

            // The same submission can appear on two pages if the list shifts: count it once.
            if (isset($seen[$submission->externalId])) {
                continue;
            }
            $seen[$submission->externalId] = true;

            $session = $this->session($operation, $submission->session);
            $this->upsert($submission, $session, $run);

            if ([] !== $submission->anomalies) {
                $run->incrementWithAnomalies();
            }
        }
    }

    private function upsert(NormalizedSubmission $submission, Session $session, ImportRun $run): void
    {
        $inscription = $this->inscriptionRepository->findOneBy(['externalId' => $submission->externalId]);

        if (null === $inscription) {
            $inscription = new Inscription($submission->externalId);
            $this->entityManager->persist($inscription);
            $this->apply($inscription, $submission, $session);
            $run->incrementCreated();
        } elseif ($this->hasChanged($inscription, $submission)) {
            $this->apply($inscription, $submission, $session);
            $run->incrementUpdated();
        } else {
            $run->incrementUnchanged();
        }

        $inscription->markSeenBy($run);
    }

    /**
     * The API bumps "updated_at" on every change: an older or equal date means nothing new.
     * A submission previously marked as removed that shows up again is always restored.
     */
    private function hasChanged(Inscription $inscription, NormalizedSubmission $submission): bool
    {
        return InscriptionStatus::Removed === $inscription->getStatus()
            || $submission->updatedAt > $inscription->getSourceUpdatedAt();
    }

    private function apply(Inscription $inscription, NormalizedSubmission $submission, Session $session): void
    {
        $inscription->setSession($session);
        $inscription->setStatus($submission->status);
        $inscription->setFirstName($submission->firstName);
        $inscription->setLastName($submission->lastName);
        $inscription->setEmail($submission->email);
        $inscription->setPhone($submission->phone);
        $inscription->setBirthDate($submission->birthDate);
        $inscription->setStructureType($submission->structureType);
        $inscription->setStructureName($submission->structureName);
        $inscription->setComment($submission->comment);
        $inscription->setAnomalies($submission->anomalyCodes());
        $inscription->setRawData($submission->rawData);
        $inscription->setSourceCreatedAt($submission->createdAt);
        $inscription->setSourceUpdatedAt($submission->updatedAt);
    }

    private function operation(string $externalId, string $title): Operation
    {
        if (!isset($this->operations[$externalId])) {
            $operation = $this->operationRepository->findOneBy(['externalId' => $externalId]);
            if (null === $operation) {
                $operation = new Operation($externalId, $title);
                $this->entityManager->persist($operation);
            }
            $operation->setTitle($title);
            $this->operations[$externalId] = $operation;
        }

        return $this->operations[$externalId];
    }

    /**
     * The API is the source of truth for session details: refreshed once per run.
     */
    private function session(Operation $operation, SessionData $data): Session
    {
        if (!isset($this->sessions[$data->externalId])) {
            $session = $this->sessionRepository->findOneBy(['externalId' => $data->externalId]);
            if (null === $session) {
                $session = new Session($operation, $data->externalId, $data->label, $data->date, $data->location, $data->capacity);
                $this->entityManager->persist($session);
            } else {
                $session->updateDetails($data->label, $data->date, $data->location, $data->capacity);
            }
            $this->sessions[$data->externalId] = $session;
        }

        return $this->sessions[$data->externalId];
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
