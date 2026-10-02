<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Payment\FakePaymentGateway;
use App\Module\Payment\Gateway\GatewayInitiationInstruction;
use App\Module\Payment\Gateway\GatewayInitiationOutcome;
use App\Module\Payment\Gateway\IncomingPaymentCallback;
use App\Module\Payment\PaymentCallbackHandler;
use App\Module\Payment\PaymentInitiationService;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
try {
    $container = $kernel->getContainer();
    $em = $container->get('doctrine')->getManager();
    $db = $em->getConnection();
    $db->executeStatement('SET SESSION innodb_lock_wait_timeout = 15');
    $attempt = $container->get(PaymentInitiationService::class)->attemptForReturnToken($argv[1]);
    if (null === $attempt || null === $attempt->providerReference()) {
        throw new RuntimeException('The callback fixture requires a persisted referenced attempt.');
    }
    $order = $attempt->payment()->order();
    // Recreate only the external fake gateway's in-memory knowledge in this new process.
    // Real commerce entities and the callback handler are never replaced by doubles.
    $gateway = $container->get(FakePaymentGateway::class);
    $gateway->queueInitiation(GatewayInitiationOutcome::awaitingCallback($attempt->providerReference()));
    $gateway->initiate(new GatewayInitiationInstruction(
        $order->orderNumber(), (string) $attempt->sequence(), $attempt->returnToken(),
        $order->grandTotal(), 'https://localhost/return', 'https://localhost/cancel', $order->customerEmail(), 'tr',
    ));
    file_put_contents($argv[2], (string) $db->fetchOne('SELECT CONNECTION_ID()'));
    $result = $container->get(PaymentCallbackHandler::class)->handle($attempt->returnToken(), new IncomingPaymentCallback(
        http_build_query(['ref' => $attempt->providerReference(), 'outcome' => 'succeeded', 'amount' => 30_000, 'currency' => 'TRY']),
        ['X-Fake-Signature' => 'valid'], [], $attempt->returnToken(),
    ));
    if (!$result->accepted()) {
        throw new RuntimeException('The callback was rejected: '.($result->reason() ?? 'unknown'));
    }
    echo "applied\n";
} catch (Throwable $failure) {
    fwrite(STDERR, $failure::class.': '.$failure->getMessage()."\n");
    exit(1);
} finally {
    $kernel->shutdown();
}
