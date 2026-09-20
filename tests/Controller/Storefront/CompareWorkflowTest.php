<?php

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompareWorkflowTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testGuestComparesProductsUsingDeterministicCatalogAttributes(): void
    {
        $first = $this->product('COMPARE-001', 'Ön Amortisör', 'on-amortisor', ['aks' => 'Ön', 'uzunluk' => '540 mm']);
        $second = $this->product('COMPARE-002', 'Arka Amortisör', 'arka-amortisor', ['aks' => 'Arka', 'malzeme' => 'Çelik']);

        foreach ([$first, $second] as $product) {
            $crawler = $this->client->request('GET', '/yeni/urun/'.$product->slug());
            self::assertSelectorExists('.compare-add-form');
            $this->client->submit($crawler->selectButton('Karşılaştırmaya ekle')->form());
            self::assertResponseRedirects('/yeni/karsilastir');
        }

        $this->client->followRedirect();
        self::assertSelectorCount(2, '.compare-product');
        self::assertSelectorCount(2, '.compare-table thead th > .compare-product');
        self::assertSelectorTextContains('.compare-table', 'Ön Amortisör');
        self::assertSelectorTextContains('.compare-table', 'Arka Amortisör');
        self::assertSelectorTextContains('.compare-table', 'Aks');
        self::assertSelectorTextContains('.compare-table', 'Uzunluk');
        self::assertSelectorTextContains('.compare-table', 'Malzeme');
        self::assertSelectorTextContains('.compare-table', '540 mm');
        self::assertSelectorTextContains('.compare-table', 'Çelik');
    }

    public function testComparisonIsBoundedToFourProducts(): void
    {
        for ($index = 1; $index <= 5; ++$index) {
            $product = $this->product(
                'COMPARE-LIMIT-'.$index,
                'Karşılaştırma Ürünü '.$index,
                'karsilastirma-urunu-'.$index,
                [],
            );
            $crawler = $this->client->request('GET', '/yeni/urun/'.$product->slug());
            $this->client->submit($crawler->selectButton('Karşılaştırmaya ekle')->form());
            self::assertResponseRedirects('/yeni/karsilastir');
        }

        $this->client->followRedirect();
        self::assertSelectorCount(4, '.compare-product');
        self::assertSelectorTextContains('.storefront-flash-error', 'En fazla 4');
        self::assertSelectorTextNotContains('.compare-table', 'Karşılaştırma Ürünü 5');
    }

    public function testGuestCanRemoveAProductFromComparison(): void
    {
        $product = $this->product('COMPARE-REMOVE', 'Karşılaştırmadan Çıkacak', 'karsilastirmadan-cikacak', []);
        $crawler = $this->client->request('GET', '/yeni/urun/'.$product->slug());
        $this->client->submit($crawler->selectButton('Karşılaştırmaya ekle')->form());

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Kaldır')->form());

        self::assertResponseRedirects('/yeni/karsilastir');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.cart-empty', 'Karşılaştırma listeniz boş');
    }

    /** @param array<string, string> $attributes */
    private function product(string $sku, string $name, string $slug, array $attributes): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        foreach ($attributes as $key => $value) {
            $product->setAttribute($key, $value);
        }
        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductPrice(
            $product,
            Money::ofMinor(30_000, 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        ));
        $this->entityManager->persist(new ProductInventory($product, 2));
        $this->entityManager->flush();

        return $product;
    }
}
