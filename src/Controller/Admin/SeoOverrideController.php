<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Catalog\Brand;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use App\Entity\Cms\BlogPost;
use App\Entity\Cms\InformationPage;
use App\Entity\Seo\SeoOverride;
use App\Entity\Seo\SeoResourceType;
use App\Form\Admin\AdminSeoType;
use App\Module\Seo\SeoOverrideData;
use App\Repository\Seo\SeoOverrideRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The merchant's own corrections to one content type's search metadata.
 *
 * One screen per content type, because that is the grain the override is stored at. The four
 * actions are the same form and the same service with a different subject, so adding SEO to a
 * new content type is a route and two lines rather than a new controller.
 */
#[IsGranted('ROLE_ADMIN')]
final class SeoOverrideController extends AbstractController
{
    public function __construct(
        private readonly SeoOverrideRepository $overrides,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/admin/catalog/products/{id}/seo', name: 'admin_seo_product', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function product(Request $request, int $id): Response
    {
        $product = $this->entityManager->find(Product::class, $id);

        return null === $product
            ? $this->notFound()
            : $this->edit($request, SeoResourceType::Product, (int) $product->id(), 'Product', 'admin_catalog_product_edit', ['id' => $id]);
    }

    #[Route('/admin/catalog/categories/{id}/seo', name: 'admin_seo_category', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function category(Request $request, int $id): Response
    {
        $category = $this->entityManager->find(Category::class, $id);

        return null === $category
            ? $this->notFound()
            : $this->edit($request, SeoResourceType::Category, (int) $category->id(), 'Category', 'admin_catalog_category_edit', ['id' => $id]);
    }

    #[Route('/admin/catalog/brands/{id}/seo', name: 'admin_seo_brand', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function brand(Request $request, int $id): Response
    {
        $brand = $this->entityManager->find(Brand::class, $id);

        return null === $brand
            ? $this->notFound()
            : $this->edit($request, SeoResourceType::Brand, (int) $brand->id(), 'Brand', 'admin_catalog_brand_edit', ['id' => $id]);
    }

    #[Route('/admin/cms/{kind}/{id}/seo', name: 'admin_seo_content', requirements: ['kind' => 'blog|pages', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function content(Request $request, string $kind, int $id): Response
    {
        if ('blog' === $kind) {
            $item = $this->entityManager->find(BlogPost::class, $id);
            $type = SeoResourceType::BlogPost;
            $subject = 'Blog post';
        } else {
            $item = $this->entityManager->find(InformationPage::class, $id);
            $type = SeoResourceType::InformationPage;
            $subject = 'Information page';
        }

        if (null === $item || null === $item->id()) {
            return $this->notFound();
        }

        // The CMS index is a list rather than a per-item screen, so "back" returns to the list
        // rather than to an edit form this controller does not own.
        return $this->edit($request, $type, (int) $item->id(), $subject, 'admin_cms_content_index', ['kind' => $kind]);
    }

    /**
     * @param array<string, scalar> $backParameters
     */
    private function edit(
        Request $request,
        SeoResourceType $type,
        int $resourceId,
        string $subject,
        string $backRoute,
        array $backParameters = [],
    ): Response {
        $existing = $this->overrides->findFor($type, $resourceId);
        $data = null === $existing ? new SeoOverrideData() : SeoOverrideData::fromOverride($existing);

        $form = $this->createForm(AdminSeoType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->store($type, $resourceId, $data);
            $this->addFlash('success', 'SEO overrides saved.');

            return $this->redirectToRoute($backRoute, $backParameters);
        }

        return $this->render('admin/seo/override.html.twig', [
            'form' => $form,
            'subject' => $subject,
            'back_url' => $this->generateUrl($backRoute, $backParameters),
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function store(SeoResourceType $type, int $resourceId, SeoOverrideData $data): void
    {
        $existing = $this->overrides->findFor($type, $resourceId);

        if (null === $existing) {
            // Nothing to correct is nothing to store. A row of empty strings would silence the
            // storefront's own fallback rather than add to it.
            if ($data->isEmpty()) {
                return;
            }
            $existing = SeoOverride::for($type, $resourceId);
            $this->entityManager->persist($existing);
        }

        $data->applyTo($existing);
        $this->entityManager->flush();
    }

    private function notFound(): Response
    {
        throw $this->createNotFoundException();
    }
}
