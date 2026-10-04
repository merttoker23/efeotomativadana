<?php

declare(strict_types=1);

namespace App\Controller\Storefront\Account;

use App\Entity\Customer\CustomerUser;
use App\Module\Order\OrderStateLabel;
use App\Module\Order\OrderSummary;
use App\Module\Returns\ReturnStateLabel;
use App\Module\Returns\ReturnIneligibility;
use App\Module\Returns\ReturnLine;
use App\Module\Returns\ReturnLineNotInOrder;
use App\Module\Returns\ReturnLineDuplicated;
use App\Module\Returns\ReturnPolicy;
use App\Module\Returns\ReturnQuantityExceeded;
use App\Module\Returns\ReturnRequestData;
use App\Module\Returns\ReturnService;
use App\Form\Customer\ReturnRequestType;
use App\Repository\Commerce\CustomerOrderRepository;
use App\Repository\Commerce\OrderThumbnailRepository;
use App\Repository\Commerce\PaymentRepository;
use App\Repository\Commerce\ShipmentRepository;
use App\Shared\StorefrontPageContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A customer's own orders, and the returns they opened against them.
 *
 * Every route here is reached through the order number *and* the customer together. A number is
 * guessable in the sense that a format is guessable, so ownership is part of the query rather than a
 * check afterwards: a foreign order answers 404, exactly as a nonexistent one does, and a customer
 * cannot tell the two apart.
 */
#[IsGranted('ROLE_CUSTOMER')]
final class CustomerOrderController extends AbstractController
{
    private const string ORDER_NUMBER = 'EOA-[0-9]{8}-[0-9A-F]{12}';
    private const string RETURN_NUMBER = 'RET-[0-9]{8}-[0-9A-F]{12}';

    #[Route('/hesabim/siparisler', name: 'customer_account_orders', methods: ['GET'])]
    public function index(Request $request, CustomerOrderRepository $orders, PaymentRepository $payments, ShipmentRepository $shipments, StorefrontPageContext $context, OrderThumbnailRepository $thumbnails): Response
    {
        $page = $orders->customerPage($this->customer(), $request->query->getInt('page', 1));
        $paymentByOrder = $payments->findForOrders($page->items);
        $shipmentByOrder = $shipments->findForOrders($page->items);
        $imagePaths = $thumbnails->forOrders($page->items);
        $summaries = [];
        foreach ($page->items as $order) {
            $summaries[] = OrderSummary::build(
                $order,
                $paymentByOrder[$order->id()] ?? null,
                $shipmentByOrder[$order->id()] ?? null,
                $imagePaths,
            );
        }

        return $this->render('storefront/account/orders.html.twig', $context->withLayout([
            'page' => $page,
            'summaries' => $summaries,
        ]));
    }

    #[Route('/hesabim/siparisler/{orderNumber}', name: 'customer_account_order_show', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['GET'])]
    public function show(string $orderNumber, CustomerOrderRepository $orders, PaymentRepository $payments, ShipmentRepository $shipments, ReturnService $returns, StorefrontPageContext $context, OrderThumbnailRepository $thumbnails, \App\Module\Order\CustomerOrderCancellationService $cancellation): Response
    {
        $order = $this->order($orders, $orderNumber);
        [$eligible, $reason] = $returns->eligibilityOf($order);

        return $this->render('storefront/account/order.html.twig', $context->withLayout([
            'order' => $order,
            'summary' => OrderSummary::build($order, $payments->findOneForOrder($order), $shipments->findOneForOrder($order), $thumbnails->forOrders([$order])),
            'stateLabel' => OrderStateLabel::for($order->state()),
            'shippingAddress' => $order->address(\App\Module\Order\OrderAddressRole::Shipping),
            'billingAddress' => $order->address(\App\Module\Order\OrderAddressRole::Billing),
            'returnable' => $eligible,
            'cancellable' => $cancellation->canCancel($order),
            'returnIneligibility' => $reason,
            'returns' => $returns->pageForCustomer($this->customer(), 1, 5)->items,
        ]));
    }

