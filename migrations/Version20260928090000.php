<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shipping aggregate: one shipment per order, its own lifecycle timestamps and the separate
 * shipment audit trail.
 *
 * The unique index on `order_id` is the load-bearing part of this migration. A double-clicked
 * button, a retried Messenger message and a hand-called admin action can all reach creation at
 * the same moment, and three application-level checks racing would still let three parcels
 * through. The database is the only place that can refuse the second one.
 */
final class Version20260928090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provider-neutral shipment persistence with a separate shipment audit trail';
    }

    public function up(Schema $schema): void
    {
        // Lifecycle timestamps are separate columns rather than inferred from the audit rows, so a
        // screen can answer "when was this delivered" without scanning the event history, and so a
        // refused event that changed nothing cannot invent a moment.
        $this->addSql('CREATE TABLE commerce_shipment (id INT AUTO_INCREMENT NOT NULL, idempotency_key VARCHAR(80) NOT NULL, method_key VARCHAR(50) NOT NULL, method_label VARCHAR(120) NOT NULL, provider_key VARCHAR(50) NOT NULL, state VARCHAR(30) NOT NULL, provider_reference VARCHAR(120) DEFAULT NULL, tracking_number VARCHAR(120) DEFAULT NULL, failure_code VARCHAR(80) DEFAULT NULL, failure_message VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ready_at DATETIME DEFAULT NULL, in_transit_at DATETIME DEFAULT NULL, delivered_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, order_id INT NOT NULL, INDEX idx_shipment_state_updated (state, updated_at), INDEX idx_shipment_provider_reference (provider_key, provider_reference), UNIQUE INDEX uniq_shipment_order (order_id), UNIQUE INDEX uniq_shipment_idempotency_key (idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE commerce_shipment_event (id INT AUTO_INCREMENT NOT NULL, to_state VARCHAR(30) NOT NULL, source VARCHAR(30) NOT NULL, actor_email VARCHAR(180) DEFAULT NULL, detail VARCHAR(255) DEFAULT NULL, occurred_at DATETIME NOT NULL, shipment_id INT NOT NULL, INDEX IDX_8998F3637BE036FC (shipment_id), INDEX idx_shipment_event_shipment_time (shipment_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE commerce_shipment ADD CONSTRAINT FK_347BE72E8D9F6D38 FOREIGN KEY (order_id) REFERENCES commerce_customer_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_shipment_event ADD CONSTRAINT FK_8998F3637BE036FC FOREIGN KEY (shipment_id) REFERENCES commerce_shipment (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_shipment_event DROP FOREIGN KEY FK_8998F3637BE036FC');
        $this->addSql('ALTER TABLE commerce_shipment DROP FOREIGN KEY FK_347BE72E8D9F6D38');
        $this->addSql('DROP TABLE commerce_shipment_event');
        $this->addSql('DROP TABLE commerce_shipment');
    }
}
