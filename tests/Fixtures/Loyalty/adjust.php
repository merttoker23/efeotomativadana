<?php

declare(strict_types=1);

use App\Entity\Customer\AdminUser;
use App\Entity\Customer\CustomerUser;
use App\Kernel;
use App\Module\Loyalty\RewardService;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$customer = $em->find(CustomerUser::class, (int) $argv[1]);
$actor = $em->find(AdminUser::class, (int) $argv[2]);
$service = $container->get(RewardService::class);
if (!$customer instanceof CustomerUser || !$actor instanceof AdminUser || !$service instanceof RewardService) {
    throw new RuntimeException('Concurrent reward test identities or service are missing.');
}
// Pin a stale repeatable-read snapshot before both concurrent writers are released.
$em->getConnection()->beginTransaction();
$service->balance($customer);
touch($argv[6]);
$deadline = microtime(true) + 40;
while (!is_file($argv[7])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrent test barrier timed out.');
    }
    usleep(10_000);
}
try {
    $service->adjust($customer, (int) $argv[3], 'Concurrent correction', $actor, $argv[4]);
    $em->getConnection()->commit();
    echo "applied\n";
} catch (DomainException $error) {
    if ($em->getConnection()->isTransactionActive()) {
        $em->getConnection()->rollBack();
    }
    echo "refused\n";
}
$kernel->shutdown();
