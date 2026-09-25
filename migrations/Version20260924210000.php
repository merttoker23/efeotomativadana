<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add B2B synchronization mappings, runs, errors and Doctrine lock storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE integration_external_mapping (id INT AUTO_INCREMENT NOT NULL, provider_key VARCHAR(100) NOT NULL, resource_type VARCHAR(20) NOT NULL, external_id VARCHAR(191) NOT NULL, local_resource_type VARCHAR(50) NOT NULL, local_resource_id VARCHAR(191) NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_seen_run_id INT DEFAULT NULL, updated_at DATETIME NOT NULL, INDEX idx_integration_mapping_local (local_resource_type, local_resource_id), INDEX idx_integration_mapping_seen (provider_key, resource_type, last_seen_run_id), UNIQUE INDEX uniq_integration_external_mapping (provider_key, resource_type, external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE integration_b2b_sync_run (id INT AUTO_INCREMENT NOT NULL, provider_key VARCHAR(100) NOT NULL, mode VARCHAR(20) NOT NULL, state VARCHAR(20) NOT NULL, active_provider_key VARCHAR(100) DEFAULT NULL, declared_count INT DEFAULT NULL, snapshot_path VARCHAR(500) DEFAULT NULL, checkpoint INT NOT NULL, counters JSON NOT NULL, latest_error VARCHAR(2000) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, INDEX idx_integration_b2b_provider_state (provider_key, state, id), INDEX idx_integration_b2b_mode_state (provider_key, mode, state, started_at), INDEX idx_integration_b2b_finished (provider_key, mode, finished_at), UNIQUE INDEX uniq_integration_b2b_active_provider (active_provider_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE integration_b2b_sync_error (id INT AUTO_INCREMENT NOT NULL, run_id INT NOT NULL, provider_key VARCHAR(100) NOT NULL, mode VARCHAR(20) NOT NULL, external_id VARCHAR(191) DEFAULT NULL, error_type VARCHAR(40) NOT NULL, retryable TINYINT(1) NOT NULL, message VARCHAR(1000) NOT NULL, context JSON NOT NULL, created_at DATETIME NOT NULL, INDEX idx_integration_b2b_error_run (run_id, id), INDEX idx_integration_b2b_error_type (run_id, error_type, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY(key_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE integration_b2b_sync_error ADD CONSTRAINT FK_7A4D9F0B2C1E8A31 FOREIGN KEY (run_id) REFERENCES integration_b2b_sync_run (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lock_keys');
        $this->addSql('DROP TABLE integration_b2b_sync_error');
        $this->addSql('DROP TABLE integration_b2b_sync_run');
        $this->addSql('DROP TABLE integration_external_mapping');
    }
}
