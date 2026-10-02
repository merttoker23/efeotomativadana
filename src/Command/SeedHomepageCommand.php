<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Cms\HomeSection;
use App\Module\Catalog\Query\CatalogCriteria;
use App\Module\Catalog\Query\CatalogOption;
use App\Module\Catalog\Query\CatalogProductView;
use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Catalog\Query\CatalogSort;
use App\Module\Cms\CmsMediaStorage;
use App\Module\Cms\HomeSectionType;
use App\Repository\Cms\BlogPostRepository;
use App\Repository\Cms\HomeSectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives a freshly installed store a homepage that already looks like the theme.
 *
 * A brand new install holds no homepage sections, so the storefront falls back to its empty-state
 * hero and an administrator has to compose twelve modules by hand before the store looks like the
 * design it was built from. This writes that starting content once: the theme's own module order,
 * in automotive wording, pointing at the catalogue the store actually has.
 *
 * Three properties matter more than the content itself.
 *
 * **It never touches the catalogue.** No product, category, brand, price, image or stock row is
 * created, changed or deleted here. The command only *reads* published catalogue records and
 * writes their existing slugs into `HomeSection` configurations, which is why running it against a
 * store that just completed its B2B import cannot disturb that import.
 *
 * **It never overwrites anything.** One existing section of any kind is enough to stop it. There
 * is deliberately no `--replace`: a command that can delete a shop's homepage content is a command
 * whose accidental invocation is unrecoverable, and this one has no reason to exist in that form.
 *
 * **It uses the ordinary domain rules.** Every section is built through `HomeSection`, so its
 * configuration passes the same `SectionConfiguration` validation an administrator's save does.
 * Nothing here writes JSON directly, and a configuration the domain would refuse cannot be stored.
 *
 * Missing catalogue data is not an error. A store with three published products gets a three
 * product top-sellers column rather than a failure, and a section that can only be built from
 * records which do not exist is skipped and reported rather than seeded empty.
 */
#[AsCommand(
    name: 'app:cms:seed-homepage',
    description: 'Fill an empty homepage CMS with the theme demo content, reusing the published catalogue.',
)]
final class SeedHomepageCommand extends Command
{
    /** How many published categories the theme's category panel shows at most. */
    private const int CATEGORY_LIMIT = 9;

    /** The theme's brand shelf; an eleventh tile would wrap onto a second row on a laptop screen. */
    private const int BRAND_LIMIT = 10;

    /** How many published products the two carousels and the tab strip draw from in total. */
    private const int PRODUCT_POOL = 10;

    /** How many products one product tab shows. */
    private const int TAB_SIZE = 4;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HomeSectionRepository $sections,
        private readonly CatalogQuery $catalog,
        private readonly BlogPostRepository $posts,
        private readonly CmsMediaStorage $media,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ([] !== $this->sections->ordered()) {
            $io->success('Homepage already contains sections. Nothing was changed.');

            return Command::SUCCESS;
        }

        // The demo images are installed before any row is written, so the set that would have to be
        // taken back on a refusal is known in one place rather than accumulated as sections build.
        $images = $this->installDemoImages();
        $pool = $this->products();
        $categories = $this->catalog->categoriesByPopularity(self::CATEGORY_LIMIT);
        $brands = $this->catalog->brandsByPopularity(self::BRAND_LIMIT);
        $connection = $this->entityManager->getConnection();
        $created = [];
        $skipped = [];

        $connection->beginTransaction();

        try {
            foreach ($this->plan($pool, $categories, $brands, $images) as $plan) {
                $configuration = $plan['configuration'];
                if (null === $configuration) {
                    $skipped[] = $plan['label'];

                    continue;
                }
                $section = new HomeSection($plan['type'], $plan['title'], $configuration);
                $section->setEnabled(true);
                $section->setSortOrder($plan['sortOrder']);
                $this->entityManager->persist($section);
                $created[] = $plan['label'];
            }

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            // A refused configuration must leave the CMS exactly as it was. The rows go back with
            // the transaction; the images are files on disk and would outlive it, so they are
            // taken back explicitly. Their names are derived from a key, so a later run recreates
            // exactly these files rather than a second copy of each.
            $connection->rollBack();
            foreach ($images as $path) {
                $this->media->removeInstalled($path);
            }

            throw $failure;
        }

        foreach ($created as $label) {
            $io->writeln(sprintf('  <info>+</info> %s', $label));
        }
        foreach ($skipped as $label) {
            $io->writeln(sprintf('  <comment>-</comment> %s — no published catalogue record to show', $label));
        }

        $io->success(sprintf(
            'Seeded %d homepage sections from %d published products, %d categories and %d brands.',
            count($created),
            count($pool),
            count($categories),
            count($brands),
        ));

