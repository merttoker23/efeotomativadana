<?php

namespace App\Controller\Admin;

use App\Module\Order\OrderState;
use App\Module\Payment\PaymentState;
use App\Repository\Catalog\BrandRepository;
use App\Repository\Catalog\CategoryRepository;
use App\Repository\Catalog\ProductRepository;
use App\Repository\Commerce\CustomerOrderRepository;
use App\Repository\Commerce\PaymentRepository;
use App\Repository\Customer\CustomerUserRepository;
use App\Repository\Integration\B2bSyncRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(
        ProductRepository $products,
        CategoryRepository $categories,
        BrandRepository $brands,
        CustomerUserRepository $customers,
        CustomerOrderRepository $orders,
        PaymentRepository $payments,
        B2bSyncRunRepository $runs,
    ): Response {
        return $this->render('admin/dashboard.html.twig', [
            'summary' => [
                'products' => $products->count([]),
                'categories' => $categories->count([]),
                'brands' => $brands->count([]),
                'customers' => $customers->count([]),
                'waitingOrders' => $orders->count(['state' => OrderState::Placed]),
                'pendingPayments' => $payments->count(['state' => PaymentState::Pending]),
            ],
            'recentOrders' => $orders->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], 5),
            'latestRun' => $runs->findOneBy([], ['id' => 'DESC']),
        ]);
    }
}
