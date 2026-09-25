<?php

namespace App\Controller\Admin\Integration;

use App\Entity\Integration\B2bSyncRun;
use App\Module\Integration\B2b\B2bDispatchResult;
use App\Module\Integration\B2b\B2bDispatchStatus;
use App\Module\Integration\B2b\B2bProviderRegistry;
use App\Module\Integration\B2b\B2bProviderStatus;
use App\Module\Integration\B2b\B2bSyncMode;
use App\Module\Integration\B2b\B2bSyncServiceInterface;
use App\Module\Settings\StoreConfiguration;
use App\Repository\Integration\B2bSyncRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class B2bController extends AbstractController
{
    #[Route('/admin/integration/b2b', name: 'admin_integration_b2b', methods: ['GET'])]
    public function index(
        Request $request,
        StoreConfiguration $configuration,
        B2bProviderRegistry $providers,
        B2bSyncRunRepository $runs,
    ): Response {
        $enabled = $configuration->isB2bEnabled();
        $providerKey = $configuration->b2bProvider();
        $providerStatus = $this->providerStatus($providerKey, $providers);
        $activeRun = $providerKey ? $runs->findActive($providerKey) : null;
        $runPage = $runs->adminPage($request->query->getInt('page', 1), 10);
        $selectedRun = $this->selectedRun($request, $runs, $activeRun, $runPage->items);

        return $this->render('admin/integration/b2b.html.twig', [
            'enabled' => $enabled,
            'providerKey' => $providerKey,
            'providerStatus' => $providerStatus,
            'activeRun' => $activeRun,
            'latestFull' => $providerKey ? $runs->latestSuccessful($providerKey, B2bSyncMode::Full) : null,
            'latestDaily' => $providerKey ? $runs->latestSuccessful($providerKey, B2bSyncMode::Daily) : null,
            'runPage' => $runPage,
            'selectedRun' => $selectedRun,
            'actionsEnabled' => $enabled && true === $providerStatus?->configured() && null === $activeRun,
        ]);
    }

    #[Route('/admin/integration/b2b/full', name: 'admin_integration_b2b_full', methods: ['POST'])]
    public function full(Request $request, B2bSyncServiceInterface $sync): Response
    {
        return $this->requestSync($request, $sync, B2bSyncMode::Full);
    }

    #[Route('/admin/integration/b2b/daily', name: 'admin_integration_b2b_daily', methods: ['POST'])]
    public function daily(Request $request, B2bSyncServiceInterface $sync): Response
    {
        return $this->requestSync($request, $sync, B2bSyncMode::Daily);
    }

    private function requestSync(Request $request, B2bSyncServiceInterface $sync, B2bSyncMode $mode): Response
    {
        if (!$this->isCsrfTokenValid('b2b_sync', $request->request->getString('_token'))) {
            throw new UnprocessableEntityHttpException('The B2B synchronization CSRF token is invalid.');
        }

        $this->addFlash(...$this->flashMessage($sync->request($mode)));

        return $this->redirectToRoute('admin_integration_b2b');
    }

    /** @return array{string, string} */
    private function flashMessage(B2bDispatchResult $result): array
    {
        return match ($result->status()) {
            B2bDispatchStatus::Disabled => ['success', 'B2B integration is disabled; no synchronization was queued.'],
            B2bDispatchStatus::Queued => ['success', sprintf('B2B %s run %d was queued.', $result->mode()->value, $result->runId() ?? 0)],
            B2bDispatchStatus::Coalesced => ['success', sprintf('B2B run %d is already active; no duplicate job was queued.', $result->runId() ?? 0)],
            B2bDispatchStatus::Rejected => ['error', $result->reason() ?? 'B2B synchronization was rejected.'],
        };
    }

    private function providerStatus(?string $providerKey, B2bProviderRegistry $providers): ?B2bProviderStatus
    {
        if (null === $providerKey || !$providers->supports($providerKey)) {
            return null;
        }

        return $providers->get($providerKey)->status();
    }

    /**
     * @param list<B2bSyncRun> $historyItems
     */
    private function selectedRun(Request $request, B2bSyncRunRepository $runs, ?B2bSyncRun $activeRun, array $historyItems): ?B2bSyncRun
    {
        $runId = $request->query->getInt('run_id');
        if ($runId > 0) {
            $run = $runs->find($runId);

            return $run instanceof B2bSyncRun ? $run : null;
        }
        if (null !== $activeRun) {
            return $activeRun;
        }

        return $historyItems[0] ?? null;
    }
}
