<?php

declare(strict_types=1);

namespace App\Controller\Admin\Commerce;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\OrderStatusChange;
use App\Entity\Customer\AdminUser;
use App\Form\Admin\OrderTransitionType;
use App\Module\Admin\ConcurrentAdminEdit;
use App\Module\Order\AdminOrderManager;
use App\Module\Order\OrderAddressRole;
use App\Module\Order\OrderState;
use App\Module\Order\OrderTransitionData;
use App\Repository\Commerce\CustomerOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/orders', name: 'admin_order_')]
#[IsGranted('ROLE_ADMIN')]
final class OrderController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CustomerOrderRepository $orders): Response
    {
        $state = OrderState::tryFrom($request->query->getString('state'));
        return $this->render('admin/orders/index.html.twig', ['page' => $orders->adminPage($request->query->getString('q'), $state, $request->query->getInt('page', 1)), 'query' => $request->query->getString('q'), 'state' => $state->value ?? '']);
    }

    #[Route('/{orderNumber}', name: 'show', requirements: ['orderNumber' => 'EOA-[0-9]{8}-[0-9A-F]{12}'], methods: ['GET'])]
    public function show(string $orderNumber, CustomerOrderRepository $orders, EntityManagerInterface $entityManager): Response
    {
        $order = $orders->findOneBy(['orderNumber' => $orderNumber]) ?? throw $this->createNotFoundException();
        return $this->renderDetail($order, $entityManager);
    }

    #[Route('/{orderNumber}/status', name: 'status', requirements: ['orderNumber' => 'EOA-[0-9]{8}-[0-9A-F]{12}'], methods: ['POST'])]
    public function status(string $orderNumber, Request $request, CustomerOrderRepository $orders, AdminOrderManager $manager, EntityManagerInterface $entityManager): Response
    {
        $order = $orders->findOneBy(['orderNumber' => $orderNumber]) ?? throw $this->createNotFoundException();
        $data = new OrderTransitionData(); $data->version = (string) $order->version();
        $form = $this->createForm(OrderTransitionType::class, $data, ['allowed_states' => $this->allowedStates($order->state())]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && null !== $data->nextState) {
            $admin = $this->getUser();
            if (!$admin instanceof AdminUser) { throw $this->createAccessDeniedException(); }
            try {
                $manager->transition($orderNumber, $data->nextState, $data->reason, (int) $data->version, $admin->getUserIdentifier());
                $this->addFlash('success', 'Order status updated.');
                return $this->redirectToRoute('admin_order_show', ['orderNumber' => $orderNumber]);
            } catch (ConcurrentAdminEdit $exception) {
                $form->addError(new FormError($exception->getMessage()));
                return $this->renderDetail($order, $entityManager, $form, Response::HTTP_CONFLICT);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }
        return $this->renderDetail($order, $entityManager, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function renderDetail(CustomerOrder $order, EntityManagerInterface $entityManager, ?\Symfony\Component\Form\FormInterface $form = null, int $status = 200): Response
    {
        if (null === $form) {
            $data = new OrderTransitionData(); $data->version = (string) $order->version();
            $form = $this->createForm(OrderTransitionType::class, $data, ['allowed_states' => $this->allowedStates($order->state()), 'action' => $this->generateUrl('admin_order_status', ['orderNumber' => $order->orderNumber()])]);
        }
        return $this->render('admin/orders/show.html.twig', [
            'order' => $order,
            'shippingAddress' => $order->address(OrderAddressRole::Shipping),
            'billingAddress' => $order->address(OrderAddressRole::Billing),
            'history' => $entityManager->getRepository(OrderStatusChange::class)->findBy(['order' => $order], ['changedAt' => 'DESC', 'id' => 'DESC']),
            'transitionForm' => $form,
        ], new Response(status: $status));
    }

    /** @return list<OrderState> */
    private function allowedStates(OrderState $state): array
    {
        return match ($state) { OrderState::Placed => [OrderState::Confirmed, OrderState::Cancelled], OrderState::Confirmed => [OrderState::Completed, OrderState::Cancelled], default => [] };
    }
}
