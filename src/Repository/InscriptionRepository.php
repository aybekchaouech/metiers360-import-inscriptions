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
     * Every inscription of a session, whatever its status, in registration order.
     *
     * @return list<Inscription>
     */
    public function findBySessionInRegistrationOrder(Session $session): array
    {
        /** @var list<Inscription> */
        return $this->createQueryBuilder('i')
            ->where('i.session = :session')
            ->setParameter('session', $session)
            ->orderBy('i.sourceCreatedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active registrations of all the sessions of an operation: needed to spot
     * people registered to several sessions.
     *
     * @return list<Inscription>
     */
    public function findActiveByOperation(Operation $operation): array
    {
        /** @var list<Inscription> */
        return $this->createQueryBuilder('i')
            ->addSelect('s')
            ->join('i.session', 's')
            ->where('s.operation = :operation')
            ->andWhere('i.status = :submitted')
            ->setParameter('operation', $operation)
            ->setParameter('submitted', InscriptionStatus::Submitted->value)
            ->getQuery()
            ->getResult();
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
