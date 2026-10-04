<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Payment\Gateway\PayTR\PaytrConfiguration;
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
        private readonly PaytrConfiguration $configuration,
        private readonly StoreConfiguration $store,
        private readonly PaymentPublicUrlFactory $urls,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln('PayTR mode: '.($this->configuration->testMode() ? 'SANDBOX (PAYTR_TEST_MODE=1)' : 'LIVE (PAYTR_TEST_MODE=0)'));
        $io->writeln('Bildirim URL: '.$this->urls->absolute('storefront_paytr_notification'));
        $errors = [];
        foreach ($this->configuration->missingCredentials() as $variable) {
            $errors[] = 'Eksik yapılandırma: '.$variable;
        }
        if ('paytr' !== $this->store->paymentProvider()) {
            $errors[] = 'Admin mağaza ayarlarında ödeme sağlayıcısını paytr olarak seçin.';
        }
        if ($this->configuration->isConfigured()) {
            try {
                $this->configuration->paymentUrl();
                $this->configuration->refundUrl();
            } catch (\InvalidArgumentException) {
                $errors[] = 'PAYTR_PAYMENT_URL ve PAYTR_REFUND_URL geçerli HTTPS adresleri olmalı.';
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
