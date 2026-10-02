<?php

namespace App\Controller\Storefront;

use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Cms\HomepageRenderer;
use App\Module\Seo\StorefrontSeo;
use App\Module\Settings\StoreConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly StorefrontSeo $seo,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(StoreConfiguration $configuration, CatalogQuery $catalog, HomepageRenderer $homepage): Response
    {
        $categories = $catalog->categories(8);

        return $this->render('storefront/home/index.html.twig', [
            'store' => [
                'name' => $configuration->storeName(),
                'locale' => $configuration->defaultLocale(),
            ],
            'catalog_navigation' => [
                'categories' => $categories,
                'brands' => $catalog->brands(8),
            ],
            'homepage' => $homepage->render(),
            'seo' => $this->seo->home(),
        ]);
    }
}
