<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Payment aggregate: one payment per order, its external attempts, refunds and the
 * separate payment audit trail.
 */
final class Version20260925203441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provider-neutral payment, attempt, refund and payment audit persistence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE commerce_payment (id INT AUTO_INCREMENT NOT NULL, provider_key VARCHAR(50) NOT NULL, method_key VARCHAR(50) NOT NULL, state VARCHAR(20) NOT NULL, amount_minor_amount BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, captured_minor_amount BIGINT NOT NULL, refunded_minor_amount BIGINT NOT NULL, failure_code VARCHAR(80) DEFAULT NULL, failure_message VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, order_id INT NOT NULL, INDEX idx_payment_state_updated (state, updated_at), UNIQUE INDEX uniq_payment_order (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE commerce_payment_attempt (id INT AUTO_INCREMENT NOT NULL, sequence INT NOT NULL, idempotency_key VARCHAR(80) NOT NULL, return_token VARCHAR(64) NOT NULL, provider_key VARCHAR(50) NOT NULL, provider_reference VARCHAR(120) DEFAULT NULL, state VARCHAR(20) NOT NULL, amount_minor_amount BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, failure_code VARCHAR(80) DEFAULT NULL, failure_message VARCHAR(500) DEFAULT NULL, succeeded_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, payment_id INT NOT NULL, INDEX IDX_3B2EA6194C3A3BB (payment_id), INDEX idx_payment_attempt_payment_sequence (payment_id, sequence), UNIQUE INDEX uniq_payment_attempt_idempotency_key (idempotency_key), INDEX idx_payment_attempt_provider_reference (provider_key, provider_reference), UNIQUE INDEX uniq_payment_attempt_return_token (return_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE commerce_payment_event (id INT AUTO_INCREMENT NOT NULL, attempt_sequence INT DEFAULT NULL, to_state VARCHAR(30) NOT NULL, source VARCHAR(30) NOT NULL, detail VARCHAR(255) DEFAULT NULL, occurred_at DATETIME NOT NULL, payment_id INT NOT NULL, INDEX IDX_F9CB86F24C3A3BB (payment_id), INDEX idx_payment_event_payment_time (payment_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE commerce_payment_refund (id INT AUTO_INCREMENT NOT NULL, amount_minor_amount BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, provider_key VARCHAR(50) NOT NULL, provider_reference VARCHAR(120) NOT NULL, state VARCHAR(20) NOT NULL, reason VARCHAR(255) NOT NULL, failure_code VARCHAR(120) DEFAULT NULL, failure_message VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, payment_id INT NOT NULL, INDEX IDX_40EFD4AF4C3A3BB (payment_id), INDEX idx_payment_refund_payment_time (payment_id, created_at), UNIQUE INDEX uniq_payment_refund_provider_reference (provider_key, provider_reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        // A callback is only ever resolved through the unguessable return token, so a
        // replayed or forged request cannot address an attempt that is not its own.
        // 'captured_amount_mismatch' is the longest payment state value, so the three state
        // columns are sized to hold it rather than truncating a real state into an unknown one.
        $this->addSql('ALTER TABLE commerce_payment MODIFY state VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE commerce_payment_attempt MODIFY state VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE commerce_payment_event MODIFY to_state VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE commerce_payment_event MODIFY attempt_sequence INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commerce_payment ADD CONSTRAINT FK_DEA580A78D9F6D38 FOREIGN KEY (order_id) REFERENCES commerce_customer_order (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_payment_attempt ADD CONSTRAINT FK_3B2EA6194C3A3BB FOREIGN KEY (payment_id) REFERENCES commerce_payment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_payment_event ADD CONSTRAINT FK_F9CB86F24C3A3BB FOREIGN KEY (payment_id) REFERENCES commerce_payment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commerce_payment_refund ADD CONSTRAINT FK_40EFD4AF4C3A3BB FOREIGN KEY (payment_id) REFERENCES commerce_payment (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commerce_payment DROP FOREIGN KEY FK_DEA580A78D9F6D38');
        $this->addSql('ALTER TABLE commerce_payment_event MODIFY to_state VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE commerce_payment_attempt MODIFY state VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE commerce_payment MODIFY state VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE commerce_payment_event MODIFY attempt_sequence BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE commerce_payment_attempt DROP FOREIGN KEY FK_3B2EA6194C3A3BB');
        $this->addSql('ALTER TABLE commerce_payment_event DROP FOREIGN KEY FK_F9CB86F24C3A3BB');
        $this->addSql('ALTER TABLE commerce_payment_refund DROP FOREIGN KEY FK_40EFD4AF4C3A3BB');
        $this->addSql('DROP TABLE commerce_payment');
        $this->addSql('DROP TABLE commerce_payment_attempt');
        $this->addSql('DROP TABLE commerce_payment_event');
        $this->addSql('DROP TABLE commerce_payment_refund');
    }
}
