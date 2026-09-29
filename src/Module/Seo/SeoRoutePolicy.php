<?php

declare(strict_types=1);

namespace App\Module\Seo;

/**
 * Which of the application's routes belong to a person rather than to the catalogue.
 *
 * A route name is the stable identity here, where a path is not: the account area can move
 * under the `/yeni` prefix, and a disallow rule written as a path would then quietly stop
 * matching. Names are also the thing a listener can be given, which is what makes the rule
 * enforceable — a crawler-facing decision that depends on a controller remembering to pass an
 * argument is a decision that will be forgotten on the one route that mattered.
 *
 * The prefixes are deliberately broad. A new `admin_*` or `customer_account_*` route is
 * covered by a name it already has, so adding a screen does not mean re-auditing this list.
 */
final class SeoRoutePolicy
{
    /** @var list<string> */
    public const PRIVATE_ROUTE_PREFIXES = [
        'admin_',
        'customer_account_',
        'customer_login',
        'customer_logout',
        'customer_registration',
        'customer_password_',
        'storefront_cart_',
        'storefront_checkout',
        'storefront_order_',
        'storefront_payment_',
        'storefront_paytr_',
        'storefront_wishlist_',
        'storefront_compare_',
        '_profiler',
        '_wdt',
    ];

    public function isPrivate(?string $routeName): bool
    {
        if (null === $routeName || '' === $routeName) {
            return false;
        }

        foreach (self::PRIVATE_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
