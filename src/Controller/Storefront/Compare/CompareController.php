<?php

namespace App\Controller\Storefront\Compare;

use App\Entity\Catalog\Product;
use App\Module\Cart\CartViolation;
use App\Module\Cart\ComparisonManager;
use App\Shared\StorefrontPageContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CompareController extends AbstractController
{
    #[Route('/karsilastir', name: 'storefront_compare_index', methods: ['GET'])]
    public function index(StorefrontPageContext $context, ComparisonManager $comparison): Response
    {
        return $this->render('storefront/compare/index.html.twig', $context->withLayout([
            'comparison' => $comparison->view(),
        ]));
    }

    #[Route('/karsilastir/ekle/{id}', name: 'storefront_compare_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function add(Product $product, Request $request, ComparisonManager $comparison): Response
    {
        if (!$this->isCsrfTokenValid('compare_add_'.$product->id(), $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz karşılaştırma isteği.');
        }

        try {
            $comparison->add($product);
            $this->addFlash('success', 'Ürün karşılaştırmaya eklendi.');
        } catch (CartViolation $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('storefront_compare_index');
    }

    #[Route('/karsilastir/sil/{id}', name: 'storefront_compare_remove', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function remove(int $id, Request $request, ComparisonManager $comparison): Response
    {
        if (!$this->isCsrfTokenValid('compare_remove_'.$id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz karşılaştırma isteği.');
        }

        $comparison->remove($id);
        $this->addFlash('success', 'Ürün karşılaştırmadan kaldırıldı.');

        return $this->redirectToRoute('storefront_compare_index');
    }
}
