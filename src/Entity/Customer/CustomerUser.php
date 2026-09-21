<?php

namespace App\Entity\Customer;

use App\Repository\Customer\CustomerUserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: CustomerUserRepository::class)]
#[ORM\Table(name: 'customer_user')]
#[ORM\UniqueConstraint(name: 'uniq_customer_user_email', columns: ['email'])]
class CustomerUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (Doctrine assigns the generated integer after insert.)
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $firstName, string $lastName)
    {
        $this->email = self::normalizeEmail($email);
        $this->setProfile($firstName, $lastName, null);
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_CUSTOMER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        if ('' === trim($hashedPassword)) {
            throw new \InvalidArgumentException('Customer password hash cannot be empty.');
        }

        $this->password = $hashedPassword;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function fullName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function setProfile(string $firstName, string $lastName, ?string $phone): void
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ('' === $firstName || '' === $lastName) {
            throw new \InvalidArgumentException('Customer name cannot be empty.');
        }

        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->phone = null === $phone || '' === trim($phone) ? null : trim($phone);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function activate(): void
    {
        $this->active = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        $passwordMatches = $user instanceof self && (
            hash_equals($this->password, $user->password)
            || (8 === strlen($this->password) && hash_equals($this->password, hash('crc32c', $user->password)))
        );

        return $user instanceof self
            && $this->getUserIdentifier() === $user->getUserIdentifier()
            && $passwordMatches
            && $this->active === $user->active;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
