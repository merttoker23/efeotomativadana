<?php

namespace App\Module\Contact;

use Symfony\Component\Validator\Constraints as Assert;

final class ContactData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $firstName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $lastName = '';

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 254)]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: '/\A[+0-9() .-]{5,40}\z/', message: 'Geçerli bir telefon numarası girin.')]
    public string $phone = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 5000)]
    public string $message = '';
}
