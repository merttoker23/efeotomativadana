<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\PayTR\PaytrConfiguration;
use App\Module\Payment\Gateway\PayTR\PaytrSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The adapter is wired in every environment, including ones where the merchant has not entered
 * their panel details yet. So the configuration must be constructible while empty and must
 * report that it cannot take money, rather than refusing to boot the container.
 */
final class PaytrConfigurationTest extends TestCase
{
    private const string ID = '123456';
    private const string KEY = 'testKey';
    private const string SALT = 'testSalt';
    private const string PAYMENT_URL = 'https://www.paytr.com/odeme';
    private const string REFUND_URL = 'https://www.paytr.com/odeme/iade';

    public function testAConfigurationWithoutCredentialsReportsItselfUnconfigured(): void
    {
        self::assertFalse($this->configuration('', '', '')->isConfigured());
    }

    #[DataProvider('partiallyFilledConfigurations')]
    public function testOneMissingCredentialIsEnoughToBeUnconfigured(string $id, string $key, string $salt): void
    {
        self::assertFalse($this->configuration($id, $key, $salt)->isConfigured());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function partiallyFilledConfigurations(): iterable
    {
        yield 'no key' => [self::ID, '', self::SALT];
        yield 'no salt' => [self::ID, self::KEY, ''];
        yield 'no id' => ['', self::KEY, self::SALT];
        yield 'blank key' => [self::ID, '   ', self::SALT];
    }

    public function testACompleteConfigurationIsConfiguredAndCanSign(): void
    {
        $configuration = $this->configuration();

        self::assertTrue($configuration->isConfigured());
        self::assertSame(self::ID, $configuration->merchantId());
        self::assertSame(
            (new PaytrSignature(self::KEY, self::SALT))->callbackHash('EOA20260925ABCDEF123456', 'success', '159990'),
            $configuration->signature()->callbackHash('EOA20260925ABCDEF123456', 'success', '159990'),
        );
    }

    public function testAnUnconfiguredStoreCannotSign(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->configuration('', '', '')->signature();
    }

    public function testAnUnconfiguredStoreHasNoMerchantIdToSend(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->configuration('', '', '')->merchantId();
    }

    public function testTestModeIsCarriedAsAFlagTheProviderUnderstands(): void
    {
        self::assertSame('1', $this->configuration(testMode: '1')->testModeFlag());
        self::assertSame('0', $this->configuration(testMode: '0')->testModeFlag());
        self::assertSame('1', $this->configuration(testMode: 'true')->testModeFlag());
        self::assertSame('0', $this->configuration(testMode: '')->testModeFlag());
    }

    public function testTestModeIsIndependentOfWhetherCredentialsExist(): void
    {
        $configuration = PaytrConfiguration::fromEnvironment('', '', '', '0', self::PAYMENT_URL, self::REFUND_URL);

        self::assertFalse($configuration->testMode());
        self::assertFalse($configuration->isConfigured());
    }

    public function testTheEndpointsAreTheOnesThisApiUses(): void
    {
        $configuration = $this->configuration();

        self::assertSame(self::PAYMENT_URL, $configuration->paymentUrl());
        self::assertSame(self::REFUND_URL, $configuration->refundUrl());
    }

    #[DataProvider('insecureEndpoints')]
    public function testAnEndpointThatIsNotHttpsIsRefusedOnUseRatherThanAtBoot(string $paymentUrl, string $refundUrl): void
    {
        // Refused when the payment is actually taken, not while the container is built: a bad
        // endpoint must make card payment unavailable, not take the whole site down.
        $configuration = $this->configuration(paymentUrl: $paymentUrl, refundUrl: $refundUrl);

        $this->expectException(\InvalidArgumentException::class);

        if ($paymentUrl === self::PAYMENT_URL) {
            $configuration->refundUrl();

            return;
        }

        $configuration->paymentUrl();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function insecureEndpoints(): iterable
    {
        yield 'payment over http' => ['http://www.paytr.com/odeme', self::REFUND_URL];
        yield 'refund over http' => [self::PAYMENT_URL, 'http://www.paytr.com/odeme/iade'];
        yield 'a javascript url' => ['javascript:alert(1)', self::REFUND_URL];
        yield 'no url at all' => ['', self::REFUND_URL];
    }

    private function configuration(
        string $id = self::ID,
        string $key = self::KEY,
        string $salt = self::SALT,
        string $testMode = '1',
        string $paymentUrl = self::PAYMENT_URL,
        string $refundUrl = self::REFUND_URL,
    ): PaytrConfiguration {
        return PaytrConfiguration::fromEnvironment($id, $key, $salt, $testMode, $paymentUrl, $refundUrl);
    }
}
