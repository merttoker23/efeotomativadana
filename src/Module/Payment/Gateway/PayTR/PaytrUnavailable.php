<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * PayTR could not be reached, or answered in a way that is worth trying again.
 *
 * Kept apart from a refusal on purpose: a provider that is unwell must not be recorded as a
 * declined card, and a refusal must not be retried against the same order.
 */
final class PaytrUnavailable extends \RuntimeException
{
}
