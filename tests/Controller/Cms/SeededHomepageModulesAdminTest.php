<?php

declare(strict_types=1);

namespace App\Tests\Controller\Cms;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Cms\BlogPost;
use App\Entity\Cms\HomeSection;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Entity\Customer\AdminUser;
use App\Module\Catalog\ProductFeedSource;
use App\Module\Cms\HomeSectionType;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Everything the seed writes has to be editable afterwards, in the ordinary form.
 *
 * A homepage can be rendered from a configuration that no form could ever have produced — a JSON
 * column written by a script is exactly that — and the result looks right until the first person
 * tries to change a word in it. So this test takes the seeded page as an administrator finds it,
 * walks every module's edit form, checks that each field the section type declares is actually on
 * the screen with the stored value in it, and then saves a change to each module and reads it back
 * off the storefront.
 *
 * The hero and the split builder are the two modules whose shape grew, so they are checked field
 * by field: a form that renders nine slide inputs but saves four would leave the page permanently
 * uneditable, which no rendered-HTML assertion would catch.
 */
final class SeededHomepageModulesAdminTest extends WebTestCase
{
    private Connection $connection;
    private EntityManagerInterface $manager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEverySeededModuleCanBeEditedInTheAdminForm(): void
    {
        $this->catalogue();
        $post = new BlogPost('Fren bakımı', 'fren-bakimi', 'Fren bakımının adımları.', 'Gövde');
        $post->setPublished(true);
        $this->manager->persist($post);
        $this->manager->flush();

        self::assertSame(0, $this->seed());
        $this->signIn();

        $sections = $this->manager->getRepository(HomeSection::class)->findBy([], ['sortOrder' => 'ASC']);
        self::assertSame(13, \count($sections), implode(',', array_map(static fn (HomeSection $s): string => $s->type()->value, $sections)));

        foreach ($sections as $section) {
            $crawler = $this->client->request('GET', sprintf('/yeni/admin/cms/home/%d/edit', $section->id()));
            self::assertResponseIsSuccessful();

            $type = $section->type();
            self::assertSame($type->label(), trim($crawler->filter('.panel .help-text strong')->text()));

            // A fixed field and every row field the type declares is on the screen, carrying the
            // stored value, so the administrator sees the module as it is rather than as a blank.
            foreach ($type->fields() as $field => $kind) {
                $name = 'link' === $kind ? sprintf('input[name="%s"]', $field) : sprintf('[name="%s"]', $field);
                self::assertSame(1, $crawler->filter($name)->count(), $type->value.' has no control for "'.$field.'".');
                $stored = (string) $section->configuration()[$field];
                self::assertSame(
                    $stored,
                    'textarea' === $kind ? $crawler->filter($name)->text() : (string) $crawler->filter($name)->attr('value'),
                    $type->value.' does not show the stored value of "'.$field.'".',
                );
            }

            $rows = $type->rowName();
            if (null !== $rows) {
                self::assertSame(
                    \count($section->configuration()[$rows]),
                    (int) $crawler->filter(sprintf('input[name="%s_count"]', $rows))->attr('value'),
                    $type->value.' does not render every stored row.',
                );
                $rendered = $crawler->filter('.cms-rows > fieldset.cms-row');
                $shape = $type->rowFields();
                foreach ($section->configuration()[$rows] as $index => $row) {
                    // Only the rendered rows, not the blank prototype the "add row" button clones:
                    // both carry the same field names, and only one of them is the module.
                    $rowCrawler = $rendered->eq($index);
                    self::assertSame((string) $index, (string) $rowCrawler->attr('data-index'));
                    // The marquee stores its words as plain strings, which the form still writes
                    // out as one named field per row.
                    $values = \is_array($row) ? $row : [$shape[0] => $row];
                    foreach ($shape as $field) {
                        if ('slugs' === $field) {
                            // A row's catalogue selection is its own picker with its own field
                            // prefix, so it is checked with the selection rather than as a value.
                            // Only a row that names its own products has a picker at all: the
                            // others take theirs from the source the row declares.
                            $picker = $rowCrawler->filter(sprintf('[name="%s_%d_p_count"]', $rows, $index));
                            if (!$this->isManualRow($row)) {
                                self::assertSame(0, $picker->count(), $type->value.' row '.$index.' offers a picker for a source that brings its own products.');

                                continue;
                            }
                            self::assertSame(
                                \count($row['slugs']),
                                (int) $picker->attr('value'),
                                $type->value.' row '.$index.' does not offer its own selection back.',
                            );

                            continue;
                        }
                        $control = $rowCrawler->filter(sprintf('[name="%s_%d_%s"]', $rows, $index, $field));
                        self::assertSame(1, $control->count(), $type->value.' row '.$index.' has no control for "'.$field.'".');
                        if (\in_array($field, ['image', 'mobileImage'], true)) {
                            // An image is chosen from the media library, so the form offers it as
                            // the selected option rather than as a text value.
                            self::assertSame(
                                1,
                                $control->filter(sprintf('option[value="%s"]%s', $values[$field] ?? '', empty($values[$field]) ? '' : '[selected]'))->count(),
                                $type->value.' row '.$index.' does not offer its own image back.',
                            );

                            continue;
                        }
                        // A long value is edited in a textarea rather than a one-line input, and a
                        // closed set is chosen from a select; all three have to come back holding
                        // what the configuration stores.
                        self::assertSame(
                            (string) $values[$field],
                            $this->controlValue($control),
                            $type->value.' row '.$index.' does not offer its own "'.$field.'" back.',
                        );
                    }
                }
            }

            $selection = $type->selection();
            if (null !== $selection) {
                if ($type->selectionPerRow()) {
                    $rendered = $crawler->filter('.cms-rows > fieldset.cms-row');
                    foreach ($section->configuration()[$rows] as $index => $row) {
                        if (!$this->isManualRow($row)) {
                            continue;
                        }
                        self::assertNotEmpty($row['slugs'], $type->value.' row '.$index.' was seeded with an empty selection.');
                        foreach ($row['slugs'] as $position => $slug) {
                            self::assertSame(
                                $slug,
                                $rendered->eq($index)->filter(sprintf('[name="%s_%d_p_%d"]', $rows, $index, $position))->attr('value'),
                                $type->value.' row '.$index.' does not offer the selected "'.$slug.'" back.',
                            );
                        }
                    }

                    continue;
                }
                $expected = $section->configuration()['slugs'];
                self::assertNotEmpty($expected, $type->value.' seeded an empty selection.');
                foreach ($expected as $index => $slug) {
                    self::assertSame(
                        $slug,
                        $crawler->filter(sprintf('[name="slugs_%d"]', $index))->attr('value'),
                        $type->value.' does not offer the selected "'.$slug.'" back to the administrator.',
                    );
                }
            }
        }
    }

