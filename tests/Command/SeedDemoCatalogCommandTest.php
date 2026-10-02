<?php

namespace App\Tests\Command;

use App\Entity\Catalog\Product;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedDemoCatalogCommandTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testFreshTaxonomiesAndEverySeededImageAreUsable(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:seed-demo'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--products' => '3', '--batch' => '2', '--prefix' => 'SEEDTEST']));
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $products = $manager->getRepository(Product::class)->findBy(['sku' => ['SEEDTEST-000000', 'SEEDTEST-000001', 'SEEDTEST-000002']]);
        self::assertCount(3, $products);
        foreach ($products as $product) {
            self::assertNotEmpty($product->categories());
            self::assertNotNull($product->brand());
            self::assertCount(2, $product->images());
            foreach ($product->images() as $image) {
                $path = $image->path();
                $directory = str_starts_with($path, '/') ? '/public' : '/assets/';
                self::assertFileExists(self::$kernel->getProjectDir().$directory.$path, 'Demo image must resolve to a shipped local file.');
            }
        }
    }
}
