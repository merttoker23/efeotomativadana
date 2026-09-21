<?php

declare(strict_types=1);

namespace App\Module\Checkout;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\CustomerOrder;
use App\Entity\Customer\CustomerAddress;
use App\Entity\Customer\CustomerUser;
use App\Module\Cart\CartRepositoryInterface;
use App\Module\Catalog\PublicationStatus;
use App\Module\Inventory\ProductInventoryRepositoryInterface;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderNumberGenerator;
use App\Module\Order\OrderRepositoryInterface;
use App\Module\Pricing\LineTotalCalculator;
use App\Module\Pricing\PricingQuery;
use App\Repository\Customer\CustomerAddressRepository;
use App\Shared\Money\Money;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class CheckoutManager
{
    /** @var array<string, ShippingOptionInterface> */
    private array $shippingOptions = [];

    /** @var array<string, PaymentOptionInterface> */
    private array $paymentOptions = [];

    /**
     * @param iterable<ShippingOptionInterface> $shippingOptions
     * @param iterable<PaymentOptionInterface>  $paymentOptions
     */
    public function __construct(
        private readonly CartRepositoryInterface $carts,
        private readonly CustomerAddressRepository $addresses,
        private readonly ProductInventoryRepositoryInterface $inventory,
        private readonly PricingQuery $pricing,
        private readonly LineTotalCalculator $lineTotals,
        private readonly OrderNumberGenerator $numbers,
        private readonly OrderRepositoryInterface $orders,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[AutowireIterator('app.checkout.shipping_option')] iterable $shippingOptions,
        #[AutowireIterator('app.checkout.payment_option')] iterable $paymentOptions,
    ) {
        foreach ($shippingOptions as $option) {
            $this->shippingOptions[$option->key()] = $option;
        }
        foreach ($paymentOptions as $option) {
            $this->paymentOptions[$option->key()] = $option;
        }
    }

    public function view(CustomerUser $customer): CheckoutView
    {
        $this->assertActiveCustomer($customer);

        return new CheckoutView(
            $this->addresses->findForCustomer($customer),
            array_values(array_filter($this->shippingOptions, static fn (ShippingOptionInterface $option): bool => $option->available())),
            array_values(array_filter($this->paymentOptions, static fn (PaymentOptionInterface $option): bool => $option->available())),
        );
    }

    public function place(CustomerUser $customer, CheckoutSelection $selection): CustomerOrder
    {
        $this->assertActiveCustomer($customer);

        $shippingAddress = $this->ownedAddress($customer, $selection->shippingAddressId);
        $billingAddress = $this->ownedAddress($customer, $selection->billingAddressId);
        $shippingOption = $this->shippingOptions[$selection->shippingOptionKey] ?? null;
        $paymentOption = $this->paymentOptions[$selection->paymentOptionKey] ?? null;
        if (null === $shippingOption || !$shippingOption->available()) {
            throw new CheckoutViolation('Seçilen teslimat yöntemi kullanılamıyor.');
        }
        if (null === $paymentOption || !$paymentOption->available()) {
            throw new CheckoutViolation('Seçilen ödeme yöntemi kullanılamıyor.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($customer, $shippingAddress, $billingAddress, $shippingOption, $paymentOption): CustomerOrder {
            $cart = $this->carts->findOneByCustomerForUpdate($customer);
            if (null === $cart || [] === $cart->items()) {
                throw new CheckoutViolation('Sepetiniz boş.');
            }

            $subtotal = null;
            $taxTotal = null;
            /** @var list<array{Product, int, \App\Module\Pricing\LineTotals, int}> $lines */
            $lines = [];
            $items = $cart->items();
            usort($items, static fn ($left, $right): int => ($left->product()->id() ?? 0) <=> ($right->product()->id() ?? 0));
            foreach ($items as $item) {
                $product = $item->product();
                $this->entityManager->refresh($product, LockMode::PESSIMISTIC_WRITE);
                $price = $this->pricing->forProductForUpdate($product);
                $inventory = $this->inventory->findOneByProductForUpdate($product);
                if (PublicationStatus::Published !== $product->publicationStatus() || null === $price) {
                    throw new CheckoutViolation(sprintf('%s artık satışa uygun değil.', $product->name()));
                }
                if (null === $inventory || !$inventory->isSellable() || $inventory->quantity() < $item->quantity()) {
                    throw new CheckoutViolation(sprintf('%s için yeterli stok bulunmuyor.', $product->name()));
                }

                $totals = $this->lineTotals->calculate($price->sellPrice(), $price->taxRate(), $item->quantity());
                if (null !== $subtotal && $subtotal->currency() !== $totals->gross()->currency()) {
                    throw new CheckoutViolation('Sepette farklı para birimleri birlikte kullanılamaz.');
                }
                $subtotal ??= Money::ofMinor(0, $totals->gross()->currency());
                $taxTotal ??= Money::ofMinor(0, $totals->gross()->currency());
                $subtotal = $subtotal->add($totals->gross());
                $taxTotal = $taxTotal->add($totals->tax());
                $inventory->adjust(-$item->quantity());
                $lines[] = [$product, $price->taxRate()->basisPoints(), $totals, $item->quantity()];
            }

            $shippingCost = $shippingOption->cost($subtotal->currency());
            $grandTotal = $subtotal->add($shippingCost);
            $order = new CustomerOrder(
                $this->numbers->generate(),
                $customer,
                $subtotal,
                $taxTotal,
                $shippingCost,
                $grandTotal,
                $shippingOption->key(),
                $shippingOption->label(),
                $paymentOption->key(),
                $paymentOption->label(),
                \DateTimeImmutable::createFromInterface($this->clock->now()),
            );

            foreach ($lines as [$product, $taxRateBasisPoints, $totals, $quantity]) {
                $order->addItem($product, $product->sku(), $product->name(), $quantity, $totals->unitGross(), $taxRateBasisPoints, $totals->net(), $totals->tax(), $totals->gross());
            }
            $this->snapshotAddress($order, OrderAddressRole::Shipping, $shippingAddress);
            $this->snapshotAddress($order, OrderAddressRole::Billing, $billingAddress);
            $order->sealSnapshots();
            $this->orders->save($order);
            $this->carts->remove($cart);
            $this->entityManager->flush();

            return $order;
        });
    }

    private function ownedAddress(CustomerUser $customer, int $addressId): CustomerAddress
    {
        $address = $this->addresses->find($addressId);
        if (null === $address || $address->customer()->id() !== $customer->id()) {
            throw new CheckoutViolation('Seçilen adres hesabınıza ait değil.');
        }

        return $address;
    }

    private function assertActiveCustomer(CustomerUser $customer): void
    {
        if (!$customer->isActive()) {
            throw new CheckoutViolation('Müşteri hesabınız aktif değil.');
        }
    }

    private function snapshotAddress(CustomerOrder $order, OrderAddressRole $role, CustomerAddress $address): void
    {
        $order->addAddress($role, $address->recipientName(), $address->phone(), $address->addressLine1(), $address->addressLine2(), $address->district(), $address->city(), $address->postalCode(), $address->countryCode());
    }
}
