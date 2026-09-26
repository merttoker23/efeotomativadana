<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Gateway\PayTR;

use App\Module\Payment\Gateway\PayTR\PaytrOrderReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PayTR documents `merchant_oid` as alphanumeric and at most 64 characters, but the store's
 * order numbers are hyphenated (`EOA-20260925-ABCDEF123456`).
 *
 * The same value has to be used for the token request, the notification and the refund, so it
 * is normalised once, here, rather than differently in each of the three places.
 */
final class PaytrOrderReferenceTest extends TestCase
{
    public function testTheHyphensAreRemovedSoTheReferenceIsPurelyAlphanumeric(): void
    {
        self::assertSame('EOA20260925ABCDEF123456', PaytrOrderReference::forOrder('EOA-20260925-ABCDEF123456'));
    }

    public function testAnAttemptScopedReferenceAlsoCarriesTheAttempt(): void
    {
        // A reference that is only the order number cannot tell two attempts of the same order
        // apart, so a notification arriving after a retry would be matched to the wrong one.
        self::assertSame(
            'EOA20260925ABCDEF123456A2',
            PaytrOrderReference::forAttempt('EOA-20260925-ABCDEF123456', '2'),
        );
    }

    public function testTwoAttemptsOfOneOrderGetDifferentReferences(): void
    {
        self::assertNotSame(
            PaytrOrderReference::forAttempt('EOA-20260925-ABCDEF123456', '1'),
            PaytrOrderReference::forAttempt('EOA-20260925-ABCDEF123456', '2'),
        );
    }

    public function testAnAttemptScopedReferenceStillFitsTheDocumentedLengthLimit(): void
    {
        self::assertLessThanOrEqual(
            64,
            mb_strlen(PaytrOrderReference::forAttempt('EOA-20260925-ABCDEF123456', '12')),
        );
    }

    public function testAnAttemptSequenceWithCharactersPaytrWouldNotAcceptIsStripped(): void
    {
        self::assertSame(
            'EOA20260925ABCDEF123456Aattempt2',
            PaytrOrderReference::forAttempt('EOA-20260925-ABCDEF123456', 'attempt-2'),
        );
    }

    public function testAnAttemptWithNoIdentityAtAllIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PaytrOrderReference::forAttempt('EOA-20260925-ABCDEF123456', '---');
    }

    public function testTheReferenceIsTheSameForEveryUseOfOneOrder(): void
    {
        // The token request, the notification and the refund must all speak one reference.
        $first = PaytrOrderReference::forOrder('EOA-20260925-ABCDEF123456');
        $second = PaytrOrderReference::forOrder('EOA-20260925-ABCDEF123456');

        self::assertSame($first, $second);
    }

    public function testTwoDifferentOrdersNeverCollapseOntoOneReference(): void
    {
        self::assertNotSame(
            PaytrOrderReference::forOrder('EOA-20260925-ABCDEF123456'),
            PaytrOrderReference::forOrder('EOA-20260926-ABCDEF123456'),
        );
    }

    public function testTheReferenceFitsTheDocumentedLengthLimit(): void
    {
        self::assertLessThanOrEqual(64, mb_strlen(PaytrOrderReference::forOrder('EOA-20260925-ABCDEF123456')));
    }

    #[DataProvider('unusableOrderNumbers')]
    public function testAnOrderNumberThatWouldNormaliseToNothingIsRefused(string $orderNumber): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PaytrOrderReference::forOrder($orderNumber);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableOrderNumbers(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'only separators' => ['---'];
    }

    public function testAnAlreadyAlphanumericReferenceIsLeftAlone(): void
    {
        self::assertSame('EOA20260925ABCDEF123456', PaytrOrderReference::forOrder('EOA20260925ABCDEF123456'));
    }
}
