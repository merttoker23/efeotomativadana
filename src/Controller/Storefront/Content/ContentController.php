<?php

namespace App\Controller\Storefront\Content;

use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Cms\BlogPostRepository;
use App\Repository\Cms\InformationPageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContentController extends AbstractController
{
    public function __construct(private readonly StoreConfiguration $settings, private readonly CatalogQuery $catalog) {}

    #[Route('/blog', name: 'storefront_blog_index', methods: ['GET'])]
    public function blog(BlogPostRepository $posts): Response
    {
        return $this->render('storefront/blog/index.html.twig', $this->context(['posts' => $posts->latestPublished()]));
    }

    #[Route('/blog/{slug}', name: 'storefront_blog_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function post(string $slug, BlogPostRepository $posts): Response
    {
        $post = $posts->findOneBy(['slug' => $slug, 'published' => true]);
        if (null === $post) { throw $this->createNotFoundException(); }
        return $this->render('storefront/blog/show.html.twig', $this->context(['post' => $post]));
    }

    #[Route('/bilgi', name: 'storefront_information_index', methods: ['GET'])]
    public function pages(InformationPageRepository $pages): Response
    {
        return $this->render('storefront/content/index.html.twig', $this->context(['pages' => $pages->findBy(['published' => true], ['title' => 'ASC'])]));
    }

    #[Route('/bilgi/{slug}', name: 'storefront_information_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function page(string $slug, InformationPageRepository $pages): Response
    {
        $page = $pages->findOneBy(['slug' => $slug, 'published' => true]);
        if (null === $page) { throw $this->createNotFoundException(); }
        return $this->render('storefront/content/show.html.twig', $this->context(['page' => $page]));
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
