<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
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
    }
}
