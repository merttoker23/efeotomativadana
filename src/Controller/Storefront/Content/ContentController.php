<?php

namespace App\Controller\Storefront\Content;

use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Seo\StorefrontSeo;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Cms\BlogPostRepository;
use App\Repository\Cms\InformationPageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContentController extends AbstractController
{
    public function __construct(
        private readonly StoreConfiguration $settings,
        private readonly CatalogQuery $catalog,
        private readonly StorefrontSeo $seo,
    ) {
    }

    /**
     * Paged. The blog index read every published post, so its cost grew with the archive and
     * its response grew with it too.
     */
    #[Route('/blog', name: 'storefront_blog_index', methods: ['GET'])]
    public function blog(Request $request, BlogPostRepository $posts): Response
    {
        $page = $posts->page($request->query->getInt('page', 1), BlogPostRepository::PER_PAGE, true);

        return $this->render('storefront/blog/index.html.twig', $this->context([
            'posts' => $page->items,
            'page' => $page,
            'seo' => $this->seo->blogIndex(),
        ]));
    }

    #[Route('/blog/{slug}', name: 'storefront_blog_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function post(string $slug, BlogPostRepository $posts): Response
    {
        $post = $posts->findOneBy(['slug' => $slug, 'published' => true]);
        if (null === $post) { throw $this->createNotFoundException(); }
        return $this->render('storefront/blog/show.html.twig', $this->context(['post' => $post, 'seo' => $this->seo->blogPost($post)]));
    }

    /** Paged, for the same reason as the blog index. */
    #[Route('/bilgi', name: 'storefront_information_index', methods: ['GET'])]
    public function pages(Request $request, InformationPageRepository $pages): Response
    {
        $page = $pages->page($request->query->getInt('page', 1), InformationPageRepository::PER_PAGE, true);

        return $this->render('storefront/content/index.html.twig', $this->context([
            'pages' => $page->items,
            'page' => $page,
            'seo' => $this->seo->informationIndex(),
        ]));
    }

    #[Route('/bilgi/{slug}', name: 'storefront_information_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function page(string $slug, InformationPageRepository $pages): Response
    {
        $page = $pages->findOneBy(['slug' => $slug, 'published' => true]);
        if (null === $page) { throw $this->createNotFoundException(); }
        return $this->render('storefront/content/show.html.twig', $this->context(['page' => $page, 'seo' => $this->seo->informationPage($page)]));
    }

    /** @param array<string, mixed> $extra
     *  @return array<string, mixed>
     */
    private function context(array $extra): array
    {
        return $extra + [
            'store' => ['name' => $this->settings->storeName(), 'locale' => $this->settings->defaultLocale()],
            'catalog_navigation' => ['categories' => $this->catalog->categories(8), 'brands' => $this->catalog->brands(8)],
        ];
    }
}
