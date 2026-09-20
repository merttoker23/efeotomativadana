<?php

declare(strict_types=1);

namespace App\Controller\Storefront\Checkout;

use App\Entity\Customer\CustomerUser;
use App\Module\Cart\CartManager;
use App\Module\Checkout\CheckoutManager;
use App\Module\Checkout\CheckoutSelection;
use App\Module\Checkout\CheckoutViolation;
use App\Module\Order\OrderRepositoryInterface;
use App\Shared\StorefrontPageContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CUSTOMER')]
final class CheckoutController extends AbstractController
{
    #[Route('/odeme', name: 'storefront_checkout', methods: ['GET', 'POST'])]
    public function checkout(Request $request, StorefrontPageContext $context, CheckoutManager $checkout, CartManager $carts): Response
    {
        $customer = $this->customer();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('checkout_place', $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Geçersiz sipariş isteği.');
            }

            try {
                $order = $checkout->place($customer, new CheckoutSelection(
                    $request->request->getInt('shipping_address'),
                    $request->request->getInt('billing_address'),
                    $request->request->getString('shipping_option'),
                    $request->request->getString('payment_option'),
                ));
                $this->addFlash('success', 'Siparişiniz güvenle oluşturuldu.');

                return $this->redirectToRoute('storefront_order_success', ['orderNumber' => $order->orderNumber()]);
            } catch (CheckoutViolation $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('storefront_checkout');
            }
        }

        return $this->render('storefront/checkout/index.html.twig', $context->withLayout([
            'checkout' => $checkout->view($customer),
            'cart' => $carts->view(),
        ]));
    }

    #[Route('/siparis/{orderNumber}/basarili', name: 'storefront_order_success', requirements: ['orderNumber' => 'EOA-\d{8}-[0-9A-F]{12}'], methods: ['GET'])]
    public function success(string $orderNumber, StorefrontPageContext $context, OrderRepositoryInterface $orders): Response
    {
        $order = $orders->findOneByNumberForCustomer($orderNumber, $this->customer());
        if (null === $order) {
            throw $this->createNotFoundException();
        }

        return $this->render('storefront/order/success.html.twig', $context->withLayout(['order' => $order]));
    }

    private function customer(): CustomerUser
    {
        $customer = $this->getUser();
        if (!$customer instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }

        return $customer;
    }
}
