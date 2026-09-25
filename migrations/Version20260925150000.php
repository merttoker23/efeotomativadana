<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist durable B2B per-run product claims for checkpoint-safe duplicate detection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE integration_b2b_sync_observation (id INT AUTO_INCREMENT NOT NULL, run_id INT NOT NULL, provider_key VARCHAR(100) NOT NULL, resource_type VARCHAR(20) NOT NULL, external_id VARCHAR(191) NOT NULL, stream_position INT DEFAULT NULL, identity_sha256 VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_integration_b2b_observation_position (run_id, resource_type, stream_position), UNIQUE INDEX uniq_integration_b2b_observation (run_id, provider_key, resource_type, external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE integration_b2b_sync_observation ADD CONSTRAINT fk_integration_b2b_observation_run FOREIGN KEY (run_id) REFERENCES integration_b2b_sync_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE integration_b2b_sync_observation');
    }
}
