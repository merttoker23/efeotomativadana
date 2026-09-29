<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * The last gate before a log line is written.
 *
 * Attached to every Monolog handler in every environment, including the ones that only
 * flush on an error, because a secret that reaches a buffer is already written to disk the
 * moment that buffer flushes. Centralising redaction here means a new call site is covered
 * by default rather than by remembering to pass its context through a helper.
 *
 * It does not try to be clever about message templates. A message with a placeholder in it
 * is a fixed literal chosen by the caller; the interpolated secrets arrive in the context,
 * which is what this walks. An interpolated literal is still covered — {@see SecretRedactor::text()}
 * is applied to the formatted message too, because a provider string is the one thing in
 * this application that is genuinely attacker-influenced free text.
 */
final class RedactingLogProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly bool $redactMessage = true,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = SecretRedactor::context($record->context);

        $message = $record->message;
        if ($this->redactMessage && '' !== $message) {
            $message = SecretRedactor::text($message);
        }

        return $record->with(message: $message, context: $context);
    }
}
