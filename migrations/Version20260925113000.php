<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track B2B message dispatch for stranded-run recovery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_b2b_sync_run ADD message_dispatched_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE integration_b2b_sync_run DROP message_dispatched_at');
    }
}
