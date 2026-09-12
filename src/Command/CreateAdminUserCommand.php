<?php

namespace App\Command;

use App\Entity\Customer\AdminUser;
use App\Repository\Customer\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:admin:create',
    description: 'Provision an administrator with a securely prompted password.',
)]
final class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly AdminUserRepository $administrators,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Administrator email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = AdminUser::normalizeEmail((string) $input->getArgument('email'));

        $emailViolations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        if (count($emailViolations) > 0) {
            $io->error((string) $emailViolations);

            return self::FAILURE;
        }

        if (null !== $this->administrators->findOneByEmail($email)) {
            $io->error(sprintf('An administrator with email "%s" already exists.', $email));

            return self::FAILURE;
        }

        $password = $io->askHidden('Password (minimum 12 characters)', static function (mixed $value): string {
            if (!is_string($value) || mb_strlen($value) < 12) {
                throw new \RuntimeException('The password must contain at least 12 characters.');
            }

            return $value;
        });
        $confirmation = $io->askHidden('Confirm password');

        if (!is_string($password) || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
            $io->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $administrator = new AdminUser($email);
        $administrator->setPassword($this->passwordHasher->hashPassword($administrator, $password));
        $this->entityManager->persist($administrator);
        $this->entityManager->flush();

        $io->success(sprintf('Administrator "%s" created.', $email));

        return self::SUCCESS;
    }
}
