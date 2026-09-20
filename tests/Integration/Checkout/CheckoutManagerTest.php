<?php

declare(strict_types=1);

namespace App\Tests\Integration\Checkout;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\Cart;
use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\CustomerAddress;
use App\Entity\Customer\CustomerUser;
use App\Module\Checkout\CheckoutManager;
use App\Module\Checkout\CheckoutSelection;
use App\Module\Checkout\CheckoutViolation;
use App\Module\Order\OrderAddressRole;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CheckoutManagerTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
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

    public function testPlacementUsesCurrentPriceAndCreatesImmutableSnapshotsBeforeConsumingCart(): void
    {
        $customer = $this->customer('checkout@example.com');
        $shipping = $this->address($customer, 'Teslimat', 'Atatürk Cad. 1');
        $billing = $this->address($customer, 'Fatura', 'Fatura Cad. 2');
        [$product, , $inventory] = $this->cartLine($customer, 12_000, 5, 2);

        $this->connection->executeStatement('UPDATE commerce_product_price SET base_minor_amount = 15000 WHERE product_id = ?', [$product->id()]);

        $order = $this->manager()->place($customer, new CheckoutSelection(
            $shipping->id() ?? 0,
            $billing->id() ?? 0,
            'local_standard',
            'local_manual',
        ));

        self::assertSame(30_000, $order->subtotal()->minorAmount());
        self::assertSame(5_000, $order->taxTotal()->minorAmount());
        self::assertSame(30_000, $order->grandTotal()->minorAmount());
        self::assertSame(3, $inventory->quantity());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]));

        $product->changeSku('CHANGED-SKU');
        $product->rename('Değişen Ürün');
        $customer->setProfile('Değişen', 'Müşteri', '05550000000');
        $shipping->update('Yeni', 'Başka Alıcı', '05550000000', 'Yeni Cad. 9', null, 'Yüreğir', 'Adana', null, false);
        $this->entityManager->flush();

        $orderId = $order->id();
        self::assertNotNull($orderId);
        $this->entityManager->clear();
        $persistedOrder = $this->entityManager->find(CustomerOrder::class, $orderId);
        self::assertInstanceOf(CustomerOrder::class, $persistedOrder);
        self::assertSame('CHECKOUT-SKU', $persistedOrder->items()[0]->sku());
        self::assertSame('Checkout Ürünü', $persistedOrder->items()[0]->productName());
        self::assertSame('Atatürk Cad. 1', $persistedOrder->address(OrderAddressRole::Shipping)?->addressLine1());
        self::assertSame('Fatura Cad. 2', $persistedOrder->address(OrderAddressRole::Billing)?->addressLine1());
        self::assertSame('Efe Yılmaz', $persistedOrder->customerName());
        self::assertNull($persistedOrder->customerPhone());

        $this->expectException(\DomainException::class);
        $persistedOrder->addItem(null, 'LATE-SKU', 'Late item', 1, Money::ofMinor(0, 'TRY'), 0, Money::ofMinor(0, 'TRY'), Money::ofMinor(0, 'TRY'), Money::ofMinor(0, 'TRY'));
    }

    public function testPlacementRefreshesPublicationStateBeforeCreatingOrder(): void
    {
        $customer = $this->customer('publication@example.com');
        $address = $this->address($customer, 'Ev', 'Yayın Cad. 1');
        [$product, , $inventory] = $this->cartLine($customer, 10_000, 3, 1);
        $this->connection->executeStatement("UPDATE catalog_product SET publication_status = 'draft' WHERE id = ?", [$product->id()]);

        try {
            $this->manager()->place($customer, new CheckoutSelection($address->id() ?? 0, $address->id() ?? 0, 'local_standard', 'local_manual'));
            self::fail('A product unpublished outside the managed entity must be rejected.');
        } catch (CheckoutViolation $exception) {
            self::assertStringContainsString('satışa uygun değil', $exception->getMessage());
        }

        self::assertSame(3, $inventory->quantity());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));
    }

    public function testHighValueOrderPersistsBeyondSignedIntegerRange(): void
    {
        $customer = $this->customer('high-value@example.com');
        $address = $this->address($customer, 'İş', 'Büyük Cad. 1');
        $this->cartLine($customer, 3_000_000_000, 1, 1);

        $order = $this->manager()->place($customer, new CheckoutSelection($address->id() ?? 0, $address->id() ?? 0, 'local_standard', 'local_manual'));

        self::assertSame(3_000_000_000, $order->grandTotal()->minorAmount());
        self::assertSame('3000000000', (string) $this->connection->fetchOne('SELECT grand_total_minor_amount FROM commerce_customer_order WHERE id = ?', [$order->id()]));
    }

    public function testForeignAddressIsRejectedWithoutChangingOrderStockOrCart(): void
    {
        $customer = $this->customer('owner@example.com');
        $owned = $this->address($customer, 'Ev', 'Sahip Cad. 1');
        $attacker = $this->customer('attacker@example.com');
        $foreign = $this->address($attacker, 'Yabancı', 'Başka Cad. 8');
        [, , $inventory] = $this->cartLine($customer, 10_000, 4, 2);

        try {
            $this->manager()->place($customer, new CheckoutSelection($foreign->id() ?? 0, $owned->id() ?? 0, 'local_standard', 'local_manual'));
            self::fail('A foreign address must be rejected.');
        } catch (CheckoutViolation $exception) {
            self::assertStringContainsString('adres', mb_strtolower($exception->getMessage()));
        }

        self::assertSame(4, $inventory->quantity());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]));
    }

    public function testStaleStockRollsBackWithoutConsumingTheCart(): void
    {
        $customer = $this->customer('stock@example.com');
        $address = $this->address($customer, 'Ev', 'Stok Cad. 3');
        [, , $inventory] = $this->cartLine($customer, 10_000, 3, 2);
        $inventory->replace(1, true);
        $this->entityManager->flush();

        $this->expectException(CheckoutViolation::class);
        try {
            $this->manager()->place($customer, new CheckoutSelection($address->id() ?? 0, $address->id() ?? 0, 'local_standard', 'local_manual'));
        } finally {
            self::assertSame(1, $inventory->quantity());
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart WHERE customer_id = ?', [$customer->id()]));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_customer_order WHERE customer_id = ?', [$customer->id()]));
        }
    }

    public function testDuplicateOrderNumberIsRejectedByPersistence(): void
    {
        $customer = $this->customer('duplicate-number@example.com');
        $zero = Money::ofMinor(0, 'TRY');
        foreach ([1, 2] as $attempt) {
            $order = new CustomerOrder(
                'EOA-20260920-ABCDEF123456',
                $customer,
                $zero,
                $zero,
                $zero,
                $zero,
                'local_standard',
                'Yerel standart teslimat',
                'local_manual',
                'Yerel manuel doğrulama (üretim dışı)',
                new \DateTimeImmutable(sprintf('2026-09-20 12:00:0%d UTC', $attempt)),
            );
            $order->addItem(null, 'DUPLICATE-SKU', 'Duplicate number item', 1, $zero, 0, $zero, $zero, $zero);
            $order->addAddress(OrderAddressRole::Shipping, 'Efe Yılmaz', '05000000000', 'Cadde 1', null, 'Seyhan', 'Adana', null, 'TR');
            $order->addAddress(OrderAddressRole::Billing, 'Efe Yılmaz', '05000000000', 'Cadde 1', null, 'Seyhan', 'Adana', null, 'TR');
            $order->sealSnapshots();
            $this->entityManager->persist($order);
            if (1 === $attempt) {
                $this->entityManager->flush();
            }
        }

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    private function manager(): CheckoutManager
    {
        $manager = self::getContainer()->get(CheckoutManager::class);
        self::assertInstanceOf(CheckoutManager::class, $manager);

        return $manager;
    }

    private function customer(string $email): CustomerUser
    {
        $customer = new CustomerUser($email, 'Efe', 'Yılmaz');
        $customer->setPassword('test-password-hash');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function address(CustomerUser $customer, string $label, string $line): CustomerAddress
    {
        $address = new CustomerAddress($customer);
        $address->update($label, 'Efe Yılmaz', '05000000000', $line, null, 'Seyhan', 'Adana', '01000', false);
        $this->entityManager->persist($address);
        $this->entityManager->flush();

        return $address;
    }

    /** @return array{Product, ProductPrice, ProductInventory} */
    private function cartLine(CustomerUser $customer, int $minorAmount, int $stock, int $quantity): array
    {
        $product = new Product('CHECKOUT-SKU', 'Checkout Ürünü', 'checkout-urunu');
        $product->publish();
        $price = new ProductPrice($product, Money::ofMinor($minorAmount, 'TRY'), TaxCategory::of('replacement-part'), TaxRate::fromBasisPoints(2_000));
        $inventory = new ProductInventory($product, $stock);
        $cart = new Cart($customer);
        $cart->add($product, $quantity);
        foreach ([$product, $price, $inventory, $cart] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$product, $price, $inventory];
    }
}
