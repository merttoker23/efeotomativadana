<?php

namespace App\Twig;

use App\Module\Cart\CartManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class StorefrontCartExtension extends AbstractExtension
{
    public function __construct(private readonly CartManager $carts)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [new TwigFunction('storefront_cart', $this->carts->summary(...))];
    }
}
