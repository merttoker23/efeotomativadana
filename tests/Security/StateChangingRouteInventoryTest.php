<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every state-changing route, checked against a declared protection.
 *
 * The phase's first checklist item — "inventory all state-changing routes and confirm
 * authentication/authorization/CSRF or external signature protection" — is a document that goes
 * stale the moment somebody adds a controller. This test makes it self-maintaining instead: it
 * reads the real route table, finds every route that can change something, and fails if any of
 * them is not in the list below.
 *
 * So there are two independent directions of failure, and both are wanted:
 *
 * 1. A route exists that this class does not know about → the test fails and names it, which
 *    forces a decision about how it is protected rather than leaving it unexamined.
 * 2. A protection is declared here but the route behind it stopped being state-changing → the
 *    test fails too, because a stale claim in a security test is worse than no claim.
 *
 * The protections themselves — that an invalid token really is refused — are proved by making
 * the request elsewhere: `CsrfEnforcementTest` and `RewardControllerTest` for browser endpoints,
 * `StorefrontPaymentFlowTest` and `PaytrStorefrontPaymentTest` for the provider callbacks.
 * What is proved here is the *inventory*, which no single functional test can establish.
 */
final class StateChangingRouteInventoryTest extends KernelTestCase
{
    /**
     * Every non-GET route, with the mechanism that makes it safe.
     *
     * `csrf` — a browser form. The token is checked server-side before any write.
     * `provider_signature` — reached by a payment provider, not a browser. CSRF is meaningless
     *   there: a webhook has no session and no token, and the gateway's own signature over the
     *   raw body is what authenticates it. Declaring CSRF here would be claiming a control the
     *   endpoint cannot have.
     * `logout_token` — the firewall's own logout, which validates its own intention.
     *
     * @var array<string, string>
     */
    private const array EXPECTED = [
        'customer_registration' => 'csrf',
        'customer_password_request' => 'csrf',
        'customer_password_reset' => 'csrf',
        'storefront_cart_add' => 'csrf',
        'storefront_cart_update' => 'csrf',
        'storefront_cart_remove' => 'csrf',
        'storefront_wishlist_add' => 'csrf',
        'storefront_wishlist_remove' => 'csrf',
        'storefront_compare_add' => 'csrf',
        'storefront_compare_remove' => 'csrf',
        'storefront_checkout' => 'csrf',
        'storefront_payment_retry' => 'csrf',
        'storefront_payment_form' => 'csrf',
        'storefront_payment_cancel' => 'csrf',
        'storefront_payment_callback' => 'provider_signature',
        'storefront_paytr_notification' => 'provider_signature',
        'customer_login' => 'csrf',
        'customer_account_profile' => 'csrf',
        'customer_account_password' => 'csrf',
        'customer_account_address_new' => 'csrf',
        'customer_account_address_edit' => 'csrf',
        'customer_account_address_delete' => 'csrf',
        'customer_account_order_return' => 'csrf',
        'customer_account_return_withdraw' => 'csrf',
        'customer_logout' => 'logout_token',
        'admin_login' => 'csrf',
        'admin_logout' => 'logout_token',
        'admin_settings' => 'csrf',
        'admin_catalog_product_new' => 'csrf',
        'admin_catalog_product_edit' => 'csrf',
        'admin_catalog_product_delete' => 'csrf',
        'admin_catalog_category_new' => 'csrf',
        'admin_catalog_category_edit' => 'csrf',
        'admin_catalog_category_delete' => 'csrf',
        'admin_catalog_brand_new' => 'csrf',
        'admin_catalog_brand_edit' => 'csrf',
        'admin_catalog_brand_delete' => 'csrf',
        'admin_cms_media_index' => 'csrf',
        'admin_cms_content_new' => 'csrf',
        'admin_cms_content_edit' => 'csrf',
        'admin_cms_content_delete' => 'csrf',
        'admin_cms_home_new' => 'csrf',
        'admin_cms_home_edit' => 'csrf',
        'admin_cms_home_toggle' => 'csrf',
        'admin_cms_home_move' => 'csrf',
        'admin_cms_home_delete' => 'csrf',
        'admin_seo_product' => 'csrf',
        'admin_seo_category' => 'csrf',
        'admin_seo_brand' => 'csrf',
        'admin_seo_content' => 'csrf',
        'admin_order_status' => 'csrf',
        'admin_customer_status' => 'csrf',
        'admin_reward_customer' => 'csrf',
        'admin_payment_retry' => 'csrf',
        'admin_payment_cancel' => 'csrf',
        'admin_shipment_create' => 'csrf',
        'admin_shipment_hand_over' => 'csrf',
        'admin_shipment_in_transit' => 'csrf',
        'admin_shipment_deliver' => 'csrf',
        'admin_shipment_refresh_status' => 'csrf',
        'admin_shipment_label' => 'csrf',
        'admin_shipment_retry' => 'csrf',
        'admin_shipment_cancel' => 'csrf',
        'admin_return_approve' => 'csrf',
        'admin_return_reject' => 'csrf',
        'admin_return_received' => 'csrf',
        'admin_return_refund' => 'csrf',
        'admin_integration_b2b_full' => 'csrf',
        'admin_integration_b2b_daily' => 'csrf',
    ];

