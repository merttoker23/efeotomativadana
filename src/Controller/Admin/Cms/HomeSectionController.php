<?php

declare(strict_types=1);

namespace App\Controller\Admin\Cms;

use App\Entity\Cms\HomeSection;
use App\Module\Cms\CmsMediaStorage;
use App\Module\Cms\CmsOptionCatalog;
use App\Module\Cms\CmsSelectOption;
use App\Module\Cms\HomeSectionDraft;
use App\Module\Cms\HomeSectionInput;
use App\Module\Cms\HomeSectionType;
use App\Repository\Cms\HomeSectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/cms/home', name: 'admin_cms_home_')]
#[IsGranted('ROLE_ADMIN')]
final class HomeSectionController extends AbstractController
{
    public function __construct(
        private readonly HomeSectionDraft $drafts,
        private readonly HomeSectionInput $input,
        private readonly CmsOptionCatalog $options,
        private readonly CmsMediaStorage $media,
    ) {}

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

    /**
     * The section editor.
     *
     * There is no configuration parameter in this request. The form is generated from the
     * section type's own declaration of its fields, the submitted values are read back into that
     * same shape, and only then converted into a configuration the domain will accept. A request
     * that carries anything else is not read at all.
     *
     * A `POST` that is not a save — "add a row", "add the products I picked" — re-renders the same
     * form with the new draft and changes nothing, so editing needs no session and no partially
     * persisted state.
     */
    private function form(Request $request, ?HomeSection $section, HomeSectionRepository $sections, EntityManagerInterface $manager): Response
    {
        $error = null;
        $notice = null;
        $fields = $this->normalize($request);

        $type = $section?->type() ?? $this->requestedType($request);
        $stored = $section?->configuration();
        $draft = $this->drafts->fromConfiguration($type, $stored);
        $title = $section?->title() ?? '';
        $subtitle = $section?->subtitle() ?? '';
        $query = mb_substr(trim($request->query->getString('q')), 0, 120);

        if ($request->isMethod('POST')) {
            $this->csrf($request, 'cms_home_form');
            $title = mb_substr(trim($fields->getString('title')), 0, 255);
            $subtitle = mb_substr(trim($fields->getString('subtitle')), 0, 500);
            $query = mb_substr(trim($fields->getString('option_query')), 0, 120);
            $draft = $this->drafts->fromRequest($type, $fields->all(), $draft);
            $action = $fields->getString('_action') ?: 'save';

            if ('save' !== $action) {
                $notice = $this->notice($action);
            } else {
                try {
                    $configuration = $this->input->configuration($type, $draft, $request->files);
                    // A new section takes its type from the request; an existing one keeps the type
                    // it was created with, because its stored configuration belongs to that type.
                    $section ??= new HomeSection($type, $title, $configuration);
                    $section->update($title, $subtitle, $configuration);
                    if (null === $section->id()) { $section->setSortOrder(\count($sections->ordered()) * 10); }
                    $manager->persist($section);
                    $manager->flush();
                    $this->addFlash('success', 'Bölüm kaydedildi.');

                    return $this->redirectToRoute('admin_cms_home_index');
                } catch (\InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return $this->render('admin/cms/home/form.html.twig', [
            'section' => $section,
            'type' => $type,
            'types' => HomeSectionType::cases(),
            'draft' => $draft,
            'title' => $title,
            'subtitle' => $subtitle,
            'query' => $query,
            'options' => $this->searchableOptions($type, $query),
            'labels' => $this->selectedLabels($type, $draft),
            'library' => $this->media->library(),
            'error' => $error,
            'notice' => $notice,
        ], new Response(status: null === $error ? 200 : 422));
    }

    /**
     * The submitted fields, with a single-button action unpacked into the three values it names.
     *
     * A row's delete button has to say which row it deletes, and a browser submits only the button
     * that was pressed. Carrying all three facts in one `_pick` value is what lets every control
     * stay a plain submit button, so the editor keeps working with scripting turned off; the
     * values are then written back into the same bag the rest of the form is read from, so there
     * is exactly one place that decides what a submission asked for.
     *
     * @return InputBag<string>
     */
    private function normalize(Request $request): InputBag
    {
        $fields = $request->request;
        $pick = $fields->getString('_pick');
        if ('' === $pick) {
            return $fields;
        }

        [$action, $index, $target] = array_pad(explode(':', $pick, 3), 3, '');
        if (1 !== preg_match('/^[a-z-]+$/', $action) || 1 !== preg_match('/^\d*$/', $index)) {
            throw $this->createAccessDeniedException();
        }

        $fields->set('_action', $action);
        $fields->set('_index', $index);
        $fields->set('_target', mb_substr($target, 0, 64));

        return $fields;
    }

    private function requestedType(Request $request): HomeSectionType
    {
        $requested = $request->request->getString('type');
        if ('' === $requested) {
            $requested = $request->query->getString('type');
        }

        return HomeSectionType::tryFrom($requested) ?? HomeSectionType::Features;
    }

    /**
     * @return list<CmsSelectOption>
     */
    private function searchableOptions(HomeSectionType $type, string $query): array
    {
        if (null === $kind = $type->selection()) {
            return [];
        }

        return $this->options->fromRequest($kind, $query);
    }

    /**
     * Labels for every slug the draft already references, so a selected row is a name and not a
     * technical identifier an administrator is asked to recognise.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, CmsSelectOption>
     */
    private function selectedLabels(HomeSectionType $type, array $draft): array
    {
        if (null === $kind = $type->selection()) {
            return [];
        }

        $slugs = \is_array($draft['slugs'] ?? null) ? $draft['slugs'] : [];
        $rows = $type->rowName();
        if (null !== $rows && \is_array($draft[$rows] ?? null)) {
            foreach ($draft[$rows] as $row) {
                if (\is_array($row) && \is_array($row['slugs'] ?? null)) {
                    $slugs = [...$slugs, ...$row['slugs']];
                }
            }
        }

        return $this->options->labelSlugs($kind, array_values(array_unique(array_filter($slugs, \is_string(...)))));
    }

    private function notice(string $action): ?string
    {
        return match ($action) {
            HomeSectionDraft::ADD_ROW => 'Yeni satır eklendi.',
            HomeSectionDraft::REMOVE_ROW => 'Satır silindi.',
            HomeSectionDraft::MOVE_ROW_UP, HomeSectionDraft::MOVE_ROW_DOWN => 'Sıralama güncellendi.',
            HomeSectionDraft::ADD_OPTION => 'Seçimler listeye eklendi.',
            HomeSectionDraft::REMOVE_OPTION, HomeSectionDraft::MOVE_OPTION_UP, HomeSectionDraft::MOVE_OPTION_DOWN => 'Seçim listesi güncellendi.',
            default => null,
        };
    }

    private function csrf(Request $request, string $key): void
    {
        if (!$this->isCsrfTokenValid($key, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
    }
}
