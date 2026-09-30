<?php

namespace App\Controller\Admin\Cms;

use App\Entity\Cms\BlogPost;
use App\Entity\Cms\InformationPage;
use App\Entity\Seo\SeoResourceType;
use App\Module\Audit\AuditAction;
use App\Module\Audit\AuditLogger;
use App\Module\Seo\SlugRedirectRecorder;
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
    public function __construct(
        private readonly SlugRedirectRecorder $redirects,
    ) {
    }

    /**
     * Paged rather than "everything": this was the only admin list in the application that read
     * a whole table, which made the screen and the request both grow with the content archive.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, string $kind, BlogPostRepository $posts, InformationPageRepository $pages): Response
    {
        $page = 'blog' === $kind ? $posts->page($request->query->getInt('page', 1)) : $pages->page($request->query->getInt('page', 1));

        return $this->render('admin/cms/content/index.html.twig', ['kind' => $kind, 'items' => $page->items, 'page' => $page]);
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

    /**
     * Recorded before the row goes, because a deleted page leaves the redirect history pointing
     * at a target that no longer resolves and nothing else would say the content ever existed.
     * The body is not copied: an audit row is not a content backup, and a full copy of every
     * deleted page is exactly the kind of thing a trail should not accumulate.
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(string $kind, int $id, Request $request, BlogPostRepository $posts, InformationPageRepository $pages, EntityManagerInterface $manager, AuditLogger $audit): Response
    {
        $this->csrf($request, 'cms_content_'.$kind.'_'.$id);
        $item = 'blog' === $kind ? $posts->find($id) : $pages->find($id);
        if (null === $item) { throw $this->createNotFoundException(); }
        $audit->record(AuditAction::CmsContentDeleted, $item->slug(), [
            'kind' => $kind,
            'content_id' => $id,
            'title' => $item->title(),
            'published' => $item->published(),
        ]);
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
                // A published page keeps its old address through redirect history rather than by
                // refusing the rename, so a corrected title does not cost the URL its inbound links.
                // A page being unpublished in the same save is leaving the storefront rather than
                // moving, and a history row for it would point at a target that never resolves.
                $wasPublished = null !== $item && $item->published() && $values['published'];
                $previousSlug = $item?->slug();
                if ('blog' === $kind) {
                    $item ??= new BlogPost($values['title'], $values['slug'], $values['excerpt'], $values['body']);
                    if (!$item instanceof BlogPost) { throw new \LogicException(); }
                    $item->update($values['title'], $values['slug'], $values['excerpt'], $values['body']);
                } else {
                    $item ??= new InformationPage($values['title'], $values['slug'], $values['body']);
                    if (!$item instanceof InformationPage) { throw new \LogicException(); }
                    $item->update($values['title'], $values['slug'], $values['body']);
                }
                if (null !== $previousSlug && null !== $item->id()) {
                    $this->redirects->record(
                        'blog' === $kind ? SeoResourceType::BlogPost : SeoResourceType::InformationPage,
                        (int) $item->id(),
                        $wasPublished,
                        $previousSlug,
                        $item->slug(),
                    );
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