        return Command::SUCCESS;
    }

    /**
     * The theme's own homepage, in its own order.
     *
     * The order is `tema/index.html`'s rather than this class's preference: notice band, category
     * panel, hero slider, top sellers, promotional grid, new arrivals, features, brand shelf,
     * product tabs, marquee, testimonials, blog. The renderer's theme slots then group the first,
     * second, third, fourth, eleventh and twelfth of those into the bands the theme draws them in,
     * so seeding them in this order is what reproduces the reference page rather than a stack of
     * full-width sections.
     *
     * `sortOrder` is the module's own number in that list times ten, which leaves room to move one
     * in the admin without renumbering the rest.
     *
     * @param list<string>              $pool       published product slugs, newest first
     * @param list<CatalogOption>      $categories published categories, most stocked first
     * @param list<CatalogOption>      $brands     published brands, most stocked first
     * @param array<string, string>    $images     demo image file name to the path it was installed at
     *
     * @return list<array{type: HomeSectionType, title: string, label: string, sortOrder: int, configuration: array<string, mixed>|null}>
     */
    private function plan(array $pool, array $categories, array $brands, array $images): array
    {
        // Two carousels drawing on one pool. Splitting the pool in half rather than slicing a
        // fixed five off each end means a store with three published products still gets a second
        // carousel with something in it instead of an empty band.
        $half = max(1, (int) ceil(count($pool) / 2));
        $tabs = $this->tabs($pool);

        return [
            [
                'type' => HomeSectionType::AnnouncementBar,
                'title' => 'Duyuru',
                'label' => 'Duyuru barı',
                'sortOrder' => 10,
                'configuration' => ['text' => 'Efe Otomotiv Adana · Aynı gün kargo · 500 TL üzeri ücretsiz gönderim'],
            ],
            [
                'type' => HomeSectionType::CategoryMenu,
                'title' => 'Kategoriler',
                'label' => 'Kategori menüsü',
                'sortOrder' => 20,
                'configuration' => $this->selection($this->optionSlugs($categories)),
            ],
            [
                'type' => HomeSectionType::HeroSlider,
                'title' => 'Kampanya',
                'label' => 'Hero slider',
                'sortOrder' => 30,
                'configuration' => $this->hero($images),
            ],
            [
                'type' => HomeSectionType::ProductCarousel,
                'title' => 'Çok Satanlar',
                'label' => 'Çok satanlar',
                'sortOrder' => 40,
                'configuration' => $this->selection(\array_slice($pool, 0, $half)),
            ],
            [
                'type' => HomeSectionType::BannerGrid,
                'title' => 'Kampanyalar',
                'label' => 'Banner grid',
                'sortOrder' => 50,
                'configuration' => $this->banners($images),
            ],
            [
                'type' => HomeSectionType::ProductCarousel,
                'title' => 'Yeni Ürünler',
                'label' => 'Yeni gelenler karuseli',
                'sortOrder' => 60,
                'configuration' => $this->selection(\array_slice($pool, $half)),
            ],
            [
                'type' => HomeSectionType::Features,
                'title' => 'Neden Efe Otomotiv Adana?',
                'label' => 'Özellikler',
                'sortOrder' => 70,
                'configuration' => ['features' => [
                    ['title' => 'Hızlı Gönderim', 'description' => 'Stoktaki ürünler aynı gün kargoya verilir.'],
                    ['title' => 'Güvenli Alışveriş', 'description' => 'Gizli kredi kartı altyapısı ve güvenli ödeme.'],
                    ['title' => 'Kolay İade', 'description' => '14 gün içinde koşulsuz iade hakkı.'],
                    ['title' => 'Uzman Destek', 'description' => 'Parça seçiminde teknik destek.'],
                ]],
            ],
            [
                'type' => HomeSectionType::BrandStrip,
                'title' => 'Popüler Markalar',
                'label' => 'Marka şeridi',
                'sortOrder' => 80,
                'configuration' => $this->selection($this->optionSlugs($brands)),
            ],
            [
                'type' => HomeSectionType::ProductTabs,
                'title' => 'Ürünler',
                'label' => 'Ürün sekmeleri',
                'sortOrder' => 90,
                'configuration' => [] === $tabs ? null : ['tabs' => $tabs],
            ],
            [
                'type' => HomeSectionType::Marquee,
                'title' => 'Avantajlarımız',
                'label' => 'Kayan yazı',
                'sortOrder' => 100,
                'configuration' => ['items' => ['Hızlı Gönderim', 'Güvenilir Markalar', 'Doğru Parça', 'Uzman Destek']],
            ],
            [
                'type' => HomeSectionType::Testimonials,
                'title' => 'Müşterilerimiz Ne Diyor',
                'label' => 'Müşteri yorumları',
                'sortOrder' => 110,
                'configuration' => ['quotes' => [
                    ['author' => 'Mehmet Y.', 'text' => 'Siparişim aynı gün elimdeydi, balata ve disk seti tam istediğim gibiydi.'],
                    ['author' => 'Ayşe K.', 'text' => 'Aracıma uygun parçayı bulmakta zorlanmıştım, destek ekibi doğru ürünü gösterdi.'],
                    ['author' => 'Hakan T.', 'text' => 'Fiyatlar piyasaya göre uygun, kargo çok hızlıydı.'],
                ]],
            ],
            [
                'type' => HomeSectionType::BlogFeed,
                'title' => 'Blog',
                'label' => 'Blog akışı',
                'sortOrder' => 120,
                // No post means nothing to feed, and an empty blog panel is a frame around nothing.
                // No post is ever written here to fill it.
                'configuration' => [] === $this->posts->latestPublished(1) ? null : ['limit' => 3],
            ],
        ];
    }

    /**
     * The hero slides, each on one of the shipped demo images.
     *
     * The links are the store's own catalogue routes rather than theme pages, so every slide lands
     * somewhere that exists.
     *
     * @param array<string, string> $images
     *
     * @return array<string, mixed>
     */
    private function hero(array $images): array
    {
        $copy = [
            ['Aracınız İçin Doğru Parça', 'Marka ve modele göre yedek parça arayın.', '/yeni/katalog'],
            ['Fren Sistemlerinde Güvenilir Markalar', 'Balata, disk ve kampana setlerinde geniş stok.', '/yeni/kategoriler'],
            ['Stoktan Hızlı Gönderim', 'Siparişiniz aynı gün kargoya verilir.', '/yeni/markalar'],
        ];

        $slides = [];
        foreach ($copy as $index => [$title, $description, $link]) {
            $slides[] = [
                'title' => $title,
                'description' => $description,
                'image' => $images['hero-'.($index + 1).'.jpg'],
                'link' => $link,
            ];
        }

        return ['slides' => $slides];
    }

    /**
     * The promotional row, on the same shipped images at the theme's banner geometry.
     *
     * @param array<string, string> $images
     *
     * @return array<string, mixed>
     */
    private function banners(array $images): array
    {
        $copy = [
            'Yedek parçada yaz fırsatı',
            'Fren setlerinde avantajlı fiyatlar',
            'Filtre yenileme zamanı',
            'Yağ ve akışkan ürünleri',
        ];

        $banners = [];
        foreach ($copy as $index => $title) {
            $banners[] = [
                'title' => $title,
                'image' => $images['banner-'.($index + 1).'.jpg'],
                'link' => '/yeni/katalog',
            ];
        }

        return ['banners' => $banners];
    }

    /**
     * The tab strip, cut from the same pool in reading order.
     *
     * A tab with no products would be a tab that opens onto nothing, so it is left out entirely
     * rather than offered empty — the same rule the renderer applies once products are later
     * unpublished.
     *
     * @param list<string> $pool
     *
     * @return list<array{title: string, slugs: list<string>}>
     */
    private function tabs(array $pool): array
    {
        $tabs = [];
        foreach (['Çok Satanlar', 'Yeni Ürünler', 'Öne Çıkanlar'] as $position => $title) {
            $slugs = \array_slice($pool, $position * self::TAB_SIZE, self::TAB_SIZE);
            if ([] !== $slugs) {
                $tabs[] = ['title' => $title, 'slugs' => $slugs];
            }
        }

        return $tabs;
    }

    /**
     * A catalogue-backed section's selection.
     *
     * An empty list is not a valid configuration for any section that references the catalogue,
     * so the section is skipped rather than stored and then silently rendered as an empty frame.
     *
     * @param list<string> $slugs
     *
     * @return array<string, mixed>|null
     */
    private function selection(array $slugs): ?array
    {
        return [] === $slugs ? null : ['slugs' => array_values(array_unique($slugs))];
    }

    /**
     * Published product slugs, newest first.
     *
     * Newest is a stable order — `created_at` then id — so a second run against the same catalogue
     * picks the same products and the seed does not reshuffle the page for no reason.
     *
     * @return list<string>
     */
    private function products(): array
    {
        $page = $this->catalog->search(new CatalogCriteria(
            sort: CatalogSort::Newest,
            page: 1,
            perPage: self::PRODUCT_POOL,
        ));

        return array_map(static fn (CatalogProductView $product): string => $product->slug, $page->items);
    }

    /**
     * @param list<CatalogOption> $options
     *
     * @return list<string>
     */
    private function optionSlugs(array $options): array
    {
        return array_map(static fn (CatalogOption $option): string => $option->slug, $options);
    }

    /**
     * Install every shipped demo image into the CMS media library, keyed by file name.
     *
     * These are raster images the project ships for exactly this purpose; a section can only
     * reference an uploaded CMS image, so the storefront's own artwork would not do. Each is
     * installed under a name derived from its file name, so a repeat run finds the same files
     * instead of leaving a new copy of every image behind.
     *
     * @return array<string, string>
     */
    private function installDemoImages(): array
    {
        $images = [];
        foreach (['hero-1.jpg', 'hero-2.jpg', 'hero-3.jpg', 'banner-1.jpg', 'banner-2.jpg', 'banner-3.jpg', 'banner-4.jpg'] as $file) {
            $images[$file] = $this->media->installLocalAsset(
                $this->projectDir.'/assets/storefront/images/demo/'.$file,
                'homepage-demo:'.$file,
            );
        }

        return $images;
    }
}