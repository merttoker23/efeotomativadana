<?php

declare(strict_types=1);

namespace App\Controller\Admin\Loyalty;

use App\Entity\Customer\AdminUser;
use App\Form\Admin\RewardAdjustmentType;
use App\Module\Loyalty\RewardAdjustmentData;
use App\Module\Loyalty\RewardService;
use App\Repository\Customer\CustomerUserRepository;
use App\Repository\Loyalty\RewardTransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class RewardController extends AbstractController
{
    #[Route('/admin/puanlar', name: 'admin_reward_index', methods: ['GET'])]
    public function index(Request $request, RewardTransactionRepository $ledger): Response
    {
        return $this->render('admin/loyalty/index.html.twig', ['page' => $ledger->page(null, $request->query->getInt('page', 1))]);
    }

    #[Route('/admin/puanlar/{customerId}', name: 'admin_reward_customer', requirements: ['customerId' => '\\d+'], methods: ['GET', 'POST'])]
    public function customer(int $customerId, Request $request, CustomerUserRepository $customers, RewardService $rewards, RewardTransactionRepository $ledger): Response
    {
        $customer = $customers->find($customerId) ?? throw $this->createNotFoundException();
        $data = new RewardAdjustmentData();
        $form = $this->createForm(RewardAdjustmentType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $actor = $this->getUser();
            if (!$actor instanceof AdminUser) {
                throw $this->createAccessDeniedException();
            }
            try {
                $rewards->adjust($customer, $data->points, $data->reason, $actor, $data->requestKey);
                $this->addFlash('success', 'Reward adjustment recorded.');
                return $this->redirectToRoute('admin_reward_customer', ['customerId' => $customerId]);
            } catch (\DomainException|\InvalidArgumentException $error) {
                $form->addError(new FormError($error->getMessage()));
            }
        }
        return $this->render('admin/loyalty/customer.html.twig', [
            'customer' => $customer, 'balance' => $rewards->balance($customer), 'form' => $form,
            'page' => $ledger->page($customer, $request->query->getInt('page', 1)),
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }
}
