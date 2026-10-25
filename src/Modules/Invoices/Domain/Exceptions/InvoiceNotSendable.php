<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;

final class InvoiceNotSendable extends DomainException
{
    public static function noProductLines(string $invoiceId): self
    {
        return new self(sprintf('Invoice %s cannot be sent without product lines.', $invoiceId));
    }

    public static function invalidProductLine(string $invoiceId): self
    {
        return new self(sprintf('Invoice %s cannot be sent: a product line has an invalid quantity or unit price.', $invoiceId));
    }

    public static function totalTooLarge(string $invoiceId): self
    {
        return new self(sprintf('Invoice %s cannot be sent: the total of its product lines exceeds %d.', $invoiceId, PHP_INT_MAX));
    }
}
