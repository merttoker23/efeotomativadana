<?php

declare(strict_types=1);

namespace App\Controller\Admin\Catalog;

use App\Entity\Catalog\Brand;
use App\Form\Admin\AdminBrandType;
use App\Module\Catalog\AdminCatalogData;
use App\Module\Catalog\AdminCatalogManager;
use App\Module\Catalog\PublicationStatus;
use App\Repository\Catalog\BrandRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/catalog/brands', name: 'admin_catalog_brand_')]
#[IsGranted('ROLE_ADMIN')]
final class BrandController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, BrandRepository $brands): Response { return $this->render('admin/catalog/brands/index.html.twig', ['page' => $brands->adminPage($request->query->getString('q'), $request->query->getInt('page', 1)), 'query' => $request->query->getString('q')]); }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(Request $request, AdminCatalogManager $manager): Response { return $this->form($request, null, new AdminCatalogData(), $manager); }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Brand $brand, Request $request, AdminCatalogManager $manager): Response
    {
        $data = new AdminCatalogData(); $data->name = $brand->name(); $data->slug = $brand->slug(); $data->published = PublicationStatus::Published === $brand->publicationStatus();
        return $this->form($request, $brand, $data, $manager);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Brand $brand, Request $request, AdminCatalogManager $manager): Response
    {
        if (!$this->isCsrfTokenValid('delete_brand_'.$brand->id(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $manager->delete($brand); $this->addFlash('success', 'Brand deleted.'); return $this->redirectToRoute('admin_catalog_brand_index');
    }

    private function form(Request $request, ?Brand $brand, AdminCatalogData $data, AdminCatalogManager $manager): Response
    {
        $form = $this->createForm(AdminBrandType::class, $data); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try { $saved = $manager->saveBrand($brand, $data); $this->addFlash('success', 'Brand saved.'); return $this->redirectToRoute('admin_catalog_brand_edit', ['id' => $saved->id()]); }
            catch (\DomainException|\InvalidArgumentException $exception) { $form->addError(new FormError($exception->getMessage())); }
        }
        return $this->render('admin/catalog/brands/form.html.twig', ['form' => $form, 'brand' => $brand], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
