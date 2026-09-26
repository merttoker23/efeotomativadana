<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Module\Payment\SanitizedFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SanitizedFailureTest extends TestCase
{
    public function testCodeIsTrimmedAndLowercased(): void
    {
        self::assertSame('timeout', SanitizedFailure::fromProvider('  TIMEOUT  ', 'message', null)->code());
    }

    public function testMissingCodeBecomesASafePlaceholder(): void
    {
        self::assertSame('unknown_error', SanitizedFailure::fromProvider(null, null, null)->code());
        self::assertSame('unknown_error', SanitizedFailure::fromProvider('   ', null, null)->code());
    }

    public function testMessageIsRequiredWhenACodeIsPresent(): void
    {
        self::assertSame('', SanitizedFailure::fromProvider('declined', null, null)->message());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secretBearingMessages(): iterable
    {
        yield 'pan' => ['Declined for 4111111111111111 today', '4111111111111111'];
        yield 'spaced pan' => ['Declined for 4111 1111 1111 1111 today', '4111 1111 1111 1111'];
        yield 'cvc' => ['cvc=123 rejected', '123'];
        yield 'cvv word' => ['cvv 456 rejected', '456'];
        yield 'password' => ['password=hunter2 rejected', 'hunter2'];
        yield 'bearer token' => ['Authorization: Bearer abc.def.ghi rejected', 'abc.def.ghi'];
    }

    #[DataProvider('secretBearingMessages')]
    public function testSecretsNeverSurviveIntoPersistedMetadata(string $message, string $secret): void
    {
        $failure = SanitizedFailure::fromProvider('provider_error', $message, null);

        self::assertStringNotContainsString($secret, $failure->message());
    }

    public function testMessageIsBoundedToThePersistenceLimit(): void
    {
        $failure = SanitizedFailure::fromProvider('provider_error', str_repeat('a', 5_000), null);

        self::assertSame(SanitizedFailure::MAX_MESSAGE_LENGTH, mb_strlen($failure->message()));
    }

    public function testMetadataIsBoundedToThePersistenceLimit(): void
    {
        $failure = SanitizedFailure::fromProvider(str_repeat('C', 500), null, str_repeat('m', 5_000));

        self::assertSame(SanitizedFailure::MAX_CODE_LENGTH, mb_strlen($failure->code()));
        self::assertSame(SanitizedFailure::MAX_MESSAGE_LENGTH, mb_strlen($failure->message()));
    }

    public function testCodeIsBoundedButKeepsItsTailWhenTruncated(): void
    {
        $failure = SanitizedFailure::fromProvider('prefix_' . str_repeat('x', 200) . '_suffix', 'message', null);

        self::assertSame(SanitizedFailure::MAX_CODE_LENGTH, mb_strlen($failure->code()));
        self::assertStringEndsWith('_suffix', $failure->code());
    }

    public function testControlCharactersAreStripped(): void
    {
        $failure = SanitizedFailure::fromProvider("decl\nined", "line\r\nbreak\ttab", null);

        self::assertSame('declined', $failure->code());
        self::assertSame('linebreak tab', $failure->message());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secretBearingCodes(): iterable
    {
        yield 'pan in code' => ['card_4111111111111111', '4111111111111111'];
        yield 'api key in code' => ['api_key_sk_live_ABC123', 'sk_live_ABC123'];
        yield 'password in code' => ['password_hunter2', 'hunter2'];
    }

    #[DataProvider('secretBearingCodes')]
    public function testASecretInTheCodeIsRedactedToo(string $code, string $secret): void
    {
        $failure = SanitizedFailure::fromProvider($code, 'Declined', null);

        self::assertStringNotContainsString($secret, $failure->code());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providerSecretKeys(): iterable
    {
        yield 'api_key' => ['api_key=sk_live_ABC123 declined', 'sk_live_ABC123'];
        yield 'apikey' => ['apikey: sk_live_ABC123 declined', 'sk_live_ABC123'];
        yield 'x-api-key' => ['X-Api-Key: abc123XYZ declined', 'abc123XYZ'];
        yield 'hmac' => ['hmac=deadbeefcafe declined', 'deadbeefcafe'];
        yield 'hash' => ['hash: 0123456789abcdef declined', '0123456789abcdef'];
        yield 'pan word' => ['pan4111111111111111 declined', '4111111111111111'];
        yield 'card number' => ['card_number 4111111111111111 declined', '4111111111111111'];
        yield 'expiry' => ['expiry=12/29 declined', '12/29'];
    }

    #[DataProvider('providerSecretKeys')]
    public function testRealProviderSecretKeysAreRedacted(string $message, string $secret): void
    {
        $failure = SanitizedFailure::fromProvider('provider_error', $message, null);

        self::assertStringNotContainsString($secret, $failure->message(), $message);
    }

    public function testRetryableClassificationIsProviderIndependent(): void
    {
        self::assertTrue(SanitizedFailure::fromProvider('timeout', 'gateway timeout', null)->isRetryable());
        self::assertFalse(SanitizedFailure::fromProvider('card_declined', 'card declined', null)->isRetryable());
    }
}
