<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\CallbackAuthentication;
use App\Module\Payment\Gateway\GatewayInitiationInstruction;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\GatewayRefundInstruction;
use App\Module\Payment\Gateway\GatewayRefundOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\Gateway\PaymentGatewayInterface;
use App\Module\Payment\SanitizedFailure;
use App\Shared\Money\Money;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PayTR's iFrame integration.
 *
 * PayTR hosts the card form, so the store never sees a PAN or a CVC: the customer enters their
 * card on PayTR's own page, which this application only frames. That is what keeps the store out
 * of PCI-DSS scope, and it is why there is no card field anywhere in this adapter.
 *
 * The flow has two halves. The token request asks PayTR for a short-lived hosted session, and the
 * customer completes the payment on the page that session opens. The result then arrives twice
 * and asynchronously: the customer is redirected to the store's return URL, and PayTR separately
 * posts a signed notification to the store's notification URL. Only the notification carries the
 * signature, so only the notification is allowed to settle anything.
 *
 * Three of PayTR's documented shapes are deliberately not symmetrical, and each is handled once
 * here rather than at the call site: the salt sits in a different place in each of the three
 * tokens, the refund amount is a decimal string while the payment amount is minor units, and
 * `total_amount` legitimately exceeds the order total whenever the customer picks an installment
 * plan.
 */
