<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Payment\Gateway\PayTR\PaytrConfigurationSource;
use App\Module\Payment\PaymentPublicUrlFactory;
use App\Module\Settings\StoreConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:paytr:check', description: 'Check PayTR setup without contacting the provider or exposing credentials.')]
final class PaytrCheckCommand extends Command
{
    public function __construct(
        private readonly PaytrConfigurationSource $configurationSource,
        private readonly StoreConfiguration $store,
        private readonly PaymentPublicUrlFactory $urls,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configuration = $this->configurationSource->current();
        $io->writeln('PayTR mode: '.($configuration->testMode() ? 'SANDBOX (Test)' : 'LIVE (Canlı)'));
        $io->writeln('Bildirim URL: '.$this->urls->absolute('storefront_paytr_notification'));
        $errors = [];
        foreach ($configuration->missingCredentials() as $variable) {
            $errors[] = 'Eksik yapılandırma: '.$variable;
        }
        if ('paytr' !== $this->store->paymentProvider()) {
            $errors[] = 'Admin > Ödeme Sağlayıcı ekranında PayTR seçin.';
        }
        if ($configuration->isConfigured()) {
            try {
                $configuration->paymentUrl();
                $configuration->refundUrl();
            } catch (\InvalidArgumentException) {
                $errors[] = 'PayTR uygulama endpoint yapılandırması geçersiz.';
            }
        }
        if ([] !== $errors) {
            $io->error($errors);

            return self::FAILURE;
        }
        $io->success('Yerel yapılandırma hazır. PayTR panelinde Direct API yetkisini ve Bildirim URL kaydını doğrulayın; ardından test kartıyla ödeme yapın. Bu kontrol gerçek ödeme testi değildir.');

        return self::SUCCESS;
    }
}
