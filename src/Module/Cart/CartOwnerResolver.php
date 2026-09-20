<?php

namespace App\Module\Cart;

use App\Entity\Commerce\Cart;
use App\Entity\Customer\CustomerUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class CartOwnerResolver
{
    public const SESSION_KEY = 'storefront_guest_cart_token';

    public function __construct(
        private CartRepositoryInterface $carts,
        private RequestStack $requests,
        private Security $security,
    ) {
    }

    public function current(): ?Cart
    {
        $customer = $this->customer();
        if (null !== $customer) {
            return $this->carts->findOneByCustomer($customer);
        }

        $token = $this->guestToken();

        return null !== $token ? $this->carts->findOneByGuestToken($token) : null;
    }

    public function getOrCreate(): Cart
    {
        $cart = $this->current();
        if (null !== $cart) {
            return $cart;
        }

        $customer = $this->customer();
        if (null !== $customer) {
            $cart = new Cart($customer);
        } else {
            $token = bin2hex(random_bytes(32));
            $this->requests->getSession()->set(self::SESSION_KEY, $token);
            $cart = new Cart(guestToken: $token);
        }

        $this->carts->save($cart);

        return $cart;
    }

    public function guestToken(): ?string
    {
        $token = $this->requests->getSession()->get(self::SESSION_KEY);

        return is_string($token) ? $token : null;
    }

    public function forgetGuestToken(): void
    {
        $this->requests->getSession()->remove(self::SESSION_KEY);
    }

    private function customer(): ?CustomerUser
    {
        $user = $this->security->getUser();

        return $user instanceof CustomerUser ? $user : null;
    }
}
