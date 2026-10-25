<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Exceptions;

use RuntimeException;
use Throwable;

final class SendNotRecorded extends RuntimeException
{
    public static function forInvoice(string $invoiceId, Throwable $previous): self
    {
        return new self(
            sprintf('Invoice %s was sent to the customer, but the send could not be recorded; it remains in draft, and sending it again notifies the customer again.', $invoiceId),
            previous: $previous,
        );
    }
}
