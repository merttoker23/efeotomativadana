<?php

namespace App\Command;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use App\Twig\StorefrontMediaExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Generates a synthetic catalogue large enough to make pagination and N+1 defects visible.
 *
 * It writes nothing that resembles real stock: every name, SKU and slug is derived
 * from a counter, and the whole run is refused outside dev/test/local. The point is to have a
 * database whose size is representative enough that a per-row query or an unbounded list costs
 * something measurable, which an empty test database can never show.
 *
 * Products are written in batches and the identity map is cleared between them, because a
 * command that builds five thousand entities without clearing would exhaust memory in a way the
 * storefront never would.
 */
#[AsCommand(
    name: 'app:catalog:seed-demo',
    description: 'Generate a synthetic catalogue for local performance work.',
)]
final class SeedDemoCatalogCommand extends Command
{
    /** Turkish automotive part categories, so the storefront reads plausibly while browsing it. */
    private const CATEGORIES = [
        'Fren Sistemi', 'Filtreler', 'Motor İç Parçaları', 'Süspansiyon', 'Elektrik Aksamı',
        'Yağlama Sistemi', 'Soğutma Sistemi', 'Egzoz Sistemi', 'Lastik ve Jant', 'Aydınlatma',
        'İç Trim', 'Dış Trim', 'Röle ve Sigorta', 'Kavrama Sistemi', 'Şanzıman',
        'Diferansiyel', 'Yakıt Sistemi', 'Emniyet Kemeri', 'Jant ve aksesuar', 'Motor Kapağı',
        'Karter', 'Turboşarjör', 'Radyatör Fan', 'Kayış Gergi', 'Koncu ve boru',
    ];

