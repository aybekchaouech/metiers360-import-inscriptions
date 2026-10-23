<?php

namespace App\Report;

use App\Duplicate\DuplicateDetector;
use App\Duplicate\DuplicateFlag;
use App\Duplicate\DuplicateReport;
use App\Entity\Inscription;
use App\Entity\Session;
use App\Enum\InscriptionStatus;
use App\Repository\ImportRunRepository;
use App\Repository\InscriptionRepository;

/**
 * Builds the view of one session for the operations team: who is registered, and a summary
 * replacing the spreadsheet they maintain by hand today.
 *
 * Seats are given in registration order. A second registration of the same person to the same
 * session does not take a seat. Beyond the capacity, registrants are on the waiting list.
 */
class SessionInscriptionsReport
{
    private const ADULT_AGE = 18;

    public function __construct(
        private readonly InscriptionRepository $inscriptionRepository,
        private readonly ImportRunRepository $importRunRepository,
        private readonly DuplicateDetector $duplicateDetector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Session $session): array
    {
        $inscriptions = $this->inscriptionRepository->findBySessionInRegistrationOrder($session);
        // People registered to another session of the same operation must be visible too.
        $duplicates = $this->duplicateDetector->detect($this->inscriptionRepository->findActiveByOperation($session->getOperation()));

        $places = $this->assignPlaces($inscriptions, $duplicates, $session->getCapacity());

        return [
            'session' => [
                'id' => $session->getExternalId(),
                'label' => $session->getLabel(),
                'date' => $session->getDate()->format('Y-m-d'),
                'location' => $session->getLocation(),
                'capacity' => $session->getCapacity(),
                'operation' => [
                    'id' => $session->getOperation()->getExternalId(),
                    'title' => $session->getOperation()->getTitle(),
                ],
            ],
            'data_freshness' => $this->dataFreshness(),
            'summary' => $this->summary($session, $inscriptions, $places, $duplicates),
            'inscriptions' => array_map(
                fn (Inscription $i): array => $this->inscription($i, $session, $places[$i->getExternalId()] ?? null, $duplicates),
                $inscriptions,
            ),
        ];
    }

    /**
     * @param list<Inscription> $inscriptions in registration order
     *
     * @return array<string, Place> place of each active registration
     */
    private function assignPlaces(array $inscriptions, DuplicateReport $duplicates, int $capacity): array
    {
        $places = [];
        $seatsTaken = 0;
        foreach ($inscriptions as $inscription) {
            if (InscriptionStatus::Submitted !== $inscription->getStatus()) {
                continue;
            }
            if (null !== $duplicates->duplicateOf($inscription->getExternalId())) {
                $places[$inscription->getExternalId()] = Place::Duplicate;
                continue;
            }
            ++$seatsTaken;
            $places[$inscription->getExternalId()] = $seatsTaken <= $capacity ? Place::Confirmed : Place::WaitingList;
        }

        return $places;
    }

