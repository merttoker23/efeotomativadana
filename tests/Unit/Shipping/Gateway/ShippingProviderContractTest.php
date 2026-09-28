<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shipping\Gateway;

use App\Module\Payment\SanitizedFailure;
use App\Module\Shipping\Gateway\ShipmentAddress;
use App\Module\Shipping\Gateway\ShipmentCancellationInstruction;
use App\Module\Shipping\Gateway\ShipmentCancellationOutcome;
use App\Module\Shipping\Gateway\ShipmentCreationInstruction;
use App\Module\Shipping\Gateway\ShipmentCreationOutcome;
use App\Module\Shipping\Gateway\ShipmentItem;
use App\Module\Shipping\Gateway\ShipmentLabel;
use App\Module\Shipping\Gateway\ShipmentLabelRequest;
use App\Module\Shipping\Gateway\ShipmentStatusReport;
use App\Module\Shipping\Gateway\ShipmentStatusRequest;
use App\Module\Shipping\Gateway\ShippingProviderInterface;
use App\Module\Shipping\ShipmentState;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

final class ShippingProviderContractTest extends TestCase
{
    public function testTheContractIsProviderNeutralTaggedAndCoversTheFourCapabilities(): void
    {
        $attributes = (new \ReflectionClass(ShippingProviderInterface::class))->getAttributes(AutoconfigureTag::class);

        self::assertCount(1, $attributes);
        self::assertSame(['app.shipping_provider'], $attributes[0]->getArguments());
        foreach (['create', 'cancel', 'status', 'label'] as $capability) {
            self::assertTrue(
                method_exists(ShippingProviderInterface::class, $capability),
                sprintf('A carrier needs a %s capability.', $capability),
            );
        }
        // The carrier's own name and the act of fetching its printable label are two different
        // things, so they cannot share one method name.
        self::assertTrue(method_exists(ShippingProviderInterface::class, 'displayName'));
    }

    /**
     * Neutrality is a property of the signatures, not of a docblock.
     *
     * Every parameter and return type on the boundary must be one this application owns. A vendor
     * type leaking into one signature — `create(ShipmentCreationInstruction $i, PaytrShipment $r)`
     * — is exactly the coupling the interface exists to prevent, and it would survive a review that
     * only reads the interface's own documentation.
     */
    public function testNoSignatureOnTheBoundaryNamesAVendorType(): void
    {
        $allowed = [
            ShipmentCreationInstruction::class,
            ShipmentCreationOutcome::class,
            ShipmentCancellationInstruction::class,
            ShipmentCancellationOutcome::class,
            ShipmentStatusRequest::class,
            ShipmentStatusReport::class,
            ShipmentLabelRequest::class,
            ShipmentLabel::class,
            SanitizedFailure::class,
            \App\Module\Shipping\ShipmentState::class,
            \App\Module\Payment\PaymentState::class,
            Money::class,
            'self',
            'static',
            'void',
            'string',
            'int',
            'bool',
            'float',
            'array',
            'null',
            'mixed',
        ];

        foreach ((new \ReflectionClass(ShippingProviderInterface::class))->getMethods() as $method) {
            $types = array_merge([$method->getReturnType()], array_map(
                static fn (\ReflectionParameter $parameter): ?\ReflectionType => $parameter->getType(),
                $method->getParameters(),
            ));
            foreach ($types as $type) {
                foreach (self::namedTypes($type) as $name) {
                    self::assertContains(
                        $name,
                        $allowed,
                        sprintf('%s::%s() names %s, which this application does not own.', ShippingProviderInterface::class, $method->getName(), $name),
                    );
                }
            }
        }
    }

    /** @return list<string> */
    private static function namedTypes(?\ReflectionType $type): array
    {
        if (null === $type) {
            return [];
        }
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return array_map(
                static function (\ReflectionType $inner): string {
                    self::assertInstanceOf(\ReflectionNamedType::class, $inner, 'Only named types may appear on the boundary.');

                    return $inner->getName();
                },
                $type->getTypes(),
            );
        }

