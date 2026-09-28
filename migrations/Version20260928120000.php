<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Return aggregate: one request per return number, its own lines and the separate return audit
 * trail.
 *
 * Two constraints carry weight rather than describing a shape:
 *
 * - the unique index on `return_number` is what makes a retried submission, a double-clicked
 *   button and a redelivered message converge on one request instead of three that each claim the
 *   same quantity of goods;
 * - the unique index on `(return_request_id, order_item_id)` makes "the same order line twice in
 *   one request" unrepresentable, so the quantity guard below it never has to defend against its
 *   own data.
 *
 * The FK to the order is CASCADE, matching the shipment: an order is historical and is not deleted
 * while a store is running, but a test fixture is, and a return that outlived its order with a
 * dangling reference would break every read of it.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add return request persistence with lines and a separate return audit trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE commerce_return_request (id INT AUTO_INCREMENT NOT NULL, return_number VARCHAR(32) NOT NULL, state VARCHAR(30) NOT NULL, customer_reason VARCHAR(1000) NOT NULL, staff_note VARCHAR(1000) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, received_at DATETIME DEFAULT NULL, refunded_at DATETIME DEFAULT NULL, refund_minor_amount BIGINT DEFAULT NULL, refund_currency VARCHAR(3) DEFAULT NULL, refund_reference VARCHAR(120) DEFAULT NULL, order_id INT NOT NULL, customer_id INT NOT NULL, INDEX IDX_1344C6219395C3F3 (customer_id), INDEX idx_return_request_customer_created (customer_id, created_at), INDEX idx_return_request_order (order_id), INDEX idx_return_request_state_updated (state, updated_at), UNIQUE INDEX uniq_return_request_number (return_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE commerce_return_request_item (id INT AUTO_INCREMENT NOT NULL, sku VARCHAR(64) NOT NULL, product_name VARCHAR(255) NOT NULL, quantity INT NOT NULL, reason VARCHAR(500) NOT NULL, unit_gross_minor_amount BIGINT NOT NULL, tax_rate_basis_points INT NOT NULL, currency VARCHAR(3) NOT NULL, return_request_id INT NOT NULL, order_item_id INT NOT NULL, INDEX IDX_FBC586E5E415FB15 (order_item_id), INDEX IDX_FBC586E589EA1297 (return_request_id), UNIQUE INDEX uniq_return_item_request_line (return_request_id, order_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE commerce_return_event (id INT AUTO_INCREMENT NOT NULL, to_state VARCHAR(30) NOT NULL, source VARCHAR(30) NOT NULL, detail VARCHAR(500) DEFAULT NULL, actor_email VARCHAR(180) DEFAULT NULL, occurred_at DATETIME NOT NULL, return_request_id INT NOT NULL, INDEX IDX_71E9C30389EA1297 (return_request_id), INDEX idx_return_event_request_time (return_request_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE commerce_return_request ADD CONSTRAINT FK_9E0F1A2B3C4D5E6F7 FOREIGN KEY (order_id) REFERENCES commerce_customer_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_return_request ADD CONSTRAINT FK_0F1A2B3C4D5E6F7A8 FOREIGN KEY (customer_id) REFERENCES customer_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_return_request_item ADD CONSTRAINT FK_1A2B3C4D5E6F7A8B9 FOREIGN KEY (return_request_id) REFERENCES commerce_return_request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_return_request_item ADD CONSTRAINT FK_2B3C4D5E6F7A8B9C0 FOREIGN KEY (order_item_id) REFERENCES commerce_order_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_return_event ADD CONSTRAINT FK_3C4D5E6F7A8B9C0D1 FOREIGN KEY (return_request_id) REFERENCES commerce_return_request (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commerce_return_event DROP FOREIGN KEY FK_3C4D5E6F7A8B9C0D1');
        $this->addSql('ALTER TABLE commerce_return_request_item DROP FOREIGN KEY FK_2B3C4D5E6F7A8B9C0');
        $this->addSql('ALTER TABLE commerce_return_request_item DROP FOREIGN KEY FK_1A2B3C4D5E6F7A8B9');
        $this->addSql('ALTER TABLE commerce_return_request DROP FOREIGN KEY FK_0F1A2B3C4D5E6F7A8');
        $this->addSql('ALTER TABLE commerce_return_request DROP FOREIGN KEY FK_9E0F1A2B3C4D5E6F7');
        $this->addSql('DROP TABLE commerce_return_event');
        $this->addSql('DROP TABLE commerce_return_request_item');
        $this->addSql('DROP TABLE commerce_return_request');
    }
}
