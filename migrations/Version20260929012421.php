<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEO metadata overrides and slug redirect history.
 *
 * Two small tables rather than nullable columns on the five tables that own public URLs: a
 * content type gains SEO by getting a row type here, not by a migration per entity, and the
 * admin form is the same form everywhere.
 *
 * `seo_slug_redirect` is what makes a published URL survive a rename, which is otherwise the
 * silent half of the "B2B name changes must not destroy established URLs" rule. The unique
 * index is on `(resource_type, old_slug)` and not per row on purpose: one address can only
 * mean one thing at a time, so when a later record is handed a slug an earlier record retired,
 * the history is re-pointed at the current owner instead of leaving two rows claiming the same
 * address.
 *
 * `resource_id` is deliberately not a foreign key. A redirect outlives the record it was
 * written for, and a cascade would delete the history that explains where a dead URL once
 * pointed; the resolver checks the target is still live before answering with it.
 */
final class Version20260929012421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SEO metadata overrides and slug redirect history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE seo_override (
              id INT AUTO_INCREMENT NOT NULL,
              resource_type VARCHAR(20) NOT NULL,
              resource_id INT NOT NULL,
              meta_title VARCHAR(255) DEFAULT NULL,
              meta_description VARCHAR(320) DEFAULT NULL,
              no_index TINYINT DEFAULT 0 NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX uniq_seo_override_resource (resource_type, resource_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE seo_slug_redirect (
              id INT AUTO_INCREMENT NOT NULL,
              resource_type VARCHAR(20) NOT NULL,
              resource_id INT NOT NULL,
              old_slug VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL,
              INDEX idx_seo_slug_redirect_resource (resource_type, resource_id),
              UNIQUE INDEX uniq_seo_slug_redirect_old_slug (resource_type, old_slug),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE seo_override');
        $this->addSql('DROP TABLE seo_slug_redirect');
    }
}
