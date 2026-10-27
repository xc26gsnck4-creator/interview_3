<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;
use Modules\Invoices\Domain\Enums\InvoiceStatus;

final class InvalidInvoiceStatusTransition extends DomainException
{
    public static function create(string $invoiceId, InvoiceStatus $current, InvoiceStatus $target): self
    {
        return new self(sprintf('Invoice %s cannot move from %s to %s.', $invoiceId, $current->value, $target->value));
    }
}
