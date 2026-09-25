<?php

namespace App\Tests\Configuration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TestEnvironmentTest extends KernelTestCase
{
    public function testKernelBootsInTestEnvironment(): void
    {
        $kernel = self::bootKernel();

        self::assertSame('test', $kernel->getEnvironment());
    }

    public function testTestDatabaseUsesTheDedicatedDockerDatabase(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $parameters = $connection->getParams();

        self::assertSame('database', $parameters['host'] ?? null);
        $database = $parameters['dbname'] ?? null;
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/^app_test[a-zA-Z0-9_]*$/D', $database);
    }

    public function testDedicatedTestDatabaseAcceptsConnections(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }
}
