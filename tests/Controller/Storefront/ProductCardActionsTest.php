<?php

declare(strict_types=1);

namespace App\Tests\Controller\Storefront;

use App\Entity\Catalog\Product;
use App\Entity\Cms\HomeSection;
use App\Entity\Commerce\ProductInventory;
use App\Entity\Commerce\ProductPrice;
use App\Module\Cms\HomeSectionType;
use App\Module\Pricing\TaxCategory;
use App\Module\Pricing\TaxRate;
use App\Shared\Money\Money;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What a product card offers to do with its product, on the homepage and in the catalogue.
 *
 * The two cards are separate templates and they used to disagree about the same situation: both
 * printed nothing at all for a product that could not be bought, which is invisible in a grid of
 * sellable products and is what makes a grid of mixed ones ragged. A card now always states what
 * the store can do with that product — add it, or say why it cannot — so its actions row is the same
 * size whatever the product is.
 *
 * What a card offers and what the cart accepts are two different things, and both are pinned here:
 * the rendered form, and a real POST of the token that form carries.
 */
final class ProductCardActionsTest extends WebTestCase
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

    /**
     * A sellable, priced product is the one case that has to end in the cart, and it has to be the
     * theme's yellow button on both cards rather than the catalogue's plain one.
     */
    public function testASellablePricedProductIsOfferedToTheCartByBothCards(): void
    {
        $this->product('CARD-OK', 'Satılabilir Ürün', 'satilabilir-urun', 10_000, 5);

        foreach ($this->cardsFor('satilabilir-urun') as $where => $card) {
            self::assertSame(
                1,
                $card->filter('.product-actions .cart-add-form')->count(),
                $where.' card printed no add-to-cart form for a sellable, priced product.',
            );
            $button = $card->filter('.product-actions .cart-add-form button');
            self::assertSame('Sepete Ekle', trim($button->text()), $where.' card button label.');
            self::assertNull($button->attr('disabled'), $where.' card disabled a button that works.');
            self::assertSame(
                0,
                $card->filter('.product-actions > .is-disabled')->count(),
                $where.' card printed the out-of-stock control for a product that is in stock.',
            );
        }

        // The theme's own styling, which is what makes the button read as the buyable one.
        self::assertSame('btn-yellow', $this->homeCard('satilabilir-urun')->filter('.cart-add-form button')->attr('class'));
    }

    /**
     * A card that renders a cart form has to render one that works, so the token it carries is posted
     * to the route it names rather than to the product page's own form. Nothing here decides what the
     * cart accepts — that still lives in the cart controller — but a card whose button cannot add the
     * product is the failure this whole change is about.
     */
    public function testTheCartButtonACardRendersAddsTheProduct(): void
    {
        $product = $this->product('CARD-POST', 'Sepete Eklenecek Ürün', 'sepete-eklenecek-urun', 12_345, 5);
        $card = $this->catalogCard('sepete-eklenecek-urun');

        self::assertSame(
            '/yeni/sepet/ekle/'.$product->id(),
            $card->filter('.cart-add-form')->attr('action'),
        );
        $token = $card->filter('.cart-add-form input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/yeni/sepet/ekle/'.$product->id(), ['_token' => $token, 'quantity' => 2]);

        self::assertResponseRedirects('/yeni/sepet');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commerce_cart_item'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT quantity FROM commerce_cart_item'));
    }

    /**
     * Out of stock. The actions row keeps its first control, because the row is sized by it — an
     * empty slot is what makes the cards of a grid sit at different heights — and the control says
     * why rather than being left blank to be guessed at.
     */
    public function testAProductOutOfStockKeepsItsActionSlotAndSaysItCannotBeBought(): void
    {
        $this->product('CARD-OUT', 'Stoksuz Ürün', 'stoksuz-urun', 10_000, 0);

        foreach ($this->cardsFor('stoksuz-urun') as $where => $card) {
            self::assertSame(0, $card->filter('.product-actions .cart-add-form')->count(), $where.' card offered a cart form for an out-of-stock product.');
            $state = $card->filter('.product-actions > .is-disabled');
            self::assertSame(1, $state->count(), $where.' card left its add-to-cart slot empty.');
            self::assertSame('Stokta Yok', trim($state->text()), $where.' card state label.');
            self::assertNotNull($state->attr('disabled'), $where.' card state was not disabled.');
            // The rest of the row is untouched, which is what keeps the card's shape the same.
            self::assertSame(1, $card->filter('.product-actions .wishlist-add-form')->count(), $where.' card lost its wishlist form.');
        }
    }

    /**
     * No price. There is nothing to charge, so there is nothing to add — but the card still has to be
     * a card of the same size as the ones beside it.
     */
    public function testAProductWithNoPriceCannotBeAddedAndStillKeepsItsCardShape(): void
    {
        $this->product('CARD-NOPRICE', 'Fiyatsız Ürün', 'fiyatsiz-urun', null, 5);

        foreach ($this->cardsFor('fiyatsiz-urun') as $where => $card) {
            self::assertSame(0, $card->filter('.product-actions .cart-add-form')->count(), $where.' card offered a cart form for a product with no price.');
            $state = $card->filter('.product-actions > .is-disabled');
            self::assertSame(1, $state->count(), $where.' card left its add-to-cart slot empty.');
            self::assertSame('Fiyat Sorun', trim($state->text()), $where.' card state label.');
            self::assertNotNull($state->attr('disabled'), $where.' card state was not disabled.');
            self::assertSame(1, $card->filter('.product-actions .wishlist-add-form')->count(), $where.' card lost its wishlist form.');
            // The price line the card has always printed for an unpriced product is still there.
            self::assertStringContainsString('Fiyat için iletişime geçin', $card->filter('.product-price')->text());
        }
    }

    /**
     * The homepage card and the catalogue card, for one product, as each page actually renders it.
     *
     * @return array<string, Crawler>
     */
    private function cardsFor(string $slug): array
    {
        return ['homepage' => $this->homeCard($slug), 'catalogue' => $this->catalogCard($slug)];
    }

    private function homeCard(string $slug): Crawler
    {
        return $this->cardOf($this->client->request('GET', '/yeni/'), $slug, true);
    }

    private function catalogCard(string $slug): Crawler
    {
        return $this->cardOf($this->client->request('GET', '/yeni/katalog?sort=name-asc'), $slug, false);
    }

    /**
     * The card one product is drawn on, whichever page drew it.
     *
     * The two cards share the `product-card` class and differ by `home-product-card`, and the
     * product link inside is what identifies the card, so the two are located the same way rather
     * than by position on the page — which would change as soon as a second product exists.
     */
    private function cardOf(Crawler $crawler, string $slug, bool $homepage): Crawler
    {
        $cards = $crawler->filterXPath(sprintf(
            './/article[contains(concat(" ", normalize-space(@class), " "), " product-card ")][%s][.//a[contains(@href, "/urun/%s")]]',
            $homepage
                ? 'contains(concat(" ", normalize-space(@class), " "), " home-product-card ")'
                : 'not(contains(concat(" ", normalize-space(@class), " "), " home-product-card "))',
            $slug,
        ));
        self::assertCount(1, $cards, sprintf(
            'The %s card for %s was not rendered exactly once.',
            $homepage ? 'homepage' : 'catalogue',
            $slug,
        ));

        return new Crawler($cards->getNode(0), $crawler->getUri());
    }

    private function product(string $sku, string $name, string $slug, ?int $priceMinor, int $quantity): Product
    {
        $product = new Product($sku, $name, $slug);
        $product->publish();
        $this->entityManager->persist($product);
        // No price record at all is how a product ends up without a price: the listing reads the
        // price the same way the pricing module does, by finding the record or not finding it.
        if (null !== $priceMinor) {
            $this->entityManager->persist(new ProductPrice(
                $product,
                Money::ofMinor($priceMinor, 'TRY'),
                TaxCategory::of('replacement-part'),
                TaxRate::fromBasisPoints(2_000),
            ));
        }
        $this->entityManager->persist(new ProductInventory($product, $quantity));
        $this->entityManager->flush();

        // Two carousel sections over the same product, because that is what it takes for the
        // homepage to draw the theme's product grid: the first product carousel of the page fills
        // the "top sellers" column beside the hero, and only the ones after it become the theme's
        // full-width card grid.
        foreach ([10, 20] as $sortOrder) {
            $section = new HomeSection(HomeSectionType::ProductCarousel, 'Öne çıkan ürünler', ['slugs' => [$slug]]);
            $section->setEnabled(true);
            $section->setSortOrder($sortOrder);
            $this->entityManager->persist($section);
        }
        $this->entityManager->flush();

        return $product;
    }
}
