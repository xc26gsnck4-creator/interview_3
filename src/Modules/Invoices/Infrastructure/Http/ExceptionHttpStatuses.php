<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Http;

use Exception;
use Illuminate\Http\Response;
use Modules\Invoices\Application\Exceptions\NotificationFailed;
use Modules\Invoices\Application\Exceptions\SendNotRecorded;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Exceptions\InvoiceNotSendable;

/**
 * The status each mapped exception answers with, as `{"message": ...}`.
 * InvoiceServiceProvider renders from these maps and the API document is
 * generated from them, so the responses and their documentation cannot drift.
 */
final class ExceptionHttpStatuses
{
    /**
     * @var array<class-string<Exception>, int>
     */
    public const array CLIENT_ERRORS = [
        InvoiceNotFound::class => Response::HTTP_NOT_FOUND,
        InvalidInvoiceStatusTransition::class => Response::HTTP_CONFLICT,
        InvoiceNotSendable::class => Response::HTTP_UNPROCESSABLE_ENTITY,
    ];

    /**
     * @var array<class-string<Exception>, int>
     */
    public const array SERVER_ERRORS = [
        NotificationFailed::class => Response::HTTP_SERVICE_UNAVAILABLE,
        SendNotRecorded::class => Response::HTTP_INTERNAL_SERVER_ERROR,
    ];

    /**
     * @var array<class-string<Exception>, int>
     */
    public const array STATUSES = self::CLIENT_ERRORS + self::SERVER_ERRORS;
}
