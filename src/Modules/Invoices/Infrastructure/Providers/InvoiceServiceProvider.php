<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Providers;

use DomainException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Modules\Invoices\Infrastructure\Http\ExceptionHttpStatuses;
use Modules\Invoices\Infrastructure\Listeners\WebhookDeliveredListener;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;

/**
 * Eager rather than deferred: a deferred provider loads only when one of its
 * bindings is resolved, and an event listener has none.
 */
final class InvoiceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(WebhookDeliveredEvent::class, WebhookDeliveredListener::class);

        $this->callAfterResolving(ExceptionHandler::class, static function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                self::renderDomainExceptions($handler);
            }
        });
    }

    /**
     * Domain exceptions know nothing about HTTP, and bootstrap/app.php knows
     * nothing about invoices, so the module maps them here. Artisan commands
     * run with Collision's console handler, which renders no HTTP responses, so
     * only the framework's own handler gets the mappings. The mapped exceptions
     * are client errors (4xx), so they are not reported either.
     *
     * An overflowing total reaches HTTP when an invoice is created, where it is
     * a fault in the submitted lines: it answers the same validation error as
     * any other bad line, and the invoice it names was rolled back, so its id
     * is left out.
     */
    private static function renderDomainExceptions(Handler $handler): void
    {
        $handler->dontReport(array_keys(ExceptionHttpStatuses::STATUSES));
        $handler->renderable(static function (DomainException $exception): ?JsonResponse {
            $status = ExceptionHttpStatuses::STATUSES[$exception::class] ?? null;

            return $status === null ? null : new JsonResponse(['message' => $exception->getMessage()], $status);
        });
        $handler->map(InvoiceTotalOverflow::class, static fn (): ValidationException => ValidationException::withMessages([
            'product_lines' => 'The total of the product lines must not exceed '.PHP_INT_MAX.'.',
        ]));
    }
}
