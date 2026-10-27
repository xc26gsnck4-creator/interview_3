<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;

final class InvalidProductLine extends DomainException
{
    public static function outOfRange(string $field, mixed $value): self
    {
        return new self(sprintf('Product line %s must be an integer between 1 and 2147483647, %s given.', $field, var_export($value, true)));
    }
}
