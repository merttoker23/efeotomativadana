<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist B2B product identity fingerprints for resume-safe duplicate detection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_external_mapping ADD content_sha256 VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_external_mapping DROP content_sha256');
    }
}
