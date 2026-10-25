<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Application\Exceptions\NotificationFailed;
use Modules\Invoices\Application\Exceptions\SendNotRecorded;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Notifications\Api\Data\NotifyData;
use Modules\Notifications\Api\NotificationFacadeInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * SQLite transactions begin IMMEDIATE (config/database.php), so this one holds
 * SQLite's database-wide write lock from BEGIN until the commit, and any other
 * writer waits for it up to the busy timeout. A concurrent send therefore
 * waits, then finds the invoice no longer in draft and is rejected by the
 * status check, or fails with a database-locked error at BEGIN, before it
 * reads or notifies anything. The claim, a conditional update before the
 * notification, is the backstop that holds in any transaction mode: of two
 * instances that both read draft, only one can claim the invoice. The claim
 * and the notification share one transaction, so any failure after the claim
 * rolls it back and the invoice stays draft. Other connections see the invoice
 * as draft until the commit; a delivery report that arrives before it waits
 * for the commit (see MarkInvoiceDeliveredService). Any throwable from
 * notify() counts as not sent, so a provider that delivers and then throws is
 * rolled back too. The lines are loaded before the notification, so once
 * notify() has returned only the commit can undo the claim (the application
 * registers no afterCommit callbacks or committed listeners). A failure then
 * means the customer was notified and the invoice stayed draft, so a retry
 * notifies them again: it becomes SendNotRecorded. A failure before it (a
 * lock error at BEGIN, a rule, NotificationFailed) passes through unchanged,
 * so a lock 503 always means nothing happened. Callers must not wrap handle()
 * in a transaction of their own, which would defer that commit.
 * The total is in minor units and is shown with two decimals, computed on ints.
 */
final readonly class SendInvoiceService
{
    public function __construct(
        private ConnectionInterface $connection,
        private NotificationFacadeInterface $notifications,
    ) {}

    public function handle(string $invoiceId): Invoice
    {
        $notifiedInvoiceId = null;

        try {
            return $this->connection->transaction(function () use ($invoiceId, &$notifiedInvoiceId): Invoice {
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

                $notifiedInvoiceId = $invoice->id;

                return $invoice;
            });
        } catch (Throwable $throwable) {
            throw_if($notifiedInvoiceId === null, $throwable);

            throw SendNotRecorded::forInvoice($notifiedInvoiceId, $throwable);
        }
    }
}
