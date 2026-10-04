<?php

namespace App\Controller\Admin;

use App\Form\Admin\CookieSettingsType;
use App\Module\Settings\StoreConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class CookieSettingsController extends AbstractController
{
    #[Route('/admin/settings/cookies', name: 'admin_settings_cookies', methods: ['GET', 'POST'])]
    public function edit(Request $request, StoreConfiguration $configuration): Response
    {
        $form = $this->createForm(CookieSettingsType::class, $configuration->currentCookies());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $configuration->saveCookies($form->getData());
            $this->addFlash('success', 'Çerez ayarları kaydedildi.');

            return $this->redirectToRoute('admin_settings_cookies');
        }

        return $this->render('admin/settings/cookies.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
