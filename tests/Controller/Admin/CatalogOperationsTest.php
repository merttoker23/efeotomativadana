<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Catalog\Product;
use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\AdminUser;
use App\Module\Catalog\ProductIdentifierType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class CatalogOperationsTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAdminCommerceRoutesRejectAnonymousUsers(): void
    {
        foreach (['/yeni/admin/catalog/products', '/yeni/admin/catalog/categories', '/yeni/admin/catalog/brands', '/yeni/admin/customers', '/yeni/admin/orders'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseRedirects('/yeni/admin/login');
        }
    }

    public function testCustomerRoleCannotAccessAdminCommerceRoutes(): void
    {
        $this->client->loginUser(new InMemoryUser('viewer@example.com', 'test-only-not-used-for-form-login', ['ROLE_USER']), 'admin');
        $this->client->request('GET', '/yeni/admin/catalog/products');
        self::assertResponseStatusCodeSame(403);
    }

    public function testProductListIsFilteredAndBoundedToTwentyRows(): void
    {
        $this->loginAdmin();
        for ($index = 1; $index <= 23; ++$index) {
            $product = new Product(sprintf('PAGE-%03d', $index), sprintf('Pagination Part %03d', $index), sprintf('pagination-part-%03d', $index));
            $this->entityManager->persist($product);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/products?q=Pagination');

        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('[data-testid="product-row"]'));
        self::assertSelectorExists('a[rel="next"]');
        self::assertSelectorTextContains('[data-testid="result-summary"]', '23');
    }

    public function testAdminCanCreateACompleteProviderIndependentProduct(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/products/new');
        $form = $crawler->selectButton('Save product')->form([
            'admin_product[sku]' => 'LOCAL-001',
            'admin_product[name]' => 'Local Brake Disc',
            'admin_product[slug]' => 'local-brake-disc',
            'admin_product[description]' => 'Locally managed replacement part.',
            'admin_product[published]' => '1',
            'admin_product[manufacturerCode]' => 'MFG-101',
            'admin_product[oemCodes]' => "OEM-1\nOEM-2",
            'admin_product[referenceCodes]' => 'REF-7',
            'admin_product[imagePaths]' => "/assets/storefront/images/local-disc.webp|Brake disc\n/assets/storefront/images/local-disc-side.webp|Side view",
            'admin_product[baseMinorAmount]' => '125000',
            'admin_product[currency]' => 'TRY',
            'admin_product[taxCategory]' => 'replacement-part',
            'admin_product[taxRateBasisPoints]' => '2000',
            'admin_product[saleMinorAmount]' => '110000',
            'admin_product[quantity]' => '12',
            'admin_product[availableForSale]' => '1',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['sku' => 'LOCAL-001']);
        self::assertInstanceOf(Product::class, $product);
        self::assertSame('local', $product->source()->value);
        self::assertSame('published', $product->publicationStatus()->value);
        self::assertCount(2, $product->images());
        self::assertCount(4, $product->identifiers());
        self::assertSame(['MFG-101'], array_map(
            static fn ($identifier): string => $identifier->code(),
            array_values(array_filter($product->identifiers(), static fn ($identifier): bool => ProductIdentifierType::Manufacturer === $identifier->type())),
        ));

        $price = $this->entityManager->getRepository(ProductPrice::class)->findOneBy(['product' => $product]);
        self::assertInstanceOf(ProductPrice::class, $price);
        self::assertSame(125000, $price->basePrice()->minorAmount());
        self::assertSame(110000, $price->salePrice()?->minorAmount());

        $inventory = $this->entityManager->getRepository(ProductInventory::class)->findOneBy(['product' => $product]);
        self::assertInstanceOf(ProductInventory::class, $inventory);
        self::assertSame(12, $inventory->quantity());
        self::assertTrue($inventory->availableForSale());
    }

    public function testAdminCanCreateBrandAndCategoryWithValidatedSlugs(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/brands/new');
        $this->client->submit($crawler->selectButton('Save brand')->form([
            'admin_brand[name]' => 'Local Brand',
            'admin_brand[slug]' => 'local-brand',
            'admin_brand[published]' => '1',
        ]));
        self::assertResponseRedirects();
        self::assertInstanceOf(Brand::class, $this->entityManager->getRepository(Brand::class)->findOneBy(['slug' => 'local-brand']));

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/categories/new');
        $this->client->submit($crawler->selectButton('Save category')->form([
            'admin_category[name]' => 'Brake Systems',
            'admin_category[slug]' => 'brake-systems',
            'admin_category[published]' => '1',
        ]));
        self::assertResponseRedirects();
        self::assertInstanceOf(Category::class, $this->entityManager->getRepository(Category::class)->findOneBy(['slug' => 'brake-systems']));
    }

    public function testStaleInventoryFormCannotOverwriteNewerStock(): void
    {
        $this->loginAdmin();
        $product = new Product('STALE-001', 'Stale Stock Product', 'stale-stock-product');
        $inventory = new ProductInventory($product, 5, true);
        $price = new ProductPrice($product, \App\Shared\Money\Money::ofMinor(10000, 'TRY'), \App\Module\Pricing\TaxCategory::of('replacement-part'), \App\Module\Pricing\TaxRate::fromBasisPoints(2000));
        foreach ([$product, $inventory, $price] as $entity) { $this->entityManager->persist($entity); }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/products/'.$product->id().'/edit');
        $form = $crawler->selectButton('Save product')->form();
        $this->connection->executeStatement('UPDATE commerce_product_inventory SET quantity = 9, version = version + 1 WHERE product_id = ?', [$product->id()]);
        $form['admin_product[quantity]'] = '3';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(9, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_product_inventory WHERE product_id = ?', [$product->id()]));
    }

    public function testDuplicateSlugIsRejectedWithoutCreatingPartialCommerceRows(): void
    {
        $this->loginAdmin();
        $existing = new Product('EXISTING-001', 'Existing', 'shared-safe-slug');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/products/new');
        $form = $crawler->selectButton('Save product')->form([
            'admin_product[sku]' => 'NEW-002',
            'admin_product[name]' => 'New Product',
            'admin_product[slug]' => 'shared-safe-slug',
            'admin_product[baseMinorAmount]' => '10000',
            'admin_product[currency]' => 'TRY',
            'admin_product[taxCategory]' => 'replacement-part',
            'admin_product[taxRateBasisPoints]' => '2000',
            'admin_product[quantity]' => '1',
            'admin_product[availableForSale]' => '1',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'slug');
        self::assertNull($this->entityManager->getRepository(Product::class)->findOneBy(['sku' => 'NEW-002']));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_product_price'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_product_inventory'));
    }

    public function testEditingAProductRetainsUnchangedIdentifiersWithoutDatabaseConflict(): void
    {
        $this->loginAdmin();
        $product = new Product('EDIT-001', 'Editable Product', 'editable-product');
        $product->addIdentifier(ProductIdentifierType::Manufacturer, 'MFG-KEEP');
        $product->addIdentifier(ProductIdentifierType::Oem, 'OEM-KEEP');
        $price = new ProductPrice($product, \App\Shared\Money\Money::ofMinor(10000, 'TRY'), \App\Module\Pricing\TaxCategory::of('replacement-part'), \App\Module\Pricing\TaxRate::fromBasisPoints(2000));
        $inventory = new ProductInventory($product, 4, true);
        foreach ([$product, $price, $inventory] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $identifierIds = array_map(static fn ($identifier): ?int => $identifier->id(), $product->identifiers());

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/products/'.$product->id().'/edit');
        $form = $crawler->selectButton('Save product')->form();
        $form['admin_product[name]'] = 'Edited Product';
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame('Edited Product', $this->connection->fetchOne('SELECT name FROM catalog_product WHERE id = ?', [$product->id()]));
        self::assertSame($identifierIds, array_map(static fn ($identifier): ?int => $identifier->id(), $product->identifiers()));
    }

    public function testPublishedCatalogSlugsCannotBeChangedWithoutRedirectHistory(): void
    {
        $this->loginAdmin();
        $product = new Product('STABLE-001', 'Stable Product', 'stable-product');
        $product->publish();
        $price = new ProductPrice($product, \App\Shared\Money\Money::ofMinor(10000, 'TRY'), \App\Module\Pricing\TaxCategory::of('replacement-part'), \App\Module\Pricing\TaxRate::fromBasisPoints(2000));
        $inventory = new ProductInventory($product, 4, true);
        $brand = new Brand('Stable Brand', 'stable-brand');
        $brand->publish();
        $category = new Category('Stable Category', 'stable-category');
        $category->publish();
        foreach ([$product, $price, $inventory, $brand, $category] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/products/'.$product->id().'/edit');
        $form = $crawler->selectButton('Save product')->form();
        $form['admin_product[slug]'] = 'changed-product';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/brands/'.$brand->id().'/edit');
        $form = $crawler->selectButton('Save brand')->form();
        $form['admin_brand[slug]'] = 'changed-brand';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/categories/'.$category->id().'/edit');
        $form = $crawler->selectButton('Save category')->form();
        $form['admin_category[slug]'] = 'changed-category';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        self::assertSame('stable-product', $this->connection->fetchOne('SELECT slug FROM catalog_product WHERE id = ?', [$product->id()]));
        self::assertSame('stable-brand', $this->connection->fetchOne('SELECT slug FROM catalog_brand WHERE id = ?', [$brand->id()]));
        self::assertSame('stable-category', $this->connection->fetchOne('SELECT slug FROM catalog_category WHERE id = ?', [$category->id()]));
    }

    public function testBlankInventoryVersionCannotBypassStaleStockProtection(): void
    {
        $this->loginAdmin();
        $product = new Product('BYPASS-001', 'Protected Stock Product', 'protected-stock-product');
        $inventory = new ProductInventory($product, 5, true);
        $price = new ProductPrice($product, \App\Shared\Money\Money::ofMinor(10000, 'TRY'), \App\Module\Pricing\TaxCategory::of('replacement-part'), \App\Module\Pricing\TaxRate::fromBasisPoints(2000));
        foreach ([$product, $inventory, $price] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/products/'.$product->id().'/edit');
        $form = $crawler->selectButton('Save product')->form();
        $this->connection->executeStatement('UPDATE commerce_product_inventory SET quantity = 9, version = version + 1 WHERE product_id = ?', [$product->id()]);
        $form['admin_product[quantity]'] = '3';
        $form['admin_product[inventoryVersion]'] = '';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(9, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_product_inventory WHERE product_id = ?', [$product->id()]));
    }

    private function loginAdmin(): void
    {
        $admin = new AdminUser('phase09-admin@example.com');
        $admin->setPassword('test-password-hash');
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin, 'admin');
    }
}
