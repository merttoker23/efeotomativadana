<?php

namespace App\Repository\Customer;

use App\Entity\Customer\PasswordResetToken;
use App\Entity\Customer\CustomerUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PasswordResetToken> */
final class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findByRawToken(string $rawToken): ?PasswordResetToken
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $rawToken)]);
    }

    public function expireUnusedFor(CustomerUser $customer, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('token')
            ->update()
            ->set('token.expiresAt', ':now')
            ->where('token.customer = :customer')
            ->andWhere('token.usedAt IS NULL')
            ->setParameter('customer', $customer)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
