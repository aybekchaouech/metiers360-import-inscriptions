<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the date each person joined their session, which gives the waiting-list order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inscription ADD session_joined_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Existing rows: the session change dates are unknown, the next import keeps these values.
        $this->addSql('UPDATE inscription SET session_joined_at = source_created_at');
        $this->addSql('ALTER TABLE inscription ALTER session_joined_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inscription DROP session_joined_at');
    }
}
