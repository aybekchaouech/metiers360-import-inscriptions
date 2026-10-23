<?php

namespace App\Entity;

use App\Repository\SessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A session of an operation (date, place, capacity). The form API repeats these details
 * inside every submission; they are stored once here.
 */
#[ORM\Entity(repositoryClass: SessionRepository::class)]
class Session
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sessions')]
    #[ORM\JoinColumn(nullable: false)]
    private Operation $operation;

    /** Session id in the external API (e.g. "ses_03"). */
    #[ORM\Column(length: 64, unique: true)]
    private string $externalId;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 255)]
    private string $location;

    #[ORM\Column]
    private int $capacity;

    /** @var Collection<int, Inscription> */
    #[ORM\OneToMany(targetEntity: Inscription::class, mappedBy: 'session')]
    private Collection $inscriptions;

    public function __construct(
        Operation $operation,
        string $externalId,
        string $label,
        \DateTimeImmutable $date,
        string $location,
        int $capacity,
    ) {
        $this->operation = $operation;
        $this->externalId = $externalId;
        $this->label = $label;
        $this->date = $date;
        $this->location = $location;
        $this->capacity = $capacity;
        $this->inscriptions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperation(): Operation
    {
        return $this->operation;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * The API is the source of truth for session details: refreshed on every import.
     */
    public function updateDetails(string $label, \DateTimeImmutable $date, string $location, int $capacity): void
    {
        $this->label = $label;
        $this->date = $date;
        $this->location = $location;
        $this->capacity = $capacity;
    }

    /** @return Collection<int, Inscription> */
    public function getInscriptions(): Collection
    {
        return $this->inscriptions;
    }
}
