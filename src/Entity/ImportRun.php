<?php

namespace App\Entity;

use App\Enum\ImportRunStatus;
use App\Repository\ImportRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal of one execution of app:inscriptions:import.
 *
 * Only a run that read every page successfully may mark submissions as removed from the API.
 */
#[ORM\Entity(repositoryClass: ImportRunRepository::class)]
class ImportRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 20, enumType: ImportRunStatus::class)]
    private ImportRunStatus $status = ImportRunStatus::Running;

    #[ORM\Column]
    private int $pagesRead = 0;

    /** "meta.total" announced by the API. */
    #[ORM\Column(nullable: true)]
    private ?int $totalAnnounced = null;

    #[ORM\Column]
    private int $created = 0;

    #[ORM\Column]
    private int $updated = 0;

    #[ORM\Column]
    private int $unchanged = 0;

    #[ORM\Column]
    private int $removed = 0;

    #[ORM\Column]
    private int $withAnomalies = 0;

    /** Submissions that could not be imported at all (no id, no session, no readable date). */
    #[ORM\Column(options: ['default' => 0])]
    private int $rejected = 0;

    /**
     * Why each rejected submission was refused, with what the API sent: a rejected registration is
     * still a real person the operations team may have to contact, it must not vanish silently.
     *
     * @var list<array{reason: string, submission: array<string, mixed>}>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $rejectedSubmissions = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(\DateTimeImmutable $startedAt)
    {
        $this->startedAt = $startedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): ImportRunStatus
    {
        return $this->status;
    }

    public function succeed(\DateTimeImmutable $finishedAt): void
    {
        $this->status = ImportRunStatus::Success;
        $this->finishedAt = $finishedAt;
    }

    public function fail(\DateTimeImmutable $finishedAt, string $error): void
    {
        $this->status = ImportRunStatus::Failed;
        $this->finishedAt = $finishedAt;
        $this->error = $error;
    }

    public function getPagesRead(): int
    {
        return $this->pagesRead;
    }

    public function incrementPagesRead(): void
    {
        ++$this->pagesRead;
    }

    public function getTotalAnnounced(): ?int
    {
        return $this->totalAnnounced;
    }

    public function setTotalAnnounced(?int $totalAnnounced): void
    {
        $this->totalAnnounced = $totalAnnounced;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function incrementCreated(): void
    {
        ++$this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function incrementUpdated(): void
    {
        ++$this->updated;
    }

    public function getUnchanged(): int
    {
        return $this->unchanged;
    }

    public function incrementUnchanged(): void
    {
        ++$this->unchanged;
    }

    public function getRemoved(): int
    {
        return $this->removed;
    }

    public function setRemoved(int $removed): void
    {
        $this->removed = $removed;
    }

    public function getWithAnomalies(): int
    {
        return $this->withAnomalies;
    }

    public function incrementWithAnomalies(): void
    {
        ++$this->withAnomalies;
    }

    public function getRejected(): int
    {
        return $this->rejected;
    }

    /**
     * @param array<string, mixed> $submission the submission exactly as received
     */
    public function reject(string $reason, array $submission): void
    {
        ++$this->rejected;
        $this->rejectedSubmissions[] = ['reason' => $reason, 'submission' => $submission];
    }

    /**
     * @return list<array{reason: string, submission: array<string, mixed>}>
     */
    public function getRejectedSubmissions(): array
    {
        return $this->rejectedSubmissions;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
