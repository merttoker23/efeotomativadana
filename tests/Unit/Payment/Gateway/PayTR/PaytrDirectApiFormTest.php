<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\GatewayInitiationInstruction;
use App\Module\Payment\Gateway\PayTR\PaytrBasket;
use App\Module\Payment\Gateway\PayTR\PaytrPaymentGateway;
use App\Module\Payment\Gateway\PayTR\PaytrSignature;
use App\Shared\Money\Money;
use App\Tests\Fixtures\Payment\PaymentRouterFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PayTR's Direct API, which is the product this merchant account is actually entitled to.
 *
 * The flow is different in kind from the hosted iFrame one: there is no server-side token call
 * at all. The store builds the fields, and the customer's browser posts them — together with the
 * card details — straight to PayTR. So the outcome is a hosted form, and the assertions here are
 * about the exact field set and the exact hash PayTR verifies.
 */
final class PaytrDirectApiFormTest extends TestCase
{
    private const string MERCHANT_ID = '123456';
    private const string MERCHANT_KEY = 'testKey';
    private const string MERCHANT_SALT = 'testSalt';
    private const string ORDER_NUMBER = 'EOA-20260925-ABCDEF123456';
    /** PayTR's own reference: the order number without hyphens, plus the attempt identity. */
    private const string ORDER_REFERENCE = 'EOA20260925ABCDEF123456A1';
    private const string RETURN_TOKEN = 'a000000000000000000000000000000000000000000000000000000000000000';
    private const string PAYMENT_URL = 'https://www.paytr.com/odeme';

    /** The token PayTR computes for exactly the field set asserted below. */
    private const string EXPECTED_TOKEN = 'SH1UrBm6E9Iy30f+VVRq/T5e0H2+Qrwwj0RA1oj+Kws=';

    public function testTheCustomerIsSentToPaytrsOwnPaymentEndpointWithAForm(): void
    {
        $outcome = $this->gateway()->initiate($this->instruction());

        self::assertTrue($outcome->isHostedForm());
        self::assertFalse($outcome->isRedirect());
        self::assertSame(self::PAYMENT_URL, $outcome->hostedFormActionUrl());
    }

    public function testTheFormCarriesTheOrderReferenceSoTheNotificationCanBeMatchedBack(): void
    {
        $outcome = $this->gateway()->initiate($this->instruction());

        self::assertSame(self::ORDER_REFERENCE, $outcome->providerReference());
    }

    public function testTheFormCarriesTheDocumentedFields(): void
    {
        $fields = $this->fields();

        self::assertSame(self::MERCHANT_ID, $fields['merchant_id']);
        self::assertSame('88.99.140.12', $fields['user_ip']);
        self::assertSame(self::ORDER_REFERENCE, $fields['merchant_oid']);
        self::assertSame('musteri@example.com', $fields['email']);
        self::assertSame('card', $fields['payment_type']);
        self::assertSame('0', $fields['installment_count']);
        self::assertSame('TL', $fields['currency']);
        self::assertSame('0', $fields['non_3d']);
        self::assertSame('0', $fields['non3d_test_failed']);
        self::assertSame('tr', $fields['client_lang']);
        self::assertSame('https://store.test/odeme/sonuc/'.self::RETURN_TOKEN, $fields['merchant_ok_url']);
        self::assertSame('https://store.test/odeme/iptal/'.self::RETURN_TOKEN, $fields['merchant_fail_url']);
    }

    public function testTheAmountIsSentAsADotDecimalBecauseThisApiExpectsThat(): void
    {
        // The Direct API takes "1599.90" here, where the token endpoint took 159990. Sending the
        // minor-unit integer would be accepted and would move a hundredfold too much.
        self::assertSame('1599.90', $this->fields()['payment_amount']);
    }

    public function testTheTokenIsSignedExactlyTheWayThisApiVerifiesIt(): void
    {
        $fields = $this->fields();

        $hashString = self::MERCHANT_ID.'88.99.140.12'.self::ORDER_REFERENCE.'musteri@example.com'
            .'1599.90'.'card'.'0'.'TL'.'1'.'0';

        self::assertSame(self::EXPECTED_TOKEN, $fields['paytr_token']);
        self::assertSame(
            (new PaytrSignature(self::MERCHANT_KEY, self::MERCHANT_SALT))->initiationToken($hashString),
            $fields['paytr_token'],
        );
    }

