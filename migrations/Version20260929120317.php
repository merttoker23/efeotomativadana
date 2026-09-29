<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The cross-cutting audit trail.
 *
 * One additive table. Two properties of the generated SQL are deliberate and worth stating,
 * because both would be easy to "fix" later by someone assuming they were oversights:
 *
 * - **No foreign key anywhere.** `resource_type`/`resource_id` are plain strings. A foreign key
 *   would cascade-delete the audit row with its subject, and "who deactivated this customer"
 *   must survive the customer being deleted. This is the one deliberate departure from the rest
 *   of the schema, where every event table cascades with its parent.
 * - **No updated_at.** The entity has no update path; a row that could change would not be
 *   evidence of anything.
 *
 * The four indexes match the four questions the table is actually asked: what happened, when;
 * what happened to one kind of thing; what did one person do; what happened to one resource.
 */
final class Version20260929120317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the cross-cutting audit trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commerce_audit_log (
              id INT AUTO_INCREMENT NOT NULL,
              action VARCHAR(60) NOT NULL,
              actor_type VARCHAR(20) NOT NULL,
              actor_email VARCHAR(180) DEFAULT NULL,
              resource_type VARCHAR(60) DEFAULT NULL,
              resource_id VARCHAR(120) DEFAULT NULL,
              payload JSON NOT NULL,
              ip_address VARCHAR(45) DEFAULT NULL,
              request_id VARCHAR(64) DEFAULT NULL,
              occurred_at DATETIME NOT NULL,
              INDEX idx_audit_log_occurred (occurred_at),
              INDEX idx_audit_log_action_time (action, occurred_at),
              INDEX idx_audit_log_actor_time (actor_email, occurred_at),
              INDEX idx_audit_log_resource (resource_type, resource_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Additive and self-contained: removing the trail is a plain DROP, with no ordering
        // constraint against any other table because this table references nothing.
        $this->addSql('DROP TABLE commerce_audit_log');
    }
}
