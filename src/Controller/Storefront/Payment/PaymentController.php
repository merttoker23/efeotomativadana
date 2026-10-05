<?php

declare(strict_types=1);

namespace App\Controller\Storefront\Payment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderRepositoryInterface;
use App\Module\Order\OrderSummary;
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
 * The browser return route is reachable without a session because the provider may redirect
 * after the customer's session has expired. A return token identifies an attempt, but only a
 * signed provider notification can settle PayTR payment state. Customer cancellation remains
 * authenticated and CSRF-protected.
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

        $payment = $initiation->paymentFor($order);

        return $this->render('storefront/payment/show.html.twig', $context->withLayout([
            'order' => $order,
            'payment' => $payment,
            'summary' => OrderSummary::build($order, $payment, null),
        ]));
    }

    #[Route('/odeme/{orderNumber}/yeniden-dene', name: 'storefront_payment_retry', requirements: ['orderNumber' => 'EOA-\d{8}-[0-9A-F]{12}'], methods: ['POST'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function retry(string $orderNumber, Request $request, OrderRepositoryInterface $orders, PaymentInitiationService $initiation, StorefrontPageContext $context): Response
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

        return $this->respondToStart($start, $order, $context);
    }

    /**
     * Renders the form a gateway wants the customer's browser to post, and nothing else.
     *
     * Provider-neutral on purpose: any gateway that answers with a hosted form is rendered by
     * this one template, so a provider's own vocabulary never reaches the storefront. Reached by
     * a POST rather than a link, because building the form starts a payment attempt, and the
     * fields are deliberately not stored — a refresh cannot replay a provider session, and the
     * order page simply offers the action again.
     */
    #[Route('/odeme/{orderNumber}/odeme-formu', name: 'storefront_payment_form', requirements: ['orderNumber' => 'EOA-\d{8}-[0-9A-F]{12}'], methods: ['POST'])]
    #[IsGranted('ROLE_CUSTOMER')]
    public function form(string $orderNumber, Request $request, StorefrontPageContext $context, OrderRepositoryInterface $orders, PaymentInitiationService $initiation): Response
    {
        $order = $orders->findOneByNumberForCustomer($orderNumber, $this->customer());
        if (null === $order) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('payment_form', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz ödeme isteği.');
        }

        try {
            $start = $initiation->retry($order);
        } catch (\DomainException|\InvalidArgumentException|\RuntimeException) {
            $start = null;
        }

        $actionUrl = null === $start ? null : $start->redirectUrl();
        if (null === $start || !$start->isHostedForm() || null === $actionUrl) {
            $this->addFlash('error', 'Ödeme başlatılamadı. Lütfen tekrar deneyin.');

            return $this->redirectToRoute('storefront_payment_show', ['orderNumber' => $order->orderNumber()]);
        }

        return $this->render('storefront/payment/gateway_form.html.twig', $context->withLayout([
            'order' => $order,
            'action_url' => $actionUrl,
            'fields' => $start->hostedFormFields(),
        ]), new Response(headers: ['Cache-Control' => 'no-store, private']));
    }

    /**
     * The provider's return address, and the browser's way back to the order.
     *
     * This route is `PUBLIC_ACCESS` because a provider may redirect the customer after their
     * session has expired, and a server-to-server webhook carries no session at all. Its safety
     * is therefore the 64-hex return token plus the gateway's own signature — never the session.
     *
     * **A GET here can never settle anything, for any provider.** That is why this method
     * branches on the verb rather than trusting the gateway. A GET arrives from an address bar,
     * a prefetcher, a chat client's link preview, a restored browser history or a proxy log
     * somebody later follows; every one of those can be triggered without the customer intending
     * to act, and all of them are replayable. A payment is money moving, so it is only ever
     * applied from a POST — which a provider makes deliberately and a link cannot. PayTR already
     * behaved this way for its own return address; making it true of the route means the next
     * adapter added inherits the rule instead of re-deciding it, which is exactly how a GET that
     * settled money would have arrived.
     */
    #[Route('/odeme/sonuc/{token}', name: 'storefront_payment_callback', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET', 'POST'])]
    public function callback(string $token, Request $request, PaymentCallbackHandler $handler): Response
    {
        $attempt = $handler->attemptFor($token);
        if (!$request->isMethod('POST') || 'paytr' === $attempt?->payment()->providerKey()) {
            // No claim either way about the payment: the browser simply gets back to the order,
            // which shows the state the store actually holds. Saying "payment failed" here would
            // be a lie whenever the provider's notification is still in flight. PayTR may also
            // return the browser by POST; only its separate notification URL settles payments.
            return $this->redirectToOrder($handler, $token);
        }

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

        return $this->redirectToOrder($handler, $token);
    }

    /**
     * Back to the order this attempt belongs to, or to the catalogue when the token addresses
     * nothing. A forged or expired token must not reveal whether that is because the order does
     * not exist, so both answers are the same redirect.
     */
    private function redirectToOrder(PaymentCallbackHandler $handler, string $token): Response
    {
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
     * POST-only and CSRF-protected on purpose. A bare GET or forwarded link must not be able to
     * cancel somebody's in-flight payment. The customer must be logged in and own the order.
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

    private function respondToStart(PaymentStartResult $start, CustomerOrder $order, StorefrontPageContext $context): Response
    {
        $orderNumber = $order->orderNumber();
        if ($start->isRedirect() && null !== $start->redirectUrl()) {
            return $this->redirect($start->redirectUrl());
        }
        if ($start->isHostedForm()) {
            return $this->render('storefront/payment/gateway_form.html.twig', $context->withLayout([
                'order' => $order,
                'action_url' => $start->redirectUrl(),
                'fields' => $start->hostedFormFields(),
            ]), new Response(headers: ['Cache-Control' => 'no-store, private']));
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
