<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Exceptions;

use RuntimeException;
use Throwable;

final class NotificationFailed extends RuntimeException
{
    public static function forInvoice(string $invoiceId, Throwable $previous): self
    {
        return new self(
            sprintf('Invoice %s could not be sent because the notification failed; it remains in draft and the send can be retried.', $invoiceId),
            previous: $previous,
        );
    }
}
