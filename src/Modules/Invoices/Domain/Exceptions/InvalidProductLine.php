<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;
use Modules\Invoices\Domain\Models\InvoiceProductLine;

final class InvalidProductLine extends DomainException
{
    public static function outOfRange(string $field, mixed $value): self
    {
        return new self(sprintf(
            'Product line %s must be an integer between 1 and %d, %s given.',
            $field,
            InvoiceProductLine::MAX_VALUE,
            var_export($value, true),
        ));
    }
}
