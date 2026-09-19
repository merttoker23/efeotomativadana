<?php
namespace App\Module\Customer;
final readonly class IssuedPasswordReset
{
    public function __construct(public string $rawToken, public \DateTimeImmutable $expiresAt) {}
}