    /**
     * Whether a row names its own products, which is the only case where the form offers a picker.
     *
     * @param array<string, mixed> $row
     */
    private function isManualRow(array $row): bool
    {
        return ProductFeedSource::normalize($row['source'] ?? null)->isManual();
    }

    /**
     * What a control currently holds: a textarea's text, a select's chosen option, an input's value.
     */
    private function controlValue(Crawler $control): string
    {
        return match ($control->nodeName()) {
            'textarea' => $control->text(),
            'select' => (string) $control->filter('option[selected]')->attr('value'),
            default => (string) $control->attr('value'),
        };
    }

    /**
     * A saved change has to reach the storefront, for the two modules whose shape is new: the hero
     * slide with its label, offer pill and two calls to action, and the split builder's promotional
     * panel.
     */
    public function testAHeroSlideIsSavedWithItsLabelOfferPillAndBothCallsToAction(): void
    {
        $this->catalogue();
        self::assertSame(0, $this->seed());
        $this->signIn();

        $hero = $this->sectionOf(HomeSectionType::HeroSlider);
        $crawler = $this->client->request('GET', sprintf('/yeni/admin/cms/home/%d/edit', $hero->id()));
        $image = $hero->configuration()['slides'][0]['image'];

        $this->client->submit($crawler->selectButton('Bölümü kaydet')->form([
            'slides_count' => 2,
            'slides_0_label' => 'Editör etiketi',
            'slides_0_title' => 'Editör başlığı',
            'slides_0_priceLabel' => 'Şu andan itibaren',
            'slides_0_priceValue' => '1.999,00 TL',
            'slides_0_image' => $image,
            'slides_1_label' => 'İkinci etiket',
            'slides_1_title' => 'İkinci başlık',
            'slides_1_primaryText' => 'Hemen başla',
            'slides_1_primaryLink' => '/yeni/katalog',
            'slides_1_secondaryText' => 'Kategoriler',
            'slides_1_secondaryLink' => '/yeni/kategoriler',
            'slides_1_image' => $image,
        ]));
        self::assertResponseRedirects('/yeni/admin/cms/home');

        $this->client->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.hero-slide.active .hero-label', 'Editör etiketi');
        self::assertSelectorTextContains('.hero-slide.active .hero-title', 'Editör başlığı');
        self::assertSelectorTextContains('.hero-slide.active .price-pill strong', '1.999,00 TL');

        // The slide that now carries both calls to action is the theme's left-aligned composition.
        $slides = $this->client->getCrawler()->filter('.hero-slide');
        self::assertStringContainsString('mobile-feature', (string) $slides->eq(1)->attr('class'));
        self::assertSame(1, $slides->eq(1)->filter('.hero-actions .shop-now[href="/yeni/katalog"]')->count());
        self::assertSame(1, $slides->eq(1)->filter('.hero-actions .learn-more[href="/yeni/kategoriler"]')->count());
    }

