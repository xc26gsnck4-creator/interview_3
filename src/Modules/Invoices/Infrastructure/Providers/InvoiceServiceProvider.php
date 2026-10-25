<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Providers;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
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
     * nothing about invoices, so the module maps them here. Only the
     * framework's own handler gets the mappings: in console runs with the dev
     * dependencies, Collision wraps it in a console handler, and console runs
     * render no HTTP responses anyway. Client errors (4xx) are not reported;
     * server errors (503, 500) are.
     */
    private static function renderDomainExceptions(Handler $handler): void
    {
        $handler->dontReport(array_keys(ExceptionHttpStatuses::CLIENT_ERRORS));
        $handler->renderable(static function (Exception $exception): ?JsonResponse {
            $status = ExceptionHttpStatuses::STATUSES[$exception::class] ?? null;

            return $status === null ? null : new JsonResponse(['message' => $exception->getMessage()], $status);
        });
    }
}
