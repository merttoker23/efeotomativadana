<?php
namespace App\Module\Customer;
use App\Entity\Customer\CustomerUser;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
final readonly class PasswordResetMailer
{
    public function __construct(private MailerInterface $mailer, private UrlGeneratorInterface $urls) {}
    public function send(CustomerUser $customer, IssuedPasswordReset $reset): void
    {
        $url = $this->urls->generate('customer_password_reset', ['token' => $reset->rawToken], UrlGeneratorInterface::ABSOLUTE_URL);
        $email = (new Email())->from('no-reply@efeotomotivadana.com.tr')->to($customer->getUserIdentifier())->subject('Efe Otomotiv Adana parola sıfırlama')->text("Parolanızı sıfırlamak için bu tek kullanımlık bağlantıyı açın:\n\n".$url."\n\nBağlantı bir saat geçerlidir.");
        $this->mailer->send($email);
    }
}
