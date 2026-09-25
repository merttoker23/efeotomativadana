<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the B2B snapshot digest for safe checkpoint resume';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_b2b_sync_run ADD snapshot_sha256 VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_b2b_sync_run DROP snapshot_sha256');
    }
}
