<?php

namespace App\Controller\Storefront\Cart;

use App\Entity\Catalog\Product;
use App\Module\Cart\CartManager;
use App\Module\Cart\CartViolation;
use App\Shared\StorefrontPageContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CartController extends AbstractController
{
    #[Route('/sepet', name: 'storefront_cart_index', methods: ['GET'])]
    public function index(StorefrontPageContext $context, CartManager $carts): Response
    {
        return $this->render('storefront/cart/index.html.twig', $context->withLayout([
            'cart' => $carts->view(),
        ]));
    }

    #[Route('/sepet/ekle/{id}', name: 'storefront_cart_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function add(Product $product, Request $request, CartManager $carts): Response
    {
        if (!$this->isCsrfTokenValid('cart_add_'.$product->id(), $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz sepet isteği.');
        }

        try {
            $carts->add($product, $request->request->get('quantity'));
            $this->addFlash('success', 'Ürün sepete eklendi.');
        } catch (CartViolation $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('storefront_cart_index');
    }

    #[Route('/sepet/{id}/guncelle', name: 'storefront_cart_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function update(int $id, Request $request, CartManager $carts): Response
    {
        if (!$this->isCsrfTokenValid('cart_update', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz sepet isteği.');
        }

        try {
            $carts->update($id, $request->request->get('quantity'));
            $this->addFlash('success', 'Sepet adedi güncellendi.');
        } catch (CartViolation $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('storefront_cart_index');
    }

    #[Route('/sepet/{id}/sil', name: 'storefront_cart_remove', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function remove(int $id, Request $request, CartManager $carts): Response
    {
        if (!$this->isCsrfTokenValid('cart_remove', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz sepet isteği.');
        }

        try {
            $carts->remove($id);
            $this->addFlash('success', 'Ürün sepetten kaldırıldı.');
        } catch (CartViolation $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('storefront_cart_index');
    }
}
