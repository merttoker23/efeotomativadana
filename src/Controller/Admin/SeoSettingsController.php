<?php

namespace App\Controller\Admin;

use App\Form\Admin\SeoSettingsType;
use App\Module\Settings\StoreConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class SeoSettingsController extends AbstractController
{
    #[Route('/admin/settings/seo', name: 'admin_settings_seo', methods: ['GET', 'POST'])]
    public function edit(Request $request, StoreConfiguration $configuration): Response
    {
        $form = $this->createForm(SeoSettingsType::class, $configuration->currentSeo());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $configuration->saveSeo($form->getData());
            $this->addFlash('success', 'SEO ayarları kaydedildi.');

            return $this->redirectToRoute('admin_settings_seo');
        }

        return $this->render('admin/settings/seo.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
