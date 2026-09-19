<?php
namespace App\Controller\Storefront\Account;
use App\Entity\Customer\CustomerAddress;
use App\Entity\Customer\CustomerUser;
use App\Form\Customer\AddressType;
use App\Module\Customer\AddressData;
use App\Repository\Customer\CustomerAddressRepository;
use App\Shared\StorefrontPageContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CUSTOMER')]
final class AddressController extends AbstractController
{
    #[Route('/hesabim/adresler', name: 'customer_account_addresses', methods: ['GET'])]
    public function index(CustomerAddressRepository $addresses, StorefrontPageContext $context): Response
    {
        return $this->render('storefront/account/addresses.html.twig', $context->withLayout(['addresses' => $addresses->findForCustomer($this->customer())]));
    }

    #[Route('/hesabim/adresler/yeni', name: 'customer_account_address_new', methods: ['GET', 'POST'])]
    public function create(Request $request, CustomerAddressRepository $addresses, EntityManagerInterface $em, StorefrontPageContext $context): Response
    {
        $address = new CustomerAddress($this->customer());
        return $this->form($request, $address, new AddressData(), $addresses, $em, $context);
    }

    #[Route('/hesabim/adresler/{id}/duzenle', name: 'customer_account_address_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(CustomerAddress $address, Request $request, CustomerAddressRepository $addresses, EntityManagerInterface $em, StorefrontPageContext $context): Response
    {
        $this->assertOwner($address);
        return $this->form($request, $address, AddressData::fromAddress($address), $addresses, $em, $context);
    }

    #[Route('/hesabim/adresler/{id}/sil', name: 'customer_account_address_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(CustomerAddress $address, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertOwner($address);
        if (!$this->isCsrfTokenValid('delete_address_'.$address->id(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $em->remove($address);
        $em->flush();
        return $this->redirectToRoute('customer_account_addresses');
    }

    private function form(Request $request, CustomerAddress $address, AddressData $data, CustomerAddressRepository $addresses, EntityManagerInterface $em, StorefrontPageContext $context): Response
    {
        $form = $this->createForm(AddressType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $address->update($data->label, $data->recipientName, $data->phone, $data->addressLine1, $data->addressLine2, $data->district, $data->city, $data->postalCode, $data->defaultAddress);
            if ($data->defaultAddress) { $addresses->clearDefaultFor($this->customer(), $address); }
            $em->persist($address);
            $em->flush();
            return $this->redirectToRoute('customer_account_addresses');
        }
        return $this->render('storefront/account/form.html.twig', $context->withLayout(['heading' => null === $address->id() ? 'Yeni adres' : 'Adresi düzenle', 'form' => $form]), new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    private function assertOwner(CustomerAddress $address): void
    {
        if ($address->customer()->id() !== $this->customer()->id()) { throw $this->createNotFoundException(); }
    }
    private function customer(): CustomerUser
    {
        $customer = $this->getUser();
        if (!$customer instanceof CustomerUser) { throw $this->createAccessDeniedException(); }
        return $customer;
    }
}
