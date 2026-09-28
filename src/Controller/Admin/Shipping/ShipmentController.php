<?php

declare(strict_types=1);

namespace App\Controller\Admin\Shipping;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Shipment;
use App\Entity\Customer\AdminUser;
use App\Form\Admin\ShipmentActionType;
use App\Form\Admin\ShipmentCancelData;
use App\Form\Admin\ShipmentCancelType;
use App\Form\Admin\ShipmentHandOverData;
use App\Form\Admin\ShipmentHandOverType;
use App\Module\Order\OrderState;
use App\Module\Shipping\ShipmentOrchestrator;
use App\Module\Shipping\ShipmentState;
use App\Module\Shipping\ShipmentTrackingView;
use App\Repository\Commerce\CustomerOrderRepository;
use App\Repository\Commerce\ShipmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Shipment visibility and the handful of actions that are safe to take by hand.
 *
 * Three boundaries shape every action here:
 *
 * - **Creating is idempotent.** The create action is a POST, and a second press returns the
 *   shipment that already exists rather than a second parcel. There is no confirmation step for an
 *   action that cannot do harm when repeated.
 * - **Only what a human can decide is offered.** A parcel already with a carrier is never
 *   hand-marked as handed over, a parcel on the road is never recalled, and a delivered parcel is
 *   never reopened. Each of those is refused when reached directly as well, because a hidden
 *   button is not a guard.
 * - **Each action carries its own CSRF token id.** A token minted for "mark in transit" and
 *   replayed as "cancel" is a forged cross-action request, and a shared id would let it through.
 */
