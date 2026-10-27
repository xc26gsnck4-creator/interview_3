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
 * is logged and ignored, so the webhook still answers 204. Anything else
 * propagates, so a database-locked error answers the busy 503, which the
 * provider can retry. ModelNotFoundException is not caught: the service holds
 * the write lock from the lookup to the transition, so the row cannot be
 * deleted in between.
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
