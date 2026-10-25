<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * Strict: an unknown invoice or one not in sending is an exception; the
 * webhook listener decides what to tolerate. The lookup and the transition
 * share one transaction, which takes SQLite's write lock at BEGIN
 * (config/database.php). SendInvoiceService notifies before it commits, so a
 * delivery report can arrive while the send still holds that lock: it waits
 * for the commit, then finds the invoice in sending. Read outside a
 * transaction, it would find the committed draft and be rejected.
 */
final readonly class MarkInvoiceDeliveredService
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function handle(string $invoiceId): Invoice
    {
        return $this->connection->transaction(static function () use ($invoiceId): Invoice {
            $invoice = Invoice::findOrThrow($invoiceId);
            $invoice->markAsSentToClient();

            return $invoice;
        });
    }
}
