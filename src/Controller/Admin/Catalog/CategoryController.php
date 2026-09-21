<?php

declare(strict_types=1);

namespace App\Controller\Admin\Catalog;

use App\Entity\Catalog\Category;
use App\Form\Admin\AdminCategoryType;
use App\Module\Catalog\AdminCatalogData;
use App\Module\Catalog\AdminCatalogManager;
use App\Module\Catalog\PublicationStatus;
use App\Repository\Catalog\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/catalog/categories', name: 'admin_catalog_category_')]
#[IsGranted('ROLE_ADMIN')]
final class CategoryController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CategoryRepository $categories): Response
    {
        return $this->render('admin/catalog/categories/index.html.twig', ['page' => $categories->adminPage($request->query->getString('q'), $request->query->getInt('page', 1)), 'query' => $request->query->getString('q')]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(Request $request, AdminCatalogManager $manager): Response { return $this->form($request, null, new AdminCatalogData(), $manager); }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Category $category, Request $request, AdminCatalogManager $manager): Response
    {
        $data = new AdminCatalogData();
        $data->name = $category->name(); $data->slug = $category->slug(); $data->published = PublicationStatus::Published === $category->publicationStatus(); $data->parent = $category->parent();
        return $this->form($request, $category, $data, $manager);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Category $category, Request $request, AdminCatalogManager $manager): Response
    {
        if (!$this->isCsrfTokenValid('delete_category_'.$category->id(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $manager->delete($category); $this->addFlash('success', 'Category deleted.');
        return $this->redirectToRoute('admin_catalog_category_index');
    }

    private function form(Request $request, ?Category $category, AdminCatalogData $data, AdminCatalogManager $manager): Response
    {
        $form = $this->createForm(AdminCategoryType::class, $data); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try { $saved = $manager->saveCategory($category, $data); $this->addFlash('success', 'Category saved.'); return $this->redirectToRoute('admin_catalog_category_edit', ['id' => $saved->id()]); }
            catch (\DomainException|\InvalidArgumentException $exception) { $form->addError(new FormError($exception->getMessage())); }
        }
        return $this->render('admin/catalog/categories/form.html.twig', ['form' => $form, 'category' => $category], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
