<?php

namespace App\Repository;

use App\Entity\Inscription;
use App\Entity\Operation;
use App\Entity\Session;
use App\Enum\InscriptionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inscription>
 */
class InscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inscription::class);
    }

    /**
     * Marks as removed the inscriptions of these operations that the last complete import did not see.
     * Only to be called after a complete import: otherwise a page that failed would look like removals.
     *
     * @param list<Operation> $operations
     *
     * @return int number of inscriptions marked as removed
     */
    public function markRemovedNotSeenSince(array $operations, \DateTimeImmutable $since): int
    {
        if ([] === $operations) {
            return 0;
        }

        $sessionsOfOperations = $this->getEntityManager()->createQueryBuilder()
            ->select('s.id')
            ->from(Session::class, 's')
            ->where('s.operation IN (:operations)')
            ->getDQL();

        /** @var int $removed */
        $removed = $this->getEntityManager()->createQueryBuilder()
            ->update(Inscription::class, 'i')
            ->set('i.status', ':removed')
            ->where('i.lastSeenAt < :since')
            ->andWhere('i.status <> :removed')
            ->andWhere(sprintf('i.session IN (%s)', $sessionsOfOperations))
            ->setParameter('removed', InscriptionStatus::Removed->value)
            ->setParameter('since', $since)
            ->setParameter('operations', $operations)
            ->getQuery()
            ->execute();

        return $removed;
    }
}
