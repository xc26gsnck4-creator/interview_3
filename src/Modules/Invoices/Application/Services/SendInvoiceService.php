<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Application\Exceptions\NotificationFailed;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Notifications\Api\Data\NotifyData;
use Modules\Notifications\Api\NotificationFacadeInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * The claim and the notification share one transaction, so any throwable from
 * notify() rolls the claim back and the invoice stays draft. A commit that
 * fails after notify() has returned leaves the invoice draft as well, with the
 * customer already notified; callers must not wrap handle() in a transaction
 * of their own, which would move that commit later.
 */
final readonly class SendInvoiceService
{
    public function __construct(
        private ConnectionInterface $connection,
        private NotificationFacadeInterface $notifications,
    ) {}

    public function handle(string $invoiceId): Invoice
    {
        return $this->connection->transaction(function () use ($invoiceId): Invoice {
            $invoice = Invoice::findOrThrow($invoiceId);
            $invoice->markAsSending();

            $total = $invoice->totalPrice();

            $data = new NotifyData(
                resourceId: Uuid::fromString($invoice->id),
                toEmail: $invoice->customer_email,
                subject: sprintf('Invoice %s', $invoice->id),
                message: sprintf('Your invoice %s for a total of %d.%02d is ready.', $invoice->id, intdiv($total, 100), $total % 100),
            );

            try {
                $this->notifications->notify($data);
            } catch (Throwable $throwable) {
                throw NotificationFailed::forInvoice($invoice->id, $throwable);
            }

            return $invoice;
        });
    }
}
