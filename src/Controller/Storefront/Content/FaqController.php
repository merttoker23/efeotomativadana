<?php

declare(strict_types=1);

namespace App\Controller\Storefront\Content;

use App\Repository\Cms\FaqItemRepository;
use App\Shared\StorefrontPageContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FaqController extends AbstractController
{
    #[Route('/sikca-sorulan-sorular', name: 'storefront_faq_index', methods: ['GET'])]
    public function index(FaqItemRepository $faqItems, StorefrontPageContext $context): Response
    {
        return $this->render('storefront/faq/index.html.twig', $context->withLayout([
            'faqItems' => $faqItems->activeOrdered(),
        ]));
    }
}
