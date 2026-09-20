<?php

declare(strict_types=1);

namespace App\Module\Checkout;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class LocalManualPaymentOption implements PaymentOptionInterface
{
    public function __construct(#[Autowire('%kernel.environment%')] private string $environment)
    {
    }

    public function key(): string { return 'local_manual'; }
    public function label(): string { return 'Yerel manuel doğrulama (üretim dışı)'; }
    public function available(): bool { return 'prod' !== $this->environment; }
    public function productionReady(): bool { return false; }
}
