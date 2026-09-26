<?php

declare(strict_types=1);

namespace App\Controller\Storefront\Payment;

use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\PaymentCallbackHandler;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PayTR's notification endpoint: the URL registered in the merchant panel as the Bildirim URL.
 *
 * It is called by PayTR's servers, with no session and no cookie, and its only job is to hand the
 * signed post to the payment module. A plain `OK` acknowledges only a result that was processed
 * or already processed. PayTR retries a notification that did not get that receipt, so an
 * internal failure must not be hidden behind an `OK` response.
 */
final class PaytrPaymentController extends AbstractController
{
    #[Route('/odeme/paytr/bildirim', name: 'storefront_paytr_notification', methods: ['POST'])]
    public function notification(
        Request $request,
        PaymentCallbackHandler $handler,
        LoggerInterface $logger,
    ): Response {
        try {
            $result = $handler->handleProviderReference(
                'paytr',
                $this->claimedOrderReference($request),
                new IncomingPaymentCallback(
                    (string) $request->getContent(),
                    $this->singleValueHeaders($request),
                    [],
                ),
            );

            if (!$result->accepted() && !$result->replayed() && 'amount_mismatch' !== $result->reason()) {
                $logger->warning('paytr.notification.rejected', ['reason' => $result->reason()]);

                return new Response('Notification not accepted', 400, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
        } catch (\Throwable $exception) {
            // PayTR must retry this notification; acknowledging a failed database write could
            // leave a charged order permanently unconfirmed.
            $logger->error('paytr.notification.failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return new Response('Notification processing failed', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response('OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * The order reference the notification claims, read only to find the attempt.
     *
     * It is a claim, not a fact: untrusted input that decides nothing on its own, because the
     * gateway's signature check is what authorises any change. Reading it here rather than in the
     * handler keeps the raw body intact for that check.
     */
    private function claimedOrderReference(Request $request): string
    {
        $fields = [];
        parse_str((string) $request->getContent(), $fields);
        $reference = $fields['merchant_oid'] ?? null;

        return is_string($reference) ? trim($reference) : '';
    }

    /**
     * A gateway signature may cover one header value, so a repeated header is reduced to its
     * first value rather than flattened.
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
}
