<?php

namespace App\Controller\Admin;

use App\Form\Admin\StoreSettingsType;
use App\Module\Settings\StoreConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class SettingsController extends AbstractController
{
    #[Route('/admin/settings', name: 'admin_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request, StoreConfiguration $configuration): Response
    {
        $form = $this->createForm(StoreSettingsType::class, $configuration->current());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $configuration->save($form->getData());
            $this->addFlash('success', 'Store settings saved.');

            return $this->redirectToRoute('admin_settings');
        }

        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('admin/settings.html.twig', [
            'form' => $form,
        ], new Response(status: $status));
    }
}