    public function testMobileBannerCanBeSelectedAndClearedWithoutChangingDesktop(): void
    {
        $this->catalogue();
        self::assertSame(0, $this->seed());
        $this->signIn();
        $hero = $this->sectionOf(HomeSectionType::HeroSlider);
        $url = sprintf('/yeni/admin/cms/home/%d/edit', $hero->id());
        $desktop = $hero->configuration()['slides'][0]['image'];
        $crawler = $this->client->request('GET', $url);
        self::assertSame(1, $crawler->filter('.cms-rows > .cms-row [name="slides_0_mobileImage_file"]')->count());
        self::assertSame(1, $crawler->filter('.cms-rows > .cms-row[data-index="0"] .cms-image img[alt="Mobil banner seçilmedi; mağazada placeholder gösterilir"]:not([hidden])')->count());

        $this->client->submit($crawler->selectButton('Bölümü kaydet')->form(['slides_0_mobileImage' => $desktop]));
        self::assertResponseRedirects('/yeni/admin/cms/home');
        self::assertSame($desktop, $this->sectionOf(HomeSectionType::HeroSlider)->configuration()['slides'][0]['mobileImage']);
        $crawler = $this->client->request('GET', $url);
        self::assertSame(1, $crawler->filter('[name="slides_0_mobileImage"] option[selected][value="'.$desktop.'"]')->count());

        $this->client->submit($crawler->selectButton('Bölümü kaydet')->form(['slides_0_mobileImage' => '']));
        self::assertResponseRedirects('/yeni/admin/cms/home');
        $saved = $this->sectionOf(HomeSectionType::HeroSlider)->configuration()['slides'][0];
        self::assertSame('', $saved['mobileImage']);
        self::assertSame($desktop, $saved['image']);
        $crawler = $this->client->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('product-placeholder-', (string) $crawler->filter('.hero-slide')->eq(0)->filter('source')->attr('srcset'));
    }

