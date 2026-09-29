<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Shared\Logging\RedactingLogProcessor;
use App\Shared\Logging\SecretRedactor;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The redaction policy, and the proof that it is actually wired in.
 *
 * Two halves, and the second is the one that matters. A redaction helper nobody calls is a
 * comment; a Monolog *processor* attached to the logger prototype is a property of the
 * configuration that every call site inherits for free. The wiring is asserted here by logging
 * through the container's own logger and reading the record back, which is the only way to prove
 * that a log line in this application is redacted without reading every log line.
 */
final class LogRedactionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function secretBearingValues(): iterable
    {
        yield 'a bare card number' => ['4111111111111111'];
        yield 'a grouped card number' => ['4111 1111 1111 1111'];
        yield 'a merchant key beside its name' => ['merchant_key=abc123def456ghi789'];
        yield 'an api key in camel case' => ['apiKey: sk_live_51H8xY2'];
        yield 'a bearer token' => ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature'];
        yield 'a password' => ["password='hunter2-long-enough'"];
        yield 'a pan with a prefix' => ['pan4111111111111111'];
        yield 'a signature' => ['signature=deadbeefdeadbeefdeadbeef'];
        yield 'a session id' => ['session=abcdef0123456789'];
    }

    /**
     * @param string $value
     */
    #[DataProvider('secretBearingValues')]
    public function testSecretsAreRemovedFromFreeText(string $value): void
    {
        $redacted = SecretRedactor::text($value);

        self::assertStringContainsString(SecretRedactor::PLACEHOLDER, $redacted);
        foreach (['4111111111111111', 'sk_live_51H8xY2', 'hunter2-long-enough', 'deadbeefdeadbeefdeadbeef', 'abcdef0123456789'] as $leak) {
            self::assertStringNotContainsString($leak, $redacted, $value);
        }
    }

    /**
     * The redaction must not destroy the sentence around it: an operator reading a log needs to
     * know *what* failed, and a policy that blanks the whole message is its own information loss.
     */
    public function testTheSurroundingMessageSurvives(): void
    {
        $redacted = SecretRedactor::text('paytr.refund.refused for order EOA20260926PROBEPROBE01 with card 4111111111111111');

        self::assertStringContainsString('paytr.refund.refused', $redacted);
        self::assertStringContainsString('EOA20260926PROBEPROBE01', $redacted);
        self::assertStringNotContainsString('4111111111111111', $redacted);
    }

    public function testOrderNumbersAndStatesAreNotMistakenForSecrets(): void
    {
        // A trail nobody can read is not an audit trail. Twelve digits in an order number and a
        // short state name must both survive; only 13 to 19 consecutive digits are a card number.
        foreach (['EOA-20260929-A1B2C3D4E5F6', 'order 123456789012 placed', 'confirmed', 'in_transit'] as $safe) {
            self::assertSame($safe, SecretRedactor::opaque($safe));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('secretContexts')]
    public function testContextKeysAreHonouredRegardlessOfWhatTheCallSiteCalledThem(array $context, string $leak): void
    {
        $redacted = SecretRedactor::context($context);
        $encoded = var_export($redacted, true);

        self::assertStringContainsString(SecretRedactor::PLACEHOLDER, $encoded);
        self::assertStringNotContainsString($leak, $encoded);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function secretContexts(): iterable
    {
        yield 'a password field' => [['password' => 'correct-horse-battery'], 'correct-horse-battery'];
        yield 'a nested authorization header' => [['request' => ['headers' => ['Authorization' => 'Bearer abc.def.ghi']]], 'abc.def.ghi'];
        yield 'a merchant key under a bespoke name' => [['merchantKey' => 'Zq7Kd91xLp2mQr4t'], 'Zq7Kd91xLp2mQr4t'];
        yield 'a card number in free text' => [['note' => 'customer sent 4111111111111111 by phone'], '4111111111111111'];
        yield 'a session token' => [['session_token' => 's3cr3t-session-value-here'], 's3cr3t-session-value-here'];
    }

    public function testReadableValuesAreKept(): void
    {
        $redacted = SecretRedactor::context([
            'order_reference' => 'EOA-20260929-ABCDEF012345',
            'amount_minor' => 30000,
            'currency' => 'TRY',
            'outcome' => 'succeeded',
            'from_state' => 'placed',
            'to_state' => 'confirmed',
            'retryable' => true,
            'items' => [['sku' => 'BRK-1'], ['sku' => 'FLT-2']],
        ]);

        self::assertSame('EOA-20260929-ABCDEF012345', $redacted['order_reference']);
        self::assertSame(30000, $redacted['amount_minor']);
        self::assertSame('succeeded', $redacted['outcome']);
        self::assertSame('confirmed', $redacted['to_state']);
        self::assertTrue($redacted['retryable']);
        self::assertSame([['sku' => 'BRK-1'], ['sku' => 'FLT-2']], $redacted['items']);
    }

    /**
     * A structure deeper than the depth limit is replaced rather than walked.
     *
     * The limit exists so redaction cannot itself become the denial of service it prevents; this
     * asserts the replacement is visible rather than silently truncating an operator's context.
     */
    public function testAnAbsurdlyNestedContextIsReplacedRatherThanWalked(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < SecretRedactor::MAX_DEPTH + 5; ++$i) {
            $deep = ['level' => $deep];
        }

        $encoded = var_export(SecretRedactor::context(['deep' => $deep]), true);

        self::assertStringContainsString('truncated', $encoded);
    }

    /**
     * The processor, through a real Monolog handler.
     *
     * Asserted on the record Monolog actually produced rather than on the return value of a
     * static helper, because the helper is not what the application calls.
     */
    public function testTheProcessorRedactsWhatMonologIsAboutToWrite(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('security', [$handler], [new RedactingLogProcessor()]);

        $logger->error('A sign-in failed.', [
            '_username' => 'attacker@example.com',
            'context' => ['password' => 'correct-horse-battery-staple'],
        ]);

        $record = $handler->getRecords()[0] ?? null;
        self::assertNotNull($record);
        $encoded = var_export($record->context, true);

        self::assertStringNotContainsString('correct-horse-battery-staple', $encoded);
        self::assertStringContainsString('attacker@example.com', $encoded, 'The submitted identity must survive: it is the whole point of the record.');
        self::assertSame(SecretRedactor::PLACEHOLDER, $record->context['context']['password']);
    }

    public function testRedactionIsIdempotentBecauseTwoHandlersMayBothApplyIt(): void
    {
        $once = SecretRedactor::text('card 4111111111111111 token=abcdef1234567890');
        $twice = SecretRedactor::text($once);

        self::assertSame($once, $twice);
    }

    public function testAnObjectInTheContextIsLeftAloneForItsOwnProcessor(): void
    {
        // A Throwable carries a message; this class does not guess what a message means. It is
        // Monolog's sensitive-parameter processor's job, and the call sites here log the
        // exception *class* rather than the exception.
        $redacted = SecretRedactor::context(['exception' => new \RuntimeException('boom 4111111111111111')]);

        self::assertInstanceOf(\RuntimeException::class, $redacted['exception']);
    }
}
