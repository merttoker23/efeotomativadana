<?php

namespace App\Module\Cart;

use App\Entity\Commerce\Cart;
use App\Entity\Customer\CustomerUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class CartOwnerResolver implements \Symfony\Contracts\Service\ResetInterface
{
    public const SESSION_KEY = 'storefront_guest_cart_token';

    /**
     * `false` means "not looked up yet"; `null` is a real answer, namely "this visitor has no
     * cart". Distinguishing the two is what lets the storefront header's cart summary and the
     * cart page's own view share one lookup instead of each issuing the same SELECT.
     */
    private bool $resolved = false;
    private ?Cart $cached = null;

    public function __construct(
        private CartRepositoryInterface $carts,
        private RequestStack $requests,
        private Security $security,
    ) {
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->cached = null;
    }

    public function current(): ?Cart
    {
        if ($this->resolved) {
            return $this->cached;
        }

        $this->resolved = true;

        return $this->cached = $this->resolve();
    }

    private function resolve(): ?Cart
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

        $this->resolved = true;
        $this->cached = $cart;

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
