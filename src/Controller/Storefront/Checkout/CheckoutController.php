<?php

declare(strict_types=1);

namespace App\Controller\Storefront\Checkout;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Cart\CartManager;
use App\Module\Checkout\CheckoutManager;
use App\Module\Checkout\CheckoutSelection;
use App\Module\Checkout\CheckoutViolation;
use App\Module\Checkout\GatewayPaymentOptionInterface;
use App\Module\Order\OrderRepositoryInterface;
use App\Module\Payment\PaymentInitiationService;
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
    public function checkout(Request $request, StorefrontPageContext $context, CheckoutManager $checkout, CartManager $carts, PaymentInitiationService $payments): Response
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

                // The order is committed before any external payment call. A gateway that is
                // unreachable therefore costs a retry on the payment page, never the order.
                return $this->startPayment($order, $payments);
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
    public function success(string $orderNumber, StorefrontPageContext $context, OrderRepositoryInterface $orders, PaymentInitiationService $payments): Response
    {
        $order = $orders->findOneByNumberForCustomer($orderNumber, $this->customer());
        if (null === $order) {
            throw $this->createNotFoundException();
        }

        // A gateway-backed order whose payment is still open shows the payment page instead of
        // a "thank you": the money has not been collected yet, and saying otherwise would lie.
        if (null !== ($payment = $payments->paymentFor($order)) && $payment->state()->awaitsCallbackDecision()) {
            return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $order->orderNumber()]);
        }

        return $this->render('storefront/order/success.html.twig', $context->withLayout([
            'order' => $order,
            'payment' => $payments->paymentFor($order),
        ]));
    }

    /**
     * A locally verified order has nothing to start; a gateway-backed one is handed to the
     * configured provider. Any failure here leaves the order intact and retryable.
     */
    private function startPayment(CustomerOrder $order, PaymentInitiationService $payments): Response
    {
        if (GatewayPaymentOptionInterface::CHECKOUT_KEY !== $order->paymentOptionKey()) {
            return $this->redirectToRoute('storefront_order_success', ['orderNumber' => $order->orderNumber()]);
        }

        try {
            $start = $payments->startAfterPlacingOrder($order);
        } catch (\Throwable) {
            // The order is already committed at this point, so no failure here may lose it. A
            // misconfigured return address or an unreachable provider both land on the payment
            // page, where the customer can start the payment again. The message is fixed
            // because the underlying error names internal concepts a shopper cannot act on.
            $this->addFlash('error', 'Ödeme başlatılamadı. Siparişiniz oluşturuldu, ödemeyi tekrar deneyebilirsiniz.');

            return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $order->orderNumber()]);
        }

        if ($start->requiresRedirect() && null !== $start->redirectUrl()) {
            return $this->redirect($start->redirectUrl());
        }
        if ($start->isFailed()) {
            $this->addFlash('error', 'Ödeme başlatılamadı. Lütfen tekrar deneyin.');

            return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $order->orderNumber()]);
        }

        return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $order->orderNumber()]);
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
