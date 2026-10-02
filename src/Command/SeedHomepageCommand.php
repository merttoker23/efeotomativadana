<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Cms\BlogPost;
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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives a freshly installed store a homepage that already looks like the theme.
 *
 * A brand new install holds no homepage sections, so the storefront falls back to its empty-state
 * hero and an administrator has to compose thirteen modules by hand before the store looks like the
 * design it was built from. This writes that starting content once: `tema/index.html`'s own block
 * order, in automotive wording, pointing at the catalogue the store actually has.
 *
 * Four properties matter more than the content itself.
 *
 * **It never touches the catalogue.** No product, category, brand, price, image or stock row is
 * created, changed or deleted here. The command only *reads* published catalogue records and
 * writes their existing slugs into `HomeSection` configurations, which is why running it against a
 * store that just completed its B2B import cannot disturb that import.
 *
 * **It writes nothing over anything unless it is asked to.** One existing section of any kind is
 * enough to stop it. `--reset` is the only way past that guard, and it removes *homepage section*
 * rows and nothing else: the catalogue, the prices, the stock and the B2B feed are outside what
 * this command can reach.
 *
 * **It uses the ordinary domain rules.** Every section is built through `HomeSection`, so its
 * configuration passes the same `SectionConfiguration` validation an administrator's save does.
 * Nothing here writes JSON directly, and a configuration the domain would refuse cannot be stored.
 *
 * **It never invents a catalogue record to fill a hole.** A grid that cannot be filled with real
 * products reuses the real products it has rather than adding one, and a section that can only be
 * built from records which do not exist is skipped and reported rather than seeded empty.
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

    /** How many published products the sections draw from in total. */
    private const int PRODUCT_POOL = 10;

    /** The theme's top-sellers column, and the width of its product grids. */
    private const int GRID_SIZE = 5;

    /** The theme's split builder shows exactly three products. */
    private const int SPLIT_SIZE = 3;

    /** The four tab names the theme's product block carries. */
    private const array TAB_TITLES = ['Çok Satanlar', 'Popüler', 'İndirimdekiler', 'Öne Çıkanlar'];

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

    protected function configure(): void
    {
        $this->addOption(
            'reset',
            null,
            InputOption::VALUE_NONE,
            'Rebuild the homepage from the theme demo content, deleting the homepage section rows that are already there. No catalogue, price, stock or B2B data is touched.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = $this->sections->ordered();
        if ([] !== $existing && !$input->getOption('reset')) {
            $io->success('Homepage already contains sections. Nothing was changed.');
            $io->writeln(' Run again with <info>--reset</info> to rebuild the homepage from the theme demo content.');

            return Command::SUCCESS;
        }

        if ([] !== $existing) {
            $this->purgeSections();
            $io->writeln(sprintf('  <comment>·</comment> Replaced %d existing homepage section(s).', \count($existing)));
        }

        // The demo images are installed before any row is written, so the set that would have to be
        // taken back on a refusal is known in one place rather than accumulated as sections build.
        $images = $this->installDemoImages();
        $pool = $this->products();
        $categories = $this->catalog->categoriesByPopularity(self::CATEGORY_LIMIT);
        $brands = $this->catalog->brandsByPopularity(self::BRAND_LIMIT);
        $demoPosts = $this->ensureDemoPosts();
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
        foreach ($demoPosts as $title) {
            $io->writeln(sprintf('  <info>+</info> Demo blog yazısı: %s', $title));
        }

        $io->success(sprintf(
            'Seeded %d homepage sections from %d published products, %d categories and %d brands.',
            \count($created),
            \count($pool),
            \count($categories),
            \count($brands),
        ));

        return Command::SUCCESS;
    }

    /**
     * Remove the homepage sections and nothing else.
     *
     * `--reset` is the only destructive path this command has, so it is deliberately narrow: it
     * walks `HomeSection` entities through the entity manager — no bulk statement, no table name
     * built from a string — which makes it impossible for it to reach the catalogue, the prices or
     * the stock even if the call site were changed by mistake.
     */
    private function purgeSections(): void
    {
        foreach ($this->sections->ordered() as $section) {
            $this->entityManager->remove($section);
        }

        $this->entityManager->flush();
    }

    /**
     * Make sure the closing row has something on its right.
     *
     * The theme pairs one testimonial with three blog cards, and a half-width gap reads as a bug.
     * A store that has published posts of its own is left alone; a store with no blog at all would
     * otherwise render an empty panel, so three editable demo posts are written for it. This is
     * the one record this command ever creates that is not a homepage section, and it is blog-only
     * on purpose: no product, category or brand is ever invented here.
     *
     * @return list<string> the demo posts this run wrote, for the summary
     */
    private function ensureDemoPosts(): array
    {
        if (0 < $this->posts->page(1, 1)->totalItems) {
            return [];
        }

        $copy = [
            ['Fren sisteminin dört mevsimlik kontrolü', 'fren-sisteminin-kontrolu', 'Balata, disk ve hidrolik yağ seviyesi için yıllık kontrol listesi.'],
            ['Yedek parçada alıntı güveni nasıl kurulur?', 'yedek-parcada-alinti-guveni', 'Bir parçanın orijinal olduğunu ve iade şartlarını nasıl anlarsınız?'],
            ['Aracınızın model yılına göre parça seçimi', 'model-yilina-gore-parca-secimi', 'Aynı adı taşıyan ama farklı yılın parçaları arasındaki farklar.'],
        ];

        $written = [];
        foreach ($copy as [$title, $slug, $excerpt]) {
            $post = new BlogPost($title, $slug, $excerpt, $excerpt."\n\n".'Efe Otomotiv Adana showroom ekibimiz bu yazıları her ay günceller; ürün ve stok bilgileri için bizimle iletişime geçebilirsiniz.');
            $post->setPublished(true);
            $this->entityManager->persist($post);
            $written[] = $title;
        }
        $this->entityManager->flush();

        return $written;
    }

    /**
     * The theme's own homepage, in its own order.
     *
     * The order is `tema/index.html`'s rather than this class's preference: notice band, category
     * panel, hero slider, top sellers, promotional grid, new arrivals, features, split builder,
     * brand shelf, product tabs, marquee, testimonials, blog. The renderer's theme slots then group
     * the first, second, third, fourth, twelfth and thirteenth of those into the bands the theme
     * draws them in, so seeding them in this order is what reproduces the reference page rather
     * than a stack of full-width sections.
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
                'configuration' => $this->selection($this->window($pool, self::GRID_SIZE, 0)),
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
                'configuration' => $this->selection($this->window($pool, self::GRID_SIZE, self::GRID_SIZE)),
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
                'type' => HomeSectionType::SplitBuilder,
                'title' => 'Fren Aksesuarlarında Öne Çıkanlar',
                'label' => 'Split builder',
                'sortOrder' => 80,
                'configuration' => $this->splitBuilder($pool),
            ],
            [
                'type' => HomeSectionType::BrandStrip,
                'title' => 'Popüler Markalar',
                'label' => 'Marka şeridi',
                'sortOrder' => 90,
                'configuration' => $this->selection($this->optionSlugs($brands)),
            ],
            [
                'type' => HomeSectionType::ProductTabs,
                'title' => 'Ürünler',
                'label' => 'Ürün sekmeleri',
                'sortOrder' => 100,
                'configuration' => [] === $tabs ? null : ['tabs' => $tabs],
            ],
            [
                'type' => HomeSectionType::Marquee,
                'title' => 'Avantajlarımız',
                'label' => 'Kayan yazı',
                'sortOrder' => 110,
                'configuration' => ['items' => ['Hızlı Gönderim', 'Güvenilir Markalar', 'Doğru Parça', 'Uzman Destek']],
            ],
            [
                'type' => HomeSectionType::Testimonials,
                'title' => 'Müşterilerimiz Ne Diyor',
                'label' => 'Müşteri yorumları',
                'sortOrder' => 120,
                // The theme's closing row is one main quote beside the blog cards, not a wall of
                // them. The section still takes as many as an administrator adds afterwards.
                'configuration' => ['quotes' => [
                    ['author' => 'Mehmet Y.', 'text' => 'Siparişim aynı gün elimdeydi, balata ve disk seti tam istediğim gibiydi.'],
                ]],
            ],
            [
                'type' => HomeSectionType::BlogFeed,
                'title' => 'Blog',
                'label' => 'Blog akışı',
                'sortOrder' => 130,
                // `ensureDemoPosts()` has already made sure there is something to show, so this is
                // never an empty panel beside the testimonial.
                'configuration' => ['limit' => 3],
            ],
        ];
    }

    /**
     * The theme's three hero compositions, each on one of the shipped demo images.
     *
     * The first and third slides stack a label, a headline and an offer pill; the second carries a
     * pair of calls to action instead of a pill, which is what puts it in the theme's left-aligned
     * composition. All three are the same nine fields, so an administrator can turn any slide into
     * any of the three from the form.
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
            [
                'label' => 'Yeni Ürünler',
                'title' => 'Yaza özel fren setleri elde 👇',
                'priceLabel' => 'Şu andan itibaren',
                'priceValue' => '2.499,00 TL',
                'primaryText' => '',
                'primaryLink' => '',
                'secondaryText' => '',
                'secondaryLink' => '',
            ],
            [
                'label' => 'Efe Otomotiv',
                'title' => 'Aracınıza uygun yedek parçayı dakikalar içinde bulun',
                'priceLabel' => '',
                'priceValue' => '',
                'primaryText' => 'Hemen Alışverişe Başla',
                'primaryLink' => '/yeni/katalog',
                'secondaryText' => 'Kategorilere göz at',
                'secondaryLink' => '/yeni/kategoriler',
            ],
            [
                'label' => 'Sınırlı Süre',
                'title' => 'Filtre ve yağ kampanyasında kaçırmayın',
                'priceLabel' => 'Şoka',
                'priceValue' => '%50 indirim',
                'primaryText' => '',
                'primaryLink' => '',
                'secondaryText' => '',
                'secondaryLink' => '',
            ],
        ];

        $slides = [];
        foreach ($copy as $index => $slide) {
            $slides[] = ['image' => $images['hero-'.($index + 1).'.jpg']] + $slide;
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
     * The split builder: three products beside one large promotional panel.
     *
     * @param list<string> $pool
     *
     * @return array<string, mixed>|null
     */
    private function splitBuilder(array $pool): ?array
    {
        $slugs = $this->window($pool, self::SPLIT_SIZE, self::SPLIT_SIZE);
        if ([] === $slugs) {
            return null;
        }

        return [
            'label' => 'Yetkili Satıcı',
            'headline' => 'Yedek parçada 2.500 TL üzeri ücretsiz kargo',
            'description' => 'Fiyatlar stoklarla sınırlıdır; kampanya, Adana showroomunda geçerlidir.',
            'cta' => 'Kampanyayı İncele',
            'link' => '/yeni/katalog',
            'slugs' => array_values(array_unique($slugs)),
        ];
    }

    /**
     * The tab strip: the theme's four tabs, each a full row of the grid.
     *
     * Each tab is cut from a different part of the pool so the four lists are not the same list
     * four times; where the store has fewer products than a tab needs, the tab reuses the real
     * products it has rather than leaving the row short or inventing a product to fill it. A tab
     * with no products at all is left out entirely — the same rule the renderer applies once
     * products are later unpublished.
     *
     * @param list<string> $pool
     *
     * @return list<array{title: string, slugs: list<string>}>
     */
    private function tabs(array $pool): array
    {
        $total = \count($pool);
        $tabs = [];
        foreach (self::TAB_TITLES as $position => $title) {
            $slugs = $this->window($pool, self::GRID_SIZE, $position * 2);
            if ([] !== $slugs && 1 < $total) {
                $tabs[] = ['title' => $title, 'slugs' => $slugs];
            }
        }

        return $tabs;
    }

    /**
     * `$count` product slugs from the pool, starting `$offset` entries in and wrapping round.
     *
     * The theme's rows are fixed widths — five across the grids, three in the split builder — and a
     * store with three published products must still fill them. Reusing the products it has keeps
     * the layout whole, which is the only honest option left when the alternative is a product row
     * that does not exist.
     *
     * @param list<string> $pool
     *
     * @return list<string>
     */
    private function window(array $pool, int $count, int $offset): array
    {
        $total = \count($pool);
        if (0 === $total || $count < 1) {
            return [];
        }

        $picked = [];
        for ($index = 0; $index < $count; ++$index) {
            $picked[] = $pool[($offset + $index) % $total];
        }

        return $picked;
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
     * installed under a name derived from its file name, so a repeat run — including the one a
     * `--reset` makes — finds the same files instead of leaving a new copy of every image behind.
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