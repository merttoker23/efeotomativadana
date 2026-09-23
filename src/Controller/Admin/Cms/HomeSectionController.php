<?php

namespace App\Controller\Admin\Cms;

use App\Entity\Cms\HomeSection;
use App\Module\Cms\HomeSectionType;
use App\Repository\Cms\HomeSectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/cms/home', name: 'admin_cms_home_')]
#[IsGranted('ROLE_ADMIN')]
final class HomeSectionController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(HomeSectionRepository $sections): Response
    {
        return $this->render('admin/cms/home/index.html.twig', ['sections' => $sections->ordered()]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(Request $request, HomeSectionRepository $sections, EntityManagerInterface $manager): Response
    {
        return $this->form($request, null, $sections, $manager);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(HomeSection $section, Request $request, HomeSectionRepository $sections, EntityManagerInterface $manager): Response
    {
        return $this->form($request, $section, $sections, $manager);
    }

    #[Route('/{id}/toggle', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(HomeSection $section, Request $request, EntityManagerInterface $manager): Response
    {
        $this->csrf($request, 'cms_section_'.$section->id());
        $section->setEnabled(!$section->enabled());
        $manager->flush();
        return $this->redirectToRoute('admin_cms_home_index');
    }

    #[Route('/{id}/move/{direction}', name: 'move', requirements: ['id' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function move(HomeSection $section, string $direction, Request $request, HomeSectionRepository $sections, EntityManagerInterface $manager): Response
    {
        $this->csrf($request, 'cms_section_'.$section->id());
        $ordered = $sections->ordered();
        $index = array_search($section, $ordered, true);
        if (false === $index) { throw $this->createNotFoundException(); }
        $target = $index + ('up' === $direction ? -1 : 1);
        if (isset($ordered[$target])) {
            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
            foreach ($ordered as $position => $item) { $item->setSortOrder($position * 10); }
            $manager->flush();
        }
        return $this->redirectToRoute('admin_cms_home_index');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(HomeSection $section, Request $request, EntityManagerInterface $manager): Response
    {
        $this->csrf($request, 'cms_section_'.$section->id());
        $manager->remove($section);
        $manager->flush();
        return $this->redirectToRoute('admin_cms_home_index');
    }

    private function form(Request $request, ?HomeSection $section, HomeSectionRepository $sections, EntityManagerInterface $manager): Response
    {
        $values = [
            'type' => $section?->type()->value ?? HomeSectionType::Features->value,
            'title' => $section?->title() ?? '',
            'subtitle' => $section?->subtitle() ?? '',
            'configuration' => null === $section ? '{"features":[]}' : json_encode($section->configuration(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        ];
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'cms_home_form');
            foreach ($values as $key => $_) { $values[$key] = $request->request->getString($key); }
            try {
                $type = HomeSectionType::tryFrom($values['type']) ?? throw new \InvalidArgumentException('Unknown section type.');
                $config = json_decode($values['configuration'], true, 32, \JSON_THROW_ON_ERROR);
                if (!is_array($config)) { throw new \InvalidArgumentException('Configuration must be a JSON object.'); }
                $section ??= new HomeSection($type, $values['title'], $config);
                if ($section->type() !== $type) { throw new \InvalidArgumentException('Section type cannot change after creation.'); }
                $section->update($values['title'], $values['subtitle'], $config);
                if (null === $section->id()) { $section->setSortOrder(count($sections->ordered()) * 10); }
                $manager->persist($section);
                $manager->flush();
                return $this->redirectToRoute('admin_cms_home_index');
            } catch (\InvalidArgumentException|\JsonException $exception) {
                $error = $exception->getMessage();
            }
        }
        return $this->render('admin/cms/home/form.html.twig', ['section' => $section, 'values' => $values, 'types' => HomeSectionType::cases(), 'error' => $error], new Response(status: null === $error ? 200 : 422));
    }

    private function csrf(Request $request, string $key): void
    {
        if (!$this->isCsrfTokenValid($key, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
    }
}
