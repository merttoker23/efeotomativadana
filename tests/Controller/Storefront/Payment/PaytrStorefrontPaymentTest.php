<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront\Payment;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderStatusChange;
use App\Entity\Commerce\Payment;
use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderState;
use App\Module\Payment\Gateway\PayTR\PaytrOrderReference;
use App\Module\Payment\Gateway\PayTR\PaytrSignature;
use App\Module\Payment\PaymentState;
use App\Module\Settings\StoreConfiguration;
use App\Shared\Money\Money;
use App\Tests\ResetsRateLimits;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PayTR's notification endpoint and the card form page, over real requests.
 *
 * The notification URL is the one PayTR's own servers call, with no session and no cookies, and
 * its answer decides whether PayTR considers the payment delivered: only a processed or replayed
 * notification may receive a plain `OK`. An invalid signature must never be acknowledged as a
 * successfully delivered payment result.
 */
final class PaytrStorefrontPaymentTest extends WebTestCase
{
    use ResetsRateLimits;
    private const string NOTIFICATION_PATH = '/yeni/odeme/paytr/bildirim';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->resetRateLimits();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        \App\Tests\Fixtures\Payment\PaytrConfigurationFixture::configure($this->connection, $container->get(\App\Module\Payment\Gateway\PayTR\PaymentSecretCipher::class));
        $manager = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testTheNotificationIsAcceptedWithoutAnySession(): void
    {
        $order = $this->orderWithPaytrAttempt();

        $this->post($order, $this->notificationBody($order));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheNotificationReplyIsPlainTextAndCarriesNoMarkupAtAll(): void
    {
        $order = $this->orderWithPaytrAttempt();

        $this->post($order, $this->notificationBody($order));

        $response = $this->client->getResponse();
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        // PayTR treats any surrounding markup or whitespace as a failed delivery.
        self::assertSame('OK', $response->getContent());
    }

    public function testASignedSuccessNotificationSettlesThePayment(): void
    {
        $order = $this->orderWithPaytrAttempt();

        $this->post($order, $this->notificationBody($order));

        [$payment, $reloaded] = $this->reload($order);
        self::assertSame(PaymentState::Succeeded, $payment->state());
        self::assertSame(OrderState::Confirmed, $reloaded->state());
    }

    public function testAReplayedNotificationIsAbsorbedAndStillAnswersOk(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $body = $this->notificationBody($order);

        $this->post($order, $body);
        [$afterFirst] = $this->reload($order);
        $succeededAt = $afterFirst->latestAttempt()?->succeededAt()?->format('U.u');

        // PayTR documents that the same notification can arrive more than once.
        $this->post($order, $body);
        $this->post($order, $body);

        [$afterReplay, $reloaded] = $this->reload($order);
        self::assertSame(PaymentState::Succeeded, $afterReplay->state());
        self::assertSame($succeededAt, $afterReplay->latestAttempt()?->succeededAt()?->format('U.u'));
        self::assertSame(1, $this->statusChangeCount($reloaded, 'Confirmed'), 'A replay must not confirm the order twice.');
    }

    public function testANotificationAfterARetrySettlesTheAttemptItBelongsTo(): void
    {
        // Two attempts of one order carry different provider references. A notification for the
        // live one must not be matched to the earlier, already-failed attempt and discarded.
        $order = $this->orderWithPaytrAttempt();
        $firstReference = $this->referenceFor($order);

        // The first attempt is closed the way it really closes: the provider reports a decline.
        $this->post($order, $this->notificationBody($order, [
            'status' => 'failed',
            'total_amount' => '0',
            'failed_reason_code' => '0',
            'failed_reason_msg' => 'KART HATASI',
        ], $firstReference));
        self::assertSame(PaymentState::Failed, $this->paymentFor($order)->state());

        // Retried the way a customer retries: through the form page, which is a real request and
        // therefore the only place a provider that needs the customer IP can be satisfied.
        $this->client->loginUser($order->customer());
        $this->selectPaytrProvider();
        $this->submitFormPage($order);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The retry form was not offered.');

        $secondReference = (string) $this->paymentFor($order)->latestAttempt()?->providerReference();
        self::assertNotSame('', $secondReference);
        self::assertNotSame($firstReference, $secondReference);

        $this->post($order, $this->notificationBody($order, reference: $secondReference));

        [$payment, $reloaded] = $this->reload($order);
        self::assertSame(PaymentState::Succeeded, $payment->state());
        self::assertSame(OrderState::Confirmed, $reloaded->state());
    }

    public function testAForgedNotificationNeverSettlesThePayment(): void
    {
        $order = $this->orderWithPaytrAttempt();

        $this->client->request('POST', self::NOTIFICATION_PATH, [], [], [], http_build_query([
            'merchant_oid' => $this->referenceFor($order),
            'status' => 'success',
            'total_amount' => '30000',
            'payment_amount' => '30000',
            'currency' => 'TL',
            'hash' => 'a-forged-hash',
        ], '', '&', \PHP_QUERY_RFC1738));

        [$payment, $reloaded] = $this->reload($order);
        self::assertNotSame(PaymentState::Succeeded, $payment->state());
        self::assertSame(OrderState::Placed, $reloaded->state());
        self::assertNotSame('OK', $this->client->getResponse()->getContent());
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testBrowserReturnWaitsForTheSignedNotificationWithoutShowingAFalseError(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $this->client->loginUser($order->customer());
        $token = $this->paymentFor($order)->latestAttempt()?->returnToken();
        self::assertNotNull($token);

        // PayTR sends no signed result to merchant_ok_url. The browser visit may happen before
        // its server notification, so it must be informational and cannot settle this order.
        $this->client->request('GET', '/yeni/odeme/sonuc/'.$token);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('/yeni/odeme/'.$order->orderNumber(), $this->client->getResponse()->headers->get('Location'));
        self::assertSame(PaymentState::RequiresAction, $this->paymentFor($order)->state());

        $this->client->followRedirect();
        self::assertStringNotContainsString('Ödeme doğrulanamadı', (string) $this->client->getResponse()->getContent());
    }

    public function testANotificationWhoseStatusWasSwappedAfterSigningIsRejected(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $body = str_replace('status=success', 'status=failed', $this->notificationBody($order));

        $this->client->request('POST', self::NOTIFICATION_PATH, [], [], [], $body);

        [$payment] = $this->reload($order);
        self::assertNotSame(PaymentState::Succeeded, $payment->state());
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('OK', $this->client->getResponse()->getContent());
    }

    public function testAFailedNotificationMarksThePaymentFailedAndLeavesTheOrderTotalAlone(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $total = $order->grandTotal();

        $this->post($order, $this->notificationBody($order, [
            'status' => 'failed',
            'total_amount' => '0',
            'failed_reason_code' => '0',
            'failed_reason_msg' => 'KART HATASI',
        ]));

        [$payment, $reloaded] = $this->reload($order);
        self::assertSame(PaymentState::Failed, $payment->state());
        self::assertSame(OrderState::Placed, $reloaded->state());
        self::assertTrue($total->equals($reloaded->grandTotal()));
    }

    public function testAnInstalmentPaymentStillSettlesTheOrderAtTheOrderTotal(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $total = $order->grandTotal();

        // The customer hands the bank more than the order is worth; the order still settles at
        // the order total, and the larger figure becomes the refund ceiling.
        $this->post($order, $this->notificationBody($order, ['total_amount' => '34560']));

        [$payment, $reloaded] = $this->reload($order);
        self::assertSame(PaymentState::Succeeded, $payment->state());
        self::assertSame(OrderState::Confirmed, $reloaded->state());
        self::assertTrue($total->equals($payment->capturedAmount()));
        self::assertSame(34_560, $payment->collectedAmount()->minorAmount());
        self::assertSame(34_560, $payment->refundableAmount()->minorAmount());
    }

    public function testTheNotificationUrlCannotBeReachedWithAGet(): void
    {
        $this->client->request('GET', self::NOTIFICATION_PATH);

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    public function testAnInternalNotificationFailureIsNotAcknowledgedAndRollsBackConfirmation(): void
    {
        $order = $this->orderWithPaytrAttempt();
        self::getContainer()->get('event_dispatcher')->addListener(\App\Module\Notification\Event\PaymentCaptured::class, static function (): never {
            throw new \RuntimeException('Simulated notification processing failure.');
        });
        $this->client->request('POST', self::NOTIFICATION_PATH, [], [], [], $this->notificationBody($order));
        self::assertResponseStatusCodeSame(500);
        self::assertNotSame('OK', $this->client->getResponse()->getContent());
        self::assertSame('placed', $this->connection->fetchOne('SELECT state FROM commerce_customer_order WHERE id = ?', [$order->id()]));
        self::assertSame('requires_action', $this->connection->fetchOne('SELECT state FROM commerce_payment WHERE order_id = ?', [$order->id()]));
    }

    public function testCustomerCancellationRequiresCsrfAndDoesNotConfirmTheOrder(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $this->client->loginUser($order->customer());
        $token = $this->paymentFor($order)->latestAttempt()?->returnToken();
        $this->client->request('POST', '/yeni/odeme/iptal/'.$token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(PaymentState::RequiresAction, $this->paymentFor($order)->state());
        $crawler = $this->client->request('GET', '/yeni/odeme/'.$order->orderNumber());
        $csrf = $crawler->filter('input[name="payment_cancel[_token]"]')->attr('value');
        $this->client->request('POST', '/yeni/odeme/iptal/'.$token, ['payment_cancel' => ['_token' => $csrf]]);
        self::assertResponseRedirects();
        [$payment, $reloaded] = $this->reload($order);
        self::assertSame(PaymentState::Cancelled, $payment->state());
        self::assertSame(OrderState::Placed, $reloaded->state());
        self::assertSame(0, $payment->capturedAmount()->minorAmount());
    }

    public function testTheFormPageRendersACardFormThatPostsStraightToPaytr(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $this->client->loginUser($order->customer());
        $this->selectPaytrProvider();

        $this->submitFormPage($order);

        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // The form must go to the provider, never back to this application.
        self::assertStringContainsString('action="https://www.paytr.com/odeme"', $content);
        self::assertStringContainsString('name="card_number"', $content);
        self::assertStringContainsString('name="cvv"', $content);
        self::assertStringContainsString('name="paytr_token"', $content);
        self::assertStringNotContainsString('name="_token"', $content);
        self::assertStringNotContainsString('<script', $content, 'The page with card fields must not load the storefront JavaScript.');
    }

    public function testCheckoutThroughSandboxNotificationAndBrowserReturnConfirmsTheOrder(): void
    {
        // The delivery address has a phone even when the customer profile does not.
        $customer = new CustomerUser('paytr-checkout@example.com', 'Efe', 'Yilmaz');
        $customer->setPassword('test-password-hash');
        $address = new \App\Entity\Customer\CustomerAddress($customer);
        $address->update('Ev', 'Efe Yilmaz', '05000000000', 'Ataturk Cad. 1', null, 'Seyhan', 'Adana', '01000', true);
        $product = new \App\Entity\Catalog\Product('PAYTR-CHECKOUT', 'Sandbox urunu', 'paytr-checkout');
        $product->publish();
        $cart = new \App\Entity\Commerce\Cart($customer);
        $cart->add($product, 1);
        foreach ([$customer, $address, $product, $cart,
            new \App\Entity\Commerce\ProductPrice($product, Money::ofMinor(30_000, 'TRY'), \App\Module\Pricing\TaxCategory::of('replacement-part'), \App\Module\Pricing\TaxRate::fromBasisPoints(2_000)),
            new \App\Entity\Commerce\ProductInventory($product, 2),
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->selectPaytrProvider();
        $this->client->loginUser($customer);
        $this->client->request('GET', '/yeni/sepet');
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', '/yeni/odeme');
        self::assertSelectorExists('input[value="gateway_checkout"]');
        $crawler = $this->client->request('POST', '/yeni/odeme', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'shipping_address' => $address->id(),
            'billing_address' => $address->id(),
            'shipping_option' => 'local_standard',
            'payment_option' => 'gateway_checkout',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="https://www.paytr.com/odeme"]');
        self::assertSelectorExists('input[name="test_mode"][value="1"]');
        self::assertSelectorExists('input[name="non_3d"][value="0"]');
        self::assertSelectorExists('input[name="user_phone"][value="05000000000"]');
        self::assertSelectorTextContains('main [role="status"]', 'Test ödemesi');
        self::assertSelectorNotExists('script');
        $returnUrl = $crawler->filter('input[name="merchant_ok_url"]')->attr('value');
        self::assertIsString($returnUrl);
        $order = $this->entityManager->getRepository(CustomerOrder::class)->findOneBy(['customer' => $customer]);
        self::assertInstanceOf(CustomerOrder::class, $order);
        self::assertCount(1, $this->paymentFor($order)->attempts());
        self::assertSame(OrderState::Placed, $order->state());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));

        $this->post($order, $this->notificationBody($order));
        [$payment, $reloaded] = $this->reload($order);
        self::assertSame(PaymentState::Succeeded, $payment->state());
        self::assertSame(OrderState::Confirmed, $reloaded->state());
        $this->client->request('GET', (string) parse_url($returnUrl, PHP_URL_PATH));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'succeeded');
    }

    public function testPaytrBrowserPostIsInformationalEvenWithASignedBody(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $this->client->loginUser($order->customer());
        $token = $this->paymentFor($order)->latestAttempt()?->returnToken();
        $this->client->request('POST', '/yeni/odeme/sonuc/'.$token, [], [], [], $this->notificationBody($order));
        self::assertSame(PaymentState::RequiresAction, $this->paymentFor($order)->state());
        $this->client->followRedirect();
        self::assertStringNotContainsString('Ödeme doğrulanamadı', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Ödemeniz alındı', (string) $this->client->getResponse()->getContent());
    }

    public function testAnUnsignedPaytrBrowserPostDoesNotShowAVerificationError(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $this->client->loginUser($order->customer());
        $token = $this->paymentFor($order)->latestAttempt()?->returnToken();
        $this->client->request('POST', '/yeni/odeme/sonuc/'.$token);
        $this->client->followRedirect();
        self::assertStringNotContainsString('Ödeme doğrulanamadı', (string) $this->client->getResponse()->getContent());
        self::assertSame(PaymentState::RequiresAction, $this->paymentFor($order)->state());
    }

    public function testTheCardFormLoadsNoAnalyticsEvenWhenGa4IsConfigured(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $this->client->loginUser($order->customer());
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        $settings = $configuration->current();
        $settings->paymentProvider = 'paytr';
        $settings->ga4MeasurementId = 'G-ABC1234567';
        $configuration->save($settings);
        $this->submitFormPage($order);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('script');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    public function testSetupCheckReportsSandboxAndThePublicNotificationUrl(): void
    {
        $this->selectPaytrProvider();
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:paytr:check'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('SANDBOX', $tester->getDisplay());
        self::assertStringContainsString('https://localhost/yeni/odeme/paytr/bildirim', $tester->getDisplay());
        self::assertStringNotContainsString($this->merchantKey(), $tester->getDisplay());
        self::assertStringNotContainsString($this->merchantSalt(), $tester->getDisplay());
    }

    public function testSetupCheckNamesMissingCredentialsWithoutExposingSecrets(): void
    {
        $this->connection->update('payment_configuration', ['merchant_id' => '', 'merchant_key_encrypted' => null, 'merchant_salt_encrypted' => null], ['id' => 1]);
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:paytr:check'));
        self::assertSame(1, $tester->execute([]));
        foreach (['Merchant ID', 'Merchant Key', 'Merchant Salt'] as $variable) {
            self::assertStringContainsString($variable, $tester->getDisplay());
        }
    }

    public function testMissingDbCredentialsHidePaytrFromCheckoutEvenOutsideProduction(): void
    {
        $this->selectPaytrProvider();
        $this->connection->update('payment_configuration', ['merchant_key_encrypted' => null], ['id' => 1]);
        $option = new \App\Module\Checkout\GatewayCheckoutPaymentOption(
            self::getContainer()->get(StoreConfiguration::class),
            self::getContainer()->get(\App\Module\Payment\PaymentGatewayRegistry::class),
        );
        self::assertFalse($option->available());
    }

    public function testSameGatewayReadsCurrentDbCredentialsForCallbackAndRefund(): void
    {
        $source = self::getContainer()->get(\App\Module\Payment\Gateway\PayTR\StoredPaytrConfiguration::class);
        $cipher = self::getContainer()->get(\App\Module\Payment\Gateway\PayTR\PaymentSecretCipher::class);
        $reference = 'EOA20260925ABCDEF123456A1';
        $body = [];
        $http = new \Symfony\Component\HttpClient\MockHttpClient(function (string $method, string $url, array $options) use (&$body): \Symfony\Component\HttpClient\Response\MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://www.paytr.com/odeme/iade', $url);
            parse_str((string) $options['body'], $body);

            return new \Symfony\Component\HttpClient\Response\MockResponse('{"status":"success"}');
        });
        $gateway = new \App\Module\Payment\Gateway\PayTR\PaytrPaymentGateway(
            $http, $source,
            new \App\Module\Payment\Gateway\PayTR\PaytrCallbackParser(),
            new \App\Module\Payment\Gateway\PayTR\PaytrClientIp(new \Symfony\Component\HttpFoundation\RequestStack()),
            new \Psr\Log\NullLogger(),
        );
        $fields = ['merchant_oid' => $reference, 'status' => 'success', 'total_amount' => '100', 'payment_amount' => '100', 'currency' => 'TL', 'test_mode' => '1'];
        $fields['hash'] = base64_encode(hash_hmac('sha256', $reference.'test-merchant-salt'.'success100', 'test-merchant-key', true));
        $callback = new \App\Module\Payment\Gateway\IncomingPaymentCallback(http_build_query($fields), [], []);
        self::assertTrue($gateway->authenticateCallback($callback)->verified());

        $this->connection->update('payment_configuration', [
            'merchant_id' => '987654',
            'merchant_key_encrypted' => $cipher->encrypt('current-db-key', 'key'),
            'merchant_salt_encrypted' => $cipher->encrypt('current-db-salt', 'salt'),
            'test_mode' => 0,
        ], ['id' => 1]);
        self::assertFalse($gateway->authenticateCallback($callback)->verified());
        $fields['test_mode'] = '0';
        $fields['hash'] = base64_encode(hash_hmac('sha256', $reference.'current-db-salt'.'success100', 'current-db-key', true));
        self::assertTrue($gateway->authenticateCallback(new \App\Module\Payment\Gateway\IncomingPaymentCallback(http_build_query($fields), [], []))->verified());
        $gateway->refund(new \App\Module\Payment\Gateway\GatewayRefundInstruction($reference, Money::ofMinor(100, 'TRY'), 'db-config-refund', 'Test'));
        self::assertSame('987654', $body['merchant_id']);
        self::assertSame(base64_encode(hash_hmac('sha256', '987654'.$reference.'1.00'.'current-db-salt', 'current-db-key', true)), $body['paytr_token']);

        $this->connection->update('payment_configuration', ['merchant_key_encrypted' => 'v1:invalid'], ['id' => 1]);
        self::assertFalse($gateway->productionReady());
    }

    public function testTheFormPageRefusesAnonymousVisitors(): void
    {
        $order = $this->orderWithPaytrAttempt();

        $this->client->request('POST', sprintf('/yeni/odeme/%s/odeme-formu', $order->orderNumber()), ['_token' => 'x']);

        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    public function testRetryRendersTheHostedFormInsteadOfRedirectingWithoutItsFields(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $this->client->loginUser($order->customer());
        $this->selectPaytrProvider();
        $crawler = $this->client->request('GET', '/yeni/odeme/'.$order->orderNumber());
        $token = $crawler->filter('form[action$="/yeniden-dene"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/yeni/odeme/'.$order->orderNumber().'/yeniden-dene', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="https://www.paytr.com/odeme"] input[name="paytr_token"]');
        self::assertSelectorNotExists('script');
    }

    public function testTheFormPageRejectsAGetBecauseItStartsAnAttempt(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $this->client->loginUser($order->customer());

        $this->client->request('GET', sprintf('/yeni/odeme/%s/odeme-formu', $order->orderNumber()));

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    public function testTheFormPageRefusesSomebodyElsesOrder(): void
    {
        $order = $this->orderWithPaytrAttempt();
        $other = new CustomerUser('other-'.bin2hex(random_bytes(6)).'@example.com', 'Other', 'Customer');
        $other->setPassword('test-password-hash');
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->client->loginUser($other);

        // A valid session for the wrong customer, which must be indistinguishable from an
        // order that does not exist.
        $this->client->request('POST', sprintf('/yeni/odeme/%s/odeme-formu', $order->orderNumber()), ['_token' => 'x']);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function submitFormPage(CustomerOrder $order): void
    {
        $this->client->request('GET', sprintf('/yeni/odeme/%s', $order->orderNumber()));
        $field = $this->client->getCrawler()->filter('input[name="_token"]')->first();
        self::assertGreaterThan(0, $field->count(), 'The order page rendered no CSRF field.');
        $token = (string) $field->attr('value');

        $this->client->request('POST', sprintf('/yeni/odeme/%s/odeme-formu', $order->orderNumber()), [
            '_token' => $token,
        ]);
    }

    private function post(CustomerOrder $order, string $body): void
    {
        $this->client->request('POST', self::NOTIFICATION_PATH, [], [], [], $body);
        self::assertSame('OK', $this->client->getResponse()->getContent());
    }

    /** @param array<string, string> $overrides */
    private function notificationBody(CustomerOrder $order, array $overrides = [], ?string $reference = null): string
    {
        $reference ??= $this->referenceFor($order);
        $fields = array_merge([
            'merchant_oid' => $reference,
            'status' => 'success',
            'total_amount' => (string) $order->grandTotal()->minorAmount(),
            'payment_amount' => (string) $order->grandTotal()->minorAmount(),
            'currency' => $order->grandTotal()->currency(),
            'payment_type' => 'card',
            'installment_count' => '0',
            'test_mode' => '1',
        ], $overrides);

        $signature = new PaytrSignature($this->merchantKey(), $this->merchantSalt());
        $fields['hash'] = $signature->callbackHash($reference, $fields['status'], $fields['total_amount']);

        return http_build_query($fields, '', '&', \PHP_QUERY_RFC1738);
    }

    private function referenceFor(CustomerOrder $order): string
    {
        $payment = $this->paymentFor($order);
        self::assertNotNull($payment);

        return (string) $payment->latestAttempt()->providerReference();
    }

    private function merchantKey(): string
    {
        return \App\Tests\Fixtures\Payment\PaytrConfigurationFixture::KEY;
    }

    private function merchantSalt(): string
    {
        return \App\Tests\Fixtures\Payment\PaytrConfigurationFixture::SALT;
    }

    /** Selects the provider the way an administrator would, through the store setting. */
    private function selectPaytrProvider(): void
    {
        $configuration = self::getContainer()->get(StoreConfiguration::class);
        self::assertInstanceOf(StoreConfiguration::class, $configuration);
        $settings = $configuration->current();
        $settings->paymentProvider = 'paytr';
        $configuration->save($settings);
    }

    /** @return array{Payment, CustomerOrder} */
    private function reload(CustomerOrder $order): array
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(CustomerOrder::class, $order->id());
        self::assertInstanceOf(CustomerOrder::class, $reloaded);
        $payment = $this->paymentFor($reloaded);
        self::assertNotNull($payment);

        return [$payment, $reloaded];
    }

    private function statusChangeCount(CustomerOrder $order, string $toState): int
    {
        return count($this->entityManager->getRepository(OrderStatusChange::class)->findBy([
            'order' => $order->id(),
            'toState' => $toState,
        ]));
    }

    private function paymentFor(CustomerOrder $order): ?Payment
    {
        return $this->entityManager->getRepository(Payment::class)->findOneBy(['order' => $order->id()]);
    }

    /**
     * An order with a PayTR attempt waiting for the provider to decide, built directly so the
     * test never performs a real provider call.
     */
    private function orderWithPaytrAttempt(): CustomerOrder
    {
        $customer = new CustomerUser('paytr-'.bin2hex(random_bytes(6)).'@example.com', 'Efe', 'Yilmaz');
        // PayTR's Direct API requires a name, a phone and an address, and the order snapshots
        // all three from the customer, so a customer without a phone cannot be charged.
        $customer->setProfile('Efe', 'Yilmaz', '05000000000');
        $customer->setPassword('test-password-hash');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $order = $this->order($customer);

        $payment = Payment::start($order, 'paytr', $order->grandTotal(), new \DateTimeImmutable());
        $attempt = $payment->beginAttempt('paytr-attempt-1', $order->grandTotal());
        $attempt->assignProviderReference(PaytrOrderReference::forAttempt($order->orderNumber(), '1'));
        $payment->markRequiresAction($attempt, new \DateTimeImmutable());

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        return $order;
    }

    private function order(CustomerUser $customer): CustomerOrder
    {
        $zero = Money::ofMinor(0, 'TRY');
        $gross = Money::ofMinor(30_000, 'TRY');
        $order = new CustomerOrder(
            'EOA-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(6))),
            $customer,
            $gross,
            $zero,
            $zero,
            $gross,
            'local_standard',
            'Yerel standart teslimat',
            'gateway_checkout',
            'Kredi karti ile odeme',
            new \DateTimeImmutable(),
        );
        $order->addItem(null, 'PAYTR-SKU', 'Odeme urunu', 1, $gross, 0, $gross, $zero, $gross);
        $order->addAddress(\App\Module\Order\OrderAddressRole::Shipping, 'Efe Yilmaz', '05000000000', 'Ataturk Cad. 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->addAddress(\App\Module\Order\OrderAddressRole::Billing, 'Efe Yilmaz', '05000000000', 'Ataturk Cad. 1', null, 'Seyhan', 'Adana', null, 'TR');
        $order->sealSnapshots();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
