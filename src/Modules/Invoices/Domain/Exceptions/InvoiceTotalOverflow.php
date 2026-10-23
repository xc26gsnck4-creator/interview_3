<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;

final class InvoiceTotalOverflow extends DomainException
{
    /**
     * Only a saved invoice has lines to overflow, so the id is always set.
     */
    public static function forInvoice(string $invoiceId): self
    {
        return new self(sprintf('The total of invoice %s exceeds the maximum representable integer.', $invoiceId));
    }
}
