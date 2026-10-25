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
 * one notifies. The transaction reads before it writes, so on SQLite the
 * claim may fail with a database-locked error while any other write
 * transaction is open, a concurrent send included. The claim and the
 * notification share one transaction, so any failure after the claim rolls it
 * back and the invoice stays draft. SQLite holds its database-wide write lock
 * from the claim until the commit, and other connections see the invoice as
 * draft until then, so a delivery report that arrives before the commit finds
 * it in draft. Any throwable from notify() counts as not sent, so a provider
 * that delivers and then throws is rolled back too. The lines are loaded
 * before the notification, so once notify() has returned only the commit can
 * undo the claim. Either way the invoice stays draft after the customer was
 * notified, and a retry notifies them again. Callers must not wrap
 * handle() in a transaction of their own, which would defer that commit. The
 * total is in minor units and is shown with two decimals, computed on ints.
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
            $invoice->load('productLines');

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
