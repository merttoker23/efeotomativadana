<?php

namespace App\Controller\Storefront\Catalog;

use App\Module\Catalog\Query\CatalogCriteria;
use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Seo\SeoMetadata;
use App\Module\Seo\StorefrontSeo;
use App\Module\Settings\StoreConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    /** Number of popular sidebar options, plus the selected option if it falls outside this slice. */
    private const FILTER_OPTIONS = 24;

    /** Page size of the paged category and brand indexes. */
    private const BRANDS_PER_PAGE = 48;

    /**
     * How many products a listing renders, and then keeps rendering as the customer scrolls.
     *
     * Every catalogue listing — the whole catalogue, a category and a brand alike — is somewhere
     * a customer stays while browsing, so all three read as one long grid rather than as a
     * sequence of pages: thirty at a time, appended below the grid already on screen. It is still
     * the same paged query — page one is these thirty, page two is the next thirty — which is why
     * the page number, the filters and the sort all keep working, and why a visitor without
     * scripting still gets the ordinary pagination below.
     */
    private const LISTING_PAGE_SIZE = 30;

    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly StoreConfiguration $configuration,
        private readonly StorefrontSeo $seo,
    ) {
    }

    #[Route('/katalog', name: 'storefront_catalog_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->listing($request, 'Ürün Kataloğu');
    }

    #[Route('/kategori/{slug}', name: 'storefront_catalog_category', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function category(Request $request, string $slug): Response
    {
        $category = $this->catalog->category($slug);
        if (null === $category) {
            throw $this->createNotFoundException('Kategori bulunamadı.');
        }

        return $this->listing($request, $category->name, categorySlug: $category->slug);
    }

    #[Route('/marka/{slug}', name: 'storefront_catalog_brand', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function brand(Request $request, string $slug): Response
    {
        $brand = $this->catalog->brand($slug);
        if (null === $brand) {
            throw $this->createNotFoundException('Marka bulunamadı.');
        }

        return $this->listing($request, $brand->name, brandSlug: $brand->slug);
    }

    /**
     * Paged. This index used to render every published brand at once; an automotive catalogue
     * carries thousands of them, so both the response and the page grew with the brand list.
     */
    #[Route('/markalar', name: 'storefront_catalog_brands', methods: ['GET'])]
    public function brands(Request $request): Response
    {
        $page = $this->catalog->brandPage($request->query->getInt('page', 1), self::BRANDS_PER_PAGE);

        return $this->render('storefront/catalog/brands.html.twig', $this->context(
            ['brands' => $page->items, 'page' => $page, 'seo' => $this->seo->brandsIndex()],
            ['categories' => $this->catalog->categories(8), 'brands' => $this->catalog->brands(8)],
        ));
    }

    /**
     * Paged for the same reason. The catalogue's own category list was an unbounded read too.
     */
    #[Route('/kategoriler', name: 'storefront_catalog_categories', methods: ['GET'])]
    public function categories(Request $request): Response
    {
        $page = $this->catalog->categoryPage($request->query->getInt('page', 1), self::BRANDS_PER_PAGE);

        return $this->render('storefront/catalog/categories.html.twig', $this->context(
            ['categories' => $page->items, 'page' => $page, 'seo' => $this->seo->brandsIndex()],
            ['categories' => $this->catalog->categories(8), 'brands' => $this->catalog->brands(8)],
        ));
    }

    #[Route('/urun/{slug}', name: 'storefront_catalog_product', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function product(string $slug): Response
    {
        $product = $this->catalog->product($slug);
        if (null === $product) {
            throw $this->createNotFoundException('Ürün bulunamadı.');
        }

        return $this->render('storefront/catalog/product.html.twig', $this->context([
            'product' => $product,
            'similarProducts' => $this->catalog->similarProducts($product->id),
            'seo' => $this->seo->product($product),
        ]));
    }

    /**
     * The collections page: the published products that are on a discount at this moment.
     *
     * A sale in this store is a second price with a window around it, not a flag anybody sets, so
     * "on a discount right now" is the same three conditions the pricing module evaluates for every
     * price it prints: a sale price that exists, that has already started, that has not ended. It is
     * therefore the catalogue's own listing asked a different question — not a second listing
     * system — so the filters, the sort, the paging, the infinite scroll and the product cards are
     * exactly the ones every other listing uses. A sale that has ended or has not begun is not on
     * this page, and neither is a draft.
     */
    #[Route('/koleksiyonlar', name: 'storefront_collections_index', methods: ['GET'])]
    public function collections(Request $request): Response
    {
        return $this->listing($request, 'Koleksiyonlar', onSaleOnly: true, seo: $this->seo->collections());
    }

    /**
     * The catalogue listing, paged, and read as one long grid.
     *
     * The page is one page of a sorted, filtered query at a time, and it is told whether a further
     * page exists so the listing can keep loading without a page reload. Nothing else about the
     * query depends on that: the repository receives the same criteria and answers the same page,
     * and the template renders the same grid whether it was reached by scrolling or by a page
     * number a browser without scripting typed.
     */
    private function listing(
        Request $request,
        string $heading,
        ?string $categorySlug = null,
        ?string $brandSlug = null,
        bool $onSaleOnly = false,
        ?SeoMetadata $seo = null,
    ): Response {
        $criteria = CatalogCriteria::fromQuery($request->query, $categorySlug, $brandSlug, self::LISTING_PAGE_SIZE, $onSaleOnly);
        // Keep the sidebar bounded and always expose the selected option so it can be removed.
        $categories = $this->catalog->categoriesByPopularity(self::FILTER_OPTIONS);
        $brands = $this->catalog->brandsByPopularity(self::FILTER_OPTIONS);
        if (null !== $criteria->categorySlug && !in_array($criteria->categorySlug, array_column($categories, 'slug'), true)) {
            $selectedCategory = $this->catalog->category($criteria->categorySlug);
            if (null !== $selectedCategory) {
                $categories[] = $selectedCategory;
            }
        }
        if (null !== $criteria->brandSlug && !in_array($criteria->brandSlug, array_column($brands, 'slug'), true)) {
            $selectedBrand = $this->catalog->brand($criteria->brandSlug);
            if (null !== $selectedBrand) {
                $brands[] = $selectedBrand;
            }
        }
        $page = $this->catalog->search($criteria);

        return $this->render('storefront/catalog/index.html.twig', $this->context(
            [
                'heading' => $heading,
                'criteria' => $criteria,
                'price_bounds' => $this->catalog->priceBounds($criteria),
                'page' => $page,
                'categories' => $categories,
                'brands' => $brands,
                'route_filter' => null !== $categorySlug ? 'category' : (null !== $brandSlug ? 'brand' : null),
                // The page the listing would fetch next, or null on the last page. The template
                // turns it into the address of that page, and a null one is what tells the scroll
                // there is nothing left to ask for.
                'next_page' => $page->nextPage(),
                'seo' => $seo ?? $this->seo->catalogListing($heading, $categorySlug, $brandSlug),
            ],
            ['categories' => array_slice($categories, 0, 8), 'brands' => array_slice($brands, 0, 8)],
        ));
    }

    /**
     * @param array<string, mixed>                                                        $values
     * @param array{categories: list<mixed>, brands: list<mixed>}|null $navigation
     *
     * @return array<string, mixed>
     */
    private function context(array $values, ?array $navigation = null): array
    {
        return $values + [
            'store' => [
                'name' => $this->configuration->storeName(),
                'locale' => $this->configuration->defaultLocale(),
            ],
            'catalog_navigation' => $navigation ?? [
                'categories' => $this->catalog->categories(8),
                'brands' => $this->catalog->brands(8),
            ],
        ];
    }
}