        return [];
    }

    public function testACarrierInstructionCarriesTheOrdersShippingFactsAndNothingElse(): void
    {
        $instruction = $this->instruction();

        self::assertSame('fake', $instruction->providerKey());
        self::assertSame('shipment-EOA-20260928-ABCDEF123456', $instruction->idempotencyKey());
        self::assertSame('EOA-20260928-ABCDEF123456', $instruction->orderNumber());
        self::assertSame('local_standard', $instruction->methodKey());
        self::assertSame(30_000, $instruction->declaredValue()->minorAmount());
        self::assertSame('TRY', $instruction->declaredValue()->currency());
        self::assertSame('Efe Yılmaz', $instruction->address()->recipientName());
        self::assertSame('Adana', $instruction->address()->city());
        self::assertSame('TR', $instruction->address()->countryCode());
        self::assertCount(1, $instruction->items());
        self::assertSame('BRK-1', $instruction->items()[0]->sku());
        self::assertSame(2, $instruction->items()[0]->quantity());
    }

    public function testTheIdempotencyKeyIsWhatStopsARetryFromCreatingASecondParcel(): void
    {
        // The same key on a replayed message is the whole contract with the carrier, so it has to
        // be deterministic for one order rather than a fresh random token per call.
        self::assertSame($this->instruction()->idempotencyKey(), $this->instruction()->idempotencyKey());
    }

    public function testACarrierInstructionRejectsAnOrderNumberThatIsNotAnOrderNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShipmentCreationInstruction(
            'fake',
            'shipment-EOA-20260928-ABCDEF123456',
            'not-an-order',
            'local_standard',
            'Yerel standart teslimat',
            $this->address(),
            [new ShipmentItem('BRK-1', 'Fren balatası', 2)],
            Money::ofMinor(30_000, 'TRY'),
        );
    }

    public function testACarrierInstructionNeedsAtLeastOneItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->instruction(items: []);
    }

    public function testAShipmentItemRejectsAnEmptySku(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShipmentItem('   ', 'Fren balatası', 1);
    }

    public function testAShipmentItemRejectsAQuantityThatIsNotAPositiveWholeNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShipmentItem('BRK-1', 'Fren balatası', 0);
    }

    public function testADeclaredValueOfNothingIsNotWorthShipping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->instruction(declaredValue: Money::ofMinor(0, 'TRY'));
    }

    public function testAShippingAddressMustSayWhereItIsGoing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShipmentAddress('Efe Yılmaz', '05000000000', '', null, 'Seyhan', 'Adana', null, 'TR');
    }

    public function testAShippingAddressNormalisesItsCountryCode(): void
    {
        self::assertSame('TR', (new ShipmentAddress('Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', '  Kat 3  ', 'Seyhan', 'Adana', '01000', 'tr'))->countryCode());
    }

    public function testAShippingAddressRejectsACountryThatIsNotACountryCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShipmentAddress('Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', null, 'Türkiye');
    }

    public function testAnAcceptedCreationMustCarryAReferenceBecauseCancelAndStatusHaveNoOtherAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShipmentCreationOutcome::accepted('   ');
    }

    public function testAnAcceptedCreationCarriesTheReferenceAndAnOptionalTrackingNumber(): void
    {
        $outcome = ShipmentCreationOutcome::accepted('SHIP-1');

        self::assertTrue($outcome->isAccepted());
        self::assertSame('SHIP-1', $outcome->providerReference());
        self::assertNull($outcome->trackingNumber());
        self::assertNull($outcome->failure());
    }

    public function testACarrierCannotSmuggleControlCharactersIntoATrackingNumber(): void
    {
        // The tracking number is rendered to customers and operators. A newline plus a second
        // fake line is the cheapest way to make a shipment look delivered, so the value is
        // normalised at the boundary instead of at each template.
        $outcome = ShipmentCreationOutcome::accepted('SHIP-1', "TRK-9\nTESLIM EDILDI");

        self::assertSame('TRK-9 TESLIM EDILDI', $outcome->trackingNumber());
    }

    public function testAnOverlongTrackingNumberIsTruncatedRatherThanStoredWhole(): void
    {
        $outcome = ShipmentCreationOutcome::accepted('SHIP-1', str_repeat('9', 500));

        self::assertSame(120, mb_strlen((string) $outcome->trackingNumber()));
    }

    /**
     * A reference is an identity, not display text.
     *
     * Truncating one produces a *different* reference, and every later cancel, status read and
     * label request would then be addressed to a parcel the carrier has never heard of. Refusing is
     * the only safe answer, and it is why the existing "must carry a reference" guard fires here.
     */
    public function testAnOverlongProviderReferenceIsRefusedRatherThanTruncated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An accepted shipment must carry a provider reference.');

        ShipmentCreationOutcome::accepted('SH' . str_repeat('X', 200));
    }

    public function testAReferenceAtTheBoundIsAccepted(): void
    {
        $reference = str_repeat('R', 120);

        self::assertSame($reference, ShipmentCreationOutcome::accepted($reference)->providerReference());
    }

    public function testARefusedCreationCarriesASanitizedFailure(): void
    {
        $outcome = ShipmentCreationOutcome::refused(SanitizedFailure::fromProvider('address_invalid', 'no such district 4111111111111111', null));

        self::assertFalse($outcome->isAccepted());
        self::assertNull($outcome->providerReference());
        self::assertSame('address_invalid', $outcome->failure()?->code());
        $message = (string) $outcome->failure()->message();
        self::assertStringNotContainsString('4111111111111111', $message);
    }

    public function testACancellationInstructionAddressesTheCarrierWithTheReferenceAndAKey(): void
    {
        $instruction = new ShipmentCancellationInstruction('fake', 'SHIP-1', 'cancel-shipment-EOA-20260928-ABCDEF123456', 'Müşteri adresi hatalı');

        self::assertSame('fake', $instruction->providerKey());
        self::assertSame('SHIP-1', $instruction->providerReference());
        self::assertSame('cancel-shipment-EOA-20260928-ABCDEF123456', $instruction->idempotencyKey());
        self::assertSame('Müşteri adresi hatalı', $instruction->reason());
    }

    public function testACancellationRequiresAReasonBecauseItIsAnAuditedAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShipmentCancellationInstruction('fake', 'SHIP-1', 'cancel-key', '   ');
    }

    public function testCancellationOutcomesReportEitherSuccessOrARefusal(): void
    {
        $cancelled = ShipmentCancellationOutcome::cancelled();
        self::assertTrue($cancelled->isCancelled());
        self::assertNull($cancelled->failure());

        $refused = ShipmentCancellationOutcome::refused(SanitizedFailure::fromProvider('already_dispatched', 'too late', null));
        self::assertFalse($refused->isCancelled());
        self::assertSame('already_dispatched', $refused->failure()?->code());
    }

    public function testAStatusReportNormalisesTheCarrierWordingIntoAStoreState(): void
    {
        $report = ShipmentStatusReport::reporting(ShipmentState::InTransit, 'TRK-9', 'Yola çıktı');

        self::assertTrue($report->isRecognised());
        self::assertSame(ShipmentState::InTransit, $report->state());
        self::assertSame('TRK-9', $report->trackingNumber());
        self::assertSame('Yola çıktı', $report->providerStatus());
    }

    public function testACarrierStatusTheAdapterCannotMapIsReportedRatherThanGuessed(): void
    {
        // Guessing here would move a parcel the store has no evidence about, so an unmapped
        // status carries no state at all and the application records it without acting.
        $report = ShipmentStatusReport::unrecognised('?????');

        self::assertFalse($report->isRecognised());
        self::assertNull($report->state());
        self::assertSame('?????', $report->providerStatus());
    }

    /**
     * An over-long carrier status is dropped, not cut in half.
     *
     * A truncated status still reads as a status — just a different one — and this string is also
     * what the application records as a cancellation reason on screen, so a plausible-looking
     * fragment is worse than an absent one.
     */
    public function testAnOverlongCarrierStatusIsDroppedRatherThanTruncated(): void
    {
        $report = ShipmentStatusReport::reporting(ShipmentState::InTransit, null, str_repeat('S', 400));

        self::assertSame(ShipmentState::InTransit, $report->state());
        self::assertSame(ShipmentState::InTransit->value, $report->providerStatus(), 'A dropped status falls back to the store state name, not a fragment.');
        self::assertSame('unrecognised', ShipmentStatusReport::unrecognised(str_repeat('S', 400))->providerStatus());
    }

    public function testAStatusRequestAddressesOneParcel(): void
    {
        $request = new ShipmentStatusRequest('fake', 'SHIP-1');

        self::assertSame('fake', $request->providerKey());
        self::assertSame('SHIP-1', $request->providerReference());
    }

    public function testALabelMustPointAtAnAbsoluteHttpsDocument(): void
    {
        $label = new ShipmentLabel('TRK-9', 'https://carrier.test/label/1.pdf');

        self::assertSame('TRK-9', $label->trackingNumber());
        self::assertSame('https://carrier.test/label/1.pdf', $label->documentUrl());
    }

    public function testALabelThatIsNotHttpsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShipmentLabel('TRK-9', 'http://carrier.test/label/1.pdf');
    }

    public function testALabelWithoutATrackingNumberIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShipmentLabel('  ', 'https://carrier.test/label/1.pdf');
    }

    public function testALabelRequestAddressesOneParcel(): void
    {
        $request = new ShipmentLabelRequest('fake', 'SHIP-1');

        self::assertSame('fake', $request->providerKey());
        self::assertSame('SHIP-1', $request->providerReference());
    }

    /** @param list<ShipmentItem>|null $items */
    private function instruction(?array $items = null, ?Money $declaredValue = null): ShipmentCreationInstruction
    {
        return new ShipmentCreationInstruction(
            'fake',
            'shipment-EOA-20260928-ABCDEF123456',
            'EOA-20260928-ABCDEF123456',
            'local_standard',
            'Yerel standart teslimat',
            $this->address(),
            $items ?? [new ShipmentItem('BRK-1', 'Fren balatası', 2)],
            $declaredValue ?? Money::ofMinor(30_000, 'TRY'),
        );
    }

    private function address(): ShipmentAddress
    {
        return new ShipmentAddress('Efe Yılmaz', '05000000000', 'Atatürk Cad. 1', null, 'Seyhan', 'Adana', '01000', 'TR');
    }
}
