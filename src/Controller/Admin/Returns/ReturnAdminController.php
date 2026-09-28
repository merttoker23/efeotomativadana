<?php

declare(strict_types=1);

namespace App\Controller\Admin\Returns;

use App\Entity\Commerce\ReturnRequest;
use App\Entity\Customer\AdminUser;
use App\Form\Admin\ReturnActionType;
use App\Form\Admin\ReturnDecisionData;
use App\Form\Admin\ReturnDecisionType;
use App\Form\Admin\ReturnRefundData;
use App\Form\Admin\ReturnRefundType;
use App\Module\Returns\ReturnService;
use App\Module\Returns\ReturnState;
use App\Module\Returns\ReturnStateLabel;
use App\Repository\Commerce\ReturnRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * An operator's view of customer return requests.
 *
 * Three properties shape every action here, and each one exists because of a specific mistake it
 * prevents:
 *
 * - **Each action has its own CSRF token id, and its form is named after it.** A token minted for
 *   "approve" and replayed as "reject" is a forged cross-action request, and a shared id would let
 *   it through — the approval would be recorded under an operator who never wrote it. Naming the
 *   form after the token id is what lets the guard read back the token it minted.
 * - **Every action is POST-only.** There is no confirmation page for an action the customer can
 *   undo, because a GET is a link a crawler follows.
 * - **A refused action is an explanation, not a crash.** Pressing "approve" on a return somebody
 *   else already rejected deserves a sentence, not a 500.
 */
