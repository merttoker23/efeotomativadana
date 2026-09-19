<?php
namespace App\Module\Customer;
use App\Entity\Customer\CustomerUser;
use App\Entity\Customer\PasswordResetToken;
use App\Repository\Customer\PasswordResetTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
final readonly class PasswordResetManager
{
    public function __construct(private PasswordResetTokenRepository $tokens, private EntityManagerInterface $entityManager) {}
    public function issue(CustomerUser $customer): IssuedPasswordReset
    {
        $now = new \DateTimeImmutable();
        $this->tokens->expireUnusedFor($customer, $now);
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = $now->add(new \DateInterval('PT1H'));
        $this->entityManager->persist(new PasswordResetToken($customer, hash('sha256', $rawToken), $expiresAt, $now));
        $this->entityManager->flush();
        return new IssuedPasswordReset($rawToken, $expiresAt);
    }
}
