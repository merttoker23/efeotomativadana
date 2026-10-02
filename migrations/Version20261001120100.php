<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serialize reward ledger writers to prevent absent-source gap-lock deadlocks under MySQL repeatable read.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE loyalty_ledger_lock (id INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('INSERT INTO loyalty_ledger_lock (id) VALUES (1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE loyalty_ledger_lock');
    }
}
