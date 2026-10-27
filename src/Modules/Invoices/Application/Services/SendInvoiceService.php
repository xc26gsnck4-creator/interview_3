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
 * The claim and the notification share one transaction, so any throwable from
 * notify() rolls the claim back and the invoice stays draft. Once notify() has
 * returned only the commit is left (the application registers no afterCommit
 * callbacks), so a failure from then on, with the customer already notified,
 * becomes SendNotRecorded, while one before notify() returns passes through
 * unchanged. Callers
 * must not wrap handle() in a transaction of their own, which would defer that
 * commit.
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
