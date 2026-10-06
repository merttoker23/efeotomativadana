<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add independently managed FAQ items for the public storefront FAQ page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cms_faq_item (id INT AUTO_INCREMENT NOT NULL, question VARCHAR(255) NOT NULL, answer LONGTEXT NOT NULL, active TINYINT(1) NOT NULL, sort_order INT NOT NULL, INDEX idx_cms_faq_storefront (active, sort_order, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cms_faq_item');
    }
}
