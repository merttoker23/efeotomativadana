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
use App\Module\Catalog\BrandLogoStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
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
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Ürünü kaydet')->form([
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
        $this->client->submit($crawler->selectButton('Markayı kaydet')->form([
            'admin_brand[name]' => 'Local Brand',
            'admin_brand[slug]' => 'local-brand',
            'admin_brand[published]' => '1',
        ]));
        self::assertResponseRedirects();
        self::assertInstanceOf(Brand::class, $this->entityManager->getRepository(Brand::class)->findOneBy(['slug' => 'local-brand']));

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/categories/new');
        $this->client->submit($crawler->selectButton('Kategoriyi kaydet')->form([
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
        $form = $crawler->selectButton('Ürünü kaydet')->form();
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
        $form = $crawler->selectButton('Ürünü kaydet')->form([
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
        $form = $crawler->selectButton('Ürünü kaydet')->form();
        $form['admin_product[name]'] = 'Edited Product';
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame('Edited Product', $this->connection->fetchOne('SELECT name FROM catalog_product WHERE id = ?', [$product->id()]));
        self::assertSame($identifierIds, array_map(static fn ($identifier): ?int => $identifier->id(), $product->identifiers()));
    }

    /**
     * The old rule refused to rename a published record at all, which protected the URL but
     * also meant a B2B feed could never correct a product name without a human deciding it
     * was time to break the link. Now that redirect history exists, the rename goes through
     * and the old address is kept.
     */
    public function testRenamingAPublishedCatalogRecordKeepsItsOldUrlInRedirectHistory(): void
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
        $form = $crawler->selectButton('Ürünü kaydet')->form();
        $form['admin_product[slug]'] = 'changed-product';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/brands/'.$brand->id().'/edit');
        $form = $crawler->selectButton('Markayı kaydet')->form();
        $form['admin_brand[slug]'] = 'changed-brand';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/categories/'.$category->id().'/edit');
        $form = $crawler->selectButton('Kategoriyi kaydet')->form();
        $form['admin_category[slug]'] = 'changed-category';
        $this->client->submit($form);
        self::assertResponseRedirects();

        self::assertSame('changed-product', $this->connection->fetchOne('SELECT slug FROM catalog_product WHERE id = ?', [$product->id()]));
        self::assertSame('changed-brand', $this->connection->fetchOne('SELECT slug FROM catalog_brand WHERE id = ?', [$brand->id()]));
        self::assertSame('changed-category', $this->connection->fetchOne('SELECT slug FROM catalog_category WHERE id = ?', [$category->id()]));

        self::assertSame(
            [
                ['resource_type' => 'brand', 'old_slug' => 'stable-brand'],
                ['resource_type' => 'category', 'old_slug' => 'stable-category'],
                ['resource_type' => 'product', 'old_slug' => 'stable-product'],
            ],
            $this->connection->fetchAllAssociative(
                'SELECT resource_type, old_slug FROM seo_slug_redirect WHERE resource_type IN (?, ?, ?) ORDER BY resource_type',
                ['product', 'category', 'brand'],
            ),
        );
    }

    public function testRenamingADraftCatalogRecordRecordsNoHistoryBecauseItWasNeverPublic(): void
    {
        $this->loginAdmin();
        $product = new Product('DRAFT-001', 'Draft Product', 'draft-product');
        $price = new ProductPrice($product, \App\Shared\Money\Money::ofMinor(10000, 'TRY'), \App\Module\Pricing\TaxCategory::of('replacement-part'), \App\Module\Pricing\TaxRate::fromBasisPoints(2000));
        $inventory = new ProductInventory($product, 4, true);
        foreach ([$product, $price, $inventory] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/products/'.$product->id().'/edit');
        $form = $crawler->selectButton('Ürünü kaydet')->form();
        $form['admin_product[slug]'] = 'renamed-draft-product';
        $this->client->submit($form);
        self::assertResponseRedirects();

        self::assertSame('renamed-draft-product', $this->connection->fetchOne('SELECT slug FROM catalog_product WHERE id = ?', [$product->id()]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM seo_slug_redirect WHERE old_slug = ?', ['draft-product']));
    }

    public function testRenamingAndUnpublishingInOneSaveRecordsNoRedirect(): void
    {
        $this->loginAdmin();
        $product = new Product('WITHDRAW-001', 'Withdrawn Product', 'withdrawn-product');
        $product->publish();
        $price = new ProductPrice($product, \App\Shared\Money\Money::ofMinor(10000, 'TRY'), \App\Module\Pricing\TaxCategory::of('replacement-part'), \App\Module\Pricing\TaxRate::fromBasisPoints(2000));
        $inventory = new ProductInventory($product, 4, true);
        foreach ([$product, $price, $inventory] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/yeni/admin/catalog/products/'.$product->id().'/edit');
        $form = $crawler->selectButton('Ürünü kaydet')->form();
        $form['admin_product[slug]'] = 'withdrawn-product-2';
        $published = $form['admin_product[published]'];
        self::assertInstanceOf(ChoiceFormField::class, $published);
        $published->untick();
        $this->client->submit($form);
        self::assertResponseRedirects();

        // The record is no longer at a public address, so a history row would point at a
        // target that can never resolve: the old URL would 404 through a redirect, and the
        // table would grow by one dead entry every time this was done.
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM seo_slug_redirect WHERE old_slug = ?', ['withdrawn-product']));
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
        $form = $crawler->selectButton('Ürünü kaydet')->form();
        $this->connection->executeStatement('UPDATE commerce_product_inventory SET quantity = 9, version = version + 1 WHERE product_id = ?', [$product->id()]);
        $form['admin_product[quantity]'] = '3';
        $form['admin_product[inventoryVersion]'] = '';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(9, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_product_inventory WHERE product_id = ?', [$product->id()]));
    }

    public function testBrandLogoCanBeUploadedOnCreateReplacedOnEditAndPreservedWhenOmitted(): void
    {
        $this->loginAdmin();
        $upload = tempnam(sys_get_temp_dir(), 'brand-logo-');
        self::assertIsString($upload);
        $image = imagecreatetruecolor(2, 1);
        self::assertNotFalse($image);
        $target = null;
        try {
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
            imagepng($image, $upload);
            $crawler = $this->client->request('GET', '/yeni/admin/catalog/brands/new');
            self::assertSelectorExists('input[type="file"][name="admin_brand[logo]"]');
            self::assertSelectorExists('form[enctype="multipart/form-data"]');
            $form = $crawler->selectButton('Markayı kaydet')->form(['admin_brand[name]' => 'Upload Brand', 'admin_brand[slug]' => 'upload-brand', 'admin_brand[published]' => '1']);
            $field = $form['admin_brand[logo]'];
            self::assertInstanceOf(FileFormField::class, $field);
            $field->upload($upload);
            $this->client->submit($form);
            $id = (int) $this->connection->fetchOne('SELECT id FROM catalog_brand WHERE slug = ?', ['upload-brand']);
            self::assertResponseRedirects('/yeni/admin/catalog/brands/'.$id.'/edit');
            $target = dirname(__DIR__, 3).'/public/uploads/cms/img/ureticiler/'.$id.'.jpg';
            self::assertFileExists($target);
            self::assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($target));
            $firstBytes = file_get_contents($target);

            $crawler = $this->client->request('GET', '/yeni/admin/catalog/brands/'.$id.'/edit');
            $firstUrl = $crawler->filter('.brand-logo-preview')->attr('src');
            self::assertStringStartsWith('/yeni/uploads/cms/img/ureticiler/'.$id.'.jpg?v=', $firstUrl);
            $form = $crawler->selectButton('Markayı kaydet')->form();
            imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
            imagepng($image, $upload);
            $field = $form['admin_brand[logo]'];
            self::assertInstanceOf(FileFormField::class, $field);
            $field->upload($upload);
            $this->client->submit($form);
            self::assertResponseRedirects();
            self::assertNotSame($firstBytes, file_get_contents($target));

            $crawler = $this->client->request('GET', '/yeni/markalar');
            $url = $crawler->filter('.brand-grid a[href="/yeni/marka/upload-brand#catalog-results"] img')->attr('src');
            self::assertStringStartsWith('/yeni/uploads/cms/img/ureticiler/'.$id.'.jpg?v=', $url);
            self::assertNotSame($firstUrl, $url, 'Replacing a logo must refresh its browser cache URL.');
            $replacementBytes = file_get_contents($target);

            $crawler = $this->client->request('GET', '/yeni/admin/catalog/brands/'.$id.'/edit');
            $this->client->submit($crawler->selectButton('Markayı kaydet')->form(['admin_brand[name]' => 'Updated Upload Brand']));
            self::assertResponseRedirects();
            self::assertSame($replacementBytes, file_get_contents($target));
        } finally {
            @unlink($upload);
            if (null !== $target) { @unlink($target); }
        }
    }

    public function testInvalidBrandLogoAndCsrfAreRejectedBeforeSaving(): void
    {
        $this->loginAdmin();
        $upload = tempnam(sys_get_temp_dir(), 'bad-logo-');
        self::assertIsString($upload);
        file_put_contents($upload, '<?php echo "not an image";');
        try {
            foreach ([false, true] as $invalidCsrf) {
                if ($invalidCsrf) {
                    // A valid image with a bad token must be rejected independently of file validation.
                    file_put_contents($upload, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==', true));
                }
                $crawler = $this->client->request('GET', '/yeni/admin/catalog/brands/new');
                $form = $crawler->selectButton('Markayı kaydet')->form(['admin_brand[name]' => 'Invalid Logo', 'admin_brand[slug]' => 'invalid-logo']);
                $field = $form['admin_brand[logo]'];
                self::assertInstanceOf(FileFormField::class, $field);
                $field->upload($upload);
                if ($invalidCsrf) { $form['admin_brand[_token]'] = 'invalid'; }
                $this->client->submit($form);
                self::assertResponseStatusCodeSame(422);
                self::assertFalse($this->connection->fetchOne('SELECT id FROM catalog_brand WHERE slug = ?', ['invalid-logo']));
            }
        } finally {
            @unlink($upload);
        }
    }

    public function testBrandLogoFormsRejectNonAdministratorPosts(): void
    {
        $brand = new Brand('Protected Logo', 'protected-logo');
        $this->entityManager->persist($brand);
        $this->entityManager->flush();
        foreach (['/yeni/admin/catalog/brands/new', '/yeni/admin/catalog/brands/'.$brand->id().'/edit'] as $uri) {
            $this->client->request('POST', $uri, ['admin_brand' => ['name' => 'Unwanted', 'slug' => 'unwanted']]);
            self::assertResponseRedirects('/yeni/admin/login');
        }
        foreach (['/yeni/admin/catalog/brands/new', '/yeni/admin/catalog/brands/'.$brand->id().'/edit'] as $uri) {
            $this->client->loginUser(new InMemoryUser('viewer@example.com', 'test-only-not-used-for-form-login', ['ROLE_USER']), 'admin');
            $this->client->request('POST', $uri, ['admin_brand' => ['name' => 'Unwanted', 'slug' => 'unwanted']]);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame('Protected Logo', $this->connection->fetchOne('SELECT name FROM catalog_brand WHERE id = ?', [$brand->id()]));
        self::assertFalse($this->connection->fetchOne('SELECT id FROM catalog_brand WHERE slug = ?', ['unwanted']));
    }

    public function testBrandLogoReplacementIsRestoredWhenFinalDatabaseFlushFails(): void
    {
        $this->loginAdmin();
        $brand = new Brand('Rollback Logo', 'rollback-logo');
        $this->entityManager->persist($brand);
        $this->entityManager->flush();
        $upload = tempnam(sys_get_temp_dir(), 'rollback-logo-');
        self::assertIsString($upload);
        $image = imagecreatetruecolor(2, 1);
        self::assertNotFalse($image);
        $target = dirname(__DIR__, 3).'/public/uploads/cms/img/ureticiler/'.$brand->id().'.jpg';
        try {
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
            imagepng($image, $upload);
            self::getContainer()->get(BrandLogoStorage::class)->store((int) $brand->id(), new UploadedFile($upload, 'logo.png', null, null, true));
            $previous = file_get_contents($target);
            self::assertIsString($previous);
            $crawler = $this->client->request('GET', '/yeni/admin/catalog/brands/'.$brand->id().'/edit');
            $form = $crawler->selectButton('Markayı kaydet')->form(['admin_brand[name]' => 'Must Roll Back']);
            imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
            imagepng($image, $upload);
            $field = $form['admin_brand[logo]'];
            self::assertInstanceOf(FileFormField::class, $field);
            $field->upload($upload);
            $this->entityManager->getEventManager()->addEventListener(['preFlush'], new class($target, $previous) {
                public function __construct(private string $file, private string $before) {}
                public function preFlush(): void
                {
                    if (file_get_contents($this->file) !== $this->before) {
                        throw new \RuntimeException('Simulated final database failure.');
                    }
                }
            });
            $this->client->submit($form);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Rollback Logo', $this->connection->fetchOne('SELECT name FROM catalog_brand WHERE id = ?', [$brand->id()]));
            self::assertSame($previous, file_get_contents($target), 'Database rollback must also restore the original logo.');
        } finally {
            @unlink($upload);
            @unlink($target);
        }
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
