<?php

declare(strict_types=1);

namespace App\Controller\Storefront\Payment;

use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderRepositoryInterface;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\PaymentCallbackHandler;
use App\Module\Payment\PaymentInitiationService;
use App\Module\Payment\PaymentStartResult;
use App\Shared\StorefrontPageContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The customer-facing payment entry points.
 *
 * The callback and cancel routes are intentionally reachable without a session: a provider
 * redirect arrives in the customer's browser and a server-to-server webhook arrives with no
 * session at all. Their safety comes from the unguessable 64-hex return token in the path
 * plus the gateway's own signature verification — never from authentication.
 */
final class PaymentController extends AbstractController
{
    #[Route('/odeme/{orderNumber}', name: 'storefront_payment_show', requirements: ['orderNumber' => 'EOA-\d{8}-[0-9A-F]{12}'], methods: ['GET'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function show(string $orderNumber, StorefrontPageContext $context, OrderRepositoryInterface $orders, PaymentInitiationService $initiation): Response
    {
        $order = $orders->findOneByNumberForCustomer($orderNumber, $this->customer());
        if (null === $order) {
            throw $this->createNotFoundException();
        }

        return $this->render('storefront/payment/show.html.twig', $context->withLayout([
            'order' => $order,
            'payment' => $initiation->paymentFor($order),
        ]));
    }

    #[Route('/odeme/{orderNumber}/yeniden-dene', name: 'storefront_payment_retry', requirements: ['orderNumber' => 'EOA-\d{8}-[0-9A-F]{12}'], methods: ['POST'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function retry(string $orderNumber, Request $request, OrderRepositoryInterface $orders, PaymentInitiationService $initiation): Response
    {
        $order = $orders->findOneByNumberForCustomer($orderNumber, $this->customer());
        if (null === $order) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('payment_retry', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz ödeme isteği.');
        }

        try {
            $start = $initiation->retry($order);
        } catch (\DomainException|\InvalidArgumentException|\RuntimeException $exception) {
            // Customers are never shown a domain message: those are written for operators and
            // name internal concepts. They see a plain explanation instead.
            $this->addFlash('error', 'Ödeme yeniden başlatılamadı. Lütfen birazdan tekrar deneyin.');

            return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $order->orderNumber()]);
        }

        return $this->respondToStart($start, $order->orderNumber());
    }

    #[Route('/odeme/sonuc/{token}', name: 'storefront_payment_callback', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET', 'POST'])]
    public function callback(string $token, Request $request, PaymentCallbackHandler $handler): Response
    {
        $result = $handler->handle($token, new IncomingPaymentCallback(
            (string) $request->getContent(),
            $this->singleValueHeaders($request),
            $this->scalarQuery($request),
            $token,
        ));

        if ($result->accepted()) {
            $this->addFlash('success', 'Ödemeniz alındı. Teşekkür ederiz.');
        } elseif ($result->replayed()) {
            $this->addFlash('success', 'Bu bildirim daha önce işlenmişti.');
        } elseif ('amount_mismatch' === $result->reason()) {
            $this->addFlash('error', 'Ödeme tutarı sipariş tutarıyla eşleşmiyor. Ekibimiz sizinle iletişime geçecek.');
        } else {
            $this->addFlash('error', 'Ödeme doğrulanamadı.');
        }

        $attempt = $handler->attemptFor($token);
        if (null !== $attempt) {
            $this->addFlash('payment_order_number', $attempt->orderNumber());

            return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $attempt->orderNumber()]);
        }

        return $this->redirectToRoute('storefront_catalog_index');
    }

    /**
     * The customer walked away at the provider.
     *
     * POST-only and CSRF-protected on purpose. This URL is handed to the provider and then
     * travels through the address bar, browser history and proxy logs, so a bare GET — or a
     * forwarded link — must not be able to cancel somebody's in-flight payment. The customer
     * must still be logged in and must own the order.
     */
    #[Route('/odeme/iptal/{token}', name: 'storefront_payment_cancel', requirements: ['token' => '[0-9a-f]{64}'], methods: ['POST'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function cancel(string $token, Request $request, OrderRepositoryInterface $orders, PaymentInitiationService $initiation): Response
    {
        $submitted = $request->request->all('payment_cancel');
        $csrfToken = is_string($submitted['_token'] ?? null) ? $submitted['_token'] : '';
        if (!$this->isCsrfTokenValid('payment_cancel', $csrfToken)) {
            throw new AccessDeniedHttpException('Geçersiz ödeme isteği.');
        }
        $attempt = $initiation->attemptForReturnToken($token);
        if (null === $attempt) {
            throw $this->createNotFoundException();
        }
        // A foreign token is indistinguishable from a nonexistent one.
        if (null === $orders->findOneByNumberForCustomer($attempt->orderNumber(), $this->customer())) {
            throw $this->createNotFoundException();
        }

        $initiation->markAbandoned($attempt);
        $this->addFlash('error', 'Ödemeniz iptal edildi. Siparişiniz hâlâ sizin için saklanıyor.');

        return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $attempt->orderNumber()]);
    }

    /**
     * A gateway signature covers one header value. A repeated header is reduced to its first
     * value rather than being flattened, so a smuggled duplicate cannot be silently joined
     * into something the gateway would have signed differently.
     *
     * @return array<string, string>
     */
    private function singleValueHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[(string) $name] = (string) (reset($values) ?: '');
        }

        return $headers;
    }

    /**
     * A gateway signature covers the query string as sent. A repeated parameter is therefore
     * dropped rather than joined, so a smuggled duplicate cannot be read as one value the
     * provider would have signed differently.
     *
     * @return array<string, string>
     */
    private function scalarQuery(Request $request): array
    {
        $query = [];
        foreach ($request->query->all() as $name => $value) {
            if (is_scalar($value)) {
                $query[(string) $name] = (string) $value;
            }
        }

        return $query;
    }

    private function respondToStart(PaymentStartResult $start, string $orderNumber): Response
    {
        if ($start->requiresRedirect() && null !== $start->redirectUrl()) {
            return $this->redirect($start->redirectUrl());
        }
        if ($start->isFailed()) {
            $this->addFlash('error', 'Ödeme başlatılamadı. Lütfen tekrar deneyin.');

            return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $orderNumber]);
        }

        return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $orderNumber]);
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
