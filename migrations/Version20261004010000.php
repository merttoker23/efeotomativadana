<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional CMS media cover path to blog posts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cms_blog_post ADD cover_image_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cms_blog_post DROP cover_image_path');
    }
}
