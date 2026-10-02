<?php

declare(strict_types=1);

namespace App\Controller\Storefront\Account;

use App\Entity\Customer\CustomerUser;
use App\Module\Loyalty\RewardService;
use App\Module\Seo\SeoMetadataFactory;
use App\Module\Seo\SeoPage;
use App\Repository\Loyalty\RewardTransactionRepository;
use App\Shared\StorefrontPageContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CUSTOMER')]
final class RewardController extends AbstractController
{
    #[Route('/hesabim/puanlarim', name: 'customer_account_rewards', methods: ['GET'])]
    public function index(Request $request, RewardService $rewards, RewardTransactionRepository $ledger, StorefrontPageContext $context, SeoMetadataFactory $seo): Response
    {
        $customer = $this->getUser();
        if (!$customer instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }
        return $this->render('storefront/account/rewards.html.twig', $context->withLayout([
            'balance' => $rewards->balance($customer),
            'availableBalance' => $rewards->availableBalance($customer),
            'page' => $ledger->page($customer, $request->query->getInt('page', 1)),
            'seo' => $seo->for(new SeoPage(route: 'customer_account_rewards', label: 'Puanlarım', noIndex: true)),
        ]));
    }
}
