<?php

namespace App\Repository;

use App\Entity\ImportRun;
use App\Enum\ImportRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRun>
 */
class ImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRun::class);
    }

    public function findLastSuccessful(): ?ImportRun
    {
        return $this->findOneBy(['status' => ImportRunStatus::Success], ['finishedAt' => 'DESC']);
    }

    public function findLast(): ?ImportRun
    {
        return $this->findOneBy([], ['startedAt' => 'DESC']);
    }
}
