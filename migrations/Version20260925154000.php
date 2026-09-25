<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925154000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index durable B2B observations by canonical SKU for bounded batch ownership checks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_integration_b2b_observation_sku ON integration_b2b_sync_observation (run_id, resource_type, sku)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_integration_b2b_observation_sku ON integration_b2b_sync_observation');
    }
}
