<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023153619 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reference to the import run that last saw each inscription';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inscription ADD last_seen_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE inscription ADD CONSTRAINT FK_5E90F6D6D3EB0CB0 FOREIGN KEY (last_seen_by_id) REFERENCES import_run (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5E90F6D6D3EB0CB0 ON inscription (last_seen_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE inscription DROP CONSTRAINT FK_5E90F6D6D3EB0CB0');
        $this->addSql('DROP INDEX IDX_5E90F6D6D3EB0CB0');
        $this->addSql('ALTER TABLE inscription DROP last_seen_by_id');
    }
}