    public function testTheSplitBuilderPanelIsSavedAndItsProductSelectionSurvives(): void
    {
        $this->catalogue();
        self::assertSame(0, $this->seed());
        $this->signIn();

        $split = $this->sectionOf(HomeSectionType::SplitBuilder);
        $crawler = $this->client->request('GET', sprintf('/yeni/admin/cms/home/%d/edit', $split->id()));
        self::assertSame(1, $crawler->filter('[name="label"]')->count());
        self::assertSame(1, $crawler->filter('[name="headline"]')->count());
        self::assertSame(1, $crawler->filter('[name="description"]')->count());
        self::assertSame(1, $crawler->filter('[name="cta"]')->count());
        self::assertSame(1, $crawler->filter('[name="link"]')->count());

        $this->client->submit($crawler->selectButton('Bölümü kaydet')->form([
            'title' => 'Editör fren setleri',
            'subtitle' => 'Editör alt başlığı',
            'label' => 'Editör etiketi',
            'headline' => 'Editör kampanya başlığı',
            'description' => 'Editör açıklaması.',
            'cta' => 'Editör butonu',
            'link' => '/yeni/markalar',
        ]));
        self::assertResponseRedirects('/yeni/admin/cms/home');

        $this->client->request('GET', '/yeni/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.split-builder .section-head h2', 'Editör fren setleri');
        self::assertSelectorTextContains('.split-builder .section-head p', 'Editör alt başlığı');
        self::assertSelectorTextContains('.split-builder .big-promo > span', 'Editör etiketi');
        self::assertSelectorTextContains('.split-builder .big-promo h2', 'Editör kampanya başlığı');
        self::assertSelectorTextContains('.split-builder .big-promo p', 'Editör açıklaması.');
        self::assertSelectorTextContains('.split-builder .big-promo .big-promo-cta', 'Editör butonu');
        self::assertSame('/yeni/markalar', $this->client->getCrawler()->filter('.split-builder .big-promo-cta')->attr('href'));
        self::assertSame(3, $this->client->getCrawler()->filter('.split-builder .split-builder-grid .product-card')->count());
    }

    /**
     * A hero call to action is a label and a destination. A form that could save one without the
     * other would render a control that goes nowhere, so the pair is refused at save time.
     */
    public function testAHeroCallToActionWithoutItsOtherHalfIsRefusedByTheAdminForm(): void
    {
        $this->catalogue();
        self::assertSame(0, $this->seed());
        $this->signIn();

        $hero = $this->sectionOf(HomeSectionType::HeroSlider);
        $crawler = $this->client->request('GET', sprintf('/yeni/admin/cms/home/%d/edit', $hero->id()));
        $image = $hero->configuration()['slides'][0]['image'];

        $this->client->submit($crawler->selectButton('Bölümü kaydet')->form([
            'slides_count' => 1,
            'slides_0_label' => 'Yeni Ürünler',
            'slides_0_title' => 'Başlık',
            'slides_0_primaryText' => 'Hemen başla',
            'slides_0_primaryLink' => '',
            'slides_0_image' => $image,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'hem buton metni hem bağlantı');
    }

    /** @return int the command's exit code */
    private function seed(): int
    {
        $tester = new CommandTester(
            (new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel))->find('app:cms:seed-homepage'),
        );

        return $tester->execute([]);
    }

    private function sectionOf(HomeSectionType $type): HomeSection
    {
        $section = $this->manager->getRepository(HomeSection::class)->findOneBy(['type' => $type]);
        self::assertInstanceOf(HomeSection::class, $section);

        return $section;
    }

    private function signIn(): void
    {
        $admin = new AdminUser('seeded-modules@example.com');
        $admin->setPassword('test-only-hash');
        $this->manager->persist($admin);
        $this->manager->flush();
        $this->client->loginUser($admin, 'admin');
    }

    private function catalogue(): void
    {
        for ($index = 1; $index <= 12; ++$index) {
            $category = new Category('Kategori '.$index, 'kategori-'.$index);
            $category->publish();
            $brand = new Brand('Marka '.$index, 'marka-'.$index);
            $brand->publish();
            $product = new Product(sprintf('MOD-%03d', $index), 'Ürün '.$index, 'urun-'.$index, \App\Module\Catalog\CatalogSource::Local, $brand);
            $product->addCategory($category);
            $product->publish();
            $this->manager->persist($category);
            $this->manager->persist($brand);
            $this->manager->persist($product);
            $this->manager->persist(new ProductPrice(
                $product,
                Money::ofMinor(45_000, 'TRY'),
                TaxCategory::of('replacement-part'),
                TaxRate::fromBasisPoints(2_000),
            ));
            $this->manager->persist(new ProductInventory($product, 5));
        }
        $this->manager->flush();
    }
}