#[Route('/admin/iadeler', name: 'admin_return_')]
#[IsGranted('ROLE_ADMIN')]
final class ReturnAdminController extends AbstractController
{
    private const string RETURN_NUMBER = 'RET-[0-9]{8}-[0-9A-F]{12}';

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ReturnRequestRepository $returns): Response
    {
        $state = ReturnState::tryFrom($request->query->getString('state'));

        return $this->render('admin/returns/index.html.twig', [
            'page' => $returns->adminPage($request->query->getString('q'), $state, $request->query->getInt('page', 1)),
            'query' => $request->query->getString('q'),
            'state_filter' => $state instanceof ReturnState ? $state->value : '',
            'states' => ReturnState::cases(),
        ]);
    }

    #[Route('/{returnNumber}', name: 'show', requirements: ['returnNumber' => self::RETURN_NUMBER], methods: ['GET'])]
    public function show(string $returnNumber, ReturnRequestRepository $returns): Response
    {
        return $this->renderDetail($this->return($returns, $returnNumber));
    }

    #[Route('/{returnNumber}/onayla', name: 'approve', requirements: ['returnNumber' => self::RETURN_NUMBER], methods: ['POST'])]
    public function approve(string $returnNumber, Request $request, ReturnRequestRepository $returns, ReturnService $service): Response
    {
        $return = $this->return($returns, $returnNumber);
        $this->guard($request, 'return_approve');
        $form = $this->decisionForm($return, 'return_approve', 'Onayla', 'Not', false);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if ($data instanceof ReturnDecisionData && $this->apply(
                fn (): ReturnRequest => $service->approve($return, $data->staffNote, $this->adminEmail()),
                'İade talebi onaylandı.',
                $form,
            )) {
                return $this->redirectToReturn($return);
            }
        }

        return $this->renderDetail($return, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
    }

    #[Route('/{returnNumber}/reddet', name: 'reject', requirements: ['returnNumber' => self::RETURN_NUMBER], methods: ['POST'])]
    public function reject(string $returnNumber, Request $request, ReturnRequestRepository $returns, ReturnService $service): Response
    {
        $return = $this->return($returns, $returnNumber);
        $this->guard($request, 'return_reject');
        $form = $this->decisionForm($return, 'return_reject', 'Reddet', 'Red sebebi (müşteriye gösterilir)', true);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if ($data instanceof ReturnDecisionData && $this->apply(
                fn (): ReturnRequest => $service->reject($return, $data->staffNote, $this->adminEmail()),
                'İade talebi reddedildi.',
                $form,
            )) {
                return $this->redirectToReturn($return);
            }
        }

        return $this->renderDetail($return, Response::HTTP_UNPROCESSABLE_ENTITY, $form);
    }

    #[Route('/{returnNumber}/urunler-geldi', name: 'received', requirements: ['returnNumber' => self::RETURN_NUMBER], methods: ['POST'])]
    public function received(string $returnNumber, Request $request, ReturnRequestRepository $returns, ReturnService $service): Response
    {
        $return = $this->return($returns, $returnNumber);
        $this->guard($request, 'return_received');

        $this->apply(
            fn (): ReturnRequest => $service->markReceived($return, $this->adminEmail()),
            'Ürünlerin ulaştığı işaretlendi.',
        );

        return $this->redirectToReturn($return);
    }

    #[Route('/{returnNumber}/iade-tamamla', name: 'refund', requirements: ['returnNumber' => self::RETURN_NUMBER], methods: ['POST'])]
    public function refund(string $returnNumber, Request $request, ReturnRequestRepository $returns, ReturnService $service): Response
    {
        $return = $this->return($returns, $returnNumber);
        $this->guard($request, 'return_refund');
        $form = $this->refundForm($return);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if ($data instanceof ReturnRefundData && $this->apply(
                fn (): ReturnRequest => $service->recordRefund($return, (int) $data->amountMinor, strtoupper(trim($data->currency)), trim($data->refundReference), $this->adminEmail()),
                'İade kaydı tamamlandı.',
                $form,
            )) {
                return $this->redirectToReturn($return);
            }
        }

        return $this->renderDetail($return, Response::HTTP_UNPROCESSABLE_ENTITY, null, $form);
    }

    private function renderDetail(ReturnRequest $return, int $status = 200, ?FormInterface $decisionForm = null, ?FormInterface $refundForm = null): Response
    {
        $state = $return->state();
        $awaitingDecision = ReturnState::Requested === $state;

        return $this->render('admin/returns/show.html.twig', [
            'return' => $return,
            'state_label' => ReturnStateLabel::for($state),
            'next_step' => ReturnStateLabel::nextStepFor($return),
            'events' => $return->events(),
            // Each action appears only in the state it is legal from, and each carries its own CSRF
            // token id. A hidden button is not a guard, so the service refuses the same changes.
            'approveForm' => $awaitingDecision ? ($decisionForm ?? $this->decisionForm($return, 'return_approve', 'Onayla', 'Not', false)) : null,
            'rejectForm' => $awaitingDecision ? ($this->isSubmittedDecision($decisionForm) ? $decisionForm : $this->decisionForm($return, 'return_reject', 'Reddet', 'Red sebebi (müşteriye gösterilir)', true)) : null,
            'receivedForm' => ReturnState::Approved === $state ? $this->actionForm('Ürünler geldi', $return, 'return_received', 'admin_return_received') : null,
            'refundForm' => ReturnState::Received === $state ? ($refundForm ?? $this->refundForm($return)) : null,
        ], new Response(status: $status));
    }

    private function decisionForm(ReturnRequest $return, string $tokenId, string $submitLabel, string $noteLabel, bool $noteRequired): FormInterface
    {
        return $this->container->get('form.factory')->createNamed($tokenId, ReturnDecisionType::class, new ReturnDecisionData(), [
            'action' => $this->generateUrl('admin_return_'.('return_approve' === $tokenId ? 'approve' : 'reject'), ['returnNumber' => $return->returnNumber()]),
            'csrf_token_id' => $tokenId,
            'submit_label' => $submitLabel,
            'note_label' => $noteLabel,
            'note_required' => $noteRequired,
            'validation_groups' => $noteRequired ? ['Default', 'reject'] : ['Default'],
        ]);
    }

    private function refundForm(ReturnRequest $return): FormInterface
    {
        return $this->container->get('form.factory')->createNamed('return_refund', ReturnRefundType::class, new ReturnRefundData(), [
            'action' => $this->generateUrl('admin_return_refund', ['returnNumber' => $return->returnNumber()]),
            'csrf_token_id' => 'return_refund',
        ]);
    }

    /**
     * A single-button form whose name equals its CSRF token id.
     *
     * `createNamed`, not `createForm`: the form's name is what the submitted fields are nested
     * under and the guard has to read back the token it minted.
     */
    private function actionForm(string $label, ReturnRequest $return, string $tokenId, string $route): FormInterface
    {
        return $this->container->get('form.factory')->createNamed($tokenId, ReturnActionType::class, null, [
            'action_label' => $label,
            'csrf_token_id' => $tokenId,
            'action' => $this->generateUrl($route, ['returnNumber' => $return->returnNumber()]),
        ]);
    }

    private function isSubmittedDecision(?FormInterface $form): bool
    {
        return null !== $form && 'return_reject' === $form->getName() && $form->isSubmitted();
    }

    /**
     * Run one service call, turning a domain refusal into a form error or a flash.
     *
     * @param callable(): ReturnRequest $change
     */
    private function apply(callable $change, string $success, ?FormInterface $form = null): bool
    {
        try {
            $change();
            $this->addFlash('success', $success);

            return true;
        } catch (\DomainException|\RuntimeException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
            if (null !== $form) {
                $form->addError(new \Symfony\Component\Form\FormError('İşlem tamamlanamadı.'));
            }
        }

        return false;
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
            throw $this->createAccessDeniedException('Geçersiz iade isteği.');
        }
    }

    private function return(ReturnRequestRepository $returns, string $returnNumber): ReturnRequest
    {
        return $returns->findOneBy(['returnNumber' => $returnNumber]) ?? throw $this->createNotFoundException();
    }

    private function adminEmail(): string
    {
        $admin = $this->getUser();
        if (!$admin instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        return $admin->getUserIdentifier();
    }

    private function redirectToReturn(ReturnRequest $return): Response
    {
        return $this->redirectToRoute('admin_return_show', ['returnNumber' => $return->returnNumber()]);
    }
}
