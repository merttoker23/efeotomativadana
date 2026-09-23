<?php

namespace App\Tests\Integration\Cms;

use App\Entity\Cms\BlogPost;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CmsPersistenceTest extends KernelTestCase
{
    public function testBlogSlugsAreUniqueInTheDatabase(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        try {
            $manager = self::getContainer()->get('doctrine')->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $manager->persist(new BlogPost('First', 'duplicate-cms-slug', 'First excerpt', 'First body'));
            $manager->persist(new BlogPost('Second', 'duplicate-cms-slug', 'Second excerpt', 'Second body'));
            $this->expectException(UniqueConstraintViolationException::class);
            $manager->flush();
        } finally {
            if ($connection->isTransactionActive()) { $connection->rollBack(); }
        }
    }
}
