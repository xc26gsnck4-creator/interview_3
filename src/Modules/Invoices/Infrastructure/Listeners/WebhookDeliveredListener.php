<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Listeners;

use Modules\Invoices\Application\Services\MarkInvoiceDeliveredService;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;
use Psr\Log\LoggerInterface;

/**
 * The tolerance for delivery reports lives here, at the boundary to the
 * Notifications module: a report for an unknown invoice or one not in sending
 * is logged and ignored, so the webhook still answers 204. Only those two
 * rejections are caught. Anything else, such as a database-locked error at
 * BEGIN, propagates and the webhook answers 500, which the provider can retry.
 * A row deleted between the lookup and the transition would surface as
 * Eloquent's ModelNotFoundException from the transition's re-read, but the
 * service holds the write lock from BEGIN, so that cannot happen.
 */
final readonly class WebhookDeliveredListener
{
    public function __construct(
        private MarkInvoiceDeliveredService $service,
        private LoggerInterface $logger,
    ) {}

    public function handle(WebhookDeliveredEvent $event): void
    {
        $invoiceId = $event->resourceId->toString();

        try {
            $this->service->handle($invoiceId);
        } catch (InvoiceNotFound|InvalidInvoiceStatusTransition $rejection) {
            $this->logger->warning('Ignoring delivery webhook for invoice', [
                'invoice_id' => $invoiceId,
                'reason' => $rejection->getMessage(),
            ]);
        }
    }
}