    #[Route('/hesabim/siparisler/{orderNumber}/iptal', name: 'customer_account_order_cancel', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['POST'])]
    public function cancel(string $orderNumber, Request $request, CustomerOrderRepository $orders, \App\Module\Order\CustomerOrderCancellationService $cancellation): Response
    {
        $order = $this->order($orders, $orderNumber);
        if (!$this->isCsrfTokenValid('customer_order_cancel_'.$order->orderNumber(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Geçersiz iptal isteği.');
        }
        try {
            $cancellation->cancel($this->customer(), $orderNumber);
            $this->addFlash('success', 'Siparişiniz iptal edildi. Tahsil edilmiş ödeme varsa iade işlemi tamamlandı.');
        } catch (\App\Module\Order\OrderNotFound) {
            throw $this->createNotFoundException();
        } catch (\App\Module\Payment\RefundRefused) {
            $this->addFlash('error', 'Ödeme iadesi tamamlanamadığı için siparişiniz iptal edilmedi. Lütfen destek ile iletişime geçin.');
        } catch (\DomainException) {
            $this->addFlash('error', 'Bu sipariş iptal edilemiyor. İade işlemlerini kullanabilir veya destek ile iletişime geçebilirsiniz.');
        }

        return $this->redirectToRoute('customer_account_order_show', ['orderNumber' => $orderNumber]);
    }

    /**
     * Open a return request for one of this customer's orders.
     *
     * The form is built from the order's own lines, with each quantity capped at what is still free
     * after existing requests. A line the store will not accept is therefore visible as a zero
     * ceiling rather than as a refusal the customer discovers after submitting.
     *
     * Limited per address on POST: opening a return is the step that creates staff work, and a
     * scripted run of them is indistinguishable from a customer who is genuinely undecided.
     */
    #[Route('/hesabim/siparisler/{orderNumber}/iade', name: 'customer_account_order_return', requirements: ['orderNumber' => self::ORDER_NUMBER], methods: ['GET', 'POST'])]
    #[RateLimit('return_request', methods: ['POST'])]
    public function return(string $orderNumber, Request $request, CustomerOrderRepository $orders, ReturnService $returns, ReturnPolicy $policy, StorefrontPageContext $context): Response
    {
        $order = $this->order($orders, $orderNumber);
        [$eligible, $reason] = $returns->eligibilityOf($order);

        if (!$eligible) {
            // Explained rather than refused: a customer who is told why is not left guessing, and
            // the order page can point them at support.
            return $this->render('storefront/account/return_blocked.html.twig', $context->withLayout([
                'order' => $order,
                'message' => $reason instanceof ReturnIneligibility ? $reason->customerMessage() : 'Bu sipariş için iade talebi oluşturulamaz.',
            ]));
        }

        $claimed = $returns->claimedQuantities($order);
        $lines = [];
        $maxima = [];
        foreach ($order->items() as $item) {
            $max = $policy->returnableQuantityFor($order, $item, $claimed);
            $lines[] = [
                'line' => new \App\Module\Returns\ReturnLineData(),
                'item' => $item,
                'max' => $max,
            ];
            $maxima[] = $max;
        }

        $data = new ReturnRequestData($lines);
        $form = $this->createForm(ReturnRequestType::class, $data, ['maxima' => $maxima]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = [];
            foreach ($data->filledLines() as $index => $line) {
                // Indexed against the order's own line list, so a posted index can only ever name a
                // line this order has. The aggregate refuses a duplicate; the service re-checks the
                // ceiling under the row lock.
                $submitted[] = new ReturnLine($order->items()[$index], $line->quantity, $line->reason);
            }

            try {
                $returns->request($this->customer(), $order->orderNumber(), $submitted, $data->customerReason);
                $this->addFlash('success', 'İade talebiniz alındı. Sonucu e-posta ile bildireceğiz.');

                return $this->redirectToRoute('customer_account_returns');
            } catch (ReturnQuantityExceeded $exception) {
                $form->addError(new FormError('Seçtiğiniz adet bu üründen iade edilebilecek miktarı aşıyor.'));
            } catch (ReturnLineDuplicated|ReturnLineNotInOrder) {
                $form->addError(new FormError('Aynı ürünü birden fazla kez ekleyemezsiniz.'));
            } catch (\DomainException $exception) {
                // Written for operators; the customer gets a plain explanation instead.
                $form->addError(new FormError('İade talebiniz oluşturulamadı. Lütfen bilgileri kontrol edin.'));
            }
        }

        return $this->render('storefront/account/return_form.html.twig', $context->withLayout([
            'order' => $order,
            'form' => $form,
            'lines' => $lines,
        ]), new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/hesabim/iadeler', name: 'customer_account_returns', methods: ['GET'])]
    public function returns(Request $request, ReturnService $returns, StorefrontPageContext $context): Response
    {
        return $this->render('storefront/account/returns.html.twig', $context->withLayout([
            'page' => $returns->pageForCustomer($this->customer(), $request->query->getInt('page', 1)),
        ]));
    }

    #[Route('/hesabim/iadeler/{returnNumber}', name: 'customer_account_return_show', requirements: ['returnNumber' => self::RETURN_NUMBER], methods: ['GET'])]
    public function returnShow(string $returnNumber, ReturnService $returns, StorefrontPageContext $context): Response
    {
        $return = $returns->findForCustomer($returnNumber, $this->customer());
        if (null === $return) {
            throw $this->createNotFoundException();
        }

        return $this->render('storefront/account/return.html.twig', $context->withLayout([
            'return' => $return,
            'stateLabel' => ReturnStateLabel::for($return->state()),
            'nextStep' => ReturnStateLabel::nextStepFor($return),
        ]));
    }

    /**
     * The customer takes back a request the store has not finished with.
     *
     * POST-only and CSRF-protected: a forwarded link must not be able to cancel somebody's return.
     * The domain refuses once the goods have arrived, so the button is hidden *and* the change is
     * guarded — a hidden button is not a guard.
     */
    #[Route('/hesabim/iadeler/{returnNumber}/geri-al', name: 'customer_account_return_withdraw', requirements: ['returnNumber' => self::RETURN_NUMBER], methods: ['POST'])]
    public function withdraw(string $returnNumber, Request $request, ReturnService $returns): Response
    {
        $return = $returns->findForCustomer($returnNumber, $this->customer());
        if (null === $return) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('customer_return_withdraw_'.$return->returnNumber(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Geçersiz iade isteği.');
        }

        try {
            $returns->withdraw($return);
            $this->addFlash('success', 'İade talebiniz geri alındı.');
        } catch (\DomainException) {
            $this->addFlash('error', 'Bu talep artık geri alınamaz.');
        }

        return $this->redirectToRoute('customer_account_returns');
    }

    private function order(CustomerOrderRepository $orders, string $orderNumber): \App\Entity\Commerce\CustomerOrder
    {
        return $orders->findOneByNumberForCustomer($orderNumber, $this->customer())
            ?? throw $this->createNotFoundException();
    }

    private function customer(): CustomerUser
    {
        $customer = $this->getUser();
        if (!$customer instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }

        return $customer;
    }
}
