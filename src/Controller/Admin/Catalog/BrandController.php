<?php

declare(strict_types=1);

namespace App\Controller\Admin\Catalog;

use App\Entity\Catalog\Brand;
use App\Form\Admin\AdminBrandType;
use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditLogger;
use App\Module\Catalog\AdminCatalogData;
use App\Module\Catalog\AdminCatalogManager;
use App\Module\Catalog\BrandLogoStorage;
use App\Module\Catalog\PublicationStatus;
use App\Repository\Catalog\BrandRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/catalog/brands', name: 'admin_catalog_brand_')]
#[IsGranted('ROLE_ADMIN')]
final class BrandController extends AbstractController
{
    public function __construct(private readonly BrandLogoStorage $logos, private readonly EntityManagerInterface $entityManager)
    {
    }

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
    public function delete(Brand $brand, Request $request, AdminCatalogManager $manager, AuditLogger $audit): Response
    {
        if (!$this->isCsrfTokenValid('delete_brand_'.$brand->id(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $audit->record(AuditAction::CatalogBrandDeleted, $brand->slug(), [
            'brand_id' => $brand->id(),
            'name' => $brand->name(),
        ]);
        $manager->delete($brand); $this->addFlash('success', 'Marka silindi.'); return $this->redirectToRoute('admin_catalog_brand_index');
    }

    private function form(Request $request, ?Brand $brand, AdminCatalogData $data, AdminCatalogManager $manager): Response
    {
        $form = $this->createForm(AdminBrandType::class, $data); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $logoBrandId = null;
            $previousLogo = null;
            try {
                $logo = $form->get('logo')->getData();
                $saved = $this->entityManager->wrapInTransaction(function () use ($manager, $brand, $data, $logo, &$logoBrandId, &$previousLogo): Brand {
                    $saved = $manager->saveBrand($brand, $data);
                    if ($logo instanceof UploadedFile) {
                        $id = (int) $saved->id();
                        $previousLogo = $this->logos->snapshot($id);
                        $logoBrandId = $id;
                        $this->logos->store($id, $logo);
                    }

                    return $saved;
                });
                $this->addFlash('success', 'Marka kaydedildi.');

                return $this->redirectToRoute('admin_catalog_brand_edit', ['id' => $saved->id()]);
            } catch (\Throwable $exception) {
                if (null !== $logoBrandId) {
                    $this->logos->restore($logoBrandId, $previousLogo);
                }
                if (!$exception instanceof \DomainException && !$exception instanceof \InvalidArgumentException && !$exception instanceof \RuntimeException) {
                    throw $exception;
                }
                $form->addError(new FormError($exception->getMessage()));
            }
        }
        return $this->render('admin/catalog/brands/form.html.twig', ['form' => $form, 'brand' => $brand], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
