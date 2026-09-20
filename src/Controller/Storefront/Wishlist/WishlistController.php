<?php

namespace App\Controller\Storefront\Wishlist;

use App\Entity\Catalog\Product;
use App\Entity\Customer\CustomerUser;
use App\Module\Cart\CartViolation;
use App\Module\Cart\WishlistManager;
use App\Shared\StorefrontPageContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class WishlistController extends AbstractController
{
    #[Route('/istek-listem', name: 'storefront_wishlist_index', methods: ['GET'])]
    public function index(StorefrontPageContext $context, WishlistManager $wishlist): Response
    {
        $customer = $this->customer();
        if (null === $customer) {
            $this->addFlash('warning', 'İstek listenizi kullanmak için giriş yapın.');

            return $this->redirectToRoute('customer_login');
        }

        return $this->render('storefront/wishlist/index.html.twig', $context->withLayout([
            'wishlist' => $wishlist->items($customer),
        ]));
    }

    #[Route('/istek-listem/ekle/{id}', name: 'storefront_wishlist_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function add(Product $product, Request $request, WishlistManager $wishlist): Response
    {
        if (!$this->isCsrfTokenValid('wishlist_add_'.$product->id(), $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek listesi isteği.');
        }

        $customer = $this->customer();
        if (null === $customer) {
            $this->addFlash('warning', 'İstek listenize ürün eklemek için giriş yapın.');

            return $this->redirectToRoute('customer_login');
        }

        try {
            $wishlist->add($customer, $product);
        } catch (CartViolation $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('storefront_catalog_product', ['slug' => $product->slug()]);
        }
        $this->addFlash('success', 'Ürün istek listenize eklendi.');

        return $this->redirectToRoute('storefront_wishlist_index');
    }

    #[Route('/istek-listem/{id}/sil', name: 'storefront_wishlist_remove', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function remove(int $id, Request $request, WishlistManager $wishlist): Response
    {
        if (!$this->isCsrfTokenValid('wishlist_remove', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek listesi isteği.');
        }

        $customer = $this->customer();
        if (null === $customer) {
            $this->addFlash('warning', 'İstek listenizi düzenlemek için giriş yapın.');

            return $this->redirectToRoute('customer_login');
        }

        try {
            $wishlist->remove($customer, $id);
        } catch (CartViolation) {
            throw $this->createNotFoundException('İstek listesi kaydı bulunamadı.');
        }
        $this->addFlash('success', 'Ürün istek listenizden kaldırıldı.');

        return $this->redirectToRoute('storefront_wishlist_index');
    }

    private function customer(): ?CustomerUser
    {
        $user = $this->getUser();

        return $user instanceof CustomerUser ? $user : null;
    }
}
