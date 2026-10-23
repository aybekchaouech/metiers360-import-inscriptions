<?php

namespace App\Repository;

use App\Entity\ImportRun;
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
     * Inscriptions of these operations that this import run did not see, and not already marked
     * as removed. After a complete run, these are the ones the API no longer returns.
     *
     * @param list<Operation> $operations
     *
     * @return list<Inscription>
     */
    public function findNotSeenBy(array $operations, ImportRun $run): array
    {
        if ([] === $operations) {
            return [];
        }

        /** @var list<Inscription> */
        return $this->createQueryBuilder('i')
            ->join('i.session', 's')
            ->where('s.operation IN (:operations)')
            ->andWhere('i.lastSeenBy IS NULL OR i.lastSeenBy <> :run')
            ->andWhere('i.status <> :removed')
            ->setParameter('operations', $operations)
            ->setParameter('run', $run)
            ->setParameter('removed', InscriptionStatus::Removed->value)
            ->getQuery()
            ->getResult();
    }
}
