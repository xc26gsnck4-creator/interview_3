<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;

final class InvoiceTotalOverflow extends DomainException
{
    public static function forInvoice(?string $invoiceId): self
    {
        return new self(sprintf(
            'The total of %s exceeds the maximum representable integer.',
            $invoiceId === null ? 'an unsaved invoice' : 'invoice '.$invoiceId,
        ));
    }
}
