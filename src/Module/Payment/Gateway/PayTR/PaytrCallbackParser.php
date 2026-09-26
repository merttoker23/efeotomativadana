<?php

declare(strict_types=1);

namespace App\Module\Payment\Gateway\PayTR;

/**
 * Reads a PayTR payment notification out of its raw form-encoded body.
 *
 * The parser is deliberately structural: it establishes that the fields PayTR documents as
 * required are present and well formed, and refuses everything else with null. It never decides
 * whether a notification is authentic — that is the signature's job — so a body that parses is
 * still only a candidate until its hash has been verified.
 */
final class PaytrCallbackParser
{
    private const int MAX_ORDER_REFERENCE_LENGTH = 64;
    private const string MAX_MINOR_AMOUNT_DIGITS = '18';

    /**
     * Provider free text is bounded before it is stored, logged or redacted, so a large or
     * deliberately hostile value cannot be used to push noise into the audit trail.
     */
    private const int MAX_FIELD_LENGTH = 500;

    public function parse(string $rawBody): ?PaytrCallbackReport
    {
        if ('' === trim($rawBody)) {
            return null;
        }

        $fields = [];
        parse_str($rawBody, $fields);

        $orderReference = $this->text($fields, 'merchant_oid');
        $status = $this->text($fields, 'status');
        $totalAmount = $this->text($fields, 'total_amount');
        $hash = $this->text($fields, 'hash');

        if (null === $orderReference || null === $status || null === $totalAmount || null === $hash) {
            return null;
        }
        if (1 !== preg_match('/^[A-Za-z0-9]{1,'.self::MAX_ORDER_REFERENCE_LENGTH.'}$/', $orderReference)) {
            return null;
        }
        if (!in_array(strtolower($status), ['success', 'failed'], true)) {
            return null;
        }
        $totalAmountMinor = $this->minorAmount($totalAmount);
        if (null === $totalAmountMinor) {
            return null;
        }

        return new PaytrCallbackReport(
            $orderReference,
            $status,
            $totalAmount,
            $totalAmountMinor,
            $this->optionalMinorAmount($fields, 'payment_amount'),
            $this->text($fields, 'payment_type'),
            $this->currency($fields),
            $this->text($fields, 'failed_reason_code'),
            $this->text($fields, 'failed_reason_msg'),
            $this->isTestMode($fields),
            $hash,
        );
    }

    /**
     * @param array<mixed> $fields
     */
    private function text(array $fields, string $name): ?string
    {
        $value = $fields[$name] ?? null;
        // parse_str turns `a[b]=1` into an array; a repeated or bracketed field is not the
        // scalar PayTR documents, and flattening it could join values the provider never signed.
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        // A field that is not valid UTF-8 would make the redaction patterns return null and emit
        // a warning, which the error handler turns into an exception. Refusing the notification
        // here keeps a malformed post to a plain "not verified", which the endpoint still answers.
        if (!mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return mb_substr($value, 0, self::MAX_FIELD_LENGTH);
    }

    private function minorAmount(string $value): ?int
    {
        if (1 !== preg_match('/^\d{1,'.self::MAX_MINOR_AMOUNT_DIGITS.'}$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<mixed> $fields
     */
    private function optionalMinorAmount(array $fields, string $name): ?int
    {
        $value = $this->text($fields, $name);

        return null === $value ? null : $this->minorAmount($value);
    }

    /**
     * @param array<mixed> $fields
     */
    private function currency(array $fields): ?string
    {
        $value = $this->text($fields, 'currency');
        if (null === $value) {
            return null;
        }

        try {
            return PaytrAmount::isoCurrency($value);
        } catch (\InvalidArgumentException) {
            // An unrecognised currency is reported as absent rather than failing the whole
            // notification: the signature is what decides authenticity, and the amount guard
            // downstream still refuses a capture it cannot reconcile.
            return null;
        }
    }

    /**
     * @param array<mixed> $fields
     */
    private function isTestMode(array $fields): bool
    {
        return '1' === $this->text($fields, 'test_mode');
    }
}
