<?php

namespace App\Controller\Storefront\Catalog;

use App\Module\Catalog\Query\CatalogCriteria;
use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Seo\StorefrontSeo;
use App\Module\Settings\StoreConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    /** How many categories/brands the catalogue's own sidebar offers before deferring to the index. */
    private const FILTER_OPTIONS = 24;

    /** Page size of the paged category and brand indexes. */
    private const BRANDS_PER_PAGE = 48;

    /**
     * How many products a category page renders, and then keeps rendering as the customer scrolls.
     *
     * A category is the one listing a customer stays on while browsing, so it reads as one long
     * grid rather than as a sequence of pages: thirty at a time, appended below the grid already
     * on screen. It is still the same paged query — page one is these thirty, page two is the next
     * thirty — which is why the page number, the filters and the sort all keep working, and why a
     * visitor without scripting still gets the ordinary pagination below.
     */
    private const CATEGORY_PAGE_SIZE = 30;

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

        return $this->listing($request, $category->name, categorySlug: $category->slug, pageSize: self::CATEGORY_PAGE_SIZE);
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
            'seo' => $this->seo->product($product),
        ]));
    }

    /**
     * The catalogue listing, paged.
     *
     * `$pageSize` is what separates a listing that reads as a sequence of pages from one that
     * reads as a single long grid. Nothing else changes with it: the repository still receives the
     * same criteria and still answers one page of a sorted, filtered query, and the template
     * renders the same grid. When a size is given, the page is also told whether a further page
     * exists so the listing can keep loading without a page reload.
     */
    private function listing(
        Request $request,
        string $heading,
        ?string $categorySlug = null,
        ?string $brandSlug = null,
        ?int $pageSize = null,
    ): Response {
        $criteria = CatalogCriteria::fromQuery($request->query, $categorySlug, $brandSlug, $pageSize);
        // The sidebar is a filter, not an index: it renders a bounded, most-popular slice and
        // links to the paged index for the full set, which is where the unbounded read used to
        // happen. Every category and brand stays reachable through that index.
        $categories = $this->catalog->categoriesByPopularity(self::FILTER_OPTIONS);
        $brands = $this->catalog->brandsByPopularity(self::FILTER_OPTIONS);
        $page = $this->catalog->search($criteria);

        return $this->render('storefront/catalog/index.html.twig', $this->context(
            [
                'heading' => $heading,
                'criteria' => $criteria,
                'page' => $page,
                'categories' => $categories,
                'brands' => $brands,
                'route_filter' => null !== $categorySlug ? 'category' : (null !== $brandSlug ? 'brand' : null),
                // The page the listing would fetch next, or null on a listing that does not scroll
                // and on the last page. The template turns it into the address of that page.
                'next_page' => null === $pageSize ? null : $page->nextPage(),
                'seo' => $this->seo->catalogListing($heading, $categorySlug, $brandSlug),
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
