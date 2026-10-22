<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;

final class InvalidProductLine extends DomainException
{
    public static function nonPositive(string $field, int $value): self
    {
        return new self(sprintf('Product line %s must be a positive integer, %d given.', $field, $value));
    }

    public static function notAnInteger(string $field, mixed $value): self
    {
        $rendered = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        return new self(sprintf('Product line %s must be a positive integer, got %s.', $field, $rendered));
    }

    public static function tooLarge(string $field, int $value): self
    {
        return new self(sprintf('Product line %s must not exceed 2147483647, %d given.', $field, $value));
    }

    public static function withoutInvoice(): self
    {
        return new self('A product line must belong to an invoice; create lines through Invoice::productLines().');
    }
}
