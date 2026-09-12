<?php

namespace App\Tests\Command;

use App\Entity\Customer\AdminUser;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateAdminUserCommandTest extends KernelTestCase
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

    public function testCommandCreatesHashedAdministratorWithoutExposingPassword(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['VeryStrong!123', 'VeryStrong!123']);

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'ADMIN@Example.COM']));

        $repository = self::getContainer()->get(ManagerRegistry::class)->getRepository(AdminUser::class);
        $user = $repository->findOneBy(['email' => 'admin@example.com']);

        self::assertInstanceOf(AdminUser::class, $user);
        self::assertSame('admin@example.com', $user->getUserIdentifier());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertNotSame('VeryStrong!123', $user->getPassword());
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'VeryStrong!123'),
        );
        self::assertStringNotContainsString('VeryStrong!123', $tester->getDisplay());
    }

    public function testCommandRejectsDuplicateAdministratorEmail(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['VeryStrong!123', 'VeryStrong!123']);
        self::assertSame(Command::SUCCESS, $tester->execute(['email' => 'admin@example.com']));

        $duplicate = $this->commandTester();
        self::assertSame(Command::FAILURE, $duplicate->execute(['email' => 'ADMIN@example.com']));
        self::assertStringContainsString('already exists', $duplicate->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:admin:create'));
    }
}
