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
 * The claim comes before the notification, so of two concurrent sends only
 * one notifies. Both share one transaction, so any failure after the claim
 * rolls it back and the invoice stays draft. SQLite holds its database-wide
 * write lock from the claim until the commit. The lines are loaded after the
 * commit, so once the notification went out only the commit can fail: that
 * leaves the invoice in draft, and a retry notifies the customer again.
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

            $data = new NotifyData(
                resourceId: Uuid::fromString($invoice->id),
                toEmail: $invoice->customer_email,
                subject: sprintf('Invoice %s', $invoice->id),
                message: sprintf('Your invoice %s for a total of %d is ready.', $invoice->id, $invoice->totalPrice()),
            );

            try {
                $this->notifications->notify($data);
            } catch (Throwable $throwable) {
                throw NotificationFailed::forInvoice($invoice->id, $throwable);
            }

            return $invoice;
        })->load('productLines');
    }
}
