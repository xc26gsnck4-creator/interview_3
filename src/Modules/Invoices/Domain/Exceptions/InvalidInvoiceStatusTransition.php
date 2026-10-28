<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;
use Modules\Invoices\Domain\Enums\InvoiceStatus;

/**
 * The message names the status the transition needs beside the one found, so
 * a client reads why the claim failed and not only the edge that was refused.
 */
final class InvalidInvoiceStatusTransition extends DomainException
{
    public static function create(string $invoiceId, InvoiceStatus $current, InvoiceStatus $expected, InvoiceStatus $target): self
    {
        return new self(sprintf(
            'Invoice %s cannot move to %s: its status is %s, not %s.',
            $invoiceId,
            $target->value,
            $current->value,
            $expected->value,
        ));
    }
}
