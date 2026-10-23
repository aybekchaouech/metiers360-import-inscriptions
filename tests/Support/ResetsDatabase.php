<?php

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Empties the test database (app_test) before each test, so that tests do not depend on each other.
 */
trait ResetsDatabase
{
    protected function resetDatabase(EntityManagerInterface $entityManager): void
    {
        $entityManager->getConnection()->executeStatement('TRUNCATE inscription, session, operation, import_run RESTART IDENTITY CASCADE');
        $entityManager->clear();
    }
}
