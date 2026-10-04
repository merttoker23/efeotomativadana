<?php

namespace App\Module\Settings;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class PaymentSettingsData
{
    #[Assert\Choice(choices: ['paytr', null, ''])]
    public ?string $paymentProvider = null;

    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/\A[0-9]*\z/', message: 'Merchant ID yalnız rakamlardan oluşmalıdır.')]
    public string $merchantId = '';

    #[Assert\Length(max: 512)]
    public string $merchantKey = '';

    #[Assert\Length(max: 512)]
    public string $merchantSalt = '';

    public bool $testMode = true;
    public bool $confirmLiveMode = false;

    #[Assert\Callback]
    public function validateLiveMode(ExecutionContextInterface $context): void
    {
        if (!$this->testMode && !$this->confirmLiveMode) {
            $context->buildViolation('Canlı modda gerçek tahsilat yapılacağını onaylayın.')
                ->atPath('confirmLiveMode')->addViolation();
        }
    }

    public function __debugInfo(): array
    {
        return ['paymentProvider' => $this->paymentProvider, 'testMode' => $this->testMode];
    }
}
