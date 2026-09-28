<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Transactional notification log.
 *
 * The unique index on `dedup_key` is the entire deduplication mechanism, and it is here rather than
 * in application code because that is the only place that can decide correctly: two payment
 * webhooks arriving in the same millisecond both read "have I already sent this?" as no before
 * either has written a row.
 *
 * `failure_message` and `attempts` exist because a notification this store believed it sent and
 * did not is a question an operator has to be able to answer at 23:00, and a Mailpit log cannot
 * answer it — a queued message is not a delivered one.
 */
final class Version20260928140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add transactional notification log with a unique dedup key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE commerce_notification (id INT AUTO_INCREMENT NOT NULL, dedup_key VARCHAR(180) NOT NULL, type VARCHAR(40) NOT NULL, subject_reference VARCHAR(120) NOT NULL, recipient VARCHAR(180) NOT NULL, channel VARCHAR(20) NOT NULL, payload JSON NOT NULL, attempts INT NOT NULL, failure_message VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, INDEX idx_notification_created (created_at), INDEX idx_notification_type_reference (type, subject_reference), UNIQUE INDEX uniq_notification_dedup_key (dedup_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE commerce_notification');
    }
}
