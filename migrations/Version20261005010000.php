<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the optional plain-text customer order note snapshot; existing orders retain a null note.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_customer_order ADD order_note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_customer_order DROP order_note');
    }
}
