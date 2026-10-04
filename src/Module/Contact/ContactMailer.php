<?php

namespace App\Module\Contact;

use App\Module\Settings\StoreConfiguration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ContactMailer
{
    public function __construct(
        private StoreConfiguration $settings,
        private ValidatorInterface $validator,
        private MailerInterface $mailer,
        #[Autowire(env: 'MAILER_DSN')] private string $mailerDsn,
    ) {
    }

    public function send(ContactData $data): void
    {
        $recipient = $this->settings->contactEmail();
        if (null === $recipient || '' === trim($recipient) || count($this->validator->validate($recipient, new EmailConstraint())) > 0
            || str_contains(strtolower($this->mailerDsn), 'null://')) {
            throw new \DomainException('The contact recipient or mail transport is not configured.');
        }
        $email = (new Email())
            ->from('no-reply@efeotomotivadana.com.tr')
            ->to($recipient)
            ->replyTo($data->email)
            ->subject($this->settings->storeName().' iletişim mesajı')
            ->text(sprintf("Ad: %s\nSoyad: %s\nE-posta: %s\nTelefon: %s\n\n%s", $data->firstName, $data->lastName, $data->email, $data->phone, $data->message));
        $this->mailer->send($email);
    }
}
