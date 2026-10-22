<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use DomainException;
use Modules\Invoices\Domain\Enums\StatusEnum;

final class InvoiceMustBeCreatedAsDraft extends DomainException
{
    public static function withStatus(StatusEnum $status): self
    {
        return new self(sprintf('An invoice must be created as draft, %s given.', $status->value));
    }
}
