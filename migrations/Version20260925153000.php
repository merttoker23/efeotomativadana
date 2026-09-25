<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Version B2B product identity fingerprints and retain canonical SKU claims';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_external_mapping ADD identity_version SMALLINT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE integration_b2b_sync_observation ADD sku VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_b2b_sync_observation DROP sku');
        $this->addSql('ALTER TABLE integration_external_mapping DROP identity_version');
    }
}
