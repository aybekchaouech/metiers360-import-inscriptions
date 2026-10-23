<?php

namespace App\Entity;

use App\Enum\InscriptionStatus;
use App\Repository\InscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One submission of the registration form.
 *
 * Nothing is ever rejected: fields that cannot be trusted are stored as null, the reason is
 * listed in $anomalies and the untouched API payload is kept in $rawData.
 */
#[ORM\Entity(repositoryClass: InscriptionRepository::class)]
#[ORM\Index(name: 'idx_inscription_session_status', columns: ['session_id', 'status'])]
#[ORM\Index(name: 'idx_inscription_email', columns: ['email'])]
class Inscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Submission id in the external API (e.g. "sub_k3m9qz7tpe"): the idempotency key of the import. */
    #[ORM\Column(length: 64, unique: true)]
    private string $externalId;

    // A person may move to another session between two imports.
    #[ORM\ManyToOne(inversedBy: 'inscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private Session $session;

    #[ORM\Column(length: 20, enumType: InscriptionStatus::class)]
    private InscriptionStatus $status;

    #[ORM\Column(length: 255)]
    private string $firstName;

    #[ORM\Column(length: 255)]
    private string $lastName;

    /** Lowercased; null when missing or invalid. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    /** Normalized to E.164 (e.g. "+33639981234") when possible. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    /** Null when unreadable or implausible (e.g. 1900-01-01, a future date). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    /** Kept as sent, even outside the documented values (e.g. "cfa", "Autre"). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $structureType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $structureName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $anomalies = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $rawData = [];

    /** Submission date in the API, UTC. Also gives the waiting-list order. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sourceCreatedAt;

    /** Last modification in the API, UTC. Used to skip unchanged submissions. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sourceUpdatedAt;

    /** When the submission was last returned by the API. Informative. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    /**
     * Import run that last saw this submission: used to detect submissions removed from the API.
     * A run reference rather than a date, because dates are stored to the second and two runs
     * can start within the same second.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ImportRun $lastSeenBy = null;

    public function __construct(string $externalId)
    {
        $this->externalId = $externalId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function setSession(Session $session): void
    {
        $this->session = $session;
    }

    public function getStatus(): InscriptionStatus
    {
        return $this->status;
    }

    public function setStatus(InscriptionStatus $status): void
    {
        $this->status = $status;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): void
    {
        $this->birthDate = $birthDate;
    }

    public function getStructureType(): ?string
    {
        return $this->structureType;
    }

    public function setStructureType(?string $structureType): void
    {
        $this->structureType = $structureType;
    }

    public function getStructureName(): ?string
    {
        return $this->structureName;
    }

    public function setStructureName(?string $structureName): void
    {
        $this->structureName = $structureName;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    /** @return list<string> */
    public function getAnomalies(): array
    {
        return $this->anomalies;
    }

    /** @param list<string> $anomalies */
    public function setAnomalies(array $anomalies): void
    {
        $this->anomalies = $anomalies;
    }

    /** @return array<string, mixed> */
    public function getRawData(): array
    {
        return $this->rawData;
    }

    /** @param array<string, mixed> $rawData */
    public function setRawData(array $rawData): void
    {
        $this->rawData = $rawData;
    }

    public function getSourceCreatedAt(): \DateTimeImmutable
    {
        return $this->sourceCreatedAt;
    }

    public function setSourceCreatedAt(\DateTimeImmutable $sourceCreatedAt): void
    {
        $this->sourceCreatedAt = $sourceCreatedAt;
    }

    public function getSourceUpdatedAt(): \DateTimeImmutable
    {
        return $this->sourceUpdatedAt;
    }

    public function setSourceUpdatedAt(\DateTimeImmutable $sourceUpdatedAt): void
    {
        $this->sourceUpdatedAt = $sourceUpdatedAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): void
    {
        $this->lastSeenAt = $lastSeenAt;
    }

    public function getLastSeenBy(): ?ImportRun
    {
        return $this->lastSeenBy;
    }

    public function markSeenBy(ImportRun $run): void
    {
        $this->lastSeenBy = $run;
        $this->lastSeenAt = $run->getStartedAt();
    }
}
