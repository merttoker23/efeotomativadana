<?php

declare(strict_types=1);

namespace App\Controller\Admin\Cms;

use App\Entity\Cms\FaqItem;
use App\Repository\Cms\FaqItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/cms/faq', name: 'admin_cms_faq_')]
#[IsGranted('ROLE_ADMIN')]
final class FaqController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(FaqItemRepository $faqItems): Response
    {
        return $this->render('admin/cms/faq/index.html.twig', [
            'items' => $faqItems->ordered(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(Request $request, FaqItemRepository $faqItems, EntityManagerInterface $manager): Response
    {
        return $this->form($request, null, $faqItems, $manager);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(FaqItem $item, Request $request, FaqItemRepository $faqItems, EntityManagerInterface $manager): Response
    {
        return $this->form($request, $item, $faqItems, $manager);
    }

    #[Route('/{id}/toggle', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(FaqItem $item, Request $request, EntityManagerInterface $manager): Response
    {
        $this->csrf($request, 'cms_faq_item_'.$item->id());
        $item->setActive(!$item->active());
        $manager->flush();
        $this->addFlash('success', 'SSS durumu güncellendi.');

        return $this->redirectToRoute('admin_cms_faq_index');
    }

    #[Route('/{id}/order', name: 'order', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function order(FaqItem $item, Request $request, EntityManagerInterface $manager): Response
    {
        $this->csrf($request, 'cms_faq_item_'.$item->id());

        try {
            $item->setSortOrder($request->request->getInt('sort_order'));
            $manager->flush();
            $this->addFlash('success', 'SSS sırası güncellendi.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_cms_faq_index');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(FaqItem $item, Request $request, EntityManagerInterface $manager): Response
    {
        $this->csrf($request, 'cms_faq_item_'.$item->id());
        $manager->remove($item);
        $manager->flush();
        $this->addFlash('success', 'SSS kaydı silindi.');

        return $this->redirectToRoute('admin_cms_faq_index');
    }

    private function form(Request $request, ?FaqItem $item, FaqItemRepository $faqItems, EntityManagerInterface $manager): Response
    {
        $values = [
            'question' => $item?->question() ?? '',
            'answer' => $item?->answer() ?? '',
            'active' => $item?->active() ?? false,
            'sort_order' => $item?->sortOrder() ?? $faqItems->nextSortOrder(),
        ];
        $error = null;

        if ($request->isMethod('POST')) {
            $this->csrf($request, 'cms_faq_form');
            $values = [
                'question' => $request->request->getString('question'),
                'answer' => $request->request->getString('answer'),
                'active' => '1' === $request->request->getString('active'),
                'sort_order' => $request->request->getInt('sort_order'),
            ];

            try {
                if ($values['sort_order'] < 0 || $values['sort_order'] > 1_000_000) {
                    throw new \InvalidArgumentException('Sıra 0 ile 1.000.000 arasında olmalıdır.');
                }

                $target = $item ?? new FaqItem($values['question'], $values['answer']);
                $target->update($values['question'], $values['answer']);
                $target->setActive($values['active']);
                $target->setSortOrder($values['sort_order']);
                $manager->persist($target);
                $manager->flush();
                $this->addFlash('success', 'SSS kaydı kaydedildi.');

                return $this->redirectToRoute('admin_cms_faq_index');
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('admin/cms/faq/form.html.twig', [
            'item' => $item,
            'values' => $values,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function csrf(Request $request, string $key): void
    {
        if (!$this->isCsrfTokenValid($key, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
