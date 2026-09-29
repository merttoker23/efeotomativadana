<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditActorType;
use App\Module\Audit\AuditContext;
use App\Module\Audit\AuditLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Authentication outcomes, on their own channel and in the audit trail.
 *
 * Two distinct audiences, one event stream:
 *
 * - The `security` log channel exists so a brute-force run is *visible*. Nothing else in this
 *   application reports a failed sign-in: an attacker guessing passwords produces no error, no
 *   failed payment and no audit row anywhere else, so without this the store cannot tell a
 *   targeted attempt from ordinary traffic.
 * - The audit row exists so the same event stays answerable after the log has rotated, and so
 *   it carries the actor, the address and the request id rather than a bare message.
 *
 * Both are best-effort by construction. A row that cannot be written must not turn a wrong
 * password into a 500 — that is precisely the behaviour an attacker measures — so everything
 * here runs inside one try/catch that swallows its own failure.
 *
 * Note what is never recorded: the submitted password, and the exception's own message. The
 * first would make this log a plaintext corpus of every guess ever attempted. The second is
 * written for a login form, is not a stable identifier, and is free text a provider-style
 * failure could put a card number in.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
#[AsEventListener(event: LoginFailureEvent::class)]
#[AsEventListener(event: LogoutEvent::class)]
final class AuthenticationAuditSubscriber
{
    public function __construct(
        private readonly AuditLogger $audit,
        /**
         * The `security` channel, declared rather than inferred.
         *
         * Symfony publishes a named autowiring alias `securityLogger`, so this parameter used to
         * be injected with the right logger by an accident of spelling — which Symfony 8.1
         * deprecates, and rightly. The danger was never the notice: rename this parameter, or
         * re-point the alias, and the listener still compiles while every sign-in record goes to
         * the default channel instead. Then the failed-password stream this class exists to
         * collect disappears, and a brute-force run is indistinguishable from ordinary traffic.
         * `#[Target]` makes the coupling explicit, so the name and the wiring are independent.
         */
        #[Target('securityLogger')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function __invoke(LoginSuccessEvent|LoginFailureEvent|LogoutEvent $event): void
    {
        try {
            match (true) {
                $event instanceof LoginSuccessEvent => $this->succeeded($event),
                $event instanceof LoginFailureEvent => $this->failed($event),
                default => $this->loggedOut($event),
            };
        } catch (\Throwable $failure) {
            $this->securityLogger->warning('An authentication outcome could not be recorded.', [
                'event' => $event::class,
                'exception_class' => $failure::class,
            ]);
        }
    }

    private function succeeded(LoginSuccessEvent $event): void
    {
        $identifier = $event->getUser()->getUserIdentifier();
        $this->securityLogger->info('A sign-in succeeded.', [
            'user' => $identifier,
            'firewall' => $event->getFirewallName(),
        ]);
        $this->audit->record(AuditAction::LoginSucceeded, $identifier, [
            'firewall' => $event->getFirewallName(),
        ]);
    }

    private function failed(LoginFailureEvent $event): void
    {
        // The submitted identifier, taken from the request rather than from a resolved user:
        // a failed sign-in has no user, and "which account is being attacked" is the whole
        // question. It is also public information the attacker already has.
        $identifier = $event->getRequest()->request->getString('_username');
        $context = [
            'firewall' => $event->getFirewallName(),
            'user' => '' === $identifier ? null : $identifier,
            'reason' => self::reason($event->getException()),
        ];

        $this->securityLogger->warning('A sign-in failed.', $context);
        $this->audit->record(
            AuditAction::LoginFailed,
            $context['user'],
            $context,
            context: self::anonymousContext($event->getRequest()->getClientIp(), $event->getRequest()->attributes->getString(RequestIdSubscriber::ATTRIBUTE) ?: null),
        );
    }

    private function loggedOut(LogoutEvent $event): void
    {
        // LogoutEvent carries no firewall name in Symfony 8 — it is dispatched by the
        // firewall's own logout listener, which is already the answer to "which firewall".
        // Rather than guess, the row records the user's identifier and lets the `user` column
        // do the work; an administrator and a customer are told apart by their own record.
        $user = $event->getToken()?->getUser();
        $identifier = $user?->getUserIdentifier();
        $this->securityLogger->info('A sign-out completed.', ['user' => $identifier]);
        $this->audit->record(AuditAction::LoggedOut, $identifier);
    }

    /**
     * A failed sign-in has no authenticated user, so resolving the actor normally would credit
     * the row to nobody — which is true, and is also the answer to "who". The override exists
     * because the *request* is real even when the user is not, and the address it came from is
     * the fact the row exists to record.
     */
    private static function anonymousContext(?string $ipAddress, ?string $requestId): AuditContext
    {
        return new AuditContext(AuditActorType::Anonymous, null, $ipAddress, $requestId);
    }

    /**
     * A human-readable cause drawn from a closed set.
     *
     * `throttled` is the one an operator acts on: it means the limiter stopped the attempt
     * rather than the credentials merely being wrong, and it is the difference between "one
     * person forgot their password" and "one address is trying every password".
     */
    private static function reason(AuthenticationException $exception): string
    {
        return match (true) {
            $exception instanceof TooManyLoginAttemptsAuthenticationException => 'throttled',
            $exception instanceof UserNotFoundException => 'unknown_user',
            default => 'invalid_credentials',
        };
    }
}
