<?php

namespace App\Controller\Admin;

use App\Form\Admin\ShippingSettingsType;
use App\Module\Settings\StoreConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ShippingSettingsController extends AbstractController
{
    #[Route('/admin/settings/shipping', name: 'admin_settings_shipping', methods: ['GET', 'POST'])]
    public function edit(Request $request, StoreConfiguration $configuration): Response
    {
        $form = $this->createForm(ShippingSettingsType::class, $configuration->currentShipping());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $configuration->saveShipping($form->getData());
            $this->addFlash('success', 'Kargo ayarları kaydedildi.');

            return $this->redirectToRoute('admin_settings_shipping');
        }

        return $this->render('admin/settings/shipping.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
