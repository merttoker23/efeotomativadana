<?php

declare(strict_types=1);

namespace App\Controller\Admin\Commerce;

use App\Entity\Commerce\CustomerOrder;
use App\Entity\Commerce\Payment;
use App\Entity\Customer\AdminUser;
use App\Form\Admin\PaymentCancelData;
use App\Form\Admin\PaymentCancelType;
use App\Form\Admin\PaymentRetryType;
use App\Module\Order\OrderRepositoryInterface;
use App\Module\Payment\PaymentAdminManager;
use App\Module\Payment\PaymentState;
use App\Repository\Commerce\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only payment visibility plus the two actions that are semantically safe by hand:
 * retrying a payment that was never captured, and cancelling one that was never captured.
 *
 * There is deliberately no admin "mark as paid" and no admin capture action. Captured money is
 * only ever moved by the provider, and a mismatch between what the provider says and what the
 * store recorded is a reconciliation task, not something an operator should be able to
 * overwrite.
 */
#[Route('/admin/odemeler', name: 'admin_payment_')]
#[IsGranted('ROLE_ADMIN')]
final class PaymentController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, PaymentRepository $payments): Response
    {
        $state = PaymentState::tryFrom($request->query->getString('state'));

        return $this->render('admin/payments/index.html.twig', [
            'page' => $payments->adminPage($request->query->getString('q'), $state, $request->query->getInt('page', 1)),
            'query' => $request->query->getString('q'),
            'state_filter' => $state instanceof PaymentState ? $state->value : '',
            'states' => PaymentState::cases(),
        ]);
    }

    #[Route('/{orderNumber}', name: 'show', requirements: ['orderNumber' => 'EOA-[0-9]{8}-[0-9A-F]{12}'], methods: ['GET'])]
    public function show(string $orderNumber, PaymentRepository $payments, EntityManagerInterface $entityManager): Response
    {
        $payment = $payments->findOneForOrderNumber($orderNumber) ?? throw $this->createNotFoundException();

        return $this->renderDetail($payment, $this->cancelForm($payment));
    }

    /**
     * A captured payment is never re-driven or cancelled from the admin area. The money has to
     * come back through a refund, which is a different, separately audited action.
     */
    private function isAdminActionable(Payment $payment): bool
    {
        return $payment->state()->canBeRetried();
    }

    #[Route('/{orderNumber}/yeniden-dene', name: 'retry', requirements: ['orderNumber' => 'EOA-[0-9]{8}-[0-9A-F]{12}'], methods: ['POST'])]
    public function retry(string $orderNumber, Request $request, PaymentRepository $payments, PaymentAdminManager $manager): Response
    {
        $payment = $payments->findOneForOrderNumber($orderNumber) ?? throw $this->createNotFoundException();
        // The form is submitted and validated, so the CSRF token is checked before anything
        // else happens: a forged restart must not reach the payment aggregate.
        $form = $this->retryForm($payment);
        $form->handleRequest($request);
        if ($form->isSubmitted() && !$form->isValid()) {
            throw $this->createAccessDeniedException('Geçersiz ödeme isteği.');
        }
        if (!$payment->state()->canBeRetried()) {
            $this->addFlash('error', 'Bu ödeme yeniden başlatılamaz.');

            return $this->redirectToRoute('admin_payment_show', ['orderNumber' => $orderNumber]);
        }

        try {
            $manager->retry($payment->order(), $this->adminEmail());
            $this->addFlash('success', 'Ödeme yeniden başlatıldı.');
        } catch (\DomainException|\RuntimeException|\InvalidArgumentException $exception) {
            $this->addFlash('error', 'Ödeme yeniden başlatılamadı.');
        }

        return $this->redirectToRoute('admin_payment_show', ['orderNumber' => $orderNumber]);
    }

    #[Route('/{orderNumber}/iptal', name: 'cancel', requirements: ['orderNumber' => 'EOA-[0-9]{8}-[0-9A-F]{12}'], methods: ['GET', 'POST'])]
    public function cancel(string $orderNumber, Request $request, PaymentRepository $payments, PaymentAdminManager $manager): Response
    {
        $payment = $payments->findOneForOrderNumber($orderNumber) ?? throw $this->createNotFoundException();
        $form = $this->createForm(PaymentCancelType::class, new PaymentCancelData(), [
            'action' => $this->generateUrl('admin_payment_cancel', ['orderNumber' => $orderNumber]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // A failed CSRF check means a forged request, not a form the operator mistyped, so
            // it is answered as an access denial instead of being redisplayed for resubmission.
            $submitted = $request->request->all('payment_cancel');
            $token = is_string($submitted['_token'] ?? null) ? $submitted['_token'] : '';
            if (!$this->isCsrfTokenValid('payment_cancel', $token)) {
                throw $this->createAccessDeniedException('Geçersiz ödeme isteği.');
            }
            if ($form->isValid()) {
                try {
                    $manager->cancel($payment->order(), (string) $form->getData()?->reason, $this->adminEmail());
                    $this->addFlash('success', 'Tahsil edilmemiş ödeme iptal edildi.');

                    return $this->redirectToRoute('admin_payment_show', ['orderNumber' => $orderNumber]);
                } catch (\DomainException|\RuntimeException|\InvalidArgumentException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        // A captured payment is never cancellable by hand; the action is not merely hidden,
        // it is refused when reached directly, because the money has to come back as a refund.
        if ($payment->state()->hasCapturedFunds()) {
            throw $this->createAccessDeniedException('Tahsil edilmiş bir ödeme iptal edilemez; iade işlemi kullanılmalıdır.');
        }

        return $this->renderDetail($payment, $form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function renderDetail(Payment $payment, \Symfony\Component\Form\FormInterface $form, int $status = 200): Response
    {
        return $this->render('admin/payments/show.html.twig', [
            'payment' => $payment,
            'order' => $payment->order(),
            'attempts' => $payment->attempts(),
            'refunds' => $payment->refunds(),
            'events' => $payment->events(),
            'retryForm' => $this->isAdminActionable($payment) ? $this->retryForm($payment) : null,
            'cancelForm' => $form,
            'isActionable' => $this->isAdminActionable($payment),
        ], new Response(status: $status));
    }

    private function retryForm(Payment $payment): FormInterface
    {
        return $this->createForm(PaymentRetryType::class, null, [
            'action' => $this->generateUrl('admin_payment_retry', ['orderNumber' => $payment->order()->orderNumber()]),
        ]);
    }

    private function cancelForm(Payment $payment): FormInterface
    {
        return $this->createForm(PaymentCancelType::class, new PaymentCancelData(), [
            'action' => $this->generateUrl('admin_payment_cancel', ['orderNumber' => $payment->order()->orderNumber()]),
        ]);
    }

    private function adminEmail(): string
    {
        $admin = $this->getUser();
        if (!$admin instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        return $admin->getUserIdentifier();
    }
}
