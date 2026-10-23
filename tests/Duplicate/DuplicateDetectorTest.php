<?php

namespace App\Tests\Duplicate;

use App\Duplicate\DuplicateDetector;
use App\Duplicate\DuplicateFlag;
use App\Entity\Inscription;
use App\Entity\Operation;
use App\Entity\Session;
use PHPUnit\Framework\TestCase;

final class DuplicateDetectorTest extends TestCase
{
    private DuplicateDetector $detector;
    private Session $bourg;
    private Session $oyonnax;

    protected function setUp(): void
    {
        $this->detector = new DuplicateDetector();
        $operation = new Operation('frm_test', 'Test');
        $this->bourg = new Session($operation, 'ses_01', 'Bourg', new \DateTimeImmutable('2026-11-17'), 'Bourg', 80);
        $this->oyonnax = new Session($operation, 'ses_02', 'Oyonnax', new \DateTimeImmutable('2026-11-18'), 'Oyonnax', 80);
    }

    public function testSamePersonTwiceInTheSameSessionKeepsTheEarliestRegistration(): void
    {
        $first = $this->inscription('a', 'Emma', 'emma.henry@mail.example', '2009-05-24', '2026-09-18');
        $second = $this->inscription('b', 'Emma', 'emma.henry@mail.example', '2009-05-24', '2026-09-22');

        // Order of the input must not matter: the earliest registration is kept.
        $report = $this->detector->detect([$second, $first]);

        self::assertSame([], $report->flagsFor('a'));
        self::assertSame([DuplicateFlag::SameSession], $report->flagsFor('b'));
        self::assertSame('a', $report->duplicateOf('b'));
        self::assertNull($report->duplicateOf('a'));
    }

    public function testSamePersonInTwoSessionsIsFlaggedButNotADuplicate(): void
    {
        $report = $this->detector->detect([
            $this->inscription('a', 'Théo', 'theo.robin@example.test', '2009-10-02', '2026-09-15', $this->bourg),
            $this->inscription('b', 'Théo', 'theo.robin@example.test', '2009-10-02', '2026-10-05', $this->oyonnax),
        ]);

        self::assertSame([DuplicateFlag::MultipleSessions], $report->flagsFor('a'));
        self::assertSame([DuplicateFlag::MultipleSessions], $report->flagsFor('b'));
        self::assertNull($report->duplicateOf('b'));
        self::assertSame(['a'], $report->relatedTo('b'));
    }

    /**
     * "famille.girard@…" is the parents' address, used for two children.
     */
    public function testSiblingsSharingAnEmailAreNotDuplicates(): void
    {
        $report = $this->detector->detect([
            $this->inscription('a', 'Manon', 'famille.girard@example.org', '2010-03-14', '2026-09-18'),
            $this->inscription('b', 'Hugo', 'famille.girard@example.org', '2011-06-27', '2026-09-18'),
        ]);

        self::assertSame([], $report->flagsFor('a'));
        self::assertSame([], $report->flagsFor('b'));
    }

    /**
     * Counter-example found while designing the rule: a typo in the first name must not turn
     * one person into two siblings.
     */
    public function testTypoInFirstNameIsAProbableDuplicate(): void
    {
        $report = $this->detector->detect([
            $this->inscription('a', 'Aybek', 'aybek@gmail.com', '2000-01-01', '2026-09-01'),
            $this->inscription('b', 'Aybec', 'aybek@gmail.com', '2000-01-01', '2026-09-02'),
        ]);

        self::assertSame([DuplicateFlag::Probable], $report->flagsFor('a'));
        self::assertSame([DuplicateFlag::Probable], $report->flagsFor('b'));
        self::assertNull($report->duplicateOf('b'), 'A probable duplicate is never counted as a sure one.');
    }

    public function testTwinsAreFlaggedForAHumanToCheck(): void
    {
        $report = $this->detector->detect([
            $this->inscription('a', 'Léa', 'famille@example.fr', '2010-05-05', '2026-09-01'),
            $this->inscription('b', 'Hugo', 'famille@example.fr', '2010-05-05', '2026-09-01'),
        ]);

        self::assertSame([DuplicateFlag::Probable], $report->flagsFor('a'));
        self::assertNull($report->duplicateOf('b'));
    }

    public function testFirstNamesAreComparedWithoutCaseAccentsOrHyphens(): void
    {
        $report = $this->detector->detect([
            $this->inscription('a', 'Thi-Lan', 'tl@example.fr', '2009-11-02', '2026-09-01'),
            $this->inscription('b', 'thi lan', 'tl@example.fr', '2009-11-02', '2026-09-02'),
            $this->inscription('c', 'Theo', 'theo@example.fr', '2009-01-01', '2026-09-01'),
            $this->inscription('d', 'Théo', 'theo@example.fr', '2009-01-01', '2026-09-02'),
        ]);

        self::assertSame('a', $report->duplicateOf('b'));
        self::assertSame('c', $report->duplicateOf('d'));
    }

    public function testWithoutEmailNameAndBirthDateIdentifyThePerson(): void
    {
        $report = $this->detector->detect([
            $this->inscription('a', 'Sami', null, '2008-03-03', '2026-09-01'),
            $this->inscription('b', 'Sami', null, '2008-03-03', '2026-09-02'),
        ]);

        self::assertSame('a', $report->duplicateOf('b'));
    }

    public function testHomonymsWithDifferentEmailsAreDifferentPeople(): void
    {
        $report = $this->detector->detect([
            $this->inscription('a', 'Kevin', 'famille.nguyen@example.org', '2011-01-19', '2026-10-01'),
            $this->inscription('b', 'Kevin', 'kevin.nguyen@lycee.test', '2008-07-21', '2026-10-13'),
        ]);

        self::assertSame([], $report->flagsFor('a'));
        self::assertSame([], $report->flagsFor('b'));
    }

    public function testCountsFlags(): void
    {
        $report = $this->detector->detect([
            $this->inscription('a', 'Emma', 'emma@example.fr', '2009-05-24', '2026-09-18'),
            $this->inscription('b', 'Emma', 'emma@example.fr', '2009-05-24', '2026-09-22'),
            $this->inscription('c', 'Louise', 'louise@example.fr', '2005-11-14', '2026-09-14'),
        ]);

        self::assertSame(1, $report->count(DuplicateFlag::SameSession));
        self::assertSame(0, $report->count(DuplicateFlag::Probable));
    }

    private function inscription(string $id, string $firstName, ?string $email, string $birthDate, string $registeredAt, ?Session $session = null): Inscription
    {
        $inscription = new Inscription($id);
        $inscription->setSession($session ?? $this->bourg);
        $inscription->setFirstName($firstName);
        $inscription->setLastName('Test');
        $inscription->setEmail($email);
        $inscription->setBirthDate(new \DateTimeImmutable($birthDate));
        $inscription->setSourceCreatedAt(new \DateTimeImmutable($registeredAt));

        return $inscription;
    }
}
