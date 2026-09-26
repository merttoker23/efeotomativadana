<?php

declare(strict_types=1);

namespace App\Module\Customer;

use App\Entity\Customer\CustomerUser;
use App\Shared\PublicUrlGenerator;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends the single-use password reset link.
 *
 * The link is built from the configured public base URI, never from the incoming request. That
 * matters for correctness and for safety: behind a TLS-terminating proxy the request scheme is
 * often plain http, and a `Host` header is attacker-controlled — so a link generated from the
 * request could put a genuine, correctly signed reset token on a host of somebody else's
 * choosing, which is account takeover rather than a cosmetic bug.
 */
final readonly class PasswordResetMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private PublicUrlGenerator $urls,
    ) {
    }

    public function send(CustomerUser $customer, IssuedPasswordReset $reset): void
    {
        $url = $this->urls->absolute('customer_password_reset', ['token' => $reset->rawToken]);
        $email = (new Email())
            ->from('no-reply@efeotomotivadana.com.tr')
            ->to($customer->getUserIdentifier())
            ->subject('Efe Otomotiv Adana parola sıfırlama')
            ->text("Parolanızı sıfırlamak için bu tek kullanımlık bağlantıyı açın:\n\n".$url."\n\nBağlantı bir saat geçerlidir.");

        $this->mailer->send($email);
    }
}