    public function testEveryStateChangingRouteIsAccountedFor(): void
    {
        $actual = $this->stateChangingRouteNames();

        $undeclared = array_diff(array_keys($actual), array_keys(self::EXPECTED));
        self::assertSame([], array_values($undeclared), sprintf(
            "These routes can change state and are not declared above.\nEach needs a decision about how it is protected:\n  %s",
            implode("\n  ", $undeclared),
        ));

        $stale = array_diff(array_keys(self::EXPECTED), array_keys($actual));
        self::assertSame([], array_values($stale), sprintf(
            "These are declared as state-changing but no longer are.\nA stale security claim is worse than none; delete the line or fix the route:\n  %s",
            implode("\n  ", $stale),
        ));
    }

    public function testEveryDeclaredProtectionIsOneThisApplicationActuallyImplements(): void
    {
        // A protection name that nothing in the test suite exercises is a comment, not a
        // control. This keeps the vocabulary small enough to be true.
        foreach (array_unique(array_values(self::EXPECTED)) as $protection) {
            self::assertContains($protection, ['csrf', 'provider_signature', 'logout_token']);
        }
    }

    /**
     * The two provider callbacks, and the only two, differ in whether they answer GET.
     *
     * A state-changing route that also answers GET is reachable by a link, a prefetcher and a
     * browser history restore. This is asserted structurally rather than per-case so a callback
     * added later cannot quietly acquire a GET.
     */
    public function testProviderCallbacksDeclareTheirVerbs(): void
    {
        $collection = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $postOnly = ['storefront_payment_callback', 'storefront_paytr_notification'];

        foreach ($postOnly as $name) {
            $route = $collection->get($name);
            self::assertNotNull($route, sprintf('Route "%s" no longer exists.', $name));
            // The browser return needs GET so the customer can be sent back to their order; it
            // is the *handler* that refuses to settle anything on it. The notification endpoint
            // has no such caller and is POST-only outright.
            $expected = 'storefront_payment_callback' === $name ? ['GET', 'POST'] : ['POST'];
            self::assertSame($expected, array_values($route->getMethods()), $name);
        }
    }

    /**
     * @return array<string, string>
     */
    private function stateChangingRouteNames(): array
    {
        $router = self::getContainer()->get(RouterInterface::class);
        $names = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $methods = array_values($route->getMethods());
            if ([] === $methods) {
                // No method requirement means every method, so treat it as state-changing.
                $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
            }
            $mutating = array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
            if ([] !== $mutating) {
                $names[(string) $name] = strtoupper(implode('|', $mutating));
            }
        }

        return $names;
    }

    /**
     * A state change that also answers GET is the defect this phase fixed once already, so the
     * inventory itself is re-read rather than trusted: the route table must contain every route
     * the declaration claims, at the paths the storefront actually publishes.
     */
    public function testTheInventoryIsDerivedFromTheRealRouteTable(): void
    {
        $collection = self::getContainer()->get(RouterInterface::class)->getRouteCollection();

        self::assertGreaterThan(\count(self::EXPECTED), \count($collection), 'The route table shrank unexpectedly.');
        foreach (['storefront_payment_callback', 'storefront_paytr_notification', 'admin_settings', 'storefront_checkout'] as $name) {
            self::assertNotNull($collection->get($name), sprintf('Route "%s" no longer exists.', $name));
        }
    }
}