#[Route('/admin/gonderiler', name: 'admin_shipment_')]
#[IsGranted('ROLE_ADMIN')]
final class ShipmentController extends AbstractController
{
    private const string ORDER_NUMBER = 'EOA-[0-9]{8}-[0-9A-F]{12}';

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ShipmentRepository $shipments): Response
    {
        $state = ShipmentState::tryFrom($request->query->getString('state'));

        return $this->render('admin/shipments/index.html.twig', [
            'page' => $shipments->adminPage($request->query->getString('q'), $state, $request->query->getInt('page', 1)),
            'query' => $request->query->getString('q'),
            'state_filter' => $state instanceof ShipmentState ? $state->value : '',
            'states' => ShipmentState::cases(),
        ]);
    }

    #[Route('/{orderNumber}', name: 'show', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['GET'])]
    public function show(string $orderNumber, CustomerOrderRepository $orders, ShipmentRepository $shipments): Response
    {
        $order = $this->order($orders, $orderNumber);

        return $this->renderDetail($order, $shipments->findOneForOrder($order), null);
    }

    #[Route('/{orderNumber}/olustur', name: 'create', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['POST'])]
    public function create(string $orderNumber, Request $request, CustomerOrderRepository $orders, ShipmentRepository $shipments, ShipmentOrchestrator $orchestrator): Response
    {
        $order = $this->order($orders, $orderNumber);
        $this->guard($request, 'shipment_create');
        $existing = $shipments->findOneForOrder($order);

        try {
            $orchestrator->createForOrder($orderNumber, $this->adminEmail());
            $this->addFlash('success', null !== $existing
                ? 'Bu sipariş için zaten bir gönderi var; yeni gönderi oluşturulmadı.'
                : 'Gönderi oluşturuldu.');
        } catch (\DomainException|\RuntimeException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToShipment($order);
    }

    #[Route('/{orderNumber}/teslimata-hazir', name: 'hand_over', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['POST'])]
    public function handOver(string $orderNumber, Request $request, CustomerOrderRepository $orders, ShipmentRepository $shipments, ShipmentOrchestrator $orchestrator): Response
    {
        $order = $this->order($orders, $orderNumber);
        $shipment = $this->shipmentFor($shipments, $order);
        $data = new ShipmentHandOverData();
        $form = $this->handOverForm($order, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->guard($request, ShipmentHandOverType::BLOCK_NAME);
            if ($form->isValid()) {
                try {
                    $orchestrator->handOver($shipment, $data->trackingNumber, $this->adminEmail());
                    $this->addFlash('success', 'Gönderi teslimata hazır olarak işaretlendi.');

                    return $this->redirectToShipment($order);
                } catch (\DomainException|\RuntimeException|\InvalidArgumentException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }

            return $this->renderDetail($order, $shipment, null, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
        }

        $this->guard($request, ShipmentHandOverType::BLOCK_NAME);

        try {
            $orchestrator->handOver($shipment, $data->trackingNumber, $this->adminEmail());
            $this->addFlash('success', 'Gönderi teslimata hazır olarak işaretlendi.');
        } catch (\DomainException|\RuntimeException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToShipment($order);
    }

    #[Route('/{orderNumber}/yolda', name: 'in_transit', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['POST'])]
    public function inTransit(string $orderNumber, Request $request, CustomerOrderRepository $orders, ShipmentRepository $shipments, ShipmentOrchestrator $orchestrator): Response
    {
        $order = $this->order($orders, $orderNumber);
        $this->guard($request, 'shipment_transit');

        $this->run(
            fn (): Shipment => $orchestrator->markInTransit($this->shipmentFor($shipments, $order), $this->adminEmail()),
            'Gönderi yolda olarak işaretlendi.',
        );

        return $this->redirectToShipment($order);
    }

    #[Route('/{orderNumber}/teslim', name: 'deliver', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['POST'])]
    public function deliver(string $orderNumber, Request $request, CustomerOrderRepository $orders, ShipmentRepository $shipments, ShipmentOrchestrator $orchestrator): Response
    {
        $order = $this->order($orders, $orderNumber);
        $this->guard($request, 'shipment_deliver');

        $this->run(
            fn (): Shipment => $orchestrator->markDelivered($this->shipmentFor($shipments, $order), $this->adminEmail()),
            'Gönderi teslim edildi olarak işaretlendi.',
        );

        return $this->redirectToShipment($order);
    }

    #[Route('/{orderNumber}/durum', name: 'refresh_status', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['POST'])]
    public function refreshStatus(string $orderNumber, Request $request, CustomerOrderRepository $orders, ShipmentRepository $shipments, ShipmentOrchestrator $orchestrator): Response
    {
        $order = $this->order($orders, $orderNumber);
        $this->guard($request, 'shipment_status');

        $this->run(
            fn (): Shipment => $orchestrator->refreshStatus($this->shipmentFor($shipments, $order), $this->adminEmail()),
            'Gönderi durumu kargo firmasından sorgulandı.',
        );

        return $this->redirectToShipment($order);
    }

    #[Route('/{orderNumber}/etiket', name: 'label', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['POST'])]
    public function label(string $orderNumber, Request $request, CustomerOrderRepository $orders, ShipmentRepository $shipments, ShipmentOrchestrator $orchestrator): Response
    {
        $order = $this->order($orders, $orderNumber);
        $this->guard($request, 'shipment_label');

        try {
            // The carrier's document URL is deliberately neither stored nor rendered. Only the
            // tracking number a carrier issues at print time is kept.
            $label = $orchestrator->requestLabel($this->shipmentFor($shipments, $order), $this->adminEmail());
            $this->addFlash(null === $label ? 'error' : 'success', null === $label
                ? 'Bu kargo firması yazdırılabilir etiket sağlamıyor.'
                : 'Gönderi etiketi yenilendi.');
        } catch (\DomainException|\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToShipment($order);
    }

    #[Route('/{orderNumber}/yeniden-dene', name: 'retry', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['POST'])]
    public function retry(string $orderNumber, Request $request, CustomerOrderRepository $orders, ShipmentRepository $shipments, ShipmentOrchestrator $orchestrator): Response
    {
        $order = $this->order($orders, $orderNumber);
        $this->guard($request, 'shipment_retry');

        $this->run(
            fn (): Shipment => $orchestrator->retryCreation($this->shipmentFor($shipments, $order), $this->adminEmail()),
            'Gönderi kargo firmasına yeniden iletildi.',
        );

        return $this->redirectToShipment($order);
    }

    #[Route('/{orderNumber}/iptal', name: 'cancel', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['GET', 'POST'])]
    public function cancel(string $orderNumber, Request $request, CustomerOrderRepository $orders, ShipmentRepository $shipments, ShipmentOrchestrator $orchestrator): Response
    {
        $order = $this->order($orders, $orderNumber);
        $shipment = $this->shipmentFor($shipments, $order);

        // A parcel already on the road is never recallable by hand. Refused here as well as hidden,
        // because stopping a courier is a return, not a cancellation.
        if (!$shipment->canBeCancelled()) {
            throw $this->createAccessDeniedException('Bu aşamadaki bir gönderi iptal edilemez.');
        }

        $form = $this->createForm(ShipmentCancelType::class, new ShipmentCancelData(), [
            'action' => $this->generateUrl('admin_shipment_cancel', ['orderNumber' => $orderNumber]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->guard($request, 'shipment_cancel');
            $data = $form->getData();
            if ($form->isValid() && $data instanceof ShipmentCancelData) {
                try {
                    $orchestrator->cancel($shipment, (string) $data->reason, $this->adminEmail());
                    $this->addFlash('success', 'Gönderi iptal edildi.');

                    return $this->redirectToShipment($order);
                } catch (\App\Module\Shipping\ShipmentCancellationRefused) {
                    $form->addError(new FormError('Kargo firması gönderiyi geri almayı kabul etmedi.'));
                } catch (\DomainException|\RuntimeException|\InvalidArgumentException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }

            return $this->renderDetail($order, $shipment, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderDetail($order, $shipment, $form);
    }

    private function renderDetail(CustomerOrder $order, ?Shipment $shipment, ?FormInterface $cancelForm, int $status = 200, ?FormInterface $handOverForm = null): Response
    {
        $state = $shipment?->state();
        $handFulfilled = null !== $shipment && $shipment->isHandFulfilledByStore();
        $carrierAccepted = null !== $shipment && !$handFulfilled && null !== $shipment->providerReference();

        return $this->render('admin/shipments/show.html.twig', [
            'order' => $order,
            'shipment' => $shipment,
            'tracking' => null !== $shipment ? ShipmentTrackingView::forShipment($shipment) : null,
            'events' => $shipment?->events() ?? [],
            'createForm' => null === $shipment && OrderState::Confirmed === $order->state()
                ? $this->actionForm('shipment_create', 'Gönderi oluştur', $order, 'admin_shipment_create')
                : null,
            'handOverForm' => null !== $handOverForm ? $handOverForm : (ShipmentState::Pending === $state && $handFulfilled ? $this->handOverForm($order) : null),
            // Never offered, and refused if reached directly: a store word that overrides the
            // carrier's is how a customer is told their parcel arrived while the courier has it.
            'inTransitForm' => ShipmentState::Ready === $state && $handFulfilled
                ? $this->actionForm('shipment_transit', 'Yolda işaretle', $order, 'admin_shipment_in_transit')
                : null,
            'deliverForm' => (ShipmentState::Ready === $state || ShipmentState::InTransit === $state) && $handFulfilled
                ? $this->actionForm('shipment_deliver', 'Teslim edildi işaretle', $order, 'admin_shipment_deliver')
                : null,
            'statusForm' => $carrierAccepted
                ? $this->actionForm('shipment_status', 'Durumu sorgula', $order, 'admin_shipment_refresh_status')
                : null,
            'labelForm' => $carrierAccepted
                ? $this->actionForm('shipment_label', 'Etiketi yenile', $order, 'admin_shipment_label')
                : null,
            'retryForm' => ShipmentState::Failed === $state && !$handFulfilled
                ? $this->actionForm('shipment_retry', 'Kargo firmasına tekrar gönder', $order, 'admin_shipment_retry')
                : null,
            'cancelForm' => $cancelForm ?? ($shipment?->canBeCancelled() ?? false ? $this->cancelForm($order) : null),
        ], new Response(status: $status));
    }

    private function actionForm(string $blockName, string $label, CustomerOrder $order, string $route): FormInterface
    {
        // createNamed, not createForm: the form's name is what the submitted fields are nested
        // under, and it has to equal the CSRF token id so the guard reads back the token it
        // minted. `createForm` would name every one of these `shipment_action`.
        return $this->container->get('form.factory')->createNamed($blockName, ShipmentActionType::class, null, [
            'action_label' => $label,
            'csrf_token_id' => $blockName,
            'action' => $this->generateUrl($route, ['orderNumber' => $order->orderNumber()]),
        ]);
    }

    private function cancelForm(CustomerOrder $order): FormInterface
    {
        return $this->createForm(ShipmentCancelType::class, new ShipmentCancelData(), [
            'action' => $this->generateUrl('admin_shipment_cancel', ['orderNumber' => $order->orderNumber()]),
        ]);
    }

    /**
     * The data object is passed in rather than created here, because the action reads the
     * submitted tracking number from the very instance the form writes into. Two instances means
     * the form validates one value while the action reads another.
     */
    private function handOverForm(CustomerOrder $order, ?ShipmentHandOverData $data = null): FormInterface
    {
        return $this->createForm(ShipmentHandOverType::class, $data ?? new ShipmentHandOverData(), [
            'action' => $this->generateUrl('admin_shipment_hand_over', ['orderNumber' => $order->orderNumber()]),
        ]);
    }

    /**
     * Rejects a request whose CSRF token was not minted for this exact action.
     *
     * Answered as an access denial rather than redisplayed for resubmission: a failed token check
     * means a forged request, not a form an operator mistyped.
     */
    private function guard(Request $request, string $tokenId): void
    {
        $submitted = $request->request->all($tokenId);
        $token = is_string($submitted['_token'] ?? null) ? $submitted['_token'] : '';
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Geçersiz gönderi isteği.');
        }
    }

    /**
     * An action the domain refused is information for the operator, not a crash: pressing
     * "delivered" on a parcel the carrier already returned deserves an explanation, not a 500.
     */
    private function run(callable $action, string $successMessage): void
    {
        try {
            $action();
            $this->addFlash('success', $successMessage);
        } catch (\DomainException|\RuntimeException $exception) {
            // A refused recall and a refused transition both land here, and both are things an
            // operator needs to read rather than a stack trace.
            $this->addFlash('error', $exception->getMessage());
        }
    }

    private function order(CustomerOrderRepository $orders, string $orderNumber): CustomerOrder
    {
        return $orders->findOneBy(['orderNumber' => $orderNumber]) ?? throw $this->createNotFoundException();
    }

    private function shipmentFor(ShipmentRepository $shipments, CustomerOrder $order): Shipment
    {
        return $shipments->findOneForOrder($order)
            ?? throw $this->createNotFoundException('Bu sipariş için henüz gönderi oluşturulmadı.');
    }

    private function adminEmail(): string
    {
        $admin = $this->getUser();
        if (!$admin instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        return $admin->getUserIdentifier();
    }

    private function redirectToShipment(CustomerOrder $order): Response
    {
        return $this->redirectToRoute('admin_shipment_show', ['orderNumber' => $order->orderNumber()]);
    }
}
