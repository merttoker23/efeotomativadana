<?php

namespace App\Controller\Admin;

use App\Form\Admin\PaymentSettingsType;
use App\Module\Payment\Gateway\PayTR\StoredPaytrConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class PaymentSettingsController extends AbstractController
{
    #[Route('/admin/settings/payment', name: 'admin_settings_payment', methods: ['GET', 'POST'])]
    public function edit(Request $request, StoredPaytrConfiguration $configuration): Response
    {
        $data = $configuration->formData();
        $form = $this->createForm(PaymentSettingsType::class, $data);
        $form->handleRequest($request);
        // The bound DTO owns the input now; do not retain secrets in the request bag.
        if ($request->request->has('payment_settings')) {
            $submitted = $request->request->all('payment_settings');
            unset($submitted['merchantKey'], $submitted['merchantSalt']);
            $request->request->set('payment_settings', $submitted);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $configuration->save($data);
                $this->addFlash('success', 'Ödeme sağlayıcı ayarları kaydedildi.');

                return $this->redirectToRoute('admin_settings_payment');
            } catch (\InvalidArgumentException) {
                $form->addError(new FormError('Güvenli ödeme yapılandırması kaydedilemedi. Sunucu secret yapılandırmasını kontrol edin.'));
            }
        }
        $data->merchantKey = $data->merchantSalt = '';
        $current = $configuration->current();

        return $this->render('admin/settings/payment.html.twig', [
            'form' => $form,
            'configured' => $current->isConfigured(),
            'testMode' => $current->testMode(),
            'secretStatus' => $configuration->secretStatus(),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
