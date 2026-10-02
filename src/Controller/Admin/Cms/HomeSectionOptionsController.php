<?php

declare(strict_types=1);

namespace App\Controller\Admin\Cms;

use App\Module\Cms\CmsOptionCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The searchable picker behind every product, category and brand control on a section form.
 *
 * It answers with slugs and labels, never with a free-text value the browser can invent: a slug is
 * resolved from a published catalogue record, so what the picker returns is by construction
 * something the domain is willing to store. `kind` is whitelisted against the three kinds the
 * catalogue has, and an unknown one is refused rather than answered with an empty list that would
 * look like "nothing matched".
 */
#[Route('/admin/cms/home/options', name: 'admin_cms_home_options')]
#[IsGranted('ROLE_ADMIN')]
final class HomeSectionOptionsController extends AbstractController
{
    #[Route('', name: '', methods: ['GET'])]
    public function __invoke(Request $request, CmsOptionCatalog $options): JsonResponse
    {
        try {
            $found = $options->fromRequest($request->query->getString('kind'), $request->query->getString('q'));
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Unknown selection kind.'], 400);
        }

        $payload = array_map(
            static fn ($option): array => [
                'slug' => $option->slug,
                'label' => $option->label,
                'hint' => $option->hint,
            ],
            $found,
        );

        return new JsonResponse(['options' => $payload]);
    }
}
