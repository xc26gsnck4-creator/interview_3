<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Http;

use DomainException;
use Illuminate\Http\Response;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;

/**
 * The status each domain exception answers with, as `{"message": ...}`.
 * InvoiceServiceProvider renders from this map and the API document is
 * generated from it, so the responses and their documentation cannot drift.
 */
final class ExceptionHttpStatuses
{
    /**
     * @var array<class-string<DomainException>, int>
     */
    public const array STATUSES = [
        InvoiceNotFound::class => Response::HTTP_NOT_FOUND,
    ];
}
