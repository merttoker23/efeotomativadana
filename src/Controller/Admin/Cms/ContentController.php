<?php

namespace App\Controller\Admin\Cms;

use App\Entity\Cms\BlogPost;
use App\Entity\Cms\InformationPage;
use App\Repository\Cms\BlogPostRepository;
use App\Repository\Cms\InformationPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/cms/{kind}', name: 'admin_cms_content_', requirements: ['kind' => 'blog|pages'])]
#[IsGranted('ROLE_ADMIN')]
final class ContentController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $kind, BlogPostRepository $posts, InformationPageRepository $pages): Response
    {
        return $this->render('admin/cms/content/index.html.twig', ['kind' => $kind, 'items' => 'blog' === $kind ? $posts->findBy([], ['id' => 'DESC']) : $pages->findBy([], ['id' => 'DESC'])]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(string $kind, Request $request, BlogPostRepository $posts, InformationPageRepository $pages, EntityManagerInterface $manager): Response
    {
        return $this->form($kind, null, $request, $posts, $pages, $manager);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(string $kind, int $id, Request $request, BlogPostRepository $posts, InformationPageRepository $pages, EntityManagerInterface $manager): Response
    {
        $item = 'blog' === $kind ? $posts->find($id) : $pages->find($id);
        if (null === $item) { throw $this->createNotFoundException(); }
        return $this->form($kind, $item, $request, $posts, $pages, $manager);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(string $kind, int $id, Request $request, BlogPostRepository $posts, InformationPageRepository $pages, EntityManagerInterface $manager): Response
    {
        $this->csrf($request, 'cms_content_'.$kind.'_'.$id);
        $item = 'blog' === $kind ? $posts->find($id) : $pages->find($id);
        if (null === $item) { throw $this->createNotFoundException(); }
        $manager->remove($item);
        $manager->flush();
        return $this->redirectToRoute('admin_cms_content_index', ['kind' => $kind]);
    }

    private function form(string $kind, BlogPost|InformationPage|null $item, Request $request, BlogPostRepository $posts, InformationPageRepository $pages, EntityManagerInterface $manager): Response
    {
        $values = ['title' => $item?->title() ?? '', 'slug' => $item?->slug() ?? '', 'excerpt' => $item instanceof BlogPost ? $item->excerpt() : '', 'body' => $item?->body() ?? '', 'published' => $item?->published() ?? false];
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'cms_content_form_'.$kind);
            foreach (['title', 'slug', 'excerpt', 'body'] as $key) { $values[$key] = $request->request->getString($key); }
            $values['published'] = '1' === $request->request->getString('published');
            try {
                $existing = ('blog' === $kind ? $posts : $pages)->findOneBy(['slug' => $values['slug']]);
                if (null !== $existing && $existing !== $item) { throw new \InvalidArgumentException('Slug is already in use.'); }
                if (null !== $item && $item->published() && $values['slug'] !== $item->slug()) { throw new \InvalidArgumentException('Published slugs cannot be changed.'); }
                if ('blog' === $kind) {
                    $item ??= new BlogPost($values['title'], $values['slug'], $values['excerpt'], $values['body']);
                    if (!$item instanceof BlogPost) { throw new \LogicException(); }
                    $item->update($values['title'], $values['slug'], $values['excerpt'], $values['body']);
                } else {
                    $item ??= new InformationPage($values['title'], $values['slug'], $values['body']);
                    if (!$item instanceof InformationPage) { throw new \LogicException(); }
                    $item->update($values['title'], $values['slug'], $values['body']);
                }
                $item->setPublished($values['published']);
                $manager->persist($item);
                $manager->flush();
                return $this->redirectToRoute('admin_cms_content_index', ['kind' => $kind]);
            } catch (\InvalidArgumentException $exception) { $error = $exception->getMessage(); }
        }
        return $this->render('admin/cms/content/form.html.twig', ['kind' => $kind, 'item' => $item, 'values' => $values, 'error' => $error], new Response(status: null === $error ? 200 : 422));
    }

    private function csrf(Request $request, string $key): void
    {
        if (!$this->isCsrfTokenValid($key, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
    }
}
