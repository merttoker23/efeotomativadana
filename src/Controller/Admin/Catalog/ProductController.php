<?php

declare(strict_types=1);

namespace App\Controller\Admin\Catalog;

use App\Entity\Catalog\Product;
use App\Form\Admin\AdminProductType;
use App\Module\Admin\ConcurrentAdminEdit;
use App\Module\Catalog\AdminCatalogManager;
use App\Module\Catalog\AdminProductData;
use App\Module\Catalog\PublicationStatus;
use App\Repository\Catalog\ProductRepository;
use App\Repository\Commerce\ProductInventoryRepository;
use App\Repository\Commerce\ProductPriceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/catalog/products', name: 'admin_catalog_product_')]
#[IsGranted('ROLE_ADMIN')]
final class ProductController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products): Response
    {
        $status = PublicationStatus::tryFrom($request->query->getString('status'));
        return $this->render('admin/catalog/products/index.html.twig', [
            'page' => $products->adminPage($request->query->getString('q'), $status, $request->query->getInt('page', 1)),
            'query' => $request->query->getString('q'),
            'status' => $status->value ?? '',
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(Request $request, AdminCatalogManager $manager): Response
    {
        return $this->form($request, null, new AdminProductData(), null, $manager);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Product $product, Request $request, ProductPriceRepository $prices, ProductInventoryRepository $inventory, AdminCatalogManager $manager): Response
    {
        $stock = $inventory->findOneByProduct($product);
        return $this->form($request, $product, AdminProductData::fromProduct($product, $prices->findOneByProduct($product), $stock), $stock?->version(), $manager);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Product $product, Request $request, AdminCatalogManager $manager): Response
    {
        if (!$this->isCsrfTokenValid('delete_product_'.$product->id(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $manager->delete($product);
        $this->addFlash('success', 'Product deleted.');
        return $this->redirectToRoute('admin_catalog_product_index');
    }

    private function form(Request $request, ?Product $product, AdminProductData $data, ?int $inventoryVersion, AdminCatalogManager $manager): Response
    {
        $form = $this->createForm(AdminProductType::class, $data, ['inventory_version' => null === $inventoryVersion ? null : (string) $inventoryVersion]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $expectedVersion = $form->get('inventoryVersion')->getData();
                $saved = $manager->saveProduct($product, $data, is_numeric($expectedVersion) ? (int) $expectedVersion : null);
                $this->addFlash('success', 'Product saved.');
                return $this->redirectToRoute('admin_catalog_product_edit', ['id' => $saved->id()]);
            } catch (ConcurrentAdminEdit $exception) {
                $form->addError(new FormError($exception->getMessage()));
                return $this->renderProductForm($form, $product, Response::HTTP_CONFLICT);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->renderProductForm($form, $product, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function renderProductForm(\Symfony\Component\Form\FormInterface $form, ?Product $product, int $status): Response
    {
        return $this->render('admin/catalog/products/form.html.twig', ['form' => $form, 'product' => $product], new Response(status: $status));
    }
}
