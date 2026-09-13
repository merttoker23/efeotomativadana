<?php

namespace App\Controller\Storefront;

use App\Module\Settings\StoreConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(StoreConfiguration $configuration): Response
    {
        return $this->render('storefront/home/index.html.twig', [
            'store' => [
                'name' => $configuration->storeName(),
                'locale' => $configuration->defaultLocale(),
            ],
        ]);
    }
}
