<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records what a customer actually handed the payment processor, when that exceeded the order
 * total.
 *
 * A customer paying by instalment gives the processor more than the order is worth; the difference
 * is interest and belongs to the processor and the customer, not to the store. The order still
 * settles at the order total, so this figure only widens what may later be refunded to that
 * customer. It is zero for every ordinary payment.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the amount collected above the order total so an instalment payment can be refunded up to what was actually paid';
    }

    public function up(Schema $schema): void
    {
        // Spelled out in full rather than relying on a DEFAULT, so the column Doctrine compares
        // against is byte-identical: an omitted default leaves MySQL reporting one, and the
        // schema check then reports drift that does not exist in behaviour.
        $this->addSql('ALTER TABLE commerce_payment ADD collected_minor_amount BIGINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_payment DROP collected_minor_amount');
    }
}
