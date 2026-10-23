<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;

final class InvoiceNotFound extends DomainException
{
    public static function withId(string $invoiceId): self
    {
        return new self(sprintf('Invoice %s was not found.', $invoiceId));
    }
}
