<?php

namespace App\Import;

use App\Enum\InscriptionStatus;

/**
 * Cleans one raw submission of the form API.
 *
 * Rule of thumb: never reject a registration because of a typo. A field that cannot be trusted
 * becomes null and an Anomaly is recorded; the raw submission is kept alongside.
 * Free text (names, comment) is only trimmed, never interpreted.
 */
class SubmissionNormalizer
{
    /** Values documented in the API. Other values are kept but flagged. */
    public const KNOWN_STRUCTURE_TYPES = ['lycee', 'mission_locale', 'france_travail'];

    /** Age at the session date outside this range is considered a typo (e.g. 1900-01-01, 2031-05-12). */
    private const MIN_PLAUSIBLE_AGE = 5;
    private const MAX_PLAUSIBLE_AGE = 100;

    /**
     * @param array<string, mixed> $raw
     *
     * @throws InvalidSubmissionException when the submission has no usable id, dates or session
     */
    public function normalize(array $raw): NormalizedSubmission
    {
        $externalId = $this->text($raw['id'] ?? null);
        if (null === $externalId) {
            throw new InvalidSubmissionException('Submission without "id".');
        }

        $answers = \is_array($raw['answers'] ?? null) ? $raw['answers'] : [];
        $session = $this->session($externalId, $answers['session'] ?? null);
        [$createdAt, $updatedAt] = $this->timestamps($externalId, $raw['created_at'] ?? null, $raw['updated_at'] ?? null);

        $anomalies = [];

        $status = InscriptionStatus::tryFrom((string) $this->text($raw['status'] ?? null));
        if (!\in_array($status, [InscriptionStatus::Submitted, InscriptionStatus::Cancelled], true)) {
            // "removed" is our own status, never an API value.
            $status = InscriptionStatus::Submitted;
            $anomalies[] = Anomaly::StatusUnknown;
        }

        $firstName = $this->text($answers['first_name'] ?? null);
        if (null === $firstName) {
            $anomalies[] = Anomaly::FirstNameMissing;
        }
        $lastName = $this->text($answers['last_name'] ?? null);
        if (null === $lastName) {
            $anomalies[] = Anomaly::LastNameMissing;
        }

        $email = $this->email($answers['email'] ?? null, $anomalies);
        $phone = $this->phone($answers['phone'] ?? null, $anomalies);
        $birthDate = $this->birthDate($answers['birth_date'] ?? null, $session->date, $anomalies);

        $structureType = $this->text($answers['structure_type'] ?? null);
        if (null === $structureType) {
            $anomalies[] = Anomaly::StructureTypeMissing;
        } elseif (!\in_array($structureType, self::KNOWN_STRUCTURE_TYPES, true)) {
            $anomalies[] = Anomaly::StructureTypeUnknown;
        }

        /** @var array<string, mixed> $raw */
        return new NormalizedSubmission(
            externalId: $externalId,
            status: $status,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            firstName: $firstName ?? '',
            lastName: $lastName ?? '',
            email: $email,
            phone: $phone,
            birthDate: $birthDate,
            structureType: $structureType,
            structureName: $this->text($answers['structure_name'] ?? null),
            comment: $this->text($answers['comment'] ?? null),
            session: $session,
            anomalies: $anomalies,
            rawData: $raw,
        );
    }

    /**
     * Trims and collapses inner whitespace; empty or non-string values become null.
     */
    private function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' === $value ? null : $value;
    }

    /**
     * Emails are case-insensitive in practice: stored lowercased so that "EMMA.HENRY@…" and
     * "emma.henry@…" match. Missing (null, absent, empty) and invalid are told apart.
     *
     * @param list<Anomaly> $anomalies
     */
    private function email(mixed $value, array &$anomalies): ?string
    {
        $email = $this->text($value);
        if (null === $email) {
            $anomalies[] = Anomaly::EmailMissing;

            return null;
        }
        $email = mb_strtolower($email);
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $anomalies[] = Anomaly::EmailInvalid;

            return null;
        }

        return $email;
    }

    /**
     * French numbers only ("06 39 98 12 34", "06.39.98.12.34", "0639981234", "+33 6 39 98 12 34"),
     * normalized to E.164: "+33639981234". The phone is optional, so a missing one is not an anomaly.
     *
     * @param list<Anomaly> $anomalies
     */
    private function phone(mixed $value, array &$anomalies): ?string
    {
        $phone = $this->text($value);
        if (null === $phone) {
            return null;
        }
        $digits = (string) preg_replace('/[\s.\-()]/', '', $phone);

        if (1 === preg_match('/^0([1-9]\d{8})$/', $digits, $m)
            || 1 === preg_match('/^(?:\+33|0033)([1-9]\d{8})$/', $digits, $m)) {
            return '+33'.$m[1];
        }
        $anomalies[] = Anomaly::PhoneInvalid;

        return null;
    }

    /**
     * Only the documented format "YYYY-MM-DD" is accepted. "12/03/2008" is not guessed:
     * day/month order cannot be proven, so it is flagged for a human to check.
     *
     * @param list<Anomaly> $anomalies
     */
    private function birthDate(mixed $value, \DateTimeImmutable $sessionDate, array &$anomalies): ?\DateTimeImmutable
    {
        $text = $this->text($value);
        if (null === $text) {
            $anomalies[] = Anomaly::BirthDateMissing;

            return null;
        }
        $date = $this->strictDate($text);
        if (null === $date) {
            $anomalies[] = Anomaly::BirthDateUnreadable;

            return null;
        }
        $diff = $date->diff($sessionDate);
        $age = $diff->invert ? -1 : $diff->y;
        if ($age < self::MIN_PLAUSIBLE_AGE || $age > self::MAX_PLAUSIBLE_AGE) {
            $anomalies[] = Anomaly::BirthDateImplausible;

            return null;
        }

        return $date;
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable} [created_at, updated_at] in UTC
     */
    private function timestamps(string $externalId, mixed $created, mixed $updated): array
    {
        $createdAt = $this->timestamp($created);
        $updatedAt = $this->timestamp($updated);
        if (null === $createdAt && null === $updatedAt) {
            throw new InvalidSubmissionException(sprintf('Submission "%s" has no readable "created_at" nor "updated_at".', $externalId));
        }

        return [$createdAt ?? $updatedAt, $updatedAt ?? $createdAt];
    }

    private function timestamp(mixed $value): ?\DateTimeImmutable
    {
        $text = $this->text($value);
        if (null === $text) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($text))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function session(string $externalId, mixed $session): SessionData
    {
        $invalid = new InvalidSubmissionException(sprintf('Submission "%s" has no usable session.', $externalId));
        if (!\is_array($session)) {
            throw $invalid;
        }
        $id = $this->text($session['id'] ?? null);
        $date = $this->strictDate((string) $this->text($session['date'] ?? null));
        if (null === $id || null === $date) {
            throw $invalid;
        }
        $capacity = $session['capacity'] ?? null;

        return new SessionData(
            externalId: $id,
            label: $this->text($session['label'] ?? null) ?? $id,
            date: $date,
            location: $this->text($session['location'] ?? null) ?? '',
            capacity: \is_int($capacity) && $capacity >= 0 ? $capacity : 0,
        );
    }

    /**
     * "YYYY-MM-DD" only, and a real calendar date (rejects "2026-02-31").
     */
    private function strictDate(string $text): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, new \DateTimeZone('UTC'));

        return false !== $date && $date->format('Y-m-d') === $text ? $date : null;
    }
}