final readonly class PaytrPaymentGateway implements PaymentGatewayInterface
{
    private const float TIMEOUT = 30.0;

    /** PayTR documents the customer email as at most 100 characters. */
    private const int MAX_EMAIL_LENGTH = 100;
    private const int MAX_NAME_LENGTH = 60;
    private const int MAX_ADDRESS_LENGTH = 400;
    private const int MAX_PHONE_LENGTH = 20;

    /**
     * Three-D Secure stays on. PayTR treats a non-3D payment as a separate entitlement, and
     * skipping it would trade the customer's and the store's fraud protection for nothing.
     */
    private const string NON_3D = '0';

    /** Statuses that mean the request itself was wrong, so retrying cannot help. */
    private const array PERMANENT_HTTP_STATUSES = [400, 401, 403, 404, 405, 415, 422];

    public function __construct(
        private HttpClientInterface $httpClient,
        private PaytrConfiguration $configuration,
        private PaytrCallbackParser $parser,
        private PaytrClientIp $clientIp,
        private LoggerInterface $logger,
    ) {
    }

    public function key(): string
    {
        return 'paytr';
    }

    public function label(): string
    {
        return 'PayTR';
    }

    /**
     * False until the merchant has entered their panel details, so a half-configured store is
     * never offered a payment it could not take, in any environment.
     */
    public function productionReady(): bool
    {
        return $this->configuration->isConfigured();
    }

    /**
     * Builds the form the customer's browser posts straight to PayTR.
     *
     * There is no server-side call here at all. The Direct API has no token step: the store signs
     * the fields, renders them as hidden inputs, and the browser posts them to PayTR together
     * with the card details the customer types. That is why the outcome is a hosted form and not
     * a redirect, and why this method never sees a card number.
     */
    public function initiate(GatewayInitiationInstruction $instruction): GatewayInitiationOutcome
    {
        if (!$this->configuration->isConfigured()) {
            return $this->refuse('provider_not_configured', 'PayTR merchant credentials are not configured.');
        }

        // PayTR rejects a request signed with a private or local address, so this is read per
        // request and never substituted.
        $customerIp = $this->clientIp->customerIp();
        if (null === $customerIp) {
            return $this->refuse(
                'customer_address_unavailable',
                'PayTR requires the customer IP address, which is not available outside a web request.',
            );
        }

        // This API documents all three as mandatory, so an order without them cannot be charged.
        $name = $instruction->customerName();
        $phone = $instruction->customerPhone();
        $address = $instruction->customerAddress();
        if (null === $name || null === $phone || null === $address) {
            return $this->refuse(
                'customer_details_incomplete',
                'PayTR requires the customer name, phone and address, which this order did not capture.',
            );
        }

        try {
            $fields = $this->initiationFields($instruction);
        } catch (PaytrRefusal $refusal) {
            return $this->refuse($refusal->failureCode(), $refusal->getMessage());
        } catch (\InvalidArgumentException $exception) {
            // An order the provider cannot be asked to charge — an unsupported currency, an
            // over-long reference — is this request's own fault, so it is refused outright
            // rather than turned into a failure a retry could paper over.
            return $this->refuse('unsupported_payment_request', $exception->getMessage());
        }

        $this->logger->info('paytr.payment.form_prepared', [
            'order_reference' => $fields['merchant_oid'],
            'amount' => $fields['payment_amount'],
            'currency' => $fields['currency'],
            'test_mode' => $this->configuration->testMode(),
        ]);

        // The reference is PayTR's own order id, which is also what its refund API is keyed on.
        return GatewayInitiationOutcome::hostedForm(
            $this->configuration->paymentUrl(),
            $fields,
            $fields['merchant_oid'],
        );
    }

    /**
     * The exact field set PayTR documents for the Direct API, signed the way it verifies.
     *
     * The hash order is part of the contract and differs from the hosted token endpoint's:
     * `payment_type` and `installment_count` sit where the basket used to, `non_3d` is appended,
     * and the amount is a dot decimal rather than minor units. No card field appears here — those
     * are typed by the customer into the form this page renders, and posted by their browser
     * straight to PayTR.
     *
     * @return array<string, string>
     */
    private function initiationFields(GatewayInitiationInstruction $instruction): array
    {
        $customerIp = $this->clientIp->customerIp();
        if (null === $customerIp) {
            throw new PaytrRefusal(
                'customer_address_unavailable',
                'PayTR requires the customer IP address, which is not available outside a web request.',
            );
        }

        // This API documents all three as mandatory, so an order without them cannot be charged.
        $name = $instruction->customerName();
        $phone = $instruction->customerPhone();
        $address = $instruction->customerAddress();
        if (null === $name || null === $phone || null === $address) {
            throw new PaytrRefusal(
                'customer_details_incomplete',
                'PayTR requires the customer name, phone and address, which this order did not capture.',
            );
        }

        $reference = PaytrOrderReference::forAttempt($instruction->orderNumber(), $instruction->attemptSequence());
        $email = $this->providerEmail($instruction->customerEmail());
        $amount = PaytrAmount::decimal($instruction->amount());
        $currency = PaytrAmount::wireCurrency($instruction->amount()->currency());
        $basket = PaytrBasket::encode($instruction->basketLines(), $reference, $amount);
        $testMode = $this->configuration->testModeFlag();

        $hashString = $this->configuration->merchantId().$customerIp.$reference.$email
            .$amount.'card'.'0'.$currency.$testMode.self::NON_3D;

        return [
            'merchant_id' => $this->configuration->merchantId(),
            'paytr_token' => $this->configuration->signature()->initiationToken($hashString),
            'user_ip' => $customerIp,
            'merchant_oid' => $reference,
            'email' => $email,
            'payment_type' => 'card',
            'payment_amount' => $amount,
            'installment_count' => '0',
            'currency' => $currency,
            'test_mode' => $testMode,
            'non_3d' => self::NON_3D,
            'non3d_test_failed' => '0',
            'card_type' => '',
            'client_lang' => $this->providerLanguage($instruction->locale()),
            'user_name' => mb_substr($name, 0, self::MAX_NAME_LENGTH),
            'user_address' => mb_substr($address, 0, self::MAX_ADDRESS_LENGTH),
            'user_phone' => mb_substr($phone, 0, self::MAX_PHONE_LENGTH),
            'user_basket' => $basket,
            'debug_on' => '0',
            'merchant_ok_url' => $instruction->returnUrl(),
            'merchant_fail_url' => $instruction->cancelUrl(),
        ];
    }

    public function authenticateCallback(IncomingPaymentCallback $callback): CallbackAuthentication
    {
        $report = $this->parser->parse($callback->rawBody());
        if (null === $report) {
            return CallbackAuthentication::rejected('malformed_notification');
        }

        if (!$this->configuration->isConfigured()) {
            return CallbackAuthentication::rejected('provider_not_configured');
        }

        // Verified before anything in the body is believed, and over the values exactly as they
        // were sent: re-formatting an amount or re-casing a status would produce another hash.
        if (!$this->configuration->signature()->callbackHashMatches(
            $report->merchantOid(),
            $report->status(),
            $report->totalAmountAsSent(),
            $report->hash(),
        )) {
            $this->logger->warning('paytr.payment.callback.signature_rejected', [
                'order_reference' => $report->merchantOid(),
            ]);

            return CallbackAuthentication::rejected('signature_mismatch');
        }

        if (!$report->isSuccess()) {
            $this->logger->info('paytr.payment.callback.failed', [
                'order_reference' => $report->merchantOid(),
                'failed_reason_code' => $report->failedReasonCode(),
                'failed_reason' => PaytrFailureReason::fromNotification(
                    $report->failedReasonCode(),
                    $report->failedReasonMessage(),
                )->message(),
            ]);

            return CallbackAuthentication::authentic($report->merchantOid(), 'failed', null);
        }

        return $this->capturedAmount($report);
    }

    public function refund(GatewayRefundInstruction $instruction): GatewayRefundOutcome
    {
        if (!$this->configuration->isConfigured()) {
            return GatewayRefundOutcome::failed(SanitizedFailure::fromProvider(
                'provider_not_configured',
                'PayTR merchant credentials are not configured.',
                null,
            ));
        }

        $reference = trim($instruction->providerReference());

        try {
            $returnAmount = PaytrAmount::decimal($instruction->amount());
        } catch (\InvalidArgumentException $exception) {
            // A currency this provider cannot express is a permanent refusal, not an error the
            // admin screen should turn into a 500 with no record of what was attempted.
            return GatewayRefundOutcome::failed(SanitizedFailure::fromProvider(
                'unsupported_refund_amount',
                $exception->getMessage(),
                null,
            ));
        }

        $fields = [
            'merchant_id' => $this->configuration->merchantId(),
            'merchant_oid' => $reference,
            'return_amount' => $returnAmount,
            'paytr_token' => $this->configuration->signature()->refundToken(
                $this->configuration->merchantId(),
                $reference,
                $returnAmount,
            ),
        ];

        try {
            $payload = $this->post($this->configuration->refundUrl(), $fields);
        } catch (PaytrUnavailable) {
            return GatewayRefundOutcome::failed(SanitizedFailure::fromProvider(
                'timeout',
                'PayTR could not be reached while refunding.',
                null,
            ));
        }

        if ('success' === ($payload['status'] ?? null)) {
            $this->logger->info('paytr.refund.completed', [
                'order_reference' => $reference,
                'return_amount' => $returnAmount,
            ]);

            return GatewayRefundOutcome::completed($reference);
        }

        $errorNumber = is_scalar($payload['err_no'] ?? null) ? trim((string) $payload['err_no']) : '';
        $errorMessage = is_scalar($payload['err_msg'] ?? null) ? (string) $payload['err_msg'] : null;

        $this->logger->warning('paytr.refund.refused', [
            'order_reference' => $reference,
            'return_amount' => $returnAmount,
            'err_no' => $errorNumber,
        ]);

        return GatewayRefundOutcome::failed(SanitizedFailure::fromProvider(
            '' === $errorNumber ? 'refund_failed' : 'paytr_error_'.$errorNumber,
            $errorMessage,
            'PayTR refused the refund request.',
        ));
    }

    /**
     * PayTR's iFrame flow captures as part of the payment, so there is never a separate
     * authorization left holding funds. Returning null is the documented way to say so.
     */
    public function releaseAuthorization(GatewayRefundInstruction $instruction): ?GatewayRefundOutcome
    {
        return null;
    }

    /**
     * A capture is only accepted when the store can reconcile it, and every figure used here is
     * one PayTR actually signed.
     *
     * The signature covers `merchant_oid`, `status` and `total_amount` only. `payment_amount`
     * and `currency` arrive outside that set, so neither is allowed to decide what was paid:
     * the collected figure comes from the signed `total_amount`, and the currency is used only
     * to express it. An amount below the signed one is the inconsistent direction worth refusing.
     */
    private function capturedAmount(PaytrCallbackReport $report): CallbackAuthentication
    {
        if (null === $report->currency()) {
            return CallbackAuthentication::rejected('unknown_currency');
        }
        // A simulated capture must never be allowed to mark a real order paid, so a notification
        // that says it is a test is refused unless this store is itself in test mode.
        if ($report->isTestMode() && !$this->configuration->testMode()) {
            $this->logger->warning('paytr.payment.callback.test_capture_refused', [
                'order_reference' => $report->merchantOid(),
            ]);

            return CallbackAuthentication::rejected('test_capture_in_live_mode');
        }

        $captured = Money::ofMinor($report->totalAmountMinor(), $report->currency());
        if ($captured->isZero()) {
            return CallbackAuthentication::rejected('empty_capture');
        }
        // A total below the order amount the provider echoes can only be inconsistent; a total
        // above it is the customer paying more, which an instalment plan legitimately causes.
        if (null !== $report->paymentAmountMinor() && $report->totalAmountMinor() < $report->paymentAmountMinor()) {
            return CallbackAuthentication::rejected('inconsistent_amount');
        }

        return CallbackAuthentication::authentic($report->merchantOid(), 'succeeded', $captured);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<mixed> the decoded response body
     *
     * @throws PaytrUnavailable when the provider is unreachable or answered in a retryable way
     */
    private function post(string $url, array $fields): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => http_build_query($fields, '', '&', \PHP_QUERY_RFC1738),
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
            ]);
            $statusCode = $response->getStatusCode();
        } catch (HttpExceptionInterface $exception) {
            $this->logger->warning('paytr.http.transport_failure', [
                'endpoint' => $url,
                'exception' => $exception::class,
            ]);

            throw new PaytrUnavailable('PayTR could not be reached.', 0, $exception);
        }

        // The status is judged before the body is read: an error page is not a provider payload,
        // and a provider that is unwell must not be recorded as a refused request.
        if ($statusCode >= 400) {
            if (!$this->isRetryableStatus($statusCode)) {
                $this->logger->warning('paytr.http.rejected', ['endpoint' => $url, 'status' => $statusCode]);

                return ['status' => 'error', 'err_msg' => 'PayTR refused the request.'];
            }

            $this->logger->warning('paytr.http.unexpected_status', ['endpoint' => $url, 'status' => $statusCode]);

            throw new PaytrUnavailable('PayTR is temporarily unavailable.');
        }

        try {
            $payload = $response->toArray(false);
        } catch (DecodingExceptionInterface|TransportExceptionInterface) {
            // An error page is not a payload, and a body that stalls mid-stream raises a timeout
            // rather than a decoding error. Neither is a reason to let the caller see an
            // exception where a retryable failure belongs.
            $payload = null;
        }

        if (!\is_array($payload)) {
            $this->logger->warning('paytr.http.unreadable_body', ['endpoint' => $url]);

            return ['status' => 'error', 'reason' => 'PayTR returned a response that could not be read.'];
        }

        return $payload;
    }

    private function isRetryableStatus(int $statusCode): bool
    {
        return !in_array($statusCode, self::PERMANENT_HTTP_STATUSES, true);
    }

    private function refuse(string $code, string $message): GatewayInitiationOutcome
    {
        $this->logger->warning('paytr.payment.refused', ['code' => $code]);

        return GatewayInitiationOutcome::failed(SanitizedFailure::fromProvider($code, $message, null));
    }

    /**
     * PayTR documents the email as at most 100 characters.
     *
     * The initiation instruction has already rejected an address that is not a valid email, and
     * that validation is ASCII-only, so no transliteration is needed or wanted here: silently
     * rewriting a customer's address would only risk delivering a receipt elsewhere.
     */
    private function providerEmail(string $email): string
    {
        return mb_substr(trim($email), 0, self::MAX_EMAIL_LENGTH);
    }

    private function providerLanguage(string $locale): string
    {
        return str_starts_with(mb_strtolower(trim($locale)), 'en') ? 'en' : 'tr';
    }
}
