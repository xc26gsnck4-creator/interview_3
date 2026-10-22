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
}
