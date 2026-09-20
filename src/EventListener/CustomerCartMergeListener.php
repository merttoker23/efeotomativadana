<?php

namespace App\EventListener;

use App\Entity\Customer\CustomerUser;
use App\Module\Cart\CartMerger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener]
final readonly class CustomerCartMergeListener
{
    public function __construct(private CartMerger $merger)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $customer = $event->getUser();
        if ('main' !== $event->getFirewallName() || !$customer instanceof CustomerUser) {
            return;
        }

        if ($this->merger->mergeGuestInto($customer)) {
            $flashBag = $event->getRequest()->getSession()->getBag('flashes');
            if ($flashBag instanceof FlashBagInterface) {
                $flashBag->add(
                    'warning',
                    'Sepetiniz birleştirilirken bazı adetler güncel stok miktarına göre sınırlandı.',
                );
            }
        }
    }
}
