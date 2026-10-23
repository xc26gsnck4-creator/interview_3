<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;
use Modules\Invoices\Domain\Enums\StatusEnum;

final class InvalidInvoiceStatusTransition extends DomainException
{
    public static function create(string $invoiceId, StatusEnum $current, StatusEnum $target): self
    {
        return new self(sprintf('Invoice %s cannot move from %s to %s.', $invoiceId, $current->value, $target->value));
    }

    /**
     * A status written past the transitions, even a legal one; the target is
     * null when the write clears the status.
     */
    public static function directWrite(string $invoiceId, StatusEnum $current, ?StatusEnum $target): self
    {
        return new self(sprintf(
            'Invoice %s status cannot be set directly from %s to %s; use its transitions.',
            $invoiceId,
            $current->value,
            $target->value ?? 'none',
        ));
    }
}
