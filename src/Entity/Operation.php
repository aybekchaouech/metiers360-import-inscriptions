<?php

namespace App\Entity;

use App\Repository\OperationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An operation is one registration form of the external tool (e.g. "Ain, novembre 2026").
 */
#[ORM\Entity(repositoryClass: OperationRepository::class)]
class Operation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Form id in the external API (e.g. "frm_7Hq2xL"). */
    #[ORM\Column(length: 64, unique: true)]
    private string $externalId;

    #[ORM\Column(length: 255)]
    private string $title;

    /** @var Collection<int, Session> */
    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'operation')]
    private Collection $sessions;

    public function __construct(string $externalId, string $title)
    {
        $this->externalId = $externalId;
        $this->title = $title;
        $this->sessions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /** @return Collection<int, Session> */
    public function getSessions(): Collection
    {
        return $this->sessions;
    }
}
