<?php

namespace App\Module\Customer;

use Symfony\Component\Validator\Constraints as Assert;

final class PasswordChangeData
{
    #[Assert\NotBlank]
    public string $currentPassword = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 4096, minMessage: 'Parola en az {{ limit }} karakter olmalıdır.')]
    public string $newPassword = '';
}
