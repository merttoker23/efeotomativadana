<?php

declare(strict_types=1);

namespace App\Module\Audit;

use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\EventListener\RequestIdSubscriber;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Reads the current actor and request from the running process.
 *
 * A service rather than something each caller passes, because "who did this" must not depend
 * on the caller remembering. It answers for three kinds of execution: an HTTP request with a
 * signed-in staff member or customer, an HTTP request with nobody signed in, and a console
 * command or Messenger handler where there is no request at all.
 */
final readonly class AuditContextResolver
{
    public function __construct(
        private RequestStack $requests,
        private TokenStorageInterface $tokens,
    ) {
    }

    public function resolve(): AuditContext
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request) {
            // A console command, a Messenger handler or a scheduled run. There is no person to
            // attribute and inventing one would be worse than admitting the actor is the system.
            return new AuditContext(AuditActorType::System, null, null, null);
        }

        return new AuditContext(
            $this->actorType($this->currentUser()),
            $this->currentUser()?->getUserIdentifier(),
            $request->getClientIp(),
            $request->attributes->getString(RequestIdSubscriber::ATTRIBUTE) ?: null,
        );
    }

    private function currentUser(): ?UserInterface
    {
        $token = $this->tokens->getToken();

        return null === $token ? null : $token->getUser();
    }

    /**
     * A staff member, a customer, or nobody.
     *
     * The classes are checked rather than the roles, because the two firewalls here have
     * separate user classes and an entity provider keyed on the wrong one would authenticate
     * the wrong table.
     */
    private function actorType(?UserInterface $user): AuditActorType
    {
        return match (true) {
            null === $user => AuditActorType::Anonymous,
            $user instanceof AdminUser => AuditActorType::Administrator,
            $user instanceof CustomerUser => AuditActorType::Customer,
            default => AuditActorType::Anonymous,
        };
    }
}
