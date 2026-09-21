<?php

declare(strict_types=1);

namespace App\Controller\Admin\Commerce;

use App\Entity\Customer\CustomerUser;
use App\Module\Customer\AdminCustomerManager;
use App\Repository\Customer\CustomerAddressRepository;
use App\Repository\Customer\CustomerUserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/customers', name: 'admin_customer_')]
#[IsGranted('ROLE_ADMIN')]
final class CustomerController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CustomerUserRepository $customers): Response
    {
        $active = match ($request->query->getString('status')) { 'active' => true, 'inactive' => false, default => null };
        return $this->render('admin/customers/index.html.twig', ['page' => $customers->adminPage($request->query->getString('q'), $active, $request->query->getInt('page', 1)), 'query' => $request->query->getString('q'), 'status' => $request->query->getString('status')]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(CustomerUser $customer, CustomerAddressRepository $addresses): Response
    {
        return $this->render('admin/customers/show.html.twig', ['customer' => $customer, 'addresses' => $addresses->findForCustomer($customer)]);
    }

    #[Route('/{id}/status', name: 'status', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function status(CustomerUser $customer, Request $request, AdminCustomerManager $manager): Response
    {
        if (!$this->isCsrfTokenValid('customer_status_'.$customer->id(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $active = match ($request->request->getString('status')) {
            'activate' => true,
            'deactivate' => false,
            default => throw new BadRequestHttpException('A valid customer status action is required.'),
        };
        $manager->setActive($customer, $active);
        $this->addFlash('success', 'Customer status updated.');
        return $this->redirectToRoute('admin_customer_show', ['id' => $customer->id()]);
    }
}
