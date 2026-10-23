<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;
use Modules\Invoices\Domain\Models\InvoiceProductLine;

final class InvoiceNotSendable extends DomainException
{
    public static function noProductLines(string $invoiceId): self
    {
        return new self(sprintf('Invoice %s cannot be sent without product lines.', $invoiceId));
    }

    public static function invalidProductLine(string $invoiceId): self
    {
        return new self(sprintf(
            'Invoice %s cannot be sent: every product line needs an integer quantity and unit price between 1 and %d.',
            $invoiceId,
            InvoiceProductLine::MAX_VALUE,
        ));
    }
}
