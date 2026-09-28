<?php

declare(strict_types=1);

namespace App\Module\Notification;

/**
 * One thing that happened, worth telling the customer about exactly once.
 *
 * The `subjectReference` is whatever the event is *about* — an order number, a return number — and
 * together with the type it forms the dedup key. Two decisions live here rather than in the callers:
 *
 * - the recipient is validated as an address at construction, so a bad one fails before anything is
 *   written and the caller learns about it at the call site instead of from a log line later;
 * - the payload is a flat map of scalars, so a template can only ever interpolate a value, never
 *   call something. A template that could reach an object would be a template that could execute
 *   whatever the object does.
 */
final readonly class NotificationEvent
{
    /** @var array<string, scalar> */
    public array $payload;

    public string $subjectReference;

    public string $recipient;

    /**
     * @param array<mixed> $payload reduced to scalars by {@see assertScalarPayload()}, or refused
     */
    public function __construct(
        public NotificationType $type,
        string $subjectReference,
        string $recipient,
        array $payload = [],
    ) {
        $this->subjectReference = trim($subjectReference);
        if ('' === $this->subjectReference || mb_strlen($this->subjectReference) > 120) {
            throw new \InvalidArgumentException('A notification must name what it is about.');
        }
        $this->recipient = mb_strtolower(trim($recipient));
        if (1 !== preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $this->recipient)) {
            throw new \InvalidArgumentException('A notification recipient must be an email address.');
        }
        $this->payload = self::assertScalarPayload($payload);
    }

    /**
     * Every value reduced to a scalar, or the event is refused.
     *
     * The point is not type safety for its own sake: a template receives this array and can only
     * ever print it. An object in here would be something a template could *call*, which is how a
     * notification template turns into a place that executes whatever an object does.
     *
     * @param array<mixed> $payload
     *
     * @return array<string, scalar>
     */
    private static function assertScalarPayload(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) || is_object($value)) {
                throw new \InvalidArgumentException('A notification payload may only carry scalars.');
            }
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /**
     * The key that makes this message sendable once.
     *
     * Derived, not passed in, so two code paths that mean the same thing produce the same key by
     * construction rather than by remembering to pass the same value.
     */
    public function dedupKey(): string
    {
        return $this->type->value.':'.$this->subjectReference;
    }
}
