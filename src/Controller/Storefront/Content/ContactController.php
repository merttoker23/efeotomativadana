<?php

namespace App\Controller\Storefront\Content;

use App\Form\Storefront\ContactType;
use App\Module\Catalog\Query\CatalogQuery;
use App\Module\Contact\ContactData;
use App\Module\Contact\ContactMailer;
use App\Module\Seo\SeoMetadataFactory;
use App\Module\Seo\SeoPage;
use App\Module\Settings\StoreConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    #[Route('/iletisim', name: 'storefront_contact', methods: ['GET', 'POST'])]
    #[RateLimit('contact', methods: ['POST'])]
    public function index(Request $request, StoreConfiguration $settings, CatalogQuery $catalog, ContactMailer $mailer, SeoMetadataFactory $seo, LoggerInterface $logger): Response
    {
        $data = new ContactData();
        $form = $this->createForm(ContactType::class, $data);
        $form->handleRequest($request);
        $status = Response::HTTP_OK;
        if ($form->isSubmitted()) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            if ($form->isValid()) {
                try {
                    $mailer->send($data);
                    $this->addFlash('success', 'Mesajınız alındı. En kısa sürede sizinle iletişime geçeceğiz.');

                    return $this->redirectToRoute('storefront_contact', status: Response::HTTP_SEE_OTHER);
                } catch (\Throwable $exception) {
                    $logger->error('Contact message could not be sent.', ['exception' => $exception]);
                    $form->addError(new FormError('Mesajınız gönderilemedi. Lütfen daha sonra tekrar deneyin.'));
                    $status = Response::HTTP_SERVICE_UNAVAILABLE;
                }
            }
        }

        return $this->render('storefront/contact.html.twig', [
            'form' => $form,
            'store' => ['name' => $settings->storeName(), 'locale' => $settings->defaultLocale()],
            'catalog_navigation' => ['categories' => $catalog->categories(8), 'brands' => $catalog->brands(8)],
            'seo' => $seo->for(new SeoPage(route: 'storefront_contact', label: 'İletişim')),
        ], new Response(status: $status));
    }
}
