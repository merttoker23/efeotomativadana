<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerUser;
use App\Module\Checkout\CheckoutSelection;
use App\Module\Checkout\CheckoutViolation;
use App\Shared\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderNoteTest extends TestCase
{
    #[DataProvider('validNotes')]
    public function testRequestAndOrderSnapshotNormalizePlainText(?string $input, ?string $expected): void
    {
        $selection = new CheckoutSelection(1, 1, 'shipping', 'payment', $input);
        self::assertSame($expected, $selection->orderNote);
        self::assertSame($expected, $this->order($input)->orderNote());
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function validNotes(): iterable
    {
        yield 'absent legacy-compatible note' => [null, null];
        yield 'blank optional note' => [" \n\t ", null];
        yield 'trim preserves lines and punctuation' => ["  A & B\nÖğleden sonra teslim edin.  ", "A & B\nÖğleden sonra teslim edin."];
        yield '1000 Turkish characters' => [str_repeat('ğ', 1000), str_repeat('ğ', 1000)];
    }

    #[DataProvider('invalidNotes')]
    public function testRequestRejectsInvalidNotes(string $note): void
    {
        $this->expectException(CheckoutViolation::class);
        new CheckoutSelection(1, 1, 'shipping', 'payment', $note);
    }

    #[DataProvider('invalidNotes')]
    public function testDomainRejectsInvalidNotesWithoutCheckout(string $note): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->order($note);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNotes(): iterable
    {
        yield 'length above character limit' => [str_repeat('ğ', 1001)];
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'html' => ['Not <b>kalın</b>'];
        yield 'comment' => ['<!-- hidden -->not'];
    }

    private function order(?string $note): CustomerOrder
    {
        $zero = Money::ofMinor(0, 'TRY');

        return new CustomerOrder('EOA-20261005-A1B2C3D4E5F6', new CustomerUser('note@example.com', 'Not', 'Müşteri'), $zero, $zero, $zero, $zero, 'shipping', 'Kargo', 'payment', 'Ödeme', new \DateTimeImmutable(), $note);
    }
}
