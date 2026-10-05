<?php

namespace App\Controller\Admin;

use App\Form\Admin\StoreSettingsType;
use App\Module\Settings\StoreConfiguration;
use App\Module\Settings\TurkishGeography;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[IsGranted('ROLE_ADMIN')]
final class SettingsController extends AbstractController
{
    #[Route('/admin/settings', name: 'admin_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request, StoreConfiguration $configuration): Response
    {
        $form = $this->createForm(StoreSettingsType::class, $configuration->currentGeneral());
        $form->handleRequest($request);
        $rejected = false;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $configuration->saveGeneral($form->getData());
            } catch (ValidationFailedException $exception) {
                // İletişim alanları, doğrulanmaları gerektiği yerde değil, normalleştirildikleri
                // yerde denetlenir: kontrol edilecek şey iki alan arasındaki ilişki ve bir katalog,
                // ikisi de tek bir alanın biçiminden ibaret değil. Burada reddedilen bir şey yine de
                // bu formun hatasıdır, bu yüzden sunucu hatası değil, ilgili alanın üstünde bir
                // mesaj olarak döner ve girdi kaybolmaz.
                $rejected = true;
                $this->report($form, $exception);
            }

            if (!$rejected) {
                $this->addFlash('success', 'Mağaza ayarları kaydedildi.');

                return $this->redirectToRoute('admin_settings');
            }
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form,
            // İl seçildiğinde ilçe kutusunu daraltan denetimin katalog verisi. Sayfayla birlikte
            // gelir; il değiştikçe sunucuya yeni bir istek atılmaz.
            'store_district_catalog' => TurkishGeography::districtsByProvince(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Doğrulama hataları, kuralın ait olduğu alana yazılır. Bilinmeyen bir alan adı için hata
     * formun geneline konur: mesaj kaybolmaz, ama yanlış alanın altında da görünmez.
     */
    private function report(FormInterface $form, ValidationFailedException $exception): void
    {
        foreach ($exception->getViolations() as $violation) {
            $message = (string) $violation->getMessage();
            if ($form->has($violation->getPropertyPath())) {
                $form->get($violation->getPropertyPath())->addError(new FormError($message));

                continue;
            }
            $form->addError(new FormError($message));
        }
    }
}
