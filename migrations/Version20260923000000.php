<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add typed homepage sections, blog posts and information pages'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cms_home_section (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(32) NOT NULL, title VARCHAR(255) NOT NULL, subtitle VARCHAR(500) DEFAULT NULL, configuration JSON NOT NULL, enabled TINYINT(1) NOT NULL, sort_order INT NOT NULL, INDEX idx_cms_home_order (enabled, sort_order, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cms_blog_post (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, excerpt LONGTEXT NOT NULL, body LONGTEXT NOT NULL, published TINYINT(1) NOT NULL, UNIQUE INDEX uniq_cms_blog_slug (slug), INDEX idx_cms_blog_published (published, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cms_information_page (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, published TINYINT(1) NOT NULL, UNIQUE INDEX uniq_cms_page_slug (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cms_home_section');
        $this->addSql('DROP TABLE cms_blog_post');
        $this->addSql('DROP TABLE cms_information_page');
    }
}