    public function testTheBasketIsPlainJsonBecauseThisApiDoesNotUseTheBase64Form(): void
    {
        $basket = $this->fields()['user_basket'];

        self::assertSame('[["Ürün adı","1599.90",1]]', $basket);
        self::assertSame([['Ürün adı', '1599.90', 1]], json_decode($basket, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testTheCustomerDetailsThisApiRequiresAreCarriedOnTheForm(): void
    {
        $fields = $this->fields();

        self::assertSame('Efe Yilmaz', $fields['user_name']);
        self::assertSame('05000000000', $fields['user_phone']);
        self::assertSame('Ataturk Cad. 1, Seyhan, Adana', $fields['user_address']);
    }

    public function testNoCardFieldIsEverProducedByTheServer(): void
    {
        // Card details are typed by the customer into the form this page renders and are posted
        // by their browser to PayTR. The store must never hold, sign or log one of them.
        $names = array_keys($this->fields());

        foreach (['card_number', 'cvv', 'expiry_month', 'expiry_year', 'cc_owner'] as $cardField) {
            self::assertNotContains($cardField, $names, 'The store must not supply a card field.');
        }
    }

    public function testTheStoreNeverCallsPaytrBeforeTheCustomerHasEnteredACard(): void
    {
        // The client is wired to explode on any use. The Direct API posts from the browser, so
        // building the form must not touch the network at all.
        $outcome = $this->gateway(client: new MockHttpClient(static function (): never {
            throw new \LogicException('The Direct API posts from the browser; the store must not call out.');
        }))->initiate($this->instruction());

        self::assertTrue($outcome->isHostedForm());
    }

    public function testTestModeSendsTheProvidersOwnSwitchOnTheForm(): void
    {
        self::assertSame('1', $this->fields(testMode: true)['test_mode']);
        self::assertSame('0', $this->fields(testMode: false)['test_mode']);
    }

    public function testThreeDSecureStaysOnSoTheProviderKeepsItsFraudChecks(): void
    {
        // non_3d=1 would skip 3-D Secure, and PayTR treats it as a separate entitlement. Leaving
        // it at 0 is both the documented default and the only setting this store may use.
        self::assertSame('0', $this->fields()['non_3d']);
    }

    public function testAnUnconfiguredStoreOffersNoPaymentAtAll(): void
    {
        $outcome = $this->gateway(configured: false)->initiate($this->instruction());

        self::assertTrue($outcome->isFailed());
        self::assertSame('provider_not_configured', $outcome->failure()?->code());
        self::assertFalse($this->gateway(configured: false)->productionReady());
    }

    public function testAStoreWithoutACustomerAddressIsRefusedWithAnActionableReason(): void
    {
        $stack = new RequestStack();

        $gateway = $this->gateway(requestStack: $stack);
        $outcome = $gateway->initiate($this->instruction());

        self::assertTrue($outcome->isFailed());
        self::assertSame('customer_address_unavailable', $outcome->failure()?->code());
    }

    public function testAMissingCustomerNameIsRefusedBecauseThisApiRequiresOne(): void
    {
        $outcome = $this->gateway()->initiate($this->instruction(customerName: null));

        self::assertTrue($outcome->isFailed());
        self::assertSame('customer_details_incomplete', $outcome->failure()?->code());
    }

    public function testACustomerNameLongerThanThisApiAcceptsIsShortened(): void
    {
        $fields = $this->fields(customerName: str_repeat('a', 80));

        self::assertSame(60, mb_strlen($fields['user_name']));
    }

    public function testACustomerAddressLongerThanThisApiAcceptsIsShortened(): void
    {
        $fields = $this->fields(customerAddress: str_repeat('b', 500));

        self::assertSame(400, mb_strlen($fields['user_address']));
    }

    public function testACustomerPhoneLongerThanThisApiAcceptsIsShortened(): void
    {
        $fields = $this->fields(customerPhone: str_repeat('0', 40));

        self::assertSame(20, mb_strlen($fields['user_phone']));
    }

    public function testAnEmptyBasketFallsBackToASingleLineAtTheOrderTotal(): void
    {
        // PayTR shows the basket on the merchant's statement, and compares it with the amount.
        // Rather than send nothing, the order is described by one line at its exact total.
        self::assertSame(
            '[["'.self::ORDER_REFERENCE.'","1599.90",1]]',
            $this->fields(basketLines: [])['user_basket'],
        );
    }

    public function testTheBasketAndTheAmountAlwaysReconcileExactly(): void
    {
        $fields = $this->fields();
        $lines = json_decode($fields['user_basket'], true, 512, \JSON_THROW_ON_ERROR);

        $sum = 0;
        foreach ($lines as [$label, $price, $quantity]) {
            $sum += (int) round(((float) $price) * 100) * $quantity;
        }

        self::assertSame(159_990, $sum, 'PayTR compares its own basket total with payment_amount.');
    }

    public function testALocaleThisApiSpellsDifferentlyIsMapped(): void
    {
        self::assertSame('en', $this->fields(locale: 'en-GB')['client_lang']);
        self::assertSame('tr', $this->fields(locale: 'tr-TR')['client_lang']);
    }

    /**
     * @param list<array{string, string, int}>|null $basketLines
     *
     * @return array<string, string>
     */
    private function fields(
        bool $configured = true,
        bool $testMode = true,
        ?string $locale = null,
        ?string $customerName = 'Efe Yilmaz',
        ?string $customerPhone = '05000000000',
        ?string $customerAddress = 'Ataturk Cad. 1, Seyhan, Adana',
        ?array $basketLines = null,
    ): array {
        $outcome = $this->gateway($configured, $testMode)->initiate($this->instruction(
            locale: $locale,
            customerName: $customerName,
            customerPhone: $customerPhone,
            customerAddress: $customerAddress,
            basketLines: $basketLines,
        ));

        self::assertFalse($outcome->isFailed(), (string) $outcome->failure()?->message());

        return $outcome->hostedFormFields();
    }

    private function gateway(
        bool $configured = true,
        bool $testMode = true,
        ?RequestStack $requestStack = null,
        ?MockHttpClient $client = null,
    ): PaytrPaymentGateway {
        $configuration = \App\Module\Payment\Gateway\PayTR\PaytrConfiguration::fromEnvironment(
            $configured ? self::MERCHANT_ID : '',
            $configured ? self::MERCHANT_KEY : '',
            $configured ? self::MERCHANT_SALT : '',
            $testMode ? '1' : '0',
            self::PAYMENT_URL,
            'https://www.paytr.com/odeme/iade',
        );

        $stack = $requestStack ?? new RequestStack();
        if (null === $requestStack) {
            $stack->push(Request::create('https://store.test/odeme', 'POST', server: ['REMOTE_ADDR' => '88.99.140.12']));
        }

        return new PaytrPaymentGateway(
            $client ?? new MockHttpClient(new MockResponse('{}')),
            $configuration,
            new \App\Module\Payment\Gateway\PayTR\PaytrCallbackParser(),
            new \App\Module\Payment\Gateway\PayTR\PaytrClientIp($stack),
            new NullLogger(),
        );
    }

    /** @param list<array{string, string, int}>|null $basketLines */
    private function instruction(
        ?string $locale = null,
        ?string $customerName = 'Efe Yilmaz',
        ?string $customerPhone = '05000000000',
        ?string $customerAddress = 'Ataturk Cad. 1, Seyhan, Adana',
        ?array $basketLines = null,
    ): GatewayInitiationInstruction {
        return new GatewayInitiationInstruction(
            self::ORDER_NUMBER,
            '1',
            self::RETURN_TOKEN,
            Money::ofMinor(159_990, 'TRY'),
            'https://store.test/odeme/sonuc/'.self::RETURN_TOKEN,
            'https://store.test/odeme/iptal/'.self::RETURN_TOKEN,
            'musteri@example.com',
            $locale ?? 'tr',
            $customerName,
            $customerPhone,
            $customerAddress,
            $basketLines ?? [['Ürün adı', '1599.90', 1]],
        );
    }
}
