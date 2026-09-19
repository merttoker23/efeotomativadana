<?php

namespace App\Module\Customer;

use Symfony\Component\Validator\Constraints as Assert;

final class PasswordResetRequestData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';
}