    private const BRANDS = [
        'Bosch', 'Continental', 'Denso', 'Valeo', 'Mahle', 'Brembo', 'TRW', 'ATE', 'Febi',
        'SKF', 'FAG', 'Sachs', 'Textar', 'Ferodo', 'Pagid', 'EBC', 'Elring', 'Victor Reinz',
        'Aisin', 'LUK', 'ZF', 'Gates', 'ContiTech', 'Pierburg', 'Delphi', 'Valeo Service',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('products', null, InputOption::VALUE_REQUIRED, 'How many products to generate', '2000')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Products per flush', '200')
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'SKU/slug prefix so a seeded store can be recognised', 'DEMO');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!in_array($this->kernel->getEnvironment(), ['dev', 'test', 'local'], true)) {
            $io->error('Refusing to generate demo catalogue data outside dev, test or local.');
            $io->warning('Run: APP_ENV=dev php bin/console app:catalog:seed-demo');

            return Command::FAILURE;
        }

        $products = max(1, (int) $input->getOption('products'));
        $batch = max(1, (int) $input->getOption('batch'));
        $prefix = strtoupper(trim((string) $input->getOption('prefix')));
        if ('' === $prefix) {
            $io->error('The prefix option cannot be empty.');

            return Command::INVALID;
        }

        $this->purgePrefix($prefix, $io);

        $categoryIds = $this->seedCategories();
        $brandIds = $this->seedBrands();
        $io->writeln(sprintf('Categories: %d, brands: %d', count($categoryIds), count($brandIds)));

        $seeded = 0;
        for ($offset = 0; $offset < $products; $offset += $batch) {
            // Categories and brands are re-read every batch because the identity map is cleared
            // between batches: a Product holds a reference to a managed Brand, and handing
            // Doctrine a detached one is an error rather than a slow insert.
            $categories = $this->loadCategories($categoryIds);
            $brands = $this->loadBrands($brandIds);

            $size = min($batch, $products - $offset);
            for ($i = 0; $i < $size; ++$i) {
                $this->seedProduct($prefix, $offset + $i, $categories, $brands);
                ++$seeded;
            }
            $this->entityManager->flush();
            // Without this the identity map keeps every product, price, image and inventory row
            // built so far alive for the whole run, which is a property of the generator and
            // not of the storefront.
            $this->entityManager->clear();
            $io->writeln(sprintf('  %d/%d', $seeded, $products));
        }

        $io->success(sprintf('Seeded %d demo products.', $seeded));

        return Command::SUCCESS;
    }

    /**
     * Removes anything a previous run of this command left behind, scoped to the prefix so that
     * a store which also holds real catalogue data is never touched beyond its own demo rows.
     */
    private function purgePrefix(string $prefix, SymfonyStyle $io): void
    {
        $connection = $this->entityManager->getConnection();
        $products = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM catalog_product WHERE sku LIKE :prefix',
            ['prefix' => $prefix.'-%'],
        );
        if (0 === $products) {
            return;
        }

        $io->writeln(sprintf('Removing %d previously seeded demo products.', $products));
        $ids = $connection->fetchFirstColumn('SELECT id FROM catalog_product WHERE sku LIKE :prefix', ['prefix' => $prefix.'-%']);
        $quoted = implode(',', array_map(static fn ($id): string => (string) (int) $id, $ids));
        $connection->executeStatement('DELETE FROM catalog_product_category WHERE product_id IN ('.$quoted.')');
        $connection->executeStatement('DELETE FROM commerce_product_price WHERE product_id IN ('.$quoted.')');
        $connection->executeStatement('DELETE FROM commerce_product_inventory WHERE product_id IN ('.$quoted.')');
        $connection->executeStatement('DELETE FROM catalog_product_image WHERE product_id IN ('.$quoted.')');
        $connection->executeStatement('DELETE FROM catalog_product_identifier WHERE product_id IN ('.$quoted.')');
        $connection->executeStatement('DELETE FROM catalog_product_attribute WHERE product_id IN ('.$quoted.')');
        $connection->executeStatement('DELETE FROM catalog_product WHERE sku LIKE :prefix', ['prefix' => $prefix.'-%']);
    }

    /**
     * Categories and brands are reused when a row with the same slug already exists, so the
     * command can be re-run against a store that has real catalogue data without colliding
     * with its own unique slugs. Only the generated products are purged.
     *
     * @return list<int>
     */
    private function seedCategories(): array
    {
        $categories = [];
        foreach (self::CATEGORIES as $name) {
            $slug = $this->slug($name);
            $category = $this->entityManager->getRepository(Category::class)->findOneBy(['slug' => $slug]);
            if (!$category instanceof Category) {
                $category = new Category($name, $slug);
                $this->entityManager->persist($category);
            }
            $category->publish();
            $categories[] = $category;
        }
        $this->entityManager->flush();

        return array_values(array_filter(array_map(static fn (Category $category): ?int => $category->id(), $categories), is_int(...)));
    }

    /** @return list<int> */
    private function seedBrands(): array
    {
        $brands = [];
        foreach (self::BRANDS as $name) {
            $slug = $this->slug($name);
            $brand = $this->entityManager->getRepository(Brand::class)->findOneBy(['slug' => $slug]);
            if (!$brand instanceof Brand) {
                $brand = new Brand($name, $slug);
                $this->entityManager->persist($brand);
            }
            $brand->publish();
            $brands[] = $brand;
        }
        $this->entityManager->flush();

        return array_values(array_filter(array_map(static fn (Brand $brand): ?int => $brand->id(), $brands), is_int(...)));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Category>
     */
    private function loadCategories(array $ids): array
    {
        return $this->entityManager->getRepository(Category::class)->findBy(['id' => $ids]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Brand>
     */
    private function loadBrands(array $ids): array
    {
        return $this->entityManager->getRepository(Brand::class)->findBy(['id' => $ids]);
    }

    /**
     * @param list<Category> $categories
     * @param list<Brand>    $brands
     */
    private function seedProduct(string $prefix, int $index, array $categories, array $brands): void
    {
        $sku = sprintf('%s-%06d', $prefix, $index);
        $name = sprintf('Demo %s Parça %06d', $categories[$index % count($categories)]->name(), $index);
        $slug = sprintf('demo-%06d-%s', $index, $this->slug($categories[$index % count($categories)]->name()));

        $product = new Product($sku, $name, $slug, \App\Module\Catalog\CatalogSource::Local, $brands[$index % count($brands)]);
        $product->addCategory($categories[$index % count($categories)]);
        $product->describe(sprintf('Synthetic catalogue entry number %d generated for local performance work.', $index));
        $product->publish();

        // Two images per product so the detail page exercises the additional-image list and the
        // grid exercises the "first image" path that the product view query resolves.
        // AssetMapper serves these shipped vectors at every supported viewport size. A made-up
        // upload path exercised the queries but made every seeded page request missing files.
        $product->addImage(StorefrontMediaExtension::PRODUCT_PLACEHOLDER, $name, 0);
        $product->addImage('storefront/images/hero-automotive.svg', $name.' — temsili görünüm', 1);

        $this->entityManager->persist($product);
        $this->entityManager->persist(new ProductPrice(
            $product,
            Money::ofMinor(50_000 + (($index * 137) % 400_000), 'TRY'),
            TaxCategory::of('replacement-part'),
            TaxRate::fromBasisPoints(2_000),
        ));
        $this->entityManager->persist(new ProductInventory($product, $index % 25));
    }

    private function slug(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $ascii = false === $ascii ? $name : $ascii;

        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ascii));
    }
}
