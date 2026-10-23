<?php

namespace App\Duplicate;

use App\Entity\Inscription;

/**
 * Finds people registered more than once.
 *
 * - Same person = same email (already lowercased) + same first name, ignoring case and accents.
 *   An email alone is not enough: siblings share "famille.xxx@" addresses.
 *   Without an email: same last name + first name + birth date (homonyms exist).
 * - Same email but a close first name ("aybek" / "aybec") or the same birth date: probable duplicate.
 *
 * Nothing is merged or deleted: inscriptions are only flagged, a human decides.
 * Only active registrations should be passed in (cancelled and removed ones do not take a seat).
 */
class DuplicateDetector
{
    /** Up to this many letters changed, two first names are considered a possible typo. */
    private const MAX_FIRST_NAME_DISTANCE = 2;

    /**
     * @param iterable<Inscription> $inscriptions
     */
    public function detect(iterable $inscriptions): DuplicateReport
    {
        /** @var array<string, list<Inscription>> $byPerson */
        $byPerson = [];
        /** @var array<string, list<Inscription>> $byEmail */
        $byEmail = [];

        foreach ($inscriptions as $inscription) {
            $person = $this->personKey($inscription);
            if (null !== $person) {
                $byPerson[$person][] = $inscription;
            }
            if (null !== $inscription->getEmail()) {
                $byEmail[$inscription->getEmail()][] = $inscription;
            }
        }

        $flags = [];
        $duplicateOf = [];
        $related = [];

        foreach ($byPerson as $registrations) {
            if (\count($registrations) < 2) {
                continue;
            }
            usort($registrations, static fn (Inscription $a, Inscription $b): int => $a->getSourceCreatedAt() <=> $b->getSourceCreatedAt());

            /** @var array<string, Inscription> $keptPerSession */
            $keptPerSession = [];
            foreach ($registrations as $registration) {
                $id = $registration->getExternalId();
                $session = $registration->getSession()->getExternalId();
                $related[$id] = $this->otherIds($registrations, $registration);

                if (isset($keptPerSession[$session])) {
                    $flags[$id][] = DuplicateFlag::SameSession;
                    $duplicateOf[$id] = $keptPerSession[$session]->getExternalId();
                } else {
                    $keptPerSession[$session] = $registration;
                }
            }
            if (\count($keptPerSession) > 1) {
                foreach ($registrations as $registration) {
                    $flags[$registration->getExternalId()][] = DuplicateFlag::MultipleSessions;
                }
            }
        }

        foreach ($byEmail as $sharingEmail) {
            foreach ($sharingEmail as $i => $a) {
                foreach (\array_slice($sharingEmail, $i + 1) as $b) {
                    if (!$this->isProbablySamePerson($a, $b)) {
                        continue;
                    }
                    foreach ([[$a, $b], [$b, $a]] as [$one, $other]) {
                        $flags[$one->getExternalId()][] = DuplicateFlag::Probable;
                        $related[$one->getExternalId()][] = $other->getExternalId();
                    }
                }
            }
        }

        $flags = array_map(static fn (array $f): array => array_values(array_unique($f, \SORT_REGULAR)), $flags);
        $related = array_map(static fn (array $r): array => array_values(array_unique($r)), $related);

        return new DuplicateReport($flags, $duplicateOf, $related);
    }

    /**
     * Two registrations sharing an email but with different first names: a typo or a twin?
     * Flagged when the first names are close or the birth dates are equal.
     */
    private function isProbablySamePerson(Inscription $a, Inscription $b): bool
    {
        $firstA = $this->normalizeName($a->getFirstName());
        $firstB = $this->normalizeName($b->getFirstName());
        if ($firstA === $firstB) {
            // Same person: already handled by the person key.
            return false;
        }

        return levenshtein($firstA, $firstB) <= self::MAX_FIRST_NAME_DISTANCE
            || (null !== $a->getBirthDate() && $a->getBirthDate() == $b->getBirthDate());
    }

    private function personKey(Inscription $inscription): ?string
    {
        $firstName = $this->normalizeName($inscription->getFirstName());
        if ('' === $firstName) {
            return null;
        }
        if (null !== $inscription->getEmail()) {
            return 'email:'.$inscription->getEmail().'|'.$firstName;
        }
        $lastName = $this->normalizeName($inscription->getLastName());
        if ('' === $lastName || null === $inscription->getBirthDate()) {
            return null;
        }

        return 'name:'.$lastName.'|'.$firstName.'|'.$inscription->getBirthDate()->format('Y-m-d');
    }

    /**
     * "Théo" → "theo", "Thi-Lan" → "thi lan".
     */
    private function normalizeName(string $name): string
    {
        $ascii = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()')?->transliterate($name);
        $ascii = false === $ascii || null === $ascii ? mb_strtolower($name) : $ascii;

        return trim((string) preg_replace('/[\s\-]+/', ' ', $ascii));
    }

    /**
     * @param list<Inscription> $registrations
     *
     * @return list<string>
     */
    private function otherIds(array $registrations, Inscription $self): array
    {
        return array_values(array_map(
            static fn (Inscription $i): string => $i->getExternalId(),
            array_filter($registrations, static fn (Inscription $i): bool => $i !== $self),
        ));
    }
}