    /**
     * @param list<Inscription>    $inscriptions
     * @param array<string, Place> $places
     *
     * @return array<string, mixed>
     */
    private function summary(Session $session, array $inscriptions, array $places, DuplicateReport $duplicates): array
    {
        $statusCount = array_fill_keys(array_map(static fn (InscriptionStatus $s): string => $s->value, InscriptionStatus::cases()), 0);
        foreach ($inscriptions as $inscription) {
            ++$statusCount[$inscription->getStatus()->value];
        }

        // Everything below is about people holding a seat or waiting for one, duplicates excluded.
        $people = array_values(array_filter(
            $inscriptions,
            static fn (Inscription $i): bool => \in_array($places[$i->getExternalId()] ?? null, [Place::Confirmed, Place::WaitingList], true),
        ));
        $placeCount = static fn (Place $place): int => \count(array_filter($places, static fn (Place $p): bool => $p === $place));

        $byStructureType = [];
        $minors = 0;
        $unknownAge = 0;
        $withAnomalies = 0;
        $multipleSessions = 0;
        $probableDuplicates = 0;
        foreach ($people as $person) {
            $type = $person->getStructureType() ?? 'unknown';
            $byStructureType[$type] = ($byStructureType[$type] ?? 0) + 1;

            $age = $this->ageAt($person, $session);
            if (null === $age) {
                ++$unknownAge;
            } elseif ($age < self::ADULT_AGE) {
                ++$minors;
            }
            if ([] !== $person->getAnomalies()) {
                ++$withAnomalies;
            }
            $flags = $duplicates->flagsFor($person->getExternalId());
            if (\in_array(DuplicateFlag::MultipleSessions, $flags, true)) {
                ++$multipleSessions;
            }
            if (\in_array(DuplicateFlag::Probable, $flags, true)) {
                ++$probableDuplicates;
            }
        }
        arsort($byStructureType);

        $expected = \count($people);
        $capacity = $session->getCapacity();

        return [
            'capacity' => $capacity,
            'people_registered' => $expected,
            'confirmed' => $placeCount(Place::Confirmed),
            'waiting_list' => $placeCount(Place::WaitingList),
            'remaining_places' => max(0, $capacity - $expected),
            'overbooked_by' => max(0, $expected - $capacity),
            'fill_rate_percent' => $capacity > 0 ? (int) round(100 * $expected / $capacity) : null,
            'registrations' => [
                'submitted' => $statusCount[InscriptionStatus::Submitted->value],
                'cancelled' => $statusCount[InscriptionStatus::Cancelled->value],
                'removed_from_source' => $statusCount[InscriptionStatus::Removed->value],
                'same_session_duplicates' => $placeCount(Place::Duplicate),
            ],
            'by_structure_type' => $byStructureType,
            'minors' => $minors,
            'unknown_age' => $unknownAge,
            'to_check' => [
                'with_anomalies' => $withAnomalies,
                'probable_duplicates' => $probableDuplicates,
                'registered_to_several_sessions' => $multipleSessions,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inscription(Inscription $inscription, Session $session, ?Place $place, DuplicateReport $duplicates): array
    {
        $age = $this->ageAt($inscription, $session);

        return [
            'id' => $inscription->getExternalId(),
            'status' => $inscription->getStatus()->value,
            'place' => $place?->value,
            'registered_at' => $this->dateTime($inscription->getSourceCreatedAt()),
            'first_name' => $inscription->getFirstName(),
            'last_name' => $inscription->getLastName(),
            'email' => $inscription->getEmail(),
            'phone' => $inscription->getPhone(),
            'birth_date' => $inscription->getBirthDate()?->format('Y-m-d'),
            'age_at_session' => $age,
            'minor' => null === $age ? null : $age < self::ADULT_AGE,
            'structure' => [
                'type' => $inscription->getStructureType(),
                'name' => $inscription->getStructureName(),
            ],
            'comment' => $inscription->getComment(),
            'anomalies' => $inscription->getAnomalies(),
            'duplicates' => [
                'flags' => array_map(static fn (DuplicateFlag $f): string => $f->value, $duplicates->flagsFor($inscription->getExternalId())),
                'duplicate_of' => $duplicates->duplicateOf($inscription->getExternalId()),
                'related' => $duplicates->relatedTo($inscription->getExternalId()),
            ],
        ];
    }

    /**
     * Tells the team how recent the data is, and warns when the latest import failed.
     *
     * @return array<string, mixed>
     */
    private function dataFreshness(): array
    {
        $lastSuccess = $this->importRunRepository->findLastSuccessful();
        $last = $this->importRunRepository->findLast();

        return [
            'last_successful_import_at' => $this->dateTime($lastSuccess?->getFinishedAt()),
            'last_import_status' => $last?->getStatus()->value,
        ];
    }

    private function ageAt(Inscription $inscription, Session $session): ?int
    {
        return $inscription->getBirthDate()?->diff($session->getDate())->y;
    }

    private function dateTime(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
