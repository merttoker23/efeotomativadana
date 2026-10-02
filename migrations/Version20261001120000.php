<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add append-only customer reward ledger with unique event sources and bounded history indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE loyalty_reward_transaction (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, order_id INT DEFAULT NULL, kind VARCHAR(30) NOT NULL, points BIGINT NOT NULL, source_key VARCHAR(120) NOT NULL, reason VARCHAR(255) NOT NULL, actor_email VARCHAR(180) DEFAULT NULL, eligible_minor BIGINT DEFAULT NULL, earn_percentage INT DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_reward_source (source_key), INDEX idx_reward_customer_created (customer_id, created_at, id), INDEX idx_reward_order_kind (order_id, kind), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE loyalty_reward_transaction ADD CONSTRAINT fk_reward_customer FOREIGN KEY (customer_id) REFERENCES customer_user (id), ADD CONSTRAINT fk_reward_order FOREIGN KEY (order_id) REFERENCES commerce_customer_order (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE loyalty_reward_transaction');
    }
}
