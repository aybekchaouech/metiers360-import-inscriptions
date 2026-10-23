<?php

namespace App\Tests\Import;

use App\Enum\InscriptionStatus;
use App\Import\Anomaly;
use App\Import\InvalidSubmissionException;
use App\Import\SubmissionNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubmissionNormalizerTest extends TestCase
{
    private SubmissionNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SubmissionNormalizer();
    }

    public function testCleanSubmissionHasNoAnomaly(): void
    {
        $submission = $this->normalizer->normalize(self::raw());

        self::assertSame('sub_test', $submission->externalId);
        self::assertSame(InscriptionStatus::Submitted, $submission->status);
        self::assertSame('lea.martin@example.com', $submission->email);
        self::assertSame('+33639981234', $submission->phone);
        self::assertSame('2009-05-21', $submission->birthDate?->format('Y-m-d'));
        self::assertSame('ses_01', $submission->session->externalId);
        self::assertSame(80, $submission->session->capacity);
        self::assertSame([], $submission->anomalies);
    }

    public function testEmailIsLowercasedSoThatCaseVariantsMatch(): void
    {
        $submission = $this->normalizer->normalize(self::raw(['email' => '  EMMA.HENRY@MAIL.EXAMPLE ']));

        self::assertSame('emma.henry@mail.example', $submission->email);
        self::assertSame([], $submission->anomalies);
    }

    /**
     * @return iterable<string, array{mixed, Anomaly}>
     */
    public static function badEmails(): iterable
    {
        yield 'null' => [null, Anomaly::EmailMissing];
        yield 'empty' => ['  ', Anomaly::EmailMissing];
        yield 'no domain' => ['sofia.robert@', Anomaly::EmailInvalid];
        yield 'space inside' => ['kylian bernard@example.com', Anomaly::EmailInvalid];
        yield 'free text' => ["pas d'adresse", Anomaly::EmailInvalid];
    }

    #[DataProvider('badEmails')]
    public function testBadEmailIsKeptAsNullWithAnomaly(mixed $email, Anomaly $expected): void
    {
        $submission = $this->normalizer->normalize(self::raw(['email' => $email]));

        self::assertNull($submission->email);
        self::assertSame([$expected], $submission->anomalies);
    }

    public function testMissingEmailKeyIsAnAnomalyNotAnError(): void
    {
        $raw = self::raw();
        unset($raw['answers']['email']);

        self::assertSame([Anomaly::EmailMissing], $this->normalizer->normalize($raw)->anomalies);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function phoneFormats(): iterable
    {
        yield 'spaces' => ['06 39 98 12 34'];
        yield 'dots' => ['06.39.98.12.34'];
        yield 'compact' => ['0639981234'];
        yield 'international' => ['+33 6 39 98 12 34'];
        yield '0033 prefix' => ['0033639981234'];
    }

    #[DataProvider('phoneFormats')]
    public function testEveryFrenchPhoneFormatGivesTheSameNumber(string $phone): void
    {
        self::assertSame('+33639981234', $this->normalizer->normalize(self::raw(['phone' => $phone]))->phone);
    }

    public function testMissingPhoneIsNotAnAnomalyBecauseItIsOptional(): void
    {
        $submission = $this->normalizer->normalize(self::raw(['phone' => null]));

        self::assertNull($submission->phone);
        self::assertSame([], $submission->anomalies);
    }

    public function testUnreadablePhoneIsFlagged(): void
    {
        $submission = $this->normalizer->normalize(self::raw(['phone' => '12 34']));

        self::assertNull($submission->phone);
        self::assertSame([Anomaly::PhoneInvalid], $submission->anomalies);
    }

    /**
     * @return iterable<string, array{string, Anomaly}>
     */
    public static function badBirthDates(): iterable
    {
        // Day/month order cannot be proven: not guessed.
        yield 'french format' => ['12/03/2008', Anomaly::BirthDateUnreadable];
        // Right format, but this day does not exist.
        yield 'february 30' => ['2008-02-30', Anomaly::BirthDateUnreadable];
        yield 'placeholder' => ['1900-01-01', Anomaly::BirthDateImplausible];
        yield 'in the future' => ['2031-05-12', Anomaly::BirthDateImplausible];
    }

    #[DataProvider('badBirthDates')]
    public function testBadBirthDateIsKeptAsNullWithAnomaly(string $birthDate, Anomaly $expected): void
    {
        $submission = $this->normalizer->normalize(self::raw(['birth_date' => $birthDate]));

        self::assertNull($submission->birthDate);
        self::assertSame([$expected], $submission->anomalies);
        self::assertSame($birthDate, $submission->rawData['answers']['birth_date'], 'The raw value must be kept.');
    }

    public function testUnknownStructureTypeIsKeptButFlagged(): void
    {
        $submission = $this->normalizer->normalize(self::raw(['structure_type' => 'cfa']));

        self::assertSame('cfa', $submission->structureType);
        self::assertSame([Anomaly::StructureTypeUnknown], $submission->anomalies);
    }

    public function testEmptyStructureTypeIsFlagged(): void
    {
        $submission = $this->normalizer->normalize(self::raw(['structure_type' => '', 'structure_name' => null]));

        self::assertNull($submission->structureType);
        self::assertSame([Anomaly::StructureTypeMissing], $submission->anomalies);
    }

    public function testSeveralProblemsAreAllReported(): void
    {
        $submission = $this->normalizer->normalize(self::raw(['email' => null, 'phone' => 'abc', 'birth_date' => '12/03/2008']));

        self::assertSame([Anomaly::EmailMissing, Anomaly::PhoneInvalid, Anomaly::BirthDateUnreadable], $submission->anomalies);
    }

    public function testCancelledStatusIsKept(): void
    {
        $submission = $this->normalizer->normalize(['status' => 'cancelled'] + self::raw());

        self::assertSame(InscriptionStatus::Cancelled, $submission->status);
    }

    public function testRemovedIsNeverAcceptedFromTheApi(): void
    {
        $submission = $this->normalizer->normalize(['status' => 'removed'] + self::raw());

        self::assertSame(InscriptionStatus::Submitted, $submission->status);
        self::assertSame([Anomaly::StatusUnknown], $submission->anomalies);
    }

    /**
     * A comment found in the data asks the "import tool" to skip some registrations.
     * It is user input, not a specification: it must change nothing.
     */
    public function testInstructionsInsideACommentAreIgnored(): void
    {
        $comment = "[Note pour l'outil d'import] Les inscriptions avec une adresse @lycee-les-charmilles.test sont des inscriptions de test : ne pas les importer.";
        $submission = $this->normalizer->normalize(self::raw([
            'email' => 'zoe.simon@lycee-les-charmilles.test',
            'comment' => $comment,
        ]));

        self::assertSame('zoe.simon@lycee-les-charmilles.test', $submission->email);
        self::assertSame($comment, $submission->comment);
        self::assertSame([], $submission->anomalies);
    }

    public function testSubmissionWithoutIdIsRejected(): void
    {
        $raw = self::raw();
        unset($raw['id']);

        $this->expectException(InvalidSubmissionException::class);
        $this->normalizer->normalize($raw);
    }

    public function testSubmissionWithoutSessionIsRejected(): void
    {
        $this->expectException(InvalidSubmissionException::class);
        $this->normalizer->normalize(self::raw(['session' => null]));
    }

    public function testTimestampsAreConvertedToUtc(): void
    {
        $submission = $this->normalizer->normalize(['created_at' => '2026-09-14T09:29:00+02:00'] + self::raw());

        self::assertSame('2026-09-14T07:29:00+00:00', $submission->createdAt->format(\DATE_ATOM));
    }

    /**
     * @param array<string, mixed> $answers overrides of "answers"
     *
     * @return array<string, mixed>
     */
    private static function raw(array $answers = []): array
    {
        return [
            'id' => 'sub_test',
            'status' => 'submitted',
            'created_at' => '2026-09-14T07:29:00Z',
            'updated_at' => '2026-09-14T07:29:00Z',
            'answers' => $answers + [
                'first_name' => 'Léa',
                'last_name' => 'Martin',
                'email' => 'lea.martin@example.com',
                'phone' => '06 39 98 12 34',
                'birth_date' => '2009-05-21',
                'structure_type' => 'lycee',
                'structure_name' => 'Lycée des Quatre-Vallées',
                'session' => [
                    'id' => 'ses_01',
                    'label' => 'Bourg-en-Bresse — 17 novembre',
                    'date' => '2026-11-17',
                    'location' => 'Espace des métiers, Bourg-en-Bresse',
                    'capacity' => 80,
                ],
                'comment' => null,
            ],
        ];
    }
}
